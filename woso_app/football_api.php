<?php
declare(strict_types=1);

function football_api_league_ids(): array
{
	$value = getenv('WOSO_THESPORTSDB_LEAGUE_IDS');
	if ($value === false || trim($value) === '') {
		$value = '4521,4849,5106,5203,5205,5204,5209,5208,4889';
	}

	$leagueIds = [];
	foreach (explode(',', $value) as $candidate) {
		$candidate = trim($candidate);
		if ($candidate === '' || !ctype_digit($candidate) || (int) $candidate < 1) {
			throw new RuntimeException('WOSO_THESPORTSDB_LEAGUE_IDS must contain comma-separated positive league IDs.');
		}

		$leagueIds[] = (int) $candidate;
	}

	return array_values(array_unique($leagueIds));
}

function football_api_competition_name(int $leagueId): string
{
	return 'Competition ' . $leagueId;
}

function football_api_key(): string
{
	$key = getenv('WOSO_THESPORTSDB_API_KEY');
	return $key === false || trim($key) === '' ? '123' : trim($key);
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
			'header' => "Accept: application/json\r\n"
				. "User-Agent: WoSo-Fan-Community/1.0\r\n",
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
		throw new RuntimeException('TheSportsDB could not be reached: ' . $warning);
	}
	if (!isset($responseHeaders[0]) || !preg_match('/^HTTP\/\S+ 2\d\d\b/', $responseHeaders[0])) {
		$status = isset($responseHeaders[0]) ? ' (' . $responseHeaders[0] . ')' : '';
		throw new RuntimeException('TheSportsDB returned an HTTP error' . $status . '.');
	}

	$payload = json_decode($body, true);
	if (!is_array($payload) || !array_key_exists('events', $payload)
		|| ($payload['events'] !== null && !is_array($payload['events']))) {
		throw new RuntimeException('TheSportsDB returned an invalid match response.');
	}

	$cache = json_encode([
		'expires_at' => time() + $cacheTtl,
		'response' => $payload,
	], JSON_UNESCAPED_SLASHES);
	if ($cache === false || file_put_contents($cachePath, $cache, LOCK_EX) === false) {
		error_log('WoSo match data warning: unable to write the TheSportsDB response cache.');
	}

	return $payload;
}

function football_api_matches_for_date(string $date): array
{
	$url = 'https://www.thesportsdb.com/api/v1/json/' . rawurlencode(football_api_key()) . '/eventsday.php?'
		. http_build_query(['d' => $date, 's' => 'Soccer']);

	return football_api_cached_request($url, 300);
}

function football_api_events_for_league(int $leagueId, string $direction): array
{
	if (!in_array($direction, ['next', 'past'], true)) {
		throw new InvalidArgumentException('Match lookup direction must be next or past.');
	}
	$url = 'https://www.thesportsdb.com/api/v1/json/' . rawurlencode(football_api_key()) . '/events' . $direction . 'league.php?'
		. http_build_query(['id' => $leagueId]);

	return football_api_cached_request($url, $direction === 'next' ? 3600 : 900);
}

function football_api_fixtures(array $response, array $allowedLeagueIds): array
{
	$events = $response['events'] ?? null;
	if ($events === null) {
		return [];
	}
	if (!is_array($events)) {
		throw new RuntimeException('TheSportsDB returned an unsupported event list.');
	}

	$fixtures = [];
	foreach ($events as $event) {
		if (!is_array($event)) {
			continue;
		}
		$leagueId = filter_var($event['idLeague'] ?? null, FILTER_VALIDATE_INT);
		if ($leagueId === false || !in_array($leagueId, $allowedLeagueIds, true)) {
			continue;
		}

		$fixtures[] = football_api_normalize_event($event, $leagueId);
	}

	return $fixtures;
}

function football_api_league_fixtures(array $response, int $leagueId): array
{
	$events = $response['events'] ?? null;
	if ($events === null) {
		return [];
	}
	if (!is_array($events)) {
		throw new RuntimeException('TheSportsDB returned an unsupported league event list.');
	}

	$fixtures = [];
	foreach ($events as $event) {
		if (!is_array($event)) {
			continue;
		}

		$fixtures[] = football_api_normalize_event($event, $leagueId);
	}

	return $fixtures;
}

