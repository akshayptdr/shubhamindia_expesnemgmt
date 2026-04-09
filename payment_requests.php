<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

require 'includes/db.php';

// Pagination setup
$limit = 10;
$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Filter setup
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'All';
$project_id_filter = isset($_GET['project_id']) ? (int) $_GET['project_id'] : null;

$where_clauses = [];
if ($status_filter !== 'All') {
    $where_clauses[] = "pr.status = '$status_filter'";
}
if ($project_id_filter) {
    $where_clauses[] = "pr.project_id = $project_id_filter";
}

$where_clause = "";
if (!empty($where_clauses)) {
    $where_clause = " WHERE " . implode(" AND ", $where_clauses);
}

// Fetch KPI statistics
$kpi_where = $project_id_filter ? " WHERE project_id = $project_id_filter" : "";
$kpi_query = "SELECT 
    COUNT(*) as total_count,
    SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) as pending_count,
    SUM(CASE WHEN status = 'Approved' THEN 1 ELSE 0 END) as approved_count,
    SUM(CASE WHEN status = 'Rejected' THEN 1 ELSE 0 END) as rejected_count,
    SUM(CASE WHEN status = 'Paid' THEN 1 ELSE 0 END) as paid_count
FROM payment_requests $kpi_where";
$kpi_stats = $pdo->query($kpi_query)->fetch();

// Fetch payment requests with employee and project info
$sql = "SELECT pr.*, e.name as employee_name, e.avatar as employee_avatar, p.project_code, p.project_name, p.activity_type, 
               (SELECT IFNULL(SUM(amount), 0) FROM payment_request_invoices WHERE payment_request_id = pr.id) as invoiced_amount
        FROM payment_requests pr
        LEFT JOIN employees e ON pr.employee_id = e.id
        LEFT JOIN projects p ON pr.project_id = p.id
        $where_clause
        ORDER BY pr.request_date DESC
        LIMIT $limit OFFSET $offset";
$requests = $pdo->query($sql)->fetchAll();

// Count total for pagination
$total_rows_query = "SELECT COUNT(*) FROM payment_requests pr" . $where_clause;
$total_rows = $pdo->query($total_rows_query)->fetchColumn();
$total_pages = ceil($total_rows / $limit);

// Active projects for dropdown
$active_projects = $pdo->query("SELECT id, project_name, project_code FROM projects WHERE status != 'Completed' ORDER BY id DESC")->fetchAll();

// Calculate available payment request limit for the current user
$user_id = $_SESSION['user_id'];
$sql_user_limit = "SELECT payment_request_limit FROM employees WHERE id = ?";
$stmt_user_limit = $pdo->prepare($sql_user_limit);
$stmt_user_limit->execute([$user_id]);
$employee_limit = (float) ($stmt_user_limit->fetchColumn() ?: 0);

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

$page_title = 'Payment Requests';
$current_page = 'payments';
include 'includes/app_header.php';
?>

