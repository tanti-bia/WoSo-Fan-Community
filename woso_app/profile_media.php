<?php
declare(strict_types=1);

function store_profile_image(string &$error, int &$status): ?array
{
	$image = $_FILES['profile_image'] ?? null;
	if ($image === null) {
		return null;
	}
	if (!is_array($image) || !isset($image['error']) || !is_int($image['error'])) {
		$error = 'The selected image could not be verified. Please choose it again.';
		$status = 400;
		return null;
	}
	if ($image['error'] === UPLOAD_ERR_NO_FILE) {
		return null;
	}
	if ($image['error'] !== UPLOAD_ERR_OK) {
		$error = match ($image['error']) {
			UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The profile picture is too large. The maximum size is 5 MB.',
			UPLOAD_ERR_PARTIAL => 'The image upload was incomplete. Please try again.',
			UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'The image could not be saved. Please try again later.',
			default => 'The image could not be uploaded. Please choose another file and try again.',
		};
		$status = in_array($image['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 413 : 400;
		return null;
	}

	$tmpName = $image['tmp_name'] ?? null;
	$fileSize = $image['size'] ?? null;
	if (!is_string($tmpName) || !is_uploaded_file($tmpName) || !is_int($fileSize) || $fileSize < 1) {
		$error = 'The selected image could not be verified. Please choose it again.';
		$status = 400;
		return null;
	}
	if ($fileSize > 5 * 1024 * 1024) {
		$error = 'The profile picture is too large. The maximum size is 5 MB.';
		$status = 413;
		return null;
	}

	$fileInfo = new finfo(FILEINFO_MIME_TYPE);
	$mimeType = $fileInfo->file($tmpName);
	$allowedImages = [
		'image/jpeg' => 'jpg',
		'image/png' => 'png',
		'image/webp' => 'webp',
	];
	$extension = is_string($mimeType) ? ($allowedImages[$mimeType] ?? null) : null;
	$imageInfo = @getimagesize($tmpName);
	if ($extension === null || $imageInfo === false || $imageInfo['mime'] !== $mimeType) {
		$error = 'Choose a valid JPEG, PNG, or WebP image.';
		$status = 400;
		return null;
	}
	if ($imageInfo[0] > 6000 || $imageInfo[1] > 6000 || $imageInfo[0] * $imageInfo[1] > 25000000) {
		$error = 'The profile picture dimensions are too large. Choose an image no larger than 6,000 by 6,000 pixels or 25 megapixels.';
		$status = 400;
		return null;
	}

	$uploadDirectory = __DIR__ . '/uploads/profiles';
	try {
		if (!is_dir($uploadDirectory)
			&& !mkdir($uploadDirectory, 0755, true)
			&& !is_dir($uploadDirectory)) {
			throw new RuntimeException('The profile image directory could not be created.');
		}

		$storedFileName = bin2hex(random_bytes(16)) . '.' . $extension;
		$absolutePath = $uploadDirectory . '/' . $storedFileName;
		if (!move_uploaded_file($tmpName, $absolutePath)) {
			throw new RuntimeException('The uploaded profile image could not be saved.');
		}
	} catch (RuntimeException $exception) {
		error_log('WoSo profile image upload error: ' . $exception->getMessage());
		$error = 'The profile picture could not be saved. Please check the upload directory permissions and try again.';
		$status = 503;
		return null;
	}

	return [
		'path' => 'uploads/profiles/' . $storedFileName,
		'absolute_path' => $absolutePath,
	];
}
