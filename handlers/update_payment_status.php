<?php
session_start();
header('Content-Type: application/json');
require_once '../includes/db.php';

// Check if logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

// Get POST data
$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['id']) || !isset($data['status'])) {
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    exit;
}

$requestId = (int) $data['id'];
$status = $data['status'];
$reviewerId = $_SESSION['user_id'];

// Role-based Authorization
$userRole = $_SESSION['user_role'] ?? '';
$paymentRoles = ['Director', 'Accounts Manager', 'Accounts Assistant'];

if ($status === 'Paid') {
    if (!in_array($userRole, $paymentRoles)) {
        echo json_encode(['success' => false, 'error' => "Only " . implode(', ', $paymentRoles) . " can mark requests as Paid."]);
        exit;
    }
}

try {
    // Check if request exists and get requester role
    $stmt = $pdo->prepare("SELECT pr.status, pr.project_id, pr.amount, pr.employee_id, e.role as requester_role 
                          FROM payment_requests pr 
                          JOIN employees e ON pr.employee_id = e.id 
                          WHERE pr.id = ?");
    $stmt->execute([$requestId]);
    $request = $stmt->fetch();

    if (!$request) {
        echo json_encode(['success' => false, 'error' => 'Request not found']);
        exit;
    }

    // Tiered Authorization Logic for Approval/Rejection
    if (in_array($status, ['Approved', 'Rejected'])) {
        $requester_role = $request['requester_role'];
        $can_approve = false;

        if (in_array($requester_role, ['Fitter', 'Senior Fitter', 'Engineer', 'Senior Engineer'])) {
            if (in_array($userRole, ['Project Manager', 'Director'])) {
                $can_approve = true;
            }
        } elseif ($requester_role === 'Project Manager') {
            if (in_array($userRole, ['Senior Project Manager', 'Director'])) {
                $can_approve = true;
            }
        } elseif ($requester_role === 'Senior Project Manager') {
            if ($userRole === 'Director') {
                $can_approve = true;
            }
        } elseif ($userRole === 'Director') {
            $can_approve = true;
        }

        if (!$can_approve) {
            echo json_encode(['success' => false, 'error' => "Unauthorized: You cannot $status this request."]);
            exit;
        }
    }

    // Validation logic for transitions
    if ($status === 'Paid' && $request['status'] !== 'Approved') {
        echo json_encode(['success' => false, 'error' => 'Only approved requests can be marked as paid']);
        exit;
    }

    if (($status === 'Approved' || $status === 'Rejected') && $request['status'] !== 'Pending') {
        echo json_encode(['success' => false, 'error' => 'Request is no longer pending']);
        exit;
    }

    // Voucher Settlement Actions (Set Off -> Approve)
    if (in_array($status, ['set_off', 'approve_voucher'])) {
        if ($request['status'] !== 'Paid') {
            echo json_encode(['success' => false, 'error' => 'Voucher settlement only allowed for Paid requests']);
            exit;
        }

        // Role-based Authorization for settlement
        if ($status === 'set_off') {
            // Requester or Director
            if ($userRole !== 'Director' && (int)$_SESSION['user_id'] !== (int)$request['employee_id']) {
                echo json_encode(['success' => false, 'error' => "Only the requester or Director can initiate Set-Off."]);
                exit;
            }
        } else {
            // Accounts or Director
            $accountsRoles = ['Accounts Manager', 'Accounts Assistant'];
            if ($userRole !== 'Director' && !in_array($userRole, $accountsRoles)) {
                echo json_encode(['success' => false, 'error' => "Only Accounts or Director can approve voucher settlement."]);
                exit;
            }
        }

        // Verify pending voucher exists
        $stmt_inv = $pdo->prepare("SELECT IFNULL(SUM(amount), 0) FROM payment_request_invoices WHERE payment_request_id = ?");
        $stmt_inv->execute([$requestId]);
        $invoiced_amount = (float)$stmt_inv->fetchColumn();
        $pending_voucher = (float)$request['amount'] - $invoiced_amount;

        if ($pending_voucher <= 0) {
            echo json_encode(['success' => false, 'error' => 'No pending vouchers to settle.']);
            exit;
        }

        if ($status === 'set_off') {
            $sql_update = "UPDATE payment_requests SET set_off_at = NOW(), set_off_by = ? WHERE id = ?";
        } else {
            // "Approve" (voucher settlement) requires set_off_at to be present
            $stmt_check = $pdo->prepare("SELECT set_off_at FROM payment_requests WHERE id = ?");
            $stmt_check->execute([$requestId]);
            if (!$stmt_check->fetchColumn()) {
                echo json_encode(['success' => false, 'error' => 'Request must be Set Off before final approval.']);
                exit;
            }
            $sql_update = "UPDATE payment_requests SET voucher_approved_at = NOW(), voucher_approved_by = ? WHERE id = ?";
        }

        $stmt_upd = $pdo->prepare($sql_update);
        if ($stmt_upd->execute([$_SESSION['user_id'], $requestId])) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to update settlement status.']);
        }
        exit;
    }

    // Budget check for approval
    if ($status === 'Approved') {
        // Get project budget
        $stmt_project = $pdo->prepare("SELECT budget FROM projects WHERE id = ?");
        $stmt_project->execute([$request['project_id']]);
        $project = $stmt_project->fetch();

        if (!$project) {
            echo json_encode(['success' => false, 'error' => 'Project not found']);
            exit;
        }

        // Get sum of already Approved/Paid requests
        $stmt_sum = $pdo->prepare("SELECT SUM(amount) as total_approved FROM payment_requests WHERE project_id = ? AND status IN ('Approved', 'Paid') AND id != ?");
        $stmt_sum->execute([$request['project_id'], $requestId]);
        $sum_row = $stmt_sum->fetch();
        $approved_total = (float) ($sum_row['total_approved'] ?? 0);

        if (($approved_total + $request['amount']) > $project['budget']) {
            echo json_encode([
                'success' => false,
                'error' => "Cannot approve payment of ₹" . number_format($request['amount'], 2) . ". Total approved amount (₹" . number_format($approved_total + $request['amount'], 2) . ") would exceed the project budget (₹" . number_format($project['budget'], 2) . ")."
            ]);
            exit;
        }
    }

    // Update status and reviewed_by
    $sql = "UPDATE payment_requests SET status = ?, reviewed_by = ?";
    $params = [$status, $reviewerId];

    if ($status === 'Approved') {
        $sql .= ", approved_at = NOW()";
    }

    if ($status === 'Paid') {
        $sql .= ", paid_at = NOW()";
        if (isset($data['reference'])) {
            $sql .= ", payment_reference = ?";
            $params[] = $data['reference'];
        }
        if (isset($data['mode'])) {
            $sql .= ", payment_method = ?";
            $params[] = $data['mode'];
        }
    }

    $sql .= " WHERE id = ?";
    $params[] = $requestId;

    $stmt = $pdo->prepare($sql);
    $result = $stmt->execute($params);

    if ($result) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to update status in database']);
    }

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
