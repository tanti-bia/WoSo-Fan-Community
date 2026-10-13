<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/football_api.php';

$error = '';
$warning = '';
$fixtures = [];
$startDateInput = isset($_GET['date']) && is_string($_GET['date']) ? trim($_GET['date']) : '';
$endDateInput = isset($_GET['date_to']) && is_string($_GET['date_to']) ? trim($_GET['date_to']) : '';
$teamSearch = isset($_GET['team']) && is_string($_GET['team']) ? trim($_GET['team']) : '';
$competitionInput = isset($_GET['competition']) && is_string($_GET['competition'])
	? trim($_GET['competition'])
	: '';
$competitionId = null;
$parsedDate = null;
$parsedStartDate = null;
$parsedEndDate = null;
$isDateRange = false;
$isDefaultMatchFeed = false;
$defaultFeedSearchDays = 30;
$feedAnchorFixtureKey = null;

try {
	$leagueIds = football_api_league_ids();
	if ($leagueIds === []) {
		throw new RuntimeException('Configure WOSO_RAPIDAPI_LEAGUE_IDS with the IDs of the women’s competitions you want to show.');
	}

	if (strlen($teamSearch) > 80) {
		throw new RuntimeException('Team name must be no longer than 80 characters.');
	}

	if ($competitionInput !== '') {
		if (!ctype_digit($competitionInput) || !in_array((int) $competitionInput, $leagueIds, true)) {
			throw new RuntimeException('Choose a competition from the list.');
		}
		$competitionId = (int) $competitionInput;
	}

	$hasStartDate = $startDateInput !== '';
	$hasEndDate = $endDateInput !== '';
	$isDefaultMatchFeed = !$hasStartDate && !$hasEndDate
		&& $teamSearch === '' && $competitionId === null;
	if (!$hasStartDate && !$hasEndDate) {
		$searchLeagueIds = $competitionId === null ? $leagueIds : [$competitionId];
		foreach ($searchLeagueIds as $leagueId) {
			$leagueMatches = football_api_all_matches_for_league($leagueId);
			$fixtures = array_merge($fixtures, football_api_league_fixtures($leagueMatches, $leagueId));
		}
		if ($isDefaultMatchFeed) {
			$today = new DateTimeImmutable('today');
			$dateLookupsUnavailable = false;
			$searchDate = $today;
			$lastSearchDate = $today->modify('+' . $defaultFeedSearchDays . ' days');
			for (; $searchDate <= $lastSearchDate; $searchDate = $searchDate->modify('+1 day')) {
				$dateString = $searchDate->format('Y-m-d');
				if ($dateLookupsUnavailable) {
					$response = football_api_cached_matches_for_date($dateString);
					if ($response === null) {
						continue;
					}
				} else {
					try {
						$response = football_api_matches_for_date($dateString);
					} catch (RuntimeException $exception) {
						$warning = 'Upcoming match lookup could not continue at ' . $searchDate->format('j M Y')
							. ': ' . $exception->getMessage();
						$dateLookupsUnavailable = true;
						continue;
					}
				}
				$dateFixtures = football_api_fixtures($response, $leagueIds);
				$fixtures = array_merge($fixtures, $dateFixtures);

				$liveFixtures = array_values(array_filter($dateFixtures, 'football_match_is_live'));
				if ($liveFixtures !== []) {
					usort($liveFixtures, static fn (array $a, array $b): int => football_match_timeline_sort_key($a) <=> football_match_timeline_sort_key($b));
					if ($feedAnchorFixtureKey === null) {
						$feedAnchorFixtureKey = football_match_fixture_key($liveFixtures[0]);
					}
				}

				$upcomingFixtures = array_values(array_filter(
					$dateFixtures,
					static function (array $fixture): bool {
						if (football_match_is_finished($fixture)) {
							return false;
						}
						$matchDate = football_match_date_time($fixture);
						return $matchDate !== null && $matchDate->getTimestamp() >= time();
					}
				));
				if ($upcomingFixtures !== []) {
					usort($upcomingFixtures, static fn (array $a, array $b): int => football_match_timeline_sort_key($a) <=> football_match_timeline_sort_key($b));
					if ($feedAnchorFixtureKey === null) {
						$feedAnchorFixtureKey = football_match_fixture_key($upcomingFixtures[0]);
					}
				}
			}

			$latestFinishedDate = null;
			foreach ($fixtures as $fixture) {
				if (!football_match_is_finished($fixture)) {
					continue;
				}
				$matchDate = football_match_date_time($fixture);
				if ($matchDate !== null && ($latestFinishedDate === null || $matchDate > $latestFinishedDate)) {
					$latestFinishedDate = $matchDate;
				}
			}

			if ($latestFinishedDate !== null) {
				$backfillDate = $latestFinishedDate->modify('+1 day')->setTime(0, 0);
				$lastBackfillDate = $today->modify('-1 day');
				for (; $backfillDate <= $lastBackfillDate; $backfillDate = $backfillDate->modify('+1 day')) {
					$dateString = $backfillDate->format('Y-m-d');
					if ($dateLookupsUnavailable) {
						$response = football_api_cached_matches_for_date($dateString);
						if ($response === null) {
							continue;
						}
					} else {
						try {
							$response = football_api_matches_for_date($dateString);
						} catch (RuntimeException $exception) {
							$backfillWarning = 'Historical match lookup could not continue at ' . $backfillDate->format('j M Y')
								. ': ' . $exception->getMessage();
							$warning = $warning === '' ? $backfillWarning : $warning . ' ' . $backfillWarning;
							$dateLookupsUnavailable = true;
							continue;
						}
					}
					$fixtures = array_merge($fixtures, football_api_fixtures($response, $leagueIds));
				}
			}
			if ($dateLookupsUnavailable) {
				$warning .= ' Uncached past and upcoming dates may be missing until API access resumes.';
			}
		}
	} elseif ($hasStartDate && $hasEndDate) {
		$parsedStartDate = DateTimeImmutable::createFromFormat('!Y-m-d', $startDateInput);
		$parsedEndDate = DateTimeImmutable::createFromFormat('!Y-m-d', $endDateInput);
		if (!$parsedStartDate || $parsedStartDate->format('Y-m-d') !== $startDateInput
			|| !$parsedEndDate || $parsedEndDate->format('Y-m-d') !== $endDateInput) {
			throw new RuntimeException('Choose a valid date interval.');
		}
		if ($parsedStartDate > $parsedEndDate) {
			throw new RuntimeException('The interval start date must be on or before its end date.');
		}

		$isDateRange = true;
		$searchLeagueIds = $competitionId === null ? $leagueIds : [$competitionId];
		foreach ($searchLeagueIds as $leagueId) {
			$leagueMatches = football_api_all_matches_for_league($leagueId);
			$fixtures = array_merge($fixtures, football_api_league_fixtures($leagueMatches, $leagueId));
		}
		$fixtures = array_values(array_filter(
			$fixtures,
			static function (array $fixture) use ($startDateInput, $endDateInput): bool {
				$matchDate = football_match_date_time($fixture);
				if ($matchDate === null) {
					return false;
				}
				$matchDate = $matchDate->format('Y-m-d');
				return $matchDate >= $startDateInput && $matchDate <= $endDateInput;
			}
		));
	} else {
		$date = $hasStartDate ? $startDateInput : $endDateInput;
		$parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
		if (!$parsedDate || $parsedDate->format('Y-m-d') !== $date) {
			throw new RuntimeException('Choose a valid match date.');
		}
		$response = football_api_matches_for_date($date);
		$fixtures = football_api_fixtures($response, $leagueIds);
	}

	if ($competitionId !== null) {
		$fixtures = array_values(array_filter(
			$fixtures,
			static fn (array $fixture): bool => (int) ($fixture['league']['id'] ?? 0) === $competitionId
		));
	}

	if ($teamSearch !== '') {
		$fixtures = array_values(array_filter(
			$fixtures,
			static fn (array $fixture): bool => stripos(football_match_team_names($fixture), $teamSearch) !== false
		));
	}

	$sortKey = $isDefaultMatchFeed ? 'football_match_timeline_sort_key' : 'football_match_sort_key';
	usort($fixtures, static fn (array $a, array $b): int => $sortKey($a) <=> $sortKey($b));
	if ($isDefaultMatchFeed) {
		$uniqueFixtures = [];
		foreach ($fixtures as $fixture) {
			$uniqueFixtures[football_match_fixture_key($fixture)] = $fixture;
		}
		$fixtures = array_values($uniqueFixtures);
	}
} catch (RuntimeException $exception) {
	$error = $exception->getMessage();
}

