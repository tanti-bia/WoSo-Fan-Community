<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/google_oauth.php';

if (!GOOGLE_SIGN_IN_ENABLED) {
	header('Location: login.php', true, 303);
	exit;
}

$state = isset($_GET['state']) && is_string($_GET['state']) ? $_GET['state'] : '';
$expectedState = $_SESSION['google_oauth_state'] ?? '';
$nonce = $_SESSION['google_oauth_nonce'] ?? '';
$redirect = $_SESSION['google_oauth_redirect'] ?? 'home.php';
unset(
	$_SESSION['google_oauth_state'],
	$_SESSION['google_oauth_nonce'],
	$_SESSION['google_oauth_redirect']
);

$error = '';
$status = 400;
$config = google_oauth_config();

if (isset($_GET['error'])) {
	$error = 'Google sign-in was cancelled or denied.';
} elseif ($config === null) {
	$error = 'Google sign-in is not configured on this server.';
	$status = 503;
} elseif (!is_string($expectedState) || $expectedState === ''
	|| !is_string($nonce) || $nonce === ''
	|| $state === '' || !hash_equals($expectedState, $state)) {
	$error = 'The Google sign-in request could not be verified. Please try again.';
} elseif (!isset($_GET['code']) || !is_string($_GET['code']) || $_GET['code'] === '') {
	$error = 'Google did not return an authorization code. Please try again.';
} else {
	try {
		$tokenResponse = google_oauth_http_json('https://oauth2.googleapis.com/token', [
			'code' => $_GET['code'],
			'client_id' => $config['client_id'],
			'client_secret' => $config['client_secret'],
			'redirect_uri' => $config['redirect_uri'],
			'grant_type' => 'authorization_code',
		]);
		if (!isset($tokenResponse['id_token']) || !is_string($tokenResponse['id_token'])) {
			throw new RuntimeException('Google did not return an ID token.');
		}

		$claims = google_oauth_verify_id_token($tokenResponse['id_token'], $config['client_id'], $nonce);
		$database = database_connection();
		$user = google_oauth_find_or_create_user($database, $claims);
		sign_in_user($user);

		$allowedTargets = ['polls.php', 'predictions.php', 'community.php', 'profile.php', 'notifications.php', 'moderation.php'];
		if (!is_string($redirect) || !in_array($redirect, $allowedTargets, true)) {
			$redirect = 'home.php';
		}
		header('Location: ' . $redirect);
		exit;
	} catch (PDOException $exception) {
		error_log('WoSo Google sign-in database error: ' . $exception->getMessage());
		$error = 'Google sign-in could not complete because of a database error. Please try again.';
		$status = 503;
	} catch (RuntimeException $exception) {
		error_log('WoSo Google sign-in error: ' . $exception->getMessage());
		$error = 'Google sign-in could not be verified. Please try again or use email and password.';
		$status = 400;
	}
}

http_response_code($status);
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Google sign-in</title>
	<link rel="stylesheet" href="styles.css">
	<script src="back_button.js" defer></script>
</head>
<body>
	<main class="auth-card">
		<h1>Unable to sign in with Google</h1>
		<p class="auth-message auth-error" role="alert"><?= escape_html($error) ?></p>
		<p><a href="login.php">Return to sign in</a></p>
	</main>
</body>
</html>
