<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/football_api.php';

$error = '';
$fixtures = [];
$searchSubmitted = isset($_GET['search']);
$date = isset($_GET['date']) && is_string($_GET['date'])
	? $_GET['date']
	: (new DateTimeImmutable('today'))->format('Y-m-d');
$teamSearch = isset($_GET['team']) && is_string($_GET['team']) ? trim($_GET['team']) : '';
$parsedDate = null;

try {
	$leagueIds = football_api_league_ids();
	if ($leagueIds === []) {
		throw new RuntimeException('Configure WOSO_RAPIDAPI_LEAGUE_IDS with the IDs of the women’s competitions you want to show.');
	}

	$parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
	if (!$parsedDate || $parsedDate->format('Y-m-d') !== $date || strlen($teamSearch) > 80) {
		throw new RuntimeException('Choose a valid match date and a team name no longer than 80 characters.');
	}

	$response = football_api_matches_for_date($date);
	$fixtures = football_api_fixtures($response, $leagueIds);
	if ($teamSearch !== '') {
		$fixtures = array_values(array_filter(
			$fixtures,
			static fn (array $fixture): bool => stripos(football_match_team_names($fixture), $teamSearch) !== false
		));
	}

	usort($fixtures, static fn (array $a, array $b): int => football_match_sort_key($a) <=> football_match_sort_key($b));
} catch (RuntimeException $exception) {
	$error = $exception->getMessage();
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
		<a href="notifications.php">Notifications</a>
		<?php if (can_access_moderation()): ?>
			<a href="moderation.php">Moderation</a>
		<?php endif; ?>
		<a href="login.php">Account</a>
	</nav>
	<main class="match-center">
		<h1>Match Center</h1>
		<p>Live matches appear first, followed by the other matches on the selected date.</p>
		<form class="match-search" action="matches.php" method="get">
			<input type="hidden" name="search" value="1">
			<label for="match-date">Match date</label>
			<input id="match-date" name="date" type="date" value="<?= escape_html($date) ?>" required>
			<label for="team">Team (optional)</label>
			<input id="team" name="team" type="search" maxlength="80" value="<?= escape_html($teamSearch) ?>" placeholder="Search by team name">
			<button class="auth-button" type="submit">Search matches</button>
		</form>
		<?php if ($parsedDate !== null): ?>
			<nav class="match-date-nav" aria-label="Browse match dates">
				<a href="matches.php?date=<?= escape_html($parsedDate->modify('-1 day')->format('Y-m-d')) ?>">Previous day</a>
				<a href="matches.php?date=<?= escape_html($parsedDate->modify('+1 day')->format('Y-m-d')) ?>">Next day</a>
			</nav>
		<?php endif; ?>
		<?php if (!$isSignedIn): ?>
			<p>Sign in to submit predictions on upcoming matches. <a href="login.php?redirect=predictions.php">Sign in</a></p>
		<?php endif; ?>
		<?php if ($error !== ''): ?>
			<p class="auth-message auth-error" role="alert"><?= escape_html($error) ?></p>
		<?php elseif ($fixtures === []): ?>
			<p><?= $searchSubmitted ? 'No matches found in the selected competitions for that date and team.' : 'No matches found in the selected competitions for this date. Use the date picker or Previous day / Next day to check another date.' ?></p>
		<?php else: ?>
			<section aria-label="Matches for <?= escape_html($date) ?>">
				<h2>Matches for <?= escape_html($parsedDate->format('D j M Y')) ?></h2>
				<ul class="match-list">
					<?php foreach ($fixtures as $fixture): ?>
						<?php
						$homeTeam = $fixture['home'] ?? [];
						$awayTeam = $fixture['away'] ?? [];
						$matchDate = football_match_date_time($fixture);
						?>
						<li class="match-card">
							<p class="match-league"><?= escape_html((string) ($fixture['league']['name'] ?? 'Competition')) ?></p>
							<p class="match-teams">
								<?= escape_html((string) ($homeTeam['name'] ?? 'TBD')) ?>
								<strong><?= escape_html(football_match_score($homeTeam)) ?> : <?= escape_html(football_match_score($awayTeam)) ?></strong>
								<?= escape_html((string) ($awayTeam['name'] ?? 'TBD')) ?>
							</p>
							<p class="match-meta">
								<?= $matchDate === null ? 'Time unavailable' : escape_html($matchDate->format('D j M, H:i')) ?>
								<span><?= escape_html(football_match_status_label($fixture)) ?></span>
							</p>
						</li>
					<?php endforeach; ?>
				</ul>
			</section>
		<?php endif; ?>
		<p class="match-attribution">Match data provided by Free API Live Football Data on RapidAPI.</p>
	</main>
</body>
</html>
