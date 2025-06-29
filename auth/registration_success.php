<?php
session_start();

if (!isset($_SESSION['registration_success']) || !$_SESSION['registration_success']) {
    header('Location: register.php');
    exit();
}

unset($_SESSION['registration_success']);
$registered_email = $_SESSION['registered_email'] ?? '';
unset($_SESSION['registered_email']);
$is_doctor = $_SESSION['is_doctor'] ?? false;
unset($_SESSION['is_doctor']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registration Successful</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(to right, #e0f7fa, #e0f2f1);
            min-height: 100vh;
        }

        .success-container {
            background-color: white;
            padding: 40px 30px;
            border-radius: 15px;
            box-shadow: 0 0 30px rgba(0, 0, 0, 0.1);
            max-width: 500px;
            width: 100%;
            text-align: center;
        }

        .success-icon {
            width: 80px;
            height: 80px;
            margin: 0 auto 20px;
        }

        .success-icon svg {
            width: 100%;
            height: 100%;
            fill: #28a745;
        }

        h2 {
            color: #28a745;
            font-weight: 700;
        }

        .lead {
            font-size: 1.1rem;
        }

        @media (max-width: 576px) {
            .success-container {
                padding: 30px 20px;
            }

            .lead {
                font-size: 1rem;
            }

            h2 {
                font-size: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="container d-flex justify-content-center align-items-center min-vh-100">
        <div class="success-container">
            <div class="success-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
                    <path d="M9 16.2l-3.5-3.5-1.4 1.4L9 19 20 8l-1.4-1.4z"/>
                </svg>
            </div>
            <h2>Registration Successful!</h2>
            <p class="lead">Your account <strong><?php echo htmlspecialchars($registered_email); ?></strong> has been created.</p>

            <div class="d-grid gap-2 mt-4">
                <a href="login.php" class="btn btn-success btn-lg">Proceed to Login</a>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
