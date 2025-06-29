<?php
session_start();
require_once '../config.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['user_role'] !== 'doctor') {
    header('Location:' . BASE_URL . '/auth/login.php');
    exit();
}

$pdo = getDBConnection();
$stmt = $pdo->prepare("SELECT DoctorID FROM doctor WHERE UserID = ?");
$stmt->execute([$_SESSION['user_id']]);
$doctor = $stmt->fetch();

$doctor_id = $doctor['DoctorID'];

$stmt = $pdo->prepare("
    SELECT DISTINCT
        su.UserID,
        su.NAME as PatientName,
        su.Email as PatientEmail,
        su.ContactNumber,
        COUNT(DISTINCT a.AppointmentID) as AppointmentCount,
        MAX(a.CreatedAt) as LastAppointment,
        CASE 
            WHEN cr.ChatRoomID IS NOT NULL THEN 1 
            ELSE 0 
        END as HasExistingChat,
        cr.ChatRoomID
    FROM systemuser su
    LEFT JOIN appointment a ON su.UserID = a.UserID AND a.DoctorID = ?
    LEFT JOIN doctor d ON su.UserID = d.UserID
    LEFT JOIN admin ad ON su.UserID = ad.UserID
    LEFT JOIN chatroom cr ON (cr.User1ID = su.UserID AND cr.User2ID = ?) 
                          OR (cr.User2ID = su.UserID AND cr.User1ID = ?)
    WHERE d.UserID IS NULL AND ad.UserID IS NULL  -- Exclude doctors and admins
    GROUP BY su.UserID, su.NAME, su.Email, su.ContactNumber, cr.ChatRoomID
    ORDER BY 
        HasExistingChat ASC,  -- Show patients without existing chats first
        AppointmentCount DESC, -- Then by appointment count
        LastAppointment DESC   -- Then by most recent appointment
");
$stmt->execute([$doctor_id, $_SESSION['user_id'], $_SESSION['user_id']]);
$patients = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Available Patients - Start Chat</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #4e73df;
            --secondary: #858796;
            --success: #1cc88a;
            --info: #36b9cc;
            --warning: #f6c23e;
            --danger: #e74a3b;
            --light: #f8f9fc;
            --dark: #5a5c69;
        }
        
        body {
            background-color: #f8f9fa;
            font-family: 'Nunito', sans-serif;
        }
        
        .main-card {
            border: none;
            border-radius: 0.5rem;
            box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
            overflow: hidden;
        }
        
        .card-header {
            background: linear-gradient(135deg, var(--primary) 0%, #224abe 100%);
            color: white;
            padding: 1.5rem;
            text-align: center;
            border-bottom: none;
        }
        
        .patient-item {
            border-bottom: 1px solid rgba(0,0,0,0.05);
            padding: 1.25rem 1.5rem;
            transition: all 0.3s ease;
        }
        
        .patient-item:hover {
            background-color: var(--light);
            transform: translateY(-1px);
        }
        
        .patient-item:last-child {
            border-bottom: none;
        }
        
        .patient-info h5 {
            color: var(--dark);
            margin-bottom: 0.5rem;
        }
        
        .patient-stats {
            display: flex;
            gap: 1rem;
            margin-top: 0.75rem;
        }
        
        .stat-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.5rem;
            border-radius: 0.25rem;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .appointments-badge {
            background-color: rgba(28, 200, 138, 0.1);
            color: var(--success);
        }
        
        .last-visit-badge {
            background-color: rgba(54, 185, 204, 0.1);
            color: var(--info);
        }
        
        .existing-chat-badge {
            background-color: rgba(246, 194, 62, 0.1);
            color: var(--warning);
        }
        
        .new-patient-badge {
            background-color: rgba(78, 115, 223, 0.1);
            color: var(--primary);
        }
        
        .btn-start-chat {
            background: linear-gradient(135deg, var(--success) 0%, #17a085 100%);
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 0.375rem;
            font-size: 0.875rem;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn-start-chat:hover {
            background: linear-gradient(135deg, #17a085 0%, var(--success) 100%);
            color: white;
            transform: translateY(-1px);
            box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,0.075);
        }
        
        .btn-continue-chat {
            background: linear-gradient(135deg, var(--warning) 0%, #e08e0b 100%);
            color: white;
            border: none;
        }
        
        .btn-continue-chat:hover {
            background: linear-gradient(135deg, #e08e0b 0%, var(--warning) 100%);
            color: white;
        }
        
        .btn-return {
            background-color: var(--secondary);
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 0.375rem;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn-return:hover {
            background-color: var(--dark);
            color: white;
            transform: translateY(-1px);
        }
        
        .search-box {
            margin-bottom: 1.5rem;
        }
        
        .search-input {
            border: 2px solid #e3e6f0;
            border-radius: 0.375rem;
            padding: 0.75rem 1rem;
            font-size: 0.9rem;
            transition: border-color 0.3s ease;
        }
        
        .search-input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 0.2rem rgba(78, 115, 223, 0.25);
        }
        
        .section-divider {
            background-color: var(--light);
            padding: 0.75rem 1.5rem;
            color: var(--secondary);
            font-weight: 600;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .empty-state {
            text-align: center;
            padding: 3rem 2rem;
            color: var(--secondary);
        }
        
        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }
    </style>
</head>
<body class="d-flex align-items-center min-vh-100 py-4">
<div class="container">
    <div class="row justify-content-center">
        <div class="col-lg-10">
            <div class="main-card">
                <div class="card-header">
                    <h4 class="mb-0">
                        <i class="fas fa-users-medical me-2"></i>
                        Available Patients
                    </h4>
                    <p class="mb-0 mt-2 opacity-75">Start or continue conversations with your patients</p>
                </div>

                <div class="card-body p-0">
                    <div class="search-box p-3">
                        <div class="input-group">
                            <span class="input-group-text bg-light border-end-0">
                                <i class="fas fa-search text-muted"></i>
                            </span>
                            <input type="text" id="patientSearch" class="form-control search-input border-start-0" 
                                   placeholder="Search patients by name or email...">
                        </div>
                    </div>

                    <?php if (empty($patients)): ?>
                        <div class="empty-state">
                            <i class="fas fa-user-slash"></i>
                            <h5>No Patients Found</h5>
                            <p>No patients are available for chat at the moment.</p>
                        </div>
                    <?php else: ?>
                        <?php 
                        $existing_chats = array_filter($patients, function($p) { return $p['HasExistingChat']; });
                        $new_patients = array_filter($patients, function($p) { return !$p['HasExistingChat']; });
                        ?>
                        
                        <?php if (!empty($new_patients)): ?>
                            <div class="section-divider">
                                <i class="fas fa-user-plus me-2"></i>New Conversations
                            </div>
                            <?php foreach ($new_patients as $patient): ?>
                                <div class="patient-item" data-patient-name="<?php echo htmlspecialchars(strtolower($patient['PatientName'])); ?>" 
                                     data-patient-email="<?php echo htmlspecialchars(strtolower($patient['PatientEmail'])); ?>">
                                    <div class="row align-items-center">
                                        <div class="col-md-8">
                                            <div class="patient-info">
                                                <h5 class="mb-1">
                                                    <i class="fas fa-user-circle me-2 text-primary"></i>
                                                    <?php echo htmlspecialchars($patient['PatientName']); ?>
                                                </h5>
                                                <p class="text-muted mb-1">
                                                    <i class="fas fa-envelope me-1"></i>
                                                    <?php echo htmlspecialchars($patient['PatientEmail']); ?>
                                                </p>
                                                <?php if ($patient['ContactNumber']): ?>
                                                    <p class="text-muted mb-1">
                                                        <i class="fas fa-phone me-1"></i>
                                                        <?php echo htmlspecialchars($patient['ContactNumber']); ?>
                                                    </p>
                                                <?php endif; ?>
                                                
                                                <div class="patient-stats">
                                                    <?php if ($patient['AppointmentCount'] > 0): ?>
                                                        <span class="stat-badge appointments-badge">
                                                            <i class="fas fa-calendar-check me-1"></i>
                                                            <?php echo $patient['AppointmentCount']; ?> appointments
                                                        </span>
                                                    <?php endif; ?>
                                                    
                                                    <?php if ($patient['LastAppointment']): ?>
                                                        <span class="stat-badge last-visit-badge">
                                                            <i class="fas fa-clock me-1"></i>
                                                            Last visit: <?php echo date('M j, Y', strtotime($patient['LastAppointment'])); ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="stat-badge new-patient-badge">
                                                            <i class="fas fa-user-plus me-1"></i>
                                                            New patient
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-4 text-end">
                                            <a href="chat.php?patientID=<?php echo $patient['UserID']; ?>" 
                                               class="btn btn-start-chat">
                                                <i class="fas fa-comment-medical me-1"></i>
                                                Start Chat
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        
                        <?php if (!empty($existing_chats)): ?>
                            <div class="section-divider">
                                <i class="fas fa-comments me-2"></i>Existing Conversations
                            </div>
                            <?php foreach ($existing_chats as $patient): ?>
                                <div class="patient-item" data-patient-name="<?php echo htmlspecialchars(strtolower($patient['PatientName'])); ?>" 
                                     data-patient-email="<?php echo htmlspecialchars(strtolower($patient['PatientEmail'])); ?>">
                                    <div class="row align-items-center">
                                        <div class="col-md-8">
                                            <div class="patient-info">
                                                <h5 class="mb-1">
                                                    <i class="fas fa-user-circle me-2 text-primary"></i>
                                                    <?php echo htmlspecialchars($patient['PatientName']); ?>
                                                </h5>
                                                <p class="text-muted mb-1">
                                                    <i class="fas fa-envelope me-1"></i>
                                                    <?php echo htmlspecialchars($patient['PatientEmail']); ?>
                                                </p>
                                                <?php if ($patient['ContactNumber']): ?>
                                                    <p class="text-muted mb-1">
                                                        <i class="fas fa-phone me-1"></i>
                                                        <?php echo htmlspecialchars($patient['ContactNumber']); ?>
                                                    </p>
                                                <?php endif; ?>
                                                
                                                <div class="patient-stats">                                                
                                                    <?php if ($patient['AppointmentCount'] > 0): ?>
                                                        <span class="stat-badge appointments-badge">
                                                            <i class="fas fa-calendar-check me-1"></i>
                                                            <?php echo $patient['AppointmentCount']; ?> appointments
                                                        </span>
                                                    <?php endif; ?>
                                                    
                                                    <?php if ($patient['LastAppointment']): ?>
                                                        <span class="stat-badge last-visit-badge">
                                                            <i class="fas fa-clock me-1"></i>
                                                            Last visit: <?php echo date('M j, Y', strtotime($patient['LastAppointment'])); ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-4 text-end">
                                            <a href="chat.php?patientID=<?php echo $patient['UserID']; ?>" 
                                               class="btn btn-continue-chat">
                                                <i class="fas fa-comments me-1"></i>
                                                Continue Chat
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    <?php endif; ?>

                    <div class="card-footer bg-light text-center py-3">
                        <div class="row justify-content-center">
                            <div class="col-auto">
                                <a href="dashboard.php" class="btn btn-return">
                                    <i class="fas fa-arrow-left me-1"></i>Return to Dashboard
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mt-3 border-0 shadow-sm">
                <div class="card-body text-center py-2">
                    <small class="text-muted">
                        Logged in as: <strong><?php echo htmlspecialchars($_SESSION['user_email'] ?? 'Unknown'); ?></strong>
                    </small>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('patientSearch').addEventListener('input', function() {
    const searchTerm = this.value.toLowerCase();
    const patientItems = document.querySelectorAll('.patient-item');
    
    patientItems.forEach(item => {
        const name = item.getAttribute('data-patient-name');
        const email = item.getAttribute('data-patient-email');
        
        if (name.includes(searchTerm) || email.includes(searchTerm)) {
            item.style.display = '';
        } else {
            item.style.display = 'none';
        }
    });
    
    const sections = document.querySelectorAll('.section-divider');
    sections.forEach(section => {
        const nextItems = [];
        let nextElement = section.nextElementSibling;
        
        while (nextElement && !nextElement.classList.contains('section-divider') && !nextElement.classList.contains('card-footer')) {
            if (nextElement.classList.contains('patient-item')) {
                nextItems.push(nextElement);
            }
            nextElement = nextElement.nextElementSibling;
        }
        
        const hasVisibleItems = nextItems.some(item => item.style.display !== 'none');
        section.style.display = hasVisibleItems ? '' : 'none';
    });
});
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>