<?php
session_start();
require_once '../config.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['user_role'] !== 'admin') {
    header('Location:' . BASE_URL . '/auth/login.php');
    exit();
}

if (isset($_POST['delete_account'])) {
    try {
        $db = getDBConnection();
        
        $db->beginTransaction();
        
        $stmt = $db->prepare("DELETE FROM Notification WHERE UserID = ?");
        $stmt->execute([$_SESSION['user_id']]);
        
        $stmt = $db->prepare("DELETE FROM SystemUser WHERE UserID = ?");
        $stmt->execute([$_SESSION['user_id']]);
        
        $db->commit();
        
        session_destroy();
        header('Location: ../auth/login.php');
        exit();
    } catch (PDOException $e) {
        $db->rollBack();
        error_log("Account deletion error: " . $e->getMessage());
        $_SESSION['error_message'] = "Failed to delete account. Please try again.";
    }
}

if (isset($_POST['logout'])) {
    session_destroy();
    header('Location: ../auth/login.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Navigation</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #4e73df;
            --secondary: #858796;
            --danger: #e74a3b;
            --light: #f8f9fc;
        }
        
        body {
            background-color: #f8f9fa;
            font-family: 'Nunito', sans-serif;
        }
        
        .nav-card {
            border: none;
            border-radius: 0.5rem;
            box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
            overflow: hidden;
        }
        
        .nav-header {
            background-color: var(--primary);
            color: white;
            padding: 1.25rem;
            text-align: center;
            border-bottom: 1px solid rgba(255,255,255,0.15);
        }
        
        .nav-item {
            border-bottom: 1px solid rgba(0,0,0,0.05);
            transition: all 0.3s;
        }
        
        .nav-item:last-child {
            border-bottom: none;
        }
        
        .nav-link {
            color: var(--secondary);
            padding: 1rem 1.5rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            cursor: pointer;
        }
        
        .nav-link:hover {
            color: var(--primary);
            background-color: var(--light);
        }
        
        .nav-link i {
            width: 1.5rem;
            text-align: center;
            font-size: 1.1rem;
        }
        
        .nav-link.danger {
            color: var(--danger);
        }
        
        .nav-link.danger:hover {
            color: #fff;
            background-color: var(--danger);
        }
        
        @media (max-width: 576px) {
            .nav-card {
                border-radius: 0;
            }
        }
    </style>
</head>
<body class="d-flex align-items-center min-vh-100 py-4">
    <div class="container">
        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        
        <div class="row justify-content-center">
            <div class="col-lg-5 col-md-7">
                <div class="nav-card">
                    <div class="nav-header">
                        <h4 class="mb-0"><i class="fas fa-user-shield me-2"></i>Admin Home</h4>
                    </div>
                    <div class="list-group list-group-flush">
                        <a href="profile.php" class="list-group-item list-group-item-action nav-item nav-link">
                            <i class="fas fa-user-circle"></i>Edit Profile
                        </a>
                        <a href="manage_user.php" class="list-group-item list-group-item-action nav-item nav-link">
                            <i class="fas fa-users-cog"></i>Manage Accounts
                        </a>
                        <a href="manage_appointment.php" class="list-group-item list-group-item-action nav-item nav-link">
                            <i class="fas fa-calendar-alt"></i>Manage Appointments
                        </a>
                        <a href="analytics.php" class="list-group-item list-group-item-action nav-item nav-link">
                            <i class="fas fa-chart-bar"></i>Analytics
                        </a>
                        <a href="#" class="list-group-item list-group-item-action nav-item nav-link danger" data-bs-toggle="modal" data-bs-target="#deleteAccountModal">
                            <i class="fas fa-trash-alt"></i>Delete Account
                        </a>
                        <a href="#" class="list-group-item list-group-item-action nav-item nav-link danger" data-bs-toggle="modal" data-bs-target="#logoutModal">
                            <i class="fas fa-sign-out-alt"></i>Logout
                        </a>
                    </div>
                </div>
                
                <div class="card mt-3 border-0 shadow-sm">
                    <div class="card-body text-center py-2">
                        <small class="text-muted">Logged in as: <strong><?php echo htmlspecialchars($_SESSION['user_email']); ?></strong></small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="deleteAccountModal" tabindex="-1" aria-labelledby="deleteAccountModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="deleteAccountModalLabel">Confirm Account Deletion</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete your account? This action cannot be undone. All your data will be permanently removed.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <form method="post" style="display: inline;">
                        <button type="submit" name="delete_account" class="btn btn-danger">Delete My Account</button>
                    </form>
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
                    <form method="post" style="display: inline;">
                        <button type="submit" name="logout" class="btn btn-primary">Logout</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>