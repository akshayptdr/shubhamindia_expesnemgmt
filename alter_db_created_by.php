<?php
require 'includes/db.php';

try {
    // Add created_by column
    $pdo->exec("ALTER TABLE projects ADD COLUMN created_by INT(6) UNSIGNED AFTER project_manager_id");

    // Attempt to add foreign key constraint
    $pdo->exec("ALTER TABLE projects ADD FOREIGN KEY (created_by) REFERENCES employees(id) ON DELETE SET NULL");

    echo "Successfully added created_by column.\\n";
} catch (Exception $e) {
    if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
        echo "Column already exists.\\n";
    } else {
        echo "Error: " . $e->getMessage() . "\\n";
    }
}
?>