<?php
require_once __DIR__ . '/auth.php';
?>
<!DOCTYPE html>
<html>
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Women's Football - Polls</title>
	<link rel="stylesheet" href="styles.css">
</head>
<body>
	<nav class="topnav" aria-label="Main navigation">
		<a href="home.html">Home</a>
		<a href="matches.php">Match Center</a>
		<a href="community.html">Community</a>
		<a class="active" aria-current="page" href="polls.php">Polls</a>
	<a href="predictions.php">Predictions</a>
	<?= notifications_nav_link() ?>
		<?php if (can_access_moderation()): ?>
			<a href="moderation.php">Moderation</a>
		<?php endif; ?>
	<a href="login.php">Account</a>
</nav>

<main>
	<h1>Polls</h1>
	<?php if (!isset($_SESSION['user_id'])): ?>
		<p>Browse community polls as a visitor. Sign in to vote.</p>
		<p><a href="login.php?redirect=polls.php">Sign in to participate in polls</a></p>
	<?php else: ?>
		<p>Browse community polls and take part in voting.</p>
	<?php endif; ?>
	<img src="https://www.fcbarcelona.com/photo-resources/2026/05/23/ece710a5-9365-48f9-ac6e-05d5e69b17b4/_5DS4138.jpg?width=2400&height=1500" width="50%">
	<p>Explore questions and share your views on women's football matches and players through community polls.</p>
</main>
</body>
</html>