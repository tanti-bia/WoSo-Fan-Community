document.addEventListener('DOMContentLoaded', () => {
	const messageList = document.getElementById('community-message-list');
	if (messageList) {
		messageList.scrollTop = messageList.scrollHeight;
	}
});
