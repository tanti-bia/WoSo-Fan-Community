<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/database.php';
require_signed_in('home.php');

function report_redirect(string $url, string $message, bool $error = false): never
{
	$_SESSION[$error ? 'report_error' : 'report_notice'] = $message;
	header('Location: ' . $url, true, 303);
	exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !has_valid_csrf_token()) {
	report_redirect('home.php', 'Your session expired. Please try again.', true);
}

$reason = trim(post_string('reason'));
$type = post_string('target_type');
$reporterId = (int) $_SESSION['user_id'];
$targetId = filter_var(post_string('target_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$returnTo = post_string('return_to');
if (!preg_match('/^home\.php(?:\?[^#\r\n]*)?(?:#[A-Za-z0-9_-]+)?$/', $returnTo)) {
	$returnTo = 'home.php';
}
if (!in_array($type, ['post', 'user'], true) || $targetId === false
	|| $reason === '' || strlen($reason) > 8000) {
	report_redirect($returnTo, 'A report needs a target and a reason of 1 to 2,000 characters.', true);
}

try {
	$database = database_connection();
	if ($type === 'post') {
		$query = $database->prepare('SELECT user_id FROM posts WHERE post_id = :post_id AND status = \'published\'');
		$query->execute(['post_id' => $targetId]);
		$reportedUserId = $query->fetchColumn();
	} else {
		$query = $database->prepare('SELECT user_id FROM users WHERE user_id = :user_id');
		$query->execute(['user_id' => $targetId]);
		$reportedUserId = $query->fetchColumn();
	}
	if ($reportedUserId === false || (int) $reportedUserId === $reporterId) {
		report_redirect($returnTo, 'You cannot report your own content or an unavailable account.', true);
	}
	$query = $database->prepare(
		'INSERT INTO reports (reporter_id, target_type, post_id, reported_user_id, reason)
		VALUES (:reporter_id, :target_type, :post_id, :reported_user_id, :reason)'
	);
	$query->execute([
		'reporter_id' => $reporterId,
		'target_type' => $type,
		'post_id' => $type === 'post' ? $targetId : null,
		'reported_user_id' => (int) $reportedUserId,
		'reason' => $reason,
	]);
	report_redirect($returnTo, 'Your report has been sent to the administrators.');
} catch (PDOException $exception) {
	error_log('WoSo report submission database error: ' . $exception->getMessage());
	report_redirect($returnTo, 'Reports are temporarily unavailable. Please try again later.', true);
}
