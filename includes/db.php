<?php
$host = 'localhost';
$db = 'expensemgmt';
$user = 'root';
$pass = 'root';
$charset = 'utf8mb4';


// $host = '127.0.0.1:3306';
// $db = 'u262579928_shubhamerp';
// $user = 'u262579928_shubhamerp';
// $pass = 'Shubhamerp1';

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