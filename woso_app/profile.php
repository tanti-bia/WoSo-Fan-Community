<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/profile_media.php';
require_signed_in('profile.php');

$error = '';
$status = 200;
$userId = (int) $_SESSION['user_id'];
$isRequestingModerator = $_SERVER['REQUEST_METHOD'] === 'POST'
	&& post_string('action') === 'request_moderator';
$isEditing = ($_SERVER['REQUEST_METHOD'] === 'POST' && !$isRequestingModerator)
	|| (isset($_GET['edit']) && is_string($_GET['edit']) && $_GET['edit'] === '1');
$moderatorRequestPending = false;
$profile = [
	'username' => (string) $_SESSION['username'],
	'email' => (string) ($_SESSION['email'] ?? ''),
	'role' => (string) ($_SESSION['role'] ?? ''),
	'display_name' => '',
	'bio' => '',
	'favorite_team' => '',
	'location' => '',
	'profile_image_path' => null,
];

if ($isRequestingModerator) {
	if (!has_valid_csrf_token()) {
		$error = 'Your session expired. Please try again.';
		$status = 400;
	} else {
		try {
			$database = database_connection();
			$database->beginTransaction();
			$roleQuery = $database->prepare(
				'SELECT role FROM users WHERE user_id = :user_id FOR UPDATE'
			);
			$roleQuery->execute(['user_id' => $userId]);
			$currentRole = $roleQuery->fetchColumn();
			if ($currentRole !== 'Member') {
				$database->rollBack();
				$error = 'Only Members can request Moderator access.';
				$status = 403;
			} else {
				$requestQuery = $database->prepare(
					'SELECT user_id FROM moderator_requests WHERE user_id = :user_id'
				);
				$requestQuery->execute(['user_id' => $userId]);
				if ($requestQuery->fetchColumn() !== false) {
					$database->rollBack();
					$_SESSION['profile_notice'] = 'Your Moderator access request is already pending.';
				} else {
					$request = $database->prepare(
						'INSERT INTO moderator_requests (user_id) VALUES (:user_id)'
					);
					$request->execute(['user_id' => $userId]);
					$database->commit();
					$_SESSION['profile_notice'] = 'Your Moderator access request has been submitted for administrator approval.';
				}
				header('Location: profile.php');
				exit;
			}
		} catch (PDOException $exception) {
			if (isset($database) && $database->inTransaction()) {
				$database->rollBack();
			}
			error_log('WoSo moderator request database error: ' . $exception->getMessage());
			$error = 'Your Moderator access request could not be submitted. Please try again later.';
			$status = 503;
		}
	}
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$profile['display_name'] = trim(post_string('display_name'));
	$profile['bio'] = trim(post_string('bio'));
	$profile['favorite_team'] = trim(post_string('favorite_team'));
	$profile['location'] = trim(post_string('location'));

	if (!has_valid_csrf_token()) {
		$error = 'Your session expired. Please try again.';
		$status = 400;
	} elseif (strlen($profile['display_name']) > 240
		|| strlen($profile['bio']) > 2000
		|| strlen($profile['favorite_team']) > 400
		|| strlen($profile['location']) > 400) {
		$error = 'One or more profile fields exceed the allowed length.';
		$status = 400;
	} else {
		$uploadedImage = store_profile_image($error, $status);
		if ($error === '') {
			try {
				$database = database_connection();
				$oldImageQuery = $database->prepare(
					'SELECT profile_image_path FROM users WHERE user_id = :user_id'
				);
				$oldImageQuery->execute(['user_id' => $userId]);
				$oldImagePath = $oldImageQuery->fetchColumn();
				$query = $database->prepare(
					'UPDATE users SET display_name = :display_name, bio = :bio,
						favorite_team = :favorite_team, location = :location,
						profile_image_path = COALESCE(:profile_image_path, profile_image_path)
					WHERE user_id = :user_id'
				);
				$query->execute([
					'display_name' => $profile['display_name'] !== '' ? $profile['display_name'] : null,
					'bio' => $profile['bio'] !== '' ? $profile['bio'] : null,
					'favorite_team' => $profile['favorite_team'] !== '' ? $profile['favorite_team'] : null,
					'location' => $profile['location'] !== '' ? $profile['location'] : null,
					'profile_image_path' => $uploadedImage['path'] ?? null,
					'user_id' => $userId,
				]);
				if ($uploadedImage !== null) {
					if (is_string($oldImagePath)
						&& preg_match('~^uploads/profiles/[a-f0-9]{32}\.(?:jpg|png|webp)$~D', $oldImagePath)) {
						$oldAbsolutePath = __DIR__ . '/' . $oldImagePath;
						if (is_file($oldAbsolutePath) && !unlink($oldAbsolutePath)) {
							error_log('WoSo profile image cleanup error: the previous image could not be removed.');
						}
					}
				}

				$_SESSION['profile_notice'] = 'Your profile has been updated.';
				header('Location: profile.php');
				exit;
			} catch (PDOException $exception) {
				if ($uploadedImage !== null && is_file($uploadedImage['absolute_path'])) {
					if (!unlink($uploadedImage['absolute_path'])) {
						error_log('WoSo profile image cleanup error: the unsaved image could not be removed.');
					}
				}
				error_log('WoSo profile update database error: ' . $exception->getMessage());
				$error = 'Your profile could not be saved. Please check the database setup and try again.';
				$status = 503;
			}
		}
	}
}

