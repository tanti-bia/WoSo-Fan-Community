# WoSo-Fan-Community
Overall functionality
A social platform focused on women's football where fans can follow teams, share opinions, discuss matches, participate in polls, and predict results.
1. Pages, explanations, and functionalities
Page: Home
Functionalities and features: A public feed of community posts with text and photo/video media; the feed shows comment counts, and opening a post shows its comments. Signed-in members can publish, like, comment, and repost, while visitors can browse.

Page: Match center
Functionalities and features: Live, upcoming, and recently finished fixtures
from the configured women's competitions, sorted by kickoff. Fixtures and live
scores are supplied by RapidAPI.

Page: Community
Functionalities and features: Discussions grouped by team, league, or topic

Page: Polls
Functionalities and features: Vote on questions about matches and players

Page: Predictions
Functionalities and features: Browse upcoming matches using the shared Match
Center fixture cache. Prediction submissions are not available yet.

Page: User profiles
Functionalities and features: Members can edit a display name, short bio,
favorite team, location, and profile picture. Clicking a username on a post or
comment shows those public profile details and the member's published posts.
Public profiles let visitors switch between the member's posts, reposts, and
comments. The Comments view shows the original post or replied-to comment for
context, followed by the member's comment, and lets visitors open that comment
in its post conversation.
The signed-in Profile page also shows account details and provides Edit profile
and Log out actions.
Page: Notifications
Functionalities and features: Notify post owners when a user likes, comments on, or reposts their posts

Page: Moderation
Functionalities and features: Report posts and manage inappropriate content.

2. User types and permissions

Visitor: Browse public discussions

Member: Publish posts, comment, vote, and submit predictions

Moderator: Remove inappropriate content and review reports

Administrator: Manage accounts, permissions, and platform settings

3. Required technologies

HTML, CSS, JavaScript, PHP, MySQL/MariaDB, and optionally AJAX for dynamic interactions. XAMPP is optional for local development.

4. Database structure

Table               Main columns
users               user_id (PK), username, email, password_hash, role
posts               post_id (PK), user_id (FK), content, media_path, media_type, created_at, status
comments            comment_id (PK), post_id (FK), parent_comment_id (FK), user_id (FK), content, media_path, media_type, created_at, status
post_likes          post_id (FK), user_id (FK), created_at
post_reposts        repost_id (PK), post_id (FK), user_id (FK), created_at
comment_likes       comment_id (FK), user_id (FK), created_at
comment_reposts    repost_id (PK), comment_id (FK), user_id (FK), created_at
notifications       notification_id (PK), recipient_user_id (FK), actor_user_id (FK), post_id (FK), comment_id (FK), target_comment_id (FK), notification_type, read_at, created_at
teams               team_id (PK), team_name, country
user_favorites      user_id (FK), team_id (FK)
polls               poll_id (PK), created_by (FK), question, end_date
poll_options        option_id (PK), poll_id (FK), option_text
votes               vote_id (PK), poll_id (FK), option_id (FK), user_id (FK)
predictions         prediction_id (PK), match_id (FK), user_id (FK), predicted_home_score, predicted_away_score
reports             report_id (PK), reporter_id (FK), post_id (FK), reason, status

5. Deploying to Render

Render can run this PHP app using the repository's Dockerfile and Blueprint
(`render.yaml`). The Blueprint creates a paid Starter web service and a
1 GB persistent disk for uploaded media. Render's managed relational database
is PostgreSQL, but this application currently requires MySQL/MariaDB; create a
MySQL database with an external provider before deploying.

1. Push the repository to a GitHub repository connected to Render.
2. Create a MySQL 8.0 or MariaDB 10.5+ database named `woso_community` with an
   externally reachable host, username, and password. Allow connections from
   Render as required by your database provider. Use TLS if the provider
   supports it.
3. In Render, choose **New > Blueprint**, select the repository, and apply
   `render.yaml`. When prompted, provide `WOSO_DB_HOST`, `WOSO_DB_NAME`,
   `WOSO_DB_USER`, and `WOSO_DB_PASSWORD`. `WOSO_DB_PORT` defaults to `3306`;
   change it if your provider uses another port. Never commit database
   credentials to this repository.
4. Create the app schema by importing `woso_app/database.sql` into the hosted
   MySQL database. If migrating an existing community, export its local
   database and import the backup instead of importing a blank schema.
