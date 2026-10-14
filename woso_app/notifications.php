<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

$notifications = [];
$error = '';
$status = 200;

if (isset($_SESSION['user_id'])) {
	require_once __DIR__ . '/database.php';

	try {
		$database = database_connection();
		$database->beginTransaction();
		$markRead = $database->prepare(
			'UPDATE notifications
			SET read_at = CURRENT_TIMESTAMP
			WHERE recipient_user_id = :recipient_user_id AND read_at IS NULL'
		);
		$markRead->execute(['recipient_user_id' => (int) $_SESSION['user_id']]);
		$query = $database->prepare(
			'SELECT notifications.notification_id, notifications.post_id,
				notifications.target_type, notifications.target_comment_id,
				notifications.notification_type, notifications.created_at,
				actors.username AS actor_username, comments.content AS comment_content,
				comments.media_path AS comment_media_path, comments.media_type AS comment_media_type
			FROM notifications
			INNER JOIN users AS actors ON actors.user_id = notifications.actor_user_id
			INNER JOIN posts ON posts.post_id = notifications.post_id
			LEFT JOIN comments ON comments.comment_id = notifications.comment_id
			WHERE notifications.recipient_user_id = :recipient_user_id
				AND posts.status = \'published\'
			ORDER BY notifications.created_at DESC, notifications.notification_id DESC
			LIMIT 100'
		);
		$query->execute(['recipient_user_id' => (int) $_SESSION['user_id']]);
		$notifications = $query->fetchAll();
		$database->commit();
	} catch (PDOException $exception) {
		if (isset($database) && $database->inTransaction()) {
			$database->rollBack();
		}
		error_log('WoSo notifications database error: ' . $exception->getMessage());
		$error = 'Notifications are temporarily unavailable. Please check the database setup and try again.';
		$status = 503;
	}
}

http_response_code($status);
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Women's Football - Notifications</title>
	<link rel="stylesheet" href="styles.css">
</head>
<body>
	<nav class="topnav" aria-label="Main navigation">
		<a href="home.php">Home</a>
		<a href="matches.php">Match Center</a>
		<a href="community.html">Community</a>
		<a href="polls.php">Polls</a>
		<a href="predictions.php">Predictions</a>
		<?= notifications_nav_link(true) ?>
		<?php if (can_access_moderation()): ?>
			<a href="moderation.php">Moderation</a>
		<?php endif; ?>
		<?= account_nav_link() ?>
	</nav>

	<main class="notifications-page">
		<header class="home-heading">
			<p class="home-eyebrow">Stay in the loop</p>
			<h1>Notifications</h1>
			<p>See who interacted with your posts and comments.</p>
		</header>

		<?php if (!isset($_SESSION['user_id'])): ?>
			<aside class="visitor-prompt">
				<p>Sign in to see notifications about your posts.</p>
				<p><a href="login.php?redirect=notifications.php">Sign in to your account</a></p>
			</aside>
		<?php elseif ($error !== ''): ?>
			<p class="auth-message auth-error" role="alert"><?= escape_html($error) ?></p>
		<?php elseif ($notifications === []): ?>
			<p class="empty-feed">No notifications yet. When someone interacts with one of your posts, you’ll see it here.</p>
		<?php else: ?>
			<ol class="notification-list">
				<?php foreach ($notifications as $notification): ?>
					<?php
					$actionText = match ([$notification['notification_type'], $notification['target_type']]) {
						['like', 'comment'] => 'liked your comment.',
						['repost', 'comment'] => 'reposted your comment.',
						['comment', 'comment'] => 'replied to your comment.',
						['like', 'post'] => 'liked your post.',
						['comment', 'post'] => 'commented on your post.',
						['repost', 'post'] => 'reposted your post.',
						default => 'interacted with your post.',
					};
					$target = $notification['target_type'] === 'comment' && $notification['target_comment_id'] !== null
						? 'home.php#comment-' . (int) $notification['target_comment_id']
						: 'home.php#post-' . (int) $notification['post_id'];
					?>
					<li class="notification-card">
						<a href="<?= escape_html($target) ?>">
							<strong><?= escape_html((string) $notification['actor_username']) ?></strong>
							<?= escape_html($actionText) ?>
						</a>
						<?php if (is_string($notification['comment_content']) && $notification['comment_content'] !== ''): ?>
							<p class="notification-comment"><?= nl2br(escape_html((string) $notification['comment_content'])) ?></p>
						<?php endif; ?>
						<?php if ($notification['comment_media_type'] === 'image' && is_string($notification['comment_media_path'])): ?>
							<img class="notification-media" src="<?= escape_html((string) $notification['comment_media_path']) ?>" alt="Photo attached to comment" loading="lazy">
						<?php elseif ($notification['comment_media_type'] === 'video' && is_string($notification['comment_media_path'])): ?>
							<video class="notification-media" controls preload="metadata">
								<source src="<?= escape_html((string) $notification['comment_media_path']) ?>">
								Your browser does not support video playback.
							</video>
						<?php endif; ?>
						<time datetime="<?= escape_html((string) $notification['created_at']) ?>"><?= escape_html(date('M j, Y \a\t g:i a', strtotime((string) $notification['created_at']))) ?></time>
					</li>
				<?php endforeach; ?>
			</ol>
		<?php endif; ?>
	</main>
</body>
</html>
