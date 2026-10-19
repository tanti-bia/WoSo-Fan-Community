document.addEventListener('DOMContentLoaded', () => {
	const messageList = document.getElementById('community-message-list');
	if (messageList) {
		messageList.scrollTop = messageList.scrollHeight;
	}

	const messageInput = document.getElementById('message-body');
	if (!messageInput) {
		return;
	}

	const resizeMessageInput = () => {
		messageInput.style.height = 'auto';
		const inputStyle = window.getComputedStyle(messageInput);
		const lineHeight = Number.parseFloat(inputStyle.lineHeight) || 22;
		const verticalPadding = Number.parseFloat(inputStyle.paddingTop) + Number.parseFloat(inputStyle.paddingBottom);
		const verticalBorders = Number.parseFloat(inputStyle.borderTopWidth) + Number.parseFloat(inputStyle.borderBottomWidth);
		const maxHeight = (lineHeight * 3) + verticalPadding + verticalBorders;
		messageInput.style.height = `${Math.min(messageInput.scrollHeight, maxHeight)}px`;
		messageInput.style.overflowY = messageInput.scrollHeight > maxHeight ? 'auto' : 'hidden';
	};

	messageInput.addEventListener('input', resizeMessageInput);
	resizeMessageInput();
});
