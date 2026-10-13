<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/post_media.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	http_response_code(405);
	header('Allow: POST');
	exit('Use a post action to continue.');
}
if (!isset($_SESSION['user_id'])) {
	http_response_code(403);
	exit('Sign in to interact with posts and comments.');
}
if (!has_valid_csrf_token()) {
	http_response_code(400);
	exit('Your session expired. Please try again.');
}

$action = post_string('action');
$targetType = post_string('target_type');
$postIdInput = post_string('post_id');
$postId = ctype_digit($postIdInput) ? filter_var($postIdInput, FILTER_VALIDATE_INT) : false;
$targetIdInput = post_string('target_id');
$targetId = ctype_digit($targetIdInput) ? filter_var($targetIdInput, FILTER_VALIDATE_INT) : false;
$content = trim(post_string('content'));
$allowedActions = ['comment', 'like', 'repost'];

if (!in_array($action, $allowedActions, true)
	|| !in_array($targetType, ['post', 'comment'], true)
	|| !is_int($postId) || $postId < 1
	|| !is_int($targetId) || $targetId < 1
	|| ($targetType === 'post' && ($action !== 'comment' || $targetId !== $postId))
	|| ($action === 'comment' && strlen($content) > 5000)
	|| ($action === 'comment' && $content === '' && !isset($_FILES['media']))) {
	http_response_code(400);
	exit('Choose a valid post or comment action.');
}

$error = '';
$status = 200;
$media = null;
if ($action === 'comment') {
	$media = store_post_media('media', $error, $status);
	if ($error !== '') {
		http_response_code($status);
		exit(escape_html($error));
	}
	if ($content === '' && $media === null) {
		http_response_code(400);
		exit('Write a comment or choose a photo or video to share.');
	}
}

$uploadedFilePath = $media['absolute_path'] ?? null;
$database = null;
$userId = (int) $_SESSION['user_id'];
$notice = '';
$redirectCommentId = null;

