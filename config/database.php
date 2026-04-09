<?php
// Check for environment variable first - this is the most reliable method
$environment = getenv('APP_ENV') ?: getenv('ENVIRONMENT') ?: 'production';

// Force production detection based on environment variable
if ($environment === 'local' || $environment === 'development') {
    // Local PostgreSQL configuration
    $host = 'localhost';
    $dbname = 'isonga_portal';
    $username = 'postgres';
    $password = 'numugisha';
    $port = '5432';
    $ssl_mode = '';
} else {
    // Neon (Production) PostgreSQL configuration
    $host = 'ep-quiet-tree-amk3wgjr-pooler.c-5.us-east-1.aws.neon.tech';
    $dbname = 'neondb';
    $username = 'neondb_owner';
    $password = 'npg_q4ystuBrRw5F';
    $port = '5432';
    $ssl_mode = ';sslmode=require';
}

try {
    $dsn = "pgsql:host=$host;port=$port;dbname=$dbname$ssl_mode";
    $pdo = new PDO($dsn, $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("SET NAMES 'UTF8'");
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}
?>



