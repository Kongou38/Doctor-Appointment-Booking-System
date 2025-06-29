<?php
session_start();
require_once '../auth/check_totp.php';
require_once '../config.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['user_role'] !== 'doctor') {
    header('Location:' . BASE_URL . '/auth/login.php');
    exit();
}

$doctorId = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_availability'])) {
        try {
            $conn = getDBConnection();
            $newStatus = $_POST['status'] === 'active' ? 'active' : 'inactive';
            
            $stmt = $conn->prepare("UPDATE doctor SET STATUS = ? WHERE UserID = ?");
            $stmt->execute([$newStatus, $doctorId]);
            
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'status' => $newStatus]);
            exit();
            
        } catch (PDOException $e) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit();
        }
    }
    
    if (isset($_POST['logout'])) {
        session_destroy();
        header('Location:' . BASE_URL . '/auth/login.php');
        exit();
    }
}

try {
    $conn = getDBConnection();
    
    $stmt = $conn->prepare("SELECT STATUS FROM doctor WHERE UserID = ?");
    $stmt->execute([$doctorId]);
    $doctorStatus = $stmt->fetchColumn();
    
    $stmt = $conn->prepare("SELECT COUNT(*) FROM Notification WHERE UserID = ?");
    $stmt->execute([$doctorId]);
    $notification_count = $stmt->fetchColumn();
    
} catch (PDOException $e) {
    $doctorStatus = 'active';
    $notification_count = 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Doctor Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --doctor-primary: #1cc88a;
            --doctor-secondary: #36b9cc;
            --doctor-dark: #2e59d9;
            --doctor-light: #f8f9fc;
            --doctor-danger: #e74a3b;
        }
        
        body {
            background-color: #f8f9fa;
            font-family: 'Nunito', sans-serif;
        }
        
        .doctor-nav-card {
            border: none;
            border-radius: 0.5rem;
            box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
            overflow: hidden;
        }
        
        .doctor-header {
            background-color: var(--doctor-primary);
            color: white;
            padding: 1.25rem;
            text-align: center;
            border-bottom: 1px solid rgba(255,255,255,0.15);
        }
        
        .doctor-nav-item {
            border-bottom: 1px solid rgba(0,0,0,0.05);
            transition: all 0.3s;
        }
        
        .doctor-nav-item:last-child {
            border-bottom: none;
        }
        
        .doctor-nav-link {
            color: #5a5c69;
            padding: 1rem 1.5rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .doctor-nav-link:hover {
            color: var(--doctor-dark);
            background-color: var(--doctor-light);
        }
        
        .doctor-nav-link i {
            width: 1.5rem;
            text-align: center;
            font-size: 1.1rem;
        }
        
        .doctor-nav-link.danger {
            color: var(--doctor-danger);
        }
        
        .doctor-nav-link.danger:hover {
            color: white;
            background-color: var(--doctor-danger);
        }
        
        .availability-toggle {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.75rem 1.5rem;
            background-color: #f8f9fa;
            border-top: 1px solid rgba(0,0,0,0.05);
        }
        
        .availability-text {
            font-weight: 600;
            color: #5a5c69;
        }
        
        .form-check-input:checked {
            background-color: var(--doctor-primary);
            border-color: var(--doctor-primary);
        }
        
        .status-badge {
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
            margin-left: 0.5rem;
        }
        
        @media (max-width: 576px) {
            .doctor-nav-card {
                border-radius: 0;
            }
        }
    </style>
</head>
<body class="d-flex align-items-center min-vh-100 py-4">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-5 col-md-7">
                <div class="doctor-nav-card">
                    <div class="doctor-header">
                        <h4 class="mb-0"><i class="fas fa-user-md me-2"></i>Doctor's Portal</h4>
                    </div>
                    <div class="list-group list-group-flush">
                        <a href="manage_slot.php" class="list-group-item list-group-item-action doctor-nav-item doctor-nav-link">
                            <i class="fas fa-home"></i>Manage Time Slot
                        </a>
                        <a href="profile.php" class="list-group-item list-group-item-action doctor-nav-item doctor-nav-link">
                            <i class="fas fa-user-edit"></i>Edit Profile
                        </a>
                        <a href="appointment.php" class="list-group-item list-group-item-action doctor-nav-item doctor-nav-link">
                            <i class="fas fa-calendar-check"></i>Appointments
                        </a>
                        <a href="chat_list.php" class="list-group-item list-group-item-action doctor-nav-item doctor-nav-link">
                            <i class="fas fa-comments"></i>Chat with Patients
                        </a>
                        <a href="notification.php" class="list-group-item list-group-item-action doctor-nav-item doctor-nav-link">
                            <i class="fas fa-bell"></i>Notifications
                            <?php if ($notification_count > 0): ?>
                                <span class="badge bg-danger rounded-pill ms-auto"><?= htmlspecialchars($notification_count) ?></span>
                            <?php endif; ?>
                        </a>
                        <a href="#" class="list-group-item list-group-item-action doctor-nav-item doctor-nav-link danger" data-bs-toggle="modal" data-bs-target="#logoutModal">
                            <i class="fas fa-sign-out-alt"></i>Logout
                        </a>
                    </div>
                    <div class="availability-toggle">
                        <span class="availability-text">
                            <i class="fas fa-circle me-2 <?= $doctorStatus === 'active' ? 'text-success' : 'text-danger' ?>"></i>
                            <span id="statusText"><?= ucfirst($doctorStatus) ?></span>
                            <span class="badge status-badge <?= $doctorStatus === 'active' ? 'bg-success' : 'bg-danger' ?>" id="statusBadge">
                                <?= $doctorStatus === 'active' ? 'Active' : 'Inactive' ?>
                            </span>
                        </span>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="availabilitySwitch" <?= $doctorStatus === 'active' ? 'checked' : '' ?>>
                        </div>
                    </div>
                </div>
                
                <div class="card mt-3 border-0 shadow-sm">
                    <div class="card-body text-center py-2">
                        <small class="text-muted">Logged in as: <strong><?= htmlspecialchars($_SESSION['user_email']) ?></strong></small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="logoutModal" tabindex="-1" aria-labelledby="logoutModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="logoutModalLabel">Confirm Logout</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to logout?</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <form method="POST">
                        <button type="submit" name="logout" class="btn btn-primary">Logout</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        const availabilitySwitch = document.getElementById('availabilitySwitch');
        const statusText = document.getElementById('statusText');
        const statusBadge = document.getElementById('statusBadge');
        
        availabilitySwitch.addEventListener('change', function() {
            const newStatus = this.checked ? 'active' : 'inactive';
            
            if (newStatus === 'active') {
                statusText.textContent = 'Active';
                statusBadge.textContent = 'Active';
                statusBadge.classList.remove('bg-danger');
                statusBadge.classList.add('bg-success');
                statusBadge.previousElementSibling.classList.remove('text-danger');
                statusBadge.previousElementSibling.classList.add('text-success');
            } else {
                statusText.textContent = 'Inactive';
                statusBadge.textContent = 'Inactive';
                statusBadge.classList.remove('bg-success');
                statusBadge.classList.add('bg-danger');
                statusBadge.previousElementSibling.classList.remove('text-success');
                statusBadge.previousElementSibling.classList.add('text-danger');
            }
            
            fetch(window.location.href, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'update_availability=true&status=' + newStatus
            })
            .then(response => response.json())
            .then(data => {
                if (!data.success) {
                    availabilitySwitch.checked = !availabilitySwitch.checked;
                    if (newStatus === 'active') {
                        statusText.textContent = 'Inactive';
                        statusBadge.textContent = 'Inactive';
                        statusBadge.classList.remove('bg-success');
                        statusBadge.classList.add('bg-danger');
                        statusBadge.previousElementSibling.classList.remove('text-success');
                        statusBadge.previousElementSibling.classList.add('text-danger');
                    } else {
                        statusText.textContent = 'Active';
                        statusBadge.textContent = 'Active';
                        statusBadge.classList.remove('bg-danger');
                        statusBadge.classList.add('bg-success');
                        statusBadge.previousElementSibling.classList.remove('text-danger');
                        statusBadge.previousElementSibling.classList.add('text-success');
                    }
                    alert('Failed to update status. Please try again.');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                availabilitySwitch.checked = !availabilitySwitch.checked;
                alert('An error occurred. Please try again.');
            });
        });
    </script>
</body>
</html>