$feedAnchorIndex = null;
if ($isDefaultMatchFeed && $fixtures !== []) {
	foreach ($fixtures as $index => $fixture) {
		if ($feedAnchorFixtureKey !== null && football_match_fixture_key($fixture) === $feedAnchorFixtureKey) {
			$feedAnchorIndex = $index;
			break;
		}
	}
	if ($feedAnchorIndex === null) {
		foreach ($fixtures as $index => $fixture) {
			if (football_match_is_live($fixture)) {
				$feedAnchorIndex = $index;
				break;
			}
		}
	}
	if ($feedAnchorIndex === null) {
		$now = time();
		foreach ($fixtures as $index => $fixture) {
			$matchDate = football_match_date_time($fixture);
			if (!football_match_is_finished($fixture)
				&& $matchDate !== null && $matchDate->getTimestamp() >= $now) {
				$feedAnchorIndex = $index;
				break;
			}
		}
	}
	if ($feedAnchorIndex === null) {
		foreach ($fixtures as $index => $fixture) {
			if (football_match_is_finished($fixture)) {
				$feedAnchorIndex = $index;
			}
		}
	}

	if ($feedAnchorIndex !== null) {
		$anchorFixture = $fixtures[$feedAnchorIndex];
		$feedAnchorFixtureKey = football_match_fixture_key($anchorFixture);
		$anchorDate = football_match_date_time($anchorFixture);
		$anchorTimestamp = $anchorDate === null ? null : $anchorDate->getTimestamp();
		$earlierFixtures = [];
		$activeFixtures = [];
		foreach ($fixtures as $fixture) {
			$matchDate = football_match_date_time($fixture);
			$isEarlier = football_match_is_finished($fixture)
				|| (!football_match_is_live($fixture)
					&& $anchorTimestamp !== null
					&& $matchDate !== null
					&& $matchDate->getTimestamp() < $anchorTimestamp);
			if ($isEarlier) {
				$earlierFixtures[] = $fixture;
			} else {
				$activeFixtures[] = $fixture;
			}
		}

		usort($earlierFixtures, static fn (array $a, array $b): int => football_match_timeline_sort_key($a) <=> football_match_timeline_sort_key($b));
		usort($activeFixtures, static fn (array $a, array $b): int => football_match_timeline_sort_key($a) <=> football_match_timeline_sort_key($b));
		$fixtures = array_merge($earlierFixtures, $activeFixtures);
		foreach ($fixtures as $index => $fixture) {
			if (football_match_fixture_key($fixture) === $feedAnchorFixtureKey) {
				$feedAnchorIndex = $index;
				break;
			}
		}
	}
}

