# Doctor Appointment Booking System

A web-based platform for managing medical appointments between patients, doctors, and administrators.

## System Requirements

- XAMPP (Apache, MySQL, PHP)
- PHP 7.4+
- MySQL 5.7+
- Web browser (Chrome, Firefox, Edge)

## Installation Guide

### 1. XAMPP Setup
1. Download and install XAMPP
2. Start Apache and MySQL services from the XAMPP control panel

### 2. Database Setup
1. Create a new MySQL database named hsp
2. Import the provided SQL file (database schema) using phpMyAdmin

### 3. Application Setup
1. Clone this repository or copy files to your XAMPP web directory

2. Configure the database connection by editing config.php:

```php
<?php
// Database Configuration
define('DB_HOST', 'localhost');      // Database host
define('DB_NAME', 'hsp');            // Database name
define('DB_USER', 'root');           // Database username
define('DB_PASS', 'usbw');           // Database password
define('BASE_URL', 'http://localhost/hsp');  // Base URL of your application

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
     error_log("Database connection failed: " . $e->getMessage());
     die("We're experiencing technical difficulties. Please try again later.");
 }
}
?>
```
Access the application in your browser base on BASE_URL.
