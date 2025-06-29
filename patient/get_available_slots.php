<?php
require_once '../auth/check_totp.php';
require_once '../config.php';

$doctorId = $_GET['doctor_id'] ?? 0;

try {
    $pdo = getDBConnection();
    
    $stmt = $pdo->prepare("
        SELECT SlotID, DAYOFWEEK, StartTime, EndTime 
        FROM timeslot 
        WHERE DoctorID = ? AND IsRecurring = 1
    ");
    $stmt->execute([$doctorId]);
    $recurringSlots = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $startDate = date('Y-m-d');
    $endDate = date('Y-m-d', strtotime('+30 days'));
    
    $stmt = $pdo->prepare("
        SELECT a.SlotID, ts.DAYOFWEEK, ts.StartTime, ts.EndTime, DATE(a.booked_datetime) as booking_date
        FROM appointment a
        JOIN timeslot ts ON a.SlotID = ts.SlotID
        WHERE a.DoctorID = ? 
        AND a.STATUS IN ('Pending', 'Approved')
        AND DATE(a.booked_datetime) BETWEEN ? AND ?
    ");
    $stmt->execute([$doctorId, $startDate, $endDate]);
    $bookedSlots = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $dateRange = [];
    $currentDate = new DateTime();
    $endDate = (new DateTime())->modify('+30 days');
    
    while ($currentDate <= $endDate) {
        $dayOfWeek = strtolower($currentDate->format('l'));
        $dateFormatted = $currentDate->format('Y-m-d');
        $displayDate = $currentDate->format('D, M j');
        
        foreach ($recurringSlots as $slot) {
            if ($slot['DAYOFWEEK'] === $dayOfWeek) {
                $isBooked = false;
                foreach ($bookedSlots as $bookedSlot) {
                    if ($bookedSlot['booking_date'] === $dateFormatted &&
                        $bookedSlot['DAYOFWEEK'] === $dayOfWeek &&
                        $bookedSlot['StartTime'] === $slot['StartTime'] &&
                        $bookedSlot['EndTime'] === $slot['EndTime']) {
                        $isBooked = true;
                        break;
                    }
                }
                
                $dateRange[$displayDate][] = [
                    'slotId' => $slot['SlotID'],
                    'dayOfWeek' => $dayOfWeek,
                    'startTime' => $slot['StartTime'],
                    'endTime' => $slot['EndTime'],
                    'isBooked' => $isBooked,
                    'date' => $dateFormatted
                ];
            }
        }
        
        $currentDate->modify('+1 day');
    }
    
    header('Content-Type: application/json');
    echo json_encode($dateRange);
    
} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Database error']);
}
?>