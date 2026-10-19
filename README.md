# WoSo Fan Community

## 1. Overall functionality

A social web application for women's football fans. Users can follow match information, publish and discuss posts, chat, submit score predictions, view prediction points, and receive community notifications. Administrators can review user reports and moderate posts and accounts.

## 2. Pages, explanations, functionalities, and features

- **Home:** Public community feed of posts with text, photos, and videos. Signed-in members can publish, like, comment, reply, and repost. Posts and comments link to public profiles and conversations.
- **Match Center:** Shows upcoming, live when available, and finished matches for configured women's competitions. Fixture data and finished scores are cached in the database.
- **Community:** Lets signed-in members start direct chats, create group chats, add members, customize group details, and exchange text and media messages.
- **Predictions:** Lets signed-in members submit or update home and away score predictions before kickoff. Finished results show saved predictions and points earned; the page also shows members' total points.
- **Public profiles:** Show a member's public profile details and posts, reposts, or comments. Profile activity links back to the related posts and conversations.
- **Profile:** Lets the signed-in member view account details, edit their username and public profile information, change their password, and log out.
- **Notifications:** Shows alerts for post and comment likes, comments, replies, reposts, and community chat activity. Members can mark notifications as read.
- **Sign in and registration:** Supports email/password accounts with email confirmation and Google sign-in when configured. Users can request a password reset by email.
- **Moderation:** Administrator-only page for reviewing member reports. Administrators can dismiss a report, remove a reported post, temporarily block an account, or ban an account.

## 3. User types and permissions

- **Visitor:** Can browse public pages, match information, predictions and public profiles. Must sign in to participate in the community or submit predictions.
- **Member:** Can manage their own profile, publish and interact with community content, send chat messages, submit predictions, and report other users or posts.
- **Administrator:** Has member capabilities and access to moderation tools. Can review reports and moderate posts and accounts. Administrators use moderation controls instead of submitting reports.

## 4. Required technologies

- HTML and CSS for page structure and styling.
- JavaScript for interactive page behavior.
- PHP for server-side pages, account handling, and application logic.
- MySQL or MariaDB for persistent application data; PHP accesses it through PDO MySQL.
- Apache or another PHP-enabled web server. XAMPP (Apache, PHP, and MySQL/MariaDB) is optional for local development.
- External services are optional/configuration-dependent: Google OAuth for Google sign-in, Resend for account email, and TheSportsDB for match data.

## 5. Database structure

The schema is defined in `woso_app/database.sql`. Most community tables use InnoDB and `utf8mb4`. Foreign keys connect user-generated records to their owners and related posts, comments, chats, or users; deleting a referenced user or content may cascade where specified in the schema.

- **users:** `user_id` (primary key), `username` (unique), `email` (unique), `password_hash`, `google_sub` (unique, optional Google account ID), `display_name`, `bio`, `favorite_team`, `location`, `profile_image_path`, `role`, `banned_at`, `suspended_until`, `created_at`.
- **pending_registrations:** `pending_registration_id` (primary key), username, email, password hash, verification token hash, redirect target, expiration and request timestamps. Stores accounts until email confirmation.
- **password_reset_requests:** `user_id` (primary/foreign key), token hash, expiration and request timestamps. Stores one-time password reset links.
- **posts:** `post_id` (primary key), `user_id` (author foreign key), content, media path/type, status, created timestamp.
- **comments:** `comment_id` (primary key), `post_id` and `user_id` (foreign keys), optional `parent_comment_id` for replies, content, media path/type, status, created timestamp.
- **post_likes:** `post_id`, `user_id` (composite primary key and foreign keys), created timestamp.
- **post_reposts:** `repost_id` (primary key), `post_id`, `user_id` (foreign keys and unique post/user pair), created timestamp.
- **comment_likes:** `comment_id`, `user_id` (composite primary key and foreign keys), created timestamp.
- **comment_reposts:** `repost_id` (primary key), `comment_id`, `user_id` (foreign keys and unique comment/user pair), created timestamp.
- **notifications:** `notification_id` (primary key), recipient and actor user IDs, post and optional comment IDs, target type, notification type, read and created timestamps.
- **reports:** `report_id` (primary key), reporter and reported user IDs, target type, optional post ID, reason, status, moderation decisions, reviewer IDs, and review timestamps. Pending reports appear in the administrator moderation queue.
- **predictions:** Composite primary key of `user_id` and `event_id`; stores predicted home and away scores and created/updated timestamps. Points are calculated from saved predictions and finished match scores.
- **football_match_cache:** `cache_key` (primary key), cached fixture JSON, fetch timestamp, optional warning text.
- **football_match_endpoint_cache:** `cache_key` (primary key), cached endpoint fixture JSON and fetch timestamp.
- **football_finished_matches:** `event_id` (primary key), kickoff timestamp, home and away scores, fixture JSON, updated timestamp.
- **chats:** `chat_id` (primary key), chat type, optional title/group image/direct-chat key, creator user ID, created timestamp.
- **chat_members:** `chat_id` and `user_id` (composite primary key and foreign keys), joined timestamp.
- **chat_messages:** `message_id` (primary key), `chat_id` and `sender_id` (foreign keys), message body, optional media path/type, created timestamp.
- **community_notifications:** `community_notification_id` (primary key), recipient and actor user IDs, chat and optional message IDs, notification type, read and created timestamps.
