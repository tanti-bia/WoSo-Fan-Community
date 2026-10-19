<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

function ensure_pending_registrations_table(PDO $database): void
{
	$database->exec(
		'CREATE TABLE IF NOT EXISTS pending_registrations
		(
			pending_registration_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			username VARCHAR(30) NOT NULL,
			email VARCHAR(254) NOT NULL,
			password_hash VARCHAR(255) NOT NULL,
			verification_token_hash CHAR(64) NOT NULL,
			redirect_target VARCHAR(32) NOT NULL DEFAULT \'home.php\',
			expires_at DATETIME NOT NULL,
			last_requested_at DATETIME NOT NULL,
			created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (pending_registration_id),
			UNIQUE KEY pending_registrations_username_unique (username),
			UNIQUE KEY pending_registrations_email_unique (email),
			UNIQUE KEY pending_registrations_token_unique (verification_token_hash),
			KEY pending_registrations_expiry (expires_at)
		) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
	);
}

function ensure_password_reset_requests_table(PDO $database): void
{
	$database->exec(
		'CREATE TABLE IF NOT EXISTS password_reset_requests
		(
			user_id INT UNSIGNED NOT NULL,
			token_hash CHAR(64) NOT NULL,
			expires_at DATETIME NOT NULL,
			last_requested_at DATETIME NOT NULL,
			created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (user_id),
			UNIQUE KEY password_reset_token_unique (token_hash),
			KEY password_reset_expiry (expires_at),
			CONSTRAINT password_reset_user_fk FOREIGN KEY (user_id)
				REFERENCES users (user_id) ON DELETE CASCADE
		) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
	);
}

function email_verification_base_url(): string
{
	$configuredUrl = getenv('WOSO_APP_BASE_URL');
	if (is_string($configuredUrl) && trim($configuredUrl) !== '') {
		$baseUrl = rtrim(trim($configuredUrl), '/');
	} else {
		$host = $_SERVER['HTTP_HOST'] ?? '';
		if (!is_string($host) || !in_array(strtolower($host), ['localhost', '127.0.0.1'], true)) {
			throw new RuntimeException('Email verification URL is not configured.');
		}
		$scriptDirectory = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
		$baseUrl = 'http://' . $host . rtrim($scriptDirectory, '/');
	}

	$parts = parse_url($baseUrl);
	$isLocalHttp = ($parts['scheme'] ?? '') === 'http'
		&& in_array(strtolower((string) ($parts['host'] ?? '')), ['localhost', '127.0.0.1'], true);
	if (filter_var($baseUrl, FILTER_VALIDATE_URL) === false
		|| (!$isLocalHttp && ($parts['scheme'] ?? '') !== 'https')
		|| isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
		throw new RuntimeException('Email verification URL configuration is invalid.');
	}

	return $baseUrl;
}

function send_account_email(string $email, string $subject, string $html, string $text): void
{
	$apiKey = getenv('WOSO_RESEND_API_KEY');
	$from = getenv('WOSO_EMAIL_FROM');
	if (!is_string($apiKey) || trim($apiKey) === '' || !is_string($from) || trim($from) === '') {
		throw new RuntimeException('Email delivery is not configured.');
	}

	$payload = json_encode([
		'from' => trim($from),
		'to' => [$email],
		'subject' => $subject,
		'html' => $html,
		'text' => $text,
	], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
	if (!is_string($payload)) {
		throw new RuntimeException('Could not prepare the verification email.');
	}

	$options = [
		'http' => [
			'method' => 'POST',
			'timeout' => 10,
			'ignore_errors' => true,
			'follow_location' => 0,
			'header' => "Authorization: Bearer " . trim($apiKey) . "\r\n"
				. "Content-Type: application/json\r\nAccept: application/json\r\n",
			'content' => $payload,
		],
	];
	$context = stream_context_create($options);
	$response = @file_get_contents('https://api.resend.com/emails', false, $context);
	$statusLine = $http_response_header[0] ?? '';
	if ($response === false || !is_string($statusLine) || !preg_match('/^HTTP\/\S+ 2\d\d\b/', $statusLine)) {
		throw new RuntimeException('The account email could not be sent.');
	}
}

function send_email_verification(string $email, string $username, string $verificationUrl): void
{
	$safeUsername = escape_html($username);
	$safeUrl = escape_html($verificationUrl);
	$html = '<p>Hello ' . $safeUsername . ',</p>'
		. '<p>Confirm your email address to finish creating your Women\'s Football Community account.</p>'
		. '<p><a href="' . $safeUrl . '">Confirm email address</a></p>'
		. '<p>This link expires in 24 hours. If you did not request this account, you can ignore this email.</p>';
	$text = "Hello {$username},\n\nConfirm your email address to finish creating your Women's Football Community account:\n"
		. $verificationUrl . "\n\nThis link expires in 24 hours. If you did not request this account, you can ignore this email.";
	send_account_email($email, 'Confirm your email address', $html, $text);
}

function send_password_reset_email(string $email, string $username, string $resetUrl): void
{
	$safeUsername = escape_html($username);
	$safeUrl = escape_html($resetUrl);
	$html = '<p>Hello ' . $safeUsername . ',</p>'
		. '<p>Use this link to choose a new password for your Women\'s Football Community account.</p>'
		. '<p><a href="' . $safeUrl . '">Reset your password</a></p>'
		. '<p>This link expires in one hour and can only be used once. If you did not request a password reset, you can ignore this email.</p>';
	$text = "Hello {$username},\n\nUse this link to choose a new password for your Women's Football Community account:\n"
		. $resetUrl . "\n\nThis link expires in one hour and can only be used once. If you did not request a password reset, you can ignore this email.";
	send_account_email($email, 'Reset your password', $html, $text);
}
