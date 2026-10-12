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
