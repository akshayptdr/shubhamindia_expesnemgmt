<?php
require 'includes/db.php';

try {
    $check = $pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'projects' AND COLUMN_NAME = 'project_report_path'");
    if ($check->rowCount() == 0) {
        $pdo->exec("ALTER TABLE projects ADD COLUMN project_report_path VARCHAR(255) NULL");
        echo "Migration successful: project_report_path column added to projects.\n";
    } else {
        echo "Column project_report_path already exists.\n";
    }
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
}
?>
