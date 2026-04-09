<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

require 'includes/db.php';

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: projects.php");
    exit;
}

$project_id = (int) $_GET['id'];

// Fetch project details
$sql = "SELECT p.*, e_mgr.name as manager_name, e_mgr.avatar as manager_avatar, e_creator.name as creator_name 
        FROM projects p 
        LEFT JOIN employees e_mgr ON p.project_manager_id = e_mgr.id 
        LEFT JOIN employees e_creator ON p.created_by = e_creator.id 
        WHERE p.id = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute([$project_id]);
$project = $stmt->fetch();

if (!$project) {
    header("Location: projects.php");
    exit;
}

// Fetch budget breakdown (added for detailed view)
$sql_budget = "SELECT expense_type, amount FROM project_expenses WHERE project_id = ? ORDER BY id ASC";
$stmt_budget = $pdo->prepare($sql_budget);
$stmt_budget->execute([$project_id]);
$budget_items = $stmt_budget->fetchAll();

// Fetch budget logs (for history)
$sql_logs = "SELECT bl.*, e.name as user_name 
             FROM budget_logs bl 
             LEFT JOIN employees e ON bl.updated_by = e.id 
             WHERE bl.project_id = ? 
             ORDER BY bl.created_at DESC";
$stmt_logs = $pdo->prepare($sql_logs);
$stmt_logs->execute([$project_id]);
$budget_logs = $stmt_logs->fetchAll();

// Fetch Associated Payment Requests
$sql_payments = "SELECT pr.*, e.name as employee_name, e.avatar as employee_avatar 
                 FROM payment_requests pr
                 LEFT JOIN employees e ON pr.employee_id = e.id
                 WHERE pr.project_id = ? 
                 ORDER BY pr.request_date DESC 
                 LIMIT 5";
$stmt_payments = $pdo->prepare($sql_payments);
$stmt_payments->execute([$project_id]);
$associated_payments = $stmt_payments->fetchAll();

// Status and progress calculations
$statusClass = 'status-' . strtolower(str_replace(' ', '-', $project['status']));
$progressColor = '#1a56db';
// Fetch approved and paid payment requests total for budget utilization
$sql_utilized = "SELECT SUM(amount) as utilized FROM payment_requests WHERE project_id = ? AND status IN ('Approved', 'Paid')";
$stmt_utilized = $pdo->prepare($sql_utilized);
$stmt_utilized->execute([$project_id]);
$utilized_row = $stmt_utilized->fetch();
$utilized_budget = (float) ($utilized_row['utilized'] ?? 0);

$total_budget = (float) $project['budget'];
$remaining_budget = $total_budget - $utilized_budget;
$utilized_percentage = $total_budget > 0 ? round(($utilized_budget / $total_budget) * 100) : 0;

$current_page = 'projects';

// Calculate available payment request limit for the current user
$user_id = $_SESSION['user_id'];
$sql_user_limit = "SELECT payment_request_limit FROM employees WHERE id = ?";
$stmt_user_limit = $pdo->prepare($sql_user_limit);
$stmt_user_limit->execute([$user_id]);
$employee_limit = (float) ($stmt_user_limit->fetchColumn() ?: 0);

// Consumed limit = (Pending + Approved + Paid Requests) - (Total Invoices for those requests)
$sql_consumed = "SELECT 
    (SELECT IFNULL(SUM(amount), 0) FROM payment_requests WHERE employee_id = ? AND status IN ('Pending', 'Approved', 'Paid')) - 
    (SELECT IFNULL(SUM(pri.amount), 0) 
     FROM payment_request_invoices pri 
     JOIN payment_requests pr ON pri.payment_request_id = pr.id 
     WHERE pr.employee_id = ? AND pr.status IN ('Pending', 'Approved', 'Paid')) as consumed";
$stmt_consumed = $pdo->prepare($sql_consumed);
$stmt_consumed->execute([$user_id, $user_id]);
$consumed_limit = (float) ($stmt_consumed->fetchColumn() ?: 0);

$available_request_limit = max(0, $employee_limit - $consumed_limit);

include 'includes/app_header.php';
?>

