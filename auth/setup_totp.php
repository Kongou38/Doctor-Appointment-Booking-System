<?php
session_start();
require_once '../config.php';
require_once 'totp_library.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['awaiting_totp_setup'])) {
    header('Location: login.php');
    exit();
}

$userId = $_SESSION['user_id'];
$email = $_SESSION['user_email'];
$role = $_SESSION['user_role'] ?? 'user';

if (!isset($_SESSION['temp_totp_secret'])) {
    $_SESSION['temp_totp_secret'] = generateTotpSecret();
}
$secret = $_SESSION['temp_totp_secret'];

$issuer = 'DoctorAppoinmentBookingSystem';
$totpUrl = "otpauth://totp/{$issuer}:{$email}?secret={$secret}&issuer={$issuer}";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = $_POST['totp_code'] ?? '';

    if (verifyTotpCode($secret, $code)) {
        $db = getDBConnection();
        $stmt = $db->prepare("INSERT INTO totp (UserID, SecretKey) VALUES (?, ?)");
        $stmt->execute([$userId, $secret]);

        unset($_SESSION['awaiting_totp_setup'], $_SESSION['temp_totp_secret']);

        header('Location: ' . BASE_URL . ($_SESSION['user_role'] === 'doctor' ? '/doctor/dashboard.php' : '/patient/dashboard.php'));
        exit();
    } else {
        $error = "Invalid verification code. Please try again.";
    }
}

$qrCodeUrl = "https://quickchart.io/qr?text=" . urlencode($totpUrl);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Set Up Two-Factor Authentication</title>
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
            max-width: 480px;
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
        
        .qr-container {
            background: white;
            padding: 20px;
            border-radius: var(--border-radius);
            border: 1px solid #e0e0e0;
            margin: 0 auto 25px;
            max-width: 220px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
        }
        
        .qr-container img {
            width: 100%;
            height: auto;
            display: block;
        }
        
        .secret-box {
            background: var(--light-color);
            padding: 15px;
            border-radius: var(--border-radius);
            font-family: 'Courier New', monospace;
            font-size: 16px;
            text-align: center;
            margin-bottom: 25px;
            word-break: break-all;
            position: relative;
        }
        
        .secret-box .copy-btn {
            position: absolute;
            right: 10px;
            top: 10px;
            background: rgba(255, 255, 255, 0.7);
            border: none;
            border-radius: 4px;
            padding: 2px 8px;
            cursor: pointer;
        }
        
        .step {
            display: flex;
            align-items: flex-start;
            margin-bottom: 20px;
        }
        
        .step-number {
            background: var(--primary-color);
            color: white;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            margin-right: 15px;
            flex-shrink: 0;
        }
        
        .step-content {
            flex: 1;
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
        }
    </style>
</head>
<body>
<div class="auth-container">
    <div class="auth-header">
        <h3><i class="fas fa-shield-alt me-2"></i>Two-Factor Setup</h3>
        <p class="mb-0">Secure your account with 2FA</p>
    </div>
    
    <div class="auth-body">
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <div class="step">
            <div class="step-number">1</div>
            <div class="step-content">
                <h5>Install Authenticator App</h5>
                <p class="text-muted">Download Google Authenticator or Authy from your app store.</p>
            </div>
        </div>
        
        <div class="step">
            <div class="step-number">2</div>
            <div class="step-content">
                <h5>Scan QR Code</h5>
                <p class="text-muted">Open the app and scan this QR code:</p>
                <div class="qr-container">
                    <img src="<?= htmlspecialchars($qrCodeUrl) ?>" alt="TOTP QR Code">
                </div>
            </div>
        </div>
        
        <div class="step">
            <div class="step-number">3</div>
            <div class="step-content">
                <h5>Or Enter Secret Key</h5>
                <p class="text-muted">If you can't scan, enter this key manually:</p>
                <div class="secret-box">
                    <?= htmlspecialchars($secret) ?>
                    <button class="copy-btn" onclick="copyToClipboard('<?= htmlspecialchars($secret) ?>')">
                        <i class="far fa-copy"></i>
                    </button>
                </div>
            </div>
        </div>
        
        <div class="step">
            <div class="step-number">4</div>
            <div class="step-content">
                <h5>Verify Code</h5>
                <p class="text-muted">Enter the 6-digit code from your app:</p>
                <form method="post">
                    <div class="mb-3">
                        <input type="text" 
                               class="form-control totp-input" 
                               name="totp_code" 
                               id="totp_code" 
                               placeholder="------" 
                               required
                               maxlength="6"
                               pattern="\d{6}"
                               inputmode="numeric"
                               autocomplete="one-time-code">
                    </div>
                    <button type="submit" class="btn btn-primary btn-verify w-100">
                        <i class="fas fa-check-circle me-2"></i>Verify & Continue
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
    // Auto-focus and move between inputs
    document.getElementById('totp_code').focus();
    
    // Copy to clipboard function
    function copyToClipboard(text) {
        navigator.clipboard.writeText(text).then(function() {
            alert('Secret key copied to clipboard!');
        }, function() {
            alert('Failed to copy text');
        });
    }
    
    // Auto-advance input
    document.getElementById('totp_code').addEventListener('input', function(e) {
        if (this.value.length === 6) {
            this.form.submit();
        }
    });
    
    // Prevent non-numeric input
    document.getElementById('totp_code').addEventListener('keydown', function(e) {
        if (!/[0-9]|Backspace|ArrowLeft|ArrowRight|Delete/.test(e.key)) {
            e.preventDefault();
        }
    });
</script>
</body>
</html>