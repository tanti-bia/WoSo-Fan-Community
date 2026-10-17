document.addEventListener('DOMContentLoaded', () => {
	const tabs = Array.from(document.querySelectorAll('[data-prediction-view]'));
	const panels = {
		make: document.querySelector('#make-predictions-panel'),
		results: document.querySelector('#view-results-panel'),
	};
	const selectView = (view, focusTab = false) => {
		tabs.forEach((tab) => {
			const selected = tab.dataset.predictionView === view;
			tab.classList.toggle('is-active', selected);
			tab.setAttribute('aria-selected', String(selected));
			tab.tabIndex = selected ? 0 : -1;
			if (selected && focusTab) tab.focus();
		});
		Object.entries(panels).forEach(([panelView, panel]) => {
			if (panel) panel.hidden = panelView !== view;
		});
	};
	tabs.forEach((tab, index) => {
		tab.addEventListener('click', () => selectView(tab.dataset.predictionView));
		tab.addEventListener('keydown', (event) => {
			if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return;
			event.preventDefault();
			const direction = event.key === 'ArrowRight' ? 1 : -1;
			const nextIndex = (index + direction + tabs.length) % tabs.length;
			selectView(tabs[nextIndex].dataset.predictionView, true);
		});
	});

	const formItem = document.querySelector('[data-prediction-form-item]');
	const form = formItem?.querySelector('.prediction-form');
	if (!formItem || !form) return;

	const selectedMatch = form.querySelector('[data-prediction-selected]');
	const eventIdInput = form.querySelector('[data-prediction-event-id]');
	const homeScoreInput = form.querySelector('[data-prediction-home-score]');
	const awayScoreInput = form.querySelector('[data-prediction-away-score]');
	let selectedCard = null;

	const selectMatch = (card) => {
		if (selectedCard) {
			selectedCard.classList.remove('is-prediction-selected');
			selectedCard.setAttribute('aria-pressed', 'false');
		}
		selectedCard = card;
		selectedCard.classList.add('is-prediction-selected');
		selectedCard.setAttribute('aria-pressed', 'true');
		selectedCard.after(formItem);
		formItem.hidden = false;
		eventIdInput.value = card.dataset.predictionMatch || '';
		selectedMatch.textContent = `${card.dataset.homeTeam} vs ${card.dataset.awayTeam}`;
		homeScoreInput.value = card.dataset.savedHomeScore || '';
		awayScoreInput.value = card.dataset.savedAwayScore || '';
		form.scrollIntoView({ behavior: 'smooth', block: 'center' });
		homeScoreInput.focus({ preventScroll: true });
	};

	document.querySelectorAll('[data-prediction-match]').forEach((card) => {
		card.addEventListener('click', (event) => {
			if (event.target.closest('a, button, input, select, textarea')) return;
			selectMatch(card);
		});
		card.addEventListener('keydown', (event) => {
			if ((event.key === 'Enter' || event.key === ' ') && !event.target.closest('a, button')) {
				event.preventDefault();
				selectMatch(card);
			}
		});
	});
});
