CREATE DATABASE IF NOT EXISTS woso_community
	CHARACTER SET utf8mb4
	COLLATE utf8mb4_unicode_ci;

USE woso_community;

CREATE TABLE IF NOT EXISTS users
(
	user_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
	username VARCHAR(30) NOT NULL,
	email VARCHAR(254) NOT NULL,
	password_hash VARCHAR(255) NOT NULL,
	google_sub VARCHAR(255) NULL,
	display_name VARCHAR(60) NULL,
	bio VARCHAR(500) NULL,
	favorite_team VARCHAR(100) NULL,
	location VARCHAR(100) NULL,
	profile_image_path VARCHAR(255) NULL,
	banned_at DATETIME NULL,
	suspended_until DATETIME NULL,
	role ENUM('Visitor', 'Member', 'Moderator', 'Administrator') NOT NULL DEFAULT 'Member',
	created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY (user_id),
	UNIQUE KEY users_username_unique (username),
	UNIQUE KEY users_email_unique (email),
	UNIQUE KEY users_google_sub_unique (google_sub)
) ENGINE=InnoDB
	DEFAULT CHARACTER SET utf8mb4
	COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS football_match_cache
(
	cache_key CHAR(64) NOT NULL,
	fixtures_json LONGTEXT NOT NULL,
	fetched_at BIGINT UNSIGNED NOT NULL,
	PRIMARY KEY (cache_key)
) ENGINE=InnoDB
	DEFAULT CHARACTER SET utf8mb4
	COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS moderator_requests
(
	user_id INT UNSIGNED NOT NULL,
	requested_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY (user_id),
	CONSTRAINT moderator_requests_user_fk
		FOREIGN KEY (user_id) REFERENCES users (user_id)
		ON DELETE CASCADE
) ENGINE=InnoDB
	DEFAULT CHARACTER SET utf8mb4
	COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS posts
(
	post_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	user_id INT UNSIGNED NOT NULL,
	content TEXT NULL,
	media_path VARCHAR(255) NULL,
	media_type ENUM('image', 'video') NULL,
	status ENUM('published', 'removed') NOT NULL DEFAULT 'published',
	created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY (post_id),
	KEY posts_feed_order (status, created_at, post_id),
	CONSTRAINT posts_user_fk
		FOREIGN KEY (user_id) REFERENCES users (user_id)
		ON DELETE CASCADE
) ENGINE=InnoDB
	DEFAULT CHARACTER SET utf8mb4
	COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reports
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
) ENGINE=InnoDB
	DEFAULT CHARACTER SET utf8mb4
	COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS post_likes
(
	post_id BIGINT UNSIGNED NOT NULL,
	user_id INT UNSIGNED NOT NULL,
	created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY (post_id, user_id),
	KEY post_likes_user (user_id, post_id),
	CONSTRAINT post_likes_post_fk
		FOREIGN KEY (post_id) REFERENCES posts (post_id)
		ON DELETE CASCADE,
	CONSTRAINT post_likes_user_fk
		FOREIGN KEY (user_id) REFERENCES users (user_id)
		ON DELETE CASCADE
) ENGINE=InnoDB
	DEFAULT CHARACTER SET utf8mb4
	COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS comments
(
	comment_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	post_id BIGINT UNSIGNED NOT NULL,
	user_id INT UNSIGNED NOT NULL,
	parent_comment_id BIGINT UNSIGNED NULL,
	content TEXT NULL,
	media_path VARCHAR(255) NULL,
	media_type ENUM('image', 'video') NULL,
	status ENUM('published', 'removed') NOT NULL DEFAULT 'published',
	created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY (comment_id),
	KEY comments_post_order (post_id, parent_comment_id, created_at, comment_id),
	CONSTRAINT comments_post_fk
		FOREIGN KEY (post_id) REFERENCES posts (post_id)
		ON DELETE CASCADE,
	CONSTRAINT comments_parent_fk
		FOREIGN KEY (parent_comment_id) REFERENCES comments (comment_id)
		ON DELETE CASCADE,
	CONSTRAINT comments_user_fk
		FOREIGN KEY (user_id) REFERENCES users (user_id)
		ON DELETE CASCADE
) ENGINE=InnoDB
	DEFAULT CHARACTER SET utf8mb4
	COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS comment_likes
