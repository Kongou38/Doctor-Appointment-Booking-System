<?php
session_start();
require_once 'check_totp.php';
require_once '../config.php';

$email = '';
$error = '';

if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    if (isset($_SESSION['user_role'])) {
        switch ($_SESSION['user_role']) {
            case 'doctor':
                header('Location: ' . BASE_URL . '/doctor/dashboard.php');
                break;
            case 'admin':
                header('Location: ' . BASE_URL . '/admin/dashboard.php');
                break;
            case 'patient':
                header('Location: ' . BASE_URL . '/patient/dashboard.php');
                break;
            default:
                header('Location: ' . BASE_URL . '/auth/logout.php');
                break;
        }
        exit();
    } else {
        header('Location: ' . BASE_URL . '/auth/logout.php');
        exit();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error = 'Please enter both email and password';
    } else {
        try {
            $db = getDBConnection();
            
            $stmt = $db->prepare("
                SELECT u.UserID, u.Name, u.Email, u.Password, 
                       d.DoctorID, a.AdminID
                FROM systemuser u
                LEFT JOIN doctor d ON u.UserID = d.UserID
                LEFT JOIN admin a ON u.UserID = a.UserID
                WHERE u.Email = ?
            ");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['Password'])) {
                $_SESSION['user_id'] = $user['UserID'];
                $_SESSION['user_email'] = $user['Email'];
                $_SESSION['user_name'] = $user['Name'];
                $_SESSION['logged_in'] = true;

                if (!empty($user['DoctorID'])) {
                    $_SESSION['user_role'] = 'doctor';
                    $_SESSION['doctor_id'] = $user['DoctorID'];
                } elseif (!empty($user['AdminID'])) {
                    $_SESSION['user_role'] = 'admin';
                    $_SESSION['admin_id'] = $user['AdminID'];
                } else {
                    $_SESSION['user_role'] = 'patient';
                }

                $totpCheck = $db->prepare("SELECT TotpID FROM totp WHERE UserID = ?");
                $totpCheck->execute([$user['UserID']]);
                $totpExists = $totpCheck->fetch();
                
                if ($_SESSION['user_role'] === 'doctor' || $_SESSION['user_role'] === 'patient') {
                    if (!$totpExists) {
                        $_SESSION['awaiting_totp_setup'] = true;
                        header('Location: setup_totp.php');
                        exit();
                    } else {
                        $_SESSION['awaiting_totp_verification'] = true;
                        header('Location: verify_totp.php');
                        exit();
                    }
                }

                switch ($_SESSION['user_role']) {
                    case 'doctor':
                        header('Location: ../doctor/dashboard.php');
                        break;
                    case 'admin':
                        header('Location: ../admin/dashboard.php');
                        break;
                    default:
                        header('Location: ../patient/dashboard.php');
                        break;
                }
                exit();
            } else {
                $error = 'Invalid email or password';
            }
        } catch (PDOException $e) {
            error_log("Login error: " . $e->getMessage());
            $error = 'Login failed. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Healthcare System Login</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
            min-height: 100vh;
        }
        .centered-container {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 20px;
        }
        .login-container {
            background-color: white;
            border-radius: 10px;
            box-shadow: 0 0 20px rgba(0, 0, 0, 0.1);
            padding: 30px;
            width: 100%;
            max-width: 400px;
        }
        .login-header {
            text-align: center;
            margin-bottom: 30px;
        }
        .login-header h2 {
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
        .btn-login {
            background-color: #0d6efd;
            color: white;
            border: none;
            height: 45px;
            border-radius: 5px;
            font-weight: 600;
            width: 100%;
            margin-top: 10px;
        }
        .btn-login:hover {
            background-color: #0b5ed7;
        }
        .form-label {
            font-weight: 500;
            margin-bottom: 5px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .alert-danger {
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
<div class="centered-container">
    <div class="login-container">
        <div class="login-header">
            <h2>Login</h2>
            <p class="text-muted">Please sign in to continue</p>
        </div>
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        <form id="loginForm" method="POST" action="login.php">
            <div class="form-group">
                <label for="email" class="form-label">Email</label>
                <input type="email" class="form-control" id="email" name="email"
                       value="<?php echo htmlspecialchars($email); ?>"
                       placeholder="Enter your email" required>
            </div>
            <div class="form-group">
                <label for="password" class="form-label">Password</label>
                <input type="password" class="form-control" id="password" name="password"
                       placeholder="Enter your password" required>
            </div>
            <button type="submit" class="btn btn-login">Sign In</button>
        </form>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    document.getElementById('loginForm').addEventListener('submit', function (e) {
        const email = document.getElementById('email').value;
        const password = document.getElementById('password').value;
        if (!email || !password) {
            alert('Please enter both email and password');
            e.preventDefault();
        }
    });
</script>
</body>
</html>