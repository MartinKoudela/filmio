<?php

// Load environment variables from .env file
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    $envVars = parse_ini_file($envFile);
    if ($envVars) {
        foreach ($envVars as $key => $value) {
            $_ENV[$key] = $value;
        }
    }
}

$db_host = $_ENV['DB_HOST'] ?? 'localhost';
$db_user = $_ENV['DB_USER'] ?? 'root';
$db_password = $_ENV['DB_PASSWORD'] ?? '';
$db_db = $_ENV['DB_NAME'] ?? 'filmio';

$conn = new mysqli($db_host, $db_user, $db_password, $db_db);
if ($conn->connect_errno) {
    http_response_code(500);
    die("Database connection failed: " . $conn->connect_error);
}

// Session timeout configuration (30 minutes)
define('SESSION_TIMEOUT', 1800);

// Function to check session timeout
function checkSessionTimeout() {
    if (isset($_SESSION['last_activity'])) {
        if (time() - $_SESSION['last_activity'] > SESSION_TIMEOUT) {
            session_unset();
            session_destroy();
            header('Location: index.php?timeout=1');
            exit;
        }
    }
    $_SESSION['last_activity'] = time();
}
