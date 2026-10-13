<?php
declare(strict_types=1);

function store_post_media(string $field, string &$error, int &$status): ?array
{
	$media = $_FILES[$field] ?? null;
	$uploadError = $media === null
		? UPLOAD_ERR_NO_FILE
		: (is_array($media) && isset($media['error']) && is_int($media['error'])
			? $media['error']
			: UPLOAD_ERR_NO_FILE);

	if ($media === null || $uploadError === UPLOAD_ERR_NO_FILE) {
		return null;
	}
	if (!is_array($media) || !isset($media['error']) || !is_int($media['error'])) {
		$error = 'The selected file could not be verified. Please choose it again.';
		$status = 400;
		return null;
	}
	if ($uploadError !== UPLOAD_ERR_OK) {
		$error = match ($uploadError) {
			UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The selected file is too large. The maximum size is 25 MB.',
			UPLOAD_ERR_PARTIAL => 'The file upload was incomplete. Please try again.',
			UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'The file could not be saved. Please try again later.',
			default => 'The file could not be uploaded. Please choose another file and try again.',
		};
		$status = in_array($uploadError, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 413 : 400;
		return null;
	}

	$tmpName = $media['tmp_name'] ?? null;
	$fileSize = $media['size'] ?? null;
	if (!is_string($tmpName) || !is_uploaded_file($tmpName) || !is_int($fileSize) || $fileSize < 1) {
		$error = 'The selected file could not be verified. Please choose it again.';
		$status = 400;
		return null;
	}
	if ($fileSize > 25 * 1024 * 1024) {
		$error = 'The selected file is too large. The maximum size is 25 MB.';
		$status = 413;
		return null;
	}

	$fileInfo = new finfo(FILEINFO_MIME_TYPE);
	$mimeType = $fileInfo->file($tmpName);
	$allowedMedia = [
		'image/jpeg' => ['type' => 'image', 'extension' => 'jpg'],
		'image/png' => ['type' => 'image', 'extension' => 'png'],
		'image/gif' => ['type' => 'image', 'extension' => 'gif'],
		'image/webp' => ['type' => 'image', 'extension' => 'webp'],
		'video/mp4' => ['type' => 'video', 'extension' => 'mp4'],
		'video/webm' => ['type' => 'video', 'extension' => 'webm'],
		'video/quicktime' => ['type' => 'video', 'extension' => 'mov'],
	];
	$mediaDetails = is_string($mimeType) ? ($allowedMedia[$mimeType] ?? null) : null;
	if ($mediaDetails === null) {
		$error = 'Choose a JPEG, PNG, GIF, or WebP photo, or an MP4, WebM, or MOV video.';
		$status = 400;
		return null;
	}

	$uploadDirectory = __DIR__ . '/uploads/posts';
	try {
		if (!is_dir($uploadDirectory)
			&& !mkdir($uploadDirectory, 0755, true)
			&& !is_dir($uploadDirectory)) {
			throw new RuntimeException('The post media directory could not be created.');
		}

		$storedFileName = bin2hex(random_bytes(16)) . '.' . $mediaDetails['extension'];
		$absolutePath = $uploadDirectory . '/' . $storedFileName;
		if (!move_uploaded_file($tmpName, $absolutePath)) {
			throw new RuntimeException('The uploaded post media could not be saved.');
		}
	} catch (RuntimeException $exception) {
		error_log('WoSo post media upload error: ' . $exception->getMessage());
		$error = 'The photo or video could not be saved. Please check the upload directory permissions and try again.';
		$status = 503;
		return null;
	}

	return [
		'path' => 'uploads/posts/' . $storedFileName,
		'type' => $mediaDetails['type'],
		'absolute_path' => $absolutePath,
	];
}
