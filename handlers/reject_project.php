<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}
require '../includes/db.php';

if ($_SESSION['user_role'] !== 'Director') {
    die("Only Director can reject projects");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $project_id = (int) ($_POST['project_id'] ?? 0);
    $approval_notes = trim($_POST['approval_notes'] ?? '');

    if (!$project_id) {
        die("Project ID is required");
    }
    if ($approval_notes === '') {
        die("A reason is required to reject a project");
    }

    try {
        $stmt = $pdo->prepare("UPDATE projects SET approval_status = 'Rejected', director_approved_by = ?, director_approved_at = NOW(), approval_notes = ? WHERE id = ? AND approval_status = 'Pending'");
        $stmt->execute([$_SESSION['user_id'], $approval_notes, $project_id]);

        header("Location: ../project_details.php?id=" . $project_id);
        exit;
    } catch (Exception $e) {
        die("Error rejecting project: " . $e->getMessage());
    }
} else {
    header("Location: ../projects.php");
    exit;
}
