    </div>
    <div class="modal" id="helpModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">The Box Help Section</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>Welcome to the Box Chat application! This platform allows you to communicate in real-time with other users in various chat rooms. To get started, you need to create an account or log in if you already have one. Once logged in, you can view the list of available chat rooms on the left side of the interface. Each room displays its name, current status, and an action button to join the room. Click on the "Join" button to enter a chat room. In the chat area on the right, you can see messages from other users and participate in the conversation by typing your message in the input field at the bottom and clicking the "Send" button. Messages will appear in the chat area in real-time, allowing for seamless communication. You can switch between different chat rooms by leaving the current room and joining another. The application also provides a help section, accessible via the "Help" button, where you can find guidance on using various features. Remember to follow community guidelines and be respectful to other users. Enjoy chatting!</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js" integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI" crossorigin="anonymous"></script>
    <?php
        $wsUrl = getenv('WS_PUBLIC_URL') ?: '';
        $wsHost = getenv('WS_PUBLIC_HOST') ?: '';
        $wsPort = getenv('WS_PUBLIC_PORT') ?: (getenv('WS_PORT') ?: '');
        $wsPath = getenv('WS_PUBLIC_PATH') ?: '';
        $assetVersion = date("H:i:s");
    ?>
    <script>
        window.BOXCHAT_CONFIG = window.BOXCHAT_CONFIG || {};
        <?php if ($wsUrl !== ''): ?>
        window.BOXCHAT_CONFIG.wsUrl = <?php echo json_encode($wsUrl); ?>;
        <?php endif; ?>
        <?php if ($wsHost !== ''): ?>
        window.BOXCHAT_CONFIG.wsHost = <?php echo json_encode($wsHost); ?>;
        <?php endif; ?>
        <?php if ($wsPort !== ''): ?>
        window.BOXCHAT_CONFIG.wsPort = <?php echo json_encode((int) $wsPort); ?>;
        <?php endif; ?>
        <?php if ($wsPath !== ''): ?>
        window.BOXCHAT_CONFIG.wsPath = <?php echo json_encode($wsPath); ?>;
        <?php endif; ?>
    </script>
    <script src="./assets/js/state.js?ver=<?php echo $assetVersion; ?>"></script>
    <script src="./assets/js/navigation.js?ver=<?php echo $assetVersion; ?>"></script>
    <script src="./assets/js/api.js?ver=<?php echo $assetVersion; ?>"></script>
    <script src="./assets/js/chat.js?ver=<?php echo $assetVersion; ?>"></script>
    <script src="./assets/js/socket.js?ver=<?php echo $assetVersion; ?>"></script>
    <script src="./assets/js/rooms.js?ver=<?php echo $assetVersion; ?>"></script>
    <script src="./assets/js/auth.js?ver=<?php echo $assetVersion; ?>"></script>
    <script src="./assets/js/init.js?ver=<?php echo $assetVersion; ?>"></script>
</body>
</html>
