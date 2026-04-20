<?php
session_start();
require '../includes/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$project_id = $_GET['project_id'] ?? null;
$request_type = $_GET['request_type'] ?? null; // e.g. "contractor - installation work"

if (!$project_id) {
    echo json_encode(['success' => false, 'message' => 'Project ID required']);
    exit;
}

try {
    if ($request_type) {
        // 1. Get category-specific budget
        $stmt = $pdo->prepare("SELECT amount FROM project_expenses WHERE project_id = ? AND expense_type = ?");
        $stmt->execute([$project_id, $request_type]);
        $category_budget = (float) ($stmt->fetchColumn() ?: 0);

        // 2. Get consumed category budget (Total Requests - Settled Surpluses)
        $sql_consumed = "SELECT 
            IFNULL(SUM(amount), 0) - 
            IFNULL((SELECT SUM(pr2.amount - (SELECT IFNULL(SUM(amount), 0) FROM payment_request_invoices WHERE payment_request_id = pr2.id))
                    FROM payment_requests pr2
                    WHERE pr2.project_id = ? AND pr2.cost_center = ? AND pr2.status = 'Paid' AND pr2.set_off_at IS NOT NULL), 0)
            FROM payment_requests WHERE project_id = ? AND cost_center = ? AND status != 'Rejected'";
        $stmt_consumed = $pdo->prepare($sql_consumed);
        $stmt_consumed->execute([$project_id, $request_type, $project_id, $request_type]);
        $consumed_amount = (float) $stmt_consumed->fetchColumn();

        $remaining_budget = max(0, $category_budget - $consumed_amount);
        $budget_label = "Category Budget";
        $total_budget = $category_budget;
    } else {
        // Fallback to project-wide budget
        $stmt = $pdo->prepare("SELECT budget FROM projects WHERE id = ?");
        $stmt->execute([$project_id]);
        $project_budget = (float) ($stmt->fetchColumn() ?: 0);

        $sql_consumed = "SELECT 
            IFNULL(SUM(amount), 0) - 
            IFNULL((SELECT SUM(pr2.amount - (SELECT IFNULL(SUM(amount), 0) FROM payment_request_invoices WHERE payment_request_id = pr2.id))
                    FROM payment_requests pr2
                    WHERE pr2.project_id = ? AND pr2.status = 'Paid' AND pr2.set_off_at IS NOT NULL), 0)
            FROM payment_requests WHERE project_id = ? AND status != 'Rejected'";
        $stmt_consumed = $pdo->prepare($sql_consumed);
        $stmt_consumed->execute([$project_id, $project_id]);
        $consumed_amount = (float) $stmt_consumed->fetchColumn();

        $remaining_budget = max(0, $project_budget - $consumed_amount);
        $budget_label = "Total Project Budget";
        $total_budget = $project_budget;
    }

    echo json_encode([
        'success' => true,
        'remaining_budget' => $remaining_budget,
        'original_budget' => $total_budget,
        'consumed_amount' => $consumed_amount,
        'budget_label' => $budget_label
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
