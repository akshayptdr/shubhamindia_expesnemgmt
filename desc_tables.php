<?php
require 'includes/db.php';
$tables = ['projects', 'payment_requests', 'payment_request_invoices'];
foreach ($tables as $table) {
    echo "--- $table ---\n";
    $stmt = $pdo->query("DESCRIBE $table");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "{$row['Field']} - {$row['Type']}\n";
    }
    echo "\n";
}
