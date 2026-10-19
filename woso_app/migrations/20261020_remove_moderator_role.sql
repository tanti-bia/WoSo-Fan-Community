-- Move existing accounts to Member before removing the legacy role value.
UPDATE users
SET role = 'Member'
WHERE role = 'Moderator';

ALTER TABLE users
MODIFY role ENUM('Visitor', 'Member', 'Administrator') NOT NULL DEFAULT 'Member';

-- The profile-based role request feature has been removed.
DROP TABLE IF EXISTS moderator_requests;
