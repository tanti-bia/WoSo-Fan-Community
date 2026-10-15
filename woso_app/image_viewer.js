(() => {
	'use strict';

	const imageViewer = document.createElement('dialog');
	imageViewer.className = 'image-viewer';
	imageViewer.setAttribute('aria-label', 'Enlarged photo');
	imageViewer.innerHTML = `
		<button class="image-viewer-close" type="button" aria-label="Close enlarged photo">&times;</button>
		<img class="image-viewer-photo" alt="">
	`;
	document.body.append(imageViewer);

	const imageViewerPhoto = imageViewer.querySelector('.image-viewer-photo');
	let originalImageWidth = 0;
	let originalImageHeight = 0;

	const resizeImageViewerPhoto = () => {
		if (imageViewerPhoto.naturalWidth === 0 || imageViewerPhoto.naturalHeight === 0) {
			return;
		}

		const maxWidth = Math.min(window.innerWidth * 0.92, 1200);
		const maxHeight = Math.min(window.innerHeight * 0.84, 900);
		const scale = Math.min(
			maxWidth / imageViewerPhoto.naturalWidth,
			maxHeight / imageViewerPhoto.naturalHeight,
			1.5 * originalImageWidth / imageViewerPhoto.naturalWidth,
			1.5 * originalImageHeight / imageViewerPhoto.naturalHeight
		);
		imageViewerPhoto.style.width = `${imageViewerPhoto.naturalWidth * scale}px`;
	};

	imageViewerPhoto.addEventListener('load', resizeImageViewerPhoto);

	document.addEventListener('click', (event) => {
		const target = event.target;
		if (!(target instanceof Element)) {
			return;
		}

		let photo = target.closest('img:not(.image-viewer-photo)');
		if (!photo) {
			const imageControl = target.closest('.post-image-button, .post-author-avatar-link');
			photo = imageControl?.querySelector('img') ?? null;
		}
		if (!photo) {
			return;
		}

		event.preventDefault();
		event.stopPropagation();
		const photoBounds = photo.getBoundingClientRect();
		originalImageWidth = photoBounds.width;
		originalImageHeight = photoBounds.height;
		imageViewerPhoto.alt = photo.alt || 'Enlarged photo';
		imageViewerPhoto.style.width = '';
		imageViewerPhoto.src = photo.currentSrc || photo.src;
		imageViewer.showModal();
		if (imageViewerPhoto.complete) {
			resizeImageViewerPhoto();
		}
	}, true);

	imageViewer.querySelector('.image-viewer-close').addEventListener('click', () => {
		imageViewer.close();
	});

	imageViewer.addEventListener('click', (event) => {
		if (event.target === imageViewer) {
			imageViewer.close();
		}
	});

	imageViewer.addEventListener('close', () => {
		imageViewerPhoto.removeAttribute('src');
		imageViewerPhoto.alt = '';
		imageViewerPhoto.style.width = '';
	});
})();
