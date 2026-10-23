<?php
require 'includes/db.php';

try {
    $check = $pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment_requests' AND COLUMN_NAME = 'paid_by'");
    if ($check->rowCount() == 0) {
        $pdo->exec("ALTER TABLE payment_requests ADD COLUMN paid_by INT NULL AFTER paid_at");
        echo "Migration successful: paid_by column added to payment_requests.\n";
    } else {
        echo "Column paid_by already exists.\n";
    }
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
}
?>
