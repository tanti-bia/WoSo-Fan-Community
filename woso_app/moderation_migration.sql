-- Run once against an existing woso_community database.
ALTER TABLE users
	ADD COLUMN banned_at DATETIME NULL,
	ADD COLUMN suspended_until DATETIME NULL;

CREATE TABLE reports
(
	report_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	reporter_id INT UNSIGNED NOT NULL,
	target_type ENUM('post', 'user') NOT NULL,
	post_id BIGINT UNSIGNED NULL,
	reported_user_id INT UNSIGNED NOT NULL,
	reason VARCHAR(2000) NOT NULL,
	status ENUM('pending', 'dismissed', 'actioned') NOT NULL DEFAULT 'pending',
	review_action ENUM('none', 'delete_post', 'ban_user', 'temporary_block') NULL,
	post_review_action ENUM('none', 'delete_post') NULL,
	post_reviewed_by INT UNSIGNED NULL,
	post_reviewed_at DATETIME NULL,
	user_review_action ENUM('none', 'temporary_block', 'ban_user') NULL,
	user_reviewed_by INT UNSIGNED NULL,
	user_reviewed_at DATETIME NULL,
	blocked_until DATETIME NULL,
	reviewed_by INT UNSIGNED NULL,
	created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
	reviewed_at DATETIME NULL,
	PRIMARY KEY (report_id),
	KEY reports_queue (status, created_at, report_id),
	KEY reports_post (post_id, status),
	KEY reports_user (reported_user_id, status),
	CONSTRAINT reports_reporter_fk FOREIGN KEY (reporter_id) REFERENCES users (user_id) ON DELETE CASCADE,
	CONSTRAINT reports_post_fk FOREIGN KEY (post_id) REFERENCES posts (post_id) ON DELETE SET NULL,
	CONSTRAINT reports_reported_user_fk FOREIGN KEY (reported_user_id) REFERENCES users (user_id) ON DELETE CASCADE,
	CONSTRAINT reports_reviewer_fk FOREIGN KEY (reviewed_by) REFERENCES users (user_id) ON DELETE SET NULL,
	CONSTRAINT reports_post_reviewer_fk FOREIGN KEY (post_reviewed_by) REFERENCES users (user_id) ON DELETE SET NULL,
	CONSTRAINT reports_user_reviewer_fk FOREIGN KEY (user_reviewed_by) REFERENCES users (user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
