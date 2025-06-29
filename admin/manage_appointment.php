<?php
session_start();
include '../config.php';
require_once '../notification_helper.php';
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $appointmentId = $_POST['appointment_id'];

        try {
            switch ($_POST['action']) {
                case 'approve':
                    $stmtInfo = $pdo->prepare("SELECT UserID, DoctorID FROM appointment WHERE AppointmentID = :id");
                    $stmtInfo->execute(['id' => $appointmentId]);
                    $appointmentInfo = $stmtInfo->fetch();
                    
                    $stmt = $pdo->prepare("UPDATE appointment SET STATUS = 'Approved' WHERE AppointmentID = :id");
                    $stmt->execute(['id' => $appointmentId]);
                    
                    if ($appointmentInfo) {
                        createNotification($pdo, $appointmentInfo['UserID'], "Your appointment has been approved by the admin.");
                        createNotification($pdo, $appointmentInfo['DoctorID'], "A new appointment has been assigned to you and approved.");
                    }
                    
                    $statusMessage = '<div class="alert alert-success mt-3">Appointment ' . htmlspecialchars($appointmentId) . ' approved successfully.</div>';
                    break;

                case 'deny':
                    $stmt = $pdo->prepare("UPDATE appointment SET STATUS = 'Denied' WHERE AppointmentID = :id");
                    $stmt->execute(['id' => $appointmentId]);
                    $stmtPatient = $pdo->prepare("SELECT UserID FROM appointment WHERE AppointmentID = :id");
                    $stmtPatient->execute(['id' => $appointmentId]);
                    $patientID = $stmtPatient->fetchColumn();
                    createNotification($pdo, $patientID, "Your appointment request has been denied by the admin.");
                    $statusMessage = '<div class="alert alert-warning mt-3">Appointment ' . htmlspecialchars($appointmentId) . ' denied.</div>';
                    break;

                case 'reassign':
                    $newDoctorId = $_POST['new_doctor_id'];
                    $slotInfo = explode('|', $_POST['new_slot_info']);
                    $newSlotId = $slotInfo[0];
                    $newAppointmentDate = $slotInfo[1];

                    $stmtSlotDetails = $pdo->prepare("SELECT StartTime, EndTime FROM timeslot WHERE SlotID = :slotId");
                    $stmtSlotDetails->execute(['slotId' => $newSlotId]);
                    $slotDetails = $stmtSlotDetails->fetch();

                    if ($slotDetails) {
                        $newBookedDatetime = $newAppointmentDate . ' ' . $slotDetails['StartTime'];

                        $stmtCheckSlot = $pdo->prepare("SELECT COUNT(*) FROM appointment 
                                                       WHERE DoctorID = :doctorId 
                                                       AND SlotID = :slotId
                                                       AND booked_datetime = :bookedDatetime
                                                       AND STATUS IN ('Pending', 'Approved')
                                                       AND AppointmentID != :currentAppointmentId");
                        $stmtCheckSlot->execute([
                            'doctorId' => $newDoctorId,
                            'slotId' => $newSlotId,
                            'bookedDatetime' => $newBookedDatetime,
                            'currentAppointmentId' => $appointmentId
                        ]);
                        
                        if ($stmtCheckSlot->fetchColumn() > 0) {
                            $statusMessage = '<div class="alert alert-danger mt-3">Error: The selected time slot is no longer available.</div>';
                            break;
                        }

                        $pdo->beginTransaction();

                        $stmt = $pdo->prepare("UPDATE appointment
                                               SET DoctorID = :newDoctorId,
                                                   SlotID = :newSlotId,
                                                   booked_datetime = :newBookedDatetime
                                               WHERE AppointmentID = :id");
                        $stmt->execute([
                            'newDoctorId' => $newDoctorId,
                            'newSlotId' => $newSlotId,
                            'newBookedDatetime' => $newBookedDatetime,
                            'id' => $appointmentId
                        ]);

                        $pdo->commit();
                        $stmtInfo = $pdo->prepare("SELECT UserID, DoctorID FROM appointment WHERE AppointmentID = :id");
                        $stmtInfo->execute(['id' => $appointmentId]);
                        $appointmentInfo = $stmtInfo->fetch();
                        
                        $stmtDoctor = $pdo->prepare("SELECT su.NAME FROM doctor d JOIN systemuser su ON d.UserID = su.UserID WHERE d.DoctorID = :doctorID");
                        $stmtDoctor->execute(['doctorID' => $appointmentInfo['DoctorID']]);
                        $doctorName = $stmtDoctor->fetchColumn();
                        
                        createNotification($pdo, $appointmentInfo['UserID'], "Your appointment has been reassigned to Dr. $doctorName.");
                        
                        createNotification($pdo, $appointmentInfo['DoctorID'], "A new appointment has been assigned to you.");
                        $statusMessage = '<div class="alert alert-info mt-3">Appointment ' . htmlspecialchars($appointmentId) . ' reassigned successfully (status unchanged).</div>';
                    } else {
                        $statusMessage = '<div class="alert alert-danger mt-3">Error: Selected new time slot details not found.</div>';
                    }
                    break;
                case 'prepare_prescription_billing':
                    $billingAmount = filter_var($_POST['billing_amount'], FILTER_VALIDATE_FLOAT);

                    if ($billingAmount === false || $billingAmount < 0) {
                        $statusMessage = '<div class="alert alert-danger mt-3">Invalid billing amount. Please enter a valid number.</div>';
                        break;
                    }

                    $pdo->beginTransaction();

                    $stmtCheckPayment = $pdo->prepare("SELECT PaymentID FROM payment WHERE AppointmentID = :appointmentId");
                    $stmtCheckPayment->execute(['appointmentId' => $appointmentId]);
                    $existingPayment = $stmtCheckPayment->fetch();

                    if ($existingPayment) {
                         $stmtUpdatePayment = $pdo->prepare("UPDATE payment 
                                                           SET Amount = :amount, PaymentStatus = 'Pending' 
                                                           WHERE AppointmentID = :appointmentId");
                         $stmtUpdatePayment->execute([
                             'amount' => $billingAmount,
                             'appointmentId' => $appointmentId
                         ]);
                    } else {
                        $stmtInsertPayment = $pdo->prepare("INSERT INTO payment (AppointmentID, Amount, PaymentStatus) 
                                                          VALUES (:appointmentId, :amount, 'Pending')");
                        $stmtInsertPayment->execute([
                            'appointmentId' => $appointmentId,
                            'amount' => $billingAmount
                        ]);
                    }

                    $stmtUpdateAppointmentStatus = $pdo->prepare("UPDATE appointment SET STATUS = 'Payment' WHERE AppointmentID = :id");
                    $stmtUpdateAppointmentStatus->execute(['id' => $appointmentId]);

                    $pdo->commit();
                    $stmtPatient = $pdo->prepare("SELECT UserID FROM appointment WHERE AppointmentID = :id");
                    $stmtPatient->execute(['id' => $appointmentId]);
                    $patientID = $stmtPatient->fetchColumn();
                    
                    createNotification($pdo, $patientID, "Your appointment billing details are ready. Please check the payment section.");
                    $statusMessage = '<div class="alert alert-success mt-3">Billing details saved for appointment ' . htmlspecialchars($appointmentId) . '. Status set to Completed.</div>';
                    break;
            }
        } catch (PDOException $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $statusMessage = '<div class="alert alert-danger mt-3">Database error: ' . $e->getMessage() . '</div>';
        }
    }
}

// Filtering
$filterPeriod = $_GET['filter_period'] ?? 'all';
$sqlWhereClause = "";
$currentDateTime = new DateTime('now', new DateTimeZone('Asia/Kuala_Lumpur'));

switch ($filterPeriod) {
    case 'today':
        $sqlWhereClause .= " AND DATE(a.booked_datetime) = CURDATE()";
        break;
    case 'this_week':
        $startOfWeek = clone $currentDateTime;
        $startOfWeek->modify('last monday');
        $endOfWeek = clone $startOfWeek;
        $endOfWeek->modify('+6 days');
        $sqlWhereClause .= " AND a.booked_datetime BETWEEN '" . $startOfWeek->format('Y-m-d 00:00:00') . "' AND '" . $endOfWeek->format('Y-m-d 23:59:59') . "'";
        break;
    case 'next_7_days':
        $start = clone $currentDateTime;
        $end = (clone $currentDateTime)->modify('+7 days');
        $sqlWhereClause .= " AND a.booked_datetime BETWEEN '" . $start->format('Y-m-d 00:00:00') . "' AND '" . $end->format('Y-m-d 23:59:59') . "'";
        break;
    case 'this_month':
        $sqlWhereClause .= " AND MONTH(a.booked_datetime) = MONTH(CURDATE()) AND YEAR(a.booked_datetime) = YEAR(CURDATE())";
        break;
}

try {
    $stmt = $pdo->prepare("
        SELECT
            a.AppointmentID,
            a.DoctorID,
            su_user.NAME AS UserName,
            su_doctor.NAME AS DoctorName,
            a.booked_datetime,
            a.Symptoms,
            a.CreatedAt,
            a.STATUS,
            ts.StartTime,
            ts.EndTime,
            ts.DAYOFWEEK,
            ad.Notes AS AdminNotes,        -- Fetch existing notes from appointmentdetail
            ad.Prescription AS Prescription, -- Fetch existing prescription from appointmentdetail
            p.Amount AS BillingAmount      -- Fetch existing billing amount from payment
        FROM
            appointment a
        JOIN
            systemuser su_user ON a.UserID = su_user.UserID
        JOIN
            doctor d ON a.DoctorID = d.DoctorID
        JOIN
            systemuser su_doctor ON d.UserID = su_doctor.UserID
        JOIN
            timeslot ts ON a.SlotID = ts.SlotID
        LEFT JOIN
            appointmentdetail ad ON a.AppointmentID = ad.AppointmentID
        LEFT JOIN
            payment p ON a.AppointmentID = p.AppointmentID
        " . $sqlWhereClause . "
        ORDER BY
            a.booked_datetime ASC
    ");
    $stmt->execute();
    $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $statusMessage .= '<div class="alert alert-danger">Error fetching appointments: ' . $e->getMessage() . '</div>';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Manage Appointments</title>
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

        .status-approved {
            background-color: #1cc88a;
            color: white;
        }

        .status-denied {
            background-color: #e74a3b;
            color: white;
        }

        .status-processing {
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

        .btn-approve {
            background-color: #1cc88a;
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 4px;
            font-size: 14px;
            cursor: pointer;
        }

        .btn-approve:hover {
            background-color: #17a673;
        }

        .btn-reassign {
            background-color: #36b9cc;
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 4px;
            font-size: 14px;
            cursor: pointer;
        }

        .btn-reassign:hover {
            background-color: #2c9faf;
        }
        .btn-prepare-billing {
            background-color: #6610f2;
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 4px;
            font-size: 14px;
            cursor: pointer;
        }

        .btn-prepare-billing:hover {
            background-color: #560bc4;
        }

        .btn-deny {
            background-color: white;
            color: #e74a3b;
            border: 1px solid #e74a3b;
            padding: 8px 15px;
            border-radius: 4px;
            font-size: 14px;
            cursor: pointer;
        }

        .btn-deny:hover {
            background-color: #e74a3b;
            color: white;
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

        .filter-container {
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 15px;
            margin-bottom: 20px;
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

        @media (max-width: 576px) {
            .action-buttons {
                flex-direction: column;
                gap: 8px;
            }
            
            .btn-approve,
            .btn-reassign,
            .btn-deny,
            .btn-prepare-billing {
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
        <h4 class="mb-4"><i class="fas fa-calendar-check me-2"></i>Manage Appointments</h4>
        
        <?php echo $statusMessage; ?>
        
        <div class="filter-container mb-4">
            <form action="" method="GET" class="row g-3 align-items-center">
                <div class="col-md-3">
                    <label for="filter_period" class="form-label mb-md-0">Filter by:</label>
                </div>
                <div class="col-md-6">
                    <select class="form-select" id="filter_period" name="filter_period" onchange="this.form.submit()">
                        <option value="all" <?= ($filterPeriod === 'all') ? 'selected' : '' ?>>All Appointments</option>
                        <option value="today" <?= ($filterPeriod === 'today') ? 'selected' : '' ?>>Today</option>
                        <option value="this_week" <?= ($filterPeriod === 'this_week') ? 'selected' : '' ?>>This Week</option>
                        <option value="next_7_days" <?= ($filterPeriod === 'next_7_days') ? 'selected' : '' ?>>Next 7 Days</option>
                        <option value="this_month" <?= ($filterPeriod === 'this_month') ? 'selected' : '' ?>>This Month</option>
                    </select>
                </div>
                <div class="col-md-3 d-grid">
                    <button type="submit" class="btn btn-primary">Apply Filter</button>
                </div>
            </form>
        </div>
        
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
                            </div>
                        </div>
                        <i class="fas fa-chevron-down appointment-toggle" id="toggle-<?= $index ?>"></i>
                    </div>
                    <div class="appointment-details" id="details-<?= $index ?>">
                        <div class="detail-row">
                            <div class="detail-label">Patient:</div>
                            <div class="detail-value"><?= htmlspecialchars($appointment['UserName']) ?></div>
                        </div>
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
                                <button class="btn-approve" data-bs-toggle="modal" data-bs-target="#approveConfirmModal" data-appointment-id="<?= $appointment['AppointmentID'] ?>">
                                    <i class="fas fa-check me-1"></i>Approve
                                </button>
                            <?php endif; ?>
                            
                            <?php if ($appointment['STATUS'] !== 'Processing' && $appointment['STATUS'] !== 'Completed'):?>
                                <button class="btn-reassign" data-bs-toggle="modal" data-bs-target="#reassignModal" 
                                    data-appointment-id="<?= $appointment['AppointmentID'] ?>"
                                    data-current-doctor-id="<?= $appointment['DoctorID'] ?>">
                                    <i class="fas fa-exchange-alt me-1"></i>Reassign
                                </button>
                            <?php endif; ?>

                            <?php if ($appointment['STATUS'] === 'Processing'): ?>
                                <button class="btn-prepare-billing" data-bs-toggle="modal" data-bs-target="#preparePrescriptionBillingModal"
                                    data-appointment-id="<?= $appointment['AppointmentID'] ?>"
                                    data-prescription="<?= htmlspecialchars($appointment['Prescription'] ?? '') ?>"
                                    data-notes="<?= htmlspecialchars($appointment['AdminNotes'] ?? '') ?>"
                                    data-billing-amount="<?= htmlspecialchars($appointment['BillingAmount'] ?? '') ?>">
                                    <i class="fas fa-file-invoice-dollar me-1"></i>Prepare Prescription & Billing
                                </button>
                            <?php endif; ?>
                            
                            <?php if ($appointment['STATUS'] === 'Pending'): ?>
                                <button class="btn-deny" data-appointment-id="<?= $appointment['AppointmentID'] ?>" onclick="confirmDeny(this)">
                                    <i class="fas fa-times me-1"></i>Deny
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="no-appointments">
                <p><i class="fas fa-info-circle me-2"></i>No appointments found for the selected period.</p>
            </div>
        <?php endif; ?>
        
        <a href="dashboard.php" class="btn-return">
            <i class="fas fa-arrow-left"></i>Return to Dashboard
        </a>
    </div>

    <div class="modal fade" id="approveConfirmModal" tabindex="-1" aria-labelledby="approveConfirmModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="approveConfirmModalLabel">Confirm Approval</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="" method="POST">
                    <div class="modal-body">
                        <p>Are you sure you want to approve this appointment?</p>
                        <input type="hidden" name="appointment_id" id="modalApproveAppointmentId">
                        <input type="hidden" name="action" value="approve">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">Confirm Approval</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="reassignModal" tabindex="-1" aria-labelledby="reassignModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form action="" method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title" id="reassignModalLabel">Reassign Appointment</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="reassign">
                        <input type="hidden" name="appointment_id" id="reassignAppointmentId">

                        <div class="mb-3">
                            <label for="newDoctorSelect" class="form-label">Select New Doctor:</label>
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
                            <small class="form-text text-muted">Showing slots for the next 30 days.</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Reassignment</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="preparePrescriptionBillingModal" tabindex="-1" aria-labelledby="preparePrescriptionBillingModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form action="" method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title" id="preparePrescriptionBillingModalLabel">Prepare Prescription & Billing</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="prepare_prescription_billing">
                        <input type="hidden" name="appointment_id" id="prepareBillingAppointmentId">

                        <div class="mb-3">
                            <label for="prescription" class="form-label">Prescription:</label>
                            <textarea class="form-control" id="prescription" name="prescription" rows="5" placeholder="Prescription details" readonly></textarea>
                        </div>

                        <div class="mb-3">
                            <label for="notes" class="form-label">Notes:</label>
                            <textarea class="form-control" id="notes" name="notes" rows="3" placeholder="Additional notes" readonly></textarea>
                        </div>

                        <div class="mb-3">
                            <label for="billingAmount" class="form-label">Billing Price (MYR):</label>
                            <input type="number" step="0.01" min="0" class="form-control" id="billingAmount" name="billing_amount" placeholder="e.g., 50.00" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save</button>
                    </div>
                </form>
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

        const approveConfirmModal = document.getElementById('approveConfirmModal');
        approveConfirmModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            const appointmentId = button.getAttribute('data-appointment-id');
            document.getElementById('modalApproveAppointmentId').value = appointmentId;
        });

        const reassignModal = document.getElementById('reassignModal');
        reassignModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            const appointmentId = button.getAttribute('data-appointment-id');
            const currentDoctorId = button.getAttribute('data-current-doctor-id');
            const currentStatus = button.closest('.appointment-card').querySelector('.status-badge').textContent.trim();
            
            document.getElementById('reassignAppointmentId').value = appointmentId;
            document.getElementById('newDoctorSelect').value = currentDoctorId;
            
            document.getElementById('reassignModalLabel').textContent = 
                `Reassign Appointment (Current Status: ${currentStatus})`;
            
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
                        option.value = `${slot.SlotID}|${slot.AppointmentDate}`;
                        option.textContent = `${formatDateForDisplay(slot.AppointmentDate)}, ${formatTimeForDisplay(slot.StartTime)} - ${formatTimeForDisplay(slot.EndTime)}`;
                        timeSlotSelect.appendChild(option);
                    });
                } else {
                    timeSlotSelect.innerHTML = '<option value="">No available slots found</option>';
                }
            } catch (error) {
                console.error('Error fetching available slots:', error);
                timeSlotSelect.innerHTML = '<option value="">Error loading slots</option>';
            }
        }

        function confirmDeny(button) {
            if (confirm('Are you sure you want to deny this appointment?')) {
                const appointmentId = button.getAttribute('data-appointment-id');
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = '';
                
                const inputAction = document.createElement('input');
                inputAction.type = 'hidden';
                inputAction.name = 'action';
                inputAction.value = 'deny';
                form.appendChild(inputAction);
                
                const inputId = document.createElement('input');
                inputId.type = 'hidden';
                inputId.name = 'appointment_id';
                inputId.value = appointmentId;
                form.appendChild(inputId);
                
                document.body.appendChild(form);
                form.submit();
            }
        }

        const preparePrescriptionBillingModal = document.getElementById('preparePrescriptionBillingModal');
        preparePrescriptionBillingModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            const appointmentId = button.getAttribute('data-appointment-id');
            const prescription = button.getAttribute('data-prescription');
            const notes = button.getAttribute('data-notes');
            const billingAmount = button.getAttribute('data-billing-amount');

            document.getElementById('prepareBillingAppointmentId').value = appointmentId;
            document.getElementById('prescription').value = prescription;
            document.getElementById('notes').value = notes;
            document.getElementById('billingAmount').value = billingAmount;
        });
    </script>
</body>
</html>