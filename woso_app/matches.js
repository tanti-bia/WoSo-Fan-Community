(() => {
	'use strict';

	const tabs = Array.from(document.querySelectorAll('[data-match-view]'));
	const panels = {
		'live-upcoming': document.querySelector('#live-upcoming-panel'),
		finished: document.querySelector('#finished-panel'),
	};

	const selectView = (view, focusTab = false) => {
		tabs.forEach((tab) => {
			const selected = tab.dataset.matchView === view;
			tab.classList.toggle('is-active', selected);
			tab.setAttribute('aria-selected', String(selected));
			tab.tabIndex = selected ? 0 : -1;
			if (selected && focusTab) {
				tab.focus();
			}
		});
		Object.entries(panels).forEach(([panelView, panel]) => {
			panel.hidden = panelView !== view;
		});
	};

	tabs.forEach((tab, index) => {
		tab.addEventListener('click', () => {
			selectView(tab.dataset.matchView);
		});
		tab.addEventListener('keydown', (event) => {
			if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') {
				return;
			}
			event.preventDefault();
			const direction = event.key === 'ArrowRight' ? 1 : -1;
			const nextIndex = (index + direction + tabs.length) % tabs.length;
			selectView(tabs[nextIndex].dataset.matchView, true);
		});
	});
})();
