<?php
require 'includes/db.php';

try {
    // 1. Add columns to payment_requests
    $pdo->exec("ALTER TABLE payment_requests ADD COLUMN payment_method VARCHAR(50) DEFAULT 'Bank Transfer' AFTER status;");
    $pdo->exec("ALTER TABLE payment_requests ADD COLUMN payment_reference VARCHAR(100) NULL AFTER payment_method;");
    $pdo->exec("ALTER TABLE payment_requests ADD COLUMN cost_center VARCHAR(100) DEFAULT 'General' AFTER payment_reference;");

    // 2. Create payment_request_invoices table
    $pdo->exec("CREATE TABLE IF NOT EXISTS payment_request_invoices (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        payment_request_id INT UNSIGNED NOT NULL,
        invoice_no VARCHAR(50) NOT NULL,
        vendor VARCHAR(100) NOT NULL,
        amount DECIMAL(15,2) NOT NULL,
        invoice_date DATE NOT NULL,
        file_path VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (payment_request_id) REFERENCES payment_requests(id) ON DELETE CASCADE
    );");

    echo "Migration successful!\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
}
