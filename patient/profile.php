<?php
session_start();
require_once '../auth/check_totp.php';
require_once '../config.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['user_role'] !== 'patient') {
    header('Location:' . BASE_URL . '/auth/login.php');
    exit();
}

$patientUserID = $_SESSION['user_id'];
$statusMessage = '';

function getUserById(int $userId): ?array {
    $conn = null;
    try {
        $conn = getDBConnection();
        $stmt = $conn->prepare("SELECT UserID, Name, Email, Password, ICNumber, ContactNumber FROM SystemUser WHERE UserID = :userId");
        $stmt->bindParam(':userId', $userId, PDO::PARAM_INT);
        $stmt->execute();
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        return $user ?: null;
    } catch (PDOException $e) {
        error_log("Error fetching SystemUser by ID: " . $e->getMessage());
        return null;
    } finally {
        $conn = null;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
    $conn = null;
    try {
        $conn = getDBConnection();
        $conn->beginTransaction();

        $userIdToUpdate = $_POST['user_id'] ?? 0;
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $contactNumber = trim($_POST['contact_number'] ?? '');
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if ($userIdToUpdate != $patientUserID) {
            throw new Exception("Unauthorized attempt to update profile.");
        }
        if (empty($name) || empty($email) || empty($contactNumber)) {
            throw new Exception("Name, Email, and Contact Number are required.");
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Invalid email format.");
        }

        $stmt = $conn->prepare("SELECT COUNT(*) FROM SystemUser WHERE Email = :email AND UserID != :userId");
        $stmt->bindParam(':email', $email, PDO::PARAM_STR);
        $stmt->bindParam(':userId', $userIdToUpdate, PDO::PARAM_INT);
        $stmt->execute();
        if ($stmt->fetchColumn() > 0) {
            throw new Exception("Email already registered by another user.");
        }
        $stmt->closeCursor();

        $sql = "UPDATE SystemUser SET Name = :name, Email = :email, ContactNumber = :contactNumber";
        $params = [
            ':name' => $name,
            ':email' => $email,
            ':contactNumber' => $contactNumber,
            ':userId' => $userIdToUpdate
        ];

        if (!empty($newPassword)) {
            if ($newPassword !== $confirmPassword) {
                throw new Exception("New password and confirm password do not match.");
            }
            if (strlen($newPassword) < 8) {
                throw new Exception("Password must be at least 8 characters long.");
            }

            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            if ($hashedPassword === false) {
                throw new Exception("Failed to hash password.");
            }
            $sql .= ", Password = :password";
            $params[':password'] = $hashedPassword;
        }

        $sql .= " WHERE UserID = :userId";
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $stmt->closeCursor();

        $conn->commit();
        $statusMessage = '<div class="alert alert-success" role="alert">Profile updated successfully!</div>';

        $_SESSION['user_info_needs_refresh'] = true;
        header('Location: profile.php?status=success'); 
        exit();

    } catch (PDOException $e) {
        if ($conn) $conn->rollback();
        $statusMessage = '<div class="alert alert-danger" role="alert">Database error: ' . htmlspecialchars($e->getMessage()) . '</div>';
        error_log("Profile update PDO error: " . $e->getMessage());
    } catch (Exception $e) {
        if ($conn) $conn->rollback();
        $statusMessage = '<div class="alert alert-danger" role="alert">Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
        error_log("Profile update error: " . $e->getMessage());
    } finally {
        $conn = null;
    }
}

$userInfo = getUserById($patientUserID);

if (!$userInfo) {
    error_log("Critical error: User info not found for UserID: " . $patientUserID);
    header('Location: login.php?error=profile_load_failed');
    exit();
}

if (isset($_GET['status']) && $_GET['status'] === 'success') {
    $statusMessage = '<div class="alert alert-success" role="alert">Profile updated successfully!</div>';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Profile</title>
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
        }
        
        .profile-container {
            background-color: white;
            border-radius: 0.5rem;
            box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
            padding: 2rem;
            width: 100%;
            max-width: 500px;
            margin: 2rem auto;
        }
        
        .profile-header {
            color: var(--primary);
            text-align: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #eaecf4;
        }
        
        .profile-field {
            margin-bottom: 1.25rem;
        }
        
        .field-label {
            font-weight: 600;
            color: var(--secondary);
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
        }
        
        .field-value, .form-control-static {
            font-size: 1rem;
            padding: 0.75rem;
            background-color: var(--light);
            border-radius: 0.25rem;
            border: 1px solid #ddd;
            width: 100%;
            box-sizing: border-box;
        }

        .form-control {
            padding: 0.75rem;
            border-radius: 0.25rem;
            border: 1px solid #d1d3e2;
            width: 100%;
            box-sizing: border-box;
        }
        
        .password-value {
            font-family: monospace;
            letter-spacing: 0.2rem;
        }
        
        .btn-edit, .btn-save, .btn-cancel {
            font-weight: 600;
            padding: 0.5rem 1.5rem;
            border-radius: 0.25rem;
            cursor: pointer;
        }

        .btn-edit {
            background-color: var(--primary);
            color: white;
            border: none;
        }
        .btn-edit:hover {
            background-color: #2e59d9;
            color: white;
        }

        .btn-save {
            background-color: #1cc88a;
            color: white;
            border: none;
        }
        .btn-save:hover {
            background-color: #17a673;
        }

        .btn-cancel {
            background-color: #f6c23e;
            color: white;
            border: none;
        }
        .btn-cancel:hover {
            background-color: #f4b619;
        }
        
        .btn-return {
            color: var(--secondary);
            border: 1px solid #ddd;
            background-color: transparent;
        }
        
        .btn-return:hover {
            background-color: #f8f9fa;
        }
        
        .action-buttons {
            display: flex;
            justify-content: space-between;
            margin-top: 2rem;
        }

        .password-fields {
            margin-top: 1.5rem;
            border-top: 1px solid #eee;
            padding-top: 1.5rem;
        }

        .hidden {
            display: none;
        }
    </style>
</head>
<body>
    <div class="profile-container">
        <div class="profile-header">
            <h4><i class="fas fa-user-circle me-2"></i>Patient Profile</h4>
        </div>
        
        <?php echo $statusMessage; ?>

        <form method="POST" action="profile.php">
            <input type="hidden" name="action" value="update_profile">
            <input type="hidden" name="user_id" value="<?= htmlspecialchars($userInfo['UserID']) ?>">

            <div class="profile-field">
                <div class="field-label">Name</div>
                <div class="field-value-display" id="nameDisplay"><?= htmlspecialchars($userInfo['Name']) ?></div>
                <input type="text" class="form-control field-value-edit hidden" id="nameInput" name="name" value="<?= htmlspecialchars($userInfo['Name']) ?>">
            </div>
            
            <div class="profile-field">
                <div class="field-label">IC. Number</div>
                <div class="field-value-display" id="icNumberDisplay"><?= htmlspecialchars($userInfo['ICNumber']) ?></div>
                <input type="text" class="form-control field-value-edit hidden" id="icNumberInput" name="ic_number" value="<?= htmlspecialchars($userInfo['ICNumber']) ?>" readonly>
            </div>
            
            <div class="profile-field">
                <div class="field-label">Contact</div>
                <div class="field-value-display" id="contactNumberDisplay"><?= htmlspecialchars($userInfo['ContactNumber']) ?></div>
                <input type="tel" class="form-control field-value-edit hidden" id="contactNumberInput" name="contact_number" value="<?= htmlspecialchars($userInfo['ContactNumber']) ?>">
            </div>
            
            <div class="profile-field">
                <div class="field-label">Email</div>
                <div class="field-value-display" id="emailDisplay"><?= htmlspecialchars($userInfo['Email']) ?></div>
                <input type="email" class="form-control field-value-edit hidden" id="emailInput" name="email" value="<?= htmlspecialchars($userInfo['Email']) ?>">
            </div>
            
            <div class="profile-field">
                <div class="field-label">Password</div>
                <div class="field-value-display password-value" id="passwordDisplay">••••••••</div>
                <div class="password-fields field-value-edit hidden">
                    <div class="mb-3">
                        <label for="newPassword" class="form-label field-label">New Password</label>
                        <input type="password" class="form-control" id="newPassword" name="new_password" placeholder="Leave blank to keep current">
                    </div>
                    <div class="mb-3">
                        <label for="confirmPassword" class="form-label field-label">Confirm New Password</label>
                        <input type="password" class="form-control" id="confirmPassword" name="confirm_password" placeholder="Confirm new password">
                    </div>
                </div>
            </div>
            
            <div class="action-buttons">
                <button type="button" class="btn btn-return view-mode-btn">
                    <i class="fas fa-arrow-left me-2"></i>Return
                </button>
                <button type="button" class="btn btn-edit view-mode-btn" id="editProfileBtn">
                    <i class="fas fa-edit me-2"></i>Edit Profile
                </button>
                <button type="submit" class="btn btn-save edit-mode-btn hidden" id="saveProfileBtn">
                    <i class="fas fa-save me-2"></i>Save Changes
                </button>
                <button type="button" class="btn btn-cancel edit-mode-btn hidden" id="cancelEditBtn">
                    <i class="fas fa-times me-2"></i>Cancel
                </button>
            </div>
        </form>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        const editProfileBtn = document.getElementById('editProfileBtn');
        const saveProfileBtn = document.getElementById('saveProfileBtn');
        const cancelEditBtn = document.getElementById('cancelEditBtn');
        const returnBtn = document.querySelector('.btn-return');

        const displayFields = document.querySelectorAll('.field-value-display');
        const editFields = document.querySelectorAll('.field-value-edit');
        const viewModeBtns = document.querySelectorAll('.view-mode-btn');
        const editModeBtns = document.querySelectorAll('.edit-mode-btn');

        const nameInput = document.getElementById('nameInput');
        const emailInput = document.getElementById('emailInput');
        const contactNumberInput = document.getElementById('contactNumberInput');
        const newPasswordInput = document.getElementById('newPassword');
        const confirmPasswordInput = document.getElementById('confirmPassword');
        const symptomsTextarea = document.getElementById('symptoms');

        let initialName, initialEmail, initialContactNumber;

        function toggleEditMode(isEditing) {
            displayFields.forEach(field => field.classList.toggle('hidden', isEditing));
            editFields.forEach(field => field.classList.toggle('hidden', !isEditing));
            viewModeBtns.forEach(btn => btn.classList.toggle('hidden', isEditing));
            editModeBtns.forEach(btn => btn.classList.toggle('hidden', !isEditing));

            if (isEditing) {
                initialName = nameInput.value;
                initialEmail = emailInput.value;
                initialContactNumber = contactNumberInput.value;
            } else {
                newPasswordInput.value = '';
                confirmPasswordInput.value = '';
            }
        }

        editProfileBtn.addEventListener('click', () => toggleEditMode(true));

        cancelEditBtn.addEventListener('click', () => {
            nameInput.value = initialName;
            emailInput.value = initialEmail;
            contactNumberInput.value = initialContactNumber;
            toggleEditMode(false);
        });

        saveProfileBtn.addEventListener('click', (event) => {
            // Basic client-side validation
            if (!nameInput.value || !emailInput.value || !contactNumberInput.value) {
                alert('Name, Email, and Contact Number cannot be empty.');
                event.preventDefault();
                return;
            }

            if (!/^\S+@\S+\.\S+$/.test(emailInput.value)) {
                alert('Please enter a valid email address.');
                event.preventDefault();
                return;
            }

            if (newPasswordInput.value) {
                if (newPasswordInput.value.length < 8) {
                    alert('New password must be at least 8 characters long.');
                    event.preventDefault();
                    return;
                }
                if (newPasswordInput.value !== confirmPasswordInput.value) {
                    alert('New password and confirm password do not match.');
                    event.preventDefault();
                    return;
                }
            }
        });

        returnBtn.addEventListener('click', function() {
            window.history.back();
        });
    </script>
</body>
</html>
