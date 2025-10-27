function handleRoomsStream() {
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
        roomsRefreshInterval = setInterval(() => loadRooms(), 5000);
        return;
    }

    try {
        roomsEventSource = new EventSource(`${API_BASE}/rooms_sse.php`);
        roomsEventSource.addEventListener('rooms_update', async () => {
            try { await loadRooms(); } catch (e) { console.warn('Rooms refresh failed', e); }
        });
        roomsEventSource.addEventListener('error', (e) => {
            console.debug('Rooms SSE error', e);
        });
    } catch (e) {
        console.warn('EventSource setup failed', e);
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

    $('#err_chatRoomName').text('');

    if (roomName === '') {
        $('#err_chatRoomName').text('Room name is required.');
        return;
    }

    try {
        await apiRequest('rooms.php', {
            method: 'POST',
            body: {
                name: roomName,
                passphrase: passphrase,
            },
        });

        $('#chatRoomName').val('');
        $('#chatRoomPassword').val('');

        const dropdown = bootstrap.Dropdown.getInstance(document.querySelector('.createRoom'));
        if (dropdown) {
            dropdown.hide();
        }

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
            <tr data-room-id="${room.id}">
                <td>${escapeHtml(room.name)}</td>
                <td><span class="badge text-bg-${statusBadge}">${escapeHtml(room.status)}</span></td>
                <td class="room-action">${actionCell}</td>
            </tr>`;
        $roomsList.append(row);
    });
}

async function joinRoom(roomId, roomName = null) {
    if (!currentUser) {
        toLogin();
        return;
    }

    if (currentRoomId === roomId || pendingJoinRoomId === roomId) {
        return;
    }

    ensureSocketConnected();

    if (currentRoomId) {
        sendSocketMessage('leave_room', { roomId: currentRoomId });
        currentRoomId = null;
    }

    pendingJoinRoomId = roomId;
    lastMessageId = 0;
    $('.messages').empty();
    $('.chat-placeholder').hide();
    $('.chat-ui').show();
    $('#leaveRoomBtn').show();
    if (roomName) {
        $('#currentRoomTitle').text(roomName);
    }
    updateRoomButtons();
    sendSocketMessage('join_room', { roomId });
}

function updateRoomButtons() {
    $('#roomsList tr').each(function () {
        const rid = Number($(this).attr('data-room-id'));
        const $cell = $(this).find('.room-action');
        if (!rid || $cell.length === 0) return;
        if (currentRoomId && rid === currentRoomId) {
            $cell.html('<span class="badge text-bg-info">Joined</span>');
        } else {
            const name = $(this).find('td').first().text();
            if ($cell.find('button.join-room-btn').length === 0) {
                $cell.html(`<button class="btn btn-primary btn-sm join-room-btn" data-room-id="${rid}" data-room-name="${escapeHtml(name)}">Join</button>`);
            }
        }
    });
}

function showRoomPasswordModal(roomId, roomName) {
    const $modal = $('#roomPasswordModal');
    $modal.data('room-id', roomId);
    $modal.data('room-name', roomName);
    $('#roomPasswordInput').val('');
    $('#roomPasswordError').text('').removeClass('text-danger');
    const modal = new bootstrap.Modal(document.getElementById('roomPasswordModal'));
    modal.show();
    setTimeout(() => $('#roomPasswordInput').trigger('focus'), 150);

    $('#roomPasswordInput').off('keypress.roomPassword').on('keypress.roomPassword', function (event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            $('#roomPasswordSubmit').trigger('click');
        }
    });
}
