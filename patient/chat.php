<?php
session_start();
require_once '../config.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['user_role'] !== 'patient') {
    header('Location:' . BASE_URL . '/auth/login.php');
    exit();
}
$userID = $_SESSION['user_id'];

if (!isset($_GET['doctorID'])) {
    die("Doctor ID not provided");
}
$doctorID = intval($_GET['doctorID']);

$conn = getDBConnection();
$stmt = $conn->prepare("SELECT UserID FROM doctor WHERE DoctorID = ?");
$stmt->execute([$doctorID]);
$doctorUserID = $stmt->fetchColumn();
$stmt = null;

if (!$doctorUserID) die("Invalid doctor");

$stmt = $conn->prepare("SELECT ChatRoomID FROM chatroom WHERE 
    (User1ID = ? AND User2ID = ?) OR (User1ID = ? AND User2ID = ?)");
$stmt->execute([$userID, $doctorUserID, $doctorUserID, $userID]);
$chatRoomID = $stmt->fetchColumn();
$stmt = null;

$stmt = $conn->prepare("SELECT NAME FROM systemuser WHERE UserID = ?");
$stmt->execute([$doctorUserID]);
$doctorName = $stmt->fetchColumn();
$stmt = null;

if (!$chatRoomID) {
    $stmt = $conn->prepare("INSERT INTO chatroom (User1ID, User2ID) VALUES (?, ?)");
    $stmt->execute([$userID, $doctorUserID]);
    $chatRoomID = $conn->lastInsertId();
    $stmt = null;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chat with Dr. A</title>
    <script
			  src="https://code.jquery.com/jquery-3.7.1.min.js"
			  integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo="
			  crossorigin="anonymous"></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --doctor-color: #1cc88a;
            --patient-color: #4e73df;
            --light-gray: #f8f9fc;
            --text-gray: #858796;
            --desktop-width: 800px;
            --mobile-width: 100%;
        }
        
        * {
            box-sizing: border-box;
        }
        
        body {
            background-color: #f8f9fa;
            font-family: 'Nunito', sans-serif;
            padding: 0;
            margin: 0;
            height: 100vh;
            display: flex;
            flex-direction: column;
        }
        
        .chat-container {
            width: 100%;
            max-width: var(--desktop-width);
            margin: 0 auto;
            height: 100%;
            display: flex;
            flex-direction: column;
            background-color: white;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        
        @media (max-width: 768px) {
            .chat-container {
                max-width: var(--mobile-width);
            }
        }
        
        .chat-header {
            background-color: white;
            padding: 15px 20px;
            border-bottom: 1px solid #eaeaea;
            text-align: center;
            position: sticky;
            top: 0;
            z-index: 10;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .doctor-name {
            color: var(--doctor-color);
            font-weight: 600;
            margin: 0;
            font-size: 1.2rem;
        }
        
        .chat-messages {
            flex: 1;
            padding: 20px;
            overflow-y: auto;
            background-color: #f5f5f5;
            display: flex;
            flex-direction: column-reverse;
        }
        
        .messages-wrapper {
            display: flex;
            flex-direction: column;
        }
        
        .message {
            margin-bottom: 15px;
            max-width: 80%;
            animation: fadeIn 0.3s ease-out;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .doctor-message {
            margin-right: auto;
            background-color: white;
            border-radius: 0 15px 15px 15px;
            padding: 12px 15px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            position: relative;
        }
        
        .patient-message {
            margin-left: auto;
            background-color: var(--patient-color);
            color: white;
            border-radius: 15px 0 15px 15px;
            padding: 12px 15px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        
        .message-time {
            font-size: 0.75rem;
            color: var(--text-gray);
            margin-top: 5px;
            display: block;
        }
        
        .doctor-message .message-time {
            text-align: left;
        }
        
        .patient-message .message-time {
            text-align: right;
            color: rgba(255,255,255,0.7);
        }
        
        .chat-input-container {
            background-color: white;
            padding: 15px 20px;
            border-top: 1px solid #eaeaea;
            position: sticky;
            bottom: 0;
        }
        
        .input-group {
            display: flex;
            align-items: center;
        }
        
        .message-input {
            flex: 1;
            border: 1px solid #ddd;
            border-radius: 25px;
            padding: 12px 20px;
            outline: none;
            font-size: 1rem;
            transition: border-color 0.3s;
        }
        
        .message-input:focus {
            border-color: var(--patient-color);
        }
        
        .send-button {
            background-color: var(--patient-color);
            color: white;
            border: none;
            border-radius: 50%;
            width: 45px;
            height: 45px;
            margin-left: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: background-color 0.3s;
        }
        
        .send-button:hover {
            background-color: #3a5fcd;
        }
        
        .btn-return {
            position: absolute;
            left: 20px;
            color: var(--text-gray);
            text-decoration: none;
            font-size: 1.2rem;
            padding: 5px;
        }
        
        .typing-indicator {
            display: none;
            margin-right: auto;
            background-color: white;
            border-radius: 0 15px 15px 15px;
            padding: 10px 15px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            margin-bottom: 15px;
            width: fit-content;
        }
        
        .typing-dots {
            display: flex;
            align-items: center;
        }
        
        .typing-dots span {
            width: 8px;
            height: 8px;
            background-color: var(--doctor-color);
            border-radius: 50%;
            display: inline-block;
            margin: 0 2px;
            animation: typingAnimation 1.4s infinite both;
        }
        
        .typing-dots span:nth-child(2) {
            animation-delay: 0.2s;
        }
        
        .typing-dots span:nth-child(3) {
            animation-delay: 0.4s;
        }
        
        @keyframes typingAnimation {
            0%, 60%, 100% { transform: translateY(0); }
            30% { transform: translateY(-5px); }
        }
        
        /* Scrollbar styling */
        .chat-messages::-webkit-scrollbar {
            width: 8px;
        }
        
        .chat-messages::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }
        
        .chat-messages::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 10px;
        }
        
        .chat-messages::-webkit-scrollbar-thumb:hover {
            background: #a8a8a8;
        }
    </style>
</head>
<body>
<body>
    <div class="chat-container">
        <div class="chat-header">
            <a href="#" class="btn-return">
                <i class="fas fa-arrow-left"></i>
            </a>
            <h5 class="doctor-name"><?= htmlspecialchars($doctorName) ?></h5>
        </div>
        
        <div class="chat-messages">
            <div class="messages-wrapper" id="messages-wrapper">
            </div>
        </div>
        
        <div class="chat-input-container">
            <div class="input-group">
                <input type="text" class="message-input" placeholder="Type your message...">
                <button class="send-button">
                    <i class="fas fa-paper-plane"></i>
                </button>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        const chatRoomID = <?= $chatRoomID ?>;
        const userID = <?= $userID ?>;
        let lastMessageID = 0;

        const messagesWrapper = document.getElementById('messages-wrapper');
        const input = document.querySelector('.message-input');
        const sendButton = document.querySelector('.send-button');

        function getCurrentTime(ts) {
            const date = new Date(ts);
            let h = date.getHours(), m = date.getMinutes();
            const ampm = h >= 12 ? 'p.m.' : 'a.m.';
            h = h % 12 || 12;
            return `Today, ${h}:${m.toString().padStart(2, '0')} ${ampm}`;
        }

        function escapeHTML(text) {
            return text.replace(/[&<>"']/g, c => ({
                '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
            }[c]));
        }

        function loadMessages() {
            $.get('get_messages.php', { chatRoomID, lastMessageID }, function(data) {
                data.forEach(msg => {
                    const isUser = msg.SenderID == userID;
                    const msgDiv = document.createElement('div');
                    msgDiv.className = `message ${isUser ? 'patient-message' : 'doctor-message'}`;
                    msgDiv.innerHTML = `
                        <div>${escapeHTML(msg.Message)}</div>
                        <span class="message-time">${getCurrentTime(msg.TIMESTAMP)}</span>
                    `;
                    messagesWrapper.appendChild(msgDiv);
                    lastMessageID = msg.MessageID;
                });
                messagesWrapper.scrollTop = messagesWrapper.scrollHeight;
            }, 'json');
        }

        function sendMessage() {
            const message = input.value.trim();
            if (!message) return;

            $.post('send_message.php', {
                chatRoomID,
                senderID: userID,
                message
            }, function() {
                input.value = '';
                loadMessages();
            });
        }

        sendButton.addEventListener('click', sendMessage);
        input.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') sendMessage();
        });

        setInterval(loadMessages, 2000);
        loadMessages();
    </script>
</body>
</html>