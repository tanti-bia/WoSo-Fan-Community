<?php
declare(strict_types=1);

function football_match_groups(array $fixtures, DateTimeImmutable $now, int $upcomingDays = 14, ?int $finishedDays = 7): array
{
	$live = [];
	$upcoming = [];
	$finished = [];
	$upcomingLimit = $now->modify('+' . $upcomingDays . ' days');
	$finishedLimit = $finishedDays === null ? null : $now->modify('-' . max(0, $finishedDays) . ' days');

	foreach ($fixtures as $fixture) {
		if (!is_array($fixture)
			|| !is_string($fixture['home']['name'] ?? null)
			|| !is_string($fixture['away']['name'] ?? null)
			|| football_match_is_cancelled($fixture)) {
			continue;
		}

		$kickoff = football_match_date_time($fixture);
		if ($kickoff === null) {
			continue;
		}

		if (football_match_is_live($fixture)) {
			$live[] = $fixture;
		} elseif (football_match_is_finished($fixture) && ($finishedLimit === null || $kickoff >= $finishedLimit)) {
			$finished[] = $fixture;
		} elseif ($kickoff >= $now && $kickoff <= $upcomingLimit) {
			$upcoming[] = $fixture;
		}
	}

	usort($live, static fn (array $left, array $right): int => football_match_timeline_sort_key($left) <=> football_match_timeline_sort_key($right));
	usort($upcoming, static fn (array $left, array $right): int => football_match_timeline_sort_key($left) <=> football_match_timeline_sort_key($right));
	usort($finished, static fn (array $left, array $right): int => football_match_timeline_sort_key($right) <=> football_match_timeline_sort_key($left));

	return ['live' => $live, 'upcoming' => $upcoming, 'finished' => $finished];
}

function football_match_user_has_location(): bool
{
	if (!isset($_SESSION['user_id'])) {
		return false;
	}

	try {
		$query = database_connection()->prepare('SELECT location FROM users WHERE user_id = :user_id LIMIT 1');
		$query->execute(['user_id' => (int) $_SESSION['user_id']]);
		$location = $query->fetchColumn();
		return is_string($location) && trim($location) !== '';
	} catch (PDOException $exception) {
		error_log('WoSo match timezone profile lookup error: ' . $exception->getMessage());
		return false;
	}
}

