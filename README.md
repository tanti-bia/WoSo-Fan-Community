# WoSo-Fan-Community
Overall functionality
A social platform focused on women's football where fans can follow teams, share opinions, discuss matches, participate in polls, and predict results.
1. Pages, explanations, and functionalities
Page: Home
Functionalities and features: Posts, announcements, and discussions from followed accounts

Page: Match center
Functionalities and features: Match details, scores, and a discussion thread

Page: Community
Functionalities and features: Discussions grouped by team, league, or topic

Page: Polls
Functionalities and features: Vote on questions about matches and players

Page: Predictions
Functionalities and features: Submit score predictions before matches and earn points

Page: User profiles
Functionalities and features: Display favorite teams, posts, and prediction history

Page: Notifications
Functionalities and features: Notify users about replies, polls, and followed teams

Page: Moderation
Functionalities and features: Report posts and manage inappropriate content.

2. User types and permissions

Visitor: Browse public discussions

Member: Publish posts, comment, vote, and submit predictions

Moderator: Remove inappropriate content and review reports

Administrator: Manage accounts, permissions, and platform settings

3. Required technologies

HTML, CSS, JavaScript, Bootstrap, PHP, MySQL, XAMPP, and optionally AJAX for dynamic interactions.

4. Database structure

Table               Main columns
users               user_id (PK), username, email, password_hash, role
posts               post_id (PK), user_id (FK), content, created_at, status
comments            comment_id (PK), post_id (FK), user_id (FK), content, created_at
teams               team_id (PK), team_name, country
user_favorites      user_id (FK), team_id (FK)
polls               poll_id (PK), created_by (FK), question, end_date
poll_options        option_id (PK), poll_id (FK), option_text
votes               vote_id (PK), poll_id (FK), option_id (FK), user_id (FK)
predictions         prediction_id (PK), match_id (FK), user_id (FK), predicted_home_score, predicted_away_score
reports             report_id (PK), reporter_id (FK), post_id (FK), reason, status

5. Running the app locally

With XAMPP, copy this repository into `C:\xampp\htdocs\WoSo-Fan-Community`,
start Apache and MySQL, then open
`http://localhost/WoSo-Fan-Community/`. The project opens the app on Home.
Home, Match Center, and Community are available to visitors. Polls, Predictions,
and Notifications are viewable while signed out; visitors can browse Polls and
Predictions but must sign in to participate. Notifications tell visitors to sign
in to view them. Profile requires an account, and Moderation requires a Moderator
or Administrator account. The Account page shows the sign-in form to visitors and
account details to signed-in users.

The Match Center retrieves fixtures from the RapidAPI listing [Free API Live
Football Data](https://rapidapi.com/Creativesdev/api/free-api-live-football-data).
Configure `WOSO_RAPIDAPI_KEY` and `WOSO_RAPIDAPI_LEAGUE_IDS` in Apache's PHP
environment:

1. Sign in to RapidAPI, open the linked API, and subscribe to a plan.
2. Copy the `x-rapidapi-key` value from that API's code snippet.
3. In RapidAPI's Test Endpoint, call `football-get-matches-by-date-and-league`
   with a date in `YYYYMMDD` format. In the response, find women's competitions
   and note each group's `id`. The current Match Center configuration uses:
   WSL 9227, Liga F 9907, Frauen-Bundesliga 9676, Première Ligue Féminine
   9677, A-League Women 9495, Women's Premier Division 10210, USL Super League
   Women 10699, Women's Champions League 9375, Women's World Cup 76, Women's
   EURO 292, Women's Nations League A 10457, FA Cup (Women) 10082, Women's
   League Cup 9717, and DFB Pokal Frauen 10650.
4. In `C:\xampp\apache\conf\httpd.conf`, set the API key and league IDs:

   ```apache
   SetEnv WOSO_RAPIDAPI_KEY "paste_your_rapidapi_key_here"
   SetEnv WOSO_RAPIDAPI_LEAGUE_IDS "9227,9907,9676,9677,9495,10210,10699,9375,76,292,10457,10082,9717,10650"
   ```

   The key provided for this setup has been added to the local Apache
   configuration; it is not stored in this repository. If you replace the key,
   update the `SetEnv` value there.
5. Save the file and restart Apache in the XAMPP Control Panel so PHP receives
   the configuration.
6. Open Match Center. Select a date and, optionally, enter a team name to
   search that day's fixtures, including completed games.

The app makes one API request per selected date and filters the results to the
configured league IDs; live matches appear first. Responses are cached on the
server to reduce provider requests. The API plan must cover the competitions
and dates you want. Keep the API key on the server and never put it in HTML,
JavaScript, or a public repository.

Import `woso_app/database.sql` in phpMyAdmin to set up the account database and
users table. PHP pages must be served through Apache; opening them directly as
files does not run the account backend.

To enable Google sign-in, create a Google OAuth 2.0 Web application client and
add the exact callback URL
`http://localhost/WoSo-Fan-Community/woso_app/google_callback.php` to its
authorized redirect URIs for local development. Configure
`WOSO_GOOGLE_CLIENT_ID`, `WOSO_GOOGLE_CLIENT_SECRET`, and
`WOSO_GOOGLE_REDIRECT_URI` in Apache's PHP environment, using that callback URL
for `WOSO_GOOGLE_REDIRECT_URI`. For deployment, use the HTTPS callback URL for
your site and keep the client secret private. Google sign-in creates new Member
accounts and can link an existing account only when Google confirms the same
email address.

For an existing database, add the Google account identifier column before using
Google sign-in:

```sql
ALTER TABLE users
	ADD COLUMN google_sub VARCHAR(255) NULL,
	ADD UNIQUE KEY users_google_sub_unique (google_sub);
```

For local XAMPP development the database connection defaults to MySQL on localhost
port 3306, database `woso_community`, user `root`, and an empty password. Configure
`WOSO_DB_HOST`, `WOSO_DB_PORT`, `WOSO_DB_NAME`, `WOSO_DB_USER`, and
`WOSO_DB_PASSWORD` in the PHP server environment to use different connection
settings. Do not use the local defaults in a production deployment.

Registration lets users choose Member or request Moderator access. Every account
is created with the Member role; moderator requests are recorded in the
`moderator_requests` table and elevated roles must be granted by an administrator.
The Moderation link is shown only to signed-in moderators and administrators, and
the moderation route checks the role even when opened directly. Passwords are
stored using PHP's password hashing API. The existing content pages remain publicly
viewable, consistent with the Visitor role described above.
