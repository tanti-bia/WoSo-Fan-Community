<?php
declare(strict_types=1);

function football_api_league_ids(): array
{
	$value = getenv('WOSO_RAPIDAPI_LEAGUE_IDS');
	if ($value === false || trim($value) === '') {
		throw new RuntimeException('Match data is not configured. Set WOSO_RAPIDAPI_LEAGUE_IDS to the women\'s competition IDs you want to show.');
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

function football_api_competition_name(int $leagueId): string
{
	$names = [
		9227 => 'WSL',
		9907 => 'Liga F',
		9676 => 'Frauen-Bundesliga',
		9677 => 'Première Ligue Féminine',
		9495 => 'A-League Women',
		10210 => "Women's Premier Division",
		10699 => 'USL Super League Women',
		9375 => "Women's Champions League",
		76 => "Women's World Cup",
		292 => "Women's EURO",
		10457 => "Women's Nations League A",
		10082 => 'FA Cup (Women)',
		9717 => "Women's League Cup",
		10650 => 'DFB Pokal Frauen',
	];

	return $names[$leagueId] ?? 'Competition ' . $leagueId;
}

function football_api_cached_response(string $url, int $extendTtl = 0): ?array
{
	$cacheDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'woso-football-cache';
	$cachePath = $cacheDirectory . DIRECTORY_SEPARATOR . hash('sha256', $url) . '.json';
	if (!is_file($cachePath)) {
		return null;
	}

	$cached = file_get_contents($cachePath);
	if ($cached === false) {
		return null;
	}
	$cache = json_decode($cached, true);
	if (!is_array($cache) || !isset($cache['expires_at'], $cache['response'])
		|| !is_numeric($cache['expires_at']) || (int) $cache['expires_at'] <= time()
		|| !is_array($cache['response'])) {
		return null;
	}
	if ($extendTtl > 0 && (int) $cache['expires_at'] < time() + $extendTtl) {
		$renewedCache = json_encode([
			'expires_at' => time() + $extendTtl,
			'response' => $cache['response'],
		], JSON_UNESCAPED_SLASHES);
		if ($renewedCache === false || file_put_contents($cachePath, $renewedCache, LOCK_EX) === false) {
			error_log('WoSo match data warning: unable to extend a historical response cache.');
		}
	}

	return $cache['response'];
}

function football_api_cached_request(string $url, int $cacheTtl, bool $extendCachedTtl = false): array
{
	$cacheDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'woso-football-cache';
	$cachedResponse = football_api_cached_response($url, $extendCachedTtl ? $cacheTtl : 0);
	if ($cachedResponse !== null) {
		return $cachedResponse;
	}
	$apiKey = getenv('WOSO_RAPIDAPI_KEY');
	if ($apiKey === false || trim($apiKey) === '') {
		throw new RuntimeException('Match data is not configured. Set WOSO_RAPIDAPI_KEY on the PHP server.');
	}

	$apiHost = 'free-api-live-football-data.p.rapidapi.com';
	$cachePath = $cacheDirectory . DIRECTORY_SEPARATOR . hash('sha256', $url) . '.json';

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
		$status = isset($responseHeaders[0]) ? ' (' . $responseHeaders[0] . ')' : '';
		throw new RuntimeException('RapidAPI returned an HTTP error' . $status . '. Check your API key and plan.');
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

function football_api_matches_for_date(string $date): array
{
	$apiHost = 'free-api-live-football-data.p.rapidapi.com';
	$url = 'https://' . $apiHost . '/football-get-matches-by-date-and-league?'
		. http_build_query(['date' => str_replace('-', '', $date)]);
	$today = (new DateTimeImmutable('today'))->format('Y-m-d');
	$cacheTtl = $date < $today ? 2592000 : ($date === $today ? 900 : 3600);

	return football_api_cached_request($url, $cacheTtl, $date < $today);
}

function football_api_cached_matches_for_date(string $date): ?array
{
	$apiHost = 'free-api-live-football-data.p.rapidapi.com';
	$url = 'https://' . $apiHost . '/football-get-matches-by-date-and-league?'
		. http_build_query(['date' => str_replace('-', '', $date)]);
	$today = (new DateTimeImmutable('today'))->format('Y-m-d');
	$cacheTtl = $date < $today ? 2592000 : ($date === $today ? 900 : 3600);

	return football_api_cached_response($url, $date < $today ? $cacheTtl : 0);
}

function football_api_all_matches_for_league(int $leagueId): array
{
	$apiHost = 'free-api-live-football-data.p.rapidapi.com';
	$url = 'https://' . $apiHost . '/football-get-all-matches-by-league?'
		. http_build_query(['leagueid' => $leagueId]);
	$response = football_api_cached_request($url, 86400);
	if (!isset($response['matches']) || !is_array($response['matches'])) {
		throw new RuntimeException('RapidAPI returned an unsupported all-matches response.');
	}

	return $response['matches'];
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
				'country' => (string) ($group['ccode'] ?? $group['country'] ?? ''),
			];
			$fixtures[] = $match;
		}
	}

	return $fixtures;
}

