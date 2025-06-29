<?php
session_start();
require_once '../config.php';

$chatRoomID = intval($_POST['chatRoomID'] ?? 0);
$senderID = intval($_POST['senderID'] ?? 0);
$message = trim($_POST['message'] ?? '');

if ($chatRoomID && $senderID && $message) {
    try {
        $conn = getDBConnection();
        
        $stmt = $conn->prepare("INSERT INTO message (ChatRoomID, SenderID, Message) VALUES (?, ?, ?)");
        $stmt->execute([$chatRoomID, $senderID, $message]);
        
        echo json_encode([
            'status' => 'success'
        ]);
    } catch (PDOException $e) {
        echo json_encode([
            'status' => 'error'
        ]);
    }
} else {
    echo json_encode([
        'status' => 'error'
    ]);
}