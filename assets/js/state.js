const API_BASE = './api';
const WS_RECONNECT_DELAY = 2500;

let currentUser = null;
let currentRoomId = null;
let pendingJoinRoomId = null;
let chatSocket = null;
let socketReady = false;
let socketQueue = [];
let reconnectTimer = null;
let lastMessageId = 0;
let lastDmId = 0;
let roomsEventSource = null;
let roomsRefreshInterval = null;
let socketAuthToken = null;

function escapeHtml(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}
