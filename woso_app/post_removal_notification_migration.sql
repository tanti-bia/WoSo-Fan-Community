-- Run once against an existing woso_community database.
ALTER TABLE notifications
	MODIFY notification_type ENUM('like', 'comment', 'repost', 'post_removed') NOT NULL;
