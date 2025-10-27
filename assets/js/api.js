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
