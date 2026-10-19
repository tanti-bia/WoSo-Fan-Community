<?php
declare(strict_types=1);

function google_sign_in_enabled(): bool
{
	$enabled = getenv('WOSO_GOOGLE_SIGN_IN_ENABLED');
	return is_string($enabled)
		&& strtolower(trim($enabled)) === 'true'
		&& google_oauth_config() !== null;
}

function google_oauth_config(): ?array
{
	$clientId = getenv('WOSO_GOOGLE_CLIENT_ID');
	$clientSecret = getenv('WOSO_GOOGLE_CLIENT_SECRET');
	$redirectUri = getenv('WOSO_GOOGLE_REDIRECT_URI');

	if (!$clientId || !$clientSecret || !$redirectUri) {
		return null;
	}

	$scheme = parse_url($redirectUri, PHP_URL_SCHEME);
	$host = parse_url($redirectUri, PHP_URL_HOST);
	$isLocalHttp = $scheme === 'http' && in_array($host, ['localhost', '127.0.0.1', '[::1]'], true);
	if (filter_var($redirectUri, FILTER_VALIDATE_URL) === false
		|| (!$isLocalHttp && $scheme !== 'https')
		|| parse_url($redirectUri, PHP_URL_USER) !== null
		|| parse_url($redirectUri, PHP_URL_PASS) !== null) {
		return null;
	}

	return [
		'client_id' => $clientId,
		'client_secret' => $clientSecret,
		'redirect_uri' => $redirectUri,
	];
}

function google_oauth_http_json(string $url, ?array $formData = null): array
{
	$options = [
		'http' => [
			'method' => $formData === null ? 'GET' : 'POST',
			'timeout' => 10,
			'ignore_errors' => true,
			'follow_location' => 0,
			'header' => "Accept: application/json\r\n",
		],
	];

	if ($formData !== null) {
		$options['http']['header'] .= "Content-Type: application/x-www-form-urlencoded\r\n";
		$options['http']['content'] = http_build_query($formData);
	}

	$context = stream_context_create($options);
	$body = @file_get_contents($url, false, $context);
	if ($body === false || !isset($http_response_header[0])
		|| !preg_match('/^HTTP\/\S+ 2\d\d\b/', $http_response_header[0])) {
		throw new RuntimeException('Google OAuth HTTP request failed.');
	}

	$data = json_decode($body, true);
	if (!is_array($data)) {
		throw new RuntimeException('Google OAuth returned an invalid response.');
	}

	return $data;
}

function google_oauth_decode_base64url(string $value): string
{
	$decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);
	if ($decoded === false) {
		throw new RuntimeException('Google returned an invalid ID token.');
	}

	return $decoded;
}

