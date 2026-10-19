CREATE TABLE IF NOT EXISTS chats
(
	chat_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	chat_type ENUM('direct', 'group') NOT NULL,
	title VARCHAR(100) NULL,
	group_image_path VARCHAR(255) NULL,
	direct_key CHAR(64) NULL,
	created_by INT UNSIGNED NOT NULL,
	created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY (chat_id),
	UNIQUE KEY chats_direct_key_unique (direct_key),
	CONSTRAINT chats_creator_fk FOREIGN KEY (created_by) REFERENCES users (user_id) ON DELETE CASCADE
) ENGINE=InnoDB
	DEFAULT CHARACTER SET utf8mb4
	COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chat_members
(
	chat_id BIGINT UNSIGNED NOT NULL,
	user_id INT UNSIGNED NOT NULL,
	joined_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY (chat_id, user_id),
	KEY chat_members_user (user_id, chat_id),
	CONSTRAINT chat_members_chat_fk FOREIGN KEY (chat_id) REFERENCES chats (chat_id) ON DELETE CASCADE,
	CONSTRAINT chat_members_user_fk FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE CASCADE
) ENGINE=InnoDB
	DEFAULT CHARACTER SET utf8mb4
	COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chat_messages
(
	message_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	chat_id BIGINT UNSIGNED NOT NULL,
	sender_id INT UNSIGNED NOT NULL,
	body VARCHAR(2000) NOT NULL,
	media_path VARCHAR(255) NULL,
	media_type ENUM('image', 'video') NULL,
	created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY (message_id),
	KEY chat_messages_history (chat_id, message_id),
	CONSTRAINT chat_messages_chat_fk FOREIGN KEY (chat_id) REFERENCES chats (chat_id) ON DELETE CASCADE,
	CONSTRAINT chat_messages_sender_fk FOREIGN KEY (sender_id) REFERENCES users (user_id) ON DELETE CASCADE
) ENGINE=InnoDB
	DEFAULT CHARACTER SET utf8mb4
	COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS community_notifications
(
	community_notification_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	recipient_user_id INT UNSIGNED NOT NULL,
	actor_user_id INT UNSIGNED NOT NULL,
	chat_id BIGINT UNSIGNED NOT NULL,
	message_id BIGINT UNSIGNED NULL,
	notification_type ENUM('added_to_group', 'message') NOT NULL,
	read_at TIMESTAMP NULL,
	created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY (community_notification_id),
	KEY community_notifications_inbox (recipient_user_id, read_at, created_at, community_notification_id),
	CONSTRAINT community_notifications_recipient_fk FOREIGN KEY (recipient_user_id) REFERENCES users (user_id) ON DELETE CASCADE,
	CONSTRAINT community_notifications_actor_fk FOREIGN KEY (actor_user_id) REFERENCES users (user_id) ON DELETE CASCADE,
	CONSTRAINT community_notifications_chat_fk FOREIGN KEY (chat_id) REFERENCES chats (chat_id) ON DELETE CASCADE,
	CONSTRAINT community_notifications_message_fk FOREIGN KEY (message_id) REFERENCES chat_messages (message_id) ON DELETE CASCADE
) ENGINE=InnoDB
	DEFAULT CHARACTER SET utf8mb4
	COLLATE utf8mb4_unicode_ci;
