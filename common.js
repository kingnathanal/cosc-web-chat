const API_BASE = './api';
const SOCKET_BASE_DELAY_MS = 1000;
const SOCKET_MAX_DELAY_MS = 20000;

// Port used for the simple WebSocket chat server.  The professor’s sample
// server listens on 8080; adjust this constant if your server runs on a
// different port.  The protocol (ws or wss) is chosen based on the current
// page’s protocol.
const WS_PORT = 8080;

let currentUser = null;
let currentRoomId = null;
let lastMessageId = 0;
let lastDmId = 0;
let roomsEventSource = null;
let roomsRefreshInterval = null;

let chatSocket = null;
let chatSocketToken = null;
let chatSocketEndpoint = null;
let chatSocketShouldReconnect = false;
let chatSocketReconnectTimer = null;
let chatSocketBackoff = SOCKET_BASE_DELAY_MS;
let chatSocketConnectPromise = null;

const socketPendingRequests = new Map();
let socketRequestCounter = 1;
const roomPassphrases = new Map();
let updateRoomButtons = function () {};

function toLogin() {
    window.location.href = 'login.php';
}

function toSignup() {
    window.location.href = 'signup.php';
}

function toIndex() {
    window.location.href = 'index.php';
}

async function logout() {
    try {
        // No WebSocket leave notification is needed for the basic server
        // implementation when logging out.
    } catch (_) {}
    disconnectChatSocket(true);
    try {
        await apiRequest('logout.php', { method: 'POST' });
    } catch (err) {
        // Even if logout fails, fallback to clearing state locally.
        console.warn('Logout request failed', err);
    } finally {
        currentUser = null;
        currentRoomId = null;
        lastMessageId = 0;
        lastDmId = 0;
        roomPassphrases.clear();
        $('.messages').empty();
        setNavState();
        window.location.href = 'index.php';
    }
}

async function login() {
    const username = $('#username').val().trim();
    const password = $('#password').val();
    const $errorBox = $('#loginError');

    $errorBox.hide().text('');

    if (username === '' || password === '') {
        $errorBox.text('Username and password are required.').show();
        return;
    }

    try {
        const response = await apiRequest('login.php', {
            method: 'POST',
            body: { username, password },
        });
        currentUser = response.user;
        setNavState();
        window.location.href = 'index.php';
    } catch (error) {
        const message = error.status === 401 ? 'Invalid username or password.' : (error.data?.error || 'Login failed.');
        $errorBox.text(message).show();
    }
}

async function registerAccount() {
    const payload = {
        firstName: $('#first_name').val().trim(),
        lastName: $('#last_name').val().trim(),
        username: $('#user_name').val().trim(),
        email: $('#email').val().trim(),
        password: $('#password1').val(),
        passwordConfirm: $('#password2').val(),
    };

    clearSignupErrors();

    const $errorBanner = $('#registerError');
    $errorBanner.hide().text('');

    try {
        await apiRequest('signup.php', {
            method: 'POST',
            body: payload,
        });
        $errorBanner.removeClass('text-danger').addClass('text-success').text('Account created! Redirecting to login...').show();
        setTimeout(() => toLogin(), 1200);
    } catch (error) {
        if (error.status === 422 && error.data?.fields) {
            showSignupFieldErrors(error.data.fields);
        } else {
            const message = error.data?.error || 'Unable to create account.';
            $errorBanner.addClass('text-danger').text(message).show();
        }
    }
}

async function apiRequest(path, { method = 'GET', body = null } = {}) {
    const options = {
        method,
        credentials: 'same-origin',
        headers: {},
    };

    if (body !== null) {
        options.headers['Content-Type'] = 'application/json';
        options.body = JSON.stringify(body);
    }

    const response = await fetch(`${API_BASE}/${path}`, options);
    let data = {};
    const text = await response.text();
    if (text) {
        try {
            data = JSON.parse(text);
        } catch {
            throw new Error('Invalid server response');
        }
    }

    if (!response.ok) {
        const error = new Error(data.error || 'Request failed');
        error.status = response.status;
        error.data = data;
        throw error;
    }

    return data;
}

function createSocketError(message, details = null) {
    const error = new Error(message);
    if (details !== null && typeof details === 'object') {
        error.details = details;
    }
    return error;
}

