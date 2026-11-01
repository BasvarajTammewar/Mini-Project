<?php
/**
 * CareerAct - Database Connection Configuration
 * Uses PHP PDO with MySQL / MariaDB
 */

declare(strict_types=1);

// Configuration parameters
$dbHost = 'localhost';
$dbPort = '3306';
$dbName = 'carrearact2'; // Default database for CareerAct AI assessment & platform
$dbUser = 'root';
$dbPass = ''; // Default XAMPP password is empty
$dbCharset = 'utf8mb4';

$dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset={$dbCharset}";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, $options);
} catch (PDOException $e) {
    // Fallback to careeract if carrearact2 is not found
    try {
        $dsnFallback = "mysql:host={$dbHost};port={$dbPort};dbname=careeract;charset={$dbCharset}";
        $pdo = new PDO($dsnFallback, $dbUser, $dbPass, $options);
    } catch (PDOException $e2) {
        // Log internal error safely for developers
        error_log('Database Connection Error: ' . $e->getMessage());

        // Do not reveal credentials or sensitive info to end users
        http_response_code(500);
        die('
            <div style="font-family: Arial, sans-serif; max-width: 540px; margin: 60px auto; padding: 24px; border: 1px solid #E4EAF2; border-radius: 12px; background: #FFF; box-shadow: 0 4px 12px rgba(0,0,0,0.05); text-align: center;">
                <h2 style="color: #163A70; margin-bottom: 12px;">Service Temporarily Unavailable</h2>
                <p style="color: #667085; font-size: 15px; line-height: 1.5;">Unable to securely connect to MySQL database <code>carrearact2</code> / <code>careeract</code>. Please ensure XAMPP MySQL is running.</p>
            </div>
        ');
    }
}

/**
 * Returns the active PDO instance
 */
function getDbConnection(): PDO {
    global $pdo;
    return $pdo;
}
