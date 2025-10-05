function toLogin() {
    window.location.href = "../login.php";
}

function toSignup() {
    window.location.href = "../signup.php";
}

function logout() {
    // Clear user session or authentication tokens here
    localStorage.setItem("is_login_ok", "false");
    localStorage.removeItem("username");
    localStorage.removeItem("password");
    alert("You have been logged out.");
    window.location.href = "../index.php"; // Redirect to homepage or login page
}

function toIndex() {
    window.location.href = "../index.php";
}

function login() {

    let username = $("#username").val();
    let password = $("#password").val();
    
    localStorage.setItem("username", username);
    localStorage.setItem("password", password);
    localStorage.setItem("is_login_ok", "true");

    window.location.href = "../index.php";
}

function sendChat() {
    let message = $("#messageInput").val();
    if (message.trim() === "") {
        alert("Message cannot be empty.");
        return;
    }

    let username = localStorage.getItem("username");
    let timestamp = new Date().toLocaleString();

    let messageElement = `<div class="mb-2">
        <strong>${username}</strong> <em>${timestamp}</em><br/>
        <span class="badge rounded-pill text-bg-success fs-6">${message}</span>
    </div>`;
    $(".messages").append(messageElement);
    sampleMessages.push({ roomId: 1, username: username, message: message, timestamp: timestamp });
    $("#messageInput").val("");
}

const availableRooms = [
    { id: 1, name: "General Chat", status: "open"},
    { id: 2, name: "Sports Talk", status: "open"},
    { id: 3, name: "Tech Discussions", status: "open"},
    { id: 4, name: "Movies & TV Shows", status: "open"},
    { id: 5, name: "Music Lovers", status: "open"},
    { id: 6, name: "Travel & Adventure", status: "closed"},
    { id: 7, name: "Foodies", status: "open"},
    { id: 8, name: "Gaming Zone", status: "open"},
    { id: 9, name: "Book Club", status: "closed"},
    { id: 10, name: "Fitness & Health", status: "open"}
];

const sampleMessages = [
    { roomId: 1, username: "Alice", message: "Hello everyone!", timestamp: "2024-10-01 10:00 AM" },
    { roomId: 1, username: "Bob", message: "Hi Alice! How are you?", timestamp: "2024-10-01 10:05 AM" },
    { roomId: 1, username: "Charlie", message: "Did you watch the game last night?", timestamp: "2024-10-01 11:00 AM" },
    { roomId: 1, username: "Dave", message: "Yes! It was amazing!", timestamp: "2024-10-01 11:15 AM" },
    { roomId: 1, username: "Eve", message: "What's the latest in tech?", timestamp: "2024-10-01 12:00 PM" },
    { roomId: 1, username: "Frank", message: "AI is really taking off!", timestamp: "2024-10-01 12:30 PM" }
];

$(document).ready(function() {
    if (localStorage.getItem("is_login_ok") == "true") {
        $(".login").hide();
        $(".signup").hide();
        $(".logout").show();
        //
        let m = "User: " + localStorage.getItem("username") + " is currently logged in";
        console.log("here here");
        $(".chat-container").show();
    }
    else {
        $(".login").show();
        $(".signup").show();
        $(".logout").hide();

        console.log("not logged in");
        $(".chat-container").hide();
    }

    availableRooms.forEach(room => {
        let roomElement = `<tr>
            <td>${room.name}</td>
            <td>${room.status}</td>
            <td><button class="btn btn-primary btn-sm">Join</button></td>
        </tr>`;
        $("#roomsList").append(roomElement);
    });

    sampleMessages.forEach(msg => {
        let messageElement = `<div class="mb-2">
            <strong>${msg.username}</strong> <em>${msg.timestamp}</em><br/>
            <span class="badge rounded-pill text-bg-success fs-6">${msg.message}</span>
        </div>`;
        $(".messages").append(messageElement);
    });
});