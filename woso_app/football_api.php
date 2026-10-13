<?php
declare(strict_types=1);

function football_api_league_ids(): array
{
	$value = getenv('WOSO_RAPIDAPI_LEAGUE_IDS');
	if ($value === false || trim($value) === '') {
		return [];
	}

	$leagueIds = [];
	foreach (explode(',', $value) as $candidate) {
		$candidate = trim($candidate);
		if ($candidate === '' || !ctype_digit($candidate) || (int) $candidate < 1) {
			throw new RuntimeException('WOSO_RAPIDAPI_LEAGUE_IDS must contain comma-separated positive league IDs.');
		}

		$leagueIds[] = (int) $candidate;
	}

	return array_values(array_unique($leagueIds));
}

function football_api_matches_for_date(string $date): array
{
	$apiKey = getenv('WOSO_RAPIDAPI_KEY');
	if ($apiKey === false || trim($apiKey) === '') {
		throw new RuntimeException('Match data is not configured. Set WOSO_RAPIDAPI_KEY on the PHP server.');
	}

	$apiHost = 'free-api-live-football-data.p.rapidapi.com';
	$url = 'https://' . $apiHost . '/football-get-matches-by-date-and-league?'
		. http_build_query(['date' => str_replace('-', '', $date)]);
	$cacheDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'woso-football-cache';
	$cachePath = $cacheDirectory . DIRECTORY_SEPARATOR . hash('sha256', $url) . '.json';
	$today = (new DateTimeImmutable('today'))->format('Y-m-d');
	$cacheTtl = $date < $today ? 86400 : ($date === $today ? 300 : 3600);

	if (is_file($cachePath)) {
		$cached = file_get_contents($cachePath);
		if ($cached !== false) {
			$cache = json_decode($cached, true);
			if (is_array($cache) && isset($cache['expires_at'], $cache['response'])
				&& is_numeric($cache['expires_at']) && (int) $cache['expires_at'] > time()
				&& is_array($cache['response'])) {
				return $cache['response'];
			}
		}
	}

	if (!is_dir($cacheDirectory) && !mkdir($cacheDirectory, 0700, true) && !is_dir($cacheDirectory)) {
		throw new RuntimeException('Unable to create the match data cache directory.');
	}

	$context = stream_context_create([
		'http' => [
			'method' => 'GET',
			'timeout' => 12,
			'ignore_errors' => true,
			'follow_location' => 0,
			'header' => "x-rapidapi-key: {$apiKey}\r\n"
				. "x-rapidapi-host: {$apiHost}\r\n"
				. "Accept: application/json\r\n",
		],
	]);
	$warning = '';
	set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
		$warning = $message;
		return true;
	});
	try {
		$body = file_get_contents($url, false, $context);
		$responseHeaders = $http_response_header ?? [];
	} finally {
		restore_error_handler();
	}

	if ($body === false) {
		throw new RuntimeException('The RapidAPI football service could not be reached: ' . $warning);
	}
	if (!isset($responseHeaders[0]) || !preg_match('/^HTTP\/\S+ 2\d\d\b/', $responseHeaders[0])) {
		throw new RuntimeException('RapidAPI returned an HTTP error. Check your API key and plan.');
	}

	$payload = json_decode($body, true);
	if (!is_array($payload) || ($payload['status'] ?? '') !== 'success'
		|| !isset($payload['response']) || !is_array($payload['response'])) {
		throw new RuntimeException('RapidAPI returned an invalid or unsuccessful match response.');
	}

	$cache = json_encode([
		'expires_at' => time() + $cacheTtl,
		'response' => $payload['response'],
	], JSON_UNESCAPED_SLASHES);
	if ($cache === false || file_put_contents($cachePath, $cache, LOCK_EX) === false) {
		error_log('WoSo match data warning: unable to write the RapidAPI response cache.');
	}

	return $payload['response'];
}

function football_api_fixtures(array $response, array $allowedLeagueIds): array
{
	$groups = $response['leagues'] ?? $response;
	if (isset($response['matches']) && is_array($response['matches'])) {
		$groups = [[
			'id' => $response['leagueId'] ?? null,
			'name' => $response['leagueName'] ?? '',
			'matches' => $response['matches'],
		]];
	}

	if (!is_array($groups)) {
		throw new RuntimeException('RapidAPI returned an unsupported match list.');
	}

	$fixtures = [];
	foreach ($groups as $group) {
		if (!is_array($group) || !isset($group['matches']) || !is_array($group['matches'])) {
			continue;
		}

		$leagueId = $group['id'] ?? $group['leagueId'] ?? null;
		if ($leagueId === null || !in_array((int) $leagueId, $allowedLeagueIds, true)) {
			continue;
		}

		foreach ($group['matches'] as $match) {
			if (!is_array($match)) {
				continue;
			}

			$match['league'] = [
				'id' => (int) $leagueId,
				'name' => (string) ($group['name'] ?? $group['leagueName'] ?? 'Competition'),
			];
			$fixtures[] = $match;
		}
	}

	return $fixtures;
}

function football_match_is_live(array $fixture): bool
{
	$status = $fixture['status'] ?? [];
	return ($status['started'] ?? false) === true
		&& ($status['finished'] ?? false) !== true
		&& ($status['cancelled'] ?? false) !== true;
}

function football_match_is_finished(array $fixture): bool
{
	$status = $fixture['status'] ?? [];
	return ($status['finished'] ?? false) === true;
}

function football_match_sort_key(array $fixture): array
{
	$statusOrder = football_match_is_live($fixture) ? 0 : (football_match_is_finished($fixture) ? 2 : 1);
	$utcTime = (string) ($fixture['status']['utcTime'] ?? '');
	$timestamp = $utcTime === '' ? PHP_INT_MAX : (strtotime($utcTime) ?: PHP_INT_MAX);
	return [$statusOrder, $statusOrder === 2 ? -$timestamp : $timestamp];
}

function football_match_team_names(array $fixture): string
{
	return (string) ($fixture['home']['name'] ?? '') . ' '
		. (string) ($fixture['away']['name'] ?? '');
}

function football_match_score(array $team): string
{
	$score = $team['score'] ?? null;
	if (is_array($score)) {
		$score = $score['current'] ?? $score['display'] ?? null;
	}

	return is_numeric($score) ? (string) (int) $score : '—';
}

function football_match_status_label(array $fixture): string
{
	$status = $fixture['status'] ?? [];
	$liveTime = $status['liveTime'] ?? null;
	if (is_array($liveTime)) {
		$liveTime = $liveTime['short'] ?? $liveTime['long'] ?? '';
	}
	if (is_string($liveTime) && $liveTime !== '') {
		return $liveTime;
	}

	$reason = $status['reason'] ?? [];
	if (is_array($reason)) {
		$reason = $reason['long'] ?? $reason['short'] ?? '';
	}
	if (is_string($reason) && $reason !== '') {
		return $reason;
	}
	if (($status['cancelled'] ?? false) === true) {
		return 'Cancelled';
	}
	if (football_match_is_live($fixture)) {
		return 'Live';
	}
	if (football_match_is_finished($fixture)) {
		return 'Finished';
	}

	return 'Scheduled';
}

function football_match_date_time(array $fixture): ?DateTimeImmutable
{
	$utcTime = $fixture['status']['utcTime'] ?? null;
	if (!is_string($utcTime) || $utcTime === '') {
		return null;
	}

	try {
		return new DateTimeImmutable($utcTime);
	} catch (Exception $exception) {
		error_log('WoSo match data warning: provider returned an invalid match date.');
		return null;
	}
}