5. Wait for the Render build and deploy to finish, then open the service's
   `onrender.com` HTTPS URL. The app can then be opened from any laptop with a
   browser; users do not need XAMPP.
6. The Blueprint mounts persistent storage at `/var/www/html/uploads`. If
   migrating existing posts, transfer the files in `woso_app/uploads/posts`
   into the mounted disk's `posts` directory and profile pictures from
   `woso_app/uploads/profiles` into its `profiles` directory, as well as
   importing the database. Uploaded media is not included in the Docker image.
   Keep future database backups and media backups independently.
7. To enable Google sign-in, configure `WOSO_GOOGLE_CLIENT_ID`,
   `WOSO_GOOGLE_CLIENT_SECRET`, and `WOSO_GOOGLE_REDIRECT_URI` as Render
   environment variables. Add the HTTPS callback URL
   `https://<your-render-domain>/google_callback.php` to the OAuth client's
   authorized redirect URIs. Keep the client secret private.
8. To enable live fixtures, set `WOSO_RAPIDAPI_KEY` to a RapidAPI key for
   `free-api-live-football-data` and configure
   `WOSO_RAPIDAPI_LEAGUE_IDS` with comma-separated women's competition IDs.
   The Blueprint defaults to the WSL, Liga F, Frauen-Bundesliga, and Première
   Ligue IDs (`9227,9907,9676,9677`); edit the value to choose the competitions
   your API plan supports. Do not commit the API key.

The container installs PHP 8.4 with PDO MySQL and verifies the built-in
Fileinfo extension; it sets upload limits above the app's 25 MB per-file limit.
If your database
provider requires a CA certificate for TLS, set `WOSO_DB_SSL_CA` to the
certificate's path in the running service; the connection verifies the server
certificate when PHP exposes that option. The service has one persistent disk
and therefore runs as a single instance. Render disk-backed services can have
brief downtime during deployments.

For local XAMPP development, import the SQL schema in phpMyAdmin, copy the
repository into `C:\xampp\htdocs\WoSo-Fan-Community`, start Apache and MySQL,
and open `http://localhost/WoSo-Fan-Community/`. Local XAMPP defaults are for
development only.

Home, Community, and Match Center are available to visitors.
Polls, Predictions, and Notifications are viewable while signed
out; visitors can browse Polls and Predictions but must sign in to participate.
Profile requires an account, and Moderation requires a Moderator or
Administrator account.

Import `woso_app/database.sql` in phpMyAdmin to set up the account database,
users table, shared football fixture cache, and community feed tables. If the
users table already exists, run
the `CREATE TABLE IF NOT EXISTS` statements for `posts`, `post_likes`,
`comments`, `comment_likes`, `comment_reposts`, `post_reposts`, and
`notifications` from that file. For databases created before comment media,
nested replies, and unread notification badges were added, first migrate the
existing `comments` and `notifications` tables:

```sql
ALTER TABLE comments
	MODIFY content TEXT NULL,
	ADD COLUMN parent_comment_id BIGINT UNSIGNED NULL,
	ADD COLUMN media_path VARCHAR(255) NULL,
	ADD COLUMN media_type ENUM('image', 'video') NULL,
	ADD COLUMN status ENUM('published', 'removed') NOT NULL DEFAULT 'published',
	DROP INDEX comments_post_order,
	ADD KEY comments_post_order (post_id, parent_comment_id, created_at, comment_id),
	ADD CONSTRAINT comments_parent_fk
		FOREIGN KEY (parent_comment_id) REFERENCES comments (comment_id) ON DELETE CASCADE;

ALTER TABLE notifications
	ADD COLUMN target_comment_id BIGINT UNSIGNED NULL,
	ADD COLUMN target_type ENUM('post', 'comment') NOT NULL DEFAULT 'post',
	ADD COLUMN read_at TIMESTAMP NULL,
	DROP INDEX notifications_recipient_order,
	ADD KEY notifications_recipient_order (recipient_user_id, read_at, created_at, notification_id),
	ADD CONSTRAINT notifications_target_comment_fk
		FOREIGN KEY (target_comment_id) REFERENCES comments (comment_id) ON DELETE CASCADE;
```

For an existing database, create the shared football fixture cache table with:

