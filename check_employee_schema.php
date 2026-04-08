<?php
require 'includes/db.php';
$stmt = $pdo->query('DESCRIBE employees');
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "{$row['Field']} ({$row['Type']}) Null: {$row['Null']}\n";
}
?>