<!-- Page Content -->
<div class="page-content">
    <div class="breadcrumbs" style="font-size: 13px; color: var(--text-secondary); margin-bottom: 8px;">
        <a href="projects.php" style="color: var(--text-secondary); text-decoration: none;">Projects</a>
        <span style="margin: 0 8px;">›</span>
        <span style="color: var(--text-primary); font-weight: 500;">
            <?php echo htmlspecialchars($project['project_name'] ?: $project['project_code']); ?>
        </span>
    </div>

    <div class="page-header">
        <div class="page-title">
            <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 4px;">
                <h2 style="margin: 0;">
                    <?php echo htmlspecialchars($project['project_name'] ?: $project['project_code']); ?>
                </h2>
                <span class="status-text <?php echo $statusClass; ?>"
                    style="font-size: 11px; padding: 4px 10px; font-weight: 600; text-transform: uppercase;">
                    <?php echo htmlspecialchars($project['status']); ?>
                </span>
            </div>
            <p>Detailed project overview and budget utilization.</p>
        </div>
        <div class="page-actions" style="display: flex; gap: 12px;">
            <?php if ($project['status'] !== 'Completed'): ?>
                <button class="btn-secondary" id="openUpdateStatusModal"
                    style="background: white; border: 1px solid var(--border-color); color: var(--text-primary); font-weight: 500; padding: 10px 16px; border-radius: 8px; cursor: pointer; display: flex; align-items: center; gap: 8px;">
                    <i class="ph ph-arrow-clockwise"></i> Update Status
                </button>
                <button class="btn-secondary" id="openPaymentRequestModal"
                    style="background: white; border: 1px solid var(--border-color); color: var(--text-primary); font-weight: 500; padding: 10px 16px; border-radius: 8px; cursor: pointer; display: flex; align-items: center; gap: 8px;">
                    <i class="ph ph-receipt"></i> Request For Payment
                </button>
                <button class="btn-primary" id="openUpdateBudgetModal"
                    style="background: #1a56db; color: white; border: none; font-weight: 500; padding: 10px 16px; border-radius: 8px; cursor: pointer; display: flex; align-items: center; gap: 8px;">
                    <i class="ph ph-pencil-simple"></i> Update the Budget
                </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- KPI Cards -->
    <div class="kpi-grid" style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 24px; margin-bottom: 24px;">
        <!-- Total Budget -->
        <div class="kpi-card"
            style="background: white; border: 1px solid var(--border-color); border-radius: 12px; padding: 24px;">
            <div class="kpi-title"
                style="font-size: 13px; color: var(--text-secondary); margin-bottom: 8px; font-weight: 500;">Total
                Budget</div>
            <div class="kpi-value"
                style="font-size: 28px; font-weight: 600; color: var(--text-primary); margin-bottom: 8px;">₹
                <?php echo number_format($project['budget'], 2); ?>
            </div>

            <?php if (!empty($budget_items)): ?>
                <div class="budget-breakdown"
                    style="margin-top: 16px; padding-top: 12px; border-top: 1px dashed var(--border-color);">
                    <div style="display: flex; flex-direction: column; gap: 8px;">
                        <?php foreach ($budget_items as $item): ?>
                            <div style="display: flex; justify-content: space-between; font-size: 13px;">
                                <span
                                    style="color: var(--text-secondary);"><?php echo htmlspecialchars($item['expense_type']); ?></span>
                                <span style="font-weight: 500; color: var(--text-primary);">₹
                                    <?php echo number_format($item['amount'], 2); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Utilized Budget -->
        <div class="kpi-card"
            style="background: white; border: 1px solid var(--border-color); border-radius: 12px; padding: 24px;">
            <div class="kpi-title"
                style="font-size: 13px; color: var(--text-secondary); margin-bottom: 8px; font-weight: 500;">Utilized
                Budget
            </div>

            <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 12px;">
                <div class="kpi-value" style="font-size: 28px; font-weight: 600; color: var(--text-primary);">₹
                    <?php echo number_format($utilized_budget, 2); ?>
                </div>
                <div style="font-size: 14px; font-weight: 500; color: #1a56db;">
                    <?php echo $utilized_percentage; ?>%
                </div>
            </div>

            <div class="progress-wrapper" style="margin-bottom: 12px;">
                <div class="progress-bg"
                    style="height: 8px; background: #f1f5f9; border-radius: 10px; overflow: hidden;">
                    <div class="progress-fill"
                        style="height: 100%; width: <?php echo $utilized_percentage; ?>%; background: #1a56db; border-radius: 10px;">
                    </div>
                </div>
            </div>


        </div>

        <!-- Remaining Budget -->
        <div class="kpi-card"
            style="background: white; border: 1px solid var(--border-color); border-radius: 12px; padding: 24px;">
            <div class="kpi-title"
                style="font-size: 13px; color: var(--text-secondary); margin-bottom: 8px; font-weight: 500;">Remaining
                Budget</div>
            <div class="kpi-value"
                style="font-size: 28px; font-weight: 600; color: var(--text-primary); margin-bottom: 8px;">₹
                <?php echo number_format($remaining_budget, 2); ?>
            </div>

        </div>
    </div>
    <div class="content-grid" style="display: grid; grid-template-columns: 2fr 1fr; gap: 24px; margin-bottom: 24px;">

        <!-- Project Overview -->
        <div class="detail-card"
            style="background: white; border: 1px solid var(--border-color); border-radius: 12px; padding: 24px;">
            <h2
                style="font-size: 16px; font-weight: 600; color: var(--text-primary); margin: 0 0 24px 0; display: flex; align-items: center; gap: 8px;">
                <i class="ph ph-file-text" style="color: #1a56db; font-size: 20px;"></i> Project Overview
            </h2>

            <div class="meta-grid"
                style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 32px;">
                <div class="meta-item">
                    <div class="meta-label"
                        style="font-size: 11px; font-weight: 600; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 6px;">
                        Activity Type</div>
                    <div class="meta-value"
                        style="font-size: 15px; font-weight: 500; color: var(--text-primary); display: flex; flex-direction: column; gap: 4px;">
                        <span><?php echo htmlspecialchars($project['activity_type'] ?? 'Project'); ?></span>
                        <?php if ($project['activity_type'] === 'Projects' && !empty($project['project_category'])): ?>
                            <span style="font-size: 12px; color: var(--text-secondary); font-weight: normal;">Category:
                                <?php echo htmlspecialchars($project['project_category']); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="meta-item">
                    <div class="meta-label"
                        style="font-size: 11px; font-weight: 600; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 6px;">
                        Project Name / Code</div>
                    <div class="meta-value" style="font-size: 15px; font-weight: 500; color: var(--text-primary);">
                        <?php
                        if (!empty($project['project_name'])) {
                            echo htmlspecialchars($project['project_name']);
                            if (!empty($project['project_code'])) {
                                echo ' (' . htmlspecialchars($project['project_code']) . ')';
                            }
                            echo ' <span style="font-size: 12px; color: var(--text-secondary); font-weight: normal;">(Pre Sale)</span>';
                        } else {
                            echo htmlspecialchars($project['project_code']);
                        }
                        ?>
                    </div>
                </div>
                <?php if (($project['activity_type'] === 'Services' || $project['activity_type'] === 'O&M') && !empty($project['service_type'])): ?>
                    <div class="meta-item">
                        <div class="meta-label"
                            style="font-size: 11px; font-weight: 600; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 6px;">
                            Service Type</div>
                        <div class="meta-value" style="font-size: 15px; font-weight: 500; color: var(--text-primary);">
                            <?php echo htmlspecialchars($project['service_type']); ?>
                        </div>
                    </div>
                <?php endif; ?>
                <div class="meta-item">
                    <div class="meta-label"
                        style="font-size: 11px; font-weight: 600; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 6px;">
                        Location</div>
                    <div class="meta-value"
                        style="font-size: 15px; font-weight: 500; color: var(--text-primary); display: flex; align-items: center; gap: 6px;">
                        <i class="ph ph-map-pin" style="color: var(--text-secondary);"></i>
                        <?php echo htmlspecialchars($project['location']); ?>
                    </div>
                </div>
                <div class="meta-item">
                    <div class="meta-label"
                        style="font-size: 11px; font-weight: 600; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 6px;">
                        Project Manager</div>
                    <div class="meta-value"
                        style="font-size: 15px; font-weight: 500; color: var(--text-primary); display: flex; align-items: center; gap: 8px;">
                        <?php if ($project['manager_avatar']): ?>
                            <img src="<?php echo htmlspecialchars($project['manager_avatar']); ?>" alt="Manager"
                                style="width: 24px; height: 24px; border-radius: 50%;">
                        <?php else: ?>
                            <div
                                style="width: 24px; height: 24px; border-radius: 50%; background: #e2e8f0; display: flex; align-items: center; justify-content: center; font-size: 10px; font-weight: 600; color: #64748b;">
                                <?php echo strtoupper(substr($project['manager_name'] ?? 'U', 0, 1)); ?>
                            </div>
                        <?php endif; ?>
                        <?php echo htmlspecialchars($project['manager_name'] ?? 'Not Assigned'); ?>
                    </div>
                </div>
                <div class="meta-item" style="display: flex; gap: 32px;">
                    <div>
                        <div class="meta-label"
                            style="font-size: 11px; font-weight: 600; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 6px;">
                            Start Date</div>
                        <div class="meta-value" style="font-size: 15px; font-weight: 500; color: var(--text-primary);">
                            <?php echo $project['start_date'] ? date('M d, Y', strtotime($project['start_date'])) : 'Not Set'; ?>
                        </div>
                    </div>
                    <div>
                        <div class="meta-label"
                            style="font-size: 11px; font-weight: 600; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 6px;">
                            End Date</div>
                        <div class="meta-value" style="font-size: 15px; font-weight: 500; color: var(--text-primary);">
                            <?php echo $project['end_date'] ? date('M d, Y', strtotime($project['end_date'])) : 'Not Set'; ?>
                        </div>
                    </div>
                </div>
            </div>


        </div>

        <!-- Budget History -->
        <div class="detail-card"
            style="background: white; border: 1px solid var(--border-color); border-radius: 12px; padding: 24px;">
            <h2
                style="font-size: 16px; font-weight: 600; color: var(--text-primary); margin: 0 0 24px 0; display: flex; align-items: center; gap: 8px;">
                <i class="ph ph-clock-counter-clockwise" style="color: #1a56db; font-size: 20px;"></i> Budget History
            </h2>

            <div class="timeline"
                style="display: flex; flex-direction: column; gap: 24px; position: relative; padding-left: 16px;">
                <!-- Timeline line -->
                <div style="position: absolute; left: 4px; top: 6px; bottom: 6px; width: 2px; background: #e2e8f0;">
                </div>

                <?php
                $has_initial_log = false;
                foreach ($budget_logs as $log) {
                    if ($log['type'] === 'Initial')
                        $has_initial_log = true;
                }
                ?>

                <?php foreach ($budget_logs as $log): ?>
                    <div class="timeline-item" style="position: relative;">
                        <div
                            style="position: absolute; left: -16px; top: 4px; width: 10px; height: 10px; border-radius: 50%; background: <?php echo $log['type'] === 'Initial' ? '#cbd5e1' : '#1a56db'; ?>; border: 2px solid white; box-shadow: 0 0 0 1px <?php echo $log['type'] === 'Initial' ? '#cbd5e1' : '#1a56db'; ?>;">
                        </div>
                        <div style="font-size: 14px; font-weight: 600; color: var(--text-primary); margin-bottom: 4px;">
                            <?php if ($log['type'] === 'Initial'): ?>
                                Initial Budget Set at ₹ <?php echo number_format($log['amount'], 2); ?>
                            <?php else: ?>
                                Budget Increased by ₹ <?php echo number_format($log['amount'], 2); ?>
                            <?php endif; ?>
                        </div>
                        <div style="font-size: 12px; color: var(--text-secondary);">
                            <?php echo date('M d, Y', strtotime($log['created_at'])); ?> by
                            <?php echo htmlspecialchars($log['user_name'] ?? 'System'); ?>
                        </div>
                        <?php if ($log['reason']): ?>
                            <div style="font-size: 12px; color: var(--text-secondary); margin-top: 4px; font-style: italic;">
                                "<?php echo htmlspecialchars($log['reason']); ?>"
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($log['breakdown'])): ?>
                            <?php $breakdown = json_decode($log['breakdown'], true); ?>
                            <?php if (is_array($breakdown) && count($breakdown) > 0): ?>
                                <div
                                    style="margin-top: 8px; padding-left: 12px; border-left: 2px solid #e2e8f0; display: flex; flex-direction: column; gap: 4px;">
                                    <?php foreach ($breakdown as $item): ?>
                                        <div style="font-size: 12px; color: var(--text-secondary);">
                                            • <?php echo htmlspecialchars($item['type']); ?>: <span
                                                style="font-weight: 500; color: var(--text-primary);">₹
                                                <?php echo number_format($item['amount'], 2); ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>

                <?php if (!$has_initial_log): ?>
                    <div class="timeline-item" style="position: relative;">
                        <div
                            style="position: absolute; left: -16px; top: 4px; width: 10px; height: 10px; border-radius: 50%; background: #cbd5e1; border: 2px solid white;">
                        </div>
                        <div style="font-size: 14px; font-weight: 500; color: var(--text-secondary); margin-bottom: 4px;">
                            Initial Budget Set at ₹
                            <?php echo number_format($project['budget'] - array_sum(array_column($budget_logs, 'amount')), 2); ?>
                        </div>
                        <div style="font-size: 12px; color: var(--text-secondary);">
                            <?php echo $project['created_at'] ? date('M d, Y', strtotime($project['created_at'])) : 'Jan 01, 2024'; ?>
                            by <?php echo htmlspecialchars($project['creator_name'] ?? 'System'); ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Associated Payment Requests -->
    <div class="detail-card"
        style="background: white; border: 1px solid var(--border-color); border-radius: 12px; padding: 24px; margin-bottom: 24px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h2 style="font-size: 16px; font-weight: 600; color: var(--text-primary); margin: 0;">Associated Payment
                Requests</h2>
            <a href="payment_requests.php?project_id=<?php echo $project_id; ?>"
                style="color: #1a56db; font-size: 13px; font-weight: 600; text-decoration: none;">View All</a>
        </div>

        <div class="table-responsive">
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="border-bottom: 1px solid var(--border-color);">
                        <th
                            style="text-align: left; padding: 12px 8px; font-size: 11px; font-weight: 600; color: var(--text-secondary); text-transform: uppercase;">
                            Request ID</th>
                        <th
                            style="text-align: left; padding: 12px 8px; font-size: 11px; font-weight: 600; color: var(--text-secondary); text-transform: uppercase;">
                            Employee</th>
                        <th
                            style="text-align: left; padding: 12px 8px; font-size: 11px; font-weight: 600; color: var(--text-secondary); text-transform: uppercase;">
                            Type</th>
                        <th
                            style="text-align: left; padding: 12px 8px; font-size: 11px; font-weight: 600; color: var(--text-secondary); text-transform: uppercase;">
                            Amount</th>
                        <th
                            style="text-align: left; padding: 12px 8px; font-size: 11px; font-weight: 600; color: var(--text-secondary); text-transform: uppercase;">
                            Date</th>
                        <th
                            style="text-align: left; padding: 12px 8px; font-size: 11px; font-weight: 600; color: var(--text-secondary); text-transform: uppercase;">
                            Status</th>
                        <th
                            style="text-align: right; padding: 12px 8px; font-size: 11px; font-weight: 600; color: var(--text-secondary); text-transform: uppercase;">
                            Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($associated_payments)): ?>
                        <tr>
                            <td colspan="6"
                                style="padding: 24px; text-align: center; color: var(--text-secondary); font-size: 14px;">No
                                payment requests found for this project.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($associated_payments as $payment): ?>
                            <tr style="border-bottom: 1px solid #f1f5f9;">
                                <td style="padding: 12px 8px; font-size: 13px; font-weight: 600; color: #1a56db;">
                                    <?php echo htmlspecialchars($payment['request_no']); ?>
                                </td>
                                <td style="padding: 12px 8px;">
                                    <span
                                        style="font-size: 13px; color: var(--text-primary);"><?php echo htmlspecialchars($payment['employee_name']); ?></span>
                                </td>
                                <td style="padding: 12px 8px;">
                                    <span
                                        style="font-size: 11px; color: #1a56db; font-weight: 600; text-transform: uppercase; display: inline-block; padding: 2px 6px; background: #eff6ff; border-radius: 4px;">
                                        <?php echo htmlspecialchars($payment['cost_center'] ?: 'General'); ?>
                                    </span>
                                </td>
                                <td style="padding: 12px 8px; font-size: 13px; font-weight: 600; color: var(--text-primary);">₹
                                    <?php echo number_format($payment['amount'], 2); ?>
                                </td>
                                <td style="padding: 12px 8px; font-size: 13px; color: var(--text-secondary);">
                                    <?php echo date('M d, Y', strtotime($payment['request_date'])); ?>
                                </td>
                                <td style="padding: 12px 8px;">
                                    <span style="display: inline-flex; align-items: center; gap: 4px; padding: 2px 8px; border-radius: 12px; font-size: 11px; font-weight: 600; 
                                <?php
                                if ($payment['status'] === 'Approved')
                                    echo 'background: #f0fdf4; color: #16a34a;';
                                elseif ($payment['status'] === 'Pending')
                                    echo 'background: #fff7ed; color: #c2410c;';
                                elseif ($payment['status'] === 'Paid')
                                    echo 'background: #f1f5f9; color: #1e293b;';
                                else
                                    echo 'background: #fef2f2; color: #dc2626;';
                                ?>">
                                        <?php echo $payment['status']; ?>
                                    </span>
                                </td>
                                <td style="padding: 12px 8px; text-align: right;">
                                    <a href="payment_request_details.php?id=<?php echo $payment['id']; ?>"
                                        style="text-decoration: none;">
                                        <i class="ph ph-eye" style="color: #94a3b8; cursor: pointer;"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>



    <!-- Update Budget Modal -->
    <div id="updateBudgetModal" class="modal-overlay"
        style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
        <div class="modal-card"
            style="background: white; width: 480px; border-radius: 12px; overflow: hidden; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1);">
            <div class="modal-header"
                style="padding: 20px 24px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center;">
                <h3 style="font-size: 18px; font-weight: 600; color: var(--text-primary); margin: 0;">Update Project
                    Budget</h3>
                <button id="closeUpdateBudgetModal"
                    style="background: none; border: none; color: var(--text-secondary); cursor: pointer; font-size: 20px; padding: 4px;"><i
                        class="ph ph-x"></i></button>
            </div>
            <form id="updateBudgetForm" style="padding: 24px;">
                <input type="hidden" name="project_id" value="<?php echo $project_id; ?>">
                <div style="margin-bottom: 20px;">
                    <label
                        style="display: block; font-size: 13px; font-weight: 500; color: var(--text-secondary); margin-bottom: 8px;">Current
                        Budget</label>
                    <div style="position: relative;">
                        <i class="ph ph-wallet"
                            style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--text-secondary);"></i>
                        <input type="text" value="₹ <?php echo number_format($project['budget'], 2); ?>" readonly
                            style="width: 100%; padding: 10px 12px 10px 38px; background: #f9fafb; border: 1px solid var(--border-color); border-radius: 8px; font-size: 14px; color: var(--text-secondary); outline: none;">
                    </div>
                </div>
                <div style="margin-bottom: 20px;">
                    <label
                        style="display: block; font-size: 13px; font-weight: 500; color: var(--text-secondary); margin-bottom: 8px;">Amount
                        to Add (Auto-calculated from breakdown)</label>
                    <div style="position: relative;">
                        <i class="ph ph-currency-inr"
                            style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--text-secondary);"></i>
                        <input type="number" step="0.01" name="amount_to_add" id="amount_to_add_input"
                            placeholder="0.00" readonly
                            style="width: 100%; padding: 10px 12px 10px 38px; background: #f9fafb; border: 1px solid var(--border-color); border-radius: 8px; font-size: 14px; color: var(--text-secondary); outline: none; cursor: not-allowed;">
                    </div>
                </div>

                <div class="section-divider"
                    style="margin-bottom: 16px; padding: 16px 0 0 0; border-top: 1px solid var(--border-color);">
                    <span
                        style="font-size: 11px; font-weight: 600; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.05em;">BUDGET
                        BREAKDOWN</span>
                </div>

                <div id="update-expense-container"
                    style="display: flex; flex-direction: column; gap: 16px; margin-bottom: 16px;">
                    <div class="expense-row"
                        style="display: grid; grid-template-columns: 1fr 1fr auto; gap: 16px; align-items: flex-end;">
                        <div>
                            <label
                                style="display: block; font-size: 13px; font-weight: 500; color: var(--text-secondary); margin-bottom: 8px;">Type</label>
                            <select name="expense_type[]" required
                                style="width: 100%; padding: 10px 12px; border: 1px solid var(--border-color); border-radius: 8px; font-size: 14px; outline: none; background: white;">
                                <option value="">Select Type</option>
                                <option value="contractor - installation work">Contractor - Installation Work</option>
                                <option value="aarya team - material shifting and lifting">Aarya Team - Material
                                    Shifting and Lifting</option>
                                <option value="company team - site expenses">Company Team - Site Expenses</option>
                            </select>
                        </div>
                        <div>
                            <label
                                style="display: block; font-size: 13px; font-weight: 500; color: var(--text-secondary); margin-bottom: 8px;">Amount
                                to Add</label>
                            <div style="position: relative;">
                                <i class="ph ph-currency-inr"
                                    style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--text-secondary);"></i>
                                <input type="number" step="0.01" name="expense_amount[]" placeholder="0.00" required
                                    style="width: 100%; padding: 10px 12px 10px 38px; border: 1px solid var(--border-color); border-radius: 8px; font-size: 14px; outline: none;">
                            </div>
                        </div>
                    </div>
                </div>

                <div style="margin-bottom: 20px;">
                    <button type="button" id="addUpdateExpenseBtn"
                        style="background: none; border: none; color: #1a56db; font-weight: 500; font-size: 14px; display: flex; align-items: center; gap: 8px; cursor: pointer; padding: 0;">
                        <i class="ph ph-plus-circle" style="font-size: 20px;"></i>
                        Add More Type
                    </button>
                </div>
                <div style="margin-bottom: 24px;">
                    <label
                        style="display: block; font-size: 13px; font-weight: 500; color: var(--text-secondary); margin-bottom: 8px;">Reason
                        for Update</label>
                    <textarea name="reason" placeholder="e.g. Additional scope approved for mobile app module" required
                        style="width: 100%; height: 100px; padding: 12px; border: 1px solid var(--border-color); border-radius: 8px; font-size: 14px; color: var(--text-primary); outline: none; resize: none;"></textarea>
                </div>
                <div class="modal-footer" style="display: flex; gap: 12px; margin-top: 8px;">
                    <button type="submit" class="btn-primary"
                        style="flex: 1; background: #1a56db; color: white; border: none; font-weight: 500; padding: 12px; border-radius: 8px; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px;">
                        <i class="ph ph-upload-simple"></i> Update Budget
                    </button>
                    <button type="button" id="cancelUpdateBudgetBtn"
                        style="flex: 1; background: white; color: var(--text-primary); border: 1px solid var(--border-color); font-weight: 500; padding: 12px; border-radius: 8px; cursor: pointer;">
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Update Status Modal -->
    <div id="updateStatusModal" class="modal-overlay"
        style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
        <div class="modal-card"
            style="background: white; width: 400px; border-radius: 12px; overflow: hidden; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1);">
            <div class="modal-header"
                style="padding: 20px 24px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center;">
                <h3 style="font-size: 18px; font-weight: 600; color: var(--text-primary); margin: 0;">Update Project
                    Status</h3>
                <button id="closeUpdateStatusModal"
                    style="background: none; border: none; color: var(--text-secondary); cursor: pointer; font-size: 20px; padding: 4px;"><i
                        class="ph ph-x"></i></button>
            </div>
            <form id="updateStatusForm" style="padding: 24px;">
                <input type="hidden" name="project_id" value="<?php echo $project_id; ?>">
                <div style="margin-bottom: 24px;">
                    <label
                        style="display: block; font-size: 13px; font-weight: 500; color: var(--text-secondary); margin-bottom: 8px;">Select
                        New Status</label>
                    <div style="position: relative;">
                        <i class="ph ph-activity"
                            style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--text-secondary);"></i>
                        <select name="status" required
                            style="width: 100%; padding: 10px 12px 10px 38px; background: white; border: 1px solid var(--border-color); border-radius: 8px; font-size: 14px; color: var(--text-primary); outline: none; appearance: none;">
                            <option value="Active" <?php echo $project['status'] === 'Active' ? 'selected' : ''; ?>>Active
                            </option>
                            <option value="Completed" <?php echo $project['status'] === 'Completed' ? 'selected' : ''; ?>>
                                Completed</option>
                            <option value="In Progress" <?php echo $project['status'] === 'In Progress' ? 'selected' : ''; ?>>In
                                Progress</option>
                        </select>
                        <i class="ph ph-caret-down"
                            style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%); color: var(--text-secondary); pointer-events: none;"></i>
                    </div>
                </div>
                <div class="modal-footer" style="display: flex; gap: 12px; margin-top: 8px;">
                    <button type="submit" class="btn-primary"
                        style="flex: 1; background: #1a56db; color: white; border: none; font-weight: 500; padding: 12px; border-radius: 8px; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px;">
                        <i class="ph ph-check-circle"></i> Save Changes
                    </button>
                    <button type="button" id="cancelUpdateStatusBtn"
                        style="flex: 1; background: white; color: var(--text-primary); border: 1px solid var(--border-color); font-weight: 500; padding: 12px; border-radius: 8px; cursor: pointer;">
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Add Payment Request Modal -->
    <div id="paymentRequestModal" class="modal-overlay"
        style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
        <div class="modal-card"
            style="background: white; width: 550px; border-radius: 12px; overflow: hidden; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1);">
            <div class="modal-header"
                style="padding: 24px 24px 20px 24px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: flex-start;">
                <div>
                    <h3 style="font-size: 18px; font-weight: 600; color: var(--text-primary); margin: 0 0 4px 0;">Add
                        New Payment Request</h3>
                    <p style="font-size: 14px; color: var(--text-secondary); margin: 0;">Submit your expense details for
                        reimbursement.</p>
                </div>
                <button id="closePaymentRequestModal"
                    style="background: none; border: none; color: var(--text-secondary); cursor: pointer; font-size: 20px; padding: 4px;"><i
                        class="ph ph-x"></i></button>
            </div>
            <form id="paymentRequestForm" action="handlers/add_payment_request.php" method="POST"
                style="padding: 24px;">
                <input type="hidden" name="project_id" value="<?php echo $project_id; ?>">

                <div style="margin-bottom: 20px;">
                    <label
                        style="display: block; font-size: 13px; font-weight: 600; color: #475569; margin-bottom: 8px;">Type
                        of Payment Request</label>
                    <div style="position: relative;">
                        <i class="ph ph-tag"
                            style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 16px;"></i>
                        <select name="request_type" id="requestTypeSelect" required
                            style="width: 100%; padding: 10px 12px 10px 32px; background: white; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 14px; color: var(--text-primary); outline: none; appearance: none;">
                            <option value="">Select Type</option>
                            <option value="contractor - installation work">Contractor - Installation Work</option>
                            <option value="aarya team - material shifting and lifting">Aarya Team - Material Shifting
                                and Lifting</option>
                            <option value="company team - site expenses">Company Team - Site Expenses</option>
                        </select>
                        <i class="ph ph-caret-down"
                            style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%); color: #94a3b8; pointer-events: none;"></i>
                    </div>
                </div>

                <!-- Error Message Alert -->
                <div id="paymentRequestError"
                    style="display: none; margin-bottom: 20px; padding: 12px; background: #fef2f2; color: #dc2626; border-radius: 8px; font-size: 13px; font-weight: 500; border: 1px solid #fee2e2; align-items: center; gap: 8px;">
                    <i class="ph-fill ph-warning-circle" style="font-size: 18px;"></i>
                    <span class="error-text"></span>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                    <div>
                        <div
                            style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                            <label style="font-size: 13px; font-weight: 600; color: #475569;">Amount</label>
                            <span id="availableLimitBadge"
                                style="font-size: 11px; font-weight: 600; color: #16a34a; background: #f0fdf4; padding: 2px 8px; border-radius: 4px;">
                                Available: ₹<?php echo number_format($available_request_limit, 2); ?>
                            </span>
                        </div>
                        <div style="position: relative;">
                            <i class="ph ph-currency-inr"
                                style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 16px;"></i>
                            <input type="number" id="requestAmountInput" step="0.01" name="amount" placeholder="0.00" required
                                max="<?php echo $available_request_limit; ?>"
                                style="width: 100%; padding: 10px 12px 10px 32px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 14px; color: var(--text-primary); outline: none;">
                        </div>
                        <?php if ($available_request_limit <= 0): ?>
                            <div
                                style="margin-top: 8px; font-size: 11px; color: #dc2626; font-weight: 500; display: flex; align-items: center; gap: 4px;">
                                <i class="ph ph-info" style="font-size: 14px;"></i>
                                Limit reached. Upload invoices to free up limit.
                            </div>
                        <?php endif; ?>
                    </div>
                    <div>
                        <label
                            style="display: block; font-size: 13px; font-weight: 600; color: #475569; margin-bottom: 8px;">Project</label>
                        <div style="position: relative;">
                            <select name="project" disabled
                                style="width: 100%; padding: 10px 12px; background: white; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 14px; color: var(--text-primary); outline: none; appearance: none; opacity: 1;">
                                <option value="<?php echo $project_id; ?>">
                                    <?php echo htmlspecialchars($project['project_name'] ?: $project['project_code']); ?>
                                </option>
                            </select>
                            <i class="ph ph-caret-down"
                                style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%); color: #94a3b8; pointer-events: none;"></i>
                        </div>
                    </div>
                </div>



                <div style="margin-bottom: 24px;">
                    <label
                        style="display: block; font-size: 13px; font-weight: 600; color: #475569; margin-bottom: 8px;">Description
                        / Notes</label>
                    <textarea name="description" placeholder="Explain the business purpose of this expense..." required
                        style="width: 100%; height: 100px; padding: 12px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 14px; color: var(--text-primary); outline: none; resize: none;"></textarea>
                </div>

                <div class="modal-footer"
                    style="display: flex; justify-content: flex-end; gap: 12px; padding-top: 16px;">
                    <button type="button" id="cancelPaymentRequestBtn"
                        style="background: none; color: #475569; border: none; font-weight: 500; font-size: 14px; padding: 10px 16px; border-radius: 8px; cursor: pointer;">
                        Cancel
                    </button>
                    <button type="submit" class="btn-primary"
                        style="background: #1a56db; color: white; border: none; font-weight: 600; font-size: 14px; padding: 10px 20px; border-radius: 8px; cursor: pointer;">
                        Submit Request
                    </button>
                </div>
            </form>
        </div>
    </div>


    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // Payment Request Modal Logic
            const prModal = document.getElementById('paymentRequestModal');
            const openPRBtn = document.getElementById('openPaymentRequestModal');
            const closePRBtn = document.getElementById('closePaymentRequestModal');
            const cancelPRBtn = document.getElementById('cancelPaymentRequestBtn');

            if (openPRBtn) {
                openPRBtn.addEventListener('click', () => {
                    prModal.style.display = 'flex';
                });
            }

            const closePRModalFunc = () => {
                prModal.style.display = 'none';
            };

            if (closePRBtn) closePRBtn.addEventListener('click', closePRModalFunc);
            if (cancelPRBtn) cancelPRBtn.addEventListener('click', closePRModalFunc);

            // Handle Payment Request Form Submission via AJAX
            const prForm = document.getElementById('paymentRequestForm');
            const prError = document.getElementById('paymentRequestError');
            const prSubmitBtn = prForm.querySelector('button[type="submit"]');

            if (prForm) {
                prForm.addEventListener('submit', function (e) {
                    e.preventDefault();

                    // UI Feedback
                    const originalBtnText = prSubmitBtn.innerHTML;
                    prSubmitBtn.disabled = true;
                    prSubmitBtn.innerHTML = '<i class="ph ph-circle-notch ph-spin"></i> Submitting...';
                    prError.style.display = 'none';

                    const formData = new FormData(this);

                    fetch('handlers/add_payment_request.php', {
                        method: 'POST',
                        body: formData
                    })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                // Success - Refresh page to show new request
                                window.location.reload();
                            } else {
                                // Error - Show in modal
                                prError.querySelector('.error-text').textContent = data.message;
                                prError.style.display = 'flex';
                                prSubmitBtn.disabled = false;
                                prSubmitBtn.innerHTML = originalBtnText;
                            }
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            prError.querySelector('.error-text').textContent = 'An unexpected error occurred. Please try again.';
                            prError.style.display = 'flex';
                            prSubmitBtn.disabled = false;
                            prSubmitBtn.innerHTML = originalBtnText;
                        });
                });
            }

            // Category-Specific Budget Loading Logic
            const typeSelect = document.getElementById('requestTypeSelect');
            const amountInput = document.getElementById('requestAmountInput');
            const limitBadge = document.getElementById('availableLimitBadge');
            const employeeLimit = <?php echo (float)$available_request_limit; ?>;
            const projectId = <?php echo (int)$project_id; ?>;

            function updateAvailableLimit() {
                const requestType = typeSelect ? typeSelect.value : null;
                
                limitBadge.innerHTML = '<i class="ph ph-circle-notch ph-spin"></i> Checking Budget...';
                
                let fetchUrl = 'handlers/get_project_budget.php?project_id=' + projectId;
                if (requestType) {
                    fetchUrl += '&request_type=' + encodeURIComponent(requestType);
                }

                fetch(fetchUrl)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            const projectRemaining = data.remaining_budget;
                            const finalAvailable = Math.min(employeeLimit, projectRemaining);
                            
                            limitBadge.textContent = 'Available: ₹' + finalAvailable.toLocaleString('en-IN', {minimumFractionDigits: 2});
                            amountInput.max = finalAvailable;
                            
                            if (projectRemaining <= 0) {
                                limitBadge.style.background = '#fef2f2';
                                limitBadge.style.color = '#dc2626';
                            } else {
                                limitBadge.style.background = '#f0fdf4';
                                limitBadge.style.color = '#16a34a';
                            }
                        }
                    })
                    .catch(err => {
                        console.error('Error fetching budget:', err);
                        limitBadge.textContent = 'Available: ₹' + employeeLimit.toLocaleString('en-IN', {minimumFractionDigits: 2});
                    });
            }

            if (typeSelect) typeSelect.addEventListener('change', updateAvailableLimit);
            // Initial load as project_id is fixed
            if (projectId) updateAvailableLimit();

            // Update Budget Modal
            const modal = document.getElementById('updateBudgetModal');
            const openBtn = document.getElementById('openUpdateBudgetModal');
            const closeBtn = document.getElementById('closeUpdateBudgetModal');
            const cancelBtn = document.getElementById('cancelUpdateBudgetBtn');
            const form = document.getElementById('updateBudgetForm');

            window.addEventListener('click', (e) => {
                if (e.target === modal) {
                    modal.style.display = 'none';
                }
                if (e.target === prModal) closePRModalFunc();
            });

            const updateExpenseContainer = document.getElementById('update-expense-container');
            const addUpdateExpenseBtn = document.getElementById('addUpdateExpenseBtn');
            const amountToAddInput = document.getElementById('amount_to_add_input');

            const calculateUpdateBudget = () => {
                const expenseInputs = updateExpenseContainer.querySelectorAll('input[name="expense_amount[]"]');
                let sumExpenses = 0;
                expenseInputs.forEach(input => {
                    sumExpenses += parseFloat(input.value) || 0;
                });
                amountToAddInput.value = sumExpenses.toFixed(2);
            };

            const updateUpdateExpenseOptions = () => {
                const selects = updateExpenseContainer.querySelectorAll('select[name="expense_type[]"]');
                const selectedValues = Array.from(selects)
                    .map(select => select.value)
                    .filter(value => value !== "");

                selects.forEach(select => {
                    Array.from(select.options).forEach(option => {
                        if (option.value === "") return;
                        if (selectedValues.includes(option.value) && option.value !== select.value) {
                            option.disabled = true;
                        } else {
                            option.disabled = false;
                        }
                    });
                });
            };

            if (updateExpenseContainer) {
                updateExpenseContainer.addEventListener('input', (e) => {
                    if (e.target.name === 'expense_amount[]') {
                        calculateUpdateBudget();
                    }
                });

                updateExpenseContainer.addEventListener('change', (e) => {
                    if (e.target.name === 'expense_type[]') {
                        updateUpdateExpenseOptions();
                    }
                });
            }

            if (addUpdateExpenseBtn) {
                addUpdateExpenseBtn.addEventListener('click', () => {
                    const newRow = document.createElement('div');
                    newRow.className = 'expense-row';
                    newRow.style.display = 'grid';
                    newRow.style.gridTemplateColumns = '1fr 1fr auto';
                    newRow.style.gap = '16px';
                    newRow.style.alignItems = 'flex-end';
                    newRow.style.marginTop = '8px';
                    newRow.innerHTML = `
                        <div style="display: flex; flex-direction: column; gap: 8px;">
                            <select name="expense_type[]" required style="width: 100%; padding: 10px 12px; border: 1px solid var(--border-color); border-radius: 8px; font-size: 14px; outline: none; background: white;">
                                <option value="">Select Type</option>
                                <option value="contractor - installation work">Contractor - Installation Work</option>
                                <option value="aarya team - material shifting and lifting">Aarya Team - Material Shifting and Lifting</option>
                                <option value="company team - site expenses">Company Team - Site Expenses</option>
                            </select>
                        </div>
                        <div style="display: flex; flex-direction: column; gap: 8px;">
                            <div style="position: relative;">
                                <i class="ph ph-currency-inr" style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--text-secondary);"></i>
                                <input type="number" step="0.01" name="expense_amount[]" placeholder="0.00" required style="width: 100%; padding: 10px 12px 10px 38px; border: 1px solid var(--border-color); border-radius: 8px; font-size: 14px; outline: none;">
                            </div>
                        </div>
                        <button type="button" class="remove-row-btn" style="background: none; border: none; color: #ef4444; cursor: pointer; padding: 10px 0;">
                            <i class="ph ph-trash" style="font-size: 18px;"></i>
                        </button>
                    `;
                    updateExpenseContainer.appendChild(newRow);

                    newRow.querySelector('.remove-row-btn').addEventListener('click', () => {
                        newRow.remove();
                        updateUpdateExpenseOptions();
                        calculateUpdateBudget();
                    });

                    updateUpdateExpenseOptions();
                });
            }

            if (openBtn) {
                openBtn.addEventListener('click', () => {
                    modal.style.display = 'flex';
                });
            }

            const closeModal = () => {
                modal.style.display = 'none';
                form.reset();
            };

            if (closeBtn) closeBtn.addEventListener('click', closeModal);
            if (cancelBtn) cancelBtn.addEventListener('click', closeModal);

            window.addEventListener('click', (e) => {
                if (e.target === modal) closeModal();
            });

            if (form) {
                form.addEventListener('submit', function (e) {
                    e.preventDefault();

                    calculateUpdateBudget();
                    const totalToAdd = parseFloat(amountToAddInput.value) || 0;
                    if (totalToAdd <= 0) {
                        alert('Amount to add must be greater than zero.');
                        return;
                    }

                    const formData = new FormData(this);
                    const submitBtn = this.querySelector('button[type="submit"]');
                    const originalContent = submitBtn.innerHTML;

                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<i class="ph ph-circle-notch ph-spin"></i> Updating...';

                    fetch('handlers/update_budget.php', {
                        method: 'POST',
                        body: formData
                    })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                location.reload();
                            } else {
                                alert(data.message || 'Error updating budget');
                                submitBtn.disabled = false;
                                submitBtn.innerHTML = originalContent;
                            }
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            alert('An error occurred. Please try again.');
                            submitBtn.disabled = false;
                            submitBtn.innerHTML = originalContent;
                        });
                });
            }

            // Update Status Modal Logic
            const statusModal = document.getElementById('updateStatusModal');
            const openStatusBtn = document.getElementById('openUpdateStatusModal');
            const closeStatusBtn = document.getElementById('closeUpdateStatusModal');
            const cancelStatusBtn = document.getElementById('cancelUpdateStatusBtn');
            const statusForm = document.getElementById('updateStatusForm');

            if (openStatusBtn) {
                openStatusBtn.addEventListener('click', () => {
                    statusModal.style.display = 'flex';
                });
            }

            const closeStatusModal = () => {
                statusModal.style.display = 'none';
            };

            if (closeStatusBtn) closeStatusBtn.addEventListener('click', closeStatusModal);
            if (cancelStatusBtn) cancelStatusBtn.addEventListener('click', closeStatusModal);

            window.addEventListener('click', (e) => {
                if (e.target === statusModal) closeStatusModal();
            });

            if (statusForm) {
                statusForm.addEventListener('submit', function (e) {
                    e.preventDefault();

                    const formData = new FormData(this);
                    const submitBtn = this.querySelector('button[type="submit"]');
                    const originalContent = submitBtn.innerHTML;

                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<i class="ph ph-circle-notch ph-spin"></i> Updating...';

                    fetch('handlers/update_project_status.php', {
                        method: 'POST',
                        body: formData
                    })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                location.reload();
                            } else {
                                alert(data.message || 'Error updating status');
                                submitBtn.disabled = false;
                                submitBtn.innerHTML = originalContent;
                            }
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            alert('An error occurred. Please try again.');
                            submitBtn.disabled = false;
                            submitBtn.innerHTML = originalContent;
                        });
                });
            }
        });
    </script>

    <?php include 'includes/app_footer.php'; ?>