function appendCombinedMessages(messages, dms, replace) {
    if (!Array.isArray(messages) || messages.length === 0) {
        messages = [];
    }
    if (!Array.isArray(dms) || dms.length === 0) { dms = []; }

    const $messages = $('.messages');

    if (replace) {
        $messages.empty();
    }

    // Merge and sort by createdAt ascending
    const merged = [];
    for (const m of messages) merged.push({ ...m, isDM: false });
    for (const d of dms) merged.push({ ...d, isDM: true });
    merged.sort((a, b) => new Date(a.createdAt) - new Date(b.createdAt));

    merged.forEach((msg) => {
        const timestamp = new Date(msg.createdAt).toLocaleString();
        let html;
        if (!msg.isDM) {
            const isBroadcast = / (joined|left) the chat$/.test(String(msg.body));
            if (isBroadcast) {
                html = `<div class="mb-2 text-center text-muted"><em>${escapeHtml(msg.body)}</em></div>`;
            } else {
                const senderLabel = (currentUser && msg.sender === currentUser.screenName) ? 'Me' : msg.sender;
                html = `<div class="mb-2">
                    <strong>${escapeHtml(senderLabel)}</strong> <em>${timestamp}</em><br/>
                    <span class="badge rounded-pill text-bg-success fs-6">${escapeHtml(msg.body)}</span>
                </div>`;
            }
            lastMessageId = Math.max(lastMessageId, msg.id);
        } else {
            const senderLabel = (currentUser && msg.sender === currentUser.screenName) ? 'Me' : msg.sender;
            html = `<div class="mb-2">
                <strong class="text-primary">[DM] ${escapeHtml(senderLabel)}</strong> <em>${timestamp}</em><br/>
                <span class="badge rounded-pill text-bg-warning fs-6">${escapeHtml(msg.body)}</span>
            </div>`;
            lastDmId = Math.max(lastDmId, msg.id);
        }
        $messages.append(html);
    });

    if ($messages.length > 0) {
        $messages.scrollTop($messages[0].scrollHeight);
    }
}

function sendChat() {
    const message = $('#messageInput').val();

    if (!currentRoomId) {
        alert('Select a room before sending messages.');
        return;
    }

    if (!message || message.trim() === '') {
        alert('Message cannot be empty.');
        return;
    }

    sendSocketMessage('chat_message', {
        roomId: currentRoomId,
        body: message.trim(),
    });
    $('#messageInput').val('');
}

async function sendDM() {
    const message = $('#messageInput').val();
    const namesRaw = $('#dmRecipients').val();
    $('#dmError').text('');

    if (!currentRoomId) {
        alert('Select a room before sending messages.');
        return;
    }
    if (!message || message.trim() === '') {
        $('#dmError').text('Type a message to send.');
        return;
    }
    const recipients = (namesRaw || '').split(',').map(s => s.trim()).filter(Boolean);
    if (recipients.length === 0) {
        $('#dmError').text('Enter at least one screen name.');
        return;
    }

    try {
        sendSocketMessage('direct_message', {
            roomId: currentRoomId,
            body: message.trim(),
            recipients,
        });
        $('#dmRecipients').val('');
        $('#dmPanel').hide();
        $('#messageInput').val('');
    } catch (error) {
        $('#dmError').text(error?.message || 'Unable to send DM.');
    }
}
