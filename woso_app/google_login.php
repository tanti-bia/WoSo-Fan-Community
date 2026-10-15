<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/google_oauth.php';

$config = google_oauth_config();
if ($config === null) {
	http_response_code(503);
	?>
	<!DOCTYPE html>
	<html lang="en">
	<head>
		<meta charset="utf-8">
		<meta name="viewport" content="width=device-width, initial-scale=1">
		<title>Google sign-in unavailable</title>
		<link rel="stylesheet" href="styles.css">
		<script src="back_button.js" defer></script>
	</head>
	<body>
		<main class="auth-card">
			<h1>Google sign-in is not configured</h1>
			<p>Set the Google OAuth environment variables in the server configuration, then try again.</p>
			<p><a href="login.php">Return to sign in</a></p>
		</main>
	</body>
	</html>
	<?php
	exit;
}

$state = bin2hex(random_bytes(32));
$nonce = bin2hex(random_bytes(32));
$_SESSION['google_oauth_state'] = $state;
$_SESSION['google_oauth_nonce'] = $nonce;
$_SESSION['google_oauth_redirect'] = auth_return_target();

$authorizationUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
	'client_id' => $config['client_id'],
	'redirect_uri' => $config['redirect_uri'],
	'response_type' => 'code',
	'scope' => 'openid email profile',
	'state' => $state,
	'nonce' => $nonce,
	'prompt' => 'select_account',
]);

header('Location: ' . $authorizationUrl);
exit;
