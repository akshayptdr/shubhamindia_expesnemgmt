<?php
require 'includes/db.php';
echo "--- Distinct Expense Types ---\n";
$stmt = $pdo->query("SELECT DISTINCT expense_type FROM payment_request_invoices");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "{$row['expense_type']}\n";
}

echo "\n--- Distinct Expense Subtypes ---\n";
$stmt = $pdo->query("SELECT DISTINCT expense_subtype FROM payment_request_invoices");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "{$row['expense_subtype']}\n";
}