try {
	$database = database_connection();
	$database->beginTransaction();
	$postQuery = $database->prepare(
		'SELECT post_id, user_id FROM posts
		WHERE post_id = :post_id AND status = \'published\'
		FOR UPDATE'
	);
	$postQuery->execute(['post_id' => $postId]);
	$post = $postQuery->fetch();
	if (!$post) {
		throw new DomainException('That post is no longer available.');
	}

	if ($targetType === 'comment') {
		$commentQuery = $database->prepare(
			'SELECT comment_id, post_id, user_id FROM comments
			WHERE comment_id = :comment_id AND status = \'published\'
			FOR UPDATE'
		);
		$commentQuery->execute(['comment_id' => $targetId]);
		$targetComment = $commentQuery->fetch();
		if (!$targetComment || (int) $targetComment['post_id'] !== $postId) {
			throw new DomainException('That comment is no longer available.');
		}
	} else {
		$targetComment = null;
	}

	if ($action === 'comment') {
		$insert = $database->prepare(
			'INSERT INTO comments (post_id, parent_comment_id, user_id, content, media_path, media_type)
			VALUES (:post_id, :parent_comment_id, :user_id, :content, :media_path, :media_type)'
		);
		$insert->execute([
			'post_id' => $postId,
			'parent_comment_id' => $targetType === 'comment' ? $targetId : null,
			'user_id' => $userId,
			'content' => $content === '' ? null : $content,
			'media_path' => $media['path'] ?? null,
			'media_type' => $media['type'] ?? null,
		]);
		$redirectCommentId = (int) $database->lastInsertId();
		$recipients = [];
		if ($targetType === 'comment' && (int) $targetComment['user_id'] !== $userId) {
			$recipients[(int) $targetComment['user_id']] = [
				'target_type' => 'comment',
				'target_comment_id' => $targetId,
			];
		}
		if ((int) $post['user_id'] !== $userId) {
			$recipients[(int) $post['user_id']] ??= [
				'target_type' => 'post',
				'target_comment_id' => null,
			];
		}
		foreach ($recipients as $recipientId => $target) {
			$notification = $database->prepare(
				'INSERT INTO notifications
					(recipient_user_id, actor_user_id, post_id, comment_id,
					 target_comment_id, target_type, notification_type)
				VALUES
					(:recipient_user_id, :actor_user_id, :post_id, :comment_id,
					 :target_comment_id, :target_type, \'comment\')'
			);
			$notification->execute([
				'recipient_user_id' => $recipientId,
				'actor_user_id' => $userId,
				'post_id' => $postId,
				'comment_id' => $redirectCommentId,
				'target_comment_id' => $target['target_comment_id'],
				'target_type' => $target['target_type'],
			]);
		}
		$notice = $targetType === 'comment' ? 'Your reply has been added.' : 'Your comment has been added.';
	} else {
		$table = $targetType === 'post' ? 'post_' . ($action === 'like' ? 'likes' : 'reposts') : 'comment_' . ($action === 'like' ? 'likes' : 'reposts');
		$idColumn = $targetType === 'post' ? 'post_id' : 'comment_id';
		$existingQuery = $database->prepare(
			'SELECT ' . $idColumn . ' FROM ' . $table
			. ' WHERE ' . $idColumn . ' = :target_id AND user_id = :user_id'
		);
		$existingQuery->execute(['target_id' => $targetId, 'user_id' => $userId]);
		if ($existingQuery->fetch()) {
			$delete = $database->prepare(
				'DELETE FROM ' . $table . ' WHERE ' . $idColumn . ' = :target_id AND user_id = :user_id'
			);
			$delete->execute(['target_id' => $targetId, 'user_id' => $userId]);
			$notice = $action === 'like' ? 'Like removed.' : 'Repost removed.';
		} else {
			$insert = $database->prepare(
				'INSERT INTO ' . $table . ' (' . $idColumn . ', user_id) VALUES (:target_id, :user_id)'
			);
			$insert->execute(['target_id' => $targetId, 'user_id' => $userId]);
			$recipientId = $targetType === 'post' ? (int) $post['user_id'] : (int) $targetComment['user_id'];
			if ($recipientId !== $userId) {
				$notification = $database->prepare(
					'INSERT INTO notifications
						(recipient_user_id, actor_user_id, post_id, target_comment_id,
						 target_type, notification_type)
					VALUES (:recipient_user_id, :actor_user_id, :post_id, :target_comment_id,
					 :target_type, :notification_type)'
				);
				$notification->execute([
					'recipient_user_id' => $recipientId,
					'actor_user_id' => $userId,
					'post_id' => $postId,
					'target_comment_id' => $targetType === 'comment' ? $targetId : null,
					'target_type' => $targetType,
					'notification_type' => $action,
				]);
			}
			$notice = $action === 'like' ? 'Post liked.' : 'Post reposted.';
		}
	}

	$database->commit();
} catch (DomainException $exception) {
	if ($database instanceof PDO && $database->inTransaction()) {
		$database->rollBack();
	}
	if ($uploadedFilePath !== null && is_file($uploadedFilePath)) {
		unlink($uploadedFilePath);
	}
	http_response_code(404);
	exit(escape_html($exception->getMessage()));
} catch (PDOException $exception) {
	if ($database instanceof PDO && $database->inTransaction()) {
		$database->rollBack();
	}
	if ($uploadedFilePath !== null && is_file($uploadedFilePath)) {
		unlink($uploadedFilePath);
	}
	error_log('WoSo comment interaction database error: ' . $exception->getMessage());
	http_response_code(503);
	exit('That action could not be saved. Please try again.');
}

$_SESSION['home_notice'] = $notice;
$anchor = $redirectCommentId === null ? 'post-' . $postId : 'comment-' . $redirectCommentId;
header('Location: home.php#' . $anchor, true, 303);
exit;
