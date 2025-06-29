<?php
require_once '../config.php';
if(session_id() == '' || !isset($_SESSION) || session_status() === PHP_SESSION_NONE) {
    session_start();
}

function checkTOTPVerification() {
    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        header('Location: ' . BASE_URL . '/auth/login.php');
        exit();
    }

    if (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin') {
        return true;
    }

    if (isset($_SESSION['awaiting_totp_setup'])) {
        header('Location: ' . BASE_URL . '/auth/setup_totp.php');
        exit();
    }

    if (isset($_SESSION['awaiting_totp_verification'])) {
        header('Location: ' . BASE_URL . '/auth/verify_totp.php');
        exit();
    }

    return true;
}

if (basename(__FILE__) == basename($_SERVER['SCRIPT_FILENAME'])) {
    checkTOTPVerification();
}