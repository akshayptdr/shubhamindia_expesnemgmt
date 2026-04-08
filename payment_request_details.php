<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

require 'includes/db.php';

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: payment_requests.php");
    exit;
}

$request_id = (int) $_GET['id'];

// Fetch payment request details
$sql = "SELECT pr.*, e.name as employee_name, e.avatar as employee_avatar, e.role as employee_role,
               p.project_name, p.project_code, p.budget as project_budget,
               r.name as reviewer_name
        FROM payment_requests pr
        LEFT JOIN employees e ON pr.employee_id = e.id
        LEFT JOIN projects p ON pr.project_id = p.id
        LEFT JOIN employees r ON pr.reviewed_by = r.id
        WHERE pr.id = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute([$request_id]);
$request = $stmt->fetch();

if (!$request) {
    header("Location: payment_requests.php");
    exit;
}

// Fetch associated invoices
$stmt_invoices = $pdo->prepare("SELECT * FROM payment_request_invoices WHERE payment_request_id = ? ORDER BY invoice_date DESC");
$stmt_invoices->execute([$request_id]);
$invoices = $stmt_invoices->fetchAll();

// Calculate remaining invoice amount
$total_invoiced_amount = array_sum(array_column($invoices, 'amount'));
$remaining_invoiceable_amount = max(0, $request['amount'] - $total_invoiced_amount);

// Calculate remaining budget for Quick Audit
$stmt_sum = $pdo->prepare("SELECT SUM(amount) as total_requested FROM payment_requests WHERE project_id = ? AND status != 'Rejected'");
$stmt_sum->execute([$request['project_id']]);
$sum_row = $stmt_sum->fetch();
$total_requested = (float) ($sum_row['total_requested'] ?? 0);
$remaining_budget = $request['project_budget'] - $total_requested;

$page_title = 'Payment Request #' . $request['request_no'];
$current_page = 'payments';
include 'includes/app_header.php';
?>