function football_api_normalize_event(array $event, int $leagueId): array
{
	$eventName = trim((string) ($event['strEvent'] ?? ''));
	$homeName = trim((string) ($event['strHomeTeam'] ?? ''));
	$awayName = trim((string) ($event['strAwayTeam'] ?? ''));
	if (($homeName === '' || $awayName === '') && $eventName !== '') {
		$parts = preg_split('/\s+(?:vs?\.?|@)\s+/iu', $eventName, 2);
		if (is_array($parts) && count($parts) === 2) {
			$homeName = $homeName !== '' ? $homeName : trim($parts[0]);
			$awayName = $awayName !== '' ? $awayName : trim($parts[1]);
		}
	}
	$rawStatus = strtoupper(trim((string) ($event['strStatus'] ?? '')));
	$finishedStatuses = ['FT', 'AET', 'PEN', 'AWD', 'WO', 'FINISHED', 'MATCH FINISHED'];
	$cancelledStatuses = ['PST', 'CANC', 'ABD', 'POSTPONED', 'CANCELLED', 'ABANDONED'];
	$liveStatuses = ['1H', 'HT', '2H', 'ET', 'BT', 'P', 'INT', 'LIVE', 'IN PLAY', 'INPLAY', 'HALFTIME'];
	$isFinished = in_array($rawStatus, $finishedStatuses, true);
	$isCancelled = in_array($rawStatus, $cancelledStatuses, true)
		|| strtolower((string) ($event['strPostponed'] ?? '')) === 'yes';
	$isLive = in_array($rawStatus, $liveStatuses, true)
		|| preg_match('/^\d{1,3}(?:\+\d+)?\'$/', $rawStatus) === 1;
	$time = trim((string) ($event['strTimestamp'] ?? ''));
	if ($time === '') {
		$date = trim((string) ($event['dateEvent'] ?? ''));
		$eventTime = trim((string) ($event['strTime'] ?? ''));
		if ($date !== '') {
			$time = $date . 'T' . ($eventTime !== '' ? $eventTime : '00:00:00') . '+00:00';
		}
	} elseif (preg_match('/(?:Z|[+-]\d{2}:\d{2})$/i', $time) !== 1) {
		$time .= '+00:00';
	}

	return [
		'id' => (string) ($event['idEvent'] ?? ''),
		'eventName' => $eventName,
		'home' => [
			'name' => $homeName,
			'badge' => (string) ($event['strHomeTeamBadge'] ?? ''),
			'score' => $event['intHomeScore'] ?? null,
		],
		'away' => [
			'name' => $awayName,
			'badge' => (string) ($event['strAwayTeamBadge'] ?? ''),
			'score' => $event['intAwayScore'] ?? null,
		],
		'status' => [
			'utcTime' => $time,
			'started' => $isLive || $isFinished,
			'finished' => $isFinished,
			'cancelled' => $isCancelled,
			'liveTime' => $isLive ? $rawStatus : '',
			'reason' => $isCancelled ? $rawStatus : '',
		],
		'notStarted' => !$isLive && !$isFinished,
		'league' => [
			'id' => $leagueId,
			'name' => (string) ($event['strLeague'] ?? 'Competition'),
			'season' => (string) ($event['strSeason'] ?? ''),
			'country' => (string) ($event['strCountry'] ?? ''),
		],
		'venue' => (string) ($event['strVenue'] ?? ''),
		'video' => (string) ($event['strVideo'] ?? ''),
	];
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
	// Provision the small shared cache table on first use so deployments with
	// an already-configured database connection do not need a separate SQL client.
	$database->exec(
		'CREATE TABLE IF NOT EXISTS football_match_cache
		(
			cache_key CHAR(64) NOT NULL,
			fixtures_json LONGTEXT NOT NULL,
			fetched_at BIGINT UNSIGNED NOT NULL,
			PRIMARY KEY (cache_key)
		) ENGINE=InnoDB
			DEFAULT CHARACTER SET utf8mb4
			COLLATE utf8mb4_unicode_ci'
	);

	$leagueIds = football_api_league_ids();
	$cacheKey = hash('sha256', 'thesportsdb:' . football_api_key() . ':' . implode(',', $leagueIds));
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

	if ($cachedFixtures !== null && $cachedAt >= time() - 300) {
		return ['fixtures' => $cachedFixtures, 'warning' => null];
	}

	try {
		$fixtures = [];
		// The free daily endpoint returns only a small global sample, most of which
		// may be outside the configured leagues. Use the league-specific schedules
		// so a refresh always includes the selected competitions.
		foreach ($leagueIds as $leagueId) {
			foreach (['past', 'next'] as $direction) {
				$leagueEvents = football_api_events_for_league($leagueId, $direction);
				foreach (football_api_league_fixtures($leagueEvents, $leagueId) as $fixture) {
					$fixtures[football_match_fixture_key($fixture)] = $fixture;
				}
			}
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
			if (str_starts_with($exception->getMessage(), 'TheSportsDB ')) {
				error_log('WoSo match data warning: TheSportsDB is unavailable and no shared fixture cache exists. ' . $exception->getMessage());
				return [
					'fixtures' => [],
					'warning' => 'Live results unavailable. No matches have been saved yet; please try again later.',
				];
			}
			throw $exception;
		}

		error_log('WoSo match data warning: fixture refresh failed; serving the shared cached fixtures. ' . $exception->getMessage());
		return [
			'fixtures' => $cachedFixtures,
			'warning' => $cachedFixtures === []
				? 'Live results unavailable. The last successful request found no matches for the selected leagues.'
				: 'Live results unavailable. Showing matches from the last successful request.',
		];
	} catch (JsonException $exception) {
		throw new RuntimeException('TheSportsDB returned match data that could not be saved.', 0, $exception);
	}
}

function football_match_is_cancelled(array $fixture): bool
{
	$status = $fixture['status'] ?? [];
	return ($status['cancelled'] ?? false) === true;
}
