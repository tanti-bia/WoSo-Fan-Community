document.addEventListener('DOMContentLoaded', () => {
	const times = document.querySelectorAll('time[data-local-match-time="true"]');
	if (times.length === 0) return;

	let timeZone;
	try {
		timeZone = Intl.DateTimeFormat().resolvedOptions().timeZone;
	} catch {
		return;
	}
	if (!timeZone) return;

	const formatter = new Intl.DateTimeFormat('en', {
		weekday: 'short',
		month: 'short',
		day: 'numeric',
		hour: 'numeric',
		minute: '2-digit',
		timeZone,
		timeZoneName: 'short'
	});

	for (const time of times) {
		const kickoff = new Date(time.dateTime);
		if (!Number.isNaN(kickoff.getTime())) {
			time.textContent = formatter.format(kickoff);
			time.setAttribute('aria-label', `${formatter.format(kickoff)} (${timeZone})`);
		}
	}
});
