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

        showRoomPasswordModal(roomId, roomName);
    });

    window.showRoomPasswordModal = showRoomPasswordModal;
    window.updateRoomButtons = updateRoomButtons;

    $(document).on('click', '#leaveRoomBtn', async function () {
        if (!currentRoomId) return;
        const roomId = currentRoomId;
        sendSocketMessage('leave_room', { roomId });
        currentRoomId = null;
        pendingJoinRoomId = null;
        lastMessageId = 0;
        lastDmId = 0;
        $('.messages').empty();
        $('.chat-ui').hide();
        $('.chat-placeholder').show();
        $('#currentRoomTitle').text('Chatroom');
        $('#leaveRoomBtn').hide();
        updateRoomButtons();
        try {
            await loadRooms();
        } catch (err) {
            console.warn('Failed to refresh rooms list', err);
        }
    });

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
            $('#roomPasswordError').text(msg).removeClass('text-success').addClass('text-danger');
        }
    });

    $('#messageInput').on('keypress', function (event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            sendChat();
        }
    });

    $(document).on('keypress', '#username, #password', function (event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            login();
        }
    });

    $(document).on('keypress', '#chatRoomName, #chatRoomPassword', function (event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            $('#createRoomForm').trigger('submit');
        }
    });

    $(document).on('keypress', '#first_name, #last_name, #user_name, #email, #password1, #password2', function (event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            registerAccount();
        }
    });

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
