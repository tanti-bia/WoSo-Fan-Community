<?php
require_once __DIR__ . '/auth.php';
?>
<!DOCTYPE html>
<html>
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

<main>
	<h1>Predictions</h1>
	<?php if (!isset($_SESSION['user_id'])): ?>
		<p>Browse match predictions as a visitor. Sign in to submit your predictions.</p>
		<p><a href="login.php?redirect=predictions.php">Sign in to make predictions</a></p>
	<?php else: ?>
		<p>Browse match predictions and submit your predictions while signed in.</p>
	<?php endif; ?>
	<img src="https://www.fcbarcelona.com/photo-resources/2026/05/23/ece710a5-9365-48f9-ac6e-05d5e69b17b4/_5DS4138.jpg?width=2400&height=1500" width="50%">
	<p>Explore community predictions and forecast results for upcoming women's football matches.</p>
</main>
</body>
</html>