<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/email_delivery.php';

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');

$error = '';
$status = 200;
$completed = false;
$token = $_SERVER['REQUEST_METHOD'] === 'POST'
	? post_string('token')
	: (isset($_GET['token']) && is_string($_GET['token']) ? $_GET['token'] : '');
$isTokenFormatValid = preg_match('/^[a-f0-9]{64}$/D', $token) === 1;
$resetRequest = null;
$database = null;

if (!$isTokenFormatValid) {
	$error = 'This password reset link is invalid or has expired. Request a new link to continue.';
	$status = 400;
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && !has_valid_csrf_token()) {
	$error = 'Your reset form expired. Open the link in your email again and retry.';
	$status = 400;
} else {
	try {
		$database = database_connection();
		ensure_password_reset_requests_table($database);
		$lookup = $database->prepare(
			'SELECT reset_requests.user_id, users.username
			FROM password_reset_requests AS reset_requests
			INNER JOIN users ON users.user_id = reset_requests.user_id
			WHERE reset_requests.token_hash = :token_hash
				AND reset_requests.expires_at > UTC_TIMESTAMP()
			LIMIT 1'
		);
		$lookup->execute(['token_hash' => hash('sha256', $token)]);
		$resetRequest = $lookup->fetch();

		if (!$resetRequest) {
			$error = 'This password reset link is invalid or has expired. Request a new link to continue.';
			$status = 400;
		} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
			$password = post_string('password');
			if (strlen($password) < 8 || strlen($password) > 4096) {
				$error = 'Your password must be between 8 and 4096 characters long.';
				$status = 400;
			} elseif ($password !== post_string('confirm_password')) {
				$error = 'The passwords do not match.';
				$status = 400;
			} else {
				$database->beginTransaction();
				$lockedLookup = $database->prepare(
					'SELECT reset_requests.user_id
					FROM password_reset_requests AS reset_requests
					INNER JOIN users ON users.user_id = reset_requests.user_id
					WHERE reset_requests.token_hash = :token_hash
						AND reset_requests.expires_at > UTC_TIMESTAMP()
					LIMIT 1 FOR UPDATE'
				);
				$lockedLookup->execute(['token_hash' => hash('sha256', $token)]);
				$userId = $lockedLookup->fetchColumn();
				if ($userId === false) {
					$database->rollBack();
					$resetRequest = null;
					$error = 'This password reset link is invalid or has expired. Request a new link to continue.';
					$status = 400;
				} else {
					$update = $database->prepare(
						'UPDATE users SET password_hash = :password_hash WHERE user_id = :user_id'
					);
					$update->execute([
						'password_hash' => password_hash($password, PASSWORD_DEFAULT),
						'user_id' => $userId,
					]);
					$delete = $database->prepare(
						'DELETE FROM password_reset_requests WHERE user_id = :user_id'
					);
					$delete->execute(['user_id' => $userId]);
					$database->commit();

					if (isset($_SESSION['user_id']) && (int) $_SESSION['user_id'] === (int) $userId) {
						unset($_SESSION['user_id'], $_SESSION['username'], $_SESSION['email'], $_SESSION['role']);
						session_regenerate_id(true);
					}
					$completed = true;
				}
			}
		}
	} catch (PDOException $exception) {
		if ($database instanceof PDO && $database->inTransaction()) {
			$database->rollBack();
		}
		error_log('WoSo password reset database error: ' . $exception->getMessage());
		$error = 'The password could not be changed right now. Please try again later.';
		$status = 503;
	}
}

http_response_code($status);
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Choose a new password</title>
	<link rel="stylesheet" href="styles.css">
	<script src="back_button.js" defer></script>
</head>
<body>
	<main class="auth-card">
		<a class="auth-home-link" href="home.html">Women's Football Community</a>
		<h1>Choose a new password</h1>
		<?php if ($error !== ''): ?>
			<p class="auth-message auth-error" role="alert"><?= escape_html($error) ?></p>
		<?php endif; ?>
		<?php if ($completed): ?>
			<p class="auth-message auth-success" role="status">Your password has been changed. Sign in with your new password.</p>
			<p class="auth-register-link"><a href="login.php">Sign in</a></p>
		<?php elseif ($resetRequest): ?>
			<p class="auth-intro">Choose a new password for @<?= escape_html((string) $resetRequest['username']) ?>.</p>
			<form action="reset_password.php" method="post">
				<input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
				<input type="hidden" name="token" value="<?= escape_html($token) ?>">
				<label for="password">New password</label>
				<input id="password" name="password" type="password" autocomplete="new-password" minlength="8" maxlength="4096" required>
				<label for="confirm-password">Confirm new password</label>
				<input id="confirm-password" name="confirm_password" type="password" autocomplete="new-password" minlength="8" maxlength="4096" required>
				<button class="auth-button" type="submit">Change password</button>
			</form>
		<?php endif; ?>
		<?php if (!$completed): ?>
			<p class="auth-register-link"><a href="login.php">Return to sign in</a></p>
		<?php endif; ?>
	</main>
</body>
</html>
