<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
	$isSecure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
	session_set_cookie_params([
		'httponly' => true,
		'secure' => $isSecure,
		'samesite' => 'Lax',
	]);
	session_start();
}

function escape_html(string $value): string
{
	return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function post_string(string $key): string
{
	return isset($_POST[$key]) && is_string($_POST[$key]) ? $_POST[$key] : '';
}

function csrf_token(): string
{
	if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
		$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
	}

	return $_SESSION['csrf_token'];
}

function has_valid_csrf_token(): bool
{
	return isset($_POST['csrf_token'], $_SESSION['csrf_token'])
		&& is_string($_POST['csrf_token'])
		&& is_string($_SESSION['csrf_token'])
		&& hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']);
}

function sign_in_user(array $user): void
{
	session_regenerate_id(true);
	$_SESSION['user_id'] = (int) $user['user_id'];
	$_SESSION['username'] = (string) $user['username'];
	$_SESSION['email'] = (string) ($user['email'] ?? '');
	$_SESSION['role'] = (string) $user['role'];
}

function auth_return_target(): string
{
	$target = $_SERVER['REQUEST_METHOD'] === 'POST'
		? post_string('redirect')
		: (isset($_GET['redirect']) && is_string($_GET['redirect']) ? $_GET['redirect'] : '');
	$allowedTargets = [
		'polls.php',
		'predictions.php',
		'profile.php',
		'notifications.php',
		'moderation.php',
	];

	return in_array($target, $allowedTargets, true) ? $target : 'home.html';
}

function require_signed_in(string $target): void
{
	if (isset($_SESSION['user_id'])) {
		return;
	}

	$allowedTargets = [
		'polls.php',
		'predictions.php',
		'profile.php',
		'notifications.php',
		'moderation.php',
	];
	$returnTarget = in_array($target, $allowedTargets, true) ? $target : 'home.html';

	header('Location: login.php?redirect=' . rawurlencode($returnTarget));
	exit;
}

function require_role(array $allowedRoles): void
{
	if (!in_array($_SESSION['role'] ?? '', $allowedRoles, true)) {
		http_response_code(403);
		exit('You do not have permission to access this page.');
	}
}

function can_access_moderation(): bool
{
	return in_array($_SESSION['role'] ?? '', ['Moderator', 'Administrator'], true);
}
