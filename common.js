const API_BASE = './api';
let currentUser = null;
let currentRoomId = null;
let pollTimer = null;
let lastMessageId = 0;
let roomsEventSource = null;

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
        if (currentRoomId) {
            try { await apiRequest('presence.php', { method: 'POST', body: { action: 'leave', room_id: currentRoomId } }); } catch {}
        }
        await apiRequest('logout.php', { method: 'POST' });
    } catch (err) {
        // Even if logout fails, fallback to clearing state locally.
        console.warn('Logout request failed', err);
    } finally {
        currentUser = null;
        stopPolling();
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
    if (!currentUser) return;
    if (!('EventSource' in window)) {
        // Fallback: periodic refresh
        setInterval(() => loadRooms(), 5000);
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
        setInterval(() => loadRooms(), 5000);
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
        
        // Reload rooms list
        await loadRooms();
        
        // Optionally auto-join the newly created room
        if (response.room && response.room.id) {
            joinRoom(response.room.id, response.room.name || null);
        }
        
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
            <tr data-room-id="${room.id}">
                <td>${escapeHtml(room.name)}</td>
                <td><span class="badge text-bg-${statusBadge}">${escapeHtml(room.status)}</span></td>
                <td class="room-action">${actionCell}</td>
            </tr>`;
        $roomsList.append(row);
    });
}

async function joinRoom(roomId, roomName = null) {
    if (currentRoomId === roomId) {
        return;
    }

    // Leave previous room if any
    const previousRoomId = currentRoomId;
    if (previousRoomId) {
        try {
            await apiRequest('presence.php', { method: 'POST', body: { action: 'leave', room_id: previousRoomId } });
        } catch (e) {
            // non-fatal
            console.warn('Failed to leave room', e);
        }
    }

    // Join new room (server-side presence + join broadcast)
    try {
        await apiRequest('presence.php', { method: 'POST', body: { action: 'join', room_id: roomId } });
    } catch (e) {
        alert(e.data?.error || 'Unable to join the room.');
        return;
    }

    currentRoomId = roomId;
    lastMessageId = 0;
    $('.messages').empty();
    // Show chat UI when a room is joined
    $('.chat-placeholder').hide();
    $('.chat-ui').show();
    $('#leaveRoomBtn').show();
    if (roomName) {
        $('#currentRoomTitle').text(roomName);
    }
    updateRoomButtons();
    stopPolling();
    await fetchMessages(true);
    startPolling();
}

async function fetchMessages(initialLoad) {
    if (!currentRoomId) {
        return;
    }

    let path = `messages.php?room_id=${encodeURIComponent(currentRoomId)}`;
    if (!initialLoad && lastMessageId > 0) {
        path += `&after=${lastMessageId}`;
    }

    try {
        const data = await apiRequest(path);
        appendMessages(data.messages, initialLoad);
    } catch (error) {
        if (error.status === 401) {
            stopPolling();
            toLogin();
        } else {
            console.error('Failed to fetch messages', error);
        }
    }
}

function appendMessages(messages, replace) {
    if (!Array.isArray(messages) || messages.length === 0) {
        return;
    }

    const $messages = $('.messages');

    if (replace) {
        $messages.empty();
    }

    messages.forEach((msg) => {
        const timestamp = new Date(msg.createdAt).toLocaleString();
        const isBroadcast = / (joined|left) the chat$/.test(String(msg.body));
        let html;
        if (isBroadcast) {
            // No timestamp for broadcasts
            html = `<div class="mb-2 text-center text-muted"><em>${escapeHtml(msg.body)}</em></div>`;
        } else {
            const senderLabel = (currentUser && msg.sender === currentUser.screenName) ? 'Me' : msg.sender;
            html = `<div class="mb-2">
                <strong>${escapeHtml(senderLabel)}</strong> <em>${timestamp}</em><br/>
                <span class="badge rounded-pill text-bg-success fs-6">${escapeHtml(msg.body)}</span>
            </div>`;
        }
        $messages.append(html);
        lastMessageId = Math.max(lastMessageId, msg.id);
    });

    $messages.scrollTop($messages[0].scrollHeight);
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

    try {
        await apiRequest('messages.php', {
            method: 'POST',
            body: { room_id: currentRoomId, body: message.trim() },
        });
        $('#messageInput').val('');
        await fetchMessages(false);
    } catch (error) {
        if (error.status === 401) {
            toLogin();
        } else {
            alert(error.data?.error || 'Unable to send message.');
        }
    }
}


function startPolling() {
    stopPolling();
    pollTimer = setInterval(() => fetchMessages(false), 3000);
}

function stopPolling() {
    if (pollTimer) {
        clearInterval(pollTimer);
        pollTimer = null;
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
    };

    // Leave button
    $(document).on('click', '#leaveRoomBtn', async function () {
        if (!currentRoomId) return;
        try {
            await apiRequest('presence.php', { method: 'POST', body: { action: 'leave', room_id: currentRoomId } });
        } catch (_) {}
        stopPolling();
        currentRoomId = null;
        lastMessageId = 0;
        $('.messages').empty();
        $('.chat-ui').hide();
        $('.chat-placeholder').show();
        $('#currentRoomTitle').text('Chatroom');
        $('#leaveRoomBtn').hide();
        await loadRooms();
    });

    // Update rooms list buttons to reflect current room
    window.updateRoomButtons = function () {
        // For current room, show Joined badge; for others, show Join button if not rendered
        $('#roomsList tr').each(function () {
            const rid = Number($(this).attr('data-room-id'));
            const $cell = $(this).find('.room-action');
            if (!rid || $cell.length === 0) return;
            if (currentRoomId && rid === currentRoomId) {
                $cell.html('<span class="badge text-bg-info">Joined</span>');
            } else {
                const name = $(this).find('td').first().text();
                // If there is no button, render one
                if ($cell.find('button.join-room-btn').length === 0) {
                    $cell.html(`<button class="btn btn-primary btn-sm join-room-btn" data-room-id="${rid}" data-room-name="${escapeHtml(name)}">Join</button>`);
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
        try {
            await apiRequest('rooms_join.php', { method: 'POST', body: { room_id: roomId, passphrase: pass } });
            const modal = bootstrap.Modal.getInstance(document.getElementById('roomPasswordModal'));
            if (modal) modal.hide();
            $('#roomPasswordInput').val('');
            await joinRoom(roomId, roomName);
        } catch (error) {
            const msg = (error.status === 401) ? 'Password is incorrect.' : (error.data?.error || 'Unable to join room.');
            $('#roomPasswordError').text(msg).addClass('text-danger');
        }
    });

    $('#messageInput').on('keypress', function (event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            sendChat();
        }
    });

    // On page unload, try to mark leave (best-effort)
    window.addEventListener('beforeunload', function () {
        if (!currentRoomId) return;
        try {
            const payload = JSON.stringify({ action: 'leave', room_id: currentRoomId });
            const blob = new Blob([payload], { type: 'application/json' });
            navigator.sendBeacon(`${API_BASE}/presence.php`, blob);
        } catch (_) {}
    });
});
