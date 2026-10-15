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
	if (navigation) {
		navigation.after(backBar);
	} else if (main) {
		main.before(backBar);
	} else {
		document.body.prepend(backBar);
	}
})();
