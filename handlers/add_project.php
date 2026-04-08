<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}
require '../includes/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $activity_type = $_POST['activity_type'] ?? '';
    $project_category = $_POST['project_category'] ?? null;
    $service_type = $_POST['service_type'] ?? null;
    $project_name = $_POST['project_name'] ?? null;
    $project_code = $_POST['project_code'] ?? '';
    $location = $_POST['location'] ?? '';
    $budget = $_POST['budget'] ?? 0;
    $start_date = $_POST['start_date'] ?? null;
    $end_date = $_POST['end_date'] ?? null;
    $project_manager_id = $_POST['project_manager_id'] ?? null;

    $expense_types = $_POST['expense_type'] ?? [];
    $expense_amounts = $_POST['expense_amount'] ?? [];

    try {
        $pdo->beginTransaction();

        // 1. Insert Project
        $created_by = $_SESSION['user_id'];
        $sql = "INSERT INTO projects (activity_type, project_category, service_type, project_name, project_code, location, budget, start_date, end_date, project_manager_id, created_by, status) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active')";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$activity_type, $project_category, $service_type, $project_name, $project_code, $location, $budget, $start_date, $end_date, $project_manager_id, $created_by]);
        $project_id = $pdo->lastInsertId();

        // 2. Insert Expenses
        $total_utilized = 0;
        $expense_sql = "INSERT INTO project_expenses (project_id, expense_type, amount) VALUES (?, ?, ?)";
        $expense_stmt = $pdo->prepare($expense_sql);

        $breakdown_array = [];

        for ($i = 0; $i < count($expense_types); $i++) {
            if (!empty($expense_types[$i]) && !empty($expense_amounts[$i])) {
                $amount = (float) $expense_amounts[$i];
                $type = trim($expense_types[$i]);
                $expense_stmt->execute([$project_id, $type, $amount]);
                $total_utilized += $amount;
                $breakdown_array[] = ['type' => $type, 'amount' => $amount];
            }
        }

        $breakdown_json = !empty($breakdown_array) ? json_encode($breakdown_array) : null;
        $log_stmt = $pdo->prepare("INSERT INTO budget_logs (project_id, type, amount, reason, breakdown, updated_by) VALUES (?, 'Initial', ?, 'Initial Budget Setup', ?, ?)");
        $log_stmt->execute([$project_id, $budget, $breakdown_json, $created_by]);

        // 3. (Removed utilized_budget update)

        $pdo->commit();
        header("Location: ../projects.php?success=1");
        exit;

    } catch (Exception $e) {
        $pdo->rollBack();
        die("Error adding project: " . $e->getMessage());
    }
} else {
    header("Location: ../projects.php");
    exit;
}
?>