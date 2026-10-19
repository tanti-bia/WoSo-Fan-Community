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
		$decisionType = post_string('decision_type');
		$action = post_string('review_action');
		$days = filter_var(post_string('block_days'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 365]]);
		try {
			$validAction = ($decisionType === 'post' && in_array($action, ['none', 'delete_post'], true))
				|| ($decisionType === 'user' && in_array($action, ['none', 'ban_user', 'temporary_block'], true));
			if ($reportId === false || !$validAction) {
				throw new RuntimeException('Choose a valid moderation action.');
			}
			$database->beginTransaction();
			$query = $database->prepare('SELECT * FROM reports WHERE report_id = :report_id AND status = \'pending\' FOR UPDATE');
			$query->execute(['report_id' => $reportId]);
			$report = $query->fetch();
			if (!$report) {
				throw new RuntimeException('That report has already been reviewed.');
			}
			if ($decisionType === 'post') {
				if ($report['target_type'] !== 'post' || $report['post_id'] === null) {
					throw new RuntimeException('Only a post report has a post decision.');
				}
				if ($action === 'delete_post') {
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
						"UPDATE reports SET post_review_action = 'delete_post', post_reviewed_by = :reviewer_one,
						post_reviewed_at = UTC_TIMESTAMP(),
						status = IF(user_review_action IS NULL, 'pending', 'actioned'),
						review_action = IF(user_review_action IS NULL, review_action, 'delete_post'),
						reviewed_by = IF(user_review_action IS NULL, reviewed_by, :reviewer_two),
						reviewed_at = IF(user_review_action IS NULL, reviewed_at, UTC_TIMESTAMP())
						WHERE target_type = 'post' AND post_id = :post_id AND status = 'pending'"
					);
					$query->execute([
						'reviewer_one' => (int) $_SESSION['user_id'],
						'reviewer_two' => (int) $_SESSION['user_id'],
						'post_id' => (int) $report['post_id'],
					]);
				}
			} elseif ($decisionType === 'user' && in_array($action, ['ban_user', 'temporary_block'], true)) {
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
			$nextPostAction = $decisionType === 'post' ? $action : $report['post_review_action'];
			$nextUserAction = $decisionType === 'user' ? $action : $report['user_review_action'];
			$fullyReviewed = $report['target_type'] === 'user'
				? $decisionType === 'user'
				: ($nextPostAction !== null && $nextUserAction !== null);
			$resolvedStatus = !$fullyReviewed
				? 'pending'
				: (($nextPostAction === 'none' || $nextPostAction === null)
					&& ($nextUserAction === 'none' || $nextUserAction === null) ? 'dismissed' : 'actioned');
			if ($decisionType === 'post') {
				$query = $database->prepare('UPDATE reports SET post_review_action = :post_action, post_reviewed_by = :post_reviewer, post_reviewed_at = UTC_TIMESTAMP(), status = :status, review_action = :last_action, reviewed_by = :last_reviewer, reviewed_at = UTC_TIMESTAMP() WHERE report_id = :report_id');
				$query->execute([
					'post_action' => $action,
					'post_reviewer' => (int) $_SESSION['user_id'],
					'status' => $resolvedStatus,
					'last_action' => $action,
					'last_reviewer' => (int) $_SESSION['user_id'],
					'report_id' => $reportId,
				]);
			} else {
				$query = $database->prepare('UPDATE reports SET user_review_action = :user_action, user_reviewed_by = :user_reviewer, user_reviewed_at = UTC_TIMESTAMP(), status = :status, review_action = :last_action, blocked_until = :blocked_until, reviewed_by = :last_reviewer, reviewed_at = UTC_TIMESTAMP() WHERE report_id = :report_id');
				$query->execute([
					'user_action' => $action,
					'user_reviewer' => (int) $_SESSION['user_id'],
					'status' => $resolvedStatus,
					'last_action' => $action,
					'blocked_until' => $action === 'temporary_block' ? $until : null,
					'last_reviewer' => (int) $_SESSION['user_id'],
					'report_id' => $reportId,
				]);
			}
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
			r.post_review_action, r.user_review_action, r.blocked_until,
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
	<link rel="icon" type="image/png" href="assets/woso-logo.png">
	<title>Women's Football - Moderation</title>
	<link rel="stylesheet" href="styles.css">
	<script src="back_button.js" defer></script>
</head>
<body>
	<nav class="topnav" aria-label="Main navigation">
		<a class="site-logo" href="home.php" aria-label="Women's Football Community home"><img src="assets/woso-logo.png" alt=""></a>
		<a href="home.html">Home</a><a href="matches.php">Match Center</a><?= community_nav_link() ?>
		<a href="predictions.php">Predictions</a><?= notifications_nav_link() ?>
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
						<?php else: ?>
							<p>This post has already been deleted.</p>
						<?php endif; ?>
						<section class="moderation-review-row" aria-labelledby="manage-post-<?= (int) $report['report_id'] ?>">
							<h3 id="manage-post-<?= (int) $report['report_id'] ?>">Manage post</h3>
							<?php if ($report['post_review_action'] !== null): ?>
								<p>Post decision saved: <?= $report['post_review_action'] === 'delete_post' ? 'Delete post' : 'Do nothing' ?>.</p>
							<?php else: ?>
								<form class="moderation-action-form post-review-form" method="post" action="moderation.php">
									<input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
									<input type="hidden" name="report_id" value="<?= (int) $report['report_id'] ?>">
									<input type="hidden" name="decision_type" value="post">
									<label for="post-action-<?= (int) $report['report_id'] ?>">Decision</label>
									<select id="post-action-<?= (int) $report['report_id'] ?>" name="review_action">
										<option value="none">Do nothing</option><option value="delete_post">Delete post</option>
									</select>
									<button class="auth-button" type="submit">Save post decision</button>
								</form>
							<?php endif; ?>
						</section>
					<?php else: ?><a href="home.php?user_id=<?= (int) $report['reported_user_id'] ?>">Open profile</a><?php endif; ?>
					<section class="moderation-review-row" aria-labelledby="manage-user-<?= (int) $report['report_id'] ?>">
						<h3 id="manage-user-<?= (int) $report['report_id'] ?>">Manage user</h3>
						<?php if ($report['user_review_action'] !== null): ?>
							<p>User decision saved: <?php if ($report['user_review_action'] === 'temporary_block'): ?>Blocked until <?= escape_html(date('M j, Y g:i a', strtotime((string) $report['blocked_until']))) ?><?php elseif ($report['user_review_action'] === 'ban_user'): ?>Banned<?php else: ?>Do nothing<?php endif; ?>.</p>
						<?php else: ?>
							<form class="moderation-action-form user-review-form" method="post" action="moderation.php">
								<input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
								<input type="hidden" name="report_id" value="<?= (int) $report['report_id'] ?>">
								<input type="hidden" name="decision_type" value="user">
								<label for="user-action-<?= (int) $report['report_id'] ?>">Decision</label>
								<select id="user-action-<?= (int) $report['report_id'] ?>" name="review_action">
									<option value="none">Do nothing</option><option value="temporary_block">Block user temporarily</option><option value="ban_user">Ban user</option>
								</select>
								<div class="moderation-block-period" hidden>
									<label for="days-<?= (int) $report['report_id'] ?>">Block length</label>
									<select id="days-<?= (int) $report['report_id'] ?>" name="block_days" disabled>
										<option value="1">1 day</option><option value="7">7 days</option><option value="30">30 days</option><option value="90">90 days</option><option value="365">365 days</option>
									</select>
								</div>
								<button class="auth-button" type="submit">Save user decision</button>
							</form>
						<?php endif; ?>
					</section>
				</article>
			<?php endforeach; ?>
		</div>
	</main>
	<script>
		document.querySelectorAll('.moderation-action-form').forEach((form) => {
			const action = form.querySelector('[name="review_action"]');
			const period = form.querySelector('.moderation-block-period');
			const days = form.querySelector('[name="block_days"]');
			if (!period || !days) return;
			const updatePeriodVisibility = () => {
				const shouldShow = action.value === 'temporary_block';
				period.hidden = !shouldShow;
				days.disabled = !shouldShow;
				days.required = shouldShow;
			};
			action.addEventListener('change', updatePeriodVisibility);
			updatePeriodVisibility();
		});
		document.querySelectorAll('.post-review-form').forEach((form) => {
			form.addEventListener('submit', (event) => {
				if (form.querySelector('[name="review_action"]').value === 'delete_post'
					&& !confirm('Delete this post and notify its author?')) event.preventDefault();
			});
		});
	</script>
</body>
</html>