$isSignedIn = isset($_SESSION['user_id']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Women's Football - Match Center</title>
	<link rel="stylesheet" href="styles.css">
</head>
<body>
	<nav class="topnav" aria-label="Main navigation">
		<a href="home.html">Home</a>
		<a class="active" aria-current="page" href="matches.php">Match Center</a>
		<a href="community.html">Community</a>
		<a href="polls.php">Polls</a>
		<a href="predictions.php">Predictions</a>
		<?= notifications_nav_link() ?>
		<?php if (can_access_moderation()): ?>
			<a href="moderation.php">Moderation</a>
		<?php endif; ?>
		<a href="login.php">Account</a>
	</nav>
	<main class="match-center">
		<h1>Match Center</h1>
		<p>With no filters, Match Center shows all available matches and lists the earliest live game or nearest upcoming match first. Earlier and later fixtures remain in the list.</p>
		<form class="match-search" action="matches.php" method="get">
			<input type="hidden" name="search" value="1">
			<div class="match-filter match-date-filter">
				<span class="match-filter-label">Match date</span>
				<div class="match-date-inputs" role="group" aria-label="Match date range">
					<label class="visually-hidden" for="match-date">From date</label>
					<span class="match-date-input">
						<input id="match-date" name="date" type="date" value="<?= escape_html($startDateInput) ?>">
						<span class="match-date-placeholder" aria-hidden="true" <?= $startDateInput !== '' ? 'hidden' : '' ?>>dd-mm-yyyy</span>
					</span>
					<label class="visually-hidden" for="match-date-to">To date</label>
					<span class="match-date-input">
						<input id="match-date-to" name="date_to" type="date" value="<?= escape_html($endDateInput) ?>">
						<span class="match-date-placeholder" aria-hidden="true" <?= $endDateInput !== '' ? 'hidden' : '' ?>>dd-mm-yyyy</span>
					</span>
				</div>
			</div>
			<div class="match-filter">
				<label for="team">Team</label>
				<input id="team" name="team" type="search" maxlength="80" value="<?= escape_html($teamSearch) ?>" placeholder="Search by team name">
			</div>
			<div class="match-filter">
				<label for="competition">Competition</label>
				<select id="competition" name="competition">
					<option value="">All competitions</option>
					<?php foreach ($leagueIds ?? [] as $leagueId): ?>
						<option value="<?= $leagueId ?>" <?= $competitionId === $leagueId ? 'selected' : '' ?>>
							<?= escape_html(football_api_competition_name($leagueId)) ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>
			<button class="auth-button" type="submit">Search matches</button>
		</form>
		<?php if (!$isSignedIn): ?>
			<p>Sign in to submit predictions on upcoming matches. <a href="login.php?redirect=predictions.php">Sign in</a></p>
		<?php endif; ?>
		<?php if ($warning !== ''): ?>
			<p class="auth-message auth-error" role="alert"><?= escape_html($warning) ?></p>
		<?php endif; ?>
		<?php if ($error !== ''): ?>
			<p class="auth-message auth-error" role="alert"><?= escape_html($error) ?></p>
		<?php elseif ($fixtures === []): ?>
			<p><?= $parsedDate !== null
				? 'No matches found in the selected competitions for this date.'
				: ($isDateRange
					? 'No matches found in the selected competitions for this date interval.'
					: ($isDefaultMatchFeed
						? 'No matches are available in the configured competitions.'
						: 'No matches found in the selected competitions for those search details.')) ?></p>
		<?php else: ?>
			<?php
			$searchDescription = [];
			if ($parsedDate !== null) {
				$searchDescription[] = 'for ' . $parsedDate->format('D j M Y');
			} elseif ($isDateRange) {
				$searchDescription[] = 'from ' . $parsedStartDate->format('D j M Y')
					. ' to ' . $parsedEndDate->format('D j M Y');
			}
			if ($teamSearch !== '') {
				$searchDescription[] = 'featuring ' . $teamSearch;
			}
			if ($competitionId !== null) {
				$searchDescription[] = 'in ' . football_api_competition_name($competitionId);
			}
			$resultsHeading = $searchDescription === []
				? 'All available matches'
				: 'Matches ' . implode(' — ', $searchDescription);
			?>
			<section aria-label="<?= escape_html($resultsHeading) ?>">
				<h2><?= escape_html($resultsHeading) ?></h2>
				<ul class="match-list<?= $isDefaultMatchFeed ? ' default-match-feed' : '' ?>"<?= $isDefaultMatchFeed ? ' id="match-timeline"' : '' ?>>
					<?php foreach ($fixtures as $index => $fixture): ?>
						<?php
						$homeTeam = $fixture['home'] ?? [];
						$awayTeam = $fixture['away'] ?? [];
						$matchDate = football_match_date_time($fixture);
						$isLive = football_match_is_live($fixture);
						$isFinished = football_match_is_finished($fixture);
						$isScheduled = !$isLive && !$isFinished;
						?>
						<li class="match-card"<?= $isDefaultMatchFeed && $index === $feedAnchorIndex ? ' id="match-feed-position"' : '' ?>>
							<p class="match-league">
								<?= escape_html((string) ($fixture['league']['name'] ?? 'Competition')) ?>
								<?php if (!empty($fixture['league']['country'])): ?>
									(<?= escape_html((string) $fixture['league']['country']) ?>)
								<?php endif; ?>
							</p>
							<p class="match-teams">
								<span class="match-home-team"><?= escape_html((string) ($homeTeam['name'] ?? 'TBD')) ?></span>
								<span class="match-center-details">
									<?php if ($isScheduled): ?>
										<span class="match-time">Scheduled</span>
										<strong class="match-score"><?= $matchDate === null ? 'Time TBA' : escape_html($matchDate->format('H:i')) ?></strong>
									<?php elseif ($matchDate !== null): ?>
										<time class="match-time" datetime="<?= escape_html($matchDate->format(DateTimeInterface::ATOM)) ?>"><?= escape_html($matchDate->format('D j M Y, H:i')) ?></time>
									<?php else: ?>
										<span class="match-time">Time unavailable</span>
									<?php endif; ?>
									<?php if ($isLive || $isFinished): ?>
										<strong class="match-score"><?= escape_html(football_match_score($homeTeam)) ?> : <?= escape_html(football_match_score($awayTeam)) ?></strong>
										<span class="match-status"><?= $isLive ? 'Live' : escape_html(football_match_status_label($fixture)) ?></span>
									<?php endif; ?>
								</span>
								<span class="match-away-team"><?= escape_html((string) ($awayTeam['name'] ?? 'TBD')) ?></span>
							</p>
						</li>
					<?php endforeach; ?>
				</ul>
			</section>
		<?php endif; ?>
		<p class="match-attribution">Match data provided by Free API Live Football Data on RapidAPI.</p>
	</main>
	<script>
		document.querySelectorAll('.match-date-input input').forEach((input) => {
			const placeholder = input.parentElement.querySelector('.match-date-placeholder');
			const updatePlaceholder = () => {
				placeholder.hidden = input.value !== '';
			};
			input.addEventListener('input', updatePlaceholder);
			input.addEventListener('change', updatePlaceholder);
		});
		const matchFeedPosition = document.getElementById('match-feed-position');
		const matchTimeline = document.getElementById('match-timeline');
		if (matchFeedPosition && matchTimeline) {
			matchTimeline.scrollTop += matchFeedPosition.getBoundingClientRect().top
				- matchTimeline.getBoundingClientRect().top;
			const remainingOffset = matchFeedPosition.getBoundingClientRect().top
				- matchTimeline.getBoundingClientRect().top;
			if (remainingOffset > 0) {
				matchTimeline.style.paddingBottom = `${Math.ceil(remainingOffset)}px`;
				matchTimeline.scrollTop += remainingOffset;
			}
		}
	</script>
</body>
</html>
