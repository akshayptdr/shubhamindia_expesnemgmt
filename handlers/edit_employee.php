<?php
require '../includes/db.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $id = $_POST['employee_id'] ?? '';
    $fullName = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $mobile = trim($_POST['mobile'] ?? '');
    $role = trim($_POST['role'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $limit = (float) ($_POST['payment_request_limit'] ?? 0);

    if (!empty($id) && !empty($fullName) && !empty($email) && !empty($mobile) && !empty($role)) {
        try {
            $sql = "UPDATE employees SET name = :name, email = :email, mobile = :mobile, role = :role, password = :password, payment_request_limit = :limit WHERE id = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':name' => $fullName,
                ':email' => $email,
                ':mobile' => $mobile,
                ':role' => $role,
                ':password' => $password,
                ':limit' => $limit,
                ':id' => $id
            ]);

            // Redirect back to index with a success flag
            header("Location: ../index.php?success=1");
            exit;
        } catch (\PDOException $e) {
            die("Error updating employee: " . $e->getMessage());
        }
    } else {
        die("Please fill in all required fields.");
    }
} else {
    header("Location: ../index.php");
    exit;
}
?>