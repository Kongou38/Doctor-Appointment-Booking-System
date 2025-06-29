<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
session_start();
require_once '../auth/check_totp.php';
require_once '../config.php';
require_once '../notification_helper.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['user_role'] !== 'patient') {
    header('Location:' . BASE_URL . '/auth/login.php');
    exit();
}

$patientID = $_SESSION['user_id'];

$statusMessage = '';
$selectedDoctorId = $_POST['doctor_id'] ?? null;
$selectedSlotInfo = $_POST['slot_info'] ?? null;
$symptomText = $_POST['symptoms'] ?? '';

if (isset($_GET['action']) && $_GET['action'] === 'get_slots_for_doctor' && isset($_GET['doctor_id'])) {
    header('Content-Type: application/json');
    $doctorID = $_GET['doctor_id'];

    $conn = null;
    try {
        $conn = getDBConnection();

        $finalAvailableSlots = [];
        $numDaysToLookAhead = 30;

        for ($i = 0; $i < $numDaysToLookAhead; $i++) {
            $currentDate = date('Y-m-d', strtotime("+$i days"));
            $dayOfWeek = strtolower(date('l', strtotime($currentDate)));

            $stmt = $conn->prepare("SELECT SlotID, StartTime, EndTime FROM TimeSlot WHERE DoctorID = :doctorID AND DayOfWeek = :dayOfWeek ORDER BY StartTime ASC");
            $stmt->bindParam(':doctorID', $doctorID, PDO::PARAM_INT);
            $stmt->bindParam(':dayOfWeek', $dayOfWeek, PDO::PARAM_STR);
            $stmt->execute();
            $generalSlots = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt->closeCursor();

            $stmt = $conn->prepare("SELECT a.SlotID, ts.StartTime 
                                  FROM Appointment a
                                  JOIN TimeSlot ts ON a.SlotID = ts.SlotID
                                  WHERE a.DoctorID = :doctorID 
                                  AND DATE(a.booked_datetime) = :currentDate 
                                  AND (a.STATUS = 'Pending' OR a.STATUS = 'Approved')");
            $stmt->bindParam(':doctorID', $doctorID, PDO::PARAM_INT);
            $stmt->bindParam(':currentDate', $currentDate, PDO::PARAM_STR);
            $stmt->execute();
            $bookedAppointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt->closeCursor();

            $bookedStartTimes = array_column($bookedAppointments, 'StartTime');

            foreach ($generalSlots as $slot) {
                if (!in_array($slot['StartTime'], $bookedStartTimes)) {
                    $start = new DateTime($slot['StartTime']);
                    $end = new DateTime($slot['EndTime']);
                    $duration = $start->diff($end)->h * 60 + $start->diff($end)->i;
                    
                    $startTime12 = date("g:i A", strtotime($slot['StartTime']));
                    $endTime12 = date("g:i A", strtotime($slot['EndTime']));
                    
                    $finalAvailableSlots[] = [
                        'SlotID' => $slot['SlotID'],
                        'StartTime' => $slot['StartTime'],
                        'EndTime' => $slot['EndTime'],
                        'StartTime12' => $startTime12,
                        'EndTime12' => $endTime12,
                        'AppointmentDate' => $currentDate,
                        'DurationMinutes' => $duration,
                        'FormattedDate' => date("D, M j", strtotime($currentDate))
                    ];
                }
            }
        }

        echo json_encode(['success' => true, 'availableSlots' => $finalAvailableSlots]);
        exit();

    } catch (PDOException $e) {
        error_log("PDO Error in get_slots_for_doctor AJAX: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error fetching slots. Please check server logs.']);
        exit();
    } catch (Exception $e) {
        error_log("General Error in get_slots_for_doctor AJAX: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'An unexpected error occurred fetching slots. Please check server logs.']);
        exit();
    } finally {
        $conn = null;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $conn = null;
    try {
        $conn = getDBConnection();
        $conn->beginTransaction();

        $doctorID = $_POST['doctor_id'] ?? '';
        $selectedSlotInfo = $_POST['slot_info'] ?? '';
        $symptom = $_POST['symptoms'] ?? '';

        if (empty($doctorID) || empty($selectedSlotInfo) || empty($symptom)) {
            throw new Exception("All fields are required.");
        }

        $slotInfoParts = explode('|', $selectedSlotInfo);
        if (count($slotInfoParts) !== 2) {
            throw new Exception("Invalid time slot selection format.");
        }
        $selectedSlotID = (int)$slotInfoParts[0];
        $appointmentDate = $slotInfoParts[1];

        $currentDate = date('Y-m-d');
        if (!preg_match("/^\d{4}-\d{2}-\d{2}$/", $appointmentDate) || $appointmentDate < $currentDate) {
            throw new Exception("Invalid or past appointment date.");
        }

        $stmt = $conn->prepare("SELECT SlotID, DayOfWeek, StartTime, EndTime FROM TimeSlot WHERE SlotID = :slotID AND DoctorID = :doctorID");
        $stmt->bindParam(':slotID', $selectedSlotID, PDO::PARAM_INT);
        $stmt->bindParam(':doctorID', $doctorID, PDO::PARAM_INT);
        $stmt->execute();
        $timeSlotDetails = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        if (!$timeSlotDetails) {
            throw new Exception("Selected time slot is invalid or not available for this doctor.");
        }

        $start = new DateTime($timeSlotDetails['StartTime']);
        $end = new DateTime($timeSlotDetails['EndTime']);
        $durationMinutes = $start->diff($end)->h * 60 + $start->diff($end)->i;

        $selectedDayOfWeek = strtolower(date('l', strtotime($appointmentDate)));
        if ($selectedDayOfWeek !== $timeSlotDetails['DayOfWeek']) {
            throw new Exception("The selected date's day (" . ucfirst($selectedDayOfWeek) . ") does not match the chosen time slot's day (" . ucfirst($timeSlotDetails['DayOfWeek']) . ").");
        }

        $bookedDateTime = $appointmentDate . ' ' . $timeSlotDetails['StartTime'];

        $stmt = $conn->prepare("SELECT COUNT(*) 
                              FROM Appointment a
                              JOIN TimeSlot ts ON a.SlotID = ts.SlotID
                              WHERE a.DoctorID = :doctorID
                              AND DATE(a.booked_datetime) = :appointmentDate
                              AND ts.StartTime = :startTime
                              AND (a.STATUS = 'Pending' OR a.STATUS = 'Approved')");
        $stmt->bindParam(':doctorID', $doctorID, PDO::PARAM_INT);
        $stmt->bindParam(':appointmentDate', $appointmentDate, PDO::PARAM_STR);
        $stmt->bindParam(':startTime', $timeSlotDetails['StartTime'], PDO::PARAM_STR);
        $stmt->execute();
        $existingAppointments = $stmt->fetchColumn();
        $stmt->closeCursor();

        if ($existingAppointments > 0) {
            throw new Exception("This time slot is already booked for " . htmlspecialchars($appointmentDate) . ". Please choose another slot.");
        }

        $stmt = $conn->prepare("INSERT INTO Appointment (UserID, DoctorID, SlotID, Symptoms, booked_datetime, duration_minutes, CreatedAt, STATUS) 
                               VALUES (:patientUserID, :doctorID, :slotID, :symptoms, :bookedDateTime, :durationMinutes, NOW(), 'Pending')");
        $stmt->bindParam(':patientUserID', $patientID, PDO::PARAM_INT);
        $stmt->bindParam(':doctorID', $doctorID, PDO::PARAM_INT);
        $stmt->bindParam(':slotID', $selectedSlotID, PDO::PARAM_INT);
        $stmt->bindParam(':symptoms', $symptom, PDO::PARAM_STR);
        $stmt->bindParam(':bookedDateTime', $bookedDateTime, PDO::PARAM_STR);
        $stmt->bindParam(':durationMinutes', $durationMinutes, PDO::PARAM_INT);
        $stmt->execute();
        $appointmentID = $conn->lastInsertId();
        $stmt->closeCursor();

        $conn->commit();
        createNotification($conn, $patientID, "Your appointment has been booked successfully and is pending approval.");

        $stmtDoctor = $conn->prepare("SELECT UserID FROM doctor WHERE DoctorID = :doctorID");
        $stmtDoctor->execute(['doctorID' => $doctorID]);
        $doctorUserID = $stmtDoctor->fetchColumn();

        createNotification($conn, $doctorUserID, "You have a new appointment request pending your approval.");
        $statusMessage = '<div class="alert alert-success alert-dismissible fade show" role="alert">
                            <strong>Success!</strong> Your appointment has been booked successfully.
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                          </div>';

    } catch (PDOException $e) {
        if ($conn) $conn->rollback();
        $statusMessage = '<div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <strong>Error!</strong> Database error: ' . htmlspecialchars($e->getMessage()) . '
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                          </div>';
        error_log("Appointment booking PDO error: " . $e->getMessage());
    } catch (Exception $e) {
        if ($conn) $conn->rollback();
        $statusMessage = '<div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <strong>Error!</strong> ' . htmlspecialchars($e->getMessage()) . '
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                          </div>';
        error_log("Appointment booking error: " . $e->getMessage());
    } finally {
        $conn = null;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Book Appointment</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .container {
            max-width: 800px;
            margin-top: 50px;
        }
        .card {
            border-radius: 15px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        .card-header {
            background-color: #4e73df;
            color: white;
            border-radius: 15px 15px 0 0 !important;
            padding: 20px;
        }
        .form-control, .form-select {
            border-radius: 10px;
            padding: 10px 15px;
            margin-bottom: 15px;
        }
        .btn-primary {
            background-color: #4e73df;
            border: none;
            border-radius: 10px;
            padding: 10px 20px;
            width: 100%;
        }
        .btn-primary:hover {
            background-color: #2e59d9;
        }
        #available-slots {
            display: none;
            margin-top: 20px;
        }
        .slot-btn {
            margin: 5px;
            min-width: 120px;
        }
        #symptoms {
            min-height: 100px;
        }
        .duration-badge {
            font-size: 0.7rem;
            margin-left: 5px;
            vertical-align: middle;
        }
        .nav-tabs .nav-link {
            color: #495057;
        }
        .nav-tabs .nav-link.active {
            color: #4e73df;
            font-weight: bold;
        }
        .invalid-feedback {
            display: none;
            color: #dc3545;
            font-size: 0.875em;
        }
        .is-invalid ~ .invalid-feedback {
            display: block;
        }
        .alert {
            border-radius: 10px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <div class="card-header text-center">
                <h3>Book an Appointment</h3>
            </div>
            <div class="card-body">
                <?php echo $statusMessage; ?>
                <form id="appointment-form" method="POST" novalidate>
                    <div class="mb-3">
                        <label for="doctor_id" class="form-label">Select Doctor</label>
                        <select class="form-select" id="doctor_id" name="doctor_id" required>
                            <option value="" selected disabled>Select a doctor</option>
                            <?php
                            try {
                                $conn = getDBConnection();
                                $stmt = $conn->prepare("SELECT d.DoctorID, u.NAME FROM doctor d JOIN systemuser u ON d.UserID = u.UserID WHERE d.STATUS = 'Active'");
                                $stmt->execute();
                                $doctors = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                
                                foreach ($doctors as $doctor) {
                                    $selected = ($selectedDoctorId == $doctor['DoctorID']) ? 'selected' : '';
                                    echo "<option value='{$doctor['DoctorID']}' $selected>{$doctor['NAME']}</option>";
                                }
                            } catch (PDOException $e) {
                                error_log("Error fetching doctors: " . $e->getMessage());
                            } finally {
                                $conn = null;
                            }
                            ?>
                        </select>
                        <div class="invalid-feedback">Please select a doctor.</div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="symptoms" class="form-label">Symptoms</label>
                        <textarea class="form-control" id="symptoms" name="symptoms" required minlength="10"><?php echo htmlspecialchars($symptomText); ?></textarea>
                        <div class="invalid-feedback">Please describe your symptoms (at least 10 characters).</div>
                    </div>
                    
                    <div id="available-slots">
                        <label class="form-label">Available Time Slots</label>
                        <div id="slots-container" class="d-flex flex-wrap"></div>
                        <input type="hidden" id="selected_slot_info" name="slot_info" required>
                        <div class="invalid-feedback">Please select an available time slot.</div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary mt-3">Book Appointment</button>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script>
        $(document).ready(function() {
            const form = document.getElementById('appointment-form');
            
            form.addEventListener('submit', function(event) {
                if (!form.checkValidity()) {
                    event.preventDefault();
                    event.stopPropagation();
                    
                    let errorMessage = "Please fix the following issues:";
                    const invalidFields = form.querySelectorAll(':invalid');
                    
                    invalidFields.forEach((field, index) => {
                        if (index < 3) {
                            const fieldName = field.labels ? field.labels[0].textContent : 'Field';
                            errorMessage += `\n- ${fieldName}: ${field.validationMessage}`;
                        }
                    });
                    
                    if (invalidFields.length > 3) {
                        errorMessage += `\n- And ${invalidFields.length - 3} more issues...`;
                    }
                    
                    Swal.fire({
                        icon: 'error',
                        title: 'Form Validation Error',
                        text: errorMessage,
                        confirmButtonColor: '#4e73df'
                    });
                }
                
                form.classList.add('was-validated');
            }, false);
            
            $('#doctor_id').change(function() {
                const doctorID = $(this).val();
                if (!doctorID) return;
                
                $('#available-slots').hide();
                $('#slots-container').empty();
                $('#selected_slot_info').val('');
                
                Swal.fire({
                    title: 'Loading Available Slots',
                    html: 'Please wait while we fetch available time slots...',
                    allowOutsideClick: false,
                    didOpen: () => {
                        Swal.showLoading();
                    }
                });
                
                $.ajax({
                    url: '?action=get_slots_for_doctor&doctor_id=' + doctorID,
                    type: 'GET',
                    dataType: 'json',
                    success: function(response) {
                        Swal.close();
                        
                        if (response.success && response.availableSlots.length > 0) {
                            const slotsByDate = {};
                            
                            response.availableSlots.forEach(slot => {
                                if (!slotsByDate[slot.AppointmentDate]) {
                                    slotsByDate[slot.AppointmentDate] = [];
                                }
                                slotsByDate[slot.AppointmentDate].push(slot);
                            });
                            
                            let tabsHtml = '<ul class="nav nav-tabs mb-3" id="dateTabs" role="tablist">';
                            let contentHtml = '<div class="tab-content" id="dateTabsContent">';
                            
                            let firstTab = true;
                            for (const date in slotsByDate) {
                                const slot = slotsByDate[date][0];
                                const tabId = 'tab-' + date.replace(/-/g, '');
                                
                                tabsHtml += `
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link ${firstTab ? 'active' : ''}" id="${tabId}-tab" data-bs-toggle="tab" 
                                            data-bs-target="#${tabId}" type="button" role="tab" aria-controls="${tabId}" 
                                            aria-selected="${firstTab ? 'true' : 'false'}">
                                            ${slot.FormattedDate}
                                        </button>
                                    </li>
                                `;
                                
                                contentHtml += `
                                    <div class="tab-pane fade ${firstTab ? 'show active' : ''}" id="${tabId}" role="tabpanel" aria-labelledby="${tabId}-tab">
                                        <div class="d-flex flex-wrap">
                                `;
                                
                                slotsByDate[date].forEach(slot => {
                                    const slotInfo = slot.SlotID + '|' + date;
                                    contentHtml += `
                                        <button type="button" class="btn btn-outline-primary slot-btn" 
                                            data-slot-info="${slotInfo}">
                                            ${slot.StartTime12} - ${slot.EndTime12}
                                            <span class="badge bg-secondary duration-badge">${slot.DurationMinutes} min</span>
                                        </button>
                                    `;
                                });
                                
                                contentHtml += `
                                        </div>
                                    </div>
                                `;
                                
                                firstTab = false;
                            }
                            
                            tabsHtml += '</ul>';
                            contentHtml += '</div>';
                            
                            $('#slots-container').html(tabsHtml + contentHtml);
                            $('#available-slots').show();
                            
                            $('.slot-btn').click(function() {
                                $('.slot-btn').removeClass('btn-primary').addClass('btn-outline-primary');
                                $(this).removeClass('btn-outline-primary').addClass('btn-primary');
                                $('#selected_slot_info').val($(this).data('slot-info'));
                                
                                $('#selected_slot_info')[0].setCustomValidity('');
                            });
                            
                            new bootstrap.Tab($('#dateTabs .nav-link')[0]).show();
                        } else {
                            $('#slots-container').html('<div class="alert alert-warning">No available slots found for this doctor in the next 30 days.</div>');
                            $('#available-slots').show();
                        }
                    },
                    error: function(xhr, status, error) {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: 'Failed to load available slots. Please try again.',
                            confirmButtonColor: '#4e73df'
                        });
                        $('#slots-container').html('<div class="alert alert-danger">Error loading available slots. Please try again.</div>');
                        $('#available-slots').show();
                        console.error(error);
                    }
                });
            });
            
            <?php if ($selectedDoctorId): ?>
                $('#doctor_id').trigger('change');
            <?php endif; ?>
            
            $('#symptoms').on('input', function() {
                if (this.value.length < 10) {
                    this.setCustomValidity('Please describe your symptoms in more detail (at least 10 characters).');
                } else {
                    this.setCustomValidity('');
                }
            });
        });
    </script>
</body>
</html>