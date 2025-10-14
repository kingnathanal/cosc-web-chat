<?php include 'header.php';?>
<!-------------------- CODE Starts HERE -------------------->
        <h1 class="text-center mb-4 main-header">Welcome to Box Chat</h1>
        <p>
            This is a simple web-based chat application where you can join chat rooms and communicate with others in real-time.
            To get started, please sign up for an account or log in if you already have one. Once logged in, you can create or join chat rooms and start chatting!
        </p>

        <div class="container my-4 chat-container" style="display:none;">
            <div class="row">
                <div class="col-5">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between">
                            <div>Available Chat Rooms</div>
                            <div class="dropdown">
                                <button class="btn btn-sm btn-primary float-end createRoom" data-bs-toggle="dropdown">Create Room</button>
                                <form class="dropdown-menu p-2" style="min-width:300px;" id="createRoomForm" onsubmit="createRoom(event)">
                                    <div class="mb-3">
                                    <label for="chatRoomName" class="form-label">Chat Room Name</label>
                                    <input type="text" class="form-control" id="chatRoomName" placeholder="Chat Room Name" required>
                                    <div id="err_chatRoomName" class="text-danger small"></div>
                                    </div>
                                    <div class="mb-3">
                                    <label for="chatRoomPassword" class="form-label">Password (optional)</label>
                                    <input type="password" class="form-control" id="chatRoomPassword" placeholder="Password">
                                    <small class="text-muted">Leave blank for an open room</small>
                                    </div>
                                    <button type="submit" class="btn btn-primary">Create Room</button>
                                </form>
                            </div>
                        </div>
                        <div class="card-body avilable-rooms" style="min-height:500px; max-height:500px; overflow-y:scroll; border:1px solid #ccc; padding:10px;">
                            <!-- Chat rooms will be listed here -->
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Room Name</th>
                                        <th>Status</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody id="roomsList">
                                    <!-- Dynamic content will be inserted here -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="col">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <span id="currentRoomTitle">Chatroom</span>
                            <button type="button" class="btn btn-outline-danger btn-sm" id="leaveRoomBtn" style="display:none;">Leave</button>
                        </div>
                        <div class="card-body h-50">
                            <div class="chat-placeholder text-center py-5" style="min-height:500px; max-height:500px; overflow-y:scroll; border:1px solid #ccc; padding:10px; display:flex; align-items:center; justify-content:center; flex-direction:column;">
                                <h3>Chatroom</h3>
                                <p class="mb-0">Send and receive messages with your loved ones.</p>
                            </div>
                            <div class="chat-ui" style="display:none;">
                                <div class="messages" style="min-height:500px; max-height:500px; overflow-y:scroll; border:1px solid #ccc; padding:10px;">
                                    <!-- Messages will be displayed here -->
                                </div>
                                <div class="input-group mt-3">
                                    <input type="text" class="form-control" placeholder="Type your message..." id="messageInput">
                                    <button class="btn btn-secondary" type="button" id="dmToggle">DM</button>
                                    <button class="btn btn-primary sendChat" type="button" id="sendButton" onclick="sendChat()">Send</button>
                                </div>
                                <div id="dmPanel" class="mt-2" style="display:none;">
                                    <div class="card card-body p-2">
                                        <label for="dmRecipients" class="form-label mb-1">Direct message to screen names (comma-separated)</label>
                                        <input type="text" class="form-control form-control-sm" id="dmRecipients" placeholder="e.g., Alice, Bob">
                                        <div class="d-flex justify-content-end mt-2 gap-2">
                                            <span id="dmError" class="text-danger small me-auto"></span>
                                            <button class="btn btn-outline-secondary btn-sm" id="dmCancel">Cancel</button>
                                            <button class="btn btn-success btn-sm" id="dmSend">Send DM</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Room Password Modal -->
        <div class="modal fade" id="roomPasswordModal" tabindex="-1" aria-hidden="true">
          <div class="modal-dialog">
            <div class="modal-content">
              <div class="modal-header">
                <h5 class="modal-title">Enter Room Password</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
              </div>
              <div class="modal-body">
                <div class="mb-2">
                  <label for="roomPasswordInput" class="form-label">Password</label>
                  <input type="password" class="form-control" id="roomPasswordInput" placeholder="Enter password">
                </div>
                <div id="roomPasswordError" class="small"></div>
              </div>
              <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="roomPasswordSubmit">Join</button>
              </div>
            </div>
          </div>
        </div>
<!-------------------- CODE ENDS HERE -------------------->
<?php include 'footer.php'; ?>