function clearSocketPendingRequests(reasonError = null) {
    socketPendingRequests.forEach(({ reject, timeout }) => {
        clearTimeout(timeout);
        if (typeof reject === 'function') {
            reject(reasonError ?? createSocketError('Chat connection closed'));
        }
    });
    socketPendingRequests.clear();
}

async function ensureChatSocketCredentials() {
    if (chatSocketToken && chatSocketEndpoint) {
        return;
    }

    let response;
    try {
        response = await apiRequest('socket_token.php', { method: 'POST' });
    } catch (error) {
        if (error?.status === 401) {
            toLogin();
        }
        throw createSocketError('Unable to obtain chat connection token', { error });
    }

    const token = typeof response?.token === 'string' ? response.token.trim() : '';
    if (!token) {
        throw createSocketError('Chat connection token missing from server response');
    }
    chatSocketToken = token;

    const endpointRaw = typeof response?.endpoint === 'string' ? response.endpoint.trim() : '';
    if (endpointRaw) {
        chatSocketEndpoint = endpointRaw;
    } else {
        const protocol = (window.location.protocol === 'https:') ? 'wss' : 'ws';
        chatSocketEndpoint = `${protocol}://${window.location.hostname}:${WS_PORT}`;
    }
}

// Connect to the chat WebSocket using the token-aware endpoint provided by
// the server. This uses `ensureChatSocketCredentials()` to obtain
// `chatSocketEndpoint` and `chatSocketToken`, then opens a WebSocket using
// `chatSocketEndpoint?token=...` so the server handshake can authenticate us.
async function connectChatSocket() {
    if (!currentUser) {
        throw createSocketError('Not authenticated');
    }

    chatSocketShouldReconnect = true;

    // Already connected?
    if (chatSocket && chatSocket.readyState === WebSocket.OPEN) {
        return;
    }

    // Another connect in-flight?
    if (chatSocketConnectPromise) {
        return chatSocketConnectPromise;
    }

    // Ensure we have an endpoint and token from the API
    chatSocketConnectPromise = (async () => {
        try {
            await ensureChatSocketCredentials();
        } catch (err) {
            // ensureChatSocketCredentials already redirects on 401; propagate
            throw createSocketError('Unable to obtain chat credentials', { error: err });
        }

        if (!chatSocketEndpoint || !chatSocketToken) {
            throw createSocketError('Chat endpoint or token missing');
        }

        // Build URL including token as query parameter (preserve existing query if present)
        const sep = chatSocketEndpoint.includes('?') ? '&' : '?';
        const wsUrl = `${chatSocketEndpoint}${sep}token=${encodeURIComponent(chatSocketToken)}`;

        return new Promise((resolve, reject) => {
            let settled = false;
            try {
                const ws = new WebSocket(wsUrl);

                const handleOpen = () => {
                    settled = true;
                    chatSocket = ws;
                    chatSocketBackoff = SOCKET_BASE_DELAY_MS;
                    resolve();
                };

                const handleMessage = (event) => {
                    handleSocketMessage(event.data);
                };

                const handleClose = (event) => {
                    ws.removeEventListener('open', handleOpen);
                    ws.removeEventListener('message', handleMessage);
                    ws.removeEventListener('close', handleClose);
                    ws.removeEventListener('error', handleError);
                    if (!settled) {
                        reject(createSocketError('Unable to establish chat connection', { event }));
                    }
                    handleSocketClose(event);
                };

                const handleError = (event) => {
                    console.error('Chat socket error', event);
                    if (!settled) {
                        reject(createSocketError('Chat connection failed', { event }));
                    }
                };

                ws.addEventListener('open', handleOpen);
                ws.addEventListener('message', handleMessage);
                ws.addEventListener('close', handleClose);
                ws.addEventListener('error', handleError);
            } catch (err) {
                reject(createSocketError('Failed to create WebSocket', { error: err }));
            }
        });
    })();

    try {
        await chatSocketConnectPromise;
    } finally {
        chatSocketConnectPromise = null;
    }
}


