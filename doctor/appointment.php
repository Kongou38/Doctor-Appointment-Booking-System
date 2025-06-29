<?php
session_start();
require_once '../auth/check_totp.php';
require_once '../config.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['user_role'] !== 'doctor') {
    header('Location:' . BASE_URL . '/auth/login.php');
    exit();
}
$doctorID = $_SESSION['doctor_id'];
$statusMessage = '';
$appointments = [];
$pdo = getDBConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $appointmentId = $_POST['appointment_id'];

        try {
            $stmtCheck = $pdo->prepare("SELECT AppointmentID FROM appointment WHERE AppointmentID = :id AND DoctorID = :doctorID");
            $stmtCheck->execute(['id' => $appointmentId, 'doctorID' => $doctorID]);
            
            if ($stmtCheck->rowCount() === 0) {
                throw new Exception("Appointment not found or not authorized");
            }

            switch ($_POST['action']) {
                case 'update_details':
                    $notes = trim($_POST['notes'] ?? '');
                    $prescription = trim($_POST['prescription'] ?? '');
                    
                    $pdo->beginTransaction();
                    
                    $stmtUpdate = $pdo->prepare("UPDATE appointment SET STATUS = 'Payment' WHERE AppointmentID = :id");
                    $stmtUpdate->execute(['id' => $appointmentId]);

                    $stmtPatient = $pdo->prepare("SELECT UserID FROM appointment WHERE AppointmentID = :id");
                    $stmtPatient->execute(['id' => $appointmentId]);
                    $patientID = $stmtPatient->fetchColumn();
                    
                    $stmtCheckDetail = $pdo->prepare("SELECT AppointmentDetailID FROM appointmentdetail WHERE AppointmentID = :appointmentId");
                    $stmtCheckDetail->execute(['appointmentId' => $appointmentId]);
                    $existingDetail = $stmtCheckDetail->fetch();
                    
                    if ($existingDetail) {
                        $stmtUpdateDetail = $pdo->prepare("UPDATE appointmentdetail 
                                                          SET Notes = :notes, 
                                                              Prescription = :prescription
                                                          WHERE AppointmentID = :appointmentId");
                        $stmtUpdateDetail->execute([
                            'notes' => $notes,
                            'prescription' => $prescription,
                            'appointmentId' => $appointmentId
                        ]);
                        
                        createNotification($pdo, $patientID, "Your appointment details have been updated by the doctor. Please check the updated information.");
                    } else {
                        $stmtInsertDetail = $pdo->prepare("INSERT INTO appointmentdetail 
                                                          (AppointmentID, Notes, Prescription) 
                                                          VALUES (:appointmentId, :notes, :prescription)");
                        $stmtInsertDetail->execute([
                            'appointmentId' => $appointmentId,
                            'notes' => $notes,
                            'prescription' => $prescription
                        ]);
                        
                        createNotification($pdo, $patientID, "The doctor has added details to your appointment. Please review the information.");
                    }
                    
                    createNotification($pdo, $doctorID, "You have successfully updated appointment #$appointmentId details.");
                    
                    $pdo->commit();
                    $statusMessage = '<div class="alert alert-success mt-3">Appointment details updated successfully.</div>';
                    break;
            
                case 'download_details':
                    $statusMessage = '<div class="alert alert-info mt-3">Download initiated.</div>';
                    break;
            }
        } catch (Exception $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $statusMessage = '<div class="alert alert-danger mt-3">Error: ' . $e->getMessage() . '</div>';
        }
    }
}

// Filtering
$filterPeriod = $_GET['filter_period'] ?? 'all';
$sqlWhereClause = "WHERE a.DoctorID = :doctorID";
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
    case 'payment_pending':
        $sqlWhereClause .= " AND a.STATUS = 'Payment'";
        break;
    case 'completed':
        $sqlWhereClause .= " AND a.STATUS = 'Completed'";
        break;
}

