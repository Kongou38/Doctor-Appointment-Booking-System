<?php
session_start();
require_once '../auth/check_totp.php';
require_once '../config.php';
require_once '../notification_helper.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['user_role'] !== 'doctor') {
    header('Location:' . BASE_URL . '/auth/login.php');
    exit();
}

$doctorID = $_SESSION['doctor_id'];

$statusMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $conn = null;
    try {
        $conn = getDBConnection();
        $conn->beginTransaction();
        $stmt = $conn->prepare("DELETE FROM TimeSlot WHERE DoctorID = :doctorID");
        $stmt->bindParam(':doctorID', $doctorID, PDO::PARAM_INT);
        $stmt->execute();
        $stmt->closeCursor();

        foreach ($_POST as $key => $value) {
            if (strpos($key, '_start_') !== false) {
                list($dayOfWeek, $type, $index) = explode('_', $key);

                $startTime = $value;
                $endTime = $_POST["{$dayOfWeek}_end_{$index}"] ?? '';

                if (!empty($startTime) && !empty($endTime)) {
                    $stmt = $conn->prepare("INSERT INTO TimeSlot (DoctorID, DayOfWeek, StartTime, EndTime) VALUES (:doctorID, :dayOfWeek, :startTime, :endTime)");
                    $stmt->bindParam(':doctorID', $doctorID, PDO::PARAM_INT);
                    $stmt->bindParam(':dayOfWeek', $dayOfWeek, PDO::PARAM_STR);
                    $stmt->bindParam(':startTime', $startTime, PDO::PARAM_STR);
                    $stmt->bindParam(':endTime', $endTime, PDO::PARAM_STR);
                    $stmt->execute();
                    $stmt->closeCursor();
                }
            }
        }

        $conn->commit();
        createNotification($conn, $doctorID, "Your availability schedule has been successfully updated.");
        $statusMessage = '<div class="alert alert-success" role="alert">Availability saved successfully!</div>';

    } catch (PDOException $e) {
        if ($conn) {
            $conn->rollback();
        }
        $statusMessage = '<div class="alert alert-danger" role="alert">Failed to save availability: ' . htmlspecialchars($e->getMessage()) . '</div>';
        error_log("Availability save failed: " . $e->getMessage());
    } finally {
        $conn = null;
    }

}

$availability = [
    'monday' => [], 'tuesday' => [], 'wednesday' => [], 'thursday' => [],
    'friday' => [], 'saturday' => [], 'sunday' => []
];

