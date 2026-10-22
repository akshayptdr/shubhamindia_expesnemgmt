<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}
require '../includes/db.php';

if ($_SESSION['user_role'] !== 'Director') {
    die("Only Director can approve projects");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $project_id = (int) ($_POST['project_id'] ?? 0);

    if (!$project_id) {
        die("Project ID is required");
    }

    try {
        $stmt = $pdo->prepare("UPDATE projects SET approval_status = 'Approved', director_approved_by = ?, director_approved_at = NOW(), approval_notes = NULL WHERE id = ? AND approval_status = 'Pending'");
        $stmt->execute([$_SESSION['user_id'], $project_id]);

        header("Location: ../project_details.php?id=" . $project_id);
        exit;
    } catch (Exception $e) {
        die("Error approving project: " . $e->getMessage());
    }
} else {
    header("Location: ../projects.php");
    exit;
}
