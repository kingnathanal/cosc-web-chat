function buildWebSocketUrl() {
    const config = window.BOXCHAT_CONFIG || {};
    if (config.wsUrl) {
        return config.wsUrl;
    }

    const protocol = window.location.protocol === 'https' ? 'wss' : 'ws';
    const host = config.wsHost || window.location.hostname;
    const port = config.wsPort ?? 8080;
    const path = config.wsPath || '/';
    const portSegment = (!port || port === 0 || port === '' || port === '0') ? '' : `:${port}`;
    return `${protocol}://${host}${portSegment}${path.startsWith('/') ? path : `/${path}`}`;
}

function ensureSocketConnected() {
    if (!currentUser) {
        resetSocket(false);
        return;
    }
    if (typeof WebSocket === 'undefined') {
        console.error('WebSocket not supported in this browser.');
        return;
    }
    if (chatSocket && (chatSocket.readyState === WebSocket.OPEN || chatSocket.readyState === WebSocket.CONNECTING)) {
        return;
    }
    connectSocket();
}

function connectSocket() {
    resetSocket(false);

    try {
        chatSocket = new WebSocket(buildWebSocketUrl());
    } catch (err) {
        console.error('WebSocket connection failed', err);
        scheduleReconnect();
        return;
    }

    chatSocket.onopen = () => {
        socketReady = true;
        flushSocketQueue();
        if (pendingJoinRoomId) {
            sendSocketMessage('join_room', { roomId: pendingJoinRoomId });
        } else if (currentRoomId) {
            pendingJoinRoomId = currentRoomId;
            sendSocketMessage('join_room', { roomId: currentRoomId });
        }
    };

    chatSocket.onmessage = (event) => {
        try {
            handleSocketMessage(JSON.parse(event.data));
        } catch (err) {
            console.error('Failed to parse socket message', err, event.data);
        }
    };

    chatSocket.onclose = () => {
        socketReady = false;
        chatSocket = null;
        if (currentUser) {
            pendingJoinRoomId = pendingJoinRoomId || currentRoomId;
        }
        scheduleReconnect();
    };

    chatSocket.onerror = (event) => {
        console.error('WebSocket error', event);
    };
}

function scheduleReconnect() {
    if (!currentUser) {
        return;
    }
    if (reconnectTimer) {
        return;
    }
    reconnectTimer = setTimeout(() => {
        reconnectTimer = null;
        connectSocket();
    }, WS_RECONNECT_DELAY);
}

function resetSocket(close = true) {
    if (reconnectTimer) {
        clearTimeout(reconnectTimer);
        reconnectTimer = null;
    }
    if (chatSocket) {
        if (close && chatSocket.readyState === WebSocket.OPEN) {
            try {
                chatSocket.close();
            } catch (_) {}
        }
        chatSocket = null;
    }
    socketReady = false;
    socketQueue = [];
}

function flushSocketQueue() {
    if (!socketReady || !chatSocket || chatSocket.readyState !== WebSocket.OPEN) {
        return;
    }
    while (socketQueue.length > 0) {
        const payload = socketQueue.shift();
        chatSocket.send(JSON.stringify(payload));
    }
}

function sendSocketMessage(type, payload = {}) {
    const envelope = { type, ...payload };

    if (socketReady && chatSocket && chatSocket.readyState === WebSocket.OPEN) {
        chatSocket.send(JSON.stringify(envelope));
        return;
    }

    socketQueue.push(envelope);
    ensureSocketConnected();
}

function handleSocketMessage(message) {
    if (!message || typeof message.type !== 'string') {
        return;
    }

    switch (message.type) {
        case 'welcome':
            break;
        case 'ping':
            sendSocketMessage('pong');
            break;
        case 'pong':
            break;
        case 'room_joined':
            onRoomJoined(message);
            break;
        case 'room_left':
            onRoomLeft(message);
            break;
        case 'message':
            if (message.roomId === currentRoomId && message.message) {
                appendCombinedMessages([message.message], [], false);
            }
            break;
        case 'direct_message':
            if (message.roomId === currentRoomId && message.message) {
                appendCombinedMessages([], [message.message], false);
            }
            break;
        case 'presence':
            break;
        case 'error':
            displaySocketError(message);
            break;
        default:
            console.warn('Unknown socket event', message);
    }
}

function displaySocketError(message) {
    const errorText = message.error || 'WebSocket error';
    const detail = message.missing ? ` Missing: ${message.missing.join(', ')}` : '';
    if (message.context === 'dm') {
        $('#dmError').text(`${errorText}${detail}`);
        $('#dmPanel').show();
        return;
    }
    if (message.context === 'join') {
        pendingJoinRoomId = null;
        currentRoomId = null;
        $('.messages').empty();
        $('.chat-ui').hide();
        $('.chat-placeholder').show();
        $('#leaveRoomBtn').hide();
        updateRoomButtons();
    }
    alert(`${errorText}${detail}`);
}

function onRoomJoined(payload) {
    const roomId = Number(payload.roomId || 0);
    if (!roomId) {
        return;
    }

    currentRoomId = roomId;
    pendingJoinRoomId = null;
    lastMessageId = 0;
    lastDmId = 0;

    $('.chat-placeholder').hide();
    $('.chat-ui').show();
    $('#leaveRoomBtn').show();
    if (payload.roomName) {
        $('#currentRoomTitle').text(payload.roomName);
    }

    $('.messages').empty();
    appendCombinedMessages(payload.messages || [], payload.dms || [], true);
    updateRoomButtons();
    loadRooms().catch(() => {});
}

function onRoomLeft(payload) {
    const roomId = Number(payload.roomId || 0);
    if (!roomId || roomId !== currentRoomId) {
        return;
    }

    currentRoomId = null;
    pendingJoinRoomId = null;
    $('.messages').empty();
    $('.chat-ui').hide();
    $('.chat-placeholder').show();
    $('#leaveRoomBtn').hide();
    $('#currentRoomTitle').text('Chatroom');
    updateRoomButtons();
}
