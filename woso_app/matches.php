<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

$now = new DateTimeImmutable('now');
$matchExamples = [
	[
		'home' => 'Arsenal Women',
		'away' => 'Chelsea Women',
		'competition' => 'WSL',
		'offset_minutes' => -68,
		'home_score' => 1,
		'away_score' => 0,
	],
	[
		'home' => 'Barcelona Femení',
		'away' => 'Real Madrid Femenino',
		'competition' => 'Liga F',
		'offset_minutes' => -24,
		'home_score' => 1,
		'away_score' => 1,
	],
	[
		'home' => 'Manchester City Women',
		'away' => 'Liverpool Women',
		'competition' => 'WSL',
		'offset_minutes' => 132,
	],
	[
		'home' => 'Lyonnes',
		'away' => 'Paris Saint-Germain',
		'competition' => 'Première Ligue',
		'offset_minutes' => 420,
	],
	[
		'home' => 'Bayern München',
		'away' => 'Wolfsburg',
		'competition' => 'Frauen-Bundesliga',
		'offset_minutes' => 1560,
	],
	[
		'home' => 'United States',
		'away' => 'Canada',
		'competition' => 'International friendly',
		'offset_minutes' => -4320,
		'home_score' => 2,
		'away_score' => 1,
	],
	[
		'home' => 'Japan',
		'away' => 'Australia',
		'competition' => 'International friendly',
		'offset_minutes' => -10080,
		'home_score' => 0,
		'away_score' => 0,
	],
	[
		'home' => 'Roma',
		'away' => 'Juventus',
		'competition' => 'Serie A Women',
		'offset_minutes' => -20160,
		'home_score' => 3,
		'away_score' => 2,
	],
];

$matches = [];
foreach ($matchExamples as $index => $example) {
	$kickoff = $now->modify(($example['offset_minutes'] >= 0 ? '+' : '') . $example['offset_minutes'] . ' minutes');
	$endsAt = $kickoff->modify('+105 minutes');
	$status = $kickoff > $now ? 'upcoming' : ($endsAt > $now ? 'live' : 'finished');
	$matches[] = $example + [
		'id' => $index,
		'kickoff' => $kickoff,
		'status' => $status,
	];
}

$kickoffOrder = static fn (array $left, array $right): int => $left['kickoff'] <=> $right['kickoff'];
$liveAndUpcoming = array_values(array_filter(
	$matches,
	static fn (array $match): bool => $match['status'] !== 'finished'
));
usort($liveAndUpcoming, $kickoffOrder);

$finishedMatches = array_values(array_filter(
	$matches,
	static fn (array $match): bool => $match['status'] === 'finished'
));
usort($finishedMatches, static fn (array $left, array $right): int => $right['kickoff'] <=> $left['kickoff']);

$featuredMatch = $liveAndUpcoming[0] ?? null;
$remainingLiveAndUpcoming = $featuredMatch === null
	? []
	: array_values(array_filter(
		$liveAndUpcoming,
		static fn (array $match): bool => $match['id'] !== $featuredMatch['id']
	));

$formatMatchDate = static fn (DateTimeImmutable $date): string => $date->format('D, M j · g:i A T');
$renderMatchCard = static function (array $match, bool $featured = false) use ($formatMatchDate): void {
	$isLive = $match['status'] === 'live';
	$statusLabel = match ($match['status']) {
		'live' => 'LIVE',
		'finished' => 'Finished',
		default => 'Upcoming',
	};
	?>
	<li class="match-card<?= $featured ? ' match-card-featured' : '' ?><?= $isLive ? ' is-live' : '' ?>">
		<div class="match-card-heading">
			<p class="match-league"><?= escape_html($match['competition']) ?></p>
			<span class="match-status-badge<?= $isLive ? ' is-live' : '' ?>"><?= escape_html($statusLabel) ?></span>
		</div>
		<p class="match-teams">
			<span class="match-home-team"><?= escape_html($match['home']) ?></span>
			<span class="match-score">
				<?= isset($match['home_score'], $match['away_score'])
					? (int) $match['home_score'] . ' – ' . (int) $match['away_score']
					: 'vs' ?>
			</span>
			<span class="match-away-team"><?= escape_html($match['away']) ?></span>
		</p>
		<p class="match-time">
			<time datetime="<?= escape_html($match['kickoff']->format(DateTimeInterface::ATOM)) ?>">
				<?= escape_html($formatMatchDate($match['kickoff'])) ?>
			</time>
			<?php if ($isLive): ?>
				<span> · In progress</span>
			<?php endif; ?>
		</p>
	</li>
	<?php
};
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
</head>
<body>
	<nav class="topnav" aria-label="Main navigation">
		<a href="home.php">Home</a>
		<a class="active" aria-current="page" href="matches.php">Match Center</a>
		<a href="community.html">Community</a>
		<a href="polls.php">Polls</a>
		<a href="predictions.php">Predictions</a>
		<?= notifications_nav_link() ?>
		<?php if (can_access_moderation()): ?>
			<a href="moderation.php">Moderation</a>
		<?php endif; ?>
		<?= account_nav_link() ?>
	</nav>
	<main class="match-center">
		<header class="match-center-heading">
			<p class="home-eyebrow">Fixtures and results</p>
			<h1>Match Center</h1>
			<p>Browse live matches, upcoming kickoffs, and recent results.</p>
		</header>

		<div class="match-view-switch" role="tablist" aria-label="Match status">
			<button id="live-upcoming-tab" class="is-active" type="button" role="tab" aria-selected="true" aria-controls="live-upcoming-panel" data-match-view="live-upcoming">Live / Upcoming</button>
			<button id="finished-tab" type="button" role="tab" aria-selected="false" aria-controls="finished-panel" data-match-view="finished" tabindex="-1">Finished</button>
		</div>

		<section id="live-upcoming-panel" class="match-view-panel" role="tabpanel" aria-labelledby="live-upcoming-tab">
			<?php if ($featuredMatch !== null): ?>
				<h2><?= $featuredMatch['status'] === 'live' ? 'Live now' : 'Next kickoff' ?></h2>
				<ul class="match-list match-featured-list">
					<?php $renderMatchCard($featuredMatch, true); ?>
				</ul>
			<?php endif; ?>

			<h2><?= $featuredMatch !== null && $featuredMatch['status'] === 'live' ? 'Other live and upcoming matches' : 'More upcoming matches' ?></h2>
			<?php if ($remainingLiveAndUpcoming !== []): ?>
				<ul class="match-list">
					<?php foreach ($remainingLiveAndUpcoming as $match): ?>
						<?php $renderMatchCard($match); ?>
					<?php endforeach; ?>
				</ul>
			<?php else: ?>
				<p class="match-empty">No live or upcoming example matches.</p>
			<?php endif; ?>
		</section>

		<section id="finished-panel" class="match-view-panel" role="tabpanel" aria-labelledby="finished-tab" hidden>
			<h2>Finished matches</h2>
			<?php if ($finishedMatches !== []): ?>
				<ul class="match-list">
					<?php foreach ($finishedMatches as $match): ?>
						<?php $renderMatchCard($match); ?>
					<?php endforeach; ?>
				</ul>
			<?php else: ?>
				<p class="match-empty">No finished example matches.</p>
			<?php endif; ?>
		</section>

		<p class="match-attribution">Example fixtures only. Kickoff times use the server's local timezone; this page does not use a live scores API.</p>
	</main>
</body>
</html>
