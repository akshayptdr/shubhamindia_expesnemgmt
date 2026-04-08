<?php
require 'includes/db.php';

try {
    $pdo->exec("ALTER TABLE payment_request_invoices MODIFY invoice_no VARCHAR(50) NULL");
    $pdo->exec("ALTER TABLE payment_request_invoices MODIFY vendor VARCHAR(100) NULL");
    $pdo->exec("ALTER TABLE payment_request_invoices MODIFY invoice_date DATE NULL");
    echo "Migration successful: Invoice fields are now optional in the database.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
}
?>