```sql
CREATE TABLE IF NOT EXISTS football_match_cache
(
	cache_key CHAR(64) NOT NULL,
	fixtures_json LONGTEXT NOT NULL,
	fetched_at BIGINT UNSIGNED NOT NULL,
	PRIMARY KEY (cache_key)
) ENGINE=InnoDB
	DEFAULT CHARACTER SET utf8mb4
	COLLATE utf8mb4_unicode_ci;
```

Match Center and Predictions read the same cached fixture snapshot. The shared
snapshot is refreshed at most every 15 minutes; the provider's full league
schedule is cached for 24 hours, and the current-day score feed for 15 minutes.
This keeps page visits from making duplicate API calls. Configure the API key
and league IDs in the PHP server environment before loading either page.

PHP pages must be served through a PHP-enabled web server; opening them
directly as files does not run the account backend.
Signed-in users can edit their public profile with a display name, bio, favorite
team, location, and a JPEG, PNG, or WebP profile picture (up to 5 MB). Profile
pictures are stored in `woso_app/uploads/profiles` or the persistent `uploads`
directory in deployment. Profile fields are optional and do not alter account
credentials.

Signed-in users can publish text posts and attach one JPEG, PNG, GIF,
or WebP photo, or one MP4, WebM, or MOV video (up to 25 MB). Uploaded media is
stored in `woso_app/uploads/posts` (or `uploads/posts` when deployed at the
site document root); ensure the PHP account can write to this directory.
Before submitting a photo or video on a post, comment, reply, or profile, users
can preview it. Photos can be zoomed and repositioned before the selected crop
is uploaded; video uploads include playback controls for preview.
Signed-in users can like and repost posts and comments (both
can be undone), and add comments or nested replies. Comments support the same
text/photo/video content as posts. Recent comments, reaction counts, and
reposts are visible in the public feed. The Home feed shows the latest 50 posts
and reposts. The menu displays an unread notification badge; opening
Notifications marks alerts as read. Post owners and comment authors are notified
about likes, comments/replies, and reposts on their content.

To enable Google sign-in, create a Google OAuth 2.0 Web application client and
add the callback URL for the environment to its authorized redirect URIs. For
local XAMPP development, use
`http://localhost/WoSo-Fan-Community/woso_app/google_callback.php`. For the
hosted site, use its HTTPS URL, for example
`https://example.com/google_callback.php`. Set
`WOSO_GOOGLE_CLIENT_ID`, `WOSO_GOOGLE_CLIENT_SECRET`, and
`WOSO_GOOGLE_REDIRECT_URI` in the PHP environment on the host. Keep the client
secret private. Google sign-in creates new Member accounts and can link an
existing account only when Google confirms the same email address.
Google sign-in is currently disabled; to restore it later, set
`GOOGLE_SIGN_IN_ENABLED` to `true` in `woso_app/google_oauth.php` after OAuth
credentials and callback configuration are ready.

For an existing database, add the Google account identifier column before using
Google sign-in:

```sql
ALTER TABLE users
	ADD COLUMN google_sub VARCHAR(255) NULL,
	ADD UNIQUE KEY users_google_sub_unique (google_sub);
```

For an existing database, add the optional profile fields before opening
`profile.php` or member profile pages:

```sql
ALTER TABLE users
	ADD COLUMN display_name VARCHAR(60) NULL,
	ADD COLUMN bio VARCHAR(500) NULL,
	ADD COLUMN favorite_team VARCHAR(100) NULL,
	ADD COLUMN location VARCHAR(100) NULL,
	ADD COLUMN profile_image_path VARCHAR(255) NULL;
```

For local XAMPP development the database connection defaults to MySQL on localhost
port 3306, database `woso_community`, user `root`, and an empty password. Configure
`WOSO_DB_HOST`, `WOSO_DB_PORT`, `WOSO_DB_NAME`, `WOSO_DB_USER`, and
`WOSO_DB_PASSWORD` in the PHP server environment to use different connection
settings. Do not use the local defaults in a production deployment.

Registration creates every account with the Member role. Signed-in Members can
request Moderator access from their profile; requests are recorded in the
`moderator_requests` table and elevated roles must be granted by an administrator.
The Moderation link is shown only to signed-in moderators and administrators, and
the moderation route checks the role even when opened directly. Passwords are
stored using PHP's password hashing API. The existing content pages remain publicly
viewable, consistent with the Visitor role described above.