function google_oauth_verify_id_token(string $idToken, string $clientId, string $expectedNonce): array
{
	if (!function_exists('openssl_verify')) {
		throw new RuntimeException('The PHP OpenSSL extension is required for Google sign-in.');
	}

	$segments = explode('.', $idToken);
	if (count($segments) !== 3) {
		throw new RuntimeException('Google returned an invalid ID token.');
	}

	$header = json_decode(google_oauth_decode_base64url($segments[0]), true);
	$claims = json_decode(google_oauth_decode_base64url($segments[1]), true);
	$signature = google_oauth_decode_base64url($segments[2]);
	if (!is_array($header) || !is_array($claims)
		|| ($header['alg'] ?? '') !== 'RS256'
		|| !is_string($header['kid'] ?? null)) {
		throw new RuntimeException('Google returned an invalid ID token.');
	}

	$keys = google_oauth_http_json('https://www.googleapis.com/oauth2/v3/certs');
	$certificate = null;
	foreach ($keys['keys'] ?? [] as $key) {
		if (is_array($key) && ($key['kid'] ?? '') === $header['kid']
			&& ($key['kty'] ?? '') === 'RSA'
			&& isset($key['x5c'][0]) && is_string($key['x5c'][0])) {
			$certificate = "-----BEGIN CERTIFICATE-----\n"
				. chunk_split($key['x5c'][0], 64, "\n")
				. "-----END CERTIFICATE-----\n";
			break;
		}
	}

	if ($certificate === null
		|| openssl_verify($segments[0] . '.' . $segments[1], $signature, $certificate, OPENSSL_ALGO_SHA256) !== 1) {
		throw new RuntimeException('Google ID token signature verification failed.');
	}

	$now = time();
	$issuer = $claims['iss'] ?? '';
	$audience = $claims['aud'] ?? '';
	$audiences = is_array($audience) ? $audience : [$audience];
	if (!in_array($issuer, ['https://accounts.google.com', 'accounts.google.com'], true)
		|| !in_array($clientId, $audiences, true)
		|| (count($audiences) > 1 && ($claims['azp'] ?? '') !== $clientId)
		|| !isset($claims['exp'], $claims['iat'])
		|| !is_numeric($claims['exp']) || (int) $claims['exp'] <= $now
		|| !is_numeric($claims['iat']) || (int) $claims['iat'] > $now + 300
		|| !isset($claims['nonce']) || !is_string($claims['nonce'])
		|| !hash_equals($expectedNonce, $claims['nonce'])
		|| !isset($claims['sub']) || !is_string($claims['sub']) || $claims['sub'] === ''
		|| !isset($claims['email']) || !is_string($claims['email'])
		|| !filter_var($claims['email'], FILTER_VALIDATE_EMAIL)
		|| strlen($claims['email']) > 254
		|| ($claims['email_verified'] ?? false) !== true) {
		throw new RuntimeException('Google ID token claims could not be validated.');
	}

	return $claims;
}

function google_oauth_find_or_create_user(PDO $database, array $claims): array
{
	$query = $database->prepare(
		'SELECT user_id, username, email, role
		FROM users
		WHERE google_sub = :google_sub
		LIMIT 1'
	);
	$query->execute(['google_sub' => $claims['sub']]);
	$user = $query->fetch();
	if ($user) {
		return $user;
	}

	$email = strtolower($claims['email']);
	$query = $database->prepare(
		'SELECT user_id, username, email, role, google_sub
		FROM users
		WHERE email = :email
		LIMIT 1'
	);
	$query->execute(['email' => $email]);
	$user = $query->fetch();
	if ($user) {
		if ($user['google_sub'] !== null && !hash_equals((string) $user['google_sub'], $claims['sub'])) {
			throw new RuntimeException('This Google account is linked to a different community account.');
		}

		$update = $database->prepare(
			'UPDATE users SET google_sub = :google_sub
			WHERE user_id = :user_id AND google_sub IS NULL'
		);
		$update->execute([
			'google_sub' => $claims['sub'],
			'user_id' => $user['user_id'],
		]);

		if ($update->rowCount() !== 1) {
			$query->execute(['google_sub' => $claims['sub']]);
			$user = $query->fetch();
			if (!$user) {
				throw new RuntimeException('The Google account could not be linked to this community account.');
			}

			return $user;
		}

		unset($user['google_sub']);
		return $user;
	}

	$emailName = strstr($email, '@', true);
	$usernamePrefix = preg_replace('/[^A-Za-z0-9_]/', '', is_string($emailName) ? $emailName : '');
	$usernamePrefix = substr($usernamePrefix, 0, 17);
	if (strlen($usernamePrefix) < 3) {
		$usernamePrefix = 'user';
	}
	$username = $usernamePrefix . '_' . bin2hex(random_bytes(6));

	$insert = $database->prepare(
		'INSERT INTO users (username, email, password_hash, google_sub, role)
		VALUES (:username, :email, :password_hash, :google_sub, :role)'
	);
	$insert->execute([
		'username' => $username,
		'email' => $email,
		'password_hash' => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
		'google_sub' => $claims['sub'],
		'role' => 'Member',
	]);

	return [
		'user_id' => (int) $database->lastInsertId(),
		'username' => $username,
		'email' => $email,
		'role' => 'Member',
	];
}
