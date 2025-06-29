<?php
session_start();
require_once '../auth/check_totp.php';
require_once '../config.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['user_role'] !== 'admin') {
    header('Location:' . BASE_URL . '/auth/login.php');
    exit();
}


$currentAdminId = $_SESSION['user_id'];

try {
    $conn = getDBConnection();
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'create_user':
                    createUser($conn);
                    break;
                case 'update_user':
                    updateUser($conn);
                    break;
                case 'update_password':
                    updatePassword($conn);
                    break;
                case 'delete_user':
                    deleteUser($conn);
                    break;
                case 'change_role':
                    changeUserRole($conn);
                    break;
            }
        }
    }
    
    $users = $conn->query("
        SELECT 
            u.UserID, u.NAME, u.Email, u.ICNumber, u.ContactNumber,
            CASE 
                WHEN a.UserID IS NOT NULL THEN 'admin'
                WHEN d.UserID IS NOT NULL THEN 'doctor'
                ELSE 'patient'
            END as role,
            d.STATUS as doctor_status
        FROM systemuser u
        LEFT JOIN admin a ON u.UserID = a.UserID
        LEFT JOIN doctor d ON u.UserID = d.UserID
        WHERE u.UserID != $currentAdminId
        ORDER BY u.NAME
    ")->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}

function createUser($conn) {
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $role = $_POST['role'];
    $icNumber = trim($_POST['ic_number']);
    $contactNumber = trim($_POST['contact_number']);
    $doctorStatus = isset($_POST['doctor_status']) ? trim($_POST['doctor_status']) : 'active';
    
    if (empty($name) || empty($email) || empty($password) || empty($role)) {
        $_SESSION['message'] = ['type' => 'danger', 'text' => 'All fields are required'];
        return;
    }
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['message'] = ['type' => 'danger', 'text' => 'Invalid email format'];
        return;
    }
    
    $stmt = $conn->prepare("SELECT COUNT(*) FROM systemuser WHERE Email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetchColumn() > 0) {
        $_SESSION['message'] = ['type' => 'danger', 'text' => 'Email already registered'];
        return;
    }
    
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    if ($hashedPassword === false) {
        $_SESSION['message'] = ['type' => 'danger', 'text' => 'Failed to hash password'];
        return;
    }
    
    try {
        $conn->beginTransaction();
        
        $stmt = $conn->prepare("
            INSERT INTO systemuser (NAME, Email, PASSWORD, ICNumber, ContactNumber)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$name, $email, $hashedPassword, $icNumber, $contactNumber]);
        $userId = $conn->lastInsertId();
        
        switch ($role) {
            case 'admin':
                $stmt = $conn->prepare("INSERT INTO admin (UserID) VALUES (?)");
                $stmt->execute([$userId]);
                break;
            case 'doctor':
                $stmt = $conn->prepare("INSERT INTO doctor (UserID, STATUS) VALUES (?, ?)");
                $stmt->execute([$userId, $doctorStatus]);
                break;
        }
        
        $conn->commit();
        $_SESSION['message'] = ['type' => 'success', 'text' => 'User created successfully'];
        
    } catch (PDOException $e) {
        $conn->rollBack();
        $_SESSION['message'] = ['type' => 'danger', 'text' => 'Database error: ' . $e->getMessage()];
    }
}

function updateUser($conn) {
    $userId = $_POST['user_id'];
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $icNumber = trim($_POST['ic_number']);
    $contactNumber = trim($_POST['contact_number']);
    $doctorStatus = isset($_POST['doctor_status']) ? trim($_POST['doctor_status']) : null;
    
    if (empty($name) || empty($email)) {
        $_SESSION['message'] = ['type' => 'danger', 'text' => 'Name and email are required'];
        return;
    }
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['message'] = ['type' => 'danger', 'text' => 'Invalid email format'];
        return;
    }
    
    try {
        $conn->beginTransaction();
        
        $stmt = $conn->prepare("
            UPDATE systemuser 
            SET NAME = ?, Email = ?, ICNumber = ?, ContactNumber = ?
            WHERE UserID = ?
        ");
        $stmt->execute([$name, $email, $icNumber, $contactNumber, $userId]);
        
        if ($doctorStatus !== null) {
            $stmt = $conn->prepare("UPDATE doctor SET STATUS = ? WHERE UserID = ?");
            $stmt->execute([$doctorStatus, $userId]);
        }
        
        $conn->commit();
        $_SESSION['message'] = ['type' => 'success', 'text' => 'User updated successfully'];
        
    } catch (PDOException $e) {
        $conn->rollBack();
        $_SESSION['message'] = ['type' => 'danger', 'text' => 'Database error: ' . $e->getMessage()];
    }
}

