<?php
require_once 'includes/db.php';

try {
    // Add approved_at column
    $pdo->exec("ALTER TABLE payment_requests ADD COLUMN approved_at DATETIME NULL AFTER status");
    echo "Added approved_at column successfully.\n";

    // Add paid_at column
    $pdo->exec("ALTER TABLE payment_requests ADD COLUMN paid_at DATETIME NULL AFTER approved_at");
    echo "Added paid_at column successfully.\n";

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>