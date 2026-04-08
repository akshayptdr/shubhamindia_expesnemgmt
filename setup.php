<?php
$host = '127.0.0.1';
$user = 'root';
$pass = 'root'; // Match db.php password

try {
    $pdo = new PDO("mysql:host=$host", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Create database
    $sql = "CREATE DATABASE IF NOT EXISTS expensemgmt";
    $pdo->exec($sql);
    echo "Database created successfully.<br>";

    // Use the database
    $pdo->exec("USE expensemgmt");

    // Create employees table
    $sql = "CREATE TABLE IF NOT EXISTS employees (
        id INT(6) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        emp_id VARCHAR(10) NOT NULL,
        name VARCHAR(100) NOT NULL,
        email VARCHAR(100) NOT NULL,
        mobile VARCHAR(30) NOT NULL,
        role VARCHAR(50) NOT NULL,
        password VARCHAR(255) NOT NULL,
        status VARCHAR(20) DEFAULT 'Active',
        avatar VARCHAR(255) DEFAULT 'https://ui-avatars.com/api/?name=New+User',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )";

    $pdo->exec($sql);
    echo "Table employees created successfully.<br>";

    // Create projects table
    $sql = "CREATE TABLE IF NOT EXISTS projects (
        id INT(6) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        project_code VARCHAR(50) NOT NULL,
        location VARCHAR(100) NOT NULL,
        budget DECIMAL(15, 2) NOT NULL,
        project_type VARCHAR(50),
        start_date DATE,
        end_date DATE,
        status VARCHAR(20) DEFAULT 'Active',
        project_manager_id INT(6) UNSIGNED,
        created_by INT(6) UNSIGNED,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (project_manager_id) REFERENCES employees(id),
        FOREIGN KEY (created_by) REFERENCES employees(id) ON DELETE SET NULL
    )";

    $pdo->exec($sql);
    echo "Table projects updated/created successfully.<br>";

    // Create project_expenses table
    $sql = "CREATE TABLE IF NOT EXISTS project_expenses (
        id INT(6) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        project_id INT(6) UNSIGNED NOT NULL,
        expense_type VARCHAR(100) NOT NULL,
        amount DECIMAL(15, 2) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
    )";

    $pdo->exec($sql);
    echo "Table project_expenses created successfully.<br>";

    // OPTIONAL: Seed data if table is empty
    $stmt = $pdo->query("SELECT COUNT(*) FROM employees");
    if ($stmt->fetchColumn() == 0) {
        $seedData = [
            ['00124', 'John Doe', 'john.doe@shubhamindia.com', '+1 (555) 012-3456', 'Manager', 'Active', '12345678'],
            ['00125', 'Sarah Jenkins', 's.jenkins@shubhamindia.com', '+1 (555) 012-3457', 'Senior Developer', 'Active', '12345678'],
            ['00126', 'Marcus Wood', 'm.wood@shubhamindia.com', '+1 (555) 012-3458', 'Designer', 'Inactive', '12345678']
        ];

        $insert = $pdo->prepare("INSERT INTO employees (emp_id, name, email, mobile, role, status, password) VALUES (?, ?, ?, ?, ?, ?, ?)");
        foreach ($seedData as $row) {
            $insert->execute($row);
        }
        echo "Dummy data seeded.<br>";
    }

    // Projects Seed Data
    $stmt = $pdo->query("SELECT COUNT(*) FROM projects");
    if ($stmt->fetchColumn() == 0) {
        $employees = $pdo->query("SELECT id FROM employees LIMIT 3")->fetchAll(PDO::FETCH_COLUMN);

        if (count($employees) >= 3) {
            $projectSeeds = [
                ['PRJ-2024-01', 'London HQ', 120000, 'Active', $employees[0]],
                ['PRJ-2024-02', 'New York Office', 85000, 'Active', $employees[1]],
                ['PRJ-2023-09', 'Paris Branch', 50000, 'In Progress', $employees[2]],
                ['PRJ-2024-03', 'Tokyo Center', 200000, 'Active', $employees[0]],
                ['PRJ-2023-05', 'Berlin Hub', 65000, 'Completed', $employees[1]]
            ];

            $insert = $pdo->prepare("INSERT INTO projects (project_code, location, budget, status, project_manager_id) VALUES (?, ?, ?, ?, ?)");
            foreach ($projectSeeds as $row) {
                $insert->execute($row);
            }
            echo "Project dummy data seeded.<br>";
        }
    }

} catch (\PDOException $e) {
    die("DB ERROR: " . $e->getMessage());
}
?>