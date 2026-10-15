<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/database.php';

$error = '';
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
		$error = 'Your password must be at least 8 characters long.';
	} elseif ($password !== $confirmPassword) {
		$error = 'The passwords do not match.';
	} else {
		try {
			$database = database_connection();
			$database->beginTransaction();
			$query = $database->prepare(
				'INSERT INTO users (username, email, password_hash, role)
				VALUES (:username, :email, :password_hash, :role)'
			);
			$query->execute([
				'username' => $username,
				'email' => $email,
				'password_hash' => password_hash($password, PASSWORD_DEFAULT),
				'role' => 'Member',
			]);

			$userId = (int) $database->lastInsertId();
			$database->commit();
			sign_in_user([
				'user_id' => $userId,
				'username' => $username,
				'email' => $email,
				'role' => 'Member',
			]);
			header('Location: ' . auth_return_target());
			exit;
		} catch (PDOException $exception) {
			if (isset($database) && $database->inTransaction()) {
				$database->rollBack();
			}

			if ($exception->getCode() === '23000') {
				$error = 'That username or email address is already registered.';
			} else {
				error_log('WoSo registration database error: ' . $exception->getMessage());
				$error = 'Account creation is temporarily unavailable. Please check the database setup and try again.';
				$status = 503;
			}
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
		<p class="auth-intro">New accounts start with Member access. You can request Moderator access from your profile after signing in.</p>
		<?php if ($error !== ''): ?>
			<p class="auth-message auth-error" role="alert"><?= escape_html($error) ?></p>
		<?php endif; ?>
		<form action="register.php" method="post">
			<input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
			<input type="hidden" name="redirect" value="<?= escape_html(auth_return_target()) ?>">
			<label for="username">Username</label>
			<input id="username" name="username" type="text" autocomplete="username" minlength="3" maxlength="30" pattern="[A-Za-z0-9_]+" value="<?= escape_html($username) ?>" required>
			<label for="email">Email address</label>
			<input id="email" name="email" type="email" autocomplete="email" maxlength="254" value="<?= escape_html($email) ?>" required>
			<label for="password">Password</label>
			<input id="password" name="password" type="password" autocomplete="new-password" minlength="8" required>
			<label for="confirm-password">Confirm password</label>
			<input id="confirm-password" name="confirm_password" type="password" autocomplete="new-password" minlength="8" required>
			<button class="auth-button" type="submit">Create account</button>
		</form>
		<p class="auth-register-link">Already have an account? <a href="login.php">Sign in</a></p>
	</main>
</body>
</html>