function scheduleSocketReconnect() {
    if (!chatSocketShouldReconnect || chatSocketReconnectTimer) {
        return;
    }

    const delay = chatSocketBackoff;
    chatSocketReconnectTimer = setTimeout(async () => {
        chatSocketReconnectTimer = null;
        try {
            await connectChatSocket();
            await rejoinActiveRoom();
        } catch (error) {
            console.warn('Chat reconnect attempt failed', error);
            if (chatSocketShouldReconnect) {
                chatSocketBackoff = Math.min(chatSocketBackoff * 2, SOCKET_MAX_DELAY_MS);
                scheduleSocketReconnect();
            }
        }
    }, delay);
}

function handleSocketClose(_event = null) {
    chatSocket = null;
    chatSocketToken = null;
    clearSocketPendingRequests(createSocketError('Chat connection closed'));

    if (!chatSocketShouldReconnect || !currentUser) {
        return;
    }

    scheduleSocketReconnect();
}

function disconnectChatSocket(force = false) {
    chatSocketShouldReconnect = !force && !!currentUser;

    if (chatSocketReconnectTimer) {
        clearTimeout(chatSocketReconnectTimer);
        chatSocketReconnectTimer = null;
    }

    if (chatSocket) {
        try {
            chatSocket.close();
        } catch (_) {}
    }

    chatSocket = null;
    chatSocketToken = null;
    clearSocketPendingRequests(createSocketError('Chat connection closed'));
}

async function sendSocketCommand(action, data = {}, expectAck = false) {
    await connectChatSocket();

    if (!chatSocket || chatSocket.readyState !== WebSocket.OPEN) {
        throw createSocketError('Chat server not connected');
    }

    const payload = Object.assign({}, data, { type: action });
    let pendingPromise = null;
    if (expectAck) {
        const requestId = socketRequestCounter++;
        payload.requestId = requestId;
        pendingPromise = new Promise((resolve, reject) => {
            const timeout = setTimeout(() => {
                socketPendingRequests.delete(requestId);
                reject(createSocketError('Chat request timed out'));
            }, 7000);
            socketPendingRequests.set(requestId, { resolve, reject, timeout });
        });
    }

    let serialized;
    try {
        serialized = JSON.stringify(payload);
    } catch (error) {
        if (expectAck && payload.requestId) {
            const pending = socketPendingRequests.get(payload.requestId);
            if (pending) {
                clearTimeout(pending.timeout);
                socketPendingRequests.delete(payload.requestId);
            }
        }
        throw createSocketError('Failed to encode message payload');
    }
    chatSocket.send(serialized);
    return pendingPromise;
}

async function rejoinActiveRoom() {
    if (!currentRoomId) {
        return;
    }

    try {
        await fetchMessages(true);
    } catch (error) {
        console.warn('Unable to refresh messages after reconnect', error);
    }

    const storedPassphrase = roomPassphrases.get(currentRoomId);
    try {
        const payload = { roomId: currentRoomId };
        if (typeof storedPassphrase === 'string' && storedPassphrase !== '') {
            payload.passphrase = storedPassphrase;
        }
        await sendSocketCommand('join', payload, true);
    } catch (error) {
        const details = error?.details || {};
        if (details.status === 401) {
            toLogin();
        } else {
            console.warn('Unable to rejoin active room over WebSocket', error);
        }
    }
}

function handleSocketMessage(raw) {
    let data;
    try {
        data = JSON.parse(raw);
    } catch (error) {
        console.warn('Received invalid socket payload', raw);
        return;
    }

    const requestId = data?.requestId;
    if (requestId && socketPendingRequests.has(requestId)) {
        const pending = socketPendingRequests.get(requestId);
        socketPendingRequests.delete(requestId);
        clearTimeout(pending.timeout);

        if (data.type === 'ack') {
            pending.resolve(data);
            return;
        }

        if (data.type === 'error') {
            pending.reject(createSocketError(data.message || 'Socket error', data));
            return;
        }

        pending.resolve(data);
    }

    if (!data || typeof data.type !== 'string') {
        return;
    }

    switch (data.type) {
        case 'chat_message':
            if (data.roomId === currentRoomId && data.message) {
                appendRealtimeMessage(Object.assign({}, data.message, { isDM: false }));
            }
            break;
        case 'dm':
            if (data.roomId === currentRoomId && data.message) {
                appendRealtimeMessage(Object.assign({}, data.message, { isDM: true }));
            }
            break;
        case 'rooms_update':
            // A rooms_update notification tells all clients to refresh the
            // available rooms list.  Re-fetch the rooms via API and update
            // the UI accordingly.  loadRooms returns a promise; log
            // failures rather than throwing.
            loadRooms().catch((e) => {
                console.warn('Rooms refresh failed', e);
            });
            break;
        case 'error':
            console.warn('Socket error event', data);
            break;
        default:
            break;
    }
}

