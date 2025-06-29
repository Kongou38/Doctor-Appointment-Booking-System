<?php
require_once '../config.php';
session_start();


$errors = [];
$name = $email = $icNumber = $contactNumber = '';
$role = 'patient';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {s
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirmPassword'] ?? '';
    $icNumber = trim($_POST['icNumber'] ?? '');
    $contactNumber = trim($_POST['contactNumber'] ?? '');
    $role = $_POST['role'] ?? 'patient';

    if (empty($name)) {
        $errors[] = 'Full name is required';
    } elseif (strlen($name) > 100) {
        $errors[] = 'Name must be less than 100 characters';
    }

    if (empty($email)) {
        $errors[] = 'Email is required';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid email format';
    } elseif (strlen($email) > 100) {
        $errors[] = 'Email must be less than 100 characters';
    }

    if (empty($password)) {
        $errors[] = 'Password is required';
    } elseif (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters';
    }

    if ($password !== $confirmPassword) {
        $errors[] = 'Passwords do not match';
    }

    if (empty($icNumber)) {
        $errors[] = 'IC number is required';
    } elseif (strlen($icNumber) > 20) {
        $errors[] = 'IC number must be less than 20 characters';
    }

    if (empty($contactNumber)) {
        $errors[] = 'Contact number is required';
    } elseif (strlen($contactNumber) > 20) {
        $errors[] = 'Contact number must be less than 20 characters';
    }

    if (!in_array($role, ['patient', 'doctor', 'admin'])) {
        $errors[] = 'Invalid role selected';
    }

    if (empty($errors)) {
        try {
            $db = getDBConnection();
            
            $stmt = $db->prepare("SELECT UserID FROM SystemUser WHERE Email = ? OR ICNumber = ?");
            $stmt->execute([$email, $icNumber]);
            
            if ($stmt->rowCount() > 0) {
                $errors[] = 'Email or IC number already registered';
            } else {
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                
                $db->beginTransaction();
                
                try {
                    $stmt = $db->prepare("
                        INSERT INTO SystemUser 
                        (Name, Email, Password, ICNumber, ContactNumber) 
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$name, $email, $hashedPassword, $icNumber, $contactNumber]);
                    $userID = $db->lastInsertId();
                    
                    if ($role === 'doctor') {
                        $stmt = $db->prepare("INSERT INTO Doctor (UserID, Status) VALUES (?, 'inactive')");
                        $stmt->execute([$userID]);
                    } elseif ($role === 'admin') {
                        $stmt = $db->prepare("INSERT INTO Admin (UserID) VALUES (?)");
                        $stmt->execute([$userID]);
                    }
                    
                    $db->commit();
                    
                    $_SESSION['registration_success'] = true;
                    $_SESSION['registered_email'] = $email;
                    
                    header('Location: registration_success.php');
                    exit();
                    
                } catch (PDOException $e) {
                    $db->rollBack();
                    error_log("Registration error: " . $e->getMessage());
                    $errors[] = 'Registration failed. Please try again.';
                }
            }
        } catch (PDOException $e) {
            error_log("Database error: " . $e->getMessage());
            $errors[] = 'Database error occurred. Please try again.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Healthcare System Registration</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
            min-height: 100vh;
        }
        
        .registration-container {
            background-color: white;
            border-radius: 10px;
            box-shadow: 0 0 20px rgba(0, 0, 0, 0.1);
            padding: 30px;
            width: 100%;
            max-width: 600px;
        }
        
        .registration-header {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .registration-header h2 {
            color: #333;
            font-weight: 600;
        }
        
        .form-control {
            height: 45px;
            border-radius: 5px;
            border: 1px solid #ddd;
            padding-left: 15px;
        }
        
        .form-control:focus {
            box-shadow: none;
            border-color: #0d6efd;
        }
        
        .btn-register {
            background-color: #0d6efd;
            color: white;
            border: none;
            height: 45px;
            border-radius: 5px;
            font-weight: 600;
            width: 100%;
            margin-top: 20px;
        }
        
        .btn-register:hover {
            background-color: #0b5ed7;
        }
        
        .form-label {
            font-weight: 500;
            margin-bottom: 5px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .role-selection {
            display: flex;
            gap: 20px;
            margin-top: 15px;
        }
        
        .form-check-input {
            width: 18px;
            height: 18px;
            margin-top: 0.2em;
        }
        
        .form-check-label {
            margin-left: 5px;
        }
        
        .error-message {
            color: #dc3545;
            font-size: 0.875em;
            margin-top: 5px;
        }
        
        .alert-danger {
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="container d-flex justify-content-center align-items-center min-vh-100">
        <div class="registration-container">
            <div class="registration-header">
                <h2>Registration</h2>
                <p class="text-muted">Create your account</p>
            </div>
            
            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <ul class="mb-0">
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            
            <form id="registrationForm" method="POST" action="register.php">
                <div class="form-group">
                    <label for="name" class="form-label">Full Name</label>
                    <input type="text" class="form-control" id="name" name="name" placeholder="Enter your full name" required>
                </div>
                
                <div class="form-group">
                    <label for="email" class="form-label">Email</label>
                    <input type="email" class="form-control" id="email" name="email" placeholder="Enter your email" required>
                </div>
                
                <div class="form-group">
                    <label for="password" class="form-label">Password</label>
                    <input type="password" class="form-control" id="password" name="password" placeholder="Enter your password (min 8 characters)" required minlength="8">
                </div>
                
                <div class="form-group">
                    <label for="confirmPassword" class="form-label">Confirm Password</label>
                    <input type="password" class="form-control" id="confirmPassword" name="confirmPassword" placeholder="Confirm your password" required>
                </div>
                
                <div class="form-group">
                    <label for="icNumber" class="form-label">IC Number</label>
                    <input type="text" class="form-control" id="icNumber" name="icNumber" placeholder="Enter your IC number" required>
                </div>
                
                <div class="form-group">
                    <label for="contactNumber" class="form-label">Contact Number</label>
                    <input type="tel" class="form-control" id="contactNumber" name="contactNumber" placeholder="Enter your phone number" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Register as:</label>
                    <div class="role-selection">
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="role" id="patientRole" value="patient" checked>
                            <label class="form-check-label" for="patientRole">Patient</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="role" id="doctorRole" value="doctor">
                            <label class="form-check-label" for="doctorRole">Doctor</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="role" id="adminRole" value="admin">
                            <label class="form-check-label" for="adminRole">Admin</label>
                        </div>
                    </div>
                </div>
                <button type="submit" class="btn btn-register">Register</button>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        document.getElementById('registrationForm').addEventListener('submit', function(e) {
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirmPassword').value;
            const termsChecked = document.getElementById('termsCheck').checked;
            
            if (password !== confirmPassword) {
                alert('Passwords do not match!');
                e.preventDefault();
                return;
            }
        
        });
    </script>
</body>
</html>