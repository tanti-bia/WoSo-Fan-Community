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
	$stats = $commentStats[$commentId] ?? ['like_count' => 0, 'reply_count' => 0, 'repost_count' => 0];
	?>
	<article class="comment-card<?= $depth > 0 ? ' comment-reply' : '' ?>" id="comment-<?= $commentId ?>" style="--comment-depth: <?= min($depth, 5) ?>">
		<header class="post-author">
			<strong><?= escape_html((string) $comment['username']) ?></strong>
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
				<label class="visually-hidden" for="reply-<?= $commentId ?>">Reply to comment</label>
				<input id="reply-<?= $commentId ?>" name="content" type="text" maxlength="5000" placeholder="Write a reply...">
				<label class="comment-media-label" for="reply-media-<?= $commentId ?>">Photo/video</label>
				<input id="reply-media-<?= $commentId ?>" class="comment-file-input" name="media" type="file" accept="image/jpeg,image/png,image/gif,image/webp,video/mp4,video/webm,video/quicktime">
				<button class="post-action" type="submit">Reply</button>
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
