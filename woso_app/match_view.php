<?php
declare(strict_types=1);

function football_match_groups(array $fixtures, DateTimeImmutable $now, int $upcomingDays = 14): array
{
	$live = [];
	$upcoming = [];
	$finished = [];
	$upcomingLimit = $now->modify('+' . $upcomingDays . ' days');
	$finishedLimit = $now->modify('-7 days');

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
		} elseif (football_match_is_finished($fixture) && $kickoff >= $finishedLimit) {
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

function render_football_match_card(array $fixture, bool $featured = false): void
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
	$league = (string) ($fixture['league']['name'] ?? '');
	if ($league === '' || $league === 'Competition') {
		$leagueId = (int) ($fixture['league']['id'] ?? 0);
		$league = $leagueId > 0 ? football_api_competition_name($leagueId) : 'Competition';
	}
	?>
	<li class="match-card<?= $featured ? ' match-card-featured' : '' ?><?= $isLive ? ' is-live' : '' ?>">
		<div class="match-card-top">
			<p class="match-league"><?= escape_html($league) ?></p>
		</div>
		<p class="match-teams">
			<span class="match-home-team"><?= escape_html((string) $fixture['home']['name']) ?></span>
			<span class="match-center-details">
				<span class="match-time">
					<?php if ($isLive): ?>
						<?= escape_html($statusLabel) ?>
					<?php elseif ($kickoff !== null): ?>
						<time datetime="<?= escape_html($kickoff->format(DateTimeInterface::ATOM)) ?>">
							<?= escape_html($kickoff->format('D, M j · g:i A T')) ?>
						</time>
					<?php endif; ?>
				</span>
				<span class="match-score">
					<?= $hasScore ? escape_html($homeScore . ' – ' . $awayScore) : ($isLive || $isFinished ? '— – —' : 'vs') ?>
				</span>
				<span class="match-status<?= $isLive ? ' is-live' : '' ?>"><?= escape_html($isLive ? 'Live' : ($isFinished ? 'Finished' : 'Upcoming')) ?></span>
			</span>
			<span class="match-away-team"><?= escape_html((string) $fixture['away']['name']) ?></span>
		</p>
	</li>
	<?php
}
