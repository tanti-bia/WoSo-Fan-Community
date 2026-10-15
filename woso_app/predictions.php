<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/football_api.php';
require_once __DIR__ . '/match_view.php';

$matchError = '';
$matchWarning = '';
$upcomingMatches = [];
try {
	$matchData = football_api_shared_fixtures(database_connection());
	$matchGroups = football_match_groups($matchData['fixtures'], new DateTimeImmutable('now'));
	$upcomingMatches = $matchGroups['upcoming'];
	$matchWarning = $matchData['warning'] ?? '';
} catch (PDOException $exception) {
	error_log('WoSo predictions match cache database error: ' . $exception->getMessage());
	http_response_code(503);
	$matchError = 'Match data storage is unavailable. Check the match cache table in the database setup.';
} catch (RuntimeException $exception) {
	error_log('WoSo predictions match data error: ' . $exception->getMessage());
	http_response_code(503);
	$matchError = $exception->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Women's Football - Predictions</title>
	<link rel="stylesheet" href="styles.css">
	<script src="image_viewer.js" defer></script>
	<script src="back_button.js" defer></script>
</head>
<body>
	<nav class="topnav" aria-label="Main navigation">
		<a href="home.html">Home</a>
		<a href="matches.php">Match Center</a>
		<a href="community.html">Community</a>
		<a href="polls.php">Polls</a>
		<a class="active" aria-current="page" href="predictions.php">Predictions</a>
		<?= notifications_nav_link() ?>
		<?php if (can_access_moderation()): ?>
			<a href="moderation.php">Moderation</a>
		<?php endif; ?>
		<?= account_nav_link() ?>
	</nav>

	<main class="predictions-page">
		<p class="home-eyebrow">Make your matchday call</p>
		<h1>Predictions</h1>
		<p>See upcoming fixtures from the Match Center. Match data is shared from the same cache to keep API requests down.</p>

		<?php if (!isset($_SESSION['user_id'])): ?>
			<p class="visitor-prompt">Sign in to take part in predictions. <a href="login.php?redirect=predictions.php">Sign in</a></p>
		<?php endif; ?>
		<?php if ($matchWarning !== ''): ?>
			<p class="auth-message auth-success" role="status"><?= escape_html($matchWarning) ?></p>
		<?php endif; ?>
		<?php if ($matchError !== ''): ?>
			<p class="auth-message auth-error" role="alert"><?= escape_html($matchError) ?></p>
		<?php else: ?>
			<section aria-labelledby="prediction-fixtures-heading">
				<h2 id="prediction-fixtures-heading">Upcoming matches</h2>
				<?php if ($upcomingMatches !== []): ?>
					<ul class="match-list">
						<?php foreach ($upcomingMatches as $match): ?>
							<?php render_football_match_card($match); ?>
						<?php endforeach; ?>
					</ul>
				<?php else: ?>
					<p class="match-empty">No upcoming matches were found in the next 14 days.</p>
				<?php endif; ?>
			</section>
			<p class="prediction-note">Score prediction submissions are not available yet.</p>
		<?php endif; ?>
	</main>
</body>
</html>
