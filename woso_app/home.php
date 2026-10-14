<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/comment_view.php';

const MAX_POST_CONTENT_LENGTH = 5000;
const MAX_POST_MEDIA_SIZE = 25 * 1024 * 1024;

$error = '';
$status = 200;
$isSignedIn = isset($_SESSION['user_id']);
$posts = [];
$interactionStats = [];
$likedPostIds = [];
$repostedPostIds = [];
$commentsByPost = [];
$repliesByParent = [];
$commentStats = [];
$likedCommentIds = [];
$repostedCommentIds = [];
$profileViewRequested = array_key_exists('user_id', $_GET);
$profileUserId = null;
$profileUser = null;
$profileRedirect = '';
$postViewRequested = array_key_exists('post_id', $_GET);
$postViewId = null;
$postRedirect = '';

if ($profileViewRequested) {
	$userIdInput = $_GET['user_id'];
	$validatedUserId = is_string($userIdInput) && ctype_digit($userIdInput)
		? filter_var($userIdInput, FILTER_VALIDATE_INT)
		: false;
	if (!is_int($validatedUserId) || $validatedUserId < 1) {
		$error = 'Choose a valid account.';
		$status = 400;
	} else {
		$profileUserId = $validatedUserId;
		$profileRedirect = '?user_id=' . $profileUserId;
	}
}

if ($postViewRequested) {
	$postIdInput = $_GET['post_id'];
	$validatedPostId = is_string($postIdInput) && ctype_digit($postIdInput)
		? filter_var($postIdInput, FILTER_VALIDATE_INT)
		: false;
	if (!is_int($validatedPostId) || $validatedPostId < 1) {
		$error = 'Choose a valid post.';
		$status = 400;
	} else {
		$postViewId = $validatedPostId;
		$postRedirect = '?post_id=' . $postViewId;
	}
}

