<?php
require_once __DIR__ . '/auth.php';
require_signed_in('moderation.php');
require_role(['Moderator', 'Administrator']);
?>
<!DOCTYPE html>
<html>
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Women's Football - Moderation</title>
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
	<a href="predictions.php">Predictions</a>
	<?= notifications_nav_link() ?>
	<a class="active" aria-current="page" href="moderation.php">Moderation</a>
	<?= account_nav_link() ?>
</nav>

<main class="moderation-page">
	<img src="https://www.fcbarcelona.com/photo-resources/2026/05/23/ece710a5-9365-48f9-ac6e-05d5e69b17b4/_5DS4138.jpg?width=2400&height=1500" width="50%">
	<p>Welcome to the Women's Football App! This is your one-stop destination for all things related to women's football. Stay updated with the latest matches, join our community discussions, participate in polls, and make predictions on upcoming games. Explore your profile, manage notifications, and engage in moderation activities to help keep our community safe and enjoyable for everyone.</p>
</main>
</body>
</html>