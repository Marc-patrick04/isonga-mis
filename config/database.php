<?php
// Detect if running locally or on production
$is_local = (
    $_SERVER['SERVER_NAME'] === 'localhost' || 
    $_SERVER['SERVER_ADDR'] === '127.0.0.1' ||
    strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') !== false ||
    getenv('ENVIRONMENT') === 'local' // Optional: set this in your local environment
);

if ($is_local) {
    // Local PostgreSQL configuration
    $host = 'localhost';
    $dbname = 'isonga_portal';
    $username = 'postgres';
    $password = 'numugisha';
    $port = '5432';
    $ssl_mode = ''; // No SSL needed for local
} else {
    // Neon (Production) PostgreSQL configuration
    $host = 'ep-quiet-tree-amk3wgjr-pooler.c-5.us-east-1.aws.neon.tech';
    $dbname = 'neondb';
    $username = 'neondb_owner';
    $password = 'npg_q4ystuBrRw5F';
    $port = '5432';
    $ssl_mode = ';sslmode=require'; // SSL required for Neon
}

try {
    // Build connection string based on environment
    $dsn = "pgsql:host=$host;port=$port;dbname=$dbname$ssl_mode";
    $pdo = new PDO($dsn, $username, $password);
    
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("SET NAMES 'UTF8'");
    
    // Optional: Add connection success message for debugging (remove in production)
    // error_log("Database connection successful - Environment: " . ($is_local ? "Local" : "Production"));
    
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}
?>