function formatTimestamp(value) {
    if (!value) {
        return new Date().toLocaleString();
    }
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return value;
    }
    return date.toLocaleString();
}

function normalizeMessageForDisplay(msg) {
    const sender = String(msg?.sender ?? 'Someone').trim();
    const body = String(msg?.body ?? '');
    const trimmedBody = body.trim();
    const senderLower = sender.toLocaleLowerCase();
    const bodyLower = trimmedBody.toLocaleLowerCase();
    let presenceType = null;
    if (senderLower && bodyLower === `${senderLower} joined the chat`) {
        presenceType = 'join';
    } else if (senderLower && bodyLower === `${senderLower} left the chat`) {
        presenceType = 'leave';
    }

    return {
        id: Number(msg?.id) || 0,
        body,
        createdAt: msg?.createdAt || new Date().toISOString(),
        sender,
        isDM: Boolean(msg?.isDM),
        presenceType,
    };
}

function buildMessageHtml(message) {
    const normalized = normalizeMessageForDisplay(message);
    const timestamp = formatTimestamp(normalized.createdAt);
    const isSelf = isCurrentUserSender(normalized.sender);

    if (normalized.presenceType) {
        const presenceBody = normalized.presenceType === 'join'
            ? (isSelf ? 'You joined the chat' : `${normalized.sender} joined the chat`)
            : (isSelf ? 'You left the chat' : `${normalized.sender} left the chat`);
        return `<div class="my-3 text-center">
            <span class="badge bg-light text-secondary border border-secondary px-3 py-2">${escapeHtml(presenceBody)}</span>
            <div><small class="text-muted">${escapeHtml(timestamp)}</small></div>
        </div>`;
    }

    if (normalized.isDM) {
        const senderLabel = isSelf ? 'Me' : normalized.sender;
        return `<div class="mb-2">
            <strong class="text-primary">[DM] ${escapeHtml(senderLabel)}</strong> <em>${escapeHtml(timestamp)}</em><br/>
            <span class="badge rounded-pill text-bg-warning fs-6">${escapeHtml(normalized.body)}</span>
        </div>`;
    }

    const senderLabel = isSelf ? 'Me' : normalized.sender;
    const bubbleClass = isSelf ? 'text-bg-primary' : 'text-bg-light';
    const textClass = isSelf ? 'text-white' : '';

    return `<div class="mb-2">
        <strong>${escapeHtml(senderLabel)}</strong> <em>${escapeHtml(timestamp)}</em><br/>
        <span class="badge rounded-pill ${bubbleClass} ${textClass} fs-6">${escapeHtml(normalized.body)}</span>
    </div>`;
}

function appendRealtimeMessage(message) {
    const normalized = normalizeMessageForDisplay(message);
    if (normalized.isDM) {
        lastDmId = Math.max(lastDmId, normalized.id);
    } else {
        lastMessageId = Math.max(lastMessageId, normalized.id);
    }
    const $messages = $('.messages');
    $messages.append(buildMessageHtml(normalized));
    if ($messages.length && $messages[0]) {
        $messages.scrollTop($messages[0].scrollHeight);
    }
}

async function refreshSession() {
    try {
        const response = await apiRequest('session.php');
        currentUser = response.authenticated ? response.user : null;
    } catch (err) {
        console.error('Failed to refresh session', err);
        currentUser = null;
    }

    setNavState();
    handleRoomsStream();

    if (currentUser) {
        try {
            await connectChatSocket();
        } catch (error) {
            console.warn('Unable to initialize chat socket', error);
        }
    } else {
        disconnectChatSocket(true);
    }
}

function setNavState() {
    if (currentUser) {
        $('.login').hide();
        $('.signup').hide();
        $('.logout').show();
        $('.chat-container').show();
    } else {
        $('.login').show();
        $('.signup').show();
        $('.logout').hide();
        $('.chat-container').hide();
    }
}

