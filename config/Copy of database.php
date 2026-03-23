<?php
$host = 'localhost';
$dbname = 'isonga_portal';
// $username = 'root';
$username = 'postgres';
$password = 'numugisha';
$port = '5432'; 

try {
    $pdo = new PDO("pgsql:host=$host;port=$port;dbname=$dbname;",$username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
     $pdo->exec("SET NAMES 'UTF8'");
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}
?>