<div class="page-content" style="padding-top: 24px;">
    <!-- Top Header Navigation -->
    <div style="margin-bottom: 32px;">
        <div class="breadcrumb"
            style="font-size: 11px; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.1em; display: flex; align-items: center; gap: 8px; margin-bottom: 12px;">
            <a href="index.php" style="color: inherit; text-decoration: none; opacity: 0.6;">Main Dashboard</a>
            <i class="ph ph-caret-right" style="font-size: 10px; opacity: 0.4;"></i>
            <a href="payment_requests.php" style="color: inherit; text-decoration: none; opacity: 0.6;">Payment
                Requests</a>
            <i class="ph ph-caret-right" style="font-size: 10px; opacity: 0.4;"></i>
            <span><?php echo htmlspecialchars($request['request_no']); ?></span>
        </div>

        <div style="display: flex; justify-content: space-between; align-items: flex-start;">
            <div>
                <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 8px;">
                    <span style="padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em;
                        <?php
                        if ($request['status'] === 'Approved')
                            echo 'background: #f0fdf4; color: #16a34a; border: 1px solid #dcfce7;';
                        elseif ($request['status'] === 'Pending')
                            echo 'background: #fffbeb; color: #d97706; border: 1px solid #fef3c7;';
                        elseif ($request['status'] === 'Paid')
                            echo 'background: #f1f5f9; color: #1e293b; border: 1px solid #e2e8f0;';
                        else
                            echo 'background: #fef2f2; color: #dc2626; border: 1px solid #fee2e2;';
                        ?>">
                        <?php
                        if ($request['status'] === 'Paid') {
                            echo 'PAID';
                        } else {
                            echo strtoupper($request['status']) . ' APPROVAL';
                        }
                        ?>
                    </span>
                    <span style="font-size: 13px; color: var(--text-secondary);">
                        Submitted
                        <?php echo date('M d, Y', strtotime($request['request_date'])); ?>
                    </span>
                </div>
                <h1 style="font-size: 28px; font-weight: 700; color: var(--text-primary); margin: 0;">Request
                    <?php echo htmlspecialchars($request['request_no']); ?>
                </h1>
            </div>

            <div style="display: flex; align-items: center; gap: 12px;">
                <?php if ($request['status'] === 'Pending'): ?>
                    <div style="display: flex; gap: 8px;">
                        <button onclick="updatePaymentStatus('Approved')"
                            style="background: #16a34a; color: white; border: none; border-radius: 8px; padding: 10px 16px; font-size: 13px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 8px; transition: opacity 0.2s;">
                            <i class="ph ph-check-circle" style="font-size: 18px;"></i> Approve
                        </button>
                        <button onclick="updatePaymentStatus('Rejected')"
                            style="background: #dc2626; color: white; border: none; border-radius: 8px; padding: 10px 16px; font-size: 13px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 8px; transition: opacity 0.2s;">
                            <i class="ph ph-x-circle" style="font-size: 18px;"></i> Reject
                        </button>
                    </div>
                <?php elseif ($request['status'] === 'Approved'): ?>
                    <div style="display: flex; gap: 8px;">
                        <button onclick="updatePaymentStatus('Paid')"
                            style="background: #1e293b; color: white; border: none; border-radius: 8px; padding: 10px 16px; font-size: 13px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 8px; transition: opacity 0.2s;">
                            <i class="ph ph-bank" style="font-size: 18px;"></i> Payment Done
                        </button>
                    </div>
                <?php endif; ?>

                <div
                    style="background: white; padding: 16px 24px; border-radius: 12px; border: 1px solid var(--border-color); display: flex; align-items: center; gap: 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                    <div
                        style="width: 40px; height: 40px; background: #eff6ff; border-radius: 10px; display: flex; align-items: center; justify-content: center; color: #1a56db;">
                        <i class="ph ph-wallet" style="font-size: 20px;"></i>
                    </div>
                    <div>
                        <div
                            style="font-size: 11px; font-weight: 600; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.05em;">
                            Total Amount</div>
                        <div style="font-size: 20px; font-weight: 700; color: #1a56db;">₹
                            <?php echo number_format($request['amount'], 2); ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="details-grid" style="display: grid; grid-template-columns: 1fr 340px; gap: 24px;">
        <!-- Left Column -->
        <div style="display: flex; flex-direction: column; gap: 24px;">
            <!-- Request Summary Card -->
            <div class="card"
                style="background: white; border: 1px solid var(--border-color); border-radius: 16px; overflow: hidden;">
                <div
                    style="padding: 24px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center;">
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <i class="ph ph-info" style="font-size: 20px; color: #1a56db;"></i>
                        <h2 style="font-size: 16px; font-weight: 600; color: var(--text-primary); margin: 0;">Request
                            Summary</h2>
                    </div>
                </div>

                <div style="padding: 24px; display: grid; grid-template-columns: 1fr 1fr; gap: 32px;">
                    <div>
                        <div style="margin-bottom: 24px;">
                            <label
                                style="display: block; font-size: 11px; font-weight: 600; color: var(--text-secondary); text-transform: uppercase; margin-bottom: 8px;">Employee
                                Name</label>
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <img src="<?php echo $request['employee_avatar'] ?: 'assets/images/default-avatar.png'; ?>"
                                    style="width: 28px; height: 28px; border-radius: 50%;">
                                <span style="font-size: 14px; font-weight: 500; color: var(--text-primary);">
                                    <?php echo htmlspecialchars($request['employee_name']); ?>
                                </span>
                            </div>
                        </div>

                        <div style="margin-bottom: 24px;">
                            <label
                                style="display: block; font-size: 11px; font-weight: 600; color: var(--text-secondary); text-transform: uppercase; margin-bottom: 8px;">Type</label>
                            <span
                                style="font-size: 14px; font-weight: 500; color: var(--text-primary); text-transform: capitalize;">
                                <?php echo htmlspecialchars($request['cost_center'] ?: 'General'); ?>
                            </span>
                        </div>



                        <div style="margin-bottom: 24px;">
                            <label
                                style="display: block; font-size: 11px; font-weight: 600; color: var(--text-secondary); text-transform: uppercase; margin-bottom: 8px;">Payment
                                Reference</label>
                            <span style="font-size: 14px; font-weight: 600; color: var(--text-primary);">
                                <?php
                                if ($request['status'] === 'Paid') {
                                    echo htmlspecialchars($request['payment_reference'] ?: '#REF-' . (1000 + $request['id']));
                                } else {
                                    echo '-';
                                }
                                ?>
                            </span>
                        </div>
                    </div>

                    <div>
                        <div style="margin-bottom: 24px;">
                            <label
                                style="display: block; font-size: 11px; font-weight: 600; color: var(--text-secondary); text-transform: uppercase; margin-bottom: 8px;">Project</label>
                            <span style="font-size: 14px; font-weight: 500; color: var(--text-primary);">
                                <?php echo htmlspecialchars($request['project_name']); ?>
                            </span>
                        </div>

                        <div style="margin-bottom: 24px;">
                            <label
                                style="display: block; font-size: 11px; font-weight: 600; color: var(--text-secondary); text-transform: uppercase; margin-bottom: 8px;">Payment
                                Method</label>
                            <span style="font-size: 14px; color: var(--text-primary);">
                                <?php
                                if ($request['status'] === 'Paid') {
                                    echo htmlspecialchars($request['payment_method'] ?: 'Bank Transfer');
                                } else {
                                    echo '-';
                                }
                                ?>
                            </span>
                        </div>
                    </div>

                    <div style="grid-column: span 2;">
                        <label
                            style="display: block; font-size: 11px; font-weight: 600; color: var(--text-secondary); text-transform: uppercase; margin-bottom: 8px;">Purpose
                            / Description</label>
                        <p style="font-size: 13px; color: var(--text-secondary); line-height: 1.6; margin: 0;">
                            <?php echo nl2br(htmlspecialchars($request['purpose'])); ?>
                        </p>
                    </div>
                </div>
            </div>

            <!-- Invoices Card -->
            <div class="card"
                style="background: white; border: 1px solid var(--border-color); border-radius: 16px; overflow: hidden;">
                <div
                    style="padding: 20px 24px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center;">
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <i class="ph ph-files" style="font-size: 20px; color: #1a56db;"></i>
                        <h2 style="font-size: 16px; font-weight: 600; color: var(--text-primary); margin: 0;">Payment
                            Invoices Added</h2>
                    </div>
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <?php if ($request['status'] !== 'Pending' && $request['status'] !== 'Rejected'): ?>
                            <button class="btn-primary" onclick="openInvoiceModal()"
                                style="background: #1a56db; color: white; border: none; font-weight: 600; font-size: 13px; padding: 8px 16px; border-radius: 8px; cursor: pointer; display: flex; align-items: center; gap: 6px;">
                                <i class="ph ph-plus" style="font-size: 16px;"></i> Add New Invoice
                            </button>
                        <?php else: ?>
                            <button class="btn-primary" disabled title="Invoices can only be added after approval"
                                style="background: #94a3b8; color: white; border: none; font-weight: 600; font-size: 13px; padding: 8px 16px; border-radius: 8px; cursor: not-allowed; display: flex; align-items: center; gap: 6px; opacity: 0.7;">
                                <i class="ph ph-plus" style="font-size: 16px;"></i> Add New Invoice
                            </button>
                        <?php endif; ?>

                    </div>
                </div>

                <div class="table-responsive">
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="background: #f8fafc; border-bottom: 1px solid var(--border-color);">
                                <th
                                    style="text-align: left; padding: 12px 24px; font-size: 11px; font-weight: 600; color: var(--text-secondary); text-transform: uppercase;">
                                    Invoice #</th>
                                <th
                                    style="text-align: left; padding: 12px 24px; font-size: 11px; font-weight: 600; color: var(--text-secondary); text-transform: uppercase;">
                                    Invoice Date</th>
                                <th
                                    style="text-align: left; padding: 12px 24px; font-size: 11px; font-weight: 600; color: var(--text-secondary); text-transform: uppercase;">
                                    Added Date</th>
                                <th
                                    style="text-align: left; padding: 12px 24px; font-size: 11px; font-weight: 600; color: var(--text-secondary); text-transform: uppercase;">
                                    Vendor</th>
                                <th
                                    style="text-align: left; padding: 12px 24px; font-size: 11px; font-weight: 600; color: var(--text-secondary); text-transform: uppercase;">
                                    Type / Detail</th>
                                <th
                                    style="text-align: left; padding: 12px 24px; font-size: 11px; font-weight: 600; color: var(--text-secondary); text-transform: uppercase;">
                                    Amount</th>
                                <th
                                    style="text-align: left; padding: 12px 24px; font-size: 11px; font-weight: 600; color: var(--text-secondary); text-transform: uppercase;">
                                    Remark</th>
                                <th
                                    style="text-align: right; padding: 12px 24px; font-size: 11px; font-weight: 600; color: var(--text-secondary); text-transform: uppercase;">
                                    Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($invoices)): ?>
                                <tr>
                                    <td colspan="8"
                                        style="padding: 32px; text-align: center; color: var(--text-secondary); font-size: 14px;">
                                        No invoices added yet. Click "Add New Invoice" to upload receipts.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($invoices as $inv): ?>
                                    <tr style="border-bottom: 1px solid var(--border-color);">
                                        <td
                                            style="padding: 16px 24px; font-size: 14px; font-weight: 500; color: var(--text-primary);">
                                            <div style="display: flex; align-items: center; gap: 10px;">
                                                <?php echo htmlspecialchars($inv['invoice_no']); ?>
                                            </div>
                                        </td>
                                        <td style="padding: 16px 24px; font-size: 14px; color: var(--text-secondary);">
                                            <?php 
                                            if (empty($inv['invoice_date']) || $inv['invoice_date'] === '0000-00-00' || date('Y', strtotime($inv['invoice_date'])) === '1970') {
                                                echo '';
                                            } else {
                                                echo date('M d, Y', strtotime($inv['invoice_date']));
                                            }
                                            ?>
                                        </td>
                                        <td style="padding: 16px 24px; font-size: 14px; color: var(--text-secondary);">
                                            <?php echo !empty($inv['created_at']) ? date('M d, Y', strtotime($inv['created_at'])) : '-'; ?>
                                        </td>
                                        <td style="padding: 16px 24px; font-size: 14px; color: var(--text-secondary);">
                                            <?php echo htmlspecialchars($inv['vendor']); ?>
                                        </td>
                                        <td style="padding: 16px 24px; font-size: 14px; color: var(--text-secondary);">
                                            <div style="font-weight: 500; color: var(--text-primary);"><?php echo htmlspecialchars($inv['expense_type'] ?: '-'); ?></div>
                                            <?php if ($inv['expense_subtype']): ?>
                                                <div style="font-size: 12px; margin-top: 2px; color: var(--text-secondary);">
                                                    <?php echo htmlspecialchars($inv['expense_subtype']); ?>
                                                    <?php if ($inv['expense_detail']): ?>
                                                        • <?php echo htmlspecialchars($inv['expense_detail']); ?>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (!empty($inv['km']) && !empty($inv['rate'])): ?>
                                                <div style="font-size: 11px; color: #1a56db; margin-top: 2px; font-weight: 500;">
                                                    <?php echo (float)$inv['km']; ?> km @ ₹<?php echo (float)$inv['rate']; ?>/km
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td
                                            style="padding: 16px 24px; font-size: 14px; font-weight: 600; color: var(--text-primary);">
                                            ₹
                                            <?php echo number_format($inv['amount'], 2); ?>
                                        </td>
                                        <td style="padding: 16px 24px; font-size: 13px; color: var(--text-secondary);">
                                            <?php echo htmlspecialchars($inv['remarks'] ?: '-'); ?>
                                        </td>
                                        <td style="padding: 16px 24px; text-align: right;">
                                            <div style="display: flex; justify-content: flex-end; gap: 12px; color: #1a56db;">
                                                <a href="<?php echo htmlspecialchars($inv['file_path']); ?>" target="_blank"
                                                    style="color: inherit;">
                                                    <i class="ph ph-eye" style="font-size: 20px; cursor: pointer;"></i>
                                                </a>
                                                <a href="<?php echo htmlspecialchars($inv['file_path']); ?>" download
                                                    style="color: inherit;">
                                                    <i class="ph ph-download-simple"
                                                        style="font-size: 20px; cursor: pointer;"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Right Column -->
        <div style="display: flex; flex-direction: column; gap: 24px;">
            <!-- Approval Progress Card -->
            <div class="card"
                style="background: white; border: 1px solid var(--border-color); border-radius: 16px; padding: 24px;">
                <h2 style="font-size: 15px; font-weight: 600; color: var(--text-primary); margin: 0 0 20px 0;">Approval
                    Progress</h2>

                <div style="display: flex; flex-direction: column; gap: 0; position: relative; padding-left: 36px;">
                    <!-- Timeline Line -->
                    <div
                        style="position: absolute; left: 13px; top: 10px; bottom: 10px; width: 2px; background: #e2e8f0;">
                    </div>

                    <!-- Step 1: Submitted -->
                    <div style="position: relative; padding-bottom: 24px;">
                        <div
                            style="position: absolute; left: -36px; top: 0; width: 28px; height: 28px; background: #1a56db; border-radius: 50%; color: white; display: flex; align-items: center; justify-content: center; z-index: 1;">
                            <i class="ph ph-check" style="font-size: 14px;"></i>
                        </div>
                        <div style="font-size: 13px; font-weight: 600; color: var(--text-primary);">Request Submitted
                        </div>
                        <div style="font-size: 11px; color: var(--text-secondary); margin-top: 2px;">
                            <?php echo htmlspecialchars($request['employee_name']); ?> •
                            <?php echo date('M d, H:i A', strtotime($request['created_at'])); ?>
                        </div>
                    </div>

                    <!-- Step 2: Manager Approval -->
                    <div style="position: relative; padding-bottom: 24px;">
                        <?php if ($request['status'] === 'Approved' || $request['status'] === 'Rejected' || $request['status'] === 'Paid'): ?>
                            <div
                                style="position: absolute; left: -36px; top: 0; width: 28px; height: 28px; background: <?php echo ($request['status'] === 'Approved' || $request['status'] === 'Paid') ? '#1a56db' : '#dc2626'; ?>; border-radius: 50%; color: white; display: flex; align-items: center; justify-content: center; z-index: 1;">
                                <i class="ph ph-<?php echo ($request['status'] === 'Approved' || $request['status'] === 'Paid') ? 'check' : 'x'; ?>"
                                    style="font-size: 14px;"></i>
                            </div>
                            <div style="font-size: 13px; font-weight: 600; color: var(--text-primary);">Manager Approval
                            </div>
                            <div style="font-size: 11px; color: var(--text-secondary); margin-top: 2px;">
                                <?php echo ($request['status'] === 'Approved' || $request['status'] === 'Paid') ? 'Approved' : 'Rejected'; ?>
                                by
                                <?php echo htmlspecialchars($request['reviewer_name'] ?? 'System Administrator'); ?>
                                <?php if (!empty($request['approved_at'])): ?>
                                    • <?php echo date('M d, H:i A', strtotime($request['approved_at'])); ?>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div
                                style="position: absolute; left: -36px; top: 0; width: 28px; height: 28px; background: white; border: 2px solid #e2e8f0; border-radius: 50%; display: flex; align-items: center; justify-content: center; z-index: 1; color: #94a3b8;">
                                <i class="ph ph-clock" style="font-size: 14px;"></i>
                            </div>
                            <div style="font-size: 13px; font-weight: 600; color: #64748b;">Manager Approval</div>
                            <div style="font-size: 11px; color: #94a3b8; margin-top: 2px;">Awaiting review</div>
                        <?php endif; ?>
                    </div>

                    <!-- Step 3: Payment Processed -->
                    <div style="position: relative;">
                        <?php if ($request['status'] === 'Paid'): ?>
                            <div
                                style="position: absolute; left: -36px; top: 0; width: 28px; height: 28px; background: #1a56db; border-radius: 50%; color: white; display: flex; align-items: center; justify-content: center; z-index: 1;">
                                <i class="ph ph-check" style="font-size: 14px;"></i>
                            </div>
                            <div style="font-size: 13px; font-weight: 600; color: var(--text-primary);">Payment Completed
                            </div>
                            <div style="font-size: 11px; color: var(--text-secondary); margin-top: 2px;">
                                Processed by
                                <?php echo htmlspecialchars($request['reviewer_name'] ?? 'System Administrator'); ?>
                                <?php if (!empty($request['paid_at'])): ?>
                                    • <?php echo date('M d, H:i A', strtotime($request['paid_at'])); ?>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div
                                style="position: absolute; left: -36px; top: 0; width: 28px; height: 28px; background: white; border: 2px solid #e2e8f0; border-radius: 50%; display: flex; align-items: center; justify-content: center; z-index: 1; color: #94a3b8;">
                                <i class="ph ph-clock" style="font-size: 14px;"></i>
                            </div>
                            <div style="font-size: 13px; font-weight: 600; color: #64748b;">Payment Completed</div>
                            <div style="font-size: 11px; color: #94a3b8; margin-top: 2px;">Awaiting approval</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Quick Audit Card Removed -->
        </div>
    </div>
    <!-- Payment Completion Modal -->
    <div id="paymentModal" class="modal-overlay">
        <div class="modal-content"
            style="max-width: 500px; padding: 0; border-radius: 16px; overflow: hidden; position: relative;">
            <div
                style="padding: 24px; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center;">
                <div style="display: flex; align-items: center; gap: 16px;">
                    <div
                        style="width: 40px; height: 40px; background: #eff6ff; border-radius: 10px; display: flex; align-items: center; justify-content: center; color: #1a56db;">
                        <i class="ph ph-check-square" style="font-size: 20px;"></i>
                    </div>
                    <h2 style="font-size: 18px; font-weight: 700; color: #1e293b; margin: 0;">Confirm Payment Completion
                    </h2>
                </div>
                <button onclick="closePaymentModal()"
                    style="background: none; border: none; color: #94a3b8; cursor: pointer; font-size: 20px;">
                    <i class="ph ph-x"></i>
                </button>
            </div>

            <form id="paymentConfirmForm" onsubmit="handlePaymentSubmit(event)" style="padding: 24px;">
                <input type="hidden" id="pay_request_id" name="id">

                <p style="font-size: 14px; color: #64748b; line-height: 1.5; margin-bottom: 24px;">
                    Provide the transaction details below to mark this payment request as completed. This action will
                    notify the recipient.
                </p>

                <div style="margin-bottom: 20px;">
                    <label
                        style="display: block; font-size: 13px; font-weight: 600; color: #475569; margin-bottom: 8px;">Payment
                        Reference</label>
                    <div style="position: relative;">
                        <i class="ph ph-hash"
                            style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #94a3b8;"></i>
                        <input type="text" id="pay_reference" name="reference" placeholder="e.g., TXN-12345678" required
                            style="width: 100%; padding: 12px 12px 12px 36px; border: 1px solid #e2e8f0; border-radius: 10px; font-size: 14px; outline: none; transition: border-color 0.2s;">
                    </div>
                </div>

                <div style="margin-bottom: 24px;">
                    <label
                        style="display: block; font-size: 13px; font-weight: 600; color: #475569; margin-bottom: 8px;">Payment
                        Method</label>
                    <div style="position: relative;">
                        <i class="ph ph-credit-card"
                            style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #94a3b8;"></i>
                        <select id="pay_method" name="method" required
                            style="width: 100%; padding: 12px 12px 12px 36px; border: 1px solid #e2e8f0; border-radius: 10px; font-size: 14px; outline: none; appearance: none; background: white;">
                            <option value="" disabled selected>Select Payment Method</option>
                            <option value="Bank Transfer">Bank Transfer</option>
                            <option value="UPI">UPI</option>
                            <option value="Cash">Cash</option>
                            <option value="Cheque">Cheque</option>
                        </select>
                        <i class="ph ph-caret-down"
                            style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%); color: #94a3b8; pointer-events: none;"></i>
                    </div>
                </div>

                <div
                    style="background: #f0f7ff; border: 1px solid #e0effe; border-radius: 10px; padding: 16px; margin-bottom: 24px; display: flex; gap: 12px; align-items: flex-start;">
                    <i class="ph ph-info" style="color: #1a56db; font-size: 18px; margin-top: 2px;"></i>
                    <p style="font-size: 12px; color: #1e40af; margin: 0; line-height: 1.5;">
                        Verification of the reference code may take up to 24 hours depending on the bank network.
                    </p>
                </div>

                <div style="display: flex; gap: 12px; justify-content: flex-end; padding-top: 12px;">
                    <button type="button" onclick="closePaymentModal()"
                        style="padding: 10px 24px; border: 1px solid #e2e8f0; border-radius: 10px; background: white; color: #475569; font-size: 14px; font-weight: 600; cursor: pointer;">
                        Cancel
                    </button>
                    <button type="submit"
                        style="padding: 10px 24px; border: none; border-radius: 10px; background: #1a56db; color: white; font-size: 14px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 8px;">
                        Submit Payment <i class="ph ph-paper-plane-tilt" style="font-size: 18px;"></i>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function updatePaymentStatus(status) {
            if (status === 'Paid') {
                document.getElementById('pay_request_id').value = <?php echo (int) $request['id']; ?>;
                document.getElementById('paymentModal').style.display = 'flex';
                return;
            }

            if (!confirm(`Are you sure you want to ${status.toLowerCase()} this payment request?`)) {
                return;
            }

            performStatusUpdate(status);
        }

        function closePaymentModal() {
            document.getElementById('paymentModal').style.display = 'none';
            document.getElementById('paymentConfirmForm').reset();
        }

        function handlePaymentSubmit(e) {
            e.preventDefault();
            const requestId = document.getElementById('pay_request_id').value;
            const reference = document.getElementById('pay_reference').value;
            const mode = document.getElementById('pay_method').value;

            performStatusUpdate('Paid', { reference, mode });
        }

        function performStatusUpdate(status, additionalData = {}) {
            const requestId = <?php echo (int) $request['id']; ?>;

            const payload = {
                id: requestId,
                status: status,
                ...additionalData
            };

            fetch('handlers/update_payment_status.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(payload)
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    } else {
                        alert('Error: ' + data.error);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred while updating the status.');
                });
        }
    </script>



    <!-- Add Invoice Modal -->
    <div id="invoiceModal"
        style="display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); backdrop-filter: blur(4px); overflow-y: auto;">
        <div
            style="background-color: white; margin: 40px auto; padding: 0; border: none; width: 95%; max-width: 650px; border-radius: 20px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1), 0 10px 10px -5px rgba(0,0,0,0.04); overflow: hidden; animation: modalSlideUp 0.3s ease-out; margin-bottom: 40px;">
            <!-- Modal Header -->
            <div
                style="padding: 20px 24px; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center; background: white;">
                <h2 style="font-size: 18px; font-weight: 700; color: #1e293b; margin: 0;">Add New Invoice</h2>
                <button onclick="closeInvoiceModal()"
                    style="background: none; border: none; color: #64748b; cursor: pointer; padding: 4px; border-radius: 6px; transition: background 0.2s;">
                    <i class="ph ph-x" style="font-size: 20px;"></i>
                </button>
            </div>

            <!-- Modal Body -->
            <form id="invoiceForm" onsubmit="handleInvoiceSubmit(event)" enctype="multipart/form-data"
                style="padding: 24px;">
                <input type="hidden" name="payment_request_id" value="<?php echo (int) $request['id']; ?>">

                <div style="margin-bottom: 20px;">
                    <label
                        style="display: block; font-size: 13px; font-weight: 600; color: #475569; margin-bottom: 8px;">Invoice
                        Number</label>
                    <input type="text" name="invoice_no" placeholder="e.g. INV-2024-002"
                        style="width: 100%; padding: 10px 14px; border: 1px solid #e2e8f0; border-radius: 10px; font-size: 14px; outline: none; transition: border-color 0.2s;">
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 20px;">
                    <div>
                        <label
                            style="display: block; font-size: 13px; font-weight: 600; color: #475569; margin-bottom: 8px;">Invoice
                            Date</label>
                        <input type="date" name="invoice_date"
                            style="width: 100%; padding: 10px 14px; border: 1px solid #e2e8f0; border-radius: 10px; font-size: 14px; outline: none;">
                    </div>
                    <div>
                        <label
                            style="display: block; font-size: 13px; font-weight: 600; color: #475569; margin-bottom: 8px;">Vendor
                            Name</label>
                        <input type="text" name="vendor" placeholder="Acme Corp"
                            style="width: 100%; padding: 10px 14px; border: 1px solid #e2e8f0; border-radius: 10px; font-size: 14px; outline: none;">
                    </div>
                </div>

                <div style="margin-bottom: 20px;">
                    <label
                        style="display: block; font-size: 13px; font-weight: 600; color: #475569; margin-bottom: 8px;">Expense Type</label>
                    <div style="position: relative;">
                        <select name="expense_type" id="mainExpenseType" required onchange="handleMainTypeChange()"
                            style="width: 100%; padding: 10px 14px; border: 1px solid #e2e8f0; border-radius: 10px; font-size: 14px; outline: none; appearance: none; background: white;">
                            <option value="" disabled selected>Select Expense Type</option>
                            <option value="Travel Expenses">Travel Expenses</option>
                            <option value="FOOD & REFRESHMENT">FOOD & REFRESHMENT</option>
                            <option value="ACCOMMODATION">ACCOMMODATION</option>
                            <option value="MATERIAL EXPENSES">MATERIAL EXPENSES</option>
                            <option value="OTHER / MISCELLANEOUS">OTHER / MISCELLANEOUS</option>
                            <option value="LABOUR">LABOUR</option>
                        </select>
                        <i class="ph ph-caret-down"
                            style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%); color: #64748b; font-size: 14px; pointer-events: none;"></i>
                    </div>
                </div>

                <div id="subtypeContainer" style="margin-bottom: 20px; display: none;">
                    <label id="subtypeLabel"
                        style="display: block; font-size: 13px; font-weight: 600; color: #475569; margin-bottom: 8px;">Sub-type</label>
                    <div style="position: relative;">
                        <select name="expense_subtype" id="expenseSubtype" onchange="handleSubtypeChange()"
                            style="width: 100%; padding: 10px 14px; border: 1px solid #e2e8f0; border-radius: 10px; font-size: 14px; outline: none; appearance: none; background: white;">
                            <option value="" disabled selected>Select Sub-type</option>
                        </select>
                        <i class="ph ph-caret-down"
                            style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%); color: #64748b; font-size: 14px; pointer-events: none;"></i>
                    </div>
                </div>

                <div id="detailContainer" style="margin-bottom: 20px; display: none;">
                    <label id="detailLabel"
                        style="display: block; font-size: 13px; font-weight: 600; color: #475569; margin-bottom: 8px;">Detail</label>
                    <div style="position: relative;">
                        <select name="expense_detail" id="expenseDetail" onchange="handleDetailChange()"
                            style="width: 100%; padding: 10px 14px; border: 1px solid #e2e8f0; border-radius: 10px; font-size: 14px; outline: none; appearance: none; background: white;">
                            <option value="" disabled selected>Select Detail</option>
                        </select>
                        <i class="ph ph-caret-down"
                            style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%); color: #64748b; font-size: 14px; pointer-events: none;"></i>
                    </div>
                </div>

                <div id="petrolDetails" style="margin-bottom: 20px; display: none; background: #f8fafc; padding: 16px; border-radius: 12px; border: 1px solid #e2e8f0;">
                    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px;">
                        <div>
                            <label style="display: block; font-size: 11px; font-weight: 600; color: #64748b; text-transform: uppercase; margin-bottom: 4px;">Km</label>
                            <input type="number" step="0.1" name="km" id="kmInput" oninput="calculatePetrolAmount()" placeholder="0.0"
                                style="width: 100%; padding: 8px 12px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 13px; outline: none;">
                        </div>
                        <div>
                            <label style="display: block; font-size: 11px; font-weight: 600; color: #64748b; text-transform: uppercase; margin-bottom: 4px;">Rate</label>
                            <input type="number" step="0.1" name="rate" id="rateInput" oninput="calculatePetrolAmount()" placeholder="0.0"
                                style="width: 100%; padding: 8px 12px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 13px; outline: none;">
                        </div>
                        <div>
                            <label style="display: block; font-size: 11px; font-weight: 600; color: #64748b; text-transform: uppercase; margin-bottom: 4px;">Vehicle</label>
                            <select name="vehicle_type" id="vehicleType"
                                style="width: 100%; padding: 8px 12px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 13px; outline: none; appearance: none; background: white;">
                                <option value="Bike">Bike</option>
                                <option value="Car">Car</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 20px;">
                    <div>
                        <label
                            style="display: block; font-size: 13px; font-weight: 600; color: #475569; margin-bottom: 8px;">Select
                            Project</label>
                        <div style="position: relative;">
                            <select disabled
                                style="width: 100%; padding: 10px 14px; border: 1px solid #e2e8f0; border-radius: 10px; font-size: 14px; outline: none; background: #f8fafc; appearance: none;">
                                <option selected><?php echo htmlspecialchars($request['project_name']); ?></option>
                            </select>
                            <i class="ph ph-caret-down"
                                style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%); color: #64748b; font-size: 14px;"></i>
                        </div>
                    </div>
                    <div>
                        <label
                            style="display: block; font-size: 13px; font-weight: 600; color: #475569; margin-bottom: 8px;">Amount</label>
                        <div style="position: relative;">
                            <span
                                style="position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: #64748b; font-size: 14px;">₹</span>
                            <input type="number" step="0.01" name="amount" placeholder="0.00" required
                                max="<?php echo $remaining_invoiceable_amount; ?>"
                                style="width: 100%; padding: 10px 14px 10px 30px; border: 1px solid #e2e8f0; border-radius: 10px; font-size: 14px; outline: none;">
                        </div>
                        <div style="font-size: 11px; color: #64748b; margin-top: 4px;">Max allowed: ₹<?php echo number_format($remaining_invoiceable_amount, 2); ?></div>
                    </div>
                </div>

                <div style="margin-bottom: 20px;">
                    <label
                        style="display: block; font-size: 13px; font-weight: 600; color: #475569; margin-bottom: 8px;">Remark
                        / Notes</label>
                    <textarea name="remarks" placeholder="Optional notes about this invoice..."
                        style="width: 100%; padding: 10px 14px; border: 1px solid #e2e8f0; border-radius: 10px; font-size: 14px; outline: none; transition: border-color 0.2s; min-height: 80px; resize: vertical;"></textarea>
                </div>

                <div style="margin-bottom: 24px;">
                    <label
                        style="display: block; font-size: 13px; font-weight: 600; color: #475569; margin-bottom: 8px;">Upload
                        Invoice File</label>
                    <div id="dropZone"
                        style="border: 2px dashed #e2e8f0; border-radius: 12px; padding: 32px; text-align: center; cursor: pointer; transition: all 0.2s; background: #f8fafc;">
                        <input type="file" id="invoiceFile" name="invoice_file" accept=".pdf,.jpg,.jpeg,.png" required
                            style="display: none;">
                        <div
                            style="width: 48px; height: 48px; background: #eff6ff; color: #1a56db; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 12px;">
                            <i class="ph ph-cloud-arrow-up" style="font-size: 24px;"></i>
                        </div>
                        <div id="fileName"
                            style="font-size: 14px; font-weight: 600; color: #1e293b; margin-bottom: 4px;">Click to
                            upload or drag and drop</div>
                        <div style="font-size: 12px; color: #64748b;">PDF, JPG or PNG (max. 2MB)</div>
                    </div>
                </div>

                <!-- Modal Footer -->
                <div style="display: flex; gap: 12px; justify-content: flex-end;">
                    <button type="button" onclick="closeInvoiceModal()"
                        style="padding: 10px 20px; border: 1px solid #e2e8f0; background: white; border-radius: 10px; font-size: 14px; font-weight: 600; color: #64748b; cursor: pointer;">Cancel</button>
                    <button type="submit"
                        style="padding: 10px 24px; background: #1a56db; color: white; border: none; border-radius: 10px; font-size: 14px; font-weight: 600; cursor: pointer;">Add
                        Invoice</button>
                </div>
            </form>
        </div>
    </div>

    <style>
        @keyframes modalSlideUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        #dropZone.dragover {
            background: #eff6ff !important;
            border-color: #1a56db !important;
        }
    </style>

    <script>
        function openInvoiceModal() {
            document.getElementById('invoiceModal').style.display = 'block';
        }

        function closeInvoiceModal() {
            document.getElementById('invoiceModal').style.display = 'none';
            document.getElementById('invoiceForm').reset();
            document.getElementById('fileName').innerHTML = 'Click to upload or drag and drop';
            document.getElementById('dropZone').style.borderColor = '#e2e8f0';
            document.getElementById('dropZone').style.background = '#f8fafc';
        }

        document.addEventListener('DOMContentLoaded', function () {
            // Drop zone logic
            const dropZone = document.getElementById('dropZone');
            const fileInput = document.getElementById('invoiceFile');
            const fileNameDisplay = document.getElementById('fileName');

            if (dropZone) {
                dropZone.onclick = () => fileInput.click();

                dropZone.ondragover = (e) => {
                    e.preventDefault();
                    dropZone.classList.add('dragover');
                };

                dropZone.ondragleave = () => {
                    dropZone.classList.remove('dragover');
                };

                dropZone.ondrop = (e) => {
                    e.preventDefault();
                    dropZone.classList.remove('dragover');
                    const files = e.dataTransfer.files;
                    if (files.length > 0) {
                        fileInput.files = files;
                        fileNameDisplay.innerHTML = files[0].name;
                    }
                };
            }

            if (fileInput) {
                fileInput.onchange = () => {
                    if (fileInput.files.length > 0) {
                        fileNameDisplay.innerHTML = fileInput.files[0].name;
                    }
                };
            }
        });

        const expenseHierarchy = {
            "Travel Expenses": {
                "Petrol": ["Bike", "Car"],
                "Tickets": ["Train", "Bus", "Flight"],
                "Local Traveling": ["Taxi", "Auto"]
            },
            "FOOD & REFRESHMENT": ["Break Fast", "High Tea", "Water Bottle", "Lunch", "Dinner"],
            "ACCOMMODATION": ["Laundry", "Hotel", "Printing & Stationery"],
            "MATERIAL EXPENSES": ["Material Purchase at Site", "Printing & Stationery", "Transportation (Material Movement)"],
            "OTHER / MISCELLANEOUS": ["Miscellaneous / Other Expenses"],
            "LABOUR": {
                "MATERIAL HANDLING": ["Hydra for Material Unloading", "Labour for Material Unloading"],
                "INSTALLATION WORK": ["Labour for Media Filling", "Labour for Site Work"]
            }
        };

        function handleMainTypeChange() {
            const mainType = document.getElementById('mainExpenseType').value;
            const subtypeContainer = document.getElementById('subtypeContainer');
            const subtypeSelect = document.getElementById('expenseSubtype');
            const detailContainer = document.getElementById('detailContainer');
            const petrolDetails = document.getElementById('petrolDetails');
            
            // Reset everything
            subtypeContainer.style.display = 'none';
            detailContainer.style.display = 'none';
            petrolDetails.style.display = 'none';
            subtypeSelect.innerHTML = '<option value="" disabled selected>Select Sub-type</option>';
            document.getElementById('expenseDetail').innerHTML = '<option value="" disabled selected>Select Detail</option>';

            if (expenseHierarchy[mainType]) {
                subtypeContainer.style.display = 'block';
                const data = expenseHierarchy[mainType];
                
                if (Array.isArray(data)) {
                    data.forEach(item => {
                        const opt = document.createElement('option');
                        opt.value = item;
                        opt.textContent = item;
                        subtypeSelect.appendChild(opt);
                    });
                } else {
                    Object.keys(data).forEach(key => {
                        const opt = document.createElement('option');
                        opt.value = key;
                        opt.textContent = key;
                        subtypeSelect.appendChild(opt);
                    });
                }
            }
        }

        function handleSubtypeChange() {
            const mainType = document.getElementById('mainExpenseType').value;
            const subtype = document.getElementById('expenseSubtype').value;
            const detailContainer = document.getElementById('detailContainer');
            const detailSelect = document.getElementById('expenseDetail');
            const petrolDetails = document.getElementById('petrolDetails');

            detailContainer.style.display = 'none';
            petrolDetails.style.display = 'none';
            detailSelect.innerHTML = '<option value="" disabled selected>Select Detail</option>';

            const data = expenseHierarchy[mainType][subtype];
            
            if (data && Array.isArray(data)) {
                detailContainer.style.display = 'block';
                data.forEach(item => {
                    const opt = document.createElement('option');
                    opt.value = item;
                    opt.textContent = item;
                    detailSelect.appendChild(opt);
                });
            }

            if (subtype === 'Petrol') {
                petrolDetails.style.display = 'block';
            }
        }

        function handleDetailChange() {
            // Placeholder for any specific logic on detail change
        }

        function calculatePetrolAmount() {
            const km = parseFloat(document.getElementById('kmInput').value) || 0;
            const rate = parseFloat(document.getElementById('rateInput').value) || 0;
            const amountInput = document.querySelector('input[name="amount"]');
            
            if (km > 0 && rate > 0) {
                amountInput.value = (km * rate).toFixed(2);
            }
        }

        function handleInvoiceSubmit(e) {
            e.preventDefault();
            const form = e.target;
            const fileInput = document.getElementById('invoiceFile');

            if (fileInput.files.length === 0) {
                alert('Please select or drag a file to upload.');
                return;
            }

            const formData = new FormData(form);

            const submitBtn = form.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerText;
            submitBtn.disabled = true;
            submitBtn.innerText = 'Uploading...';

            fetch('handlers/add_invoice.php', {
                method: 'POST',
                body: formData
            })
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok: ' + response.statusText);
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        location.reload();
                    } else {
                        alert('Upload Failed: ' + (data.error || 'Unknown error occurred'));
                        submitBtn.disabled = false;
                        submitBtn.innerText = originalText;
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred while adding the invoice: ' + error.message);
                    submitBtn.disabled = false;
                    submitBtn.innerText = originalText;
                });
        }

        window.onclick = function (event) {
            const paymentModal = document.getElementById('paymentModal');
            const invoiceModal = document.getElementById('invoiceModal');
            if (event.target == paymentModal) {
                closePaymentModal();
            }
            if (event.target == invoiceModal) {
                closeInvoiceModal();
            }
        }
    </script>

    <?php include 'includes/app_footer.php'; ?>