if (isset($_SESSION['home_notice']) && is_string($_SESSION['home_notice'])) {
	$notice = $_SESSION['home_notice'];
	unset($_SESSION['home_notice']);
} else {
	$notice = '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	if (!$isSignedIn) {
		http_response_code(403);
		exit('Sign in to publish a post.');
	}

	if (!has_valid_csrf_token()) {
		$error = 'Your session expired. Please try again.';
		$status = 400;
	} else {
		$action = post_string('action');
		if (in_array($action, ['like', 'comment', 'repost'], true)) {
			$postIdInput = post_string('post_id');
			$postId = ctype_digit($postIdInput) ? filter_var($postIdInput, FILTER_VALIDATE_INT) : false;
			$comment = trim(post_string('comment'));

			if (!is_int($postId) || $postId < 1) {
				$error = 'Choose a valid post.';
				$status = 400;
			} elseif ($action === 'comment' && ($comment === '' || strlen($comment) > 2000)) {
				$error = 'Comments must be between 1 and 2,000 characters.';
				$status = 400;
			} else {
				try {
					$database = database_connection();
					$database->beginTransaction();
					$postQuery = $database->prepare(
						'SELECT post_id, user_id FROM posts
						WHERE post_id = :post_id AND status = \'published\'
						FOR UPDATE'
					);
					$postQuery->execute(['post_id' => $postId]);
					$targetPost = $postQuery->fetch();
					if (!$targetPost) {
						$database->rollBack();
						$error = 'That post is no longer available.';
						$status = 404;
					} else {
						if ($action === 'like') {
							$existing = $database->prepare(
								'SELECT post_id FROM post_likes
								WHERE post_id = :post_id AND user_id = :user_id'
							);
							$existing->execute([
								'post_id' => $postId,
								'user_id' => (int) $_SESSION['user_id'],
							]);
							if ($existing->fetch()) {
								$toggle = $database->prepare(
									'DELETE FROM post_likes
									WHERE post_id = :post_id AND user_id = :user_id'
								);
								$toggle->execute([
									'post_id' => $postId,
									'user_id' => (int) $_SESSION['user_id'],
								]);
								$notice = 'Like removed.';
							} else {
								$toggle = $database->prepare(
									'INSERT INTO post_likes (post_id, user_id)
									VALUES (:post_id, :user_id)'
								);
								$toggle->execute([
									'post_id' => $postId,
									'user_id' => (int) $_SESSION['user_id'],
								]);
								if ((int) $targetPost['user_id'] !== (int) $_SESSION['user_id']) {
									$notification = $database->prepare(
										'INSERT INTO notifications
											(recipient_user_id, actor_user_id, post_id, notification_type)
										VALUES (:recipient_user_id, :actor_user_id, :post_id, \'like\')'
									);
									$notification->execute([
										'recipient_user_id' => (int) $targetPost['user_id'],
										'actor_user_id' => (int) $_SESSION['user_id'],
										'post_id' => $postId,
									]);
								}
								$notice = 'Post liked.';
							}
						} elseif ($action === 'comment') {
							$insertComment = $database->prepare(
								'INSERT INTO comments (post_id, user_id, content)
								VALUES (:post_id, :user_id, :content)'
							);
							$insertComment->execute([
								'post_id' => $postId,
								'user_id' => (int) $_SESSION['user_id'],
								'content' => $comment,
							]);
							if ((int) $targetPost['user_id'] !== (int) $_SESSION['user_id']) {
								$notification = $database->prepare(
									'INSERT INTO notifications
										(recipient_user_id, actor_user_id, post_id, comment_id, notification_type)
									VALUES (:recipient_user_id, :actor_user_id, :post_id, :comment_id, \'comment\')'
								);
								$notification->execute([
									'recipient_user_id' => (int) $targetPost['user_id'],
									'actor_user_id' => (int) $_SESSION['user_id'],
									'post_id' => $postId,
									'comment_id' => (int) $database->lastInsertId(),
								]);
							}
							$notice = 'Your comment has been added.';
						} else {
							$existing = $database->prepare(
								'SELECT repost_id FROM post_reposts
								WHERE post_id = :post_id AND user_id = :user_id'
							);
							$existing->execute([
								'post_id' => $postId,
								'user_id' => (int) $_SESSION['user_id'],
							]);
							if ($existing->fetch()) {
								$toggle = $database->prepare(
									'DELETE FROM post_reposts
									WHERE post_id = :post_id AND user_id = :user_id'
								);
								$toggle->execute([
									'post_id' => $postId,
									'user_id' => (int) $_SESSION['user_id'],
								]);
								$notice = 'Repost removed.';
							} else {
								$toggle = $database->prepare(
									'INSERT INTO post_reposts (post_id, user_id)
									VALUES (:post_id, :user_id)'
								);
								$toggle->execute([
									'post_id' => $postId,
									'user_id' => (int) $_SESSION['user_id'],
								]);
								if ((int) $targetPost['user_id'] !== (int) $_SESSION['user_id']) {
									$notification = $database->prepare(
										'INSERT INTO notifications
											(recipient_user_id, actor_user_id, post_id, notification_type)
										VALUES (:recipient_user_id, :actor_user_id, :post_id, \'repost\')'
									);
									$notification->execute([
										'recipient_user_id' => (int) $targetPost['user_id'],
										'actor_user_id' => (int) $_SESSION['user_id'],
										'post_id' => $postId,
									]);
								}
								$notice = 'Post reposted.';
							}
						}

						$database->commit();
						$_SESSION['home_notice'] = $notice;
						header('Location: home.php' . ($postRedirect !== '' ? $postRedirect : $profileRedirect) . '#post-' . $postId, true, 303);
						exit;
					}
				} catch (PDOException $exception) {
					if (isset($database) && $database->inTransaction()) {
						$database->rollBack();
					}
					error_log('WoSo home post interaction database error: ' . $exception->getMessage());
					$error = 'That action could not be saved. Please try again.';
					$status = 503;
				}
			}
		} elseif ($action !== 'create') {
			$error = 'Choose a valid post action.';
			$status = 400;
		} else {
			$content = trim(post_string('content'));
			$uploadedFilePath = null;

			if (strlen($content) > MAX_POST_CONTENT_LENGTH) {
				$error = 'Posts must be no longer than 5,000 characters.';
				$status = 400;
			} else {
				$media = $_FILES['media'] ?? null;
				$uploadError = $media === null
					? UPLOAD_ERR_NO_FILE
					: (is_array($media) && isset($media['error']) && is_int($media['error'])
						? $media['error']
						: UPLOAD_ERR_NO_FILE);
				$hasMedia = $uploadError !== UPLOAD_ERR_NO_FILE;

				if ($media !== null && (!is_array($media) || !isset($media['error']) || !is_int($media['error']))) {
					$error = 'The selected file could not be verified. Please choose it again.';
					$status = 400;
				} elseif ($hasMedia && $uploadError !== UPLOAD_ERR_OK) {
					$error = match ($uploadError) {
						UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The selected file is too large. The maximum size is 25 MB.',
						UPLOAD_ERR_PARTIAL => 'The file upload was incomplete. Please try again.',
						UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'The file could not be saved. Please try again later.',
						default => 'The file could not be uploaded. Please choose another file and try again.',
					};
					$status = in_array($uploadError, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 413 : 400;
				} elseif ($content === '' && !$hasMedia) {
					$error = 'Write a post or choose a photo or video to share.';
					$status = 400;
				} elseif ($hasMedia) {
					$tmpName = $media['tmp_name'] ?? null;
					$fileSize = $media['size'] ?? null;

					if (!is_string($tmpName) || !is_uploaded_file($tmpName) || !is_int($fileSize) || $fileSize < 1) {
						$error = 'The selected file could not be verified. Please choose it again.';
						$status = 400;
					} elseif ($fileSize > MAX_POST_MEDIA_SIZE) {
						$error = 'The selected file is too large. The maximum size is 25 MB.';
						$status = 413;
					} else {
						$fileInfo = new finfo(FILEINFO_MIME_TYPE);
						$mimeType = $fileInfo->file($tmpName);
						$allowedMedia = [
							'image/jpeg' => ['type' => 'image', 'extension' => 'jpg'],
							'image/png' => ['type' => 'image', 'extension' => 'png'],
							'image/gif' => ['type' => 'image', 'extension' => 'gif'],
							'image/webp' => ['type' => 'image', 'extension' => 'webp'],
							'video/mp4' => ['type' => 'video', 'extension' => 'mp4'],
							'video/webm' => ['type' => 'video', 'extension' => 'webm'],
							'video/quicktime' => ['type' => 'video', 'extension' => 'mov'],
						];
						$mediaDetails = is_string($mimeType) ? ($allowedMedia[$mimeType] ?? null) : null;

						if ($mediaDetails === null) {
							$error = 'Choose a JPEG, PNG, GIF, or WebP photo, or an MP4, WebM, or MOV video.';
							$status = 400;
						} else {
							$uploadDirectory = __DIR__ . '/uploads/posts';
							try {
								if (!is_dir($uploadDirectory)
									&& !mkdir($uploadDirectory, 0755, true)
									&& !is_dir($uploadDirectory)) {
									throw new RuntimeException('The post media directory could not be created.');
								}

								$storedFileName = bin2hex(random_bytes(16)) . '.' . $mediaDetails['extension'];
								$uploadedFilePath = $uploadDirectory . '/' . $storedFileName;
								if (!move_uploaded_file($tmpName, $uploadedFilePath)) {
									throw new RuntimeException('The uploaded post media could not be saved.');
								}
								$mediaPath = 'uploads/posts/' . $storedFileName;
								$mediaType = $mediaDetails['type'];
							} catch (RuntimeException $exception) {
								error_log('WoSo home media upload error: ' . $exception->getMessage());
								$error = 'The photo or video could not be saved. Please check the upload directory permissions and try again.';
								$status = 503;
							}
						}
					}
				}

				if ($error === '') {
					try {
						$database = database_connection();
						$statement = $database->prepare(
							'INSERT INTO posts (user_id, content, media_path, media_type)
							VALUES (:user_id, :content, :media_path, :media_type)'
						);
						$statement->execute([
							'user_id' => (int) $_SESSION['user_id'],
							'content' => $content === '' ? null : $content,
							'media_path' => $mediaPath ?? null,
							'media_type' => $mediaType ?? null,
						]);
						$_SESSION['home_notice'] = 'Your post has been shared.';
						header('Location: home.php');
						exit;
					} catch (PDOException $exception) {
						if ($uploadedFilePath !== null && is_file($uploadedFilePath)) {
							unlink($uploadedFilePath);
						}
						error_log('WoSo home post database error: ' . $exception->getMessage());
						$error = 'Your post could not be saved. Please check the database setup and try again.';
						$status = 503;
					}
				}
			}
		}
	}
}

try {
	$database = database_connection();
	if ($postViewRequested && $postViewId !== null) {
		$statement = $database->prepare(
			'SELECT posts.post_id AS feed_item_id, \'post\' AS feed_type,
				posts.created_at AS feed_created_at, NULL AS reposter_username,
				NULL AS reposter_user_id, posts.post_id, posts.content, posts.media_path,
				posts.media_type, posts.created_at, users.user_id, users.username
			FROM posts
			INNER JOIN users ON users.user_id = posts.user_id
			WHERE posts.status = \'published\' AND posts.post_id = :post_id'
		);
		$statement->execute(['post_id' => $postViewId]);
		$posts = $statement->fetchAll();
		if ($posts === []) {
			$error = 'That post could not be found.';
			$status = 404;
		}
	}

	if ($profileViewRequested && $profileUserId !== null) {
		$profileQuery = $database->prepare(
			'SELECT user_id, username FROM users WHERE user_id = :user_id'
		);
		$profileQuery->execute(['user_id' => $profileUserId]);
		$profileUser = $profileQuery->fetch() ?: null;
		if ($profileUser === null) {
			$error = 'That account could not be found.';
			$status = 404;
		} else {
			$statement = $database->prepare(
				'SELECT posts.post_id AS feed_item_id, \'post\' AS feed_type,
					posts.created_at AS feed_created_at, NULL AS reposter_username,
					NULL AS reposter_user_id, posts.post_id, posts.content, posts.media_path,
					posts.media_type, posts.created_at, users.user_id, users.username
				FROM posts
				INNER JOIN users ON users.user_id = posts.user_id
				WHERE posts.status = \'published\' AND posts.user_id = :user_id
				ORDER BY posts.created_at DESC, posts.post_id DESC'
			);
			$statement->execute(['user_id' => $profileUserId]);
			$posts = $statement->fetchAll();
		}
	}

	if (!$profileViewRequested && !$postViewRequested) {
		$statement = $database->query(
			'SELECT feed_items.feed_item_id, feed_items.feed_type, feed_items.feed_created_at,
				feed_items.reposter_username, feed_items.reposter_user_id,
				posts.post_id, posts.content, posts.media_path, posts.media_type,
				posts.created_at, users.user_id, users.username
			FROM (
				SELECT posts.post_id AS feed_item_id, \'post\' AS feed_type,
					posts.created_at AS feed_created_at, NULL AS reposter_username,
					NULL AS reposter_user_id, posts.post_id
				FROM posts
				WHERE posts.status = \'published\'
				UNION ALL
				SELECT post_reposts.repost_id AS feed_item_id, \'repost\' AS feed_type,
					post_reposts.created_at AS feed_created_at, reposting_users.username AS reposter_username,
					post_reposts.user_id AS reposter_user_id, post_reposts.post_id
				FROM post_reposts
				INNER JOIN posts ON posts.post_id = post_reposts.post_id
				INNER JOIN users AS reposting_users ON reposting_users.user_id = post_reposts.user_id
				WHERE posts.status = \'published\'
			) AS feed_items
			INNER JOIN posts ON posts.post_id = feed_items.post_id
			INNER JOIN users ON users.user_id = posts.user_id
			ORDER BY feed_items.feed_created_at DESC, feed_items.feed_item_id DESC
			LIMIT 50'
		);
		$posts = $statement->fetchAll();
	}
	$postIds = array_values(array_unique(array_map(
		static fn (array $post): int => (int) $post['post_id'],
		$posts
	)));
	if ($postIds !== []) {
		$placeholders = implode(',', array_fill(0, count($postIds), '?'));
		$statsQuery = $database->prepare(
			'SELECT posts.post_id,
				(SELECT COUNT(*) FROM post_likes WHERE post_likes.post_id = posts.post_id) AS like_count,
				(SELECT COUNT(*) FROM comments WHERE comments.post_id = posts.post_id AND comments.status = \'published\') AS comment_count,
				(SELECT COUNT(*) FROM post_reposts WHERE post_reposts.post_id = posts.post_id) AS repost_count
			FROM posts
			WHERE posts.post_id IN (' . $placeholders . ')'
		);
		$statsQuery->execute($postIds);
		foreach ($statsQuery->fetchAll() as $stats) {
			$interactionStats[(int) $stats['post_id']] = $stats;
		}

		if ($isSignedIn) {
			$likedQuery = $database->prepare(
				'SELECT post_id FROM post_likes
				WHERE user_id = ? AND post_id IN (' . $placeholders . ')'
			);
			$likedQuery->execute(array_merge([(int) $_SESSION['user_id']], $postIds));
			$likedPostIds = array_fill_keys(array_map('intval', $likedQuery->fetchAll(PDO::FETCH_COLUMN)), true);

			$repostedQuery = $database->prepare(
				'SELECT post_id FROM post_reposts
				WHERE user_id = ? AND post_id IN (' . $placeholders . ')'
			);
			$repostedQuery->execute(array_merge([(int) $_SESSION['user_id']], $postIds));
			$repostedPostIds = array_fill_keys(array_map('intval', $repostedQuery->fetchAll(PDO::FETCH_COLUMN)), true);
		}

		if ($postViewRequested && $posts !== []) {
		$commentsQuery = $database->prepare(
			'WITH RECURSIVE recent_roots AS (
				SELECT comments.comment_id, comments.post_id, comments.parent_comment_id,
					comments.user_id, comments.content, comments.media_path, comments.media_type,
					comments.created_at, users.username
				FROM comments
				INNER JOIN users ON users.user_id = comments.user_id
				WHERE comments.post_id IN (' . $placeholders . ')
					AND comments.parent_comment_id IS NULL
					AND comments.status = \'published\'
			),
			comment_tree AS (
				SELECT recent_roots.comment_id, recent_roots.post_id, recent_roots.parent_comment_id,
					recent_roots.user_id, recent_roots.content, recent_roots.media_path, recent_roots.media_type,
					recent_roots.created_at, recent_roots.username, 0 AS depth
				FROM recent_roots
				UNION ALL
				SELECT replies.comment_id, replies.post_id, replies.parent_comment_id,
					replies.user_id, replies.content, replies.media_path, replies.media_type,
					replies.created_at, reply_users.username, comment_tree.depth + 1
				FROM comments AS replies
				INNER JOIN comment_tree ON comment_tree.comment_id = replies.parent_comment_id
				INNER JOIN users AS reply_users ON reply_users.user_id = replies.user_id
				WHERE replies.status = \'published\' AND comment_tree.depth < 10
			)
			SELECT * FROM comment_tree
			ORDER BY post_id, created_at, comment_id'
		);
		$commentsQuery->execute($postIds);
		$comments = $commentsQuery->fetchAll();
		$commentIds = array_map(static fn (array $comment): int => (int) $comment['comment_id'], $comments);
		foreach ($comments as $comment) {
			$parentId = $comment['parent_comment_id'] === null ? null : (int) $comment['parent_comment_id'];
			if ($parentId === null) {
				$commentsByPost[(int) $comment['post_id']][] = $comment;
			} else {
				$repliesByParent[$parentId][] = $comment;
			}
		}
		if ($commentIds !== []) {
			$commentPlaceholders = implode(',', array_fill(0, count($commentIds), '?'));
			$commentStatsQuery = $database->prepare(
				'SELECT comments.comment_id,
					(SELECT COUNT(*) FROM comment_likes WHERE comment_likes.comment_id = comments.comment_id) AS like_count,
					(SELECT COUNT(*) FROM comments AS replies WHERE replies.parent_comment_id = comments.comment_id AND replies.status = \'published\') AS reply_count,
					(SELECT COUNT(*) FROM comment_reposts WHERE comment_reposts.comment_id = comments.comment_id) AS repost_count
				FROM comments
				WHERE comments.comment_id IN (' . $commentPlaceholders . ')'
			);
			$commentStatsQuery->execute($commentIds);
			foreach ($commentStatsQuery->fetchAll() as $stats) {
				$commentStats[(int) $stats['comment_id']] = $stats;
			}

			if ($isSignedIn) {
				$likedCommentsQuery = $database->prepare(
					'SELECT comment_id FROM comment_likes
					WHERE user_id = ? AND comment_id IN (' . $commentPlaceholders . ')'
				);
				$likedCommentsQuery->execute(array_merge([(int) $_SESSION['user_id']], $commentIds));
				$likedCommentIds = array_fill_keys(array_map('intval', $likedCommentsQuery->fetchAll(PDO::FETCH_COLUMN)), true);

				$repostedCommentsQuery = $database->prepare(
					'SELECT comment_id FROM comment_reposts
					WHERE user_id = ? AND comment_id IN (' . $commentPlaceholders . ')'
				);
				$repostedCommentsQuery->execute(array_merge([(int) $_SESSION['user_id']], $commentIds));
				$repostedCommentIds = array_fill_keys(array_map('intval', $repostedCommentsQuery->fetchAll(PDO::FETCH_COLUMN)), true);
			}
		}
		}
	}
} catch (PDOException $exception) {
	error_log('WoSo home feed database error: ' . $exception->getMessage());
	$error = 'The community feed is temporarily unavailable. Please check the database setup and try again.';
	$status = 503;
}

http_response_code($status);
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?= $profileUser !== null ? escape_html((string) $profileUser['username']) . ' - Posts' : 'Women\'s Football - Home' ?></title>
	<link rel="stylesheet" href="styles.css">
</head>
<body>
	<nav class="topnav" aria-label="Main navigation">
		<a class="active" aria-current="page" href="home.php">Home</a>
		<a href="matches.php">Match Center</a>
		<a href="community.html">Community</a>
		<a href="polls.php">Polls</a>
		<a href="predictions.php">Predictions</a>
		<?= notifications_nav_link() ?>
		<?php if (can_access_moderation()): ?>
			<a href="moderation.php">Moderation</a>
		<?php endif; ?>
		<a href="login.php"><?= $isSignedIn ? 'Account' : 'Sign in' ?></a>
	</nav>
	<main class="home-page">
		<header class="home-heading">
			<p class="home-eyebrow">A community for the beautiful game</p>
			<h1><?= $postViewRequested && $posts !== [] ? 'Post by @' . escape_html((string) $posts[0]['username']) : ($profileUser !== null ? 'Posts by @' . escape_html((string) $profileUser['username']) : 'Women\'s Football Community') ?></h1>
			<?php if ($postViewRequested): ?>
				<p><a href="<?= $profileUser !== null ? 'home.php?user_id=' . (int) $profileUser['user_id'] : ($posts !== [] ? 'home.php?user_id=' . (int) $posts[0]['user_id'] : 'home.php') ?>">Back to posts</a></p>
			<?php elseif ($profileUser !== null): ?>
				<p><a href="home.php">Back to the community feed</a></p>
			<?php else: ?>
			<p>Share the moments, matches, and conversations you love with fans everywhere.</p>
			<?php endif; ?>
		</header>

		<?php if ($error !== ''): ?>
			<p class="auth-message auth-error" role="alert"><?= escape_html($error) ?></p>
		<?php endif; ?>
		<?php if ($notice !== ''): ?>
			<p class="auth-message auth-success" role="status"><?= escape_html($notice) ?></p>
		<?php endif; ?>

		<?php if ($isSignedIn && !$profileViewRequested && !$postViewRequested): ?>
			<section class="post-composer" aria-labelledby="composer-heading">
				<h2 id="composer-heading">What's on your mind, <?= escape_html((string) $_SESSION['username']) ?>?</h2>
				<form action="home.php" method="post" enctype="multipart/form-data">
					<input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
					<input type="hidden" name="action" value="create">
					<label class="visually-hidden" for="post-content">Write a post</label>
					<textarea id="post-content" name="content" maxlength="<?= MAX_POST_CONTENT_LENGTH ?>" placeholder="Share a thought about women's football..." rows="4"><?= escape_html(post_string('content')) ?></textarea>
					<div class="post-composer-actions">
						<div>
							<label for="post-media">Add a photo or video</label>
							<input id="post-media" name="media" type="file" accept="image/jpeg,image/png,image/gif,image/webp,video/mp4,video/webm,video/quicktime">
							<p class="post-hint">JPEG, PNG, GIF, WebP, MP4, WebM, or MOV. Up to 25 MB.</p>
						</div>
						<button class="auth-button" type="submit">Share post</button>
					</div>
				</form>
			</section>
		<?php elseif (!$profileViewRequested && !$postViewRequested): ?>
			<aside class="visitor-prompt">
				<p>Join the conversation: <a href="login.php?redirect=home.php">sign in</a> or <a href="register.php?redirect=home.php">create an account</a> to share a post.</p>
			</aside>
		<?php endif; ?>

		<section class="home-feed" aria-labelledby="feed-heading">
			<div class="home-feed-heading">
				<div>
					<p class="home-eyebrow">From the community</p>
					<h2 id="feed-heading"><?= $postViewRequested ? 'Post and comments' : ($profileUser !== null ? 'Posts by @' . escape_html((string) $profileUser['username']) : ($profileViewRequested ? 'Account posts' : 'Latest posts')) ?></h2>
				</div>
			</div>
			<?php if ($posts === [] && (!$profileViewRequested || $profileUser !== null)): ?>
				<p class="empty-feed"><?php if ($profileUser !== null): ?>@<?= escape_html((string) $profileUser['username']) ?> hasn't shared any posts yet.<?php else: ?>No posts yet. <?= $isSignedIn ? 'Be the first to start the conversation.' : 'Check back soon for community updates.' ?><?php endif; ?></p>
			<?php elseif ($posts !== []): ?>
				<div class="post-list">
					<?php foreach ($posts as $post): ?>
						<?php
						$postId = (int) $post['post_id'];
						$feedItemKey = $post['feed_type'] === 'repost'
							? 'repost-' . (int) $post['feed_item_id']
							: 'post-' . $postId;
						$stats = $interactionStats[$postId] ?? ['like_count' => 0, 'comment_count' => 0, 'repost_count' => 0];
						?>
						<article class="post-card<?= $postViewRequested ? '' : ' is-openable' ?>" id="<?= escape_html($feedItemKey) ?>"<?= $postViewRequested ? '' : ' tabindex="0" aria-label="Open post by @' . escape_html((string) $post['username']) . '" data-post-url="home.php?post_id=' . $postId . '"' ?>>
							<?php if ($post['feed_type'] === 'repost'): ?>
								<p class="post-repost-attribution"><a class="profile-link" href="home.php?user_id=<?= (int) $post['reposter_user_id'] ?>"><?= escape_html((string) $post['reposter_username']) ?></a> reposted</p>
							<?php endif; ?>
							<header class="post-author">
								<a class="profile-link" href="home.php?user_id=<?= (int) $post['user_id'] ?>"><?= escape_html((string) $post['username']) ?></a>
								<time datetime="<?= escape_html((string) $post['feed_created_at']) ?>"><?= escape_html(date('M j, Y \a\t g:i a', strtotime((string) $post['feed_created_at']))) ?></time>
							</header>
							<div class="post-open-content">
								<?php if (is_string($post['content']) && $post['content'] !== ''): ?>
									<p class="post-content"><?= nl2br(escape_html((string) $post['content'])) ?></p>
								<?php endif; ?>
								<?php if ($post['media_type'] === 'image' && is_string($post['media_path'])): ?>
									<img class="post-media" src="<?= escape_html((string) $post['media_path']) ?>" alt="Photo shared by <?= escape_html((string) $post['username']) ?>" loading="lazy">
								<?php elseif ($post['media_type'] === 'video' && is_string($post['media_path'])): ?>
									<video class="post-media"<?= $postViewRequested ? ' controls' : '' ?> preload="metadata">
										<source src="<?= escape_html((string) $post['media_path']) ?>">
										Your browser does not support video playback.
									</video>
								<?php endif; ?>
							</div>
							<div class="post-interactions" aria-label="Post interactions">
								<?php if ($isSignedIn): ?>
									<form action="home.php<?= escape_html($postViewRequested ? $postRedirect : $profileRedirect) ?>" method="post">
										<input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
										<input type="hidden" name="post_id" value="<?= $postId ?>">
										<input type="hidden" name="action" value="like">
										<button class="post-action<?= isset($likedPostIds[$postId]) ? ' is-active' : '' ?>" type="submit" aria-pressed="<?= isset($likedPostIds[$postId]) ? 'true' : 'false' ?>">&#9829; <?= isset($likedPostIds[$postId]) ? 'Liked' : 'Like' ?> <span><?= (int) $stats['like_count'] ?></span></button>
									</form>
									<form action="home.php<?= escape_html($postViewRequested ? $postRedirect : $profileRedirect) ?>" method="post">
										<input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
										<input type="hidden" name="post_id" value="<?= $postId ?>">
										<input type="hidden" name="action" value="repost">
										<button class="post-action<?= isset($repostedPostIds[$postId]) ? ' is-active' : '' ?>" type="submit" aria-pressed="<?= isset($repostedPostIds[$postId]) ? 'true' : 'false' ?>"><?= isset($repostedPostIds[$postId]) ? 'Reposted' : 'Repost' ?> <span><?= (int) $stats['repost_count'] ?></span></button>
									</form>
								<?php else: ?>
									<span class="post-action-summary">&#9829; <?= (int) $stats['like_count'] ?> likes</span>
									<span class="post-action-summary"><?= (int) $stats['repost_count'] ?> reposts</span>
								<?php endif; ?>
								<span class="post-action-summary"><?= (int) $stats['comment_count'] ?> comments</span>
							</div>
							<?php if ($postViewRequested && !empty($commentsByPost[$postId])): ?>
								<div class="post-comments" aria-label="Recent comments">
									<?php foreach ($commentsByPost[$postId] as $comment): ?>
										<?php render_comment($comment, $repliesByParent, $commentStats, $likedCommentIds, $repostedCommentIds, $isSignedIn); ?>
									<?php endforeach; ?>
								</div>
							<?php elseif ($postViewRequested): ?>
								<p class="empty-feed">No comments yet. Be the first to join the conversation.</p>
							<?php endif; ?>
							<?php if ($isSignedIn && $postViewRequested): ?>
								<form class="post-comment-form post-root-comment-form" action="home_action.php<?= escape_html($postRedirect) ?>" method="post" enctype="multipart/form-data">
									<input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
									<input type="hidden" name="target_type" value="post">
									<input type="hidden" name="target_id" value="<?= $postId ?>">
									<input type="hidden" name="post_id" value="<?= $postId ?>">
									<input type="hidden" name="action" value="comment">
									<label class="visually-hidden" for="comment-<?= escape_html($feedItemKey) ?>">Write a comment</label>
									<input id="comment-<?= escape_html($feedItemKey) ?>" name="content" type="text" maxlength="<?= MAX_POST_CONTENT_LENGTH ?>" placeholder="Write a comment...">
									<label class="comment-media-label" for="comment-media-<?= escape_html($feedItemKey) ?>">Photo/video</label>
									<input id="comment-media-<?= escape_html($feedItemKey) ?>" class="comment-file-input" name="media" type="file" accept="image/jpeg,image/png,image/gif,image/webp,video/mp4,video/webm,video/quicktime">
									<button class="post-action" type="submit">Comment</button>
								</form>
							<?php endif; ?>
						</article>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</section>
	</main>
	<?php if (!$postViewRequested): ?>
		<script>
			document.querySelectorAll('.post-card.is-openable').forEach((postCard) => {
				const openPost = () => {
					window.location.href = postCard.dataset.postUrl;
				};

				postCard.addEventListener('click', (event) => {
					if (event.target.closest('a, button, input, textarea, select, video, audio, form, [contenteditable="true"]')) {
						return;
					}
					openPost();
				});

				postCard.addEventListener('keydown', (event) => {
					if (event.target === postCard && event.key === 'Enter') {
						event.preventDefault();
						openPost();
					}
				});
			});
		</script>
	<?php endif; ?>
</body>
</html>
