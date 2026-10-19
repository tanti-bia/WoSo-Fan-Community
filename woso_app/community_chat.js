document.addEventListener('DOMContentLoaded', () => {
	const initializeUsernameSuggestions = (input, suggestions, onSelect, isDisabled = () => false) => {
		const suggestionButtons = [...suggestions.querySelectorAll('.community-user-suggestion')];
		let visibleSuggestions = [];
		let activeSuggestionIndex = -1;

		const closeSuggestions = () => {
			suggestions.hidden = true;
			input.setAttribute('aria-expanded', 'false');
			input.removeAttribute('aria-activedescendant');
			visibleSuggestions = [];
			activeSuggestionIndex = -1;
		};

		const selectSuggestion = (button) => {
			onSelect(button);
			input.focus();
			closeSuggestions();
		};

		const showActiveSuggestion = () => {
			suggestionButtons.forEach((button) => button.classList.remove('is-active'));
			const activeButton = visibleSuggestions[activeSuggestionIndex];
			if (!activeButton) {
				input.removeAttribute('aria-activedescendant');
				return;
			}
			activeButton.classList.add('is-active');
			input.setAttribute('aria-activedescendant', activeButton.id);
		};

		const updateSuggestions = () => {
			const query = input.value.trim().replace(/^@/, '').toLocaleLowerCase();
			visibleSuggestions = [];
			suggestionButtons.forEach((button) => {
				button.hidden = true;
				button.classList.remove('is-active');
			});
			activeSuggestionIndex = -1;

			if (!query) {
				closeSuggestions();
				return;
			}

			for (const button of suggestionButtons) {
				const username = (button.dataset.username || '').toLocaleLowerCase();
				if (!isDisabled(button) && username.startsWith(query) && visibleSuggestions.length < 8) {
					button.hidden = false;
					visibleSuggestions.push(button);
				}
			}

			suggestions.hidden = visibleSuggestions.length === 0;
			input.setAttribute('aria-expanded', String(visibleSuggestions.length > 0));
		};

		suggestionButtons.forEach((button, index) => {
			button.id = `${suggestions.id}-option-${index}`;
			button.addEventListener('click', () => selectSuggestion(button));
		});
		input.addEventListener('input', updateSuggestions);
		input.addEventListener('focus', updateSuggestions);
		input.addEventListener('keydown', (event) => {
			if (event.key === 'Escape') {
				closeSuggestions();
				return;
			}
			if (!visibleSuggestions.length) {
				return;
			}
			if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
				event.preventDefault();
				const direction = event.key === 'ArrowDown' ? 1 : -1;
				activeSuggestionIndex = activeSuggestionIndex < 0
					? (direction > 0 ? 0 : visibleSuggestions.length - 1)
					: (activeSuggestionIndex + direction + visibleSuggestions.length) % visibleSuggestions.length;
				showActiveSuggestion();
			} else if (event.key === 'Enter' && activeSuggestionIndex >= 0) {
				event.preventDefault();
				selectSuggestion(visibleSuggestions[activeSuggestionIndex]);
			}
		});
		document.addEventListener('click', (event) => {
			if (!(event.target instanceof Element) || !event.target.closest('.community-user-search')) {
				closeSuggestions();
			}
		});
	};

	const directUsernameInput = document.getElementById('direct-username');
	const directUserSuggestions = document.getElementById('direct-user-suggestions');
	if (directUsernameInput && directUserSuggestions) {
		initializeUsernameSuggestions(directUsernameInput, directUserSuggestions, (button) => {
			directUsernameInput.value = button.dataset.username || '';
		});
	}

	const groupForm = document.querySelector('.community-group-create form');
	const groupMemberInput = document.getElementById('group-member-search');
	const groupMemberSuggestions = document.getElementById('group-member-suggestions');
	const selectedMembers = document.getElementById('group-selected-members');
	if (groupForm && groupMemberInput && groupMemberSuggestions && selectedMembers) {
		const selectedIds = new Set();
		const addGroupMember = (button) => {
			const userId = button.dataset.userId || '';
			const username = button.dataset.username || '';
			if (!userId || selectedIds.has(userId)) {
				return;
			}
			if (selectedIds.size >= 30) {
				groupMemberInput.setCustomValidity('A group can have up to 30 other members.');
				groupMemberInput.reportValidity();
				return;
			}

			groupMemberInput.setCustomValidity('');
			selectedIds.add(userId);
			const hiddenInput = document.createElement('input');
			hiddenInput.type = 'hidden';
			hiddenInput.name = 'member_ids[]';
			hiddenInput.value = userId;
			groupForm.append(hiddenInput);

			const memberChip = document.createElement('span');
			memberChip.className = 'community-selected-member';
			const memberName = document.createElement('span');
			memberName.textContent = `@${username}`;
			const removeButton = document.createElement('button');
			removeButton.type = 'button';
			removeButton.className = 'community-selected-member-remove';
			removeButton.setAttribute('aria-label', `Remove @${username}`);
			removeButton.textContent = '×';
			removeButton.addEventListener('click', () => {
				selectedIds.delete(userId);
				hiddenInput.remove();
				memberChip.remove();
				groupMemberInput.setCustomValidity('');
			});
			memberChip.append(memberName, removeButton);
			selectedMembers.append(memberChip);
			groupMemberInput.value = '';
		};

		groupMemberInput.addEventListener('input', () => groupMemberInput.setCustomValidity(''));
		groupForm.addEventListener('submit', (event) => {
			if (selectedIds.size === 0) {
				event.preventDefault();
				groupMemberInput.setCustomValidity('Choose at least one member from the suggestions.');
				groupMemberInput.reportValidity();
				groupMemberInput.focus();
			}
		});
		initializeUsernameSuggestions(
			groupMemberInput,
			groupMemberSuggestions,
			addGroupMember,
			(button) => selectedIds.has(button.dataset.userId || '')
		);
	}

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

	const chatMediaInput = document.getElementById('chat-media');
	const attachmentName = document.getElementById('chat-attachment-name');
	if (chatMediaInput && attachmentName) {
		chatMediaInput.addEventListener('change', () => {
			attachmentName.textContent = chatMediaInput.files?.[0]?.name || '';
		});
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
