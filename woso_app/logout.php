<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	http_response_code(405);
	header('Allow: POST');
	exit('Use the sign-out button to end your session.');
}

if (!has_valid_csrf_token()) {
	http_response_code(400);
	exit('Your session expired. Return to the account page and try again.');
}

$_SESSION = [];
$cookie = session_get_cookie_params();
setcookie(session_name(), '', [
	'expires' => time() - 42000,
	'path' => $cookie['path'],
	'domain' => $cookie['domain'],
	'secure' => $cookie['secure'],
	'httponly' => $cookie['httponly'],
	'samesite' => 'Lax',
]);
session_destroy();

header('Location: login.php?logged_out=1');
exit;