function handleRoomsStream() {
    // Close any existing stream
    if (roomsEventSource) {
        try { roomsEventSource.close(); } catch (_) {}
        roomsEventSource = null;
    }
    if (roomsRefreshInterval) {
        clearInterval(roomsRefreshInterval);
        roomsRefreshInterval = null;
    }
    if (!currentUser) return;
    if (!('EventSource' in window)) {
        // Fallback: periodic refresh
        roomsRefreshInterval = setInterval(() => loadRooms(), 5000);
        return;
    }

    try {
        roomsEventSource = new EventSource(`${API_BASE}/rooms_sse.php`);
        roomsEventSource.addEventListener('rooms_update', async () => {
            try { await loadRooms(); } catch (e) { console.warn('Rooms refresh failed', e); }
        });
        roomsEventSource.addEventListener('error', (e) => {
            // Browser will auto-reconnect; we can also log
            console.debug('Rooms SSE error', e);
        });
    } catch (e) {
        console.warn('EventSource setup failed', e);
        // Fallback: periodic refresh
        roomsRefreshInterval = setInterval(() => loadRooms(), 5000);
    }
}

async function loadRooms() {
    try {
        const data = await apiRequest('rooms.php');
        renderRooms(data.rooms);
        updateRoomButtons();
    } catch (error) {
        if (error.status === 401) {
            toLogin();
            return;
        }
        console.error('Failed to load rooms', error);
    }
}

async function createRoom(event) {
    event.preventDefault();
    
    const roomName = $('#chatRoomName').val().trim();
    const passphrase = $('#chatRoomPassword').val();
    
    // Clear previous errors
    $('#err_chatRoomName').text('');
    
    if (roomName === '') {
        $('#err_chatRoomName').text('Room name is required.');
        return;
    }
    
    try {
        const response = await apiRequest('rooms.php', {
            method: 'POST',
            body: { 
                name: roomName, 
                passphrase: passphrase 
            },
        });
        
        // Clear form
        $('#chatRoomName').val('');
        $('#chatRoomPassword').val('');
        
        // Close the dropdown
        const dropdown = bootstrap.Dropdown.getInstance(document.querySelector('.createRoom'));
        if (dropdown) {
            dropdown.hide();
        }
        
        // Reload rooms list (do not auto-join new room)
        await loadRooms();

    } catch (error) {
        if (error.status === 401) {
            toLogin();
            return;
        }
        
        const message = error.data?.error || 'Failed to create room.';
        $('#err_chatRoomName').text(message);
    }
}

function renderRooms(rooms) {
    const $roomsList = $('#roomsList');
    $roomsList.empty();

    if (!rooms || rooms.length === 0) {
        $roomsList.append('<tr><td colspan="3" class="text-center text-muted">No rooms available.</td></tr>');
        return;
    }

    rooms.forEach((room) => {
        const statusBadge = room.status === 'open' ? 'success' : 'secondary';
        const isCurrent = (currentRoomId && room.id === currentRoomId);
        const actionCell = isCurrent
          ? '<span class="badge text-bg-info">Joined</span>'
          : `<button class="btn btn-primary btn-sm join-room-btn" data-room-id="${room.id}" data-room-name="${escapeHtml(room.name)}" data-room-locked="${room.status !== 'open'}">Join</button>`;
        const row = `
            <tr data-room-id="${room.id}" data-room-locked="${room.status !== 'open'}">
                <td>${escapeHtml(room.name)}</td>
                <td><span class="badge text-bg-${statusBadge}">${escapeHtml(room.status)}</span></td>
                <td class="room-action">${actionCell}</td>
            </tr>`;
        $roomsList.append(row);
    });
}

async function joinRoom(roomId, roomName = null, passphrase = undefined) {
    if (!roomId || currentRoomId === roomId) {
        return false;
    }

    if (!currentUser) {
        toLogin();
        return false;
    }

    // Preserve provided passphrase for this room; although the basic WebSocket
    // server does not enforce room-level authentication, we retain the
    // passphrase in case future enhancements require it for API calls.
    if (typeof passphrase === 'string') {
        roomPassphrases.set(roomId, passphrase);
    }
    const storedPassphrase = roomPassphrases.get(roomId);

    currentRoomId = roomId;
    lastMessageId = 0;
    lastDmId = 0;
    $('.messages').empty();
    $('.chat-placeholder').hide();
    $('.chat-ui').show();
    $('#leaveRoomBtn').show();
    const title = roomName || `Room ${roomId}`;
    $('#currentRoomTitle').text(title);
    updateRoomButtons();
    await fetchMessages(true);

    try {
        const payload = { roomId };
        if (typeof storedPassphrase === 'string' && storedPassphrase !== '') {
            payload.passphrase = storedPassphrase;
        }
        await sendSocketCommand('join', payload, true);
    } catch (error) {
        const details = error?.details || {};
        if (details.status === 401) {
            toLogin();
        } else {
            console.warn('Unable to join chat room over WebSocket', error);
        }
    }

    return true;
}

