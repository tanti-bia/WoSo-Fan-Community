<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/post_media.php';
require_once __DIR__ . '/profile_media.php';
require_signed_in('community.php');

$userId = (int) $_SESSION['user_id'];
$error = '';
$notice = isset($_SESSION['community_notice']) && is_string($_SESSION['community_notice'])
	? $_SESSION['community_notice']
	: '';
unset($_SESSION['community_notice']);
$users = [];
$conversations = [];
$messages = [];
$activeParticipants = [];
$communityNotifications = [];
$unreadCommunityCount = 0;
$activeConversation = null;
$selectedChatId = 0;
$status = 200;
$pendingGroupImage = null;
$pendingChatMedia = null;

try {
	$database = database_connection();
	$database->exec(
		'CREATE TABLE IF NOT EXISTS chats
		(
			chat_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			chat_type ENUM(\'direct\', \'group\') NOT NULL,
			title VARCHAR(100) NULL,
			group_image_path VARCHAR(255) NULL,
			direct_key CHAR(64) NULL,
			created_by INT UNSIGNED NOT NULL,
			created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (chat_id),
			UNIQUE KEY chats_direct_key_unique (direct_key),
			CONSTRAINT chats_creator_fk FOREIGN KEY (created_by) REFERENCES users (user_id) ON DELETE CASCADE
		) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
	);
	$database->exec(
		'CREATE TABLE IF NOT EXISTS chat_members
		(
			chat_id BIGINT UNSIGNED NOT NULL,
			user_id INT UNSIGNED NOT NULL,
			joined_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (chat_id, user_id),
			KEY chat_members_user (user_id, chat_id),
			CONSTRAINT chat_members_chat_fk FOREIGN KEY (chat_id) REFERENCES chats (chat_id) ON DELETE CASCADE,
			CONSTRAINT chat_members_user_fk FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE CASCADE
		) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
	);
	$database->exec(
		'CREATE TABLE IF NOT EXISTS chat_messages
		(
			message_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			chat_id BIGINT UNSIGNED NOT NULL,
			sender_id INT UNSIGNED NOT NULL,
			body VARCHAR(2000) NOT NULL,
			media_path VARCHAR(255) NULL,
			media_type ENUM(\'image\', \'video\') NULL,
			created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (message_id),
			KEY chat_messages_history (chat_id, message_id),
			CONSTRAINT chat_messages_chat_fk FOREIGN KEY (chat_id) REFERENCES chats (chat_id) ON DELETE CASCADE,
			CONSTRAINT chat_messages_sender_fk FOREIGN KEY (sender_id) REFERENCES users (user_id) ON DELETE CASCADE
		) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
	);
	$database->exec(
		'CREATE TABLE IF NOT EXISTS community_notifications
		(
			community_notification_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			recipient_user_id INT UNSIGNED NOT NULL,
			actor_user_id INT UNSIGNED NOT NULL,
			chat_id BIGINT UNSIGNED NOT NULL,
			message_id BIGINT UNSIGNED NULL,
			notification_type ENUM(\'added_to_group\', \'message\') NOT NULL,
			read_at TIMESTAMP NULL,
			created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (community_notification_id),
			KEY community_notifications_inbox (recipient_user_id, read_at, created_at, community_notification_id),
			CONSTRAINT community_notifications_recipient_fk FOREIGN KEY (recipient_user_id) REFERENCES users (user_id) ON DELETE CASCADE,
			CONSTRAINT community_notifications_actor_fk FOREIGN KEY (actor_user_id) REFERENCES users (user_id) ON DELETE CASCADE,
			CONSTRAINT community_notifications_chat_fk FOREIGN KEY (chat_id) REFERENCES chats (chat_id) ON DELETE CASCADE,
			CONSTRAINT community_notifications_message_fk FOREIGN KEY (message_id) REFERENCES chat_messages (message_id) ON DELETE CASCADE
		) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
	);

	if ($_SERVER['REQUEST_METHOD'] === 'POST') {
		if (!has_valid_csrf_token()) {
			$error = 'Your session expired. Refresh the page and try again.';
			$status = 400;
		} else {
			$action = post_string('action');
			if ($action === 'start_direct') {
				$otherUsername = trim(post_string('other_username'));
				if ($otherUsername === '' || strlen($otherUsername) > 30) {
					$error = 'Enter the username of the member you want to chat with.';
				} else {
					$userCheck = $database->prepare('SELECT user_id FROM users WHERE username = :username LIMIT 1');
					$userCheck->execute(['username' => $otherUsername]);
					$otherUserId = (int) $userCheck->fetchColumn();
					if ($otherUserId < 1) {
						$error = 'No member was found with that username. Check the spelling and try again.';
					} elseif ($otherUserId === $userId) {
						$error = 'Enter another member’s username to start a chat.';
					} else {
						$directKey = hash('sha256', min($userId, $otherUserId) . ':' . max($userId, $otherUserId));
						$database->beginTransaction();
						$createDirect = $database->prepare(
							'INSERT INTO chats (chat_type, direct_key, created_by)
							VALUES (\'direct\', :direct_key, :created_by)
							ON DUPLICATE KEY UPDATE chat_id = LAST_INSERT_ID(chat_id)'
						);
						$createDirect->execute(['direct_key' => $directKey, 'created_by' => $userId]);
						$chatId = (int) $database->lastInsertId();
						$addMember = $database->prepare('INSERT IGNORE INTO chat_members (chat_id, user_id) VALUES (:chat_id, :user_id)');
						$addMember->execute(['chat_id' => $chatId, 'user_id' => $userId]);
						$addMember->execute(['chat_id' => $chatId, 'user_id' => $otherUserId]);
						$database->commit();
						$_SESSION['community_notice'] = 'Your direct chat is ready.';
						header('Location: community.php?chat_id=' . $chatId);
						exit;
					}
				}
			} elseif ($action === 'create_group') {
				$title = trim(post_string('group_title'));
				$memberInputs = isset($_POST['member_ids']) && is_array($_POST['member_ids']) ? $_POST['member_ids'] : [];
				$memberIds = [];
				foreach ($memberInputs as $memberInput) {
					if (is_string($memberInput) && ctype_digit($memberInput)) {
						$memberId = filter_var($memberInput, FILTER_VALIDATE_INT);
						if (is_int($memberId) && $memberId > 0 && $memberId !== $userId) {
							$memberIds[] = $memberId;
						}
					}
				}
				$memberIds = array_values(array_unique($memberIds));
				if ($title === '' || strlen($title) > 100) {
					$error = 'Enter a group name of up to 100 characters.';
				} elseif ($memberIds === [] || count($memberIds) > 30) {
					$error = 'Choose between 1 and 30 other members for the group.';
				} else {
					$placeholders = implode(',', array_fill(0, count($memberIds), '?'));
					$memberCheck = $database->prepare('SELECT user_id FROM users WHERE user_id IN (' . $placeholders . ')');
					$memberCheck->execute($memberIds);
					$validMemberIds = array_map('intval', $memberCheck->fetchAll(PDO::FETCH_COLUMN));
					if (count($validMemberIds) !== count($memberIds)) {
						$error = 'One or more selected members could not be found. Please try again.';
					} else {
						$database->beginTransaction();
						$createGroup = $database->prepare('INSERT INTO chats (chat_type, title, created_by) VALUES (\'group\', :title, :created_by)');
						$createGroup->execute(['title' => $title, 'created_by' => $userId]);
						$chatId = (int) $database->lastInsertId();
						$addMember = $database->prepare('INSERT INTO chat_members (chat_id, user_id) VALUES (:chat_id, :user_id)');
						foreach (array_merge([$userId], $validMemberIds) as $memberId) {
							$addMember->execute(['chat_id' => $chatId, 'user_id' => $memberId]);
						}
						$notifyAdded = $database->prepare(
							'INSERT INTO community_notifications
								(recipient_user_id, actor_user_id, chat_id, notification_type)
							 VALUES (:recipient_user_id, :actor_user_id, :chat_id, \'added_to_group\')'
						);
						foreach ($validMemberIds as $memberId) {
							$notifyAdded->execute([
								'recipient_user_id' => $memberId,
								'actor_user_id' => $userId,
								'chat_id' => $chatId,
							]);
						}
						$database->commit();
						$_SESSION['community_notice'] = 'Your group chat has been created.';
						header('Location: community.php?chat_id=' . $chatId);
						exit;
					}
				}
			} elseif ($action === 'update_group_details') {
				$chatIdRaw = post_string('chat_id');
				$chatId = ctype_digit($chatIdRaw) ? filter_var($chatIdRaw, FILTER_VALIDATE_INT) : false;
				$title = trim(post_string('group_title'));
				$removeGroupImage = post_string('remove_group_image') === '1';
				if (!is_int($chatId) || $chatId < 1) {
					$error = 'Choose a valid group chat.';
					$status = 400;
				} elseif ($title === '' || strlen($title) > 100) {
					$error = 'Enter a group name of up to 100 characters.';
					$status = 400;
				} else {
					$groupCheck = $database->prepare(
						'SELECT chats.chat_id, chats.group_image_path FROM chats
						 INNER JOIN chat_members ON chat_members.chat_id = chats.chat_id
						 WHERE chats.chat_id = :chat_id AND chat_members.user_id = :user_id
						 AND chats.chat_type = \'group\' LIMIT 1'
					);
					$groupCheck->execute(['chat_id' => $chatId, 'user_id' => $userId]);
					$groupRecord = $groupCheck->fetch(PDO::FETCH_ASSOC);
					if ($groupRecord === false) {
						$error = 'You do not have access to edit that group.';
						$status = 403;
					} else {
						$oldGroupImagePath = $groupRecord['group_image_path'];
						$pendingGroupImage = store_group_image($error, $status);
						if ($error === '') {
							$newGroupImagePath = $pendingGroupImage['path'] ?? ($removeGroupImage ? null : $oldGroupImagePath);
							$updateGroup = $database->prepare(
								'UPDATE chats SET title = :title, group_image_path = :group_image_path
								 WHERE chat_id = :chat_id AND chat_type = \'group\''
							);
							$updateGroup->execute([
								'title' => $title,
								'group_image_path' => $newGroupImagePath,
								'chat_id' => $chatId,
							]);
							if ($pendingGroupImage !== null || $removeGroupImage) {
								if (is_string($oldGroupImagePath)
									&& preg_match('~^uploads/groups/[a-f0-9]{32}\.(?:jpg|png|webp)$~D', $oldGroupImagePath)) {
									$oldAbsolutePath = __DIR__ . '/' . $oldGroupImagePath;
									if (is_file($oldAbsolutePath) && !unlink($oldAbsolutePath)) {
										error_log('WoSo group image cleanup error: the previous image could not be removed.');
									}
								}
							}
							$pendingGroupImage = null;
							$_SESSION['community_notice'] = 'Group details updated.';
							header('Location: community.php?chat_id=' . $chatId);
							exit;
						}
					}
				}
			} elseif ($action === 'send_message') {
				$chatIdRaw = post_string('chat_id');
				$chatId = ctype_digit($chatIdRaw) ? filter_var($chatIdRaw, FILTER_VALIDATE_INT) : false;
				$body = trim(post_string('body'));
				if (!is_int($chatId) || $chatId < 1) {
					$error = 'Choose a chat before sending a message.';
				} elseif (strlen($body) > 2000) {
					$error = 'Messages can contain up to 2,000 characters.';
				} else {
					$membership = $database->prepare('SELECT 1 FROM chat_members WHERE chat_id = :chat_id AND user_id = :user_id LIMIT 1');
					$membership->execute(['chat_id' => $chatId, 'user_id' => $userId]);
					if ($membership->fetchColumn() === false) {
						$error = 'You do not have access to that chat.';
					} else {
						$pendingChatMedia = store_post_media('chat_media', $error, $status, 'chats');
						if ($error === '' && $body === '' && $pendingChatMedia === null) {
							$error = 'Write a message or attach a photo or video before sending.';
							$status = 400;
						}
						if ($error === '') {
							$database->beginTransaction();
							$send = $database->prepare(
								'INSERT INTO chat_messages (chat_id, sender_id, body, media_path, media_type)
								 VALUES (:chat_id, :sender_id, :body, :media_path, :media_type)'
							);
							$send->execute([
								'chat_id' => $chatId,
								'sender_id' => $userId,
								'body' => $body,
								'media_path' => $pendingChatMedia['path'] ?? null,
								'media_type' => $pendingChatMedia['type'] ?? null,
							]);
							$messageId = (int) $database->lastInsertId();
							$notifyMembers = $database->prepare(
								'INSERT INTO community_notifications
									(recipient_user_id, actor_user_id, chat_id, message_id, notification_type)
								 SELECT user_id, :actor_user_id, :notification_chat_id, :message_id, \'message\'
								 FROM chat_members
								 WHERE chat_id = :members_chat_id AND user_id <> :sender_user_id'
							);
							$notifyMembers->execute([
								'actor_user_id' => $userId,
								'notification_chat_id' => $chatId,
								'message_id' => $messageId,
								'members_chat_id' => $chatId,
								'sender_user_id' => $userId,
							]);
							$database->commit();
							$pendingChatMedia = null;
							$_SESSION['community_notice'] = 'Message sent.';
							header('Location: community.php?chat_id=' . $chatId);
							exit;
						}
					}
				}
			} else {
				$error = 'Choose a valid community action.';
				$status = 400;
			}
		}
	}

	$usersQuery = $database->prepare('SELECT user_id, username FROM users WHERE user_id <> :user_id ORDER BY username');
	$usersQuery->execute(['user_id' => $userId]);
	$users = $usersQuery->fetchAll();

	$conversationsQuery = $database->prepare(
		' SELECT chats.chat_id, chats.chat_type, chats.title, chats.group_image_path, chats.created_at,
			(SELECT GROUP_CONCAT(member_user.username ORDER BY member_user.username SEPARATOR \', \')
			 FROM chat_members AS participants
			 INNER JOIN users AS member_user ON member_user.user_id = participants.user_id
			 WHERE participants.chat_id = chats.chat_id AND participants.user_id <> :participant_user_id) AS participant_names,
			(SELECT CASE
				 WHEN chat_messages.body <> \'\' THEN chat_messages.body
				 WHEN chat_messages.media_type = \'image\' THEN \'Photo\'
				 WHEN chat_messages.media_type = \'video\' THEN \'Video\'
				 ELSE \'\' END
			 FROM chat_messages WHERE chat_messages.chat_id = chats.chat_id
			 ORDER BY chat_messages.message_id DESC LIMIT 1) AS last_message,
			(SELECT chat_messages.created_at FROM chat_messages WHERE chat_messages.chat_id = chats.chat_id
			 ORDER BY chat_messages.message_id DESC LIMIT 1) AS last_message_at
		 FROM chats
		 INNER JOIN chat_members AS my_membership ON my_membership.chat_id = chats.chat_id
		 WHERE my_membership.user_id = :current_user_id
		 ORDER BY COALESCE(
			(SELECT chat_messages.created_at FROM chat_messages WHERE chat_messages.chat_id = chats.chat_id
			 ORDER BY chat_messages.message_id DESC LIMIT 1), chats.created_at
		 ) DESC, chats.chat_id DESC'
	);
	$conversationsQuery->execute(['participant_user_id' => $userId, 'current_user_id' => $userId]);
	$conversations = $conversationsQuery->fetchAll();

	$chatIdInput = isset($_GET['chat_id']) && is_string($_GET['chat_id']) ? $_GET['chat_id'] : '';
	$selectedChatId = ctype_digit($chatIdInput) ? (int) $chatIdInput : 0;
	if ($selectedChatId > 0) {
		foreach ($conversations as $conversation) {
			if ((int) $conversation['chat_id'] === $selectedChatId) {
				$activeConversation = $conversation;
				break;
			}
		}
		if ($activeConversation === null) {
			$error = 'That conversation could not be found or you are not a member.';
			$status = 404;
			$selectedChatId = 0;
		} else {
			$markNotificationsRead = $database->prepare(
				'UPDATE community_notifications SET read_at = CURRENT_TIMESTAMP
				 WHERE recipient_user_id = :recipient_user_id AND chat_id = :chat_id AND read_at IS NULL'
			);
			$markNotificationsRead->execute(['recipient_user_id' => $userId, 'chat_id' => $selectedChatId]);
			$participantsQuery = $database->prepare(
				' SELECT users.user_id, users.username, users.display_name, users.profile_image_path
				 FROM chat_members
				 INNER JOIN users ON users.user_id = chat_members.user_id
				 WHERE chat_members.chat_id = :chat_id AND chat_members.user_id <> :current_user_id
				 ORDER BY COALESCE(NULLIF(users.display_name, \'\'), users.username), users.username'
			);
			$participantsQuery->execute(['chat_id' => $selectedChatId, 'current_user_id' => $userId]);
			$activeParticipants = $participantsQuery->fetchAll();
			$messageQuery = $database->prepare(
				'SELECT chat_messages.message_id, chat_messages.sender_id, chat_messages.body,
					chat_messages.media_path, chat_messages.media_type,
					chat_messages.created_at, users.username, users.display_name, users.profile_image_path
				FROM chat_messages
			INNER JOIN users ON users.user_id = chat_messages.sender_id
			WHERE chat_messages.chat_id = :chat_id
			ORDER BY chat_messages.message_id ASC'
			);
			$messageQuery->execute(['chat_id' => $selectedChatId]);
			$messages = $messageQuery->fetchAll();
		}
	}

	$unreadCountQuery = $database->prepare(
		' SELECT COUNT(*) FROM community_notifications
		 WHERE recipient_user_id = :recipient_user_id AND read_at IS NULL'
	);
	$unreadCountQuery->execute(['recipient_user_id' => $userId]);
	$unreadCommunityCount = (int) $unreadCountQuery->fetchColumn();
	$notificationsQuery = $database->prepare(
		' SELECT community_notifications.community_notification_id,
			community_notifications.notification_type, community_notifications.chat_id,
			community_notifications.read_at, community_notifications.created_at,
			actors.username AS actor_username, chats.chat_type, chats.title
		 FROM community_notifications
		 INNER JOIN users AS actors ON actors.user_id = community_notifications.actor_user_id
		 INNER JOIN chats ON chats.chat_id = community_notifications.chat_id
		 WHERE community_notifications.recipient_user_id = :recipient_user_id
		 ORDER BY community_notifications.created_at DESC, community_notifications.community_notification_id DESC
		 LIMIT 30'
	);
	$notificationsQuery->execute(['recipient_user_id' => $userId]);
	$communityNotifications = $notificationsQuery->fetchAll();
} catch (PDOException $exception) {
	if (isset($database) && $database->inTransaction()) {
		$database->rollBack();
	}
	if ($pendingGroupImage !== null && is_file($pendingGroupImage['absolute_path'])) {
		if (!unlink($pendingGroupImage['absolute_path'])) {
			error_log('WoSo group image cleanup error: an unsaved image could not be removed.');
		}
	}
	if ($pendingChatMedia !== null && is_file($pendingChatMedia['absolute_path'])) {
		if (!unlink($pendingChatMedia['absolute_path'])) {
			error_log('WoSo chat media cleanup error: an unsaved attachment could not be removed.');
		}
	}
	error_log('WoSo community chat database error: ' . $exception->getMessage());
	$error = 'Community chats are temporarily unavailable. Check the chat tables in your database setup.';
	$status = 503;
}

http_response_code($status);
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Women's Football - Community</title>
	<link rel="stylesheet" href="styles.css?v=<?= (string) filemtime(__DIR__ . '/styles.css') ?>">
	<script src="back_button.js" defer></script>
	<script src="upload_preview.js" defer></script>
	<script src="community_chat.js" defer></script>
</head>
<body>
	<nav class="topnav" aria-label="Main navigation">
		<a href="home.php">Home</a>
		<a href="matches.php">Match Center</a>
		<?= community_nav_link(true) ?>
		<a href="polls.php">Polls</a>
		<a href="predictions.php">Predictions</a>
		<?= notifications_nav_link() ?>
		<?= moderation_nav_link() ?>
		<?= account_nav_link() ?>
	</nav>

	<main class="community-page">
		<header class="home-heading community-heading">
			<p class="home-eyebrow">Talk football together</p>
			<h1>Community chats</h1>
			<p>Start a private conversation with another member or bring everyone into a group chat.</p>
		</header>
		<?php if ($notice !== ''): ?>
			<p class="auth-message auth-success" role="status"><?= escape_html($notice) ?></p>
		<?php endif; ?>
		<?php if ($error !== ''): ?>
			<p class="auth-message auth-error" role="alert"><?= escape_html($error) ?></p>
		<?php endif; ?>
		<section class="community-notifications" aria-labelledby="community-notifications-heading">
			<div class="community-notifications-heading">
				<h2 id="community-notifications-heading">Community notifications</h2>
				<?php if ($unreadCommunityCount > 0): ?>
					<span class="community-unread-count"><?= $unreadCommunityCount ?> unread</span>
				<?php endif; ?>
			</div>
			<?php if ($communityNotifications === []): ?>
				<p class="community-empty">You have no community notifications.</p>
			<?php else: ?>
				<ul class="community-notification-list">
					<?php foreach ($communityNotifications as $communityNotification): ?>
						<?php
						$isUnread = $communityNotification['read_at'] === null;
						if ($communityNotification['notification_type'] === 'added_to_group') {
			$notificationText = (string) $communityNotification['actor_username'] . ' added you to the group "' . (string) $communityNotification['title'] . '".';
						} else {
							$conversationName = $communityNotification['chat_type'] === 'group'
				? '"' . (string) $communityNotification['title'] . '"'
								: 'your direct conversation';
							$notificationText = (string) $communityNotification['actor_username'] . ' sent a message in ' . $conversationName . '.';
						}
						?>
						<li>
							<a class="community-notification-link<?= $isUnread ? ' is-unread' : '' ?>" href="community.php?chat_id=<?= (int) $communityNotification['chat_id'] ?>">
								<span><?= escape_html($notificationText) ?></span>
								<time datetime="<?= escape_html((string) $communityNotification['created_at']) ?>"><?= escape_html(date('M j, Y g:i a', strtotime((string) $communityNotification['created_at']))) ?></time>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</section>

		<div class="community-chat-layout">
			<aside class="community-chat-sidebar" aria-label="Chat list and creation">
				<section class="community-chat-create" aria-labelledby="direct-chat-heading">
					<h2 id="direct-chat-heading">Start a chat</h2>
					<form method="post" action="community.php">
						<input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
						<input type="hidden" name="action" value="start_direct">
						<label for="direct-username">Search by username</label>
						<div class="community-user-search">
							<input id="direct-username" name="other_username" type="search" maxlength="30" autocomplete="off" autocapitalize="none" spellcheck="false" placeholder="Type their username" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="direct-user-suggestions" required>
							<div id="direct-user-suggestions" class="community-user-suggestions" role="listbox" aria-label="Matching usernames" hidden>
								<?php foreach ($users as $user): ?>
									<button type="button" class="community-user-suggestion" role="option" data-username="<?= escape_html((string) $user['username']) ?>" hidden>@<?= escape_html((string) $user['username']) ?></button>
								<?php endforeach; ?>
							</div>
						</div>
						<button class="auth-button" type="submit">Open direct chat</button>
					</form>
				</section>

				<details class="community-chat-create community-group-create">
					<summary>Create a group chat</summary>
					<form method="post" action="community.php">
						<input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
						<input type="hidden" name="action" value="create_group">
						<label for="group-title">Group name</label>
						<input id="group-title" name="group_title" type="text" maxlength="100" required>
						<label for="group-member-search">Search members by username</label>
						<div class="community-user-search community-group-member-picker">
							<div id="group-selected-members" class="community-selected-members" aria-live="polite"></div>
							<input id="group-member-search" type="search" maxlength="30" autocomplete="off" autocapitalize="none" spellcheck="false" placeholder="Type a username" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="group-member-suggestions">
							<div id="group-member-suggestions" class="community-user-suggestions" role="listbox" aria-label="Matching group members" hidden>
								<?php foreach ($users as $user): ?>
									<button type="button" class="community-user-suggestion" role="option" data-user-id="<?= (int) $user['user_id'] ?>" data-username="<?= escape_html((string) $user['username']) ?>" hidden>@<?= escape_html((string) $user['username']) ?></button>
								<?php endforeach; ?>
							</div>
						</div>
						<p class="community-form-help">Choose 1–30 members from the suggestions. You will be included automatically.</p>
						<button class="auth-button" type="submit">Create group</button>
					</form>
				</details>

				<section aria-labelledby="your-chats-heading">
					<h2 id="your-chats-heading">Your chats</h2>
					<?php if ($conversations === []): ?>
						<p class="community-empty">No chats yet. Start a direct chat or create a group.</p>
					<?php else: ?>
						<ul class="community-chat-list">
							<?php foreach ($conversations as $conversation): ?>
								<?php
								$isGroup = $conversation['chat_type'] === 'group';
								$chatTitle = $isGroup
									? (string) $conversation['title']
									: (string) ($conversation['participant_names'] ?? 'Direct chat');
								$chatPreview = trim((string) ($conversation['last_message'] ?? ''));
								?>
								<li>
									<a class="community-chat-link<?= (int) $conversation['chat_id'] === $selectedChatId ? ' is-active' : '' ?>" href="community.php?chat_id=<?= (int) $conversation['chat_id'] ?>">
										<?php if ($isGroup): ?>
											<span class="community-chat-list-avatar" aria-hidden="true">
												<?php if (is_string($conversation['group_image_path']) && $conversation['group_image_path'] !== ''): ?>
													<img src="<?= escape_html((string) $conversation['group_image_path']) ?>" alt="">
												<?php else: ?>
													<?= escape_html(strtoupper(substr($chatTitle, 0, 1))) ?>
												<?php endif; ?>
											</span>
										<?php endif; ?>
										<span class="community-chat-link-copy">
											<strong><?= escape_html($chatTitle) ?></strong>
											<span><?= escape_html($chatPreview !== '' ? $chatPreview : ($isGroup ? 'Group chat' : 'Direct chat')) ?></span>
										</span>
									</a>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</section>
			</aside>

			<section class="community-chat-thread" aria-label="Selected conversation">
				<?php if ($activeConversation === null): ?>
					<div class="community-thread-empty">
						<span class="community-empty-mark" aria-hidden="true">&#10022;</span>
						<h2>Your conversations</h2>
						<p>Select a conversation to catch up, or start a new one with a member.</p>
					</div>
				<?php else: ?>
					<?php
					$activeIsGroup = $activeConversation['chat_type'] === 'group';
					$directParticipant = $activeParticipants[0] ?? null;
					$activeTitle = $activeIsGroup
						? (string) $activeConversation['title']
						: (string) (($directParticipant['display_name'] ?? '') ?: ($directParticipant['username'] ?? 'Direct chat'));
					$activeGroupImagePath = $activeIsGroup && is_string($activeConversation['group_image_path'] ?? null)
						? (string) $activeConversation['group_image_path']
						: '';
					?>
					<div id="community-chat-content" class="community-thread-content" data-chat-content>
					<header class="community-thread-heading">
						<?php if ($activeIsGroup): ?>
							<div class="community-group-heading">
								<span class="community-group-avatar" aria-hidden="true">
									<?php if ($activeGroupImagePath !== ''): ?>
										<img src="<?= escape_html($activeGroupImagePath) ?>" alt="">
									<?php else: ?>
										<?= escape_html(strtoupper(substr($activeTitle, 0, 1))) ?>
									<?php endif; ?>
								</span>
								<div class="community-group-heading-copy">
									<p class="community-thread-type">Group conversation</p>
									<h2 class="community-group-title"><?= escape_html($activeTitle) ?></h2>
								</div>
							</div>
							<div class="community-participant-list" aria-label="Conversation members">
								<?php foreach ($activeParticipants as $participant): ?>
									<div class="post-author-identity community-participant-identity">
										<a class="post-author-avatar-link" href="home.php?user_id=<?= (int) $participant['user_id'] ?>" aria-label="View @<?= escape_html((string) $participant['username']) ?>'s profile">
											<?php if (is_string($participant['profile_image_path']) && $participant['profile_image_path'] !== ''): ?>
												<img class="post-author-avatar" src="<?= escape_html((string) $participant['profile_image_path']) ?>" alt="" loading="lazy">
											<?php else: ?>
												<span class="post-author-avatar post-author-avatar-placeholder" aria-hidden="true"><?= escape_html(strtoupper(substr((string) $participant['username'], 0, 1))) ?></span>
											<?php endif; ?>
										</a>
										<span class="post-author-names">
											<?php if (is_string($participant['display_name']) && $participant['display_name'] !== ''): ?>
												<span class="post-author-display-name"><?= escape_html((string) $participant['display_name']) ?></span>
											<?php endif; ?>
											<a class="profile-link" href="home.php?user_id=<?= (int) $participant['user_id'] ?>">@<?= escape_html((string) $participant['username']) ?></a>
										</span>
									</div>
								<?php endforeach; ?>
							</div>
							<details class="community-group-settings">
								<summary>Edit group details</summary>
								<form method="post" enctype="multipart/form-data" action="community.php?chat_id=<?= $selectedChatId ?>">
									<input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
									<input type="hidden" name="action" value="update_group_details">
									<input type="hidden" name="chat_id" value="<?= $selectedChatId ?>">
									<label for="group-edit-title">Group name</label>
									<input id="group-edit-title" name="group_title" type="text" maxlength="100" value="<?= escape_html($activeTitle) ?>" required>
									<label for="group-image">Group picture</label>
									<input id="group-image" name="group_image" type="file" accept="image/jpeg,image/png,image/webp">
									<p class="community-form-help">JPEG, PNG, or WebP; up to 5 MB.</p>
									<?php if ($activeGroupImagePath !== ''): ?>
										<label class="community-remove-group-image"><input type="checkbox" name="remove_group_image" value="1"> Remove current picture</label>
									<?php endif; ?>
									<button class="auth-button" type="submit">Save group details</button>
								</form>
							</details>
						<?php else: ?>
							<p class="community-thread-type">Private conversation</p>
							<div class="post-author-identity community-contact-identity">
								<a class="post-author-avatar-link" href="home.php?user_id=<?= (int) $directParticipant['user_id'] ?>" aria-label="View @<?= escape_html((string) $directParticipant['username']) ?>'s profile">
									<?php if (is_string($directParticipant['profile_image_path']) && $directParticipant['profile_image_path'] !== ''): ?>
										<img class="post-author-avatar" src="<?= escape_html((string) $directParticipant['profile_image_path']) ?>" alt="" loading="lazy">
									<?php else: ?>
										<span class="post-author-avatar post-author-avatar-placeholder" aria-hidden="true"><?= escape_html(strtoupper(substr((string) $directParticipant['username'], 0, 1))) ?></span>
									<?php endif; ?>
								</a>
								<span class="post-author-names">
									<?php if (is_string($directParticipant['display_name']) && $directParticipant['display_name'] !== ''): ?>
										<span class="post-author-display-name"><?= escape_html((string) $directParticipant['display_name']) ?></span>
									<?php endif; ?>
									<a class="profile-link" href="home.php?user_id=<?= (int) $directParticipant['user_id'] ?>">@<?= escape_html((string) $directParticipant['username']) ?></a>
								</span>
							</div>
						<?php endif; ?>
					</header>
					<div id="community-message-list" class="community-message-list" aria-live="polite" aria-label="Messages">
						<?php if ($messages === []): ?>
							<p class="community-empty">No messages yet. Say hello!</p>
						<?php else: ?>
							<?php foreach ($messages as $message): ?>
								<?php $isOwnMessage = (int) $message['sender_id'] === $userId; ?>
								<?php $messageDisplayName = (string) (($message['display_name'] ?? '') ?: $message['username']); ?>
								<article class="community-message<?= $isOwnMessage ? ' is-own-message' : '' ?>">
									<p class="community-message-meta"><strong><?= escape_html($isOwnMessage ? 'You' : $messageDisplayName) ?></strong><span>@<?= escape_html((string) $message['username']) ?></span><time datetime="<?= escape_html((string) $message['created_at']) ?>"><?= escape_html(date('M j, Y g:i a', strtotime((string) $message['created_at']))) ?></time></p>
									<?php if (is_string($message['media_path']) && $message['media_path'] !== ''): ?>
										<?php if ($message['media_type'] === 'image'): ?>
											<a class="community-message-media-link" href="<?= escape_html((string) $message['media_path']) ?>" target="_blank" rel="noopener noreferrer">
												<img class="community-message-media" src="<?= escape_html((string) $message['media_path']) ?>" alt="Photo shared by @<?= escape_html((string) $message['username']) ?>" loading="lazy">
											</a>
										<?php elseif ($message['media_type'] === 'video'): ?>
											<video class="community-message-media" src="<?= escape_html((string) $message['media_path']) ?>" controls preload="metadata">Your browser does not support video playback.</video>
										<?php endif; ?>
									<?php endif; ?>
									<?php if (trim((string) $message['body']) !== ''): ?>
										<p><?= nl2br(escape_html((string) $message['body'])) ?></p>
									<?php endif; ?>
								</article>
							<?php endforeach; ?>
						<?php endif; ?>
					</div>
					<form class="community-message-form" method="post" enctype="multipart/form-data" action="community.php?chat_id=<?= $selectedChatId ?>">
						<input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
						<input type="hidden" name="action" value="send_message">
						<input type="hidden" name="chat_id" value="<?= $selectedChatId ?>">
						<label class="community-visually-hidden" for="message-body">Message</label>
						<div class="community-composer-bubble">
							<textarea id="message-body" name="body" maxlength="2000" rows="1" placeholder="Write a message..."></textarea>
							<div class="community-composer-bubble-tools">
								<span id="chat-attachment-name" class="community-attachment-name" aria-live="polite"></span>
								<div class="community-composer-icon-stack">
									<button class="community-send-button" type="submit" aria-label="Send message" title="Send message">
										<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M3 11.5 21 3l-8.5 18-1.7-7.8L3 11.5Z"/><path d="m10.8 13.2 4.8-4.8"/></svg>
									</button>
									<input class="community-attachment-input" id="chat-media" name="chat_media" type="file" accept="image/jpeg,image/png,image/gif,image/webp,video/mp4,video/webm,video/quicktime,.mov" aria-label="Attach a photo or video" aria-describedby="chat-media-help">
									<label class="community-attachment-button" for="chat-media" title="Attach a photo or video">
										<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M8.5 12.5 14.7 6.3a3.2 3.2 0 0 1 4.5 4.5l-8.1 8.1a5 5 0 0 1-7.1-7.1l8.1-8.1"/><path d="m7.8 14.2 8.1-8.1"/></svg>
										<span class="community-visually-hidden">Attach a photo or video</span>
									</label>
								</div>
								<p id="chat-media-help" class="community-visually-hidden">JPEG, PNG, GIF, WebP, MP4, WebM, or MOV; up to 25 MB.</p>
							</div>
						</div>
						<div class="community-composer-footer">
							<span>Messages are shared with everyone in this conversation.</span>
						</div>
					</form>
					</div>
					<div class="community-thread-minimized" data-chat-minimized hidden>
						<span class="community-empty-mark" aria-hidden="true">&#10022;</span>
						<h2>Conversation minimized</h2>
						<p>Your messages are still here. Reopen this conversation whenever you’re ready.</p>
						<button class="auth-button" type="button" data-chat-reopen>Reopen conversation</button>
					</div>
				<?php endif; ?>
			</section>
		</div>
	</main>
</body>
</html>
