<?php include 'header.php';?>
<!-------------------- CODE Starts HERE -------------------->
        <h1 class="text-center mb-4 main-header">Welcome to Box Chat, yeap</h1>
        <p>
            This is a simple web-based chat application where you can join chat rooms and communicate with others in real-time.
            To get started, please sign up for an account or log in if you already have one. Once logged in, you can create or join chat rooms and start chatting!
        </p>

        <div class="container my-4 chat-container" style="display:none;">
            <div class="row">
                <div class="col-5">
                    <div class="card">
                        <div class="card-header">Available Chat Rooms</div>
                        <div class="card-body avilable-rooms" style="min-height:400px; max-height:500px; overflow-y:scroll; border:1px solid #ccc; padding:10px;">
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
                        <div class="card-header">Chat Area</div>
                        <div class="card-body h-50">
                            <div class="messages" style="min-height:500px; max-height:600px; overflow-y:scroll; border:1px solid #ccc; padding:10px;">
                                <!-- Messages will be displayed here -->
                            </div>
                            <div class="input-group mt-3">
                                <input type="text" class="form-control" placeholder="Type your message..." id="messageInput">
                                <button class="btn btn-primary sendChat" type="button" id="sendButton" onclick="sendChat()">Send</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
<!-------------------- CODE ENDS HERE -------------------->
<?php include 'footer.php'; ?>