async function fetchMessages(initialLoad) {
    if (!currentRoomId) {
        return;
    }

    try {
        const msgs = await apiRequest(`messages.php?room_id=${encodeURIComponent(currentRoomId)}`);
        let dms = { dms: [] };
        try {
            dms = await apiRequest(`dm.php?room_id=${encodeURIComponent(currentRoomId)}`);
        } catch (dmErr) {
            console.warn('DM fetch failed', dmErr);
        }
        appendCombinedMessages(msgs.messages, dms.dms, initialLoad !== false);
    } catch (error) {
        if (error.status === 401) {
            toLogin();
        } else {
            console.error('Failed to fetch messages', error);
        }
    }
}

function appendCombinedMessages(messages, dms, replace) {
    const $messages = $('.messages');

    if (replace) {
        $messages.empty();
    }

    const merged = [];
    if (Array.isArray(messages)) {
        for (const m of messages) {
            merged.push(Object.assign({}, m, { isDM: false }));
        }
    }
    if (Array.isArray(dms)) {
        for (const d of dms) {
            merged.push(Object.assign({}, d, { isDM: true }));
        }
    }

    merged.sort((a, b) => {
        const aTime = new Date(a.createdAt).getTime();
        const bTime = new Date(b.createdAt).getTime();
        return aTime - bTime;
    });

    merged.forEach((msg) => {
        const normalized = normalizeMessageForDisplay(msg);
        if (normalized.isDM) {
            lastDmId = Math.max(lastDmId, normalized.id);
        } else {
            lastMessageId = Math.max(lastMessageId, normalized.id);
        }
        $messages.append(buildMessageHtml(normalized));
    });

    if ($messages.length && $messages[0]) {
        $messages.scrollTop($messages[0].scrollHeight);
    }
}

