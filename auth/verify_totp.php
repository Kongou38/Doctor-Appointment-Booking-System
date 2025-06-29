<?php
session_start();
require_once '../config.php';
require_once 'totp_library.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['awaiting_totp_verification'])) {
    header('Location: login.php');
    exit();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = $_POST['totp_code'] ?? '';
    
    $db = getDBConnection();
    $stmt = $db->prepare("SELECT SecretKey FROM totp WHERE UserID = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $totp = $stmt->fetch();
    
    if ($totp && verifyTotpCode($totp['SecretKey'], $code)) {
        unset($_SESSION['awaiting_totp_verification']);
        
        switch ($_SESSION['user_role']) {
            case 'doctor':
                header('Location:' . BASE_URL . '/doctor/dashboard.php');
                break;
            case 'admin':
                header('Location:' . BASE_URL . '/admin/dashboard.php');
                break;
            default:
                header('Location:' . BASE_URL . '/patient/dashboard.php');
                break;
        }
        exit();
    } else {
        $error = "Invalid verification code. Please try again.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Two-Factor Verification</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #4361ee;
            --secondary-color: #3f37c9;
            --light-color: #f8f9fa;
            --dark-color: #212529;
            --success-color: #4cc9f0;
            --border-radius: 12px;
            --box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
        }
        
        body {
            background-color: #f5f7fb;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            padding: 20px;
        }
        
        .auth-container {
            max-width: 400px;
            width: 100%;
            margin: 0 auto;
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            overflow: hidden;
        }
        
        .auth-header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 25px;
            text-align: center;
        }
        
        .auth-body {
            padding: 30px;
        }
        
        .auth-icon {
            font-size: 48px;
            color: var(--primary-color);
            margin-bottom: 20px;
        }
        
        .totp-input {
            letter-spacing: 10px;
            font-size: 24px;
            text-align: center;
            padding: 15px;
            height: 60px;
        }
        
        .btn-verify {
            background: var(--primary-color);
            border: none;
            padding: 12px;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .btn-verify:hover {
            background: var(--secondary-color);
            transform: translateY(-2px);
        }
        
        @media (max-width: 576px) {
            .auth-body {
                padding: 20px;
            }
            
            .totp-input {
                font-size: 20px;
                padding: 12px;
                height: 50px;
            }
            
            .auth-icon {
                font-size: 36px;
            }
        }
    </style>
</head>
<body>
<div class="auth-container">
    <div class="auth-header">
        <h3><i class="fas fa-shield-alt me-2"></i>Two-Factor Verification</h3>
    </div>
    
    <div class="auth-body text-center">
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <div class="auth-icon">
            <i class="fas fa-mobile-alt"></i>
        </div>
        
        <h4>Enter Verification Code</h4>
        <p class="text-muted mb-4">Please enter the 6-digit code from your authenticator app</p>
        
        <form method="post">
            <div class="mb-3">
                <input type="text" 
                       class="form-control totp-input mx-auto" 
                       name="totp_code" 
                       id="totp_code" 
                       placeholder="------" 
                       required
                       maxlength="6"
                       pattern="\d{6}"
                       inputmode="numeric"
                       autocomplete="one-time-code"
                       style="max-width: 250px;">
            </div>
            <button type="submit" class="btn btn-primary btn-verify w-100">
                <i class="fas fa-unlock-alt me-2"></i>Verify & Continue
            </button>
        </form>
        
        <div class="mt-4 text-muted small">
            <i class="fas fa-info-circle me-1"></i> Open your authenticator app to get your verification code
        </div>
    </div>
</div>

<script>
    document.getElementById('totp_code').focus();
    
    document.getElementById('totp_code').addEventListener('input', function(e) {
        if (this.value.length === 6) {
            this.form.submit();
        }
    });
    
    document.getElementById('totp_code').addEventListener('keydown', function(e) {
        if (!/[0-9]|Backspace|ArrowLeft|ArrowRight|Delete/.test(e.key)) {
            e.preventDefault();
        }
    });
</script>
</body>
</html>