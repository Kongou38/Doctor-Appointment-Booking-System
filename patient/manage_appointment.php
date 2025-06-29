<?php
session_start();
require_once '../config.php';
require_once '../auth/check_totp.php';
require_once '../notification_helper.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['user_role'] !== 'patient') {
    header('Location:' . BASE_URL . '/auth/login.php');
    exit();
}

$userID = $_SESSION['user_id'];
$statusMessage = '';
$appointments = [];
$doctors = [];
$pdo = getDBConnection();

if (isset($_GET['action']) && $_GET['action'] === 'get_slots_for_doctor') {
    header('Content-Type: application/json');
    $doctorID = $_GET['doctor_id'] ?? null;

    if ($doctorID) {
        $availableSlots = getAvailableSlotsForDoctor($pdo, $doctorID);
        echo json_encode(['success' => true, 'availableSlots' => $availableSlots]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Doctor ID is missing.']);
    }
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $appointmentId = $_POST['appointment_id'];

        try {
            $stmtCheck = $pdo->prepare("SELECT AppointmentID FROM appointment WHERE AppointmentID = :id AND UserID = :userID");
            $stmtCheck->execute(['id' => $appointmentId, 'userID' => $userID]);
            
            if ($stmtCheck->rowCount() === 0) {
                throw new Exception("Appointment not found or not authorized");
            }

            switch ($_POST['action']) {
                case 'cancel':
                    $stmt = $pdo->prepare("UPDATE appointment SET STATUS = 'Cancelled' WHERE AppointmentID = :id");
                    $stmt->execute(['id' => $appointmentId]);
                    $stmtDoctor = $pdo->prepare("SELECT DoctorID FROM appointment WHERE AppointmentID = :id");
                    $stmtDoctor->execute(['id' => $appointmentId]);
                    $doctorID = $stmtDoctor->fetchColumn();
                    
                    $stmtDoctorUser = $pdo->prepare("SELECT UserID FROM doctor WHERE DoctorID = :doctorID");
                    $stmtDoctorUser->execute(['doctorID' => $doctorID]);
                    $doctorUserID = $stmtDoctorUser->fetchColumn();
                    
                    createNotification($pdo, $userID, "You have cancelled your appointment.");
                    
                    createNotification($pdo, $doctorUserID, "A patient has cancelled their appointment with you.");
                    $statusMessage = '<div class="alert alert-success mt-3">Appointment cancelled successfully.</div>';
                    break;

                case 'reschedule':
                    $newDoctorId = $_POST['new_doctor_id'];
                    $slotInfo = explode('|', $_POST['new_slot_info']);
                    $newSlotId = $slotInfo[0];
                    $newAppointmentDate = $slotInfo[1];
                    $startTime = $slotInfo[2];
                    
                    $stmtSlotCheck = $pdo->prepare("SELECT SlotID FROM timeslot WHERE SlotID = :slotId AND DoctorID = :doctorId");
                    $stmtSlotCheck->execute(['slotId' => $newSlotId, 'doctorId' => $newDoctorId]);
                    
                    if ($stmtSlotCheck->rowCount() === 0) {
                        throw new Exception("Invalid time slot for selected doctor");
                    }
                    
                    $stmtDoubleBook = $pdo->prepare("SELECT COUNT(*) FROM appointment 
                                                     WHERE DoctorID = :doctorId 
                                                     AND SlotID = :slotId
                                                     AND booked_datetime = :bookedDatetime
                                                     AND STATUS IN ('Pending', 'Approved')");
                    $stmtDoubleBook->execute([
                        'doctorId' => $newDoctorId,
                        'slotId' => $newSlotId,
                        'bookedDatetime' => $newAppointmentDate . ' ' . $startTime
                    ]);
                    
                    if ($stmtDoubleBook->fetchColumn() > 0) {
                        throw new Exception("The selected time slot is no longer available");
                    }

                    $stmt = $pdo->prepare("UPDATE appointment
                                           SET DoctorID = :newDoctorId,
                                               SlotID = :newSlotId,
                                               booked_datetime = :newBookedDatetime,
                                               STATUS = 'Pending'
                                           WHERE AppointmentID = :id");
                    $stmt->execute([
                        'newDoctorId' => $newDoctorId,
                        'newSlotId' => $newSlotId,
                        'newBookedDatetime' => $newAppointmentDate . ' ' . $startTime,
                        'id' => $appointmentId
                    ]);
                    
                    $stmtNewDoctor = $pdo->prepare("SELECT UserID FROM doctor WHERE DoctorID = :doctorID");
                    $stmtNewDoctor->execute(['doctorID' => $newDoctorId]);
                    $newDoctorUserID = $stmtNewDoctor->fetchColumn();
                    
                    if ($newDoctorId != $appointment['DoctorID']) {
                        $stmtOldDoctor = $pdo->prepare("SELECT UserID FROM doctor WHERE DoctorID = :doctorID");
                        $stmtOldDoctor->execute(['doctorID' => $appointment['DoctorID']]);
                        $oldDoctorUserID = $stmtOldDoctor->fetchColumn();
                        
                        createNotification($pdo, $oldDoctorUserID, "A patient has rescheduled their appointment with another doctor.");
                    }
                    
                    createNotification($pdo, $userID, "You have rescheduled your appointment.");
                    
                    createNotification($pdo, $newDoctorUserID, "A patient has rescheduled an appointment with you.");

                    $statusMessage = '<div class="alert alert-info mt-3">Appointment rescheduled successfully.</div>';
                    break;

                case 'process_payment':
                    $paymentMethod = $_POST['payment_method'];
                    $amount = $_POST['amount'];
                    
                    try {
                        $stmtCheck = $pdo->prepare("SELECT a.AppointmentID 
                                                   FROM appointment a
                                                   JOIN payment p ON a.AppointmentID = p.AppointmentID
                                                   WHERE a.AppointmentID = :id 
                                                   AND a.UserID = :userID
                                                   AND p.PaymentStatus = 'Pending'");
                        $stmtCheck->execute(['id' => $appointmentId, 'userID' => $userID]);
                        
                        if ($stmtCheck->rowCount() === 0) {
                            throw new Exception("Appointment not found or payment not required");
                        }

                        $stmt = $pdo->prepare("UPDATE payment 
                                               SET PaymentMethod = :method,
                                                   PaymentStatus = 'Completed',
                                                   TransactionDate = NOW()
                                               WHERE AppointmentID = :id");
                        $stmt->execute([
                            'method' => $paymentMethod,
                            'id' => $appointmentId
                        ]);
                        
                        $stmt = $pdo->prepare("UPDATE appointment 
                                               SET STATUS = 'Completed'
                                               WHERE AppointmentID = :id");
                        $stmt->execute(['id' => $appointmentId]);
                        
                        $stmtAppointment = $pdo->prepare("SELECT DoctorID FROM appointment WHERE AppointmentID = :id");
                        $stmtAppointment->execute(['id' => $appointmentId]);
                        $doctorID = $stmtAppointment->fetchColumn();
                        
                        $stmtDoctorUser = $pdo->prepare("SELECT UserID FROM doctor WHERE DoctorID = :doctorID");
                        $stmtDoctorUser->execute(['doctorID' => $doctorID]);
                        $doctorUserID = $stmtDoctorUser->fetchColumn();
                        
                        createNotification($pdo, $userID, "Your payment of RM " . number_format($amount, 2) . " has been processed successfully.");
                        
                        createNotification($pdo, $doctorUserID, "A patient has completed payment for their appointment.");

                        $statusMessage = '<div class="alert alert-success mt-3">Payment completed successfully.</div>';
                    } catch (Exception $e) {
                        $statusMessage = '<div class="alert alert-danger mt-3">Error processing payment: ' . $e->getMessage() . '</div>';
                    }
                    break;
            }
        } catch (Exception $e) {
            $statusMessage = '<div class="alert alert-danger mt-3">Error: ' . $e->getMessage() . '</div>';
        }
    }
}

try {
    $stmt = $pdo->prepare("SELECT d.DoctorID, su.NAME AS DoctorName
                           FROM doctor d
                           JOIN systemuser su ON d.UserID = su.UserID
                           WHERE d.STATUS = 'Active'");
    $stmt->execute();
    $doctors = $stmt->fetchAll();
} catch (PDOException $e) {
    $statusMessage .= '<div class="alert alert-danger">Error fetching doctors: ' . $e->getMessage() . '</div>';
}


try {
    $stmt = $pdo->prepare("
        SELECT
            a.AppointmentID,
            a.DoctorID,
            su_doctor.NAME AS DoctorName,
            a.booked_datetime,
            a.Symptoms,
            a.CreatedAt,
            a.STATUS,
            ts.StartTime,
            ts.EndTime,
            ts.DAYOFWEEK,
            p.Amount AS PaymentAmount,
            p.PaymentStatus
        FROM
            appointment a
        JOIN
            doctor d ON a.DoctorID = d.DoctorID
        JOIN
            systemuser su_doctor ON d.UserID = su_doctor.UserID
        JOIN
            timeslot ts ON a.SlotID = ts.SlotID
        LEFT JOIN
            payment p ON a.AppointmentID = p.AppointmentID
        WHERE 
            a.UserID = :userID
        ORDER BY
            a.booked_datetime ASC
    ");
    $stmt->execute(['userID' => $userID]);
    $appointments = $stmt->fetchAll();
} catch (PDOException $e) {
    $statusMessage .= '<div class="alert alert-danger">Error fetching appointments: ' . $e->getMessage() . '</div>';
}

function getAvailableSlotsForDoctor($pdo, $doctorID) {
    $availableSlots = [];
    $interval = new DateInterval('P30D');
    $today = new DateTime('now', new DateTimeZone('Asia/Kuala_Lumpur'));
    $endDate = (clone $today)->add($interval);

    try {
        $stmtSlots = $pdo->prepare("SELECT SlotID, DAYOFWEEK, StartTime, EndTime 
                                    FROM timeslot
                                    WHERE DoctorID = :doctorID");
        $stmtSlots->execute(['doctorID' => $doctorID]);
        $recurringSlots = $stmtSlots->fetchAll();

        $stmtBooked = $pdo->prepare("SELECT a.SlotID, DATE(a.booked_datetime) AS AppointmentDate, 
                                            TIME(a.booked_datetime) AS StartTime
                                     FROM appointment a
                                     WHERE a.DoctorID = :doctorID
                                     AND a.STATUS IN ('Pending', 'Approved')
                                     AND a.booked_datetime BETWEEN :startDate AND :endDate");
        $stmtBooked->execute([
            'doctorID' => $doctorID,
            'startDate' => $today->format('Y-m-d H:i:s'),
            'endDate' => $endDate->format('Y-m-d H:i:s')
        ]);
        
        $bookedAppointments = [];
        while ($row = $stmtBooked->fetch(PDO::FETCH_ASSOC)) {
            $bookedAppointments[$row['AppointmentDate']][] = $row;
        }

        $period = new DatePeriod($today, new DateInterval('P1D'), $endDate);

        foreach ($period as $date) {
            $dayOfWeek = strtolower($date->format('l'));
            $currentDateYMD = $date->format('Y-m-d');

            foreach ($recurringSlots as $slot) {
                if ($slot['DAYOFWEEK'] === $dayOfWeek) {
                    $slotDateTime = new DateTime($currentDateYMD . ' ' . $slot['StartTime'], 
                                              new DateTimeZone('Asia/Kuala_Lumpur'));

                    $isBooked = false;
                    if (isset($bookedAppointments[$currentDateYMD])) {
                        foreach ($bookedAppointments[$currentDateYMD] as $bookedSlot) {
                            if ($bookedSlot['SlotID'] == $slot['SlotID'] && 
                                $bookedSlot['StartTime'] == $slot['StartTime']) {
                                    $isBooked = true;
                                    break;
                            }
                        }
                    }

                    if (!$isBooked && $slotDateTime > $today) {
                        $availableSlots[] = [
                            'SlotID' => $slot['SlotID'],
                            'AppointmentDate' => $currentDateYMD,
                            'StartTime' => $slot['StartTime'],
                            'EndTime' => $slot['EndTime'],
                            'DayOfWeek' => ucfirst($dayOfWeek)
                        ];
                    }
                }
            }
        }
    } catch (PDOException $e) {
        error_log("Error getting available slots: " . $e->getMessage());
        return [];
    }
    return $availableSlots;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Appointments</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #4e73df;
            --secondary: #858796;
            --light: #f8f9fc;
        }

        body {
            background-color: #f8f9fa;
            font-family: 'Nunito', sans-serif;
            padding: 20px;
        }

        .history-container {
            max-width: 800px;
            margin: 0 auto;
        }

        .appointment-card {
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 15px;
            overflow: hidden;
        }

        .appointment-header {
            padding: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: pointer;
        }

        .appointment-header:hover {
            background-color: rgba(0,0,0,0.02);
        }

        .appointment-main-info {
            display: flex;
            flex-direction: column;
        }

        .appointment-date {
            font-weight: 600;
            color: var(--primary);
            margin-bottom: 3px;
        }

        .appointment-status {
            font-size: 0.9rem;
        }

        .status-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .status-pending {
            background-color: #f6c23e;
            color: white;
        }

        .status-processing {
            background-color: #36b9cc;
            color: white;
        }

        .status-approved {
            background-color: #1cc88a;
            color: white;
        }

        .status-cancelled {
            background-color: #e74a3b;
            color: white;
        }

        .status-payment {
            background-color: #36b9cc;
            color: white;
        }

        .status-completed {
            background-color: #6c757d;
            color: white;
        }

        .appointment-toggle {
            transition: transform 0.3s;
            color: var(--secondary);
        }

        .appointment-toggle.collapsed {
            transform: rotate(-90deg);
        }

        .appointment-details {
            padding: 0 15px;
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease-out, padding 0.3s ease;
        }

        .appointment-details.show {
            padding: 15px;
            max-height: 500px;
            border-top: 1px solid #eee;
        }

        .detail-row {
            display: flex;
            margin-bottom: 10px;
            gap: 15px;
        }
        
        .detail-label {
            font-weight: 600;
            color: var(--secondary);
            width: 100px;
            flex-shrink: 0;
            text-align: right;
        }
        
        .detail-value {
            flex: 1;
            word-break: break-word;
            min-width: 0;
        }

        .symptoms-value {
            white-space: pre-line;
            line-height: 1.5;
        }

        .action-buttons {
            display: flex;
            gap: 10px;
            margin-top: 15px;
            justify-content: flex-end;
            flex-wrap: wrap;
        }

        .btn-reschedule {
            background-color: var(--primary);
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 4px;
            font-size: 14px;
            cursor: pointer;
        }

        .btn-reschedule:hover {
            background-color: #3a5bc7;
        }

        .btn-cancel {
            background-color: white;
            color: #e74a3b;
            border: 1px solid #e74a3b;
            padding: 8px 15px;
            border-radius: 4px;
            font-size: 14px;
            cursor: pointer;
        }

        .btn-cancel:hover {
            background-color: #e74a3b;
            color: white;
        }

        .btn-pay {
            background-color: #1cc88a;
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 4px;
            font-size: 14px;
            cursor: pointer;
        }

        .btn-pay:hover {
            background-color: #17a673;
        }

        .btn-return {
            display: block;
            margin-top: 20px;
            text-align: center;
            color: var(--secondary);
            text-decoration: none;
            padding: 8px;
            border-radius: 4px;
        }

        .btn-return:hover {
            background-color: rgba(0,0,0,0.05);
        }

        .btn-return i {
            margin-right: 5px;
        }

        .no-appointments {
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 20px;
            text-align: center;
            color: var(--secondary);
        }

        .modal-content {
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.3);
        }

        .modal-header {
            background-color: var(--primary);
            color: white;
            border-bottom: none;
            padding: 15px;
        }

        .modal-header .btn-close {
            filter: invert(1);
        }

        .modal-title {
            font-size: 1.2rem;
        }

        .modal-body {
            padding: 20px;
        }

        .modal-footer {
            border-top: none;
            padding: 15px;
            justify-content: center;
        }

        .form-label {
            font-weight: 600;
            color: var(--secondary);
        }

        .time-slot-option {
            display: flex;
            justify-content: space-between;
        }

        @media (max-width: 576px) {
            .action-buttons {
                flex-direction: column;
                gap: 8px;
            }
            
            .btn-reschedule,
            .btn-cancel,
            .btn-pay {
                width: 100%;
            }
            
            .detail-row {
                flex-direction: column;
                gap: 5px;
            }
            
            .detail-label {
                text-align: left;
                width: auto;
            }
        }
    </style>
</head>
<body>
    <div class="history-container">
        <h4 class="mb-4"><i class="fas fa-calendar-check me-2"></i>My Appointments</h4>
        
        <?php echo $statusMessage; ?>
        
        <?php if (!empty($appointments)): ?>
            <?php foreach ($appointments as $index => $appointment): ?>
                <div class="appointment-card">
                    <div class="appointment-header" onclick="toggleDetails(<?= $index ?>)">
                        <div class="appointment-main-info">
                            <div class="appointment-date">
                                <?= (new DateTime($appointment['booked_datetime']))->format('d/m/Y') ?>
                                <span class="appointment-time"><?= (new DateTime($appointment['booked_datetime']))->format('h:i A') ?></span>
                            </div>
                            <div class="appointment-status">
                                Status: <span class="status-badge status-<?= strtolower($appointment['STATUS']) ?>">
                                    <?= htmlspecialchars($appointment['STATUS']) ?>
                                </span>
                                <?php if ($appointment['STATUS'] === 'Payment' && isset($appointment['PaymentAmount'])): ?>
                                    <span class="ms-2">(RM <?= number_format($appointment['PaymentAmount'], 2) ?>)</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <i class="fas fa-chevron-down appointment-toggle" id="toggle-<?= $index ?>"></i>
                    </div>
                    <div class="appointment-details" id="details-<?= $index ?>">
                        <div class="detail-row">
                            <div class="detail-label">Doctor:</div>
                            <div class="detail-value"><?= htmlspecialchars($appointment['DoctorName']) ?></div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Day/Time:</div>
                            <div class="detail-value">
                                <?= ucfirst($appointment['DAYOFWEEK']) ?> 
                                <?= (new DateTime($appointment['StartTime']))->format('g:i a') ?> - 
                                <?= (new DateTime($appointment['EndTime']))->format('g:i a') ?>
                            </div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Symptoms:</div>
                            <div class="detail-value symptoms-value"><?= htmlspecialchars($appointment['Symptoms']) ?></div>
                        </div>
                        
                        <div class="action-buttons">
                            <?php if ($appointment['STATUS'] === 'Pending'): ?>
                                <button class="btn-reschedule" data-bs-toggle="modal" data-bs-target="#rescheduleModal" 
                                    data-appointment-id="<?= $appointment['AppointmentID'] ?>"
                                    data-current-doctor-id="<?= $appointment['DoctorID'] ?>">
                                    <i class="fas fa-calendar-alt me-1"></i>Reschedule
                                </button>
                                <button class="btn-cancel" data-appointment-id="<?= $appointment['AppointmentID'] ?>" onclick="confirmCancel(this)">
                                    <i class="fas fa-times me-1"></i>Cancel
                                </button>
                            <?php elseif ($appointment['STATUS'] === 'Payment'): ?>
                                <button class="btn-pay" data-bs-toggle="modal" data-bs-target="#paymentModal" 
                                    data-appointment-id="<?= $appointment['AppointmentID'] ?>">
                                    <i class="fas fa-credit-card me-1"></i>Pay Now
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="no-appointments">
                <p><i class="fas fa-info-circle me-2"></i>You don't have any appointments yet.</p>
            </div>
        <?php endif; ?>
        
        <a href="dashboard.php" class="btn-return">
            <i class="fas fa-arrow-left"></i>Return to Dashboard
        </a>
    </div>

    <div class="modal fade" id="cancelConfirmModal" tabindex="-1" aria-labelledby="cancelConfirmModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="cancelConfirmModalLabel">Confirm Cancellation</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="" method="POST">
                    <div class="modal-body">
                        <p>Are you sure you want to cancel this appointment?</p>
                        <input type="hidden" name="appointment_id" id="modalCancelAppointmentId">
                        <input type="hidden" name="action" value="cancel">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">No, Keep It</button>
                        <button type="submit" class="btn btn-danger">Yes, Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="rescheduleModal" tabindex="-1" aria-labelledby="rescheduleModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form action="" method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title" id="rescheduleModalLabel">Reschedule Appointment</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="reschedule">
                        <input type="hidden" name="appointment_id" id="rescheduleAppointmentId">

                        <div class="mb-3">
                            <label for="newDoctorSelect" class="form-label">Select Doctor:</label>
                            <select class="form-select" id="newDoctorSelect" name="new_doctor_id" required>
                                <option value="">-- Select a Doctor --</option>
                                <?php foreach ($doctors as $doctor): ?>
                                    <option value="<?= htmlspecialchars($doctor['DoctorID']) ?>">
                                        <?= htmlspecialchars($doctor['DoctorName']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label for="newTimeSlotSelect" class="form-label">Available Time Slots:</label>
                            <select class="form-select" id="newTimeSlotSelect" name="new_slot_info" required>
                                <option value="">-- Select a Doctor First --</option>
                            </select>
                            <small class="form-text text-muted">Showing available slots for the next 30 days</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Confirm Reschedule</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="paymentModal" tabindex="-1" aria-labelledby="paymentModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form id="paymentForm" action="" method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title" id="paymentModalLabel">Complete Payment</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="process_payment">
                        <input type="hidden" name="appointment_id" id="paymentAppointmentId">
                        
                        <div class="mb-3">
                            <label class="form-label">Amount to Pay:</label>
                            <div class="form-control" id="paymentAmountDisplay">Loading...</div>
                            <input type="hidden" name="amount" id="paymentAmount">
                        </div>
                        
                        <div class="mb-3">
                            <label for="paymentMethod" class="form-label">Payment Method:</label>
                            <select class="form-select" id="paymentMethod" name="payment_method" required>
                                <option value="">-- Select Payment Method --</option>
                                <option value="Cash">Cash</option>
                                <option value="Online Banking">Online Banking</option>
                                <option value="Insurance">Insurance</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">Proceed to Payment</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="paymentProcessingModal" tabindex="-1" aria-labelledby="paymentProcessingModalLabel" aria-hidden="true" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="paymentProcessingModalLabel">Processing Payment</h5>
                </div>
                <div class="modal-body text-center">
                    <div class="spinner-border text-primary mb-3" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p id="paymentStatusMessage">Connecting to payment gateway...</p>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function toggleDetails(index) {
            const details = document.getElementById(`details-${index}`);
            const toggle = document.getElementById(`toggle-${index}`);
            
            details.classList.toggle('show');
            toggle.classList.toggle('collapsed');
        }

        function formatTimeForDisplay(time24hr) {
            const [hours, minutes] = time24hr.split(':');
            const hour = parseInt(hours, 10);
            const ampm = hour >= 12 ? 'PM' : 'AM';
            const displayHour = hour % 12 || 12;
            return `${displayHour}:${minutes} ${ampm}`;
        }

        function formatDateForDisplay(dateYMD) {
            const [year, month, day] = dateYMD.split('-');
            return `${day}/${month}/${year}`;
        }

        const rescheduleModal = document.getElementById('rescheduleModal');
        rescheduleModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            const appointmentId = button.getAttribute('data-appointment-id');
            const currentDoctorId = button.getAttribute('data-current-doctor-id');
            
            document.getElementById('rescheduleAppointmentId').value = appointmentId;
            document.getElementById('newDoctorSelect').value = currentDoctorId;
            
            populateNewTimeSlots(currentDoctorId);
        });

        document.getElementById('newDoctorSelect').addEventListener('change', function() {
            populateNewTimeSlots(this.value);
        });

        async function populateNewTimeSlots(doctorID) {
            const timeSlotSelect = document.getElementById('newTimeSlotSelect');
            
            if (!doctorID) {
                timeSlotSelect.innerHTML = '<option value="">-- Select a Doctor First --</option>';
                return;
            }

            timeSlotSelect.innerHTML = '<option value="">Loading available slots...</option>';
            
            try {
                const response = await fetch(`manage_appointment.php?action=get_slots_for_doctor&doctor_id=${doctorID}`);
                const result = await response.json();
                
                timeSlotSelect.innerHTML = '';
                
                if (result.success && result.availableSlots.length > 0) {
                    timeSlotSelect.innerHTML = '<option value="">-- Select a Time Slot --</option>';
                    
                    result.availableSlots.forEach(slot => {
                        const option = document.createElement('option');
                        option.value = `${slot.SlotID}|${slot.AppointmentDate}|${slot.StartTime}`;
                        
                        const optionText = document.createElement('span');
                        optionText.className = 'time-slot-option';
                        optionText.innerHTML = `
                            <span>${slot.DayOfWeek}, ${formatDateForDisplay(slot.AppointmentDate)}</span>
                            <span>${formatTimeForDisplay(slot.StartTime)} - ${formatTimeForDisplay(slot.EndTime)}</span>
                        `;
                        
                        option.appendChild(optionText);
                        timeSlotSelect.appendChild(option);
                    });
                } else {
                    timeSlotSelect.innerHTML = '<option value="">No available slots found for this doctor</option>';
                }
            } catch (error) {
                console.error('Error fetching available slots:', error);
                timeSlotSelect.innerHTML = '<option value="">Error loading slots. Please try again.</option>';
            }
        }

        function confirmCancel(button) {
            const appointmentId = button.getAttribute('data-appointment-id');
            const modal = new bootstrap.Modal(document.getElementById('cancelConfirmModal'));
            document.getElementById('modalCancelAppointmentId').value = appointmentId;
            modal.show();
        }

        const paymentModal = document.getElementById('paymentModal');
        paymentModal.addEventListener('show.bs.modal', async function (event) {
            const button = event.relatedTarget;
            const appointmentId = button.getAttribute('data-appointment-id');
            
            document.getElementById('paymentAppointmentId').value = appointmentId;
            
            try {
                const response = await fetch(`get_payment_amount.php?appointment_id=${appointmentId}`);
                const result = await response.json();
                
                if (result.success) {
                    const amount = parseFloat(result.amount) || 0;
                    document.getElementById('paymentAmountDisplay').textContent = `RM ${amount.toFixed(2)}`;
                    document.getElementById('paymentAmount').value = amount;
                } else {
                    document.getElementById('paymentAmountDisplay').textContent = 'Error loading amount';
                }
            } catch (error) {
                console.error('Error fetching payment amount:', error);
                document.getElementById('paymentAmountDisplay').textContent = 'Error loading amount';
            }
        });

        document.getElementById('paymentForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const paymentModal = bootstrap.Modal.getInstance(document.getElementById('paymentModal'));
            paymentModal.hide();
            
            const processingModal = new bootstrap.Modal(document.getElementById('paymentProcessingModal'));
            processingModal.show();
            
            const statusMessage = document.getElementById('paymentStatusMessage');
            statusMessage.textContent = 'Connecting to payment gateway...';
            
            setTimeout(() => {
                statusMessage.textContent = 'Processing payment...';
            }, 1500);
            
            setTimeout(() => {
                statusMessage.textContent = 'Verifying transaction...';
            }, 3000);
            
            setTimeout(() => {
                this.submit();
            }, 4500);
        });
    </script>
</body>
</html>