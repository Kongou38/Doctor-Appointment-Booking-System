<?php
require_once '../config.php';
require_once '../auth/check_totp.php';

try {
    $pdo = getDBConnection();
    
    $stmt = $pdo->prepare("
        SELECT d.DoctorID, u.NAME 
        FROM doctor d
        JOIN systemuser u ON d.UserID = u.UserID
        WHERE d.STATUS = 'Active'
    ");
    $stmt->execute();
    
    $doctors = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    header('Content-Type: application/json');
    echo json_encode($doctors);
    
} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Database error']);
}
?>