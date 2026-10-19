<?php
declare(strict_types=1);

function render_comment(
	array $comment,
	array $repliesByParent,
	array $commentStats,
	array $likedCommentIds,
	array $repostedCommentIds,
	bool $isSignedIn,
	int $depth = 0
): void {
	$commentId = (int) $comment['comment_id'];
	$commentUrl = 'home.php?post_id=' . (int) $comment['post_id'] . '#comment-' . $commentId;
	$stats = $commentStats[$commentId] ?? ['like_count' => 0, 'reply_count' => 0, 'repost_count' => 0];
	?>
	<article class="comment-card is-openable<?= $depth > 0 ? ' comment-reply' : '' ?>"
		id="comment-<?= $commentId ?>"
		tabindex="0"
		aria-label="Open comment by @<?= escape_html((string) $comment['username']) ?>"
		data-post-url="<?= escape_html($commentUrl) ?>"
		style="--comment-depth: <?= min($depth, 5) ?>">
		<header class="post-author">
			<div class="post-author-identity">
				<a class="post-author-avatar-link" href="home.php?user_id=<?= (int) $comment['user_id'] ?>" aria-label="View @<?= escape_html((string) $comment['username']) ?>'s profile">
					<?php if (is_string($comment['profile_image_path']) && $comment['profile_image_path'] !== ''): ?>
						<img class="post-author-avatar" src="<?= escape_html((string) $comment['profile_image_path']) ?>" alt="" loading="lazy">
					<?php else: ?>
						<span class="post-author-avatar post-author-avatar-placeholder" aria-hidden="true"><?= escape_html(strtoupper(substr((string) $comment['username'], 0, 1))) ?></span>
					<?php endif; ?>
				</a>
				<span class="post-author-names">
					<?php if (is_string($comment['display_name']) && $comment['display_name'] !== ''): ?>
						<span class="post-author-display-name"><?= escape_html((string) $comment['display_name']) ?></span>
					<?php endif; ?>
					<a class="profile-link" href="home.php?user_id=<?= (int) $comment['user_id'] ?>">@<?= escape_html((string) $comment['username']) ?></a>
				</span>
			</div>
			<time datetime="<?= escape_html((string) $comment['created_at']) ?>"><?= escape_html(date('M j, Y \a\t g:i a', strtotime((string) $comment['created_at']))) ?></time>
		</header>
		<?php if (is_string($comment['content']) && $comment['content'] !== ''): ?>
			<p class="post-content"><?= nl2br(escape_html((string) $comment['content'])) ?></p>
		<?php endif; ?>
		<?php if ($comment['media_type'] === 'image' && is_string($comment['media_path'])): ?>
			<img class="comment-media" src="<?= escape_html((string) $comment['media_path']) ?>" alt="Photo shared by <?= escape_html((string) $comment['username']) ?>" loading="lazy">
		<?php elseif ($comment['media_type'] === 'video' && is_string($comment['media_path'])): ?>
			<video class="comment-media" controls preload="metadata">
				<source src="<?= escape_html((string) $comment['media_path']) ?>">
				Your browser does not support video playback.
			</video>
		<?php endif; ?>
		<div class="post-interactions" aria-label="Comment interactions">
			<?php if ($isSignedIn): ?>
				<form action="home_action.php" method="post">
					<input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
					<input type="hidden" name="action" value="like">
					<input type="hidden" name="target_type" value="comment">
					<input type="hidden" name="post_id" value="<?= (int) $comment['post_id'] ?>">
					<input type="hidden" name="target_id" value="<?= $commentId ?>">
					<button class="post-action<?= isset($likedCommentIds[$commentId]) ? ' is-active' : '' ?>" type="submit" aria-pressed="<?= isset($likedCommentIds[$commentId]) ? 'true' : 'false' ?>">&#9829; <?= isset($likedCommentIds[$commentId]) ? 'Liked' : 'Like' ?> <span><?= (int) $stats['like_count'] ?></span></button>
				</form>
				<form action="home_action.php" method="post">
					<input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
					<input type="hidden" name="action" value="repost">
					<input type="hidden" name="target_type" value="comment">
					<input type="hidden" name="post_id" value="<?= (int) $comment['post_id'] ?>">
					<input type="hidden" name="target_id" value="<?= $commentId ?>">
					<button class="post-action<?= isset($repostedCommentIds[$commentId]) ? ' is-active' : '' ?>" type="submit" aria-pressed="<?= isset($repostedCommentIds[$commentId]) ? 'true' : 'false' ?>"><?= isset($repostedCommentIds[$commentId]) ? 'Reposted' : 'Repost' ?> <span><?= (int) $stats['repost_count'] ?></span></button>
				</form>
			<?php else: ?>
				<span class="post-action-summary">&#9829; <?= (int) $stats['like_count'] ?> likes</span>
				<span class="post-action-summary"><?= (int) $stats['repost_count'] ?> reposts</span>
			<?php endif; ?>
			<span class="post-action-summary"><?= (int) $stats['reply_count'] ?> replies</span>
		</div>
		<?php if ($isSignedIn && $depth < 10): ?>
			<form class="post-comment-form" action="home_action.php" method="post" enctype="multipart/form-data">
				<input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
				<input type="hidden" name="action" value="comment">
				<input type="hidden" name="target_type" value="comment">
				<input type="hidden" name="post_id" value="<?= (int) $comment['post_id'] ?>">
				<input type="hidden" name="target_id" value="<?= $commentId ?>">
				<div class="post-comment-bubble">
					<label class="visually-hidden" for="reply-<?= $commentId ?>">Reply to comment</label>
					<input id="reply-<?= $commentId ?>" name="content" type="text" maxlength="5000" placeholder="Write a reply...">
					<div class="composer-icon-stack">
					<button class="composer-send-button" type="submit" aria-label="Reply" title="Reply">
						<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M3 11.5 21 3l-8.5 18-1.7-7.8L3 11.5Z"/><path d="m10.8 13.2 4.8-4.8"/></svg>
					</button>
					<input id="reply-media-<?= $commentId ?>" class="comment-file-input composer-file-input" name="media" type="file" accept="image/jpeg,image/png,image/gif,image/webp,video/mp4,video/webm,video/quicktime" aria-label="Attach a photo or video">
					<label class="composer-attachment-button" for="reply-media-<?= $commentId ?>" title="Attach a photo or video">
						<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M8.5 12.5 14.7 6.3a3.2 3.2 0 0 1 4.5 4.5l-8.1 8.1a5 5 0 0 1-7.1-7.1l8.1-8.1"/><path d="m7.8 14.2 8.1-8.1"/></svg>
						<span class="visually-hidden">Attach a photo or video</span>
					</label>
					</div>
				</div>
			</form>
		<?php endif; ?>
		<?php if (!empty($repliesByParent[$commentId])): ?>
			<div class="comment-replies">
				<?php foreach ($repliesByParent[$commentId] as $reply): ?>
					<?php render_comment($reply, $repliesByParent, $commentStats, $likedCommentIds, $repostedCommentIds, $isSignedIn, $depth + 1); ?>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</article>
	<?php
}
