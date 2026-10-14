<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

http_response_code(503);
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Match Center unavailable</title>
	<link rel="stylesheet" href="styles.css">
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
		<a href="login.php"><?= isset($_SESSION['user_id']) ? 'Account' : 'Sign in' ?></a>
	</nav>
	<main class="match-unavailable-page">
		<section class="match-unavailable-card" role="status">
			<h1>Match Center is not available</h1>
			<p>This page is currently unavailable. Please check back later.</p>
			<a href="home.php">Return to Home</a>
		</section>
	</main>
</body>
</html>
