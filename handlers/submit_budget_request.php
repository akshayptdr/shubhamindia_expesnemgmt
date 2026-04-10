<?php
session_start();
require '../includes/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$allowed_roles = ['Director', 'Project Manager', 'Senior Project Manager'];
if (!isset($_SESSION['user_role']) || !in_array($_SESSION['user_role'], $allowed_roles)) {
    echo json_encode(['success' => false, 'message' => 'Permission denied: Only Project Managers and Director can request budget updates.']);
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

    $breakdown_array = [];
    for ($i = 0; $i < count($expense_types); $i++) {
        if (!empty($expense_types[$i]) && !empty($expense_amounts[$i])) {
            $breakdown_array[] = [
                'type' => trim($expense_types[$i]),
                'amount' => (float) $expense_amounts[$i]
            ];
        }
    }

    $breakdown_json = json_encode($breakdown_array);

    try {
        $stmt = $pdo->prepare("INSERT INTO budget_change_requests (project_id, requested_by, amount_to_add, breakdown, reason, status) VALUES (?, ?, ?, ?, ?, 'Pending')");
        $stmt->execute([$project_id, $user_id, $amount_to_add, $breakdown_json, $reason]);

        echo json_encode(['success' => true, 'message' => 'Budget change request submitted for approval.']);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
