<?php
require 'includes/db.php';

try {
    $pdo->exec("ALTER TABLE employees ADD COLUMN payment_request_limit DECIMAL(15, 2) DEFAULT 0.00 AFTER avatar");
    echo "Migration successful: Added payment_request_limit column to employees.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
}
?>