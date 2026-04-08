<?php
require '../includes/db.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $fullName = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $mobile = trim($_POST['mobile'] ?? '');
    $role = trim($_POST['role'] ?? '');
    $limit = (float) ($_POST['payment_request_limit'] ?? 0);

    // Generate a random 5-digit employee ID
    $emp_id = str_pad(rand(1, 99999), 5, '0', STR_PAD_LEFT);

    // Generate a random 8-digit password
    $password = (string) rand(10000000, 99999999);

    if (!empty($fullName) && !empty($email) && !empty($mobile) && !empty($role)) {
        try {
            // Check if mobile number already exists
            $checkStmt = $pdo->prepare("SELECT id FROM employees WHERE mobile = :mobile LIMIT 1");
            $checkStmt->execute([':mobile' => $mobile]);
            if ($checkStmt->fetch()) {
                header("Location: ../index.php?error=duplicate");
                exit;
            }

            $sql = "INSERT INTO employees (emp_id, name, email, mobile, role, password, payment_request_limit) VALUES (:emp_id, :name, :email, :mobile, :role, :password, :limit)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':emp_id' => $emp_id,
                ':name' => $fullName,
                ':email' => $email,
                ':mobile' => $mobile,
                ':role' => $role,
                ':password' => $password,
                ':limit' => $limit
            ]);

            // Redirect back to index with a success flag
            header("Location: ../index.php?success=1");
            exit;
        } catch (\PDOException $e) {
            die("Error inserting employee: " . $e->getMessage());
        }
    } else {
        die("Please fill in all required fields.");
    }
} else {
    header("Location: ../index.php");
    exit;
}
?>