<?php
session_start();
require '../includes/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SESSION['user_role'] !== 'Director') {
    echo json_encode(['success' => false, 'message' => 'Only Director can reject budget requests']);
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
        $stmt = $pdo->prepare("UPDATE budget_change_requests SET status = 'Rejected', approved_by = ?, approved_at = NOW() WHERE id = ? AND status = 'Pending'");
        $stmt->execute([$admin_id, $request_id]);

        if ($stmt->rowCount() > 0) {
            echo json_encode(['success' => true, 'message' => 'Budget request rejected.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Request not found or already processed']);
        }
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
