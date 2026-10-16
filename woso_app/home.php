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
$profileActivity = 'posts';
$profileComments = [];
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
		$requestedActivity = isset($_GET['activity']) && is_string($_GET['activity'])
			? $_GET['activity']
			: 'posts';
		if (!in_array($requestedActivity, ['posts', 'reposts', 'comments'], true)) {
			$error = 'Choose a valid profile activity.';
			$status = 400;
		} else {
			$profileActivity = $requestedActivity;
		}
		$profileRedirect = '?user_id=' . $profileUserId . '&activity=' . rawurlencode($profileActivity);
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
				NULL AS reposter_user_id, NULL AS reposter_display_name,
				NULL AS reposter_profile_image_path, posts.post_id, posts.content, posts.media_path,
				posts.media_type, posts.created_at, users.user_id, users.username,
				users.display_name, users.profile_image_path
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
			'SELECT user_id, username, display_name, bio, favorite_team, location, profile_image_path
			FROM users WHERE user_id = :user_id'
		);
		$profileQuery->execute(['user_id' => $profileUserId]);
		$profileUser = $profileQuery->fetch() ?: null;
		if ($profileUser === null) {
			$error = 'That account could not be found.';
			$status = 404;
		} elseif ($profileActivity === 'posts') {
			$statement = $database->prepare(
				'SELECT posts.post_id AS feed_item_id, \'post\' AS feed_type,
					posts.created_at AS feed_created_at, NULL AS reposter_username,
					NULL AS reposter_user_id, NULL AS reposter_display_name,
					NULL AS reposter_profile_image_path, posts.post_id, posts.content, posts.media_path,
					posts.media_type, posts.created_at, users.user_id, users.username,
					users.display_name, users.profile_image_path
				FROM posts
				INNER JOIN users ON users.user_id = posts.user_id
				WHERE posts.status = \'published\' AND posts.user_id = :user_id
				ORDER BY posts.created_at DESC, posts.post_id DESC'
			);
			$statement->execute(['user_id' => $profileUserId]);
			$posts = $statement->fetchAll();
		} elseif ($profileActivity === 'reposts') {
			$statement = $database->prepare(
				'SELECT post_reposts.repost_id AS feed_item_id, \'repost\' AS feed_type,
					post_reposts.created_at AS feed_created_at,
					reposting_users.username AS reposter_username,
					reposting_users.user_id AS reposter_user_id,
					reposting_users.display_name AS reposter_display_name,
					reposting_users.profile_image_path AS reposter_profile_image_path,
					posts.post_id, posts.content, posts.media_path, posts.media_type,
					posts.created_at, authors.user_id, authors.username,
					authors.display_name, authors.profile_image_path
				FROM post_reposts
				INNER JOIN posts ON posts.post_id = post_reposts.post_id
				INNER JOIN users AS authors ON authors.user_id = posts.user_id
				INNER JOIN users AS reposting_users ON reposting_users.user_id = post_reposts.user_id
				WHERE post_reposts.user_id = :user_id AND posts.status = \'published\'
				ORDER BY post_reposts.created_at DESC, post_reposts.repost_id DESC'
			);
			$statement->execute(['user_id' => $profileUserId]);
			$posts = $statement->fetchAll();
		} else {
			$statement = $database->prepare(
				'SELECT comments.comment_id, comments.post_id, comments.content,
					comments.parent_comment_id, comments.media_path, comments.media_type, comments.created_at,
					LEFT(posts.content, 220) AS post_content,
					CHAR_LENGTH(posts.content) > 220 AS post_content_truncated,
					posts.media_type AS post_media_type,
					authors.user_id AS post_author_user_id,
					authors.username AS post_author_username,
					LEFT(parent_comments.content, 220) AS parent_comment_preview,
					CHAR_LENGTH(parent_comments.content) > 220 AS parent_comment_truncated,
					parent_comments.media_type AS parent_comment_media_type,
					parent_users.user_id AS parent_user_id,
					parent_users.username AS parent_username,
					parent_users.display_name AS parent_display_name
				FROM comments
				INNER JOIN posts ON posts.post_id = comments.post_id
				INNER JOIN users AS authors ON authors.user_id = posts.user_id
				LEFT JOIN comments AS parent_comments
					ON parent_comments.comment_id = comments.parent_comment_id
					AND parent_comments.status = \'published\'
				LEFT JOIN users AS parent_users ON parent_users.user_id = parent_comments.user_id
				WHERE comments.user_id = :user_id
					AND comments.status = \'published\'
					AND posts.status = \'published\'
				ORDER BY comments.created_at DESC, comments.comment_id DESC
				LIMIT 100'
			);
			$statement->execute(['user_id' => $profileUserId]);
			$profileComments = $statement->fetchAll();
		}
	}

	if (!$profileViewRequested && !$postViewRequested) {
		$statement = $database->query(
			'SELECT feed_items.feed_item_id, feed_items.feed_type, feed_items.feed_created_at,
				feed_items.reposter_username, feed_items.reposter_user_id,
				feed_items.reposter_display_name, feed_items.reposter_profile_image_path,
				posts.post_id, posts.content, posts.media_path, posts.media_type,
				posts.created_at, users.user_id, users.username,
				users.display_name, users.profile_image_path
			FROM (
				SELECT posts.post_id AS feed_item_id, \'post\' AS feed_type,
					posts.created_at AS feed_created_at, NULL AS reposter_username,
					NULL AS reposter_user_id, NULL AS reposter_display_name,
					NULL AS reposter_profile_image_path, posts.post_id
				FROM posts
				WHERE posts.status = \'published\'
				UNION ALL
				SELECT post_reposts.repost_id AS feed_item_id, \'repost\' AS feed_type,
					post_reposts.created_at AS feed_created_at, reposting_users.username AS reposter_username,
					post_reposts.user_id AS reposter_user_id,
					reposting_users.display_name AS reposter_display_name,
					reposting_users.profile_image_path AS reposter_profile_image_path,
					post_reposts.post_id
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
					comments.created_at, users.username, users.display_name, users.profile_image_path
				FROM comments
				INNER JOIN users ON users.user_id = comments.user_id
				WHERE comments.post_id IN (' . $placeholders . ')
					AND comments.parent_comment_id IS NULL
					AND comments.status = \'published\'
			),
			comment_tree AS (
				SELECT recent_roots.comment_id, recent_roots.post_id, recent_roots.parent_comment_id,
					recent_roots.user_id, recent_roots.content, recent_roots.media_path, recent_roots.media_type,
					recent_roots.created_at, recent_roots.username, recent_roots.display_name,
					recent_roots.profile_image_path, 0 AS depth
				FROM recent_roots
				UNION ALL
				SELECT replies.comment_id, replies.post_id, replies.parent_comment_id,
					replies.user_id, replies.content, replies.media_path, replies.media_type,
					replies.created_at, reply_users.username, reply_users.display_name,
					reply_users.profile_image_path, comment_tree.depth + 1
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
	$error = 'The community feed is temporarily unavailable. Check that MySQL is running, the database settings are correct, and the latest tables and migrations in database.sql have been applied.';
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
	<script src="image_viewer.js" defer></script>
	<script src="back_button.js" defer></script>
	<script src="upload_preview.js" defer></script>
</head>
<body>
	<nav class="topnav<?= $postViewRequested ? ' post-view-nav' : '' ?>" aria-label="Main navigation">
		<a class="active" aria-current="page" href="home.php">Home</a>
		<a href="matches.php">Match Center</a>
		<a href="community.html">Community</a>
		<a href="polls.php">Polls</a>
		<a href="predictions.php">Predictions</a>
		<?= notifications_nav_link() ?>
		<?php if (can_access_moderation()): ?>
			<a href="moderation.php">Moderation</a>
		<?php endif; ?>
		<?= account_nav_link() ?>
	</nav>
	<main class="home-page">
		<?php if (!$postViewRequested): ?>
			<header class="home-heading">
				<p class="home-eyebrow">A community for the beautiful game</p>
				<?php if ($profileUser === null): ?>
					<h1>Women's Football Community</h1>
				<?php endif; ?>
				<?php if ($profileUser !== null): ?>
					<div class="public-profile">
						<?php if (is_string($profileUser['profile_image_path']) && $profileUser['profile_image_path'] !== ''): ?>
							<img class="profile-avatar" src="<?= escape_html((string) $profileUser['profile_image_path']) ?>" alt="<?= escape_html((string) ($profileUser['display_name'] ?: $profileUser['username'])) ?>'s profile picture">
						<?php else: ?>
							<div class="profile-avatar profile-avatar-placeholder" aria-hidden="true"><?= escape_html(strtoupper(substr((string) $profileUser['username'], 0, 1))) ?></div>
						<?php endif; ?>
						<div class="public-profile-details">
							<?php if (is_string($profileUser['display_name']) && $profileUser['display_name'] !== ''): ?>
								<h2><?= escape_html((string) $profileUser['display_name']) ?></h2>
							<?php endif; ?>
							<p class="profile-username">@<?= escape_html((string) $profileUser['username']) ?></p>
							<?php if (is_string($profileUser['bio']) && $profileUser['bio'] !== ''): ?>
								<p class="profile-bio"><?= nl2br(escape_html((string) $profileUser['bio'])) ?></p>
							<?php endif; ?>
							<div class="profile-facts">
								<?php if (is_string($profileUser['favorite_team']) && $profileUser['favorite_team'] !== ''): ?>
									<p><strong>Favorite team</strong> <?= escape_html((string) $profileUser['favorite_team']) ?></p>
								<?php endif; ?>
								<?php if (is_string($profileUser['location']) && $profileUser['location'] !== ''): ?>
									<p><strong>Location</strong> <?= escape_html((string) $profileUser['location']) ?></p>
								<?php endif; ?>
							</div>
						</div>
					</div>
					<nav class="profile-activity-menu" aria-label="View profile activity">
						<a class="<?= $profileActivity === 'posts' ? 'active' : '' ?>"<?= $profileActivity === 'posts' ? ' aria-current="page"' : '' ?> href="home.php?user_id=<?= (int) $profileUser['user_id'] ?>&amp;activity=posts">Posts</a>
						<a class="<?= $profileActivity === 'reposts' ? 'active' : '' ?>"<?= $profileActivity === 'reposts' ? ' aria-current="page"' : '' ?> href="home.php?user_id=<?= (int) $profileUser['user_id'] ?>&amp;activity=reposts">Reposts</a>
						<a class="<?= $profileActivity === 'comments' ? 'active' : '' ?>"<?= $profileActivity === 'comments' ? ' aria-current="page"' : '' ?> href="home.php?user_id=<?= (int) $profileUser['user_id'] ?>&amp;activity=comments">Comments</a>
					</nav>
					<?php if ($isSignedIn && $profileUserId === (int) $_SESSION['user_id']): ?>
						<p><a href="profile.php?edit=1">Edit your profile</a></p>
					<?php endif; ?>
				<?php else: ?>
				<p>Share the moments, matches, and conversations you love with fans everywhere.</p>
				<?php endif; ?>
			</header>
		<?php endif; ?>

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

		<section class="home-feed" <?= $postViewRequested ? 'aria-label="Post and comments"' : 'aria-labelledby="feed-heading"' ?>>
			<?php if (!$postViewRequested): ?>
				<div class="home-feed-heading">
					<div>
						<p class="home-eyebrow">From the community</p>
						<h2 id="feed-heading"><?= $profileUser !== null ? ucfirst($profileActivity) : ($profileViewRequested ? 'Account posts' : 'Latest posts') ?></h2>
					</div>
				</div>
			<?php endif; ?>
			<?php if ($profileUser !== null && $profileActivity === 'comments'): ?>
				<?php if ($profileComments === []): ?>
					<p class="empty-feed">@<?= escape_html((string) $profileUser['username']) ?> hasn't commented on any posts yet.</p>
				<?php else: ?>
					<div class="profile-comment-list">
						<?php foreach ($profileComments as $profileComment): ?>
							<?php
							$isReply = $profileComment['parent_comment_id'] !== null;
							$showReplyContext = $isReply && $profileComment['parent_user_id'] !== null;
							$contextText = $showReplyContext
								? $profileComment['parent_comment_preview']
								: $profileComment['post_content'];
							$contextTruncated = $showReplyContext
								? (bool) $profileComment['parent_comment_truncated']
								: (bool) $profileComment['post_content_truncated'];
							$contextMediaType = $showReplyContext
								? $profileComment['parent_comment_media_type']
								: $profileComment['post_media_type'];
							$contextUserId = $showReplyContext
								? $profileComment['parent_user_id']
								: $profileComment['post_author_user_id'];
							$contextUsername = $showReplyContext
								? $profileComment['parent_username']
								: $profileComment['post_author_username'];
							$contextDisplayName = $showReplyContext
								? $profileComment['parent_display_name']
								: null;
							$contextLabel = $showReplyContext ? 'Replying to' : 'Comment on post by';
							$commentUrl = 'home.php?post_id=' . (int) $profileComment['post_id']
								. '#comment-' . (int) $profileComment['comment_id'];
							?>
							<article class="profile-comment-card is-openable"
								tabindex="0"
								aria-label="Open comment by @<?= escape_html((string) $profileUser['username']) ?> on a post by @<?= escape_html((string) $profileComment['post_author_username']) ?>"
								data-post-url="<?= escape_html($commentUrl) ?>">
								<section class="profile-comment-context" aria-label="<?= escape_html($contextLabel) ?>">
									<p class="profile-comment-context-label"><?= escape_html($contextLabel) ?>
										<?php if ($showReplyContext && is_string($contextDisplayName) && $contextDisplayName !== ''): ?>
											<strong><?= escape_html($contextDisplayName) ?></strong>
										<?php endif; ?>
										<?php if ($contextUserId !== null && is_string($contextUsername)): ?>
											<a href="home.php?user_id=<?= (int) $contextUserId ?>">@<?= escape_html($contextUsername) ?></a>
										<?php endif; ?>
									</p>
									<?php if (is_string($contextText) && $contextText !== ''): ?>
										<p class="profile-comment-context-text"><?= nl2br(escape_html((string) $contextText)) ?><?= $contextTruncated ? '…' : '' ?></p>
									<?php elseif (is_string($contextMediaType)): ?>
										<p class="profile-comment-context-text"><?= $contextMediaType === 'video' ? 'Shared a video' : 'Shared a photo' ?></p>
									<?php else: ?>
										<p class="profile-comment-context-text">Original post</p>
									<?php endif; ?>
								</section>
								<div class="profile-comment-own">
									<p class="profile-comment-meta">
										<span>Comment by @<?= escape_html((string) $profileUser['username']) ?></span>
										<time datetime="<?= escape_html((string) $profileComment['created_at']) ?>"><?= escape_html(date('M j, Y \a\t g:i a', strtotime((string) $profileComment['created_at']))) ?></time>
									</p>
									<?php if (is_string($profileComment['content']) && $profileComment['content'] !== ''): ?>
										<p class="profile-comment-content"><?= nl2br(escape_html((string) $profileComment['content'])) ?></p>
									<?php endif; ?>
									<?php if (is_string($profileComment['media_path']) && $profileComment['media_path'] !== ''): ?>
										<p class="profile-comment-attachment"><?= $profileComment['media_type'] === 'video' ? 'Shared a video' : 'Shared a photo' ?></p>
									<?php endif; ?>
									<a class="profile-comment-open-link" href="<?= escape_html($commentUrl) ?>">Open comment</a>
								</div>
							</article>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			<?php elseif ($error === '' && $posts === [] && (!$profileViewRequested || $profileUser !== null)): ?>
				<p class="empty-feed"><?php if ($profileUser !== null): ?>@<?= escape_html((string) $profileUser['username']) ?> <?= $profileActivity === 'reposts' ? 'hasn\'t reposted anything yet.' : 'hasn\'t shared any posts yet.' ?><?php else: ?>No posts yet. <?= $isSignedIn ? 'Be the first to start the conversation.' : 'Check back soon for community updates.' ?><?php endif; ?></p>
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
								<p class="post-repost-attribution">
									<a class="post-author-avatar-link" href="home.php?user_id=<?= (int) $post['reposter_user_id'] ?>" aria-label="View @<?= escape_html((string) $post['reposter_username']) ?>'s profile">
										<?php if (is_string($post['reposter_profile_image_path']) && $post['reposter_profile_image_path'] !== ''): ?>
											<img class="post-author-avatar" src="<?= escape_html((string) $post['reposter_profile_image_path']) ?>" alt="" loading="lazy">
										<?php else: ?>
											<span class="post-author-avatar post-author-avatar-placeholder" aria-hidden="true"><?= escape_html(strtoupper(substr((string) $post['reposter_username'], 0, 1))) ?></span>
										<?php endif; ?>
									</a>
									<span class="post-repost-byline">
										<?php if (is_string($post['reposter_display_name']) && $post['reposter_display_name'] !== ''): ?>
											<span class="post-author-display-name"><?= escape_html((string) $post['reposter_display_name']) ?></span>
										<?php endif; ?>
										<a class="profile-link" href="home.php?user_id=<?= (int) $post['reposter_user_id'] ?>">@<?= escape_html((string) $post['reposter_username']) ?></a>
										<span>reposted</span>
									</span>
								</p>
							<?php endif; ?>
							<header class="post-author">
								<div class="post-author-identity">
									<a class="post-author-avatar-link" href="home.php?user_id=<?= (int) $post['user_id'] ?>" aria-label="View @<?= escape_html((string) $post['username']) ?>'s profile">
										<?php if (is_string($post['profile_image_path']) && $post['profile_image_path'] !== ''): ?>
											<img class="post-author-avatar" src="<?= escape_html((string) $post['profile_image_path']) ?>" alt="" loading="lazy">
										<?php else: ?>
											<span class="post-author-avatar post-author-avatar-placeholder" aria-hidden="true"><?= escape_html(strtoupper(substr((string) $post['username'], 0, 1))) ?></span>
										<?php endif; ?>
									</a>
									<span class="post-author-names">
										<?php if (is_string($post['display_name']) && $post['display_name'] !== ''): ?>
											<span class="post-author-display-name"><?= escape_html((string) $post['display_name']) ?></span>
										<?php endif; ?>
										<a class="profile-link" href="home.php?user_id=<?= (int) $post['user_id'] ?>">@<?= escape_html((string) $post['username']) ?></a>
									</span>
								</div>
								<time datetime="<?= escape_html((string) $post['feed_created_at']) ?>"><?= escape_html(date('M j, Y \a\t g:i a', strtotime((string) $post['feed_created_at']))) ?></time>
							</header>
							<div class="post-open-content">
								<?php if (is_string($post['content']) && $post['content'] !== ''): ?>
									<p class="post-content"><?= nl2br(escape_html((string) $post['content'])) ?></p>
								<?php endif; ?>
								<?php if ($post['media_type'] === 'image' && is_string($post['media_path'])): ?>
									<button class="post-image-button" type="button" aria-label="Enlarge photo shared by @<?= escape_html((string) $post['username']) ?>">
										<img class="post-media" src="<?= escape_html((string) $post['media_path']) ?>" alt="Photo shared by <?= escape_html((string) $post['username']) ?>" loading="lazy">
									</button>
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
	<script>
		document.querySelectorAll('.post-card.is-openable, .profile-comment-card.is-openable, .comment-card.is-openable').forEach((openableCard) => {
			const openCard = () => {
				window.location.href = openableCard.dataset.postUrl;
			};

			openableCard.addEventListener('click', (event) => {
				if (event.target.closest('a, button, input, textarea, select, video, audio, form, [contenteditable="true"]')) {
					return;
				}
				if (openableCard.matches('.comment-card') && event.target.closest('.comment-card.is-openable') !== openableCard) {
					return;
				}
				openCard();
			});

			openableCard.addEventListener('keydown', (event) => {
				if (event.target === openableCard && (event.key === 'Enter' || event.key === ' ')) {
					event.preventDefault();
					openCard();
				}
			});
		});
	</script>
</body>
</html>
