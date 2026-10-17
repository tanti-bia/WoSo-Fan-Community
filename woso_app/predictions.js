document.addEventListener('DOMContentLoaded', () => {
	const form = document.querySelector('.prediction-form');
	if (!form) return;

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
