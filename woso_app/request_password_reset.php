<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/email_delivery.php';

$error = '';
$notice = '';
$email = '';
$status = 200;
$isSignedIn = isset($_SESSION['user_id']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$email = strtolower(trim(post_string('email')));
	if (!has_valid_csrf_token()) {
		$error = 'Your session expired. Please try again.';
		$status = 400;
	} elseif (!$isSignedIn && (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254)) {
		$error = 'Enter a valid email address.';
	} else {
		try {
			$database = database_connection();
			ensure_password_reset_requests_table($database);
			if ($isSignedIn) {
				$userQuery = $database->prepare(
					'SELECT user_id, username, email FROM users WHERE user_id = :user_id LIMIT 1'
				);
				$userQuery->execute(['user_id' => (int) $_SESSION['user_id']]);
			} else {
				$userQuery = $database->prepare(
					'SELECT user_id, username, email FROM users WHERE email = :email LIMIT 1'
				);
				$userQuery->execute(['email' => $email]);
			}
			$user = $userQuery->fetch();
			if ($user) {
				$userId = (int) $user['user_id'];
				$database->beginTransaction();
				$existingResetQuery = $database->prepare(
					'SELECT TIMESTAMPDIFF(SECOND, last_requested_at, UTC_TIMESTAMP()) AS seconds_since_request
					FROM password_reset_requests WHERE user_id = :user_id LIMIT 1 FOR UPDATE'
				);
				$existingResetQuery->execute(['user_id' => $userId]);
				$existingReset = $existingResetQuery->fetch();
				if ($existingReset && (int) $existingReset['seconds_since_request'] < 60) {
					$database->rollBack();
				} else {
					$token = bin2hex(random_bytes(32));
					$tokenHash = hash('sha256', $token);
					if ($existingReset) {
						$saveReset = $database->prepare(
							'UPDATE password_reset_requests
							SET token_hash = :token_hash, expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 HOUR),
								last_requested_at = UTC_TIMESTAMP()
							WHERE user_id = :user_id'
						);
					} else {
						$saveReset = $database->prepare(
							'INSERT INTO password_reset_requests (user_id, token_hash, expires_at, last_requested_at)
							VALUES (:user_id, :token_hash, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 HOUR), UTC_TIMESTAMP())'
						);
					}
					$saveReset->execute(['user_id' => $userId, 'token_hash' => $tokenHash]);
					$database->commit();

					$resetUrl = email_verification_base_url()
						. '/reset_password.php?token=' . rawurlencode($token);
					send_password_reset_email((string) $user['email'], (string) $user['username'], $resetUrl);
				}
			}
			$notice = 'If an account matches that email address, a password reset link has been sent. The link expires in one hour. Check your inbox and spam folder.';
		} catch (PDOException $exception) {
			if (isset($database) && $database->inTransaction()) {
				$database->rollBack();
			}
			error_log('WoSo password reset request database error: ' . $exception->getMessage());
			$error = 'Password reset is temporarily unavailable. Please try again later.';
			$status = 503;
		} catch (RuntimeException $exception) {
			error_log('WoSo password reset email error: ' . $exception->getMessage());
			$notice = 'If an account matches that email address, a password reset link has been sent. The link expires in one hour. Check your inbox and spam folder.';
		}
	}
}

http_response_code($status);
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Reset your password</title>
	<link rel="stylesheet" href="styles.css">
	<script src="back_button.js" defer></script>
</head>
<body>
	<main class="auth-card">
		<a class="auth-home-link" href="home.html">Women's Football Community</a>
		<h1><?= $isSignedIn ? 'Change your password' : 'Reset your password' ?></h1>
		<p class="auth-intro"><?= $isSignedIn
			? 'We’ll send a password reset link to the email address on your account.'
			: 'Enter your account email address and we’ll send a link to choose a new password.' ?></p>
		<?php if ($error !== ''): ?>
			<p class="auth-message auth-error" role="alert"><?= escape_html($error) ?></p>
		<?php endif; ?>
		<?php if ($notice !== ''): ?>
			<p class="auth-message auth-success" role="status"><?= escape_html($notice) ?></p>
		<?php endif; ?>
		<form action="request_password_reset.php" method="post">
			<input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
			<?php if (!$isSignedIn): ?>
				<label for="email">Email address</label>
				<input id="email" name="email" type="email" autocomplete="email" maxlength="254" value="<?= escape_html($email) ?>" required>
			<?php endif; ?>
			<button class="auth-button" type="submit">Email me a password link</button>
		</form>
		<p class="auth-register-link"><a href="<?= $isSignedIn ? 'profile.php' : 'login.php' ?>">Return to <?= $isSignedIn ? 'profile' : 'sign in' ?></a></p>
	</main>
</body>
</html>
