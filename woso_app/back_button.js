(() => {
	'use strict';

	const currentPath = window.location.pathname;
	const isMainFeed = currentPath.endsWith('/home.php') && window.location.search === '';
	if (isMainFeed) {
		return;
	}

	const backBar = document.createElement('div');
	backBar.className = 'page-back-bar';

	const backButton = document.createElement('button');
	backButton.className = 'page-back-button';
	backButton.type = 'button';
	backButton.textContent = 'Back';
	backButton.addEventListener('click', () => {
		if (window.history.length > 1) {
			window.history.back();
			return;
		}
		window.location.href = 'home.php';
	});

	backBar.append(backButton);
	const navigation = document.querySelector('.topnav');
	const main = document.querySelector('main');
	if (main) {
		backBar.classList.add('is-in-content');
		const heading = main.querySelector('.home-heading, .match-center-heading');
		(heading || main).prepend(backBar);
	} else if (navigation) {
		backBar.classList.add('is-outside-content');
		navigation.after(backBar);
	} else {
		backBar.classList.add('is-outside-content');
		document.body.prepend(backBar);
	}
})();
