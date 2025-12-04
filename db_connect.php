<?php

$db_host = 'localhost';
$db_user = 'root';
$db_password = 'root';
$db_db = 'filmio';

$conn = new mysqli($db_host, $db_user, $db_password, $db_db);
if ($conn->connect_errno) {
    http_response_code(500);
    die("Database connection failed: " . $conn->connect_error);
}
