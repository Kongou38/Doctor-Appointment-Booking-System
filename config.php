<?php
define('DB_HOST', 'localhost');
define('DB_NAME', 'hsp');
define('DB_USER', 'root');
define('DB_PASS', 'usbw');
define('BASE_URL', 'http://localhost:8081/hsp');

function getDBConnection() {
    try {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        
        return new PDO($dsn, DB_USER, DB_PASS, $options);
    } catch (PDOException $e) {
        // Log the error (don't expose to user)
        error_log("Database connection failed: " . $e->getMessage());
        
        // Display generic error message
        die("We're experiencing technical difficulties. Please try again later.");
    }
}
?>