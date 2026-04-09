<?php
require 'includes/db.php';
echo "--- projects ---\n";
$stmt = $pdo->query("SELECT id, project_name, budget FROM projects LIMIT 5");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

echo "\n--- project_expenses (Budgets by category?) ---\n";
$stmt = $pdo->query("SELECT * FROM project_expenses LIMIT 10");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

echo "\n--- payment_requests (Types used) ---\n";
$stmt = $pdo->query("SELECT DISTINCT cost_center FROM payment_requests");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