function updatePassword($conn) {
    $userId = $_POST['user_id'];
    $newPassword = $_POST['new_password'];
    $confirmPassword = $_POST['confirm_password'];
    
    if (empty($newPassword) || empty($confirmPassword)) {
        $_SESSION['message'] = ['type' => 'danger', 'text' => 'Both password fields are required'];
        return;
    }
    
    if ($newPassword !== $confirmPassword) {
        $_SESSION['message'] = ['type' => 'danger', 'text' => 'Passwords do not match'];
        return;
    }
    
    if (strlen($newPassword) < 8) {
        $_SESSION['message'] = ['type' => 'danger', 'text' => 'Password must be at least 8 characters'];
        return;
    }
    
    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
    if ($hashedPassword === false) {
        $_SESSION['message'] = ['type' => 'danger', 'text' => 'Failed to hash password'];
        return;
    }
    
    try {
        $stmt = $conn->prepare("UPDATE systemuser SET PASSWORD = ? WHERE UserID = ?");
        $stmt->execute([$hashedPassword, $userId]);
        
        $_SESSION['message'] = ['type' => 'success', 'text' => 'Password updated successfully'];
        
    } catch (PDOException $e) {
        $_SESSION['message'] = ['type' => 'danger', 'text' => 'Database error: ' . $e->getMessage()];
    }
}

function deleteUser($conn) {
    $userId = $_POST['user_id'];
    
    try {
        $conn->beginTransaction();
        
        $conn->exec("DELETE FROM admin WHERE UserID = $userId");
        $conn->exec("DELETE FROM doctor WHERE UserID = $userId");
        
        $conn->exec("DELETE FROM systemuser WHERE UserID = $userId");
        
        $conn->commit();
        $_SESSION['message'] = ['type' => 'success', 'text' => 'User deleted successfully'];
        
    } catch (PDOException $e) {
        $conn->rollBack();
        $_SESSION['message'] = ['type' => 'danger', 'text' => 'Database error: ' . $e->getMessage()];
    }
}

