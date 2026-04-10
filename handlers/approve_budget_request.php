<?php
session_start();
require '../includes/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SESSION['user_role'] !== 'Director') {
    echo json_encode(['success' => false, 'message' => 'Only Director can approve budget requests']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $request_id = $_POST['request_id'] ?? null;
    $admin_id = $_SESSION['user_id'];

    if (!$request_id) {
        echo json_encode(['success' => false, 'message' => 'Missing Request ID']);
        exit;
    }

    try {
        $pdo->beginTransaction();

        // 1. Fetch the request
        $stmt = $pdo->prepare("SELECT * FROM budget_change_requests WHERE id = ? AND status = 'Pending' FOR UPDATE");
        $stmt->execute([$request_id]);
        $request = $stmt->fetch();

        if (!$request) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Request not found or already processed']);
            exit;
        }

        $project_id = $request['project_id'];
        $amount_to_add = (float) $request['amount_to_add'];
        $breakdown = json_decode($request['breakdown'], true);

        // 2. Get current project budget
        $stmt = $pdo->prepare("SELECT budget FROM projects WHERE id = ? FOR UPDATE");
        $stmt->execute([$project_id]);
        $project = $stmt->fetch();
        $new_budget = $project['budget'] + $amount_to_add;

        // 3. Update project budget
        $stmt = $pdo->prepare("UPDATE projects SET budget = ? WHERE id = ?");
        $stmt->execute([$new_budget, $project_id]);

        // 4. Update expense breakdown
        $check_expense = $pdo->prepare("SELECT id, amount FROM project_expenses WHERE project_id = ? AND expense_type = ?");
        $update_expense = $pdo->prepare("UPDATE project_expenses SET amount = ? WHERE id = ?");
        $insert_expense = $pdo->prepare("INSERT INTO project_expenses (project_id, expense_type, amount) VALUES (?, ?, ?)");

        foreach ($breakdown as $item) {
            $type = $item['type'];
            $amount = (float) $item['amount'];

            $check_expense->execute([$project_id, $type]);
            $existing = $check_expense->fetch();

            if ($existing) {
                $update_expense->execute([$existing['amount'] + $amount, $existing['id']]);
            } else {
                $insert_expense->execute([$project_id, $type, $amount]);
            }
        }

        // 5. Log the update in budget_logs
        $stmt = $pdo->prepare("INSERT INTO budget_logs (project_id, type, amount, reason, breakdown, updated_by) VALUES (?, 'Update', ?, ?, ?, ?)");
        $stmt->execute([$project_id, $amount_to_add, $request['reason'], $request['breakdown'], $request['requested_by']]);

        // 6. Update request status
        $stmt = $pdo->prepare("UPDATE budget_change_requests SET status = 'Approved', approved_by = ?, approved_at = NOW() WHERE id = ?");
        $stmt->execute([$admin_id, $request_id]);

        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Budget request approved and project updated.']);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Execution Error: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