<div class="page-content">
    <div class="breadcrumb"
        style="font-size: 11px; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.1em; display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
        <span style="opacity: 0.6;">Main Dashboard</span>
        <i class="ph ph-caret-right" style="font-size: 10px; opacity: 0.4;"></i>
        <span>Payment Requests</span>
    </div>

    <div class="page-header"
        style="display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 32px;">
        <div class="header-content">
            <h1 style="font-size: 28px; font-weight: 700; color: var(--text-primary); margin: 0 0 6px 0;">Payment
                Requests</h1>
            <p style="font-size: 14px; color: var(--text-secondary); margin: 0;">Manage and track all employee payment
                submissions.</p>
        </div>
        <div class="page-actions" style="display: flex; gap: 12px;">
            <button class="btn-secondary" id="openPaymentRequestModal"
                style="background: white; border: 1px solid var(--border-color); color: var(--text-primary); font-weight: 500; padding: 10px 16px; border-radius: 8px; cursor: pointer; display: flex; align-items: center; gap: 8px;">
                <i class="ph ph-receipt"></i> Request For Payment
            </button>
        </div>
    </div>

    <!-- KPI Dashboard -->
    <div class="kpi-grid" style="display: grid; grid-template-columns: repeat(5, 1fr); gap: 24px; margin-bottom: 32px;">
        <a href="?status=All<?php echo $project_id_filter ? '&project_id=' . $project_id_filter : ''; ?>"
            class="kpi-card"
            style="text-decoration: none; background: white; padding: 20px 24px; border-radius: 12px; border: 1px solid <?php echo $status_filter === 'All' ? '#1a56db' : 'var(--border-color)'; ?>; display: flex; align-items: center; gap: 20px; transition: all 0.2s;">
            <div
                style="width: 44px; height: 44px; background: #eff6ff; border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                <i class="ph ph-file-text" style="color: #1a56db; font-size: 22px;"></i>
            </div>
            <div>
                <span
                    style="display: block; font-size: 11px; font-weight: 600; color: var(--text-secondary); text-transform: uppercase;">Total
                    Requests</span>
                <span
                    style="font-size: 20px; font-weight: 700; color: var(--text-primary);"><?php echo number_format($kpi_stats['total_count']); ?></span>
            </div>
        </a>
        <a href="?status=Pending<?php echo $project_id_filter ? '&project_id=' . $project_id_filter : ''; ?>"
            class="kpi-card"
            style="text-decoration: none; background: white; padding: 20px 24px; border-radius: 12px; border: 1px solid <?php echo $status_filter === 'Pending' ? '#d97706' : 'var(--border-color)'; ?>; display: flex; align-items: center; gap: 20px; transition: all 0.2s;">
            <div
                style="width: 44px; height: 44px; background: #fffbeb; border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                <i class="ph ph-clock" style="color: #d97706; font-size: 22px;"></i>
            </div>
            <div>
                <span
                    style="display: block; font-size: 11px; font-weight: 600; color: var(--text-secondary); text-transform: uppercase;">Pending</span>
                <span
                    style="font-size: 20px; font-weight: 700; color: var(--text-primary);"><?php echo number_format($kpi_stats['pending_count']); ?></span>
            </div>
        </a>
        <a href="?status=Approved<?php echo $project_id_filter ? '&project_id=' . $project_id_filter : ''; ?>"
            class="kpi-card"
            style="text-decoration: none; background: white; padding: 20px 24px; border-radius: 12px; border: 1px solid <?php echo $status_filter === 'Approved' ? '#16a34a' : 'var(--border-color)'; ?>; display: flex; align-items: center; gap: 20px; transition: all 0.2s;">
            <div
                style="width: 44px; height: 44px; background: #f0fdf4; border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                <i class="ph ph-check-circle" style="color: #16a34a; font-size: 22px;"></i>
            </div>
            <div>
                <span
                    style="display: block; font-size: 11px; font-weight: 600; color: var(--text-secondary); text-transform: uppercase;">Approved</span>
                <span
                    style="font-size: 20px; font-weight: 700; color: var(--text-primary);"><?php echo number_format($kpi_stats['approved_count']); ?></span>
            </div>
        </a>
        <a href="?status=Rejected<?php echo $project_id_filter ? '&project_id=' . $project_id_filter : ''; ?>"
            class="kpi-card"
            style="text-decoration: none; background: white; padding: 20px 24px; border-radius: 12px; border: 1px solid <?php echo $status_filter === 'Rejected' ? '#dc2626' : 'var(--border-color)'; ?>; display: flex; align-items: center; gap: 20px; transition: all 0.2s;">
            <div
                style="width: 44px; height: 44px; background: #fef2f2; border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                <i class="ph ph-warning-circle" style="color: #dc2626; font-size: 22px;"></i>
            </div>
            <div>
                <span
                    style="display: block; font-size: 11px; font-weight: 600; color: var(--text-secondary); text-transform: uppercase;">Rejected</span>
                <span
                    style="font-size: 20px; font-weight: 700; color: var(--text-primary);"><?php echo number_format($kpi_stats['rejected_count']); ?></span>
            </div>
        </a>
        <a href="?status=Paid<?php echo $project_id_filter ? '&project_id=' . $project_id_filter : ''; ?>"
            class="kpi-card"
            style="text-decoration: none; background: white; padding: 20px 24px; border-radius: 12px; border: 1px solid <?php echo $status_filter === 'Paid' ? '#1e293b' : 'var(--border-color)'; ?>; display: flex; align-items: center; gap: 20px; transition: all 0.2s;">
            <div
                style="width: 44px; height: 44px; background: #f1f5f9; border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                <i class="ph ph-bank" style="color: #1e293b; font-size: 22px;"></i>
            </div>
            <div>
                <span
                    style="display: block; font-size: 11px; font-weight: 600; color: var(--text-secondary); text-transform: uppercase;">Paid</span>
                <span
                    style="font-size: 20px; font-weight: 700; color: var(--text-primary);"><?php echo number_format($kpi_stats['paid_count']); ?></span>
            </div>
        </a>
    </div>

    <!-- Requests Table Container -->
    <div class="table-container"
        style="background: white; border-radius: 16px; border: 1px solid var(--border-color); overflow: hidden;">
        <!-- Table Filters/Header -->
        <div
            style="padding: 16px 24px 0 24px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: flex-end;">
            <div class="tabs" style="display: flex; gap: 32px;">
                <?php
                $tabs = [
                    'All' => 'All Requests',
                    'Pending' => 'Pending',
                    'Approved' => 'Approved',
                    'Rejected' => 'Rejected',
                    'Paid' => 'Paid'
                ];
                foreach ($tabs as $status => $label):
                    $isActive = ($status_filter === $status);
                    $url = "?status=$status" . ($project_id_filter ? "&project_id=$project_id_filter" : "");
                    ?>
                    <a href="<?php echo $url; ?>" class="tab-item <?php echo $isActive ? 'active' : ''; ?>"
                        style="text-decoration: none; padding-bottom: 16px; font-size: 14px; font-weight: 600; color: <?php echo $isActive ? '#1a56db' : 'var(--text-secondary)'; ?>; border-bottom: 2px solid <?php echo $isActive ? '#1a56db' : 'transparent'; ?>; transition: all 0.2s; display: inline-block;">
                        <?php echo $label; ?>
                    </a>
                <?php endforeach; ?>
            </div>
            <!-- Removed Filter and Export buttons as requested -->
        </div>

        <div class="table-responsive">
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: #fdfdfd; border-bottom: 1px solid var(--border-color);">
                        <th
                            style="text-align: left; padding: 16px 24px; font-size: 12px; font-weight: 600; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.05em;">
                            Request ID</th>
                        <th
                            style="text-align: left; padding: 16px 24px; font-size: 12px; font-weight: 600; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.05em;">
                            Employee</th>
                        <th
                            style="text-align: left; padding: 16px 24px; font-size: 12px; font-weight: 600; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.05em;">
                            Project</th>
                        <th
                            style="text-align: left; padding: 16px 24px; font-size: 12px; font-weight: 600; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.05em;">
                            Type</th>
                        <th
                            style="text-align: left; padding: 16px 24px; font-size: 12px; font-weight: 600; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.05em;">
                            Amount</th>
                        <th
                            style="text-align: left; padding: 16px 24px; font-size: 12px; font-weight: 600; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.05em;">
                            Pending Voucher</th>
                        <th
                            style="text-align: left; padding: 16px 24px; font-size: 12px; font-weight: 600; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.05em;">
                            Date</th>
                        <th
                            style="text-align: left; padding: 16px 24px; font-size: 12px; font-weight: 600; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.05em;">
                            Status</th>
                        <th
                            style="text-align: right; padding: 16px 24px; font-size: 12px; font-weight: 600; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.05em;">
                            Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($requests as $request): ?>
                        <tr style="border-bottom: 1px solid var(--border-color);">
                            <td style="padding: 16px 24px; font-size: 14px; font-weight: 600; color: #1a56db;">
                                <?php echo htmlspecialchars($request['request_no']); ?>
                            </td>
                            <td style="padding: 16px 24px;">
                                <span style="font-size: 14px; font-weight: 500; color: var(--text-primary);">
                                    <?php echo htmlspecialchars($request['employee_name']); ?>
                                </span>
                            </td>
                            <td style="padding: 16px 24px;">
                                <div style="font-size: 13px; font-weight: 500; color: var(--text-primary);">
                                    <?php echo htmlspecialchars($request['project_name'] ?? $request['project_code']); ?>
                                </div>
                                <div style="font-size: 12px; color: var(--text-secondary); margin-top: 2px;">
                                    <?php echo htmlspecialchars($request['project_code']); ?>
                                </div>
                                <?php if (!empty($request['activity_type'])): ?>
                                    <div
                                        style="font-size: 11px; color: #1a56db; font-weight: 600; text-transform: uppercase; margin-top: 4px; display: inline-block; padding: 2px 6px; background: #eff6ff; border-radius: 4px;">
                                        <?php echo htmlspecialchars($request['activity_type']); ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 16px 24px;">
                                <span style="font-size: 13px; color: var(--text-primary); text-transform: capitalize;">
                                    <?php echo htmlspecialchars($request['cost_center'] ?: 'General'); ?>
                                </span>
                            </td>
                            <td style="padding: 16px 24px; font-size: 14px; font-weight: 700; color: var(--text-primary);">₹
                                <?php echo number_format($request['amount'], 2); ?>
                            </td>
                            <td style="padding: 16px 24px; font-size: 14px; font-weight: 600; color: <?php echo ($request['amount'] - $request['invoiced_amount']) > 0 ? '#d97706' : '#16a34a'; ?>;">
                                <?php 
                                if ($request['status'] === 'Pending' || $request['status'] === 'Rejected') {
                                    echo '<span style="color: #94a3b8; font-weight: 400;">-</span>';
                                } else {
                                    echo '₹ ' . number_format($request['amount'] - $request['invoiced_amount'], 2); 
                                }
                                ?>
                            </td>
                            <td style="padding: 16px 24px; font-size: 14px; color: var(--text-secondary);">
                                <?php echo date('M d, Y', strtotime($request['request_date'])); ?>
                            </td>
                            <td style="padding: 16px 24px;">
                                <span style="display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; 
                                <?php
                                if ($request['status'] === 'Approved')
                                    echo 'background: #f0fdf4; color: #16a34a;';
                                elseif ($request['status'] === 'Pending')
                                    echo 'background: #fffbeb; color: #d97706;';
                                elseif ($request['status'] === 'Paid')
                                    echo 'background: #f1f5f9; color: #1e293b;';
                                else
                                    echo 'background: #fef2f2; color: #dc2626;';
                                ?>">
                                    <i class="ph-fill ph-circle" style="font-size: 8px;"></i>
                                    <?php echo $request['status']; ?>
                                </span>
                            </td>
                            <td style="padding: 16px 24px; text-align: right;">
                                <a href="payment_request_details.php?id=<?php echo $request['id']; ?>"
                                    style="display: inline-flex; margin-left: auto; text-decoration: none; color: #94a3b8;">
                                    <i class="ph ph-eye" style="font-size: 20px;"></i>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Table Footer/Pagination -->
        <div
            style="padding: 20px 24px; display: flex; justify-content: space-between; align-items: center; background: #fdfdfd; border-top: 1px solid var(--border-color);">
            <div
                style="font-size: 13px; font-weight: 600; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.05em;">
                Showing
                <?php echo $offset + 1; ?> to
                <?php echo min($offset + $limit, $total_rows); ?> of
                <?php echo number_format($total_rows); ?> Results
            </div>
            <div style="display: flex; gap: 8px;">
                <a href="?status=<?php echo $status_filter; ?>&page=<?php echo max(1, $page - 1); ?><?php echo $project_id_filter ? '&project_id=' . $project_id_filter : ''; ?>"
                    style="padding: 8px 16px; border: 1px solid var(--border-color); border-radius: 8px; font-size: 13px; font-weight: 600; color: var(--text-secondary); text-decoration: none; background: white;">Previous</a>
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <a href="?status=<?php echo $status_filter; ?>&page=<?php echo $i; ?><?php echo $project_id_filter ? '&project_id=' . $project_id_filter : ''; ?>"
                        style="width: 36px; height: 36px; display: flex; align-items: center; justify-content: center; border-radius: 8px; font-size: 13px; font-weight: 600; text-decoration: none; 
                        <?php echo $i === $page ? 'background: #1a56db; color: white; border: none;' : 'background: white; color: var(--text-secondary); border: 1px solid var(--border-color);'; ?>">
                        <?php echo $i; ?>
                    </a>
                <?php endfor; ?>
                <a href="?status=<?php echo $status_filter; ?>&page=<?php echo min($total_pages, $page + 1); ?><?php echo $project_id_filter ? '&project_id=' . $project_id_filter : ''; ?>"
                    style="padding: 8px 16px; border: 1px solid var(--border-color); border-radius: 8px; font-size: 13px; font-weight: 600; color: var(--text-secondary); text-decoration: none; background: white;">Next</a>
            </div>
        </div>
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
                            <select name="project_id" id="requestProjectSelect" required
                                style="width: 100%; padding: 10px 12px; background: white; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 14px; color: var(--text-primary); outline: none; appearance: none; opacity: 1;">
                                <option value="">Select Project</option>
                                <?php foreach ($active_projects as $p): ?>
                                    <option value="<?php echo $p['id']; ?>">
                                        <?php echo htmlspecialchars($p['project_name'] ?: $p['project_code']); ?>
                                    </option>
                                <?php endforeach; ?>
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
            
            if (prForm) {
                const prSubmitBtn = prForm.querySelector('button[type="submit"]');
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

            // Dynamic Budget Loading Logic
            const projectSelect = document.getElementById('requestProjectSelect');
            const typeSelect = document.getElementById('requestTypeSelect');
            const amountInput = document.getElementById('requestAmountInput');
            const limitBadge = document.getElementById('availableLimitBadge');
            const employeeLimit = <?php echo (float)$available_request_limit; ?>;

            function updateAvailableLimit() {
                const projectId = projectSelect ? projectSelect.value : null;
                const requestType = typeSelect ? typeSelect.value : null;
                
                if (!projectId) {
                    limitBadge.textContent = 'Available: ₹' + employeeLimit.toLocaleString('en-IN', {minimumFractionDigits: 2});
                    amountInput.max = employeeLimit;
                    return;
                }

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

            if (projectSelect) projectSelect.addEventListener('change', updateAvailableLimit);
            if (typeSelect) typeSelect.addEventListener('change', updateAvailableLimit);

            window.addEventListener('click', (e) => {
                if (e.target === prModal) closePRModalFunc();
            });
        });
    </script>
<?php include 'includes/app_footer.php'; ?>