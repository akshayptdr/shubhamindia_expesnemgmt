<?php
require 'includes/db.php';

try {
    $sql = "CREATE TABLE IF NOT EXISTS budget_change_requests (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        project_id INT UNSIGNED NOT NULL,
        requested_by INT UNSIGNED NOT NULL,
        amount_to_add DECIMAL(15,2) NOT NULL,
        breakdown JSON,
        reason TEXT NOT NULL,
        status ENUM('Pending', 'Approved', 'Rejected') DEFAULT 'Pending',
        approved_by INT UNSIGNED,
        approved_at DATETIME,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (project_id) REFERENCES projects(id),
        FOREIGN KEY (requested_by) REFERENCES employees(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

    $pdo->exec($sql);
    echo "Table 'budget_change_requests' created successfully.\n";

} catch (PDOException $e) {
    die("Error creating table: " . $e->getMessage());
}
?>
