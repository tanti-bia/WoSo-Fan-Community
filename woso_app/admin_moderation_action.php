<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/database.php';
require_signed_in('home.php');
require_role(['Administrator']);

function moderation_action_redirect(string $url, string $message, bool $error = false): never
{
	$_SESSION[$error ? 'report_error' : 'report_notice'] = $message;
	header('Location: ' . $url, true, 303);
	exit;
}

$returnTo = post_string('return_to');
if (!preg_match('/^home\.php(?:\?[^#\r\n]*)?(?:#[A-Za-z0-9_-]+)?$/', $returnTo)) {
	$returnTo = 'home.php';
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !has_valid_csrf_token()) {
	moderation_action_redirect($returnTo, 'Your session expired. Please try again.', true);
}

$targetType = post_string('target_type');
$action = post_string('review_action');
$targetId = filter_var(post_string('target_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$days = filter_var(post_string('block_days'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 365]]);
$validAction = ($targetType === 'post' && in_array($action, ['none', 'delete_post'], true))
	|| ($targetType === 'user' && in_array($action, ['none', 'ban_user', 'temporary_block'], true));
if ($targetId === false || !$validAction) {
	moderation_action_redirect($returnTo, 'Choose a valid moderation action.', true);
}

try {
	$database = database_connection();
	$database->beginTransaction();
	if ($targetType === 'post') {
		$query = $database->prepare('SELECT user_id, status FROM posts WHERE post_id = :post_id FOR UPDATE');
		$query->execute(['post_id' => $targetId]);
		$post = $query->fetch();
		if (!$post || $post['status'] !== 'published') {
			throw new RuntimeException('This post has already been deleted or is unavailable.');
		}
		if ($action === 'delete_post') {
			$query = $database->prepare("UPDATE posts SET status = 'removed' WHERE post_id = :post_id");
			$query->execute(['post_id' => $targetId]);
			$query = $database->prepare(
				'INSERT INTO notifications (recipient_user_id, actor_user_id, post_id, target_type, notification_type)
				VALUES (:recipient_user_id, :actor_user_id, :post_id, \'post\', \'post_removed\')'
			);
			$query->execute([
				'recipient_user_id' => (int) $post['user_id'],
				'actor_user_id' => (int) $_SESSION['user_id'],
				'post_id' => $targetId,
			]);
		}
	} else {
		$query = $database->prepare('SELECT role FROM users WHERE user_id = :user_id FOR UPDATE');
		$query->execute(['user_id' => $targetId]);
		$targetRole = $query->fetchColumn();
		if ($targetRole === false) {
			throw new RuntimeException('That account is unavailable.');
		}
		if ($targetId === (int) $_SESSION['user_id']) {
			throw new RuntimeException('This action cannot be applied to your own account.');
		}
		if ($action !== 'none' && $targetRole === 'Administrator') {
			throw new RuntimeException('Administrator accounts cannot be restricted.');
		}
		if ($action === 'ban_user') {
			$query = $database->prepare('UPDATE users SET banned_at = UTC_TIMESTAMP(), suspended_until = NULL WHERE user_id = :user_id');
			$query->execute(['user_id' => $targetId]);
		} elseif ($action === 'temporary_block') {
			if ($days === false) {
				throw new RuntimeException('Choose a block period from 1 to 365 days.');
			}
			$allowedDays = [1, 7, 30, 90, 365];
			if (!in_array($days, $allowedDays, true)) {
				throw new RuntimeException('Choose one of the available block periods.');
			}
			$until = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
				->modify('+' . $days . ' days')->format('Y-m-d H:i:s');
			$query = $database->prepare('UPDATE users SET suspended_until = :until, banned_at = NULL WHERE user_id = :user_id');
			$query->execute(['until' => $until, 'user_id' => $targetId]);
		}
	}
	$database->commit();
	moderation_action_redirect($returnTo, 'Moderation action saved.');
} catch (RuntimeException $exception) {
	if (isset($database) && $database->inTransaction()) {
		$database->rollBack();
	}
	moderation_action_redirect($returnTo, $exception->getMessage(), true);
} catch (PDOException $exception) {
	if (isset($database) && $database->inTransaction()) {
		$database->rollBack();
	}
	error_log('WoSo inline moderation database error: ' . $exception->getMessage());
	moderation_action_redirect($returnTo, 'The moderation action could not be saved. Please try again.', true);
}
