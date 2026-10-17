CREATE TABLE IF NOT EXISTS football_finished_matches
(
	event_id VARCHAR(64) NOT NULL,
	kickoff_at BIGINT UNSIGNED NOT NULL,
	home_score INT NULL,
	away_score INT NULL,
	fixture_json LONGTEXT NOT NULL,
	updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	PRIMARY KEY (event_id),
	KEY football_finished_matches_kickoff (kickoff_at)
) ENGINE=InnoDB
	DEFAULT CHARACTER SET utf8mb4
	COLLATE utf8mb4_unicode_ci;