function football_api_league_fixtures(array $matches, int $leagueId): array
{
	$fixtures = [];
	foreach ($matches as $match) {
		if (!is_array($match)) {
			continue;
		}

		$tournament = $match['displayTournament'] ?? $match['tournament'] ?? [];
		$leagueName = is_string($tournament)
			? $tournament
			: (is_array($tournament) ? (string) ($tournament['name'] ?? '') : '');
		$country = is_array($tournament)
			? (string) ($tournament['ccode'] ?? $tournament['country'] ?? '')
			: '';
		$match['league'] = [
			'id' => $leagueId,
			'name' => $leagueName !== '' ? $leagueName : 'Competition',
			'country' => $country,
		];
		$fixtures[] = $match;
	}

	return $fixtures;
}

function football_match_is_live(array $fixture): bool
{
	$status = $fixture['status'] ?? [];
	return ($status['started'] ?? false) === true
		&& ($fixture['notStarted'] ?? false) !== true
		&& ($status['finished'] ?? false) !== true
		&& ($status['cancelled'] ?? false) !== true;
}

function football_match_is_finished(array $fixture): bool
{
	$status = $fixture['status'] ?? [];
	return ($status['finished'] ?? false) === true
		|| ($fixture['finished'] ?? false) === true;
}

function football_match_sort_key(array $fixture): array
{
	$statusOrder = football_match_is_live($fixture) ? 0 : (football_match_is_finished($fixture) ? 2 : 1);
	$utcTime = (string) ($fixture['status']['utcTime'] ?? '');
	$timestamp = $utcTime === '' ? PHP_INT_MAX : (strtotime($utcTime) ?: PHP_INT_MAX);
	return [$statusOrder, $statusOrder === 2 ? -$timestamp : $timestamp];
}

function football_match_timeline_sort_key(array $fixture): array
{
	$utcTime = (string) ($fixture['status']['utcTime'] ?? '');
	$timestamp = $utcTime === '' ? PHP_INT_MAX : (strtotime($utcTime) ?: PHP_INT_MAX);
	return [$timestamp, (int) ($fixture['id'] ?? 0)];
}

function football_match_fixture_key(array $fixture): string
{
	if (isset($fixture['id']) && (is_string($fixture['id']) || is_int($fixture['id'])) && (string) $fixture['id'] !== '') {
		return 'id:' . (string) $fixture['id'];
	}

	return 'fixture:' . (string) ($fixture['league']['id'] ?? 0) . '|'
		. (string) ($fixture['status']['utcTime'] ?? '') . '|'
		. football_match_team_names($fixture);
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

	if (($fixture['notStarted'] ?? false) === true) {
		return 'Scheduled';
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
		return (new DateTimeImmutable($utcTime))->setTimezone(new DateTimeZone(date_default_timezone_get()));
	} catch (Exception $exception) {
		error_log('WoSo match data warning: provider returned an invalid match date.');
		return null;
	}
}

