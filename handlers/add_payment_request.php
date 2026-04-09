<?php
session_start();
require '../includes/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$employee_id = $_SESSION['user_id'];
$project_id = $_POST['project_id'] ?? null;
$amount = $_POST['amount'] ?? null;
$request_type = $_POST['request_type'] ?? 'General';
$description = $_POST['description'] ?? '';

if (!$project_id || !$amount) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

try {
    // Check employee request limit
    $sql_limit = "SELECT payment_request_limit FROM employees WHERE id = ?";
    $stmt_limit = $pdo->prepare($sql_limit);
    $stmt_limit->execute([$employee_id]);
    $employee_limit = (float) ($stmt_limit->fetchColumn() ?: 0);

    // Consumed limit = (Pending + Approved + Paid Requests) - (Total Invoices for those requests)
    $sql_consumed = "SELECT 
        (SELECT IFNULL(SUM(amount), 0) FROM payment_requests WHERE employee_id = ? AND status IN ('Pending', 'Approved', 'Paid')) - 
        (SELECT IFNULL(SUM(pri.amount), 0) 
         FROM payment_request_invoices pri 
         JOIN payment_requests pr ON pri.payment_request_id = pr.id 
         WHERE pr.employee_id = ? AND pr.status IN ('Pending', 'Approved', 'Paid')) as consumed";
    $stmt_consumed = $pdo->prepare($sql_consumed);
    $stmt_consumed->execute([$employee_id, $employee_id]);
    $consumed_limit = (float) ($stmt_consumed->fetchColumn() ?: 0);

    $available_limit = max(0, $employee_limit - $consumed_limit);

    if ($amount > $available_limit) {
        echo json_encode(['success' => false, 'message' => 'Request exceeds your personal available limit of ₹' . number_format($available_limit, 2)]);
        exit;
    }

    // Check project budget (overall)
    $stmt_proj = $pdo->prepare("SELECT budget, project_name FROM projects WHERE id = ?");
    $stmt_proj->execute([$project_id]);
    $project_data = $stmt_proj->fetch();
    $project_budget = (float) ($project_data['budget'] ?? 0);
    $project_name = $project_data['project_name'] ?? 'Unknown Project';

    $stmt_proj_consumed = $pdo->prepare("SELECT IFNULL(SUM(amount), 0) FROM payment_requests WHERE project_id = ? AND status != 'Rejected'");
    $stmt_proj_consumed->execute([$project_id]);
    $project_consumed = (float) $stmt_proj_consumed->fetchColumn();

    $project_remaining = max(0, $project_budget - $project_consumed);

    if ($amount > $project_remaining) {
        echo json_encode(['success' => false, 'message' => 'Request exceeds the project\'s total remaining budget of ₹' . number_format($project_remaining, 2)]);
        exit;
    }

    // Check category-specific budget
    if ($request_type && strtolower($request_type) !== 'general') {
        $stmt_cat = $pdo->prepare("SELECT amount FROM project_expenses WHERE project_id = ? AND expense_type = ?");
        $stmt_cat->execute([$project_id, $request_type]);
        $category_budget = (float) ($stmt_cat->fetchColumn() ?: 0);

        if ($category_budget > 0) {
            $stmt_cat_consumed = $pdo->prepare("SELECT IFNULL(SUM(amount), 0) FROM payment_requests WHERE project_id = ? AND cost_center = ? AND status != 'Rejected'");
            $stmt_cat_consumed->execute([$project_id, $request_type]);
            $category_consumed = (float) $stmt_cat_consumed->fetchColumn();

            $category_remaining = max(0, $category_budget - $category_consumed);

            if ($amount > $category_remaining) {
                echo json_encode(['success' => false, 'message' => 'Request exceeds the available budget for ' . $request_type . ' (Remaining: ₹' . number_format($category_remaining, 2) . ')']);
                exit;
            }
        }
    }

    // Generate a unique request number similar to #PR-1025
    $stmt = $pdo->query("SELECT MAX(id) as max_id FROM payment_requests");
    $row = $stmt->fetch();
    $next_num = ($row['max_id'] ?? 0) + 1000;
    $request_no = '#PR-' . $next_num;

    $sql = "INSERT INTO payment_requests (request_no, employee_id, project_id, amount, cost_center, status, request_date, purpose) 
            VALUES (?, ?, ?, ?, ?, 'Pending', CURDATE(), ?)";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$request_no, $employee_id, $project_id, $amount, $request_type, $description]);

    echo json_encode(['success' => true, 'message' => 'Payment request submitted successfully.']);
    exit;
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    exit;
}
?>