if (!$isEditing || $error !== '' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
	try {
		$database = database_connection();
		$query = $database->prepare(
			'SELECT username, email, role, display_name, bio, favorite_team, location, profile_image_path
			FROM users WHERE user_id = :user_id'
		);
		$query->execute(['user_id' => $userId]);
		$savedProfile = $query->fetch();
		if ($savedProfile) {
			$profile['username'] = (string) $savedProfile['username'];
			$profile['email'] = (string) $savedProfile['email'];
			$profile['role'] = (string) $savedProfile['role'];
			$profile['profile_image_path'] = $savedProfile['profile_image_path'];
			if (!$isEditing || $_SERVER['REQUEST_METHOD'] !== 'POST') {
				$profile['display_name'] = (string) ($savedProfile['display_name'] ?? '');
				$profile['bio'] = (string) ($savedProfile['bio'] ?? '');
				$profile['favorite_team'] = (string) ($savedProfile['favorite_team'] ?? '');
				$profile['location'] = (string) ($savedProfile['location'] ?? '');
			}
		}
		if ($profile['role'] === 'Member') {
			$requestQuery = $database->prepare(
				'SELECT user_id FROM moderator_requests WHERE user_id = :user_id'
			);
			$requestQuery->execute(['user_id' => $userId]);
			$moderatorRequestPending = $requestQuery->fetchColumn() !== false;
		}
	} catch (PDOException $exception) {
		error_log('WoSo profile load database error: ' . $exception->getMessage());
		$error = 'Your profile is temporarily unavailable. Please check the database setup and try again.';
		$status = 503;
	}
}

$notice = isset($_SESSION['profile_notice']) && is_string($_SESSION['profile_notice'])
	? $_SESSION['profile_notice']
	: '';
unset($_SESSION['profile_notice']);
http_response_code($status);
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Your Profile - Women's Football</title>
	<link rel="stylesheet" href="styles.css">
	<script src="image_viewer.js" defer></script>
	<script src="back_button.js" defer></script>
	<script src="upload_preview.js" defer></script>
