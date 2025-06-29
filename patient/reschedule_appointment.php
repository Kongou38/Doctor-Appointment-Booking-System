<?php
require_once '../config.php';
require_once '../auth/check_totp.php';
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['user_role'] !== 'patient') {
    header('Location:' . BASE_URL . '/auth/login.php');
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);

if (empty($input['appointment_id']) || empty($input['doctor_id']) || empty($input['slot_id']) || empty($input['slot_date'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit();
}

$appointmentId = (int)$input['appointment_id'];
$doctorId = (int)$input['doctor_id'];
$slotId = (int)$input['slot_id'];
$slotDate = $input['slot_date'];
$startTime = $input['start_time'] ?? '00:00:00';

try {
    $pdo = getDBConnection();
    
    $pdo->beginTransaction();
    
    $stmt = $pdo->prepare("
        SELECT AppointmentID 
        FROM appointment 
        WHERE AppointmentID = ? 
        AND UserID = ?
        AND STATUS IN ('Pending', 'Approved')
    ");
    $stmt->execute([$appointmentId, $_SESSION['user_id']]);
    
    if ($stmt->rowCount() === 0) {
        $pdo->rollBack();
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Appointment not found, not authorized, or not reschedulable']);
        exit();
    }
    
    $stmt = $pdo->prepare("
        SELECT SlotID, StartTime 
        FROM timeslot 
        WHERE SlotID = ? AND DoctorID = ?
    ");
    $stmt->execute([$slotId, $doctorId]);
    
    if ($stmt->rowCount() === 0) {
        $pdo->rollBack();
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Invalid time slot for selected doctor']);
        exit();
    }
    
    $slotData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $stmt = $pdo->prepare("
        SELECT a.AppointmentID 
        FROM appointment a
        WHERE a.SlotID = ? 
        AND a.STATUS IN ('Pending', 'Approved')
        AND DATE(a.booked_datetime) = ?
        AND a.AppointmentID != ?
    ");
    $stmt->execute([$slotId, $slotDate, $appointmentId]);
    
    if ($stmt->rowCount() > 0) {
        $pdo->rollBack();
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'This time slot is already booked']);
        exit();
    }
    
    $bookedDatetime = date('Y-m-d H:i:s', strtotime("$slotDate $startTime"));
    
    $updateStmt = $pdo->prepare("
        UPDATE appointment 
        SET 
            DoctorID = :doctor_id, 
            SlotID = :slot_id, 
            STATUS = 'Pending', 
            booked_datetime = :booked_datetime,
            CreatedAt = NOW()
        WHERE 
            AppointmentID = :appointment_id
    ");
    
    $updateStmt->execute([
        ':doctor_id' => $doctorId,
        ':slot_id' => $slotId,
        ':booked_datetime' => $bookedDatetime,
        ':appointment_id' => $appointmentId
    ]);
    
    $pdo->commit();
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
    
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Database error: " . $e->getMessage());
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false, 
        'message' => 'Database error',
        'error_details' => $e->getMessage()
    ]);
}
?>