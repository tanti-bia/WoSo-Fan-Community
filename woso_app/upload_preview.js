(() => {
	'use strict';

	document.querySelectorAll('input[type="file"][accept*="image/"]').forEach((input) => {
		const form = input.form;
		if (!form) {
			return;
		}

		const isProfileImage = input.name === 'profile_image';
		const isImageFile = (file) => file.type.startsWith('image/')
			|| /\.(?:jpe?g|png|gif|webp)$/i.test(file.name);
		const preview = document.createElement('section');
		preview.className = 'upload-preview';
		preview.hidden = true;
		preview.setAttribute('aria-label', 'Media preview');
		preview.innerHTML = `
			<p class="upload-preview-title">Preview before posting</p>
			<div class="upload-preview-frame${isProfileImage ? ' is-square' : ''}" tabindex="0" aria-label="Drag the photo to choose which part to show">
				<img class="upload-preview-image" alt="Photo preview">
			</div>
			<div class="upload-preview-controls">
				<label>
					Zoom
					<input class="upload-preview-zoom" type="range" min="1" max="3" step="0.01" value="1">
				</label>
				<label>
					Horizontal position
					<input class="upload-preview-x" type="range" min="0" max="100" value="50">
				</label>
				<label>
					Vertical position
					<input class="upload-preview-y" type="range" min="0" max="100" value="50">
				</label>
			</div>
			<div class="upload-preview-video-wrap" hidden>
				<video class="upload-preview-video" controls playsinline></video>
			</div>
			<p class="upload-preview-message" role="status"></p>
		`;
		input.insertAdjacentElement('afterend', preview);

		const frame = preview.querySelector('.upload-preview-frame');
		const image = preview.querySelector('.upload-preview-image');
		const videoWrap = preview.querySelector('.upload-preview-video-wrap');
		const video = preview.querySelector('.upload-preview-video');
		const controls = preview.querySelector('.upload-preview-controls');
		const zoom = preview.querySelector('.upload-preview-zoom');
		const horizontal = preview.querySelector('.upload-preview-x');
		const vertical = preview.querySelector('.upload-preview-y');
		const message = preview.querySelector('.upload-preview-message');
		let imageUrl = null;
		let videoUrl = null;
		let imageReady = false;
		let submitInProgress = false;
		let drag = null;
		let frameRatio = 1;

		const drawPreview = () => {
			if (!imageReady) {
				return;
			}

			const width = frame.clientWidth;
			const height = frame.clientHeight;
			const fitScale = Math.max(width / image.naturalWidth, height / image.naturalHeight);
			const scale = fitScale * Number(zoom.value);
			const renderedWidth = image.naturalWidth * scale;
			const renderedHeight = image.naturalHeight * scale;
			const xOverflow = Math.max(0, renderedWidth - width);
			const yOverflow = Math.max(0, renderedHeight - height);
			image.style.width = `${renderedWidth}px`;
			image.style.height = `${renderedHeight}px`;
			image.style.left = `${-xOverflow * Number(horizontal.value) / 100}px`;
			image.style.top = `${-yOverflow * Number(vertical.value) / 100}px`;
		};

		const clearPreview = () => {
			preview.hidden = true;
			imageReady = false;
			image.removeAttribute('src');
			video.pause();
			video.removeAttribute('src');
			video.load();
			if (imageUrl !== null) {
				URL.revokeObjectURL(imageUrl);
				imageUrl = null;
			}
			if (videoUrl !== null) {
				URL.revokeObjectURL(videoUrl);
				videoUrl = null;
			}
			message.textContent = '';
		};

		input.addEventListener('change', () => {
			clearPreview();
			const file = input.files?.[0];
			if (!file) {
				return;
			}
			const isVideo = file.type.startsWith('video/') || /\.(?:mp4|webm|mov)$/i.test(file.name);

			preview.hidden = false;
			if (isVideo) {
				frame.hidden = true;
				controls.hidden = true;
				videoWrap.hidden = false;
				videoUrl = URL.createObjectURL(file);
				video.src = videoUrl;
				return;
			}
			if (!isImageFile(file)) {
				message.textContent = 'This file type cannot be previewed.';
				return;
			}

			frame.hidden = false;
			controls.hidden = false;
			videoWrap.hidden = true;
			zoom.value = '1';
			horizontal.value = '50';
			vertical.value = '50';
			imageUrl = URL.createObjectURL(file);
			image.onload = () => {
				frameRatio = image.naturalWidth / image.naturalHeight;
				frame.classList.toggle('is-square', isProfileImage);
				if (!isProfileImage) {
					frame.style.aspectRatio = String(frameRatio);
					frame.style.width = `${Math.min(480, 640 * frameRatio)}px`;
				}
				imageReady = true;
				message.textContent = file.type === 'image/gif'
					? 'Cropping an animated GIF saves its first frame as a still photo.'
					: 'Drag the photo or adjust the controls to frame it.';
				drawPreview();
			};
			image.onerror = () => {
				message.textContent = 'This photo could not be previewed. Please choose another file.';
			};
			image.src = imageUrl;
		});

		[zoom, horizontal, vertical].forEach((control) => {
			control.addEventListener('input', drawPreview);
		});

		frame.addEventListener('pointerdown', (event) => {
			if (!imageReady) {
				return;
			}
			drag = {
				pointerId: event.pointerId,
				x: event.clientX,
				y: event.clientY,
				horizontal: Number(horizontal.value),
				vertical: Number(vertical.value),
			};
			frame.setPointerCapture(event.pointerId);
			event.preventDefault();
		});
		frame.addEventListener('pointermove', (event) => {
			if (drag === null || drag.pointerId !== event.pointerId) {
				return;
			}
			const widthOverflow = Math.max(1, image.clientWidth - frame.clientWidth);
			const heightOverflow = Math.max(1, image.clientHeight - frame.clientHeight);
			horizontal.value = String(Math.min(100, Math.max(0,
				drag.horizontal - (event.clientX - drag.x) / widthOverflow * 100
			)));
			vertical.value = String(Math.min(100, Math.max(0,
				drag.vertical - (event.clientY - drag.y) / heightOverflow * 100
			)));
			drawPreview();
		});
		const stopDragging = () => {
			drag = null;
		};
		frame.addEventListener('pointerup', stopDragging);
		frame.addEventListener('pointercancel', stopDragging);

		form.addEventListener('submit', async (event) => {
			if (submitInProgress) {
				return;
			}
			const file = input.files?.[0];
			if (!file || !isImageFile(file)) {
				return;
			}

			event.preventDefault();
			if (!imageReady) {
				message.textContent = 'Wait for the photo preview to finish loading before submitting.';
				return;
			}

			try {
				const width = frame.clientWidth;
				const height = frame.clientHeight;
				const fitScale = Math.max(width / image.naturalWidth, height / image.naturalHeight);
				const scale = fitScale * Number(zoom.value);
				const renderedWidth = image.naturalWidth * scale;
				const renderedHeight = image.naturalHeight * scale;
				const sourceX = (renderedWidth - width) * Number(horizontal.value) / 100 / scale;
				const sourceY = (renderedHeight - height) * Number(vertical.value) / 100 / scale;
				const sourceWidth = width / scale;
				const sourceHeight = height / scale;
				const outputMaxDimension = isProfileImage ? 1200 : 1600;
				const outputScale = Math.min(1, outputMaxDimension / Math.max(sourceWidth, sourceHeight));
				const canvas = document.createElement('canvas');
				canvas.width = Math.max(1, Math.round(sourceWidth * outputScale));
				canvas.height = Math.max(1, Math.round(sourceHeight * outputScale));
				const context = canvas.getContext('2d');
				if (!context) {
					throw new Error('Photo editing is not available in this browser.');
				}
				context.drawImage(
					image,
					sourceX, sourceY, sourceWidth, sourceHeight,
					0, 0, canvas.width, canvas.height
				);

				const mimeType = file.type === 'image/gif' ? 'image/png' : file.type || 'image/png';
				const blob = await new Promise((resolve, reject) => {
					canvas.toBlob((result) => {
						if (result) {
							resolve(result);
						} else {
							reject(new Error('The edited photo could not be prepared for upload.'));
						}
					}, mimeType, 0.92);
				});
				const extension = blob.type === 'image/png'
					? 'png'
					: blob.type === 'image/webp' ? 'webp' : 'jpg';
				const editedFile = new File(
					[blob],
					`${file.name.replace(/\.[^.]+$/, '')}-cropped.${extension}`,
					{ type: blob.type, lastModified: Date.now() }
				);
				const transfer = new DataTransfer();
				transfer.items.add(editedFile);
				input.files = transfer.files;
				submitInProgress = true;
				if (event.submitter instanceof HTMLElement) {
					form.requestSubmit(event.submitter);
				} else {
					form.requestSubmit();
				}
			} catch (error) {
				message.textContent = error instanceof Error
					? error.message
					: 'The edited photo could not be prepared for upload.';
			}
		});

		window.addEventListener('resize', drawPreview);
	});
})();
