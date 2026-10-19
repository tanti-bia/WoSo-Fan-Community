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
$token = $_SERVER['REQUEST_METHOD'] === 'POST'
	? post_string('token')
	: (isset($_GET['token']) && is_string($_GET['token']) ? $_GET['token'] : '');
$isTokenFormatValid = preg_match('/^[a-f0-9]{64}$/D', $token) === 1;
$pending = null;
$database = null;

if (!$isTokenFormatValid) {
	$error = 'This confirmation link is invalid or has expired. Submit the create-account form again to request a new link.';
	$status = 400;
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && !has_valid_csrf_token()) {
	$error = 'Your confirmation page expired. Open the link in your email again and retry.';
	$status = 400;
} else {
	try {
		$database = database_connection();
		ensure_pending_registrations_table($database);
		$lookup = $database->prepare(
			'SELECT pending_registration_id, username, email, password_hash, redirect_target
			FROM pending_registrations
			WHERE verification_token_hash = :token_hash AND expires_at > UTC_TIMESTAMP()
			LIMIT 1'
		);
		$lookup->execute(['token_hash' => hash('sha256', $token)]);
		$pending = $lookup->fetch();
		if (!$pending) {
			$error = 'This confirmation link is invalid or has expired. Submit the create-account form again to request a new link.';
			$status = 400;
		} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
			$database->beginTransaction();
			$lockedLookup = $database->prepare(
				'SELECT pending_registration_id, username, email, password_hash, redirect_target
				FROM pending_registrations
				WHERE verification_token_hash = :token_hash AND expires_at > UTC_TIMESTAMP()
				LIMIT 1 FOR UPDATE'
			);
			$lockedLookup->execute(['token_hash' => hash('sha256', $token)]);
			$pending = $lockedLookup->fetch();
			if (!$pending) {
				$database->rollBack();
				$error = 'This confirmation link is invalid or has expired. Submit the create-account form again to request a new link.';
				$status = 400;
			} else {
				$insert = $database->prepare(
					'INSERT INTO users (username, email, password_hash, role)
					VALUES (:username, :email, :password_hash, :role)'
				);
				$insert->execute([
					'username' => $pending['username'],
					'email' => $pending['email'],
					'password_hash' => $pending['password_hash'],
					'role' => 'Member',
				]);
				$user = [
					'user_id' => (int) $database->lastInsertId(),
					'username' => (string) $pending['username'],
					'email' => (string) $pending['email'],
					'role' => 'Member',
				];
				$delete = $database->prepare(
					'DELETE FROM pending_registrations WHERE pending_registration_id = :pending_id'
				);
				$delete->execute(['pending_id' => $pending['pending_registration_id']]);
				$database->commit();

				sign_in_user($user);
			$allowedTargets = ['predictions.php', 'community.php', 'profile.php', 'notifications.php', 'moderation.php'];
				$redirectTarget = (string) $pending['redirect_target'];
				if (!in_array($redirectTarget, $allowedTargets, true)) {
					$redirectTarget = 'home.php';
				}
				header('Location: ' . $redirectTarget, true, 303);
				exit;
		}
	}
	} catch (PDOException $exception) {
		if ($database instanceof PDO && $database->inTransaction()) {
			$database->rollBack();
		}
		if ($exception->getCode() === '23000') {
			$error = 'That username or email address is already registered. Sign in if you already have an account.';
			$status = 409;
		} else {
			error_log('WoSo email confirmation database error: ' . $exception->getMessage());
			$error = 'Email confirmation is temporarily unavailable. Please try again later.';
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
	<title>Confirm your email</title>
	<link rel="stylesheet" href="styles.css">
	<script src="back_button.js" defer></script>
</head>
<body>
	<main class="auth-card">
		<a class="auth-home-link" href="home.html">Women's Football Community</a>
		<h1>Confirm your email</h1>
		<?php if ($error !== ''): ?>
			<p class="auth-message auth-error" role="alert"><?= escape_html($error) ?></p>
		<?php elseif ($pending): ?>
			<p class="auth-intro">Confirm this email address to finish creating your account.</p>
			<form action="verify_email.php" method="post">
				<input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
				<input type="hidden" name="token" value="<?= escape_html($token) ?>">
				<button class="auth-button" type="submit">Confirm email and create account</button>
			</form>
		<?php endif; ?>
		<p class="auth-register-link"><a href="login.php">Return to sign in</a></p>
	</main>
</body>
</html>