/**
 * @return array{fixtures: list<array>, warning: ?string}
 */
function football_api_shared_fixtures(PDO $database): array
{
	$leagueIds = football_api_league_ids();
	$cacheKey = hash('sha256', implode(',', $leagueIds));
	$cacheQuery = $database->prepare(
		'SELECT fixtures_json, fetched_at
		FROM football_match_cache
		WHERE cache_key = :cache_key'
	);
	$cacheQuery->execute(['cache_key' => $cacheKey]);
	$cachedRow = $cacheQuery->fetch();
	$cachedFixtures = null;
	$cachedAt = 0;

	if ($cachedRow !== false) {
		$cachedAt = (int) $cachedRow['fetched_at'];
		try {
			$decodedFixtures = json_decode((string) $cachedRow['fixtures_json'], true, 512, JSON_THROW_ON_ERROR);
			if (is_array($decodedFixtures) && array_is_list($decodedFixtures)) {
				$cachedFixtures = $decodedFixtures;
			} else {
				error_log('WoSo match data warning: the shared fixture cache has an unsupported format.');
			}
		} catch (JsonException $exception) {
			error_log('WoSo match data warning: unable to decode the shared fixture cache.');
		}
	}

	if ($cachedFixtures !== null && $cachedAt >= time() - 900) {
		return ['fixtures' => $cachedFixtures, 'warning' => null];
	}

	try {
		$fixtures = [];
		foreach ($leagueIds as $leagueId) {
			$leagueMatches = football_api_all_matches_for_league($leagueId);
			foreach (football_api_league_fixtures($leagueMatches, $leagueId) as $fixture) {
				$fixtures[football_match_fixture_key($fixture)] = $fixture;
			}
		}

		$today = (new DateTimeImmutable('today'))->format('Y-m-d');
		$todayFixtures = football_api_fixtures(football_api_matches_for_date($today), $leagueIds);
		foreach ($todayFixtures as $fixture) {
			$fixtures[football_match_fixture_key($fixture)] = $fixture;
		}
		$fixtures = array_values($fixtures);
		$fixturesJson = json_encode($fixtures, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

		$storeQuery = $database->prepare(
			'INSERT INTO football_match_cache (cache_key, fixtures_json, fetched_at)
			VALUES (:cache_key, :fixtures_json, :fetched_at)
			ON DUPLICATE KEY UPDATE
				fixtures_json = VALUES(fixtures_json),
				fetched_at = VALUES(fetched_at)'
		);
		try {
			$storeQuery->execute([
				'cache_key' => $cacheKey,
				'fixtures_json' => $fixturesJson,
				'fetched_at' => time(),
			]);
		} catch (PDOException $exception) {
			error_log('WoSo match data warning: unable to save the shared fixture cache. ' . $exception->getMessage());
			return [
				'fixtures' => $fixtures,
				'warning' => 'Fixtures loaded, but could not be saved for reuse. Check database permissions.',
			];
		}

		return ['fixtures' => $fixtures, 'warning' => null];
	} catch (RuntimeException $exception) {
		if ($cachedFixtures === null) {
			throw $exception;
		}

		error_log('WoSo match data warning: fixture refresh failed; serving the shared cached fixtures. ' . $exception->getMessage());
		return [
			'fixtures' => $cachedFixtures,
			'warning' => 'Match data could not be refreshed. Showing the last saved fixtures.',
		];
	} catch (JsonException $exception) {
		throw new RuntimeException('The football service returned match data that could not be saved.', 0, $exception);
	}
}

function football_match_is_cancelled(array $fixture): bool
{
	$status = $fixture['status'] ?? [];
	return ($status['cancelled'] ?? false) === true;
}
