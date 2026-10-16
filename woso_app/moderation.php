<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/database.php';
require_signed_in('moderation.php');
require_role(['Administrator']);

$error = '';
$notice = '';
$database = database_connection();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	if (!has_valid_csrf_token()) {
		$error = 'Your session expired. Refresh the page and try again.';
	} else {
		$reportId = filter_var(post_string('report_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
		$action = post_string('review_action');
		$days = filter_var(post_string('block_days'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 365]]);
		try {
			if ($reportId === false || !in_array($action, ['none', 'delete_post', 'ban_user', 'temporary_block'], true)) {
				throw new RuntimeException('Choose a valid moderation action.');
			}
			$database->beginTransaction();
			$query = $database->prepare('SELECT * FROM reports WHERE report_id = :report_id AND status = \'pending\' FOR UPDATE');
			$query->execute(['report_id' => $reportId]);
			$report = $query->fetch();
			if (!$report) {
				throw new RuntimeException('That report has already been reviewed.');
			}
			if ($action === 'delete_post') {
				if ($report['target_type'] !== 'post' || $report['post_id'] === null) {
					throw new RuntimeException('Only a reported post can be deleted here.');
				}
				$query = $database->prepare('SELECT user_id, status FROM posts WHERE post_id = :post_id FOR UPDATE');
				$query->execute(['post_id' => $report['post_id']]);
				$post = $query->fetch();
				if (!$post || $post['status'] !== 'published') {
					throw new RuntimeException('This post has already been deleted or is unavailable.');
				}
				$query = $database->prepare("UPDATE posts SET status = 'removed' WHERE post_id = :post_id");
				$query->execute(['post_id' => $report['post_id']]);
				$query = $database->prepare(
					'INSERT INTO notifications (recipient_user_id, actor_user_id, post_id, target_type, notification_type)
					VALUES (:recipient_user_id, :actor_user_id, :post_id, \'post\', \'post_removed\')'
				);
				$query->execute([
					'recipient_user_id' => (int) $post['user_id'],
					'actor_user_id' => (int) $_SESSION['user_id'],
					'post_id' => (int) $report['post_id'],
				]);
				$query = $database->prepare(
					"UPDATE reports
					SET status = 'actioned', review_action = 'delete_post', reviewed_by = :reviewer, reviewed_at = UTC_TIMESTAMP()
					WHERE target_type = 'post' AND post_id = :post_id AND status = 'pending'"
				);
				$query->execute([
					'reviewer' => (int) $_SESSION['user_id'],
					'post_id' => (int) $report['post_id'],
				]);
			} elseif (in_array($action, ['ban_user', 'temporary_block'], true)) {
				if ((int) $report['reported_user_id'] === (int) $_SESSION['user_id']) {
					throw new RuntimeException('This action cannot be applied to your own account.');
				}
				$query = $database->prepare("SELECT role FROM users WHERE user_id = :user_id FOR UPDATE");
				$query->execute(['user_id' => $report['reported_user_id']]);
				if ($query->fetchColumn() === 'Administrator') {
					throw new RuntimeException('Administrator accounts cannot be restricted through this queue.');
				}
				if ($action === 'ban_user') {
					$query = $database->prepare('UPDATE users SET banned_at = UTC_TIMESTAMP(), suspended_until = NULL WHERE user_id = :user_id');
					$query->execute(['user_id' => $report['reported_user_id']]);
				} else {
					if ($days === false) {
						throw new RuntimeException('Choose a block period from 1 to 365 days.');
					}
					$until = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+' . $days . ' days')->format('Y-m-d H:i:s');
					$query = $database->prepare('UPDATE users SET suspended_until = :until, banned_at = NULL WHERE user_id = :user_id');
					$query->execute(['until' => $until, 'user_id' => $report['reported_user_id']]);
				}
			}
			$resolved = $action === 'none' ? 'dismissed' : 'actioned';
			$query = $database->prepare('UPDATE reports SET status = :status, review_action = :action, blocked_until = :blocked_until, reviewed_by = :reviewer, reviewed_at = UTC_TIMESTAMP() WHERE report_id = :report_id');
			$query->execute([
				'status' => $resolved,
				'action' => $action,
				'blocked_until' => $action === 'temporary_block' ? $until : null,
				'reviewer' => (int) $_SESSION['user_id'],
				'report_id' => $reportId,
			]);
			$database->commit();
			$notice = 'Report reviewed.';
		} catch (RuntimeException $exception) {
			if ($database->inTransaction()) $database->rollBack();
			$error = $exception->getMessage();
		} catch (PDOException $exception) {
			if ($database->inTransaction()) $database->rollBack();
			error_log('WoSo moderation database error: ' . $exception->getMessage());
			$error = 'The report could not be saved. Check that the moderation database migration has been applied.';
		}
	}
}

$reports = [];
try {
	$query = $database->query(
		"SELECT r.report_id, r.target_type, r.post_id, r.reported_user_id, r.reason, r.created_at,
			reporter.username AS reporter_username, reported.username AS reported_username,
			p.content AS post_content, p.status AS post_status
		FROM reports r
		JOIN users reporter ON reporter.user_id = r.reporter_id
		JOIN users reported ON reported.user_id = r.reported_user_id
		LEFT JOIN posts p ON p.post_id = r.post_id
		WHERE r.status = 'pending'
		ORDER BY r.created_at ASC, r.report_id ASC"
	);
	$reports = $query->fetchAll();
} catch (PDOException $exception) {
	error_log('WoSo moderation queue database error: ' . $exception->getMessage());
	$error = 'The moderation queue is unavailable. Apply the moderation database migration first.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Women's Football - Moderation</title>
	<link rel="stylesheet" href="styles.css">
	<script src="back_button.js" defer></script>
</head>
<body>
	<nav class="topnav" aria-label="Main navigation">
		<a href="home.html">Home</a><a href="matches.php">Match Center</a><a href="community.html">Community</a>
		<a href="polls.php">Polls</a><a href="predictions.php">Predictions</a><?= notifications_nav_link() ?>
		<?= moderation_nav_link(true) ?><?= account_nav_link() ?>
	</nav>
	<main class="moderation-page">
		<header class="page-heading"><p class="home-eyebrow">Administrator tools</p><h1>Moderation reports</h1><p>Review reports and choose an action for the post or account.</p></header>
		<?php if ($error !== ''): ?><p class="auth-message auth-error" role="alert"><?= escape_html($error) ?></p><?php endif; ?>
		<?php if ($notice !== ''): ?><p class="auth-message auth-success" role="status"><?= escape_html($notice) ?></p><?php endif; ?>
		<?php if ($reports === [] && $error === ''): ?><p class="empty-feed">There are no reports waiting for review.</p><?php endif; ?>
		<div class="moderation-report-list">
			<?php foreach ($reports as $report): ?>
				<article class="moderation-report-card">
					<p class="home-eyebrow"><?= $report['target_type'] === 'post' ? 'Reported post' : 'Reported account' ?> · <?= escape_html(date('M j, Y g:i a', strtotime((string) $report['created_at']))) ?></p>
					<h2><?= $report['target_type'] === 'post' ? 'Post by ' : 'Account ' ?>@<?= escape_html((string) $report['reported_username']) ?></h2>
					<p><strong>Reported by</strong> @<?= escape_html((string) $report['reporter_username']) ?></p>
					<p><strong>Reason</strong><br><?= nl2br(escape_html((string) $report['reason'])) ?></p>
					<?php if ($report['target_type'] === 'post'): ?>
						<p class="moderation-target-content"><?= $report['post_content'] !== null && $report['post_content'] !== '' ? nl2br(escape_html((string) $report['post_content'])) : 'This post has no text.' ?></p>
						<?php if ($report['post_status'] === 'published'): ?>
							<a href="home.php?post_id=<?= (int) $report['post_id'] ?>">Open post</a>
							<form class="moderation-delete-form" method="post" action="moderation.php" onsubmit="return confirm('Delete this post and notify its author?')">
							<input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
							<input type="hidden" name="report_id" value="<?= (int) $report['report_id'] ?>">
							<input type="hidden" name="review_action" value="delete_post">
							<button class="profile-logout-button" type="submit">Delete post and notify author</button>
						</form>
						<?php else: ?>
							<p>This post has already been deleted.</p>
						<?php endif; ?>
					<?php else: ?><a href="home.php?user_id=<?= (int) $report['reported_user_id'] ?>">Open profile</a><?php endif; ?>
					<form class="moderation-action-form" method="post" action="moderation.php">
						<input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
						<input type="hidden" name="report_id" value="<?= (int) $report['report_id'] ?>">
						<label for="action-<?= (int) $report['report_id'] ?>">Decision</label>
						<select id="action-<?= (int) $report['report_id'] ?>" name="review_action">
							<option value="none">Do nothing</option>
							<option value="temporary_block"><?= $report['target_type'] === 'post' ? 'Block user' : 'Block user temporarily' ?></option>
							<option value="ban_user">Ban user</option>
						</select>
						<div class="moderation-block-period" hidden>
							<label for="days-<?= (int) $report['report_id'] ?>">Block length</label>
							<select id="days-<?= (int) $report['report_id'] ?>" name="block_days" disabled>
								<option value="1">1 day</option><option value="7">7 days</option><option value="30">30 days</option><option value="90">90 days</option><option value="365">365 days</option>
							</select>
						</div>
						<button class="auth-button" type="submit">Save decision</button>
					</form>
				</article>
			<?php endforeach; ?>
		</div>
	</main>
	<script>
		document.querySelectorAll('.moderation-action-form').forEach((form) => {
			const action = form.querySelector('[name="review_action"]');
			const period = form.querySelector('.moderation-block-period');
			const days = form.querySelector('[name="block_days"]');
			const updatePeriodVisibility = () => {
				const shouldShow = action.value === 'temporary_block';
				period.hidden = !shouldShow;
				days.disabled = !shouldShow;
				days.required = shouldShow;
			};
			action.addEventListener('change', updatePeriodVisibility);
			updatePeriodVisibility();
		});
	</script>
</body>
</html>
