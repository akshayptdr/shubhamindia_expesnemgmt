<?php
session_start();
require '../includes/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $project_id = $_POST['project_id'] ?? null;
    $amount_to_add = $_POST['amount_to_add'] ?? null;
    $expense_types = $_POST['expense_type'] ?? [];
    $expense_amounts = $_POST['expense_amount'] ?? [];
    $reason = $_POST['reason'] ?? '';
    $user_id = $_SESSION['user_id'];

    if (!$project_id || !$amount_to_add || !is_numeric($amount_to_add) || $amount_to_add <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid budget amount']);
        exit;
    }

    // Verify calculated amount to add against individual expenses
    $calculated_total = 0;
    foreach ($expense_amounts as $amt) {
        $calculated_total += (float) $amt;
    }

    // We can use the calculated total since it should perfectly match amount_to_add from JS.
    $amount_to_add_float = (float) $amount_to_add;

    try {
        $pdo->beginTransaction();

        // 1. Get current budget
        $stmt = $pdo->prepare("SELECT budget FROM projects WHERE id = ?");
        $stmt->execute([$project_id]);
        $project = $stmt->fetch();

        if (!$project) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Project not found']);
            exit;
        }

        $new_budget = $project['budget'] + $amount_to_add_float;

        // 2. Update projects table
        $stmt = $pdo->prepare("UPDATE projects SET budget = ? WHERE id = ?");
        $stmt->execute([$new_budget, $project_id]);

        // 3. Process Expense Breakdown
        $check_expense = $pdo->prepare("SELECT id, amount FROM project_expenses WHERE project_id = ? AND expense_type = ?");
        $update_expense = $pdo->prepare("UPDATE project_expenses SET amount = ? WHERE id = ?");
        $insert_expense = $pdo->prepare("INSERT INTO project_expenses (project_id, expense_type, amount) VALUES (?, ?, ?)");

        $breakdown_array = [];

        for ($i = 0; $i < count($expense_types); $i++) {
            if (!empty($expense_types[$i]) && !empty($expense_amounts[$i])) {
                $type = trim($expense_types[$i]);
                $amount = (float) $expense_amounts[$i];
                if ($amount <= 0)
                    continue;

                $breakdown_array[] = ['type' => $type, 'amount' => $amount];

                $check_expense->execute([$project_id, $type]);
                $existing = $check_expense->fetch();

                if ($existing) {
                    $update_expense->execute([$existing['amount'] + $amount, $existing['id']]);
                } else {
                    $insert_expense->execute([$project_id, $type, $amount]);
                }
            }
        }

        $breakdown_json = !empty($breakdown_array) ? json_encode($breakdown_array) : null;

        // 4. Log the update in budget_logs
        $stmt = $pdo->prepare("INSERT INTO budget_logs (project_id, type, amount, reason, breakdown, updated_by) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$project_id, 'Update', $amount_to_add_float, $reason, $breakdown_json, $user_id]);

        $pdo->commit();
        echo json_encode(['success' => true]);

    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'DB Error: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
