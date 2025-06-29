<?php
session_start();
require_once '../config.php';

$chatRoomID = intval($_GET['chatRoomID']);
$lastMessageID = intval($_GET['lastMessageID'] ?? 0);

$conn = getDBConnection();
$stmt = $conn->prepare("SELECT MessageID, SenderID, Message, TIMESTAMP FROM message 
                        WHERE ChatRoomID = ? AND MessageID > ? ORDER BY MessageID ASC");
$stmt->execute([$chatRoomID, $lastMessageID]);
$messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($messages);