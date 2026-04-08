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
    $status = $_POST['status'] ?? null;

    if (!$project_id || !$status) {
        echo json_encode(['success' => false, 'message' => 'Missing required fields']);
        exit;
    }

    $allowed_statuses = ['Active', 'Completed', 'In Progress'];
    if (!in_array($status, $allowed_statuses)) {
        echo json_encode(['success' => false, 'message' => 'Invalid status']);
        exit;
    }

    try {
        $stmtCheck = $pdo->prepare("SELECT status FROM projects WHERE id = ?");
        $stmtCheck->execute([$project_id]);
        if ($stmtCheck->fetchColumn() === 'Completed') {
            echo json_encode(['success' => false, 'message' => 'Cannot update a completed project']);
            exit;
        }
        $stmt = $pdo->prepare("UPDATE projects SET status = ? WHERE id = ?");
        $result = $stmt->execute([$status, $project_id]);

        if ($result) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to update status']);
        }

    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'DB Error: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
