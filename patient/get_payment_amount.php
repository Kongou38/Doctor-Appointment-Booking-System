<?php
session_start();
include '../config.php';
require_once '../auth/check_totp.php';

header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['user_role'] !== 'patient') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$appointmentId = $_GET['appointment_id'] ?? null;
$userID = $_SESSION['user_id'];

if (!$appointmentId) {
    echo json_encode(['success' => false, 'message' => 'Appointment ID missing']);
    exit();
}

try {
    $pdo = getDBConnection();
    
    $stmt = $pdo->prepare("SELECT Amount 
                           FROM payment 
                           WHERE AppointmentID = :appointmentId
                           AND PaymentStatus = 'Pending'");
    $stmt->execute(['appointmentId' => $appointmentId]);
    
    $payment = $stmt->fetch();
    
    if ($payment) {
        echo json_encode(['success' => true, 'amount' => $payment['Amount']]);
    } else {
        echo json_encode(['success' => false, 'message' => 'No pending payment found']);
    }
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}