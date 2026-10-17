-- Run once against an existing woso_community database.
-- For an existing reports table, add separate post and user review decisions.
ALTER TABLE reports
	ADD COLUMN post_review_action ENUM('none', 'delete_post') NULL,
	ADD COLUMN post_reviewed_by INT UNSIGNED NULL,
	ADD COLUMN post_reviewed_at DATETIME NULL,
	ADD COLUMN user_review_action ENUM('none', 'temporary_block', 'ban_user') NULL,
	ADD COLUMN user_reviewed_by INT UNSIGNED NULL,
	ADD COLUMN user_reviewed_at DATETIME NULL,
	ADD CONSTRAINT reports_post_reviewer_fk FOREIGN KEY (post_reviewed_by) REFERENCES users (user_id) ON DELETE SET NULL,
	ADD CONSTRAINT reports_user_reviewer_fk FOREIGN KEY (user_reviewed_by) REFERENCES users (user_id) ON DELETE SET NULL;

-- Include the post removal notification type; safe if the earlier standalone
-- notification migration has already been run.
ALTER TABLE notifications
	MODIFY notification_type ENUM('like', 'comment', 'repost', 'post_removed') NOT NULL;
