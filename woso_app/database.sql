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
	role ENUM('Visitor', 'Member', 'Moderator', 'Administrator') NOT NULL DEFAULT 'Member',
	created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY (user_id),
	UNIQUE KEY users_username_unique (username),
	UNIQUE KEY users_email_unique (email),
	UNIQUE KEY users_google_sub_unique (google_sub)
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