(
	comment_id BIGINT UNSIGNED NOT NULL,
	user_id INT UNSIGNED NOT NULL,
	created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY (comment_id, user_id),
	KEY comment_likes_user (user_id, comment_id),
	CONSTRAINT comment_likes_comment_fk
		FOREIGN KEY (comment_id) REFERENCES comments (comment_id)
		ON DELETE CASCADE,
	CONSTRAINT comment_likes_user_fk
		FOREIGN KEY (user_id) REFERENCES users (user_id)
		ON DELETE CASCADE
) ENGINE=InnoDB
	DEFAULT CHARACTER SET utf8mb4
	COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS comment_reposts
(
	repost_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	comment_id BIGINT UNSIGNED NOT NULL,
	user_id INT UNSIGNED NOT NULL,
	created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY (repost_id),
	UNIQUE KEY comment_reposts_unique (comment_id, user_id),
	KEY comment_reposts_feed (created_at, repost_id),
	CONSTRAINT comment_reposts_comment_fk
		FOREIGN KEY (comment_id) REFERENCES comments (comment_id)
		ON DELETE CASCADE,
	CONSTRAINT comment_reposts_user_fk
		FOREIGN KEY (user_id) REFERENCES users (user_id)
		ON DELETE CASCADE
) ENGINE=InnoDB
	DEFAULT CHARACTER SET utf8mb4
	COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS post_reposts
(
	repost_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	post_id BIGINT UNSIGNED NOT NULL,
	user_id INT UNSIGNED NOT NULL,
	created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY (repost_id),
	UNIQUE KEY post_reposts_unique (post_id, user_id),
	KEY post_reposts_feed (created_at, repost_id),
	CONSTRAINT post_reposts_post_fk
		FOREIGN KEY (post_id) REFERENCES posts (post_id)
		ON DELETE CASCADE,
	CONSTRAINT post_reposts_user_fk
		FOREIGN KEY (user_id) REFERENCES users (user_id)
		ON DELETE CASCADE
) ENGINE=InnoDB
	DEFAULT CHARACTER SET utf8mb4
	COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notifications
(
	notification_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	recipient_user_id INT UNSIGNED NOT NULL,
	actor_user_id INT UNSIGNED NOT NULL,
	post_id BIGINT UNSIGNED NOT NULL,
	comment_id BIGINT UNSIGNED NULL,
	target_comment_id BIGINT UNSIGNED NULL,
	target_type ENUM('post', 'comment') NOT NULL DEFAULT 'post',
	notification_type ENUM('like', 'comment', 'repost', 'post_removed') NOT NULL,
	read_at TIMESTAMP NULL,
	created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY (notification_id),
	KEY notifications_recipient_order (recipient_user_id, read_at, created_at, notification_id),
	CONSTRAINT notifications_recipient_fk
		FOREIGN KEY (recipient_user_id) REFERENCES users (user_id)
		ON DELETE CASCADE,
	CONSTRAINT notifications_actor_fk
		FOREIGN KEY (actor_user_id) REFERENCES users (user_id)
		ON DELETE CASCADE,
	CONSTRAINT notifications_post_fk
		FOREIGN KEY (post_id) REFERENCES posts (post_id)
		ON DELETE CASCADE,
	CONSTRAINT notifications_comment_fk
		FOREIGN KEY (comment_id) REFERENCES comments (comment_id)
		ON DELETE CASCADE,
	CONSTRAINT notifications_target_comment_fk
		FOREIGN KEY (target_comment_id) REFERENCES comments (comment_id)
		ON DELETE CASCADE
) ENGINE=InnoDB
	DEFAULT CHARACTER SET utf8mb4
	COLLATE utf8mb4_unicode_ci;
