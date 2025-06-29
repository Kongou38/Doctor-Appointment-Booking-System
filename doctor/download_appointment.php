<?php
session_start();
require_once '../auth/check_totp.php';
require_once '../config.php';
require_once '../dompdf/autoload.inc.php';

use Dompdf\Dompdf;
use Dompdf\Options;

if (!isset($_SESSION['logged_in']) || $_SESSION['user_role'] !== 'doctor') {
    header('Location: ../auth/login.php');
    exit();
}

$doctorID = $_SESSION['doctor_id'];
$appointmentId = $_GET['id'] ?? null;

if (!$appointmentId) {
    die("Appointment ID is missing.");
}

$pdo = getDBConnection();

try {
    $stmtCurrent = $pdo->prepare("
        SELECT
            a.AppointmentID,
            a.UserID,
            a.booked_datetime,
            a.Symptoms,
            a.STATUS, /* Still fetch STATUS to potentially use in queries, but won't display */
            ts.StartTime,
            ts.EndTime,
            ts.DAYOFWEEK,
            su_patient.NAME AS PatientName,
            su_patient.Email AS PatientEmail,
            su_patient.ICNumber AS PatientICNumber,
            su_patient.ContactNumber AS PatientContactNumber,
            ad.Notes,
            ad.Prescription,
            su_doctor.NAME AS DoctorName,
            su_doctor.Email AS DoctorEmail
        FROM
            appointment a
        JOIN
            timeslot ts ON a.SlotID = ts.SlotID
        JOIN
            systemuser su_patient ON a.UserID = su_patient.UserID
        JOIN
            doctor d ON a.DoctorID = d.DoctorID
        JOIN
            systemuser su_doctor ON d.UserID = su_doctor.UserID
        LEFT JOIN
            appointmentdetail ad ON a.AppointmentID = ad.AppointmentID
        WHERE
            a.AppointmentID = :appointmentId AND a.DoctorID = :doctorID
    ");
    $stmtCurrent->execute(['appointmentId' => $appointmentId, 'doctorID' => $doctorID]);
    $currentAppointment = $stmtCurrent->fetch(PDO::FETCH_ASSOC);

    if (!$currentAppointment) {
        die("Appointment not found or you are not authorized to view this appointment.");
    }

    $patientUserID = $currentAppointment['UserID'];

    $stmtHistory = $pdo->prepare("
        SELECT
            a.AppointmentID,
            a.booked_datetime,
            a.Symptoms,
            a.STATUS, /* Still fetch STATUS for filtering, but won't display */
            ts.StartTime,
            ts.EndTime,
            ts.DAYOFWEEK,
            su_doctor.NAME AS DoctorName,
            ad.Notes,
            ad.Prescription
        FROM
            appointment a
        JOIN
            timeslot ts ON a.SlotID = ts.SlotID
        JOIN
            doctor d ON a.DoctorID = d.DoctorID
        JOIN
            systemuser su_doctor ON d.UserID = su_doctor.UserID
        LEFT JOIN
            appointmentdetail ad ON a.AppointmentID = ad.AppointmentID
        WHERE
            a.UserID = :patientUserID
            AND a.STATUS = 'Completed'
            AND a.AppointmentID != :currentAppointmentId
        ORDER BY
            a.booked_datetime DESC
    ");
    $stmtHistory->execute(['patientUserID' => $patientUserID, 'currentAppointmentId' => $appointmentId]);
    $completedAppointments = $stmtHistory->fetchAll(PDO::FETCH_ASSOC);

    $options = new Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isRemoteEnabled', true);
    $dompdf = new Dompdf($options);

    $html = '<!DOCTYPE html>';
    $html .= '<html><head>';
    $html .= '<meta charset="UTF-8">';
    $html .= '<title>Patient Appointment History Report</title>';
    $html .= '<style>';
    $html .= '
        body { font-family: DejaVu Sans, sans-serif; font-size: 10pt; margin: 40px; }
        h1, h2, h3 { color: #333; }
        .header { text-align: center; margin-bottom: 30px; }
        .details-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        .details-table th, .details-table td { border: 1px solid #ddd; padding: 8px; text-align: left; vertical-align: top; }
        .details-table th { background-color: #f2f2f2; width: 150px; }
        .section-title { margin-top: 25px; margin-bottom: 10px; color: #555; border-bottom: 1px solid #eee; padding-bottom: 5px; }
        pre { white-space: pre-wrap; word-wrap: break-word; background-color: #f8f8f8; padding: 10px; border: 1px solid #eee; border-radius: 5px; }
        .appointment-item { border: 1px solid #ccc; padding: 15px; margin-bottom: 20px; border-radius: 8px; }
        .appointment-item h3 { margin-top: 0; color: #4e73df; }
        .appointment-item p { margin-bottom: 5px; }
        .current-appointment-section { border: 2px solid #4e73df; padding: 20px; margin-bottom: 30px; background-color: #e6efff; border-radius: 10px;}
        .current-appointment-section h2 { color: #2e59d9; }
        /* Removed status-badge styling as status will not be displayed */
    ';
    $html .= '</style>';
    $html .= '</head><body>';

    $html .= '<div class="header">';
    $html .= '<h1>Patient Appointment History Report</h1>';
    $html .= '<p>Generated on: ' . date('d/m/Y H:i:s') . '</p>';
    $html .= '</div>';

    $html .= '<h2 class="section-title">Patient Information</h2>';
    $html .= '<table class="details-table">';
    $html .= '<tr><th>Name:</th><td>' . htmlspecialchars($currentAppointment['PatientName']) . '</td></tr>';
    $html .= '<tr><th>Email:</th><td>' . htmlspecialchars($currentAppointment['PatientEmail']) . '</td></tr>';
    $html .= '<tr><th>IC Number:</th><td>' . htmlspecialchars($currentAppointment['PatientICNumber']) . '</td></tr>';
    $html .= '<tr><th>Contact Number:</th><td>' . htmlspecialchars($currentAppointment['PatientContactNumber']) . '</td></tr>';
    $html .= '</table>';
    
    $html .= '<div class="current-appointment-section">';
    $html .= '<h2 class="section-title">Current Appointment Details</h2>';
    $html .= '<table class="details-table">';
    $html .= '<tr><th>Appointment Date:</th><td>' . (new DateTime($currentAppointment['booked_datetime']))->format('d/m/Y h:i A') . '</td></tr>';
    $html .= '<tr><th>Day/Time Slot:</th><td>' . ucfirst($currentAppointment['DAYOFWEEK']) . ' ' . (new DateTime($currentAppointment['StartTime']))->format('g:i a') . ' - ' . (new DateTime($currentAppointment['EndTime']))->format('g:i a') . '</td></tr>';
    $html .= '<tr><th>Doctor:</th><td>' . htmlspecialchars($currentAppointment['DoctorName']) . '</td></tr>';

    $html .= '<tr><th>Symptoms:</th><td><pre>' . htmlspecialchars($currentAppointment['Symptoms']) . '</pre></td></tr>';
    
    if (!empty($currentAppointment['Notes'])) {
        $html .= '<tr><th>Clinical Notes:</th><td><pre>' . htmlspecialchars($currentAppointment['Notes']) . '</pre></td></tr>';
    } else {
        $html .= '<tr><th>Clinical Notes:</th><td>No notes available.</td></tr>';
    }

    if (!empty($currentAppointment['Prescription'])) {
        $html .= '<tr><th>Prescription:</th><td><pre>' . htmlspecialchars($currentAppointment['Prescription']) . '</pre></td></tr>';
    } else {
        $html .= '<tr><th>Prescription:</th><td>No prescription available.</td></tr>';
    }
    $html .= '</table>';
    $html .= '</div>'; 

    $html .= '<h2 class="section-title">Previous Completed Appointments History</h2>';

    if (!empty($completedAppointments)) {
        foreach ($completedAppointments as $appointment) {
            $html .= '<div class="appointment-item">';
            $html .= '<h3>Appointment Date: ' . (new DateTime($appointment['booked_datetime']))->format('d/m/Y h:i A') . '</h3>';
            $html .= '<table class="details-table">';
            $html .= '<tr><th>Doctor:</th><td>' . htmlspecialchars($appointment['DoctorName']) . '</td></tr>';
            $html .= '<tr><th>Day/Time Slot:</th><td>' . ucfirst($appointment['DAYOFWEEK']) . ' ' . (new DateTime($appointment['StartTime']))->format('g:i a') . ' - ' . (new DateTime($appointment['EndTime']))->format('g:i a') . '</td></tr>';
            $html .= '<tr><th>Symptoms:</th><td><pre>' . htmlspecialchars($appointment['Symptoms']) . '</pre></td></tr>';
            
            if (!empty($appointment['Notes'])) {
                $html .= '<tr><th>Clinical Notes:</th><td><pre>' . htmlspecialchars($appointment['Notes']) . '</pre></td></tr>';
            } else {
                $html .= '<tr><th>Clinical Notes:</th><td>No notes available.</td></tr>';
            }

            if (!empty($appointment['Prescription'])) {
                $html .= '<tr><th>Prescription:</th><td><pre>' . htmlspecialchars($appointment['Prescription']) . '</pre></td></tr>';
            } else {
                $html .= '<tr><th>Prescription:</th><td>No prescription available.</td></tr>';
            }
            $html .= '</table>';
            $html .= '</div>';
        }
    } else {
        $html .= '<p>No previous completed appointments found for this patient.</p>';
    }

    $html .= '</body></html>';

    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    $filename = 'Patient_History_Report_' . $currentAppointment['PatientName'] . '_' . date('Ymd_His') . '.pdf';
    $dompdf->stream($filename, ["Attachment" => true]);

} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
} catch (Exception $e) {
    die("An error occurred: " . $e->getMessage());
}
?>