<?php
require 'includes/db.php';
$stmt = $pdo->query('DESCRIBE payment_request_invoices');
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo $row['Field'] . " (" . $row['Type'] . ")\n";
}
?>