function changeUserRole($conn) {
    $userId = $_POST['user_id'];
    $newRole = $_POST['new_role'];
    $doctorStatus = isset($_POST['doctor_status']) ? trim($_POST['doctor_status']) : 'active';
    
    try {
        $conn->beginTransaction();
        
        $conn->exec("DELETE FROM admin WHERE UserID = $userId");
        $conn->exec("DELETE FROM doctor WHERE UserID = $userId");
        
        switch ($newRole) {
            case 'admin':
                $conn->exec("INSERT INTO admin (UserID) VALUES ($userId)");
                break;
            case 'doctor':
                $conn->exec("INSERT INTO doctor (UserID, STATUS) VALUES ($userId, '$doctorStatus')");
                break;
        }
        
        $conn->commit();
        $_SESSION['message'] = ['type' => 'success', 'text' => 'User role changed successfully'];
        
    } catch (PDOException $e) {
        $conn->rollBack();
        $_SESSION['message'] = ['type' => 'danger', 'text' => 'Database error: ' . $e->getMessage()];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #4e73df;
            --secondary: #858796;
            --light: #f8f9fc;
            --success: #1cc88a;
            --info: #36b9cc;
            --warning: #f6c23e;
            --danger: #e74a3b;
        }
        
        body {
            background-color: #f8f9fa;
            font-family: 'Nunito', sans-serif;
            padding-bottom: 2rem;
        }
        
        .management-container {
            background-color: white;
            border-radius: 0.5rem;
            box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
            padding: 1.5rem;
            width: 100%;
            margin: 1rem auto;
        }
        
        @media (min-width: 768px) {
            .management-container {
                padding: 2rem;
                margin: 2rem auto;
                max-width: 95%;
            }
        }
        
        .management-header {
            color: var(--primary);
            text-align: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #eaecf4;
        }
        
        .user-table {
            width: 100%;
            margin-bottom: 1.5rem;
            font-size: 0.875rem;
        }
        
        @media (min-width: 768px) {
            .user-table {
                font-size: 1rem;
                margin-bottom: 2rem;
            }
        }
        
        .user-table th {
            background-color: var(--light);
            color: var(--secondary);
            font-weight: 600;
            padding: 0.75rem;
            white-space: nowrap;
        }
        
        .user-table td {
            padding: 0.75rem;
            border-top: 1px solid #eaecf4;
            vertical-align: middle;
            word-break: break-word;
        }
        
        .badge-admin {
            background-color: var(--primary);
        }
        
        .badge-doctor {
            background-color: var(--success);
        }
        
        .badge-patient {
            background-color: var(--info);
        }
        
        .badge-inactive {
            background-color: var(--danger);
        }
        
        .action-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 0.25rem;
        }
        
        .action-buttons .btn {
            padding: 0.25rem 0.5rem;
            font-size: 0.75rem;
            min-width: 2rem;
        }
        
        @media (min-width: 768px) {
            .action-buttons .btn {
                font-size: 0.875rem;
                min-width: auto;
            }
        }
        
        .btn-create {
            background-color: var(--primary);
            color: white;
            font-weight: 600;
            padding: 0.5rem 1rem;
            margin-bottom: 1rem;
        }
        
        @media (min-width: 768px) {
            .btn-create {
                padding: 0.5rem 1.5rem;
                margin-bottom: 0;
            }
        }
        
        .btn-return {
            color: var(--secondary);
            border: 1px solid #ddd;
            padding: 0.5rem 1rem;
            margin-bottom: 1rem;
        }
        
        @media (min-width: 768px) {
            .btn-return {
                padding: 0.5rem 1.5rem;
                margin-bottom: 0;
            }
        }
        
        .modal-content {
            border-radius: 0.5rem;
        }
        
        .form-label {
            font-weight: 600;
            color: var(--secondary);
        }
        
        .message-alert {
            position: fixed;
            top: 1rem;
            right: 1rem;
            left: 1rem;
            z-index: 1000;
        }
        
        @media (min-width: 768px) {
            .message-alert {
                left: auto;
                min-width: 300px;
            }
        }
        
        .password-toggle {
            cursor: pointer;
            position: absolute;
            right: 10px;
            top: 10px;
        }
        
        .table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        
        .header-actions {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        
        @media (min-width: 768px) {
            .header-actions {
                flex-direction: row;
                justify-content: space-between;
                align-items: center;
            }
        }
        
        .mobile-label {
            display: inline-block;
            font-weight: 600;
            color: var(--secondary);
            min-width: 80px;
        }
        
        @media (min-width: 768px) {
            .mobile-label {
                display: none;
            }
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="management-container">
            <div class="management-header">
                <h4><i class="fas fa-users-cog me-2"></i>User Management</h4>
            </div>
            
            <?php if (isset($_SESSION['message'])): ?>
            <div class="message-alert alert alert-<?= $_SESSION['message']['type'] ?> alert-dismissible fade show" role="alert">
                <?= $_SESSION['message']['text'] ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php unset($_SESSION['message']); endif; ?>
            
            <div class="header-actions mb-3">
                <button class="btn btn-return" onclick="window.history.back()">
                    <i class="fas fa-arrow-left me-2"></i>Return
                </button>
                <button class="btn btn-create" data-bs-toggle="modal" data-bs-target="#createUserModal">
                    <i class="fas fa-plus me-2"></i>Create New User
                </button>
            </div>
            
            <div class="table-responsive">
                <table class="user-table">
                    <thead class="d-none d-md-table-header-group">
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Contact</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user): ?>
                        <tr>
                            <td>
                                <span class="d-md-none mobile-label">Name: </span>
                                <?= htmlspecialchars($user['NAME']) ?>
                            </td>
                            <td>
                                <span class="d-md-none mobile-label">Email: </span>
                                <?= htmlspecialchars($user['Email']) ?>
                            </td>
                            <td>
                                <span class="d-md-none mobile-label">Contact: </span>
                                <?= htmlspecialchars($user['ContactNumber']) ?>
                            </td>
                            <td>
                                <span class="d-md-none mobile-label">Role: </span>
                                <span class="badge rounded-pill badge-<?= $user['role'] ?>">
                                    <?= ucfirst($user['role']) ?>
                                </span>
                            </td>
                            <td>
                                <span class="d-md-none mobile-label">Status: </span>
                                <?php if ($user['role'] === 'doctor'): ?>
                                <span class="badge rounded-pill <?= $user['doctor_status'] === 'active' ? 'bg-success' : 'bg-danger' ?>">
                                    <?= ucfirst($user['doctor_status']) ?>
                                </span>
                                <?php else: ?>
                                <span class="badge rounded-pill bg-secondary">N/A</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="action-buttons">
                                    <button class="btn btn-sm btn-outline-primary" 
                                            data-bs-toggle="modal" 
                                            data-bs-target="#editUserModal"
                                            data-userid="<?= $user['UserID'] ?>"
                                            data-name="<?= htmlspecialchars($user['NAME']) ?>"
                                            data-email="<?= htmlspecialchars($user['Email']) ?>"
                                            data-icnumber="<?= htmlspecialchars($user['ICNumber']) ?>"
                                            data-contact="<?= htmlspecialchars($user['ContactNumber']) ?>"
                                            data-role="<?= $user['role'] ?>"
                                            data-status="<?= $user['doctor_status'] ?? '' ?>"
                                            data-bs-toggle="tooltip" data-bs-placement="top" title="Edit">
                                        <i class="fas fa-edit"></i>
                                        <span class="d-none d-md-inline"> Edit</span>
                                    </button>
                                    <button class="btn btn-sm btn-outline-secondary" 
                                            data-bs-toggle="modal" 
                                            data-bs-target="#changePasswordModal"
                                            data-userid="<?= $user['UserID'] ?>"
                                            data-name="<?= htmlspecialchars($user['NAME']) ?>"
                                            data-bs-toggle="tooltip" data-bs-placement="top" title="Change Password">
                                        <i class="fas fa-key"></i>
                                        <span class="d-none d-md-inline"> Password</span>
                                    </button>
                                    <button class="btn btn-sm btn-outline-info" 
                                            data-bs-toggle="modal" 
                                            data-bs-target="#changeRoleModal"
                                            data-userid="<?= $user['UserID'] ?>"
                                            data-name="<?= htmlspecialchars($user['NAME']) ?>"
                                            data-currentrole="<?= $user['role'] ?>"
                                            data-bs-toggle="tooltip" data-bs-placement="top" title="Change Role">
                                        <i class="fas fa-user-tag"></i>
                                        <span class="d-none d-md-inline"> Role</span>
                                    </button>
                                    <button class="btn btn-sm btn-outline-danger" 
                                            data-bs-toggle="modal" 
                                            data-bs-target="#deleteUserModal"
                                            data-userid="<?= $user['UserID'] ?>"
                                            data-name="<?= htmlspecialchars($user['NAME']) ?>"
                                            data-bs-toggle="tooltip" data-bs-placement="top" title="Delete">
                                        <i class="fas fa-trash-alt"></i>
                                        <span class="d-none d-md-inline"> Delete</span>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <?php if (empty($users)): ?>
            <div class="alert alert-info text-center">
                No users found (excluding current admin).
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="modal fade" id="createUserModal" tabindex="-1" aria-labelledby="createUserModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="create_user">
                    <div class="modal-header">
                        <h5 class="modal-title" id="createUserModalLabel">Create New User</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="createName" class="form-label">Full Name</label>
                            <input type="text" class="form-control" id="createName" name="name" required>
                        </div>
                        <div class="mb-3">
                            <label for="createEmail" class="form-label">Email</label>
                            <input type="email" class="form-control" id="createEmail" name="email" required>
                        </div>
                        <div class="mb-3">
                            <label for="createPassword" class="form-label">Password</label>
                            <div class="position-relative">
                                <input type="password" class="form-control" id="createPassword" name="password" required>
                                <i class="fas fa-eye password-toggle" onclick="togglePassword('createPassword')"></i>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="createICNumber" class="form-label">IC Number</label>
                            <input type="text" class="form-control" id="createICNumber" name="ic_number">
                        </div>
                        <div class="mb-3">
                            <label for="createContact" class="form-label">Contact Number</label>
                            <input type="tel" class="form-control" id="createContact" name="contact_number">
                        </div>
                        <div class="mb-3">
                            <label for="createRole" class="form-label">Role</label>
                            <select class="form-select" id="createRole" name="role" required>
                                <option value="">Select Role</option>
                                <option value="admin">Admin</option>
                                <option value="doctor">Doctor</option>
                                <option value="patient">Patient</option>
                            </select>
                        </div>
                        <div class="mb-3" id="doctorStatusField" style="display: none;">
                            <label for="createDoctorStatus" class="form-label">Doctor Status</label>
                            <select class="form-select" id="createDoctorStatus" name="doctor_status">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Create User</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="editUserModal" tabindex="-1" aria-labelledby="editUserModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="update_user">
                    <input type="hidden" name="user_id" id="editUserId">
                    <div class="modal-header">
                        <h5 class="modal-title" id="editUserModalLabel">Edit User</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="editName" class="form-label">Full Name</label>
                            <input type="text" class="form-control" id="editName" name="name" required>
                        </div>
                        <div class="mb-3">
                            <label for="editEmail" class="form-label">Email</label>
                            <input type="email" class="form-control" id="editEmail" name="email" required>
                        </div>
                        <div class="mb-3">
                            <label for="editICNumber" class="form-label">IC Number</label>
                            <input type="text" class="form-control" id="editICNumber" name="ic_number">
                        </div>
                        <div class="mb-3">
                            <label for="editContact" class="form-label">Contact Number</label>
                            <input type="tel" class="form-control" id="editContact" name="contact_number">
                        </div>
                        <div class="mb-3" id="editDoctorStatusField">
                            <label for="editDoctorStatus" class="form-label">Doctor Status</label>
                            <select class="form-select" id="editDoctorStatus" name="doctor_status">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="changePasswordModal" tabindex="-1" aria-labelledby="changePasswordModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="update_password">
                    <input type="hidden" name="user_id" id="changePasswordUserId">
                    <div class="modal-header">
                        <h5 class="modal-title" id="changePasswordModalLabel">Change Password</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p>Changing password for: <strong id="changePasswordUserName"></strong></p>
                        <div class="mb-3">
                            <label for="newPassword" class="form-label">New Password</label>
                            <div class="position-relative">
                                <input type="password" class="form-control" id="newPassword" name="new_password" required>
                                <i class="fas fa-eye password-toggle" onclick="togglePassword('newPassword')"></i>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="confirmPassword" class="form-label">Confirm New Password</label>
                            <div class="position-relative">
                                <input type="password" class="form-control" id="confirmPassword" name="confirm_password" required>
                                <i class="fas fa-eye password-toggle" onclick="togglePassword('confirmPassword')"></i>
                            </div>
                        </div>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>Password must be at least 8 characters long.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Change Password</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="deleteUserModal" tabindex="-1" aria-labelledby="deleteUserModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="delete_user">
                    <input type="hidden" name="user_id" id="deleteUserId">
                    <div class="modal-header">
                        <h5 class="modal-title" id="deleteUserModalLabel">Confirm Deletion</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p>Are you sure you want to delete user <strong id="deleteUserName"></strong>?</p>
                        <p class="text-danger">This action cannot be undone.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Delete User</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="changeRoleModal" tabindex="-1" aria-labelledby="changeRoleModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="change_role">
                    <input type="hidden" name="user_id" id="changeRoleUserId">
                    <div class="modal-header">
                        <h5 class="modal-title" id="changeRoleModalLabel">Change User Role</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p>Changing role for user: <strong id="changeRoleUserName"></strong></p>
                        <div class="mb-3">
                            <label for="newRole" class="form-label">New Role</label>
                            <select class="form-select" id="newRole" name="new_role" required>
                                <option value="">Select Role</option>
                                <option value="admin">Admin</option>
                                <option value="doctor">Doctor</option>
                                <option value="patient">Patient</option>
                            </select>
                        </div>
                        <div class="mb-3" id="changeRoleStatusField" style="display: none;">
                            <label for="changeRoleDoctorStatus" class="form-label">Doctor Status</label>
                            <select class="form-select" id="changeRoleDoctorStatus" name="doctor_status">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Change Role</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        document.getElementById('createRole').addEventListener('change', function() {
            document.getElementById('doctorStatusField').style.display = 
                this.value === 'doctor' ? 'block' : 'none';
        });
        
        document.getElementById('newRole').addEventListener('change', function() {
            document.getElementById('changeRoleStatusField').style.display = 
                this.value === 'doctor' ? 'block' : 'none';
        });

        const editUserModal = document.getElementById('editUserModal');
        editUserModal.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            const userId = button.getAttribute('data-userid');
            const name = button.getAttribute('data-name');
            const email = button.getAttribute('data-email');
            const icNumber = button.getAttribute('data-icnumber');
            const contact = button.getAttribute('data-contact');
            const role = button.getAttribute('data-role');
            const status = button.getAttribute('data-status');
            
            document.getElementById('editUserId').value = userId;
            document.getElementById('editName').value = name;
            document.getElementById('editEmail').value = email;
            document.getElementById('editICNumber').value = icNumber;
            document.getElementById('editContact').value = contact;
            
            const statusField = document.getElementById('editDoctorStatusField');
            statusField.style.display = role === 'doctor' ? 'block' : 'none';
            
            if (status) {
                document.getElementById('editDoctorStatus').value = status;
            }
        });

        const changePasswordModal = document.getElementById('changePasswordModal');
        changePasswordModal.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            document.getElementById('changePasswordUserId').value = button.getAttribute('data-userid');
            document.getElementById('changePasswordUserName').textContent = button.getAttribute('data-name');
        });

        const deleteUserModal = document.getElementById('deleteUserModal');
        deleteUserModal.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            document.getElementById('deleteUserId').value = button.getAttribute('data-userid');
            document.getElementById('deleteUserName').textContent = button.getAttribute('data-name');
        });

        const changeRoleModal = document.getElementById('changeRoleModal');
        changeRoleModal.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            const userId = button.getAttribute('data-userid');
            const name = button.getAttribute('data-name');
            const currentRole = button.getAttribute('data-currentrole');
            
            document.getElementById('changeRoleUserId').value = userId;
            document.getElementById('changeRoleUserName').textContent = name;
            document.getElementById('newRole').value = currentRole;
            
            document.getElementById('newRole').dispatchEvent(new Event('change'));
        });

        function togglePassword(fieldId) {
            const field = document.getElementById(fieldId);
            const icon = field.nextElementSibling;
            if (field.type === 'password') {
                field.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                field.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }

        const alert = document.querySelector('.alert.alert-dismissible');
        if (alert) {
            setTimeout(() => {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            }, 5000);
        }

        const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });

        document.addEventListener('DOMContentLoaded', function() {
            const isMobile = window.matchMedia('(max-width: 767px)').matches;
            if (isMobile) {
                const tableHeaders = document.querySelectorAll('.user-table th');
                tableHeaders.forEach(header => header.style.display = 'none');
            }
        });
    </script>
</body>
</html>