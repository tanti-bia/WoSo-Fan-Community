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

	return in_array($target, $allowedTargets, true) ? $target : 'home.php';
}

function require_signed_in(string $target): void
{
	enforce_account_restrictions();
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
	$returnTarget = in_array($target, $allowedTargets, true) ? $target : 'home.php';

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
	return ($_SESSION['role'] ?? '') === 'Administrator';
}

function enforce_account_restrictions(): void
{
	if (!isset($_SESSION['user_id']) || !is_numeric($_SESSION['user_id'])) {
		return;
	}
	require_once __DIR__ . '/database.php';
	try {
		$query = database_connection()->prepare(
			'SELECT banned_at, suspended_until > UTC_TIMESTAMP() AS is_suspended FROM users WHERE user_id = :user_id LIMIT 1'
		);
		$query->execute(['user_id' => (int) $_SESSION['user_id']]);
		$account = $query->fetch();
		if (!$account || $account['banned_at'] !== null || (int) $account['is_suspended'] === 1) {
			$_SESSION['account_restriction_notice'] = !$account || $account['banned_at'] !== null
				? 'This account has been banned.' : 'This account is temporarily blocked.';
			unset($_SESSION['user_id'], $_SESSION['username'], $_SESSION['email'], $_SESSION['role']);
			session_regenerate_id(true);
		}
	} catch (PDOException $exception) {
		error_log('WoSo account restriction check failed: ' . $exception->getMessage());
	}
}

function account_nav_link(bool $active = false): string
{
	$signedIn = isset($_SESSION['user_id']);
	$label = $signedIn ? 'Profile' : 'Sign in';
	$href = $signedIn ? 'profile.php' : 'login.php';

	return '<a' . ($active ? ' class="active" aria-current="page"' : '')
		. ' href="' . $href . '">' . $label . '</a>';
}

function notifications_nav_link(bool $active = false): string
{
	$count = null;
	$countAvailable = true;
	if (isset($_SESSION['user_id'])) {
		require_once __DIR__ . '/database.php';
		try {
			$database = database_connection();
			$query = $database->prepare(
				'SELECT COUNT(*) FROM notifications
				WHERE recipient_user_id = :user_id AND read_at IS NULL'
			);
			$query->execute(['user_id' => (int) $_SESSION['user_id']]);
			$count = (int) $query->fetchColumn();
		} catch (PDOException $exception) {
			error_log('WoSo notification badge database error: ' . $exception->getMessage());
			$countAvailable = false;
		}
	}

	$label = 'Notifications';
	$badge = '';
	if ($count !== null && $count > 0) {
		$displayCount = $count > 99 ? '99+' : (string) $count;
		$badge = '<span class="notification-badge" aria-label="' . escape_html($count . ' new notifications') . '">'
			. escape_html($displayCount) . '</span>';
		$label .= ' (' . $count . ' new)';
	} elseif (!$countAvailable) {
		$badge = '<span class="notification-badge is-unavailable" aria-label="Notification count unavailable">!</span>';
		$label .= ' (count unavailable)';
	}

	return '<a class="notification-nav-link' . ($active ? ' active' : '') . '"'
		. ($active ? ' aria-current="page"' : '')
		. ' href="notifications.php">' . escape_html($label) . $badge . '</a>';
}