try {
    $stmt = $pdo->prepare("
        SELECT
            a.AppointmentID,
            a.booked_datetime,
            a.Symptoms,
            a.STATUS,
            ts.StartTime,
            ts.EndTime,
            ts.DAYOFWEEK,
            su_patient.NAME AS PatientName,
            ad.Notes,
            ad.Prescription
        FROM
            appointment a
        JOIN
            timeslot ts ON a.SlotID = ts.SlotID
        JOIN
            systemuser su_patient ON a.UserID = su_patient.UserID
        LEFT JOIN
            appointmentdetail ad ON a.AppointmentID = ad.AppointmentID
        " . $sqlWhereClause . "
        ORDER BY
            a.booked_datetime ASC
    ");
    $stmt->execute(['doctorID' => $doctorID]);
    $appointments = $stmt->fetchAll();
} catch (PDOException $e) {
    $statusMessage .= '<div class="alert alert-danger">Error fetching appointments: ' . $e->getMessage() . '</div>';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Doctor Appointments</title>
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
            max-width: 1000px;
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

        .status-approved {
            background-color: #1cc88a;
            color: white;
        }

        .status-payment {
            background-color: #f6c23e;
            color: white;
        }

        .status-completed {
            background-color: #36b9cc;
            color: white;
        }

        .status-cancelled {
            background-color: #e74a3b;
            color: white;
        }

        .status-pending {
            background-color: #f6c23e;
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
            max-height: 1000px;
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
            width: 120px;
            flex-shrink: 0;
            text-align: right;
        }
        
        .detail-value {
            flex: 1;
            word-break: break-word;
            min-width: 0;
        }

        .symptoms-value, .notes-value, .prescription-value {
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

        .btn-update {
            background-color: var(--primary);
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 4px;
            font-size: 14px;
            cursor: pointer;
        }

        .btn-update:hover {
            background-color: #3a5bc7;
        }

        .btn-download {
            background-color: #1cc88a;
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 4px;
            font-size: 14px;
            cursor: pointer;
        }

        .btn-download:hover {
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

        @media (max-width: 768px) {
            .detail-row {
                flex-direction: column;
                gap: 5px;
            }
            
            .detail-label {
                text-align: left;
                width: auto;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .btn-update, .btn-download {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="history-container">
        <h4 class="mb-4"><i class="fas fa-calendar-check me-2"></i>My Appointments</h4>
        
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
                        <option value="payment_pending" <?= ($filterPeriod === 'payment_pending') ? 'selected' : '' ?>>Payment Pending</option>
                        <option value="completed" <?= ($filterPeriod === 'completed') ? 'selected' : '' ?>>Completed</option>
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
                            <div class="detail-value"><?= htmlspecialchars($appointment['PatientName']) ?></div>
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
                        
                        <?php if (!empty($appointment['Notes'])): ?>
                        <div class="detail-row">
                            <div class="detail-label">Notes:</div>
                            <div class="detail-value notes-value"><?= htmlspecialchars($appointment['Notes']) ?></div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($appointment['Prescription'])): ?>
                        <div class="detail-row">
                            <div class="detail-label">Prescription:</div>
                            <div class="detail-value prescription-value"><?= htmlspecialchars($appointment['Prescription']) ?></div>
                        </div>
                        <?php endif; ?>
                        
                        <div class="action-buttons">
                            <?php if ($appointment['STATUS'] === 'Approved'): ?>
                                <button class="btn-update" data-bs-toggle="modal" data-bs-target="#updateDetailsModal" 
                                    data-appointment-id="<?= $appointment['AppointmentID'] ?>"
                                    data-notes="<?= htmlspecialchars($appointment['Notes'] ?? '') ?>"
                                    data-prescription="<?= htmlspecialchars($appointment['Prescription'] ?? '') ?>">
                                    <i class="fas fa-edit me-1"></i>Update Details
                                </button>
                            <?php endif; ?>
                            
                            <button class="btn-download" onclick="downloadDetails(<?= $appointment['AppointmentID'] ?>)">
                                <i class="fas fa-download me-1"></i>Download
                            </button>
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

    <div class="modal fade" id="updateDetailsModal" tabindex="-1" aria-labelledby="updateDetailsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form action="" method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title" id="updateDetailsModalLabel">Update Appointment Details</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="update_details">
                        <input type="hidden" name="appointment_id" id="modalAppointmentId">

                        <div class="mb-3">
                            <label for="appointmentNotes" class="form-label">Clinical Notes:</label>
                            <textarea class="form-control" id="appointmentNotes" name="notes" rows="5" placeholder="Enter clinical notes about the appointment"></textarea>
                        </div>

                        <div class="mb-3">
                            <label for="appointmentPrescription" class="form-label">Prescription:</label>
                            <textarea class="form-control" id="appointmentPrescription" name="prescription" rows="5" placeholder="Enter prescription details (medications, dosage, instructions)"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Details</button>
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

    const updateDetailsModal = document.getElementById('updateDetailsModal');
    updateDetailsModal.addEventListener('show.bs.modal', function (event) {
        const button = event.relatedTarget;
        const appointmentId = button.getAttribute('data-appointment-id');
        const notes = button.getAttribute('data-notes');
        const prescription = button.getAttribute('data-prescription');
        
        document.getElementById('modalAppointmentId').value = appointmentId;
        document.getElementById('appointmentNotes').value = notes;
        document.getElementById('appointmentPrescription').value = prescription;
    });

    function downloadDetails(appointmentId) {
        window.location.href = `download_appointment.php?id=${appointmentId}`;
    }
</script>
</body>
</html>