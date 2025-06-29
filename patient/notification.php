<?php
session_start();
require_once '../auth/check_totp.php';
require_once '../config.php';
require_once '../notification_helper.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['user_role'] !== 'patient') {
    header('Location:' . BASE_URL . '/auth/login.php');
    exit();
}

$userID = $_SESSION['user_id'] ?? 6;
$notifications = [];

try {
    $pdo = getDBConnection();
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_all_read'])) {
        if (markAllNotificationsAsRead($pdo, $userID)) {
            $_SESSION['success_message'] = "All notifications marked as read";
        } else {
            $_SESSION['error_message'] = "Failed to mark notifications as read";
        }
        header('Location: notification.php');
        exit();
    }
    
    $notifications = getUserNotifications($pdo, $userID, 100);
} catch (PDOException $e) {
    error_log("Error fetching notifications: " . $e->getMessage());
    $notifications = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Doctor Notifications</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #4e73df;
            --success: #1cc88a;
            --warning: #f6c23e;
            --danger: #e74a3b;
            --info: #36b9cc;
            --light: #f8f9fc;
        }

        body {
            background-color: #f8f9fa;
            font-family: 'Nunito', sans-serif;
        }
        
        .notification-container {
            max-width: 800px;
            margin: 2rem auto;
            background-color: white;
            border-radius: 0.5rem;
            box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
            padding: 2rem;
        }
        
        .notification-header {
            color: var(--primary);
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #eaecf4;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .notification-list {
            list-style: none;
            padding: 0;
        }
        
        .notification-item {
            padding: 1rem;
            margin-bottom: 1rem;
            border-radius: 0.35rem;
            border-left: 4px solid;
            background-color: var(--light);
            display: flex;
            align-items: flex-start;
        }
        
        .notification-item.appointment {
            border-left-color: var(--primary);
        }
        
        .notification-item.payment {
            border-left-color: var(--info);
        }
        
        .notification-item.completed {
            border-left-color: var(--success);
        }
        
        .notification-item.cancelled {
            border-left-color: var(--danger);
        }
        
        .notification-item.rescheduled {
            border-left-color: var(--warning);
        }
        
        .notification-icon {
            font-size: 1.25rem;
            margin-right: 1rem;
            margin-top: 0.25rem;
        }
        
        .notification-content {
            flex: 1;
        }
        
        .notification-time {
            font-size: 0.8rem;
            color: #6c757d;
            margin-top: 0.25rem;
        }
        
        .empty-state {
            text-align: center;
            padding: 2rem;
            color: #6c757d;
        }
        
        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: #dee2e6;
        }
        
        .btn-mark-all {
            background-color: var(--primary);
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 0.25rem;
        }
        
        .btn-mark-all:hover {
            background-color: #2e59d9;
        }
        
        .badge-count {
            background-color: var(--danger);
            border-radius: 10px;
            padding: 0.25rem 0.5rem;
            font-size: 0.75rem;
        }
    </style>
</head>
<body>
    <div class="notification-container">
        <div class="notification-header">
            <h4 class="mb-0">
                <i class="fas fa-bell me-2"></i>Notifications
                <?php if (!empty($notifications)): ?>
                    <span class="badge-count"><?= count($notifications) ?></span>
                <?php endif; ?>
            </h4>
            <?php if (!empty($notifications)): ?>
                <form method="POST">
                    <button type="submit" name="mark_all_read" class="btn-mark-all">
                        <i class="fas fa-check-circle me-1"></i>Mark all as read
                    </button>
                </form>
            <?php endif; ?>
        </div>
        
        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <?= $_SESSION['success_message'] ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['success_message']); ?>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <?= $_SESSION['error_message'] ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['error_message']); ?>
        <?php endif; ?>
        
        <?php if (empty($notifications)): ?>
            <div class="empty-state">
                <i class="fas fa-bell-slash"></i>
                <h5>No notifications yet</h5>
                <p>You'll see important updates here when they become available</p>
            </div>
        <?php else: ?>
            <ul class="notification-list">
                <?php foreach ($notifications as $notification): 
                    $type = getNotificationType($notification['Message']);
                    $icon = getNotificationIcon($type);
                ?>
                <li class="notification-item <?= $type ?>">
                    <i class="fas <?= $icon ?> notification-icon"></i>
                    <div class="notification-content">
                        <div><?= htmlspecialchars($notification['Message']) ?></div>
                        <div class="notification-time">
                            <?= (new DateTime($notification['CreatedAt']))->format('M j, Y \a\t g:i A') ?>
                        </div>
                    </div>
                </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>