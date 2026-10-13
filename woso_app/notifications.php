<?php
require_once __DIR__ . '/auth.php';
?>
<!DOCTYPE html>
<html>
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Women's Football - Notifications</title>
	<link rel="stylesheet" href="styles.css">
</head>
<body>
	<nav class="topnav" aria-label="Main navigation">
		<a href="home.html">Home</a>
		<a href="matches.php">Match Center</a>
		<a href="community.html">Community</a>
		<a href="polls.php">Polls</a>
	<a href="predictions.php">Predictions</a>
	<a class="active" aria-current="page" href="notifications.php">Notifications</a>
		<?php if (can_access_moderation()): ?>
			<a href="moderation.php">Moderation</a>
		<?php endif; ?>
	<a href="login.php">Account</a>
</nav>

<main>
	<h1>Notifications</h1>
	<?php if (!isset($_SESSION['user_id'])): ?>
		<p>Sign in to view your notifications.</p>
		<p><a href="login.php?redirect=notifications.php">Sign in to your account</a></p>
	<?php else: ?>
		<p>Your notifications will appear here.</p>
	<?php endif; ?>
</main>
</body>
</html>