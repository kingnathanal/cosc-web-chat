async function logout() {
    try {
        if (currentRoomId) {
            sendSocketMessage('leave_room', { roomId: currentRoomId });
        }
        await apiRequest('logout.php', { method: 'POST' });
    } catch (err) {
        console.warn('Logout request failed', err);
    } finally {
        currentUser = null;
        currentRoomId = null;
        pendingJoinRoomId = null;
        resetSocket(true);
        invalidateSocketToken();
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
        ensureSocketConnected();
    } else {
        resetSocket(false);
        invalidateSocketToken();
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