function render_football_match_card(array $fixture, bool $featured = false, bool $useLocalTime = false, ?array $prediction = null, bool $predictionSelectable = true, ?int $predictionPoints = null, bool $predictionPointsPending = false): void
{
	$isLive = football_match_is_live($fixture);
	$isFinished = football_match_is_finished($fixture);
	$kickoff = football_match_date_time($fixture);
	$statusLabel = $isLive ? football_match_status_label($fixture) : ($isFinished ? 'Finished' : 'Upcoming');
	if ($isLive && preg_match('/^\d+(?:\+\d+)?\'?$/', $statusLabel) === 1) {
		$statusLabel = rtrim($statusLabel, '\'') . '\'';
	} elseif ($isLive && $statusLabel === 'Live' && $kickoff !== null) {
		$elapsedMinutes = max(1, (int) floor((time() - $kickoff->getTimestamp()) / 60));
		$statusLabel = $elapsedMinutes . '\'';
	}
	$homeScore = football_match_score($fixture['home']);
	$awayScore = football_match_score($fixture['away']);
	$hasScore = $homeScore !== '—' && $awayScore !== '—';
	$season = (string) ($fixture['league']['season'] ?? '');
	$league = (string) ($fixture['league']['name'] ?? '');
	if ($league === '' || $league === 'Competition') {
		$leagueId = (int) ($fixture['league']['id'] ?? 0);
		$league = $leagueId > 0 ? football_api_competition_name($leagueId) : 'Competition';
	}
	?>
	<?php $showPrediction = $prediction !== null; ?>
	<?php $selectable = $showPrediction && $predictionSelectable; ?>
	<li class="match-card<?= $featured ? ' match-card-featured' : '' ?><?= $isLive ? ' is-live' : '' ?><?= $selectable ? ' prediction-match-card' : '' ?>"<?= $selectable ? ' role="button" tabindex="0" aria-pressed="false" data-prediction-match="' . escape_html((string) $fixture['id']) . '" data-home-team="' . escape_html((string) $fixture['home']['name']) . '" data-away-team="' . escape_html((string) $fixture['away']['name']) . '" data-saved-home-score="' . escape_html((string) ($prediction['home_score'] ?? '')) . '" data-saved-away-score="' . escape_html((string) ($prediction['away_score'] ?? '')) . '" aria-label="Select prediction for ' . escape_html((string) $fixture['home']['name'] . ' versus ' . (string) $fixture['away']['name']) . '"' : '' ?>>
		<div class="match-card-top">
			<p class="match-league"><?= escape_html($league) ?><?= $season !== '' ? ' · ' . escape_html($season) : '' ?></p>
		</div>
		<p class="match-teams">
			<span class="match-home-team">
				<?php if (filter_var($fixture['home']['badge'] ?? '', FILTER_VALIDATE_URL) && str_starts_with((string) $fixture['home']['badge'], 'https://')): ?>
					<img class="match-team-badge" src="<?= escape_html((string) $fixture['home']['badge']) ?>" alt="" loading="lazy">
				<?php endif; ?>
				<?= escape_html((string) $fixture['home']['name']) ?>
			</span>
			<span class="match-center-details">
				<span class="match-time">
					<?php if ($isLive): ?>
						<?= escape_html($statusLabel) ?>
					<?php elseif ($kickoff !== null): ?>
						<?php $utcKickoff = $kickoff->setTimezone(new DateTimeZone('UTC')); ?>
						<time datetime="<?= escape_html($utcKickoff->format(DateTimeInterface::ATOM)) ?>"<?= $useLocalTime ? ' data-local-match-time="true"' : '' ?>>
							<?= escape_html($utcKickoff->format('D, M j · g:i A')) ?> GMT
						</time>
					<?php endif; ?>
				</span>
				<span class="match-score">
					<?= $hasScore ? escape_html($homeScore . ' – ' . $awayScore) : ($isLive || $isFinished ? '— – —' : 'vs') ?>
				</span>
				<span class="match-status<?= $isLive ? ' is-live' : '' ?>"><?= escape_html($isLive ? 'Live' : ($isFinished ? 'Finished' : 'Upcoming')) ?></span>
			</span>
			<span class="match-away-team">
				<?php if (filter_var($fixture['away']['badge'] ?? '', FILTER_VALIDATE_URL) && str_starts_with((string) $fixture['away']['badge'], 'https://')): ?>
					<img class="match-team-badge" src="<?= escape_html((string) $fixture['away']['badge']) ?>" alt="" loading="lazy">
				<?php endif; ?>
				<?= escape_html((string) $fixture['away']['name']) ?>
			</span>
		</p>
		<?php $videoUrl = (string) ($fixture['video'] ?? ''); ?>
		<?php if (!$selectable && filter_var($videoUrl, FILTER_VALIDATE_URL) && str_starts_with($videoUrl, 'https://')): ?>
			<p class="match-meta"><a href="<?= escape_html($videoUrl) ?>" target="_blank" rel="noopener noreferrer">Watch video</a></p>
		<?php endif; ?>
		<?php if ($showPrediction): ?>
			<p class="prediction-card-score<?= $selectable ? '' : ' prediction-result-summary' ?>"<?= $selectable ? ' aria-live="polite"' : '' ?>>
				<?php if ($prediction !== []): ?>
					Your prediction: <?= escape_html((string) $prediction['home_score'] . ' – ' . (string) $prediction['away_score']) ?>
				<?php else: ?>
					<?= $selectable ? 'Select match to predict' : 'You did not submit a prediction for this match.' ?>
				<?php endif; ?>
				<?php if (!$selectable && $predictionPointsPending): ?>
					<br>Points pending final score
				<?php elseif (!$selectable && $predictionPoints !== null): ?>
					<br>Points earned: <?= (int) $predictionPoints ?>
				<?php endif; ?>
			</p>
		<?php endif; ?>
	</li>
	<?php
}
