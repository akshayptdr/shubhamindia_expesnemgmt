<?php
// LOCAL
// $host = 'localhost';
// $db = 'expensemgmt';
// $user = 'root';
// $pass = 'root';

// HOSTINGER LIVE
$host = 'srv1826.hstgr.io';
$db = 'u923266720_shubhamerp';
$user = 'u923266720_shubhamerp';
$pass = 'Shubhaminc.2018';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => true,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    if ($e->getCode() == 1049) {
        // Database not found, maybe setup hasn't been run
        die("Database 'expensemgmt' not found. Please run setup.php first.");
    }
    throw new \PDOException($e->getMessage(), (int) $e->getCode());
}