$conn = null;
try {
    $conn = getDBConnection();
    $stmt = $conn->prepare("SELECT DayOfWeek, StartTime, EndTime FROM TimeSlot WHERE DoctorID = :doctorID ORDER BY DayOfWeek, StartTime ASC");
    $stmt->bindParam(':doctorID', $doctorID, PDO::PARAM_INT);
    $stmt->execute();
    $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($result as $row) {
        $availability[$row['DayOfWeek']][] = [
            'start' => substr($row['StartTime'], 0, 5),
            'end' => substr($row['EndTime'], 0, 5)
        ];
    }
    $stmt->closeCursor();

} catch (PDOException $e) {
    $statusMessage = '<div class="alert alert-danger" role="alert">Failed to load availability: ' . htmlspecialchars($e->getMessage()) . '</div>';
    error_log("Availability load failed: " . $e->getMessage());
} finally {
    $conn = null;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Doctor Availability</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #1cc88a;
            --secondary: #858796;
            --light: #f8f9fc;
        }

        body {
            background-color: #f8f9fa;
            font-family: 'Nunito', sans-serif;
            padding: 20px;
        }

        .availability-container {
            max-width: 800px;
            margin: 0 auto;
            background-color: white;
            border-radius: 0.5rem;
            box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
            padding: 2rem;
        }

        .availability-header {
            color: var(--primary);
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #eaecf4;
            display: flex;
            align-items: center;
        }

        .availability-header i {
            margin-right: 10px;
        }

        .day-card {
            border: 1px solid #e3e6f0;
            border-radius: 0.35rem;
            margin-bottom: 1rem;
            overflow: hidden;
        }

        .day-header {
            background-color: var(--light);
            padding: 0.75rem 1.25rem;
            border-bottom: 1px solid #e3e6f0;
            font-weight: 600;
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: pointer;
        }

        .day-header:hover {
            background-color: #f1f3f9;
        }

        .day-content {
            padding: 1.25rem;
        }

        .time-slot {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
            align-items: center;
        }

        .time-input {
            flex: 1;
            padding: 0.375rem 0.75rem;
            border: 1px solid #d1d3e2;
            border-radius: 0.35rem;
        }

        .add-slot {
            color: var(--primary);
            background: none;
            border: none;
            padding: 0.5rem;
            cursor: pointer;
        }

        .remove-slot {
            color: #e74a3b;
            background: none;
            border: none;
            padding: 0.5rem;
            cursor: pointer;
        }

        .btn-save {
            background-color: var(--primary);
            color: white;
            border: none;
            padding: 0.5rem 1.5rem;
            border-radius: 0.35rem;
            font-weight: 600;
            margin-top: 1rem;
        }

        .btn-save:hover {
            background-color: #17a673;
        }

        .btn-return {
            display: inline-block;
            color: var(--secondary);
            text-decoration: none;
            margin-top: 1rem;
            margin-right: 1rem;
        }

        .btn-return i {
            margin-right: 5px;
        }

        @media (max-width: 768px) {
            .time-slot {
                flex-direction: column;
                align-items: flex-start;
            }

            .time-input {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="availability-container">
        <div class="availability-header">
            <i class="fas fa-calendar-alt"></i>
            <h4 class="mb-0">Set Your Availability</h4>
        </div>

        <?php echo $statusMessage;?>

        <form action="manage_slot.php" method="POST">
            <?php
            $daysOfWeek = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
            foreach ($daysOfWeek as $day):
                $slots = $availability[$day] ?? [];
            ?>
            <div class="day-card">
                <div class="day-header" data-bs-toggle="collapse" href="#<?= $day ?>Slots">
                    <span><?= ucfirst($day) ?></span>
                    <i class="fas fa-chevron-down"></i>
                </div>
                <div class="collapse <?= (count($slots) > 0 || $day === 'monday') ? 'show' : '' ?>" id="<?= $day ?>Slots">
                    <div class="day-content">
                        <div class="time-slots" id="<?= $day ?>TimeSlots">
                            <?php if (empty($slots)): ?>
                                <div class="time-slot">
                                    <input type="time" class="time-input start-time" name="<?= $day ?>_start_0" value="">
                                    <span>to</span>
                                    <input type="time" class="time-input end-time" name="<?= $day ?>_end_0" value="">
                                    <button type="button" class="remove-slot">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                            <?php else: ?>
                                <?php foreach ($slots as $index => $slot): ?>
                                    <div class="time-slot">
                                        <input type="time" class="time-input start-time" name="<?= $day ?>_start_<?= $index ?>" value="<?= htmlspecialchars($slot['start']) ?>">
                                        <span>to</span>
                                        <input type="time" class="time-input end-time" name="<?= $day ?>_end_<?= $index ?>" value="<?= htmlspecialchars($slot['end']) ?>">
                                        <button type="button" class="remove-slot">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <button type="button" class="add-slot" data-day="<?= $day ?>">
                            <i class="fas fa-plus-circle"></i> Add time slot
                        </button>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>

            <div class="actions">
                <a href="#" class="btn-return">
                    <i class="fas fa-arrow-left"></i> Return
                </a>
                <button type="submit" class="btn btn-save">
                    <i class="fas fa-save"></i> Save Availability
                </button>
            </div>
        </form>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        function createTimeSlotElement(day, index, startTime = '', endTime = '') {
            const newSlot = document.createElement('div');
            newSlot.className = 'time-slot';
            newSlot.innerHTML = `
                <input type="time" class="time-input start-time" name="${day}_start_${index}" value="${startTime}">
                <span>to</span>
                <input type="time" class="time-input end-time" name="${day}_end_${index}" value="${endTime}">
                <button type="button" class="remove-slot">
                    <i class="fas fa-times"></i>
                </button>
            `;
            newSlot.querySelector('.remove-slot').addEventListener('click', function() {
                this.closest('.time-slot').remove();
                updateInputNames(day);
            });
            return newSlot;
        }

        function updateInputNames(day) {
            const container = document.getElementById(`${day}TimeSlots`);
            const slotElements = container.querySelectorAll('.time-slot');
            slotElements.forEach((slot, index) => {
                const startTimeInput = slot.querySelector('.start-time');
                const endTimeInput = slot.querySelector('.end-time');
                if (startTimeInput) startTimeInput.name = `${day}_start_${index}`;
                if (endTimeInput) endTimeInput.name = `${day}_end_${index}`;
            });
        }

        document.querySelectorAll('.remove-slot').forEach(button => {
            button.addEventListener('click', function() {
                const timeSlotDiv = this.closest('.time-slot');
                const day = timeSlotDiv.parentElement.id.replace('TimeSlots', '');
                timeSlotDiv.remove();
                updateInputNames(day);
            });
        });

        document.querySelectorAll('.add-slot').forEach(button => {
            button.addEventListener('click', function() {
                const day = this.getAttribute('data-day');
                const container = document.getElementById(`${day}TimeSlots`);
                const nextIndex = container.children.length;
                container.appendChild(createTimeSlotElement(day, nextIndex));
            });
        });

        document.querySelector('.btn-return').addEventListener('click', function(e) {
            e.preventDefault();
            window.history.back();
        });
    </script>
</body>
</html>