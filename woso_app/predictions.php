<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/football_api.php';
require_once __DIR__ . '/match_view.php';

function predictions_read_goal_score(array $team): ?int
{
	$score = $team['score'] ?? null;
	if (is_array($score)) {
		$score = $score['current'] ?? $score['display'] ?? null;
	}

	return is_numeric($score) && (float) $score >= 0 ? (int) $score : null;
}

function predictions_points_for_match(?array $prediction, array $fixture): ?int
{
	$homeActual = predictions_read_goal_score($fixture['home'] ?? []);
	$awayActual = predictions_read_goal_score($fixture['away'] ?? []);
	if ($homeActual === null || $awayActual === null) {
		return null;
	}
	if ($prediction === null) {
		return 0;
	}

	$homePredicted = (int) $prediction['home_score'];
	$awayPredicted = (int) $prediction['away_score'];
	$points = 0;
	if ((($homePredicted - $awayPredicted) <=> 0) === (($homeActual - $awayActual) <=> 0)) {
		$points += 3;
	}
	if ($homePredicted === $homeActual) {
		$points += 2;
	}
	if ($awayPredicted === $awayActual) {
		$points += 2;
	}
	if ($homePredicted - $awayPredicted === $homeActual - $awayActual) {
		$points += 3;
	}

	return $points;
}

$matchError = '';
$matchWarning = '';
$actionError = '';
$saved = isset($_GET['saved']) && $_GET['saved'] === '1';
$useLocalMatchTime = football_match_user_has_location();
$upcomingMatches = [];
$finishedMatches = [];
$userPredictions = [];
$predictionPointsByEvent = [];
$totalPredictionPoints = 0;
$scoredFinishedMatchCount = 0;
$database = null;

