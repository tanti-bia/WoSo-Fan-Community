<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_SESSION['user_id'])) {
	header('Location: profile.php');
	exit;
}

$error = '';
$email = '';
$status = 200;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$email = strtolower(trim(post_string('email')));
	$password = post_string('password');

	if (!has_valid_csrf_token()) {
		$error = 'Your session expired. Please try again.';
		$status = 400;
	} elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254 || $password === '' || strlen($password) > 4096) {
		$error = 'Enter a valid email address and password.';
	} else {
		try {
			$database = database_connection();
			$query = $database->prepare(
				'SELECT user_id, username, email, password_hash, role
				FROM users
				WHERE email = :email
				LIMIT 1'
			);
			$query->execute(['email' => $email]);
			$user = $query->fetch();

			if ($user && password_verify($password, $user['password_hash'])) {
				sign_in_user($user);
				header('Location: ' . auth_return_target());
				exit;
			}

			$error = 'The email or password is incorrect.';
		} catch (PDOException $exception) {
			error_log('WoSo login database error: ' . $exception->getMessage());
			$error = 'Sign-in is temporarily unavailable. Please check the database setup and try again.';
			$status = 503;
		}
	}
}

if (isset($_GET['logged_out'])) {
	$notice = 'You have been signed out.';
} else {
	$notice = '';
}

http_response_code($status);
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Women's Football - Account</title>
	<link rel="stylesheet" href="styles.css">
	<script src="back_button.js" defer></script>
</head>
<body>
	<nav class="topnav" aria-label="Main navigation">
		<a href="home.html">Home</a>
		<a href="matches.php">Match Center</a>
		<a href="community.html">Community</a>
		<a href="polls.php">Polls</a>
		<a href="predictions.php">Predictions</a>
		<?= notifications_nav_link() ?>
		<?php if (can_access_moderation()): ?>
			<a href="moderation.php">Moderation</a>
		<?php endif; ?>
		<?= account_nav_link(true) ?>
	</nav>
	<main class="auth-card">
		<a class="auth-home-link" href="home.html">Women's Football Community</a>
		<?php if (isset($_SESSION['user_id'])): ?>
			<h1>Your account</h1>
			<p class="auth-intro">Manage your account from your profile page.</p>
			<p><a class="auth-button" href="profile.php">Open your profile</a></p>
		<?php else: ?>
			<h1>Sign in</h1>
			<p class="auth-intro">Sign in to your Women's Football Community account.</p>
			<?php if ($error !== ''): ?>
				<p class="auth-message auth-error" role="alert"><?= escape_html($error) ?></p>
			<?php endif; ?>
			<?php if ($notice !== ''): ?>
				<p class="auth-message auth-success" role="status"><?= escape_html($notice) ?></p>
			<?php endif; ?>
			<form action="login.php" method="post">
				<input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
				<input type="hidden" name="redirect" value="<?= escape_html(auth_return_target()) ?>">
				<label for="email">Email address</label>
				<input id="email" name="email" type="email" autocomplete="email" maxlength="254" value="<?= escape_html($email) ?>" required>
				<label for="password">Password</label>
				<input id="password" name="password" type="password" autocomplete="current-password" required>
				<button class="auth-button" type="submit">Sign in</button>
			</form>
			<p class="auth-intro">Or sign in with</p>
			<a class="auth-button google-button" href="google_login.php?redirect=<?= rawurlencode(auth_return_target()) ?>">Continue with Google</a>
			<p class="auth-register-link">New here? <a href="register.php?redirect=<?= rawurlencode(auth_return_target()) ?>">Create an account</a></p>
		<?php endif; ?>
	</main>
</body>
</html>
