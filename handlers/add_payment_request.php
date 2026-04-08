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
        echo json_encode(['success' => false, 'message' => 'Request exceeds your available limit of ₹' . number_format($available_limit, 2) . '. Please upload invoices for outstanding requests.']);
        exit;
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