async function sendChat() {
    const message = $('#messageInput').val();

    if (!currentRoomId) {
        alert('Select a room before sending messages.');
        return;
    }

    if (!message || message.trim() === '') {
        alert('Message cannot be empty.');
        return;
    }

    const trimmed = message.trim();
    let sentViaSocket = false;
    let socketError = null;

    try {
        await sendSocketCommand('message', {
            roomId: currentRoomId,
            body: trimmed,
        }, true);
        sentViaSocket = true;
    } catch (error) {
        socketError = error;
        const details = error?.details || {};
        if (details.status === 401) {
            toLogin();
            return;
        }
        console.warn('Socket message send failed; falling back to HTTP', error);
    }

    if (sentViaSocket) {
        $('#messageInput').val('');
        return;
    }

    try {
        const resp = await apiRequest('messages.php', {
            method: 'POST',
            body: { room_id: currentRoomId, body: trimmed },
        });
        const payload = resp?.payload || {};
        // Always use the current user's screen name for the sender field when
        // constructing a local message.  Falling back to "Me" can cause
        // self-messages to be misclassified when displayed later.
        const msgObj = {
            id: Number(payload.id) || 0,
            body: String(payload.body || trimmed),
            createdAt: payload.createdAt || new Date().toISOString(),
            sender: currentUser?.screenName ? String(currentUser.screenName).trim() : 'Me',
            isDM: false,
        };
        appendRealtimeMessage(msgObj);
        $('#messageInput').val('');
    } catch (error) {
        const details = error?.details || {};
        if (details.status === 401 || error?.status === 401) {
            toLogin();
        } else if (socketError) {
            const socketMsg = socketError?.message || '';
            const fallbackMsg = details.message || error?.message || 'Unable to send message.';
            alert(socketMsg ? `${fallbackMsg} (${socketMsg})` : fallbackMsg);
        } else {
            alert(details.message || error?.message || 'Unable to send message.');
        }
    }
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

    const trimmed = message.trim();
    let sentViaSocket = false;
    let socketError = null;

    try {
        await sendSocketCommand('dm', {
            roomId: currentRoomId,
            body: trimmed,
            recipients,
        }, true);
        sentViaSocket = true;
    } catch (error) {
        socketError = error;
        const details = error?.details || {};
        if (details.status === 401) {
            toLogin();
            return;
        }
        if (Array.isArray(details.missing) && details.missing.length > 0) {
            $('#dmError').text('Unknown: ' + details.missing.join(', '));
            return;
        }
        if (typeof details.message === 'string' && details.action === 'dm') {
            $('#dmError').text(details.message);
            return;
        }
        console.warn('Socket DM send failed; falling back to HTTP', error);
    }

    if (sentViaSocket) {
        $('#dmRecipients').val('');
        $('#dmPanel').hide();
        $('#messageInput').val('');
        $('#dmError').text('');
        return;
    }

    try {
        const resp = await apiRequest('dm.php', {
            method: 'POST',
            body: { room_id: currentRoomId, body: trimmed, recipients },
        });
        const dmId = resp?.dm?.id ? Number(resp.dm.id) : 0;
        // Always use the current user's screen name for the sender field.  See
        // commentary in sendChat() for rationale.
        const dmObj = {
            id: dmId,
            body: trimmed,
            createdAt: new Date().toISOString(),
            sender: currentUser?.screenName ? String(currentUser.screenName).trim() : 'Me',
            isDM: true,
        };
        appendRealtimeMessage(dmObj);
        $('#dmRecipients').val('');
        $('#dmPanel').hide();
        $('#messageInput').val('');
        $('#dmError').text('');
    } catch (error) {
        const details = error?.details || {};
        if (details.status === 401 || error?.status === 401) {
            toLogin();
        } else if (Array.isArray(details.missing) && details.missing.length > 0) {
            $('#dmError').text('Unknown: ' + details.missing.join(', '));
        } else {
            $('#dmError').text(details.message || error?.message || 'Unable to send DM.');
        }
    }
}

