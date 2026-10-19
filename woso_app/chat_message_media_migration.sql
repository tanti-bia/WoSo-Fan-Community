ALTER TABLE chat_messages
	ADD COLUMN media_path VARCHAR(255) NULL AFTER body,
	ADD COLUMN media_type ENUM('image', 'video') NULL AFTER media_path;
