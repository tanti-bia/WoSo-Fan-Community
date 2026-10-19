document.addEventListener('DOMContentLoaded', () => {
	const messageList = document.getElementById('community-message-list');
	if (messageList) {
		messageList.scrollTop = messageList.scrollHeight;
	}

	const activeChatLink = document.querySelector('.community-chat-link.is-active');
	const chatThread = document.querySelector('.community-chat-thread');
	const chatContent = chatThread?.querySelector('[data-chat-content]');
	const minimizedChat = chatThread?.querySelector('[data-chat-minimized]');
	const reopenChatButton = chatThread?.querySelector('[data-chat-reopen]');

	if (activeChatLink && chatThread && chatContent && minimizedChat) {
		const setMinimized = (minimized) => {
			chatThread.classList.toggle('is-minimized', minimized);
			chatContent.hidden = minimized;
			minimizedChat.hidden = !minimized;
			activeChatLink.setAttribute('aria-expanded', String(!minimized));
		};

		activeChatLink.setAttribute('aria-controls', 'community-chat-content');
		activeChatLink.setAttribute('aria-expanded', 'true');
		activeChatLink.addEventListener('click', (event) => {
			event.preventDefault();
			setMinimized(!chatThread.classList.contains('is-minimized'));
		});
		reopenChatButton?.addEventListener('click', () => setMinimized(false));
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