</head>
<body>
	<nav class="topnav" aria-label="Main navigation">
		<a href="home.php">Home</a>
		<a href="matches.php">Match Center</a>
		<a href="community.html">Community</a>
		<a href="polls.php">Polls</a>
		<a href="predictions.php">Predictions</a>
		<?= notifications_nav_link() ?>
		<?= moderation_nav_link() ?>
		<?= account_nav_link(true) ?>
	</nav>
	<main class="profile-page">
		<header class="home-heading profile-heading">
			<p class="home-eyebrow">Your account</p>
			<h1><?= $isEditing ? 'Edit your profile' : 'Account details' ?></h1>
			<p><?= $isEditing ? 'Update the details other fans see on your profile.' : 'Your account information and community profile.' ?></p>
		</header>
		<?php if ($error !== ''): ?>
			<p class="auth-message auth-error" role="alert"><?= escape_html($error) ?></p>
		<?php endif; ?>
		<?php if ($notice !== ''): ?>
			<p class="auth-message auth-success" role="status"><?= escape_html($notice) ?></p>
		<?php endif; ?>
		<section class="profile-card <?= $isEditing ? 'is-editing' : 'is-overview' ?>">
			<?php if (is_string($profile['profile_image_path']) && $profile['profile_image_path'] !== ''): ?>
				<img class="profile-avatar profile-avatar-large" src="<?= escape_html($profile['profile_image_path']) ?>" alt="Your profile picture">
			<?php else: ?>
				<div class="profile-avatar profile-avatar-large profile-avatar-placeholder" aria-hidden="true"><?= escape_html(strtoupper(substr((string) $profile['username'], 0, 1))) ?></div>
			<?php endif; ?>
			<?php if ($isEditing): ?>
				<p class="profile-username">@<?= escape_html((string) $profile['username']) ?></p>
				<form action="profile.php?edit=1" method="post" enctype="multipart/form-data">
					<input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
					<label for="display-name">Display name</label>
					<input id="display-name" name="display_name" type="text" maxlength="60" value="<?= escape_html((string) $profile['display_name']) ?>" placeholder="How should we call you?">
					<label for="bio">About you</label>
					<textarea id="bio" name="bio" maxlength="500" rows="5" placeholder="Tell fans a little about yourself..."><?= escape_html((string) $profile['bio']) ?></textarea>
					<label for="favorite-team">Favorite team</label>
					<input id="favorite-team" name="favorite_team" type="text" maxlength="100" value="<?= escape_html((string) $profile['favorite_team']) ?>" placeholder="e.g. Arsenal Women">
					<label for="location">Location</label>
					<input id="location" name="location" type="text" maxlength="100" value="<?= escape_html((string) $profile['location']) ?>" placeholder="City or country">
					<label for="profile-image">Profile picture</label>
					<input id="profile-image" name="profile_image" type="file" accept="image/jpeg,image/png,image/webp">
					<p class="post-hint">JPEG, PNG, or WebP; maximum 5 MB. Upload a new picture to replace your current one.</p>
					<div class="profile-account-actions">
						<button class="auth-button" type="submit">Save profile</button>
						<a class="profile-secondary-button" href="profile.php">Cancel</a>
					</div>
				</form>
			<?php else: ?>
				<div class="profile-account-details">
					<h2><?= $profile['display_name'] !== '' ? escape_html((string) $profile['display_name']) : escape_html((string) $profile['username']) ?></h2>
					<p class="profile-username">@<?= escape_html((string) $profile['username']) ?></p>
					<h3 class="profile-section-title">Account information</h3>
					<dl class="profile-account-list">
						<dt>Username</dt>
						<dd>@<?= escape_html((string) $profile['username']) ?></dd>
						<dt>Display name</dt>
						<dd><?= $profile['display_name'] !== '' ? escape_html((string) $profile['display_name']) : 'Not set' ?></dd>
						<dt>Email address</dt>
						<dd><?= $profile['email'] !== '' ? escape_html((string) $profile['email']) : 'Not available' ?></dd>
						<dt>Account type</dt>
						<dd><?= escape_html((string) $profile['role']) ?></dd>
						<dt>About you</dt>
						<dd><?= $profile['bio'] !== '' ? nl2br(escape_html((string) $profile['bio'])) : 'Not set' ?></dd>
						<dt>Favorite team</dt>
						<dd><?= $profile['favorite_team'] !== '' ? escape_html((string) $profile['favorite_team']) : 'Not set' ?></dd>
						<dt>Location</dt>
						<dd><?= $profile['location'] !== '' ? escape_html((string) $profile['location']) : 'Not set' ?></dd>
					</dl>
					<div class="profile-account-actions">
						<a class="auth-button" href="profile.php?edit=1">Edit profile</a>
						<a class="profile-secondary-button" href="home.php?user_id=<?= $userId ?>">View public profile</a>
						<form action="logout.php" method="post">
							<input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
							<button class="profile-logout-button" type="submit">Log out</button>
						</form>
					</div>
					<?php if ($profile['role'] === 'Member'): ?>
						<section class="moderator-request" aria-labelledby="moderator-request-heading">
							<h3 id="moderator-request-heading">Moderator access</h3>
							<?php if ($moderatorRequestPending): ?>
								<p>Your request is pending administrator approval.</p>
							<?php else: ?>
								<p>Request Moderator access. An administrator must approve your request.</p>
								<form action="profile.php" method="post">
									<input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
									<input type="hidden" name="action" value="request_moderator">
									<button class="profile-secondary-button" type="submit">Request Moderator access</button>
								</form>
							<?php endif; ?>
						</section>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</section>
	</main>
</body>
</html>
