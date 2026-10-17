<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/football_api.php';
require_once __DIR__ . '/match_view.php';

$now = new DateTimeImmutable('now');
$matchGroups = ['live' => [], 'upcoming' => [], 'finished' => []];
$matchWarning = '';
$matchError = '';
$useLocalMatchTime = football_match_user_has_location();
try {
	$matchData = football_api_shared_fixtures(database_connection());
	$matchGroups = football_match_groups($matchData['fixtures'], $now, 14, null);
	$matchWarning = $matchData['warning'] ?? '';
} catch (PDOException $exception) {
	error_log('WoSo match data database error: ' . $exception->getMessage());
	http_response_code(503);
	$matchError = 'Match data storage is unavailable. Check the database connection and confirm its user can create the match cache and finished match tables.';
} catch (RuntimeException $exception) {
	error_log('WoSo match data error: ' . $exception->getMessage());
	http_response_code(503);
	$matchError = $exception->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Women's Football - Match Center</title>
	<link rel="stylesheet" href="styles.css">
	<script src="back_button.js" defer></script>
	<script src="matches.js" defer></script>
	<script src="match_times.js" defer></script>
</head>
<body>
	<nav class="topnav" aria-label="Main navigation">
		<a href="home.php">Home</a>
		<a class="active" aria-current="page" href="matches.php">Match Center</a>
		<a href="community.html">Community</a>
		<a href="polls.php">Polls</a>
		<a href="predictions.php">Predictions</a>
		<?= notifications_nav_link() ?>
		<?= moderation_nav_link() ?>
		<?= account_nav_link() ?>
	</nav>
	<main class="match-center">
		<header class="match-center-heading">
			<p class="home-eyebrow">Fixtures and results</p>
			<h1>Match Center</h1>
			<p>Live match information when available, upcoming fixtures, and recent results from the competitions you follow.</p>
		</header>

		<?php if ($matchWarning !== ''): ?>
			<p class="auth-message auth-success" role="status"><?= escape_html($matchWarning) ?></p>
		<?php endif; ?>
		<?php if ($matchError !== ''): ?>
			<p class="auth-message auth-error" role="alert"><?= escape_html($matchError) ?></p>
		<?php else: ?>
			<div class="match-view-switch" role="tablist" aria-label="Match status">
				<button id="live-upcoming-tab" class="is-active" type="button" role="tab" aria-selected="true" aria-controls="live-upcoming-panel" data-match-view="live-upcoming">Live / Upcoming</button>
				<button id="finished-tab" type="button" role="tab" aria-selected="false" aria-controls="finished-panel" data-match-view="finished" tabindex="-1">Finished</button>
			</div>

			<section id="live-upcoming-panel" class="match-view-panel" role="tabpanel" aria-labelledby="live-upcoming-tab">
				<h2>Live</h2>
				<?php if ($matchGroups['live'] !== []): ?>
					<ul class="match-list match-featured-list">
						<?php foreach ($matchGroups['live'] as $index => $match): ?>
							<?php render_football_match_card($match, $index === 0, $useLocalMatchTime); ?>
						<?php endforeach; ?>
					</ul>
				<?php else: ?>
					<p class="match-empty">There are no live matches right now.</p>
				<?php endif; ?>

				<h2>Upcoming</h2>
				<?php if ($matchGroups['upcoming'] !== []): ?>
					<ul class="match-list">
						<?php foreach ($matchGroups['upcoming'] as $match): ?>
							<?php render_football_match_card($match, false, $useLocalMatchTime); ?>
						<?php endforeach; ?>
					</ul>
				<?php else: ?>
					<p class="match-empty">No upcoming matches were found in the next 14 days.</p>
				<?php endif; ?>
			</section>

			<section id="finished-panel" class="match-view-panel" role="tabpanel" aria-labelledby="finished-tab" hidden>
				<h2>Finished matches</h2>
				<?php if ($matchGroups['finished'] !== []): ?>
					<ul class="match-list">
						<?php foreach ($matchGroups['finished'] as $match): ?>
							<?php render_football_match_card($match, false, $useLocalMatchTime); ?>
						<?php endforeach; ?>
					</ul>
				<?php else: ?>
					<p class="match-empty">No finished matches have been saved for these competitions yet.</p>
				<?php endif; ?>
			</section>
		<?php endif; ?>

		<p class="match-attribution">Fixtures and results are provided by <a href="https://www.thesportsdb.com/" target="_blank" rel="noopener noreferrer">TheSportsDB</a> and shared with Predictions. Data is cached to reduce API requests.</p>
	</main>
</body>
</html>
