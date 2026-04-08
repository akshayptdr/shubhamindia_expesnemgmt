<?php
require_once 'includes/db.php';
$stmt = $pdo->query("DESCRIBE payment_request_invoices");
$cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $col) {
    echo $col['Field'] . " - " . $col['Type'] . "\n";
}
?>