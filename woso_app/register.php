<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/email_delivery.php';

$error = '';
$notice = '';
$username = '';
$email = '';
$status = 200;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$username = trim(post_string('username'));
	$email = strtolower(trim(post_string('email')));
	$password = post_string('password');
	$confirmPassword = post_string('confirm_password');

	if (!has_valid_csrf_token()) {
		$error = 'Your session expired. Please try again.';
		$status = 400;
	} elseif (!preg_match('/^[A-Za-z0-9_]{3,30}$/D', $username)) {
		$error = 'Choose a username of 3 to 30 letters, numbers, or underscores.';
	} elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
		$error = 'Enter a valid email address.';
	} elseif (strlen($password) < 8 || strlen($password) > 4096) {
		$error = 'Your password must be at least 8 characters long and no more than 4096 bytes.';
	} elseif ($password !== $confirmPassword) {
		$error = 'The passwords do not match.';
	} else {
		$database = null;
		try {
			$database = database_connection();
			ensure_pending_registrations_table($database);
			$redirectTarget = auth_return_target();
			$token = bin2hex(random_bytes(32));
			$tokenHash = hash('sha256', $token);
			$database->beginTransaction();
			$database->exec('DELETE FROM pending_registrations WHERE expires_at <= UTC_TIMESTAMP()');

			$existingUserQuery = $database->prepare(
				'SELECT user_id FROM users WHERE email = :email OR username = :username LIMIT 1'
			);
			$existingUserQuery->execute(['email' => $email, 'username' => $username]);
			if ($existingUserQuery->fetch()) {
				$database->rollBack();
				$error = 'That username or email address is already registered. Sign in if you already have an account.';
			} else {
				$pendingQuery = $database->prepare(
					'SELECT pending_registration_id, username, email,
						TIMESTAMPDIFF(SECOND, last_requested_at, UTC_TIMESTAMP()) AS seconds_since_request
					FROM pending_registrations
					WHERE email = :email OR username = :username
					LIMIT 1 FOR UPDATE'
				);
				$pendingQuery->execute(['email' => $email, 'username' => $username]);
				$pending = $pendingQuery->fetch();
				$passwordHash = password_hash($password, PASSWORD_DEFAULT);
				if ($pending) {
					if (strcasecmp((string) $pending['email'], $email) !== 0
						|| strcasecmp((string) $pending['username'], $username) !== 0) {
						$database->rollBack();
						$error = 'That username or email address is already awaiting confirmation.';
					} elseif ((int) $pending['seconds_since_request'] < 60) {
						$database->rollBack();
						$notice = 'A confirmation email was requested recently. Wait a minute before requesting another.';
					} else {
						$update = $database->prepare(
							'UPDATE pending_registrations
							SET password_hash = :password_hash, verification_token_hash = :token_hash,
								redirect_target = :redirect_target, expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 24 HOUR),
								last_requested_at = UTC_TIMESTAMP()
							WHERE pending_registration_id = :pending_id'
						);
						$update->execute([
							'password_hash' => $passwordHash,
							'token_hash' => $tokenHash,
							'redirect_target' => $redirectTarget,
							'pending_id' => $pending['pending_registration_id'],
						]);
						$database->commit();
					}
				} else {
					$insert = $database->prepare(
						'INSERT INTO pending_registrations
							(username, email, password_hash, verification_token_hash, redirect_target, expires_at, last_requested_at)
						VALUES (:username, :email, :password_hash, :token_hash, :redirect_target,
							DATE_ADD(UTC_TIMESTAMP(), INTERVAL 24 HOUR), UTC_TIMESTAMP())'
					);
					$insert->execute([
						'username' => $username,
						'email' => $email,
						'password_hash' => $passwordHash,
						'token_hash' => $tokenHash,
						'redirect_target' => $redirectTarget,
					]);
					$database->commit();
				}

				if ($error === '' && $notice === '') {
					$verificationUrl = email_verification_base_url()
						. '/verify_email.php?token=' . rawurlencode($token);
					send_email_verification($email, $username, $verificationUrl);
					$notice = 'Check your inbox for a confirmation link. It expires in 24 hours. You can submit this form again to resend it.';
				}
			}
		} catch (PDOException $exception) {
			if ($database instanceof PDO && $database->inTransaction()) {
				$database->rollBack();
			}
			if ($exception->getCode() === '23000') {
				$error = 'That username or email address is already registered. Sign in if you already have an account.';
			} else {
				error_log('WoSo registration database error: ' . $exception->getMessage());
				$error = 'Account setup is temporarily unavailable. Please try again later.';
				$status = 503;
			}
		} catch (RuntimeException $exception) {
			error_log('WoSo verification email error: ' . $exception->getMessage());
			$error = 'Your details are saved, but the confirmation email could not be sent. Check the email service configuration, wait one minute, then submit the form again to retry.';
			$status = 503;
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
	<title>Women's Football - Create Account</title>
	<link rel="stylesheet" href="styles.css">
	<script src="back_button.js" defer></script>
</head>
<body>
	<main class="auth-card">
		<a class="auth-home-link" href="home.html">Women's Football Community</a>
		<h1>Create an account</h1>
		<p class="auth-intro">We’ll email you a confirmation link. Your account is created after you confirm your address.</p>
		<?php if ($error !== ''): ?>
			<p class="auth-message auth-error" role="alert"><?= escape_html($error) ?></p>
		<?php endif; ?>
		<?php if ($notice !== ''): ?>
			<p class="auth-message auth-success" role="status"><?= escape_html($notice) ?></p>
		<?php endif; ?>
		<form action="register.php" method="post">
			<input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
			<input type="hidden" name="redirect" value="<?= escape_html(auth_return_target()) ?>">
			<label for="username">Username</label>
			<input id="username" name="username" type="text" autocomplete="username" minlength="3" maxlength="30" pattern="[A-Za-z0-9_]+" value="<?= escape_html($username) ?>" required>
			<label for="email">Email address</label>
			<input id="email" name="email" type="email" autocomplete="email" maxlength="254" value="<?= escape_html($email) ?>" required>
			<label for="password">Password</label>
			<input id="password" name="password" type="password" autocomplete="new-password" minlength="8" maxlength="4096" aria-describedby="password-guidance" required>
			<p id="password-guidance" class="auth-intro">Use at least 8 characters (maximum 4096 bytes). Letters, numbers, spaces, and symbols are allowed; no specific character mix is required.</p>
			<label for="confirm-password">Confirm password</label>
			<input id="confirm-password" name="confirm_password" type="password" autocomplete="new-password" minlength="8" maxlength="4096" aria-describedby="password-guidance" required>
			<button class="auth-button" type="submit">Send confirmation email</button>
		</form>
		<p class="auth-register-link">Already have an account? <a href="login.php">Sign in</a></p>
	</main>
</body>
</html>
