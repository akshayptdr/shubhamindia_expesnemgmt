<?php
require 'includes/db.php';

try {
    $existing = $pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'projects'")->fetchAll(PDO::FETCH_COLUMN);
    $is_first_run = !in_array('approval_status', $existing);

    $columns = [
        'approval_status' => "VARCHAR(20) NOT NULL DEFAULT 'Pending'",
        'director_approved_by' => "INT UNSIGNED NULL",
        'director_approved_at' => "TIMESTAMP NULL",
        'approval_notes' => "TEXT NULL",
    ];

    foreach ($columns as $name => $definition) {
        if (!in_array($name, $existing)) {
            $pdo->exec("ALTER TABLE projects ADD COLUMN $name $definition");
            echo "Added column $name<br>\n";
        } else {
            echo "Column $name already exists<br>\n";
        }
    }

    if ($is_first_run) {
        // Projects that already exist were already in use, so treat them as approved
        $pdo->exec("UPDATE projects SET approval_status = 'Approved'");
        echo "Existing projects marked Approved<br>\n";
    }
    echo "Migration completed.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
}
?>