function escapeHtml(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function isCurrentUserSender(sender) {
    if (!currentUser || !currentUser.screenName) {
        return false;
    }
    const currentName = String(currentUser.screenName).trim();
    if (!currentName) {
        return false;
    }
    const s = String(sender).trim().toLocaleLowerCase();
    // Treat "me" or "you" (case-insensitive) as referring to the current user.  This
    // ensures that fallback messages labeled "Me" are still rendered as being
    // sent by the current user.
    if (s === 'me' || s === 'you') {
        return true;
    }
    return s === currentName.toLocaleLowerCase();
}

function clearSignupErrors() {
    $('#registerError').removeClass('text-success').addClass('text-danger').hide().text('');
    $('#err_first_name, #err_last_name, #err_user_name, #err_email, #err_password1, #err_password2').text('');
}

function showSignupFieldErrors(errors) {
    if (errors.firstName) {
        $('#err_first_name').text(errors.firstName);
    }
    if (errors.lastName) {
        $('#err_last_name').text(errors.lastName);
    }
    if (errors.username) {
        $('#err_user_name').text(errors.username);
    }
    if (errors.email) {
        $('#err_email').text(errors.email);
    }
    if (errors.password) {
        $('#err_password1').text(errors.password);
    }
    if (errors.passwordConfirm) {
        $('#err_password2').text(errors.passwordConfirm);
    }
}

$(document).ready(async function () {
    await refreshSession();

    if (currentUser) {
        await loadRooms();
    }

    $(document).on('click', '.join-room-btn', async function () {
        const roomId = Number($(this).data('room-id'));
        const roomName = $(this).data('room-name');
        const isLocked = String($(this).data('room-locked')) === 'true';
        if (Number.isNaN(roomId)) return;

        if (!isLocked) {
            await joinRoom(roomId, roomName);
            return;
        }

        // Locked room: prompt for password via modal
        showRoomPasswordModal(roomId, roomName);
    });

    // Helper to show password modal and stash context
    window.showRoomPasswordModal = function (roomId, roomName) {
        const $modal = $('#roomPasswordModal');
        $modal.data('room-id', roomId);
        $modal.data('room-name', roomName);
        $('#roomPasswordInput').val('');
        $('#roomPasswordError').text('').removeClass('text-danger');
        const modal = new bootstrap.Modal(document.getElementById('roomPasswordModal'));
        modal.show();
        setTimeout(() => $('#roomPasswordInput').trigger('focus'), 150);

        // Add Enter key handler for password input
        $('#roomPasswordInput').off('keypress.roomPassword').on('keypress.roomPassword', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                $('#roomPasswordSubmit').trigger('click');
            }
        });
    };

    // Leave button
    $(document).on('click', '#leaveRoomBtn', async function () {
        if (!currentRoomId) return;
        const roomId = currentRoomId;
        try {
            await sendSocketCommand('leave', { roomId }, true);
        } catch (error) {
            const details = error?.details || {};
            if (details.status === 401) {
                toLogin();
                return;
            }
            console.warn('Unable to leave chat room over WebSocket', error);
        }
        currentRoomId = null;
        lastMessageId = 0;
        lastDmId = 0;
        $('.messages').empty();
        $('.chat-ui').hide();
        $('.chat-placeholder').show();
        $('#currentRoomTitle').text('Chatroom');
        $('#leaveRoomBtn').hide();
        updateRoomButtons();
    });

    // Update rooms list buttons to reflect current room
    updateRoomButtons = function () {
        // For current room, show Joined badge; for others, show Join button if not rendered
        $('#roomsList tr').each(function () {
            const rid = Number($(this).attr('data-room-id'));
            const $cell = $(this).find('.room-action');
            if (!rid || $cell.length === 0) return;
            if (currentRoomId && rid === currentRoomId) {
                $cell.html('<span class="badge text-bg-info">Joined</span>');
            } else {
                const name = $(this).find('td').first().text();
                const isLocked = String($(this).attr('data-room-locked')) === 'true';
                // If there is no button, render one
                if ($cell.find('button.join-room-btn').length === 0) {
                    $cell.html(`<button class="btn btn-primary btn-sm join-room-btn" data-room-id="${rid}" data-room-name="${escapeHtml(name)}" data-room-locked="${isLocked}">Join</button>`);
                }
            }
        });
    };

    // Handle password submit
    $(document).on('click', '#roomPasswordSubmit', async function () {
        const roomId = Number($('#roomPasswordModal').data('room-id'));
        const roomName = $('#roomPasswordModal').data('room-name');
        const pass = $('#roomPasswordInput').val();
        $('#roomPasswordError').text('');
        if (Number.isNaN(roomId)) {
            return;
        }
        try {
            await apiRequest('rooms_join.php', { method: 'POST', body: { room_id: roomId, passphrase: pass } });
            const joined = await joinRoom(roomId, roomName, pass);
            if (joined) {
                const modal = bootstrap.Modal.getInstance(document.getElementById('roomPasswordModal'));
                if (modal) modal.hide();
                $('#roomPasswordInput').val('');
                $('#roomPasswordError').text('');
            }
        } catch (error) {
            const msg = (error.status === 401) ? 'Password is incorrect.' : (error.data?.error || 'Unable to join room.');
            $('#roomPasswordError').text(msg).removeClass('text-success').addClass('text-danger');
        }
    });

    $('#messageInput').on('keypress', function (event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            sendChat();
        }
    });

    // Press Enter to login (on login page inputs)
    $(document).on('keypress', '#username, #password', function (event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            login();
        }
    });

    // Press Enter to create a room (in the Create Room dropdown form)
    $(document).on('keypress', '#chatRoomName, #chatRoomPassword', function (event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            $('#createRoomForm').trigger('submit');
        }
    });

    // Press Enter to submit signup form fields
    $(document).on('keypress', '#first_name, #last_name, #user_name, #email, #password1, #password2', function (event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            registerAccount();
        }
    });

    // DM panel toggle and actions
    $(document).on('click', '#dmToggle', function () {
        $('#dmPanel').toggle();
        if ($('#dmPanel').is(':visible')) {
            $('#dmRecipients').trigger('focus');
        }
    });
    $(document).on('click', '#dmCancel', function () {
        $('#dmPanel').hide();
        $('#dmRecipients').val('');
        $('#dmError').text('');
    });
    $(document).on('click', '#dmSend', function () {
        sendDM();
    });
    $(document).on('keypress', '#dmRecipients', function (event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            sendDM();
        }
    });

});