try {
	$database = database_connection();
	$database->exec(
		'CREATE TABLE IF NOT EXISTS predictions
		(
			user_id INT UNSIGNED NOT NULL,
			event_id VARCHAR(64) NOT NULL,
			home_score INT UNSIGNED NOT NULL,
			away_score INT UNSIGNED NOT NULL,
			created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (user_id, event_id),
			KEY predictions_event (event_id),
			CONSTRAINT predictions_user_fk FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE CASCADE
		) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
	);
	$matchData = football_api_shared_fixtures($database);
	$matchGroups = football_match_groups($matchData['fixtures'], new DateTimeImmutable('now'), 14, null);
	$upcomingMatches = $matchGroups['upcoming'];
	$finishedMatches = $matchGroups['finished'];
	$matchWarning = $matchData['warning'] ?? '';

	if ($_SERVER['REQUEST_METHOD'] === 'POST') {
		require_signed_in('predictions.php');
		if (!has_valid_csrf_token()) {
			$actionError = 'Your session expired. Refresh the page and try again.';
		} else {
			$eventId = post_string('event_id');
			$homeScoreRaw = post_string('home_score');
			$awayScoreRaw = post_string('away_score');
			if ($eventId === '' || strlen($eventId) > 64
				|| !preg_match('/^\d+$/', $homeScoreRaw)
				|| !preg_match('/^\d+$/', $awayScoreRaw)
				|| (float) $homeScoreRaw > 4294967295
				|| (float) $awayScoreRaw > 4294967295) {
				$actionError = 'Choose a match and enter a whole number of goals for both teams.';
			} else {
				$selectedFixture = null;
				foreach ($upcomingMatches as $fixture) {
					if ((string) ($fixture['id'] ?? '') === $eventId) {
						$selectedFixture = $fixture;
						break;
					}
				}
				$kickoff = is_array($selectedFixture) ? football_match_date_time($selectedFixture) : null;
				if ($kickoff === null || $kickoff->getTimestamp() <= time()) {
					$actionError = 'That match has started or is no longer available for predictions.';
				} else {
					$savePrediction = $database->prepare(
						'INSERT INTO predictions (user_id, event_id, home_score, away_score)
						VALUES (:user_id, :event_id, :home_score, :away_score)
						ON DUPLICATE KEY UPDATE
							home_score = VALUES(home_score),
							away_score = VALUES(away_score),
							updated_at = CURRENT_TIMESTAMP'
					);
					$savePrediction->execute([
						'user_id' => (int) $_SESSION['user_id'],
						'event_id' => $eventId,
						'home_score' => (int) $homeScoreRaw,
						'away_score' => (int) $awayScoreRaw,
					]);
					header('Location: predictions.php?saved=1');
					exit;
				}
			}
		}
	}

	if (isset($_SESSION['user_id'])) {
		$predictionQuery = $database->prepare('SELECT event_id, home_score, away_score FROM predictions WHERE user_id = :user_id');
		$predictionQuery->execute(['user_id' => (int) $_SESSION['user_id']]);
		foreach ($predictionQuery->fetchAll() as $prediction) {
			$userPredictions[(string) $prediction['event_id']] = $prediction;
		}
		foreach ($finishedMatches as $finishedMatch) {
			$eventId = (string) ($finishedMatch['id'] ?? '');
			$prediction = $userPredictions[$eventId] ?? null;
			$points = predictions_points_for_match($prediction, $finishedMatch);
			if ($points !== null) {
				$predictionPointsByEvent[$eventId] = $points;
				$totalPredictionPoints += $points;
				$scoredFinishedMatchCount++;
			}
		}
	}
} catch (PDOException $exception) {
	error_log('WoSo predictions database error: ' . $exception->getMessage());
	http_response_code(503);
	$matchError = 'Predictions or match data storage is unavailable. Check the database connection and schema.';
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
	<script src="match_times.js" defer></script>
	<script src="predictions.js" defer></script>
</head>
<body>
	<nav class="topnav" aria-label="Main navigation">
		<a href="home.html">Home</a>
		<a href="matches.php">Match Center</a>
		<a href="community.html">Community</a>
		<a href="polls.php">Polls</a>
		<a class="active" aria-current="page" href="predictions.php">Predictions</a>
		<?= notifications_nav_link() ?>
		<?= moderation_nav_link() ?>
		<?= account_nav_link() ?>
	</nav>

	<main class="predictions-page">
		<p class="home-eyebrow">Make your matchday call</p>
		<h1>Predictions</h1>
		<p>Select an upcoming match, enter a score for each team, and submit your prediction. You can change it any time before kickoff.</p>
		<section class="prediction-scoring-rules" aria-labelledby="prediction-scoring-heading">
			<h2 id="prediction-scoring-heading">How points are scored</h2>
			<ul>
				<li>Correctly predict the winner or a draw: <strong>+3 points</strong></li>
				<li>Correctly predict the home team’s goals: <strong>+2 points</strong></li>
				<li>Correctly predict the away team’s goals: <strong>+2 points</strong></li>
				<li>Correctly predict the goal difference: <strong>+3 points</strong></li>
			</ul>
			<?php if (isset($_SESSION['user_id'])): ?>
				<p class="prediction-total-points">Your total: <strong><?= (int) $totalPredictionPoints ?> points</strong> across <?= (int) $scoredFinishedMatchCount ?> finished matches with final scores.</p>
			<?php endif; ?>
		</section>

		<?php if (!isset($_SESSION['user_id'])): ?>
			<p class="visitor-prompt">Sign in to take part in predictions. <a href="login.php?redirect=predictions.php">Sign in</a></p>
		<?php endif; ?>
		<?php if ($matchWarning !== ''): ?>
			<p class="auth-message auth-success" role="status"><?= escape_html($matchWarning) ?></p>
		<?php endif; ?>
		<?php if ($saved): ?>
			<p class="auth-message auth-success" role="status">Your prediction has been saved.</p>
		<?php endif; ?>
		<?php if ($actionError !== ''): ?>
			<p class="auth-message auth-error" role="alert"><?= escape_html($actionError) ?></p>
		<?php endif; ?>
		<?php if ($matchError !== ''): ?>
			<p class="auth-message auth-error" role="alert"><?= escape_html($matchError) ?></p>
		<?php else: ?>
			<div class="match-view-switch" role="tablist" aria-label="Prediction views">
				<button id="make-predictions-tab" class="is-active" type="button" role="tab" aria-selected="true" aria-controls="make-predictions-panel" data-prediction-view="make">Make predictions</button>
				<button id="view-results-tab" type="button" role="tab" aria-selected="false" aria-controls="view-results-panel" data-prediction-view="results" tabindex="-1">View results</button>
			</div>

			<section id="make-predictions-panel" class="match-view-panel" role="tabpanel" aria-labelledby="make-predictions-tab">
				<h2>Upcoming matches</h2>
				<?php if ($upcomingMatches !== []): ?>
					<ul class="match-list prediction-match-list">
						<?php foreach ($upcomingMatches as $match): ?>
							<?php $eventId = (string) ($match['id'] ?? ''); ?>
							<?php $prediction = $userPredictions[$eventId] ?? []; ?>
							<?php render_football_match_card($match, false, $useLocalMatchTime, isset($_SESSION['user_id']) ? $prediction : null); ?>
						<?php endforeach; ?>
						<?php if (isset($_SESSION['user_id'])): ?>
							<li class="prediction-form-item" data-prediction-form-item hidden>
								<form class="prediction-form" method="post" action="predictions.php" aria-labelledby="prediction-form-heading">
									<h2 id="prediction-form-heading">Your score prediction</h2>
									<p class="prediction-selected-match" data-prediction-selected></p>
									<input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
									<input type="hidden" name="event_id" value="" data-prediction-event-id required>
									<div class="prediction-score-fields">
										<label>Home goals <input type="number" name="home_score" min="0" max="4294967295" step="1" required inputmode="numeric" data-prediction-home-score></label>
										<span aria-hidden="true">–</span>
										<label>Away goals <input type="number" name="away_score" min="0" max="4294967295" step="1" required inputmode="numeric" data-prediction-away-score></label>
									</div>
									<button class="auth-button" type="submit">Submit prediction</button>
								</form>
							</li>
						<?php endif; ?>
					</ul>
				<?php else: ?>
					<p class="match-empty">No upcoming matches were found in the next 14 days.</p>
				<?php endif; ?>
			</section>

			<section id="view-results-panel" class="match-view-panel" role="tabpanel" aria-labelledby="view-results-tab" hidden>
				<h2>Finished matches</h2>
				<?php if ($finishedMatches !== []): ?>
					<ul class="match-list prediction-results-list">
						<?php foreach ($finishedMatches as $match): ?>
							<?php $eventId = (string) ($match['id'] ?? ''); ?>
							<?php $prediction = isset($_SESSION['user_id']) ? ($userPredictions[$eventId] ?? []) : null; ?>
							<?php $points = $prediction === [] ? 0 : ($predictionPointsByEvent[$eventId] ?? null); ?>
							<?php $pointsPending = $prediction !== [] && $points === null; ?>
							<?php render_football_match_card($match, false, $useLocalMatchTime, $prediction, false, $points, $pointsPending); ?>
						<?php endforeach; ?>
					</ul>
				<?php else: ?>
					<p class="match-empty">No finished matches have been saved for these competitions yet.</p>
				<?php endif; ?>
			</section>
		<?php endif; ?>
	</main>
</body>
</html>
