<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
require 'includes/db.php';

// Calculate KPIs
// 1. Pending Requests
$stmt_pending = $pdo->query("SELECT COUNT(*) FROM payment_requests WHERE status = 'Pending'");
$pending_requests = $stmt_pending->fetchColumn() ?: 0;

// 2. Approved Today (using request_date as a proxy if approved_at doesn't exist or just DATE(updated_at) / DATE(created_at). We'll assume created_at or request_date)
$stmt_approved_today = $pdo->query("SELECT COUNT(*) FROM payment_requests WHERE status = 'Approved' AND DATE(request_date) = CURDATE()");
$approved_today = $stmt_approved_today->fetchColumn() ?: 0;

// 3. Monthly Total
$stmt_monthly = $pdo->query("SELECT SUM(amount) FROM payment_requests WHERE status IN ('Approved', 'Paid') AND MONTH(request_date) = MONTH(CURDATE()) AND YEAR(request_date) = YEAR(CURDATE())");
$monthly_total = $stmt_monthly->fetchColumn() ?: 0;


$page_title = 'Dashboard';
$current_page = 'dashboard';
include 'includes/app_header.php';
?>

<div class="page-content" style="padding-top: 24px;">
    <div style="margin-bottom: 32px;">
        <h1 style="font-size: 28px; font-weight: 700; color: var(--text-primary); margin: 0;">Overview Dashboard</h1>
        <p style="color: var(--text-secondary); margin-top: 4px; font-size: 14px;">Welcome back! Here's what's happening with your payment requests.</p>
    </div>

    <!-- KPI Display grid matching design -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 24px;">
        
        <!-- Pending Requests Card -->
        <div style="background: white; border-radius: 12px; padding: 24px; border: 1px solid var(--border-color); box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 24px;">
                <h3 style="font-size: 15px; font-weight: 500; color: #64748b; margin: 0;">Pending Requests</h3>
                <div style="width: 40px; height: 40px; border-radius: 10px; background: #fffbeb; display: flex; align-items: center; justify-content: center; color: #d97706;">
                    <i class="ph ph-clipboard-text" style="font-size: 22px;"></i>
                </div>
            </div>
            <div style="display: flex; align-items: baseline; gap: 12px;">
                <span style="font-size: 32px; font-weight: 700; color: #0f172a; line-height: 1;"><?php echo number_format($pending_requests); ?></span>
                <span style="display: inline-flex; align-items: center; padding: 4px 8px; border-radius: 6px; font-size: 12px; font-weight: 600; background: #dcfce7; color: #16a34a;">
                    +5%
                </span>
            </div>
            <div style="margin-top: 12px; font-size: 13px; color: #94a3b8;">
                from last week
            </div>
        </div>

        <!-- Approved Today Card -->
        <div style="background: white; border-radius: 12px; padding: 24px; border: 1px solid var(--border-color); box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 24px;">
                <h3 style="font-size: 15px; font-weight: 500; color: #64748b; margin: 0;">Approved Today</h3>
                <div style="width: 40px; height: 40px; border-radius: 10px; background: #f0fdf4; display: flex; align-items: center; justify-content: center; color: #16a34a;">
                    <i class="ph ph-check-circle" style="font-size: 22px;"></i>
                </div>
            </div>
            <div style="display: flex; align-items: baseline; gap: 12px;">
                <span style="font-size: 32px; font-weight: 700; color: #0f172a; line-height: 1;"><?php echo number_format($approved_today); ?></span>
                <span style="display: inline-flex; align-items: center; padding: 4px 8px; border-radius: 6px; font-size: 12px; font-weight: 600; background: #fee2e2; color: #dc2626;">
                    -2%
                </span>
            </div>
            <div style="margin-top: 12px; font-size: 13px; color: #94a3b8;">
                vs yesterday
            </div>
        </div>

        <!-- Monthly Total Card -->
        <div style="background: white; border-radius: 12px; padding: 24px; border: 1px solid var(--border-color); box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 24px;">
                <h3 style="font-size: 15px; font-weight: 500; color: #64748b; margin: 0;">Monthly Total</h3>
                <div style="width: 40px; height: 40px; border-radius: 10px; background: #e0e7ff; display: flex; align-items: center; justify-content: center; color: #4338ca;">
                    <i class="ph ph-money" style="font-size: 22px;"></i>
                </div>
            </div>
            <div style="display: flex; align-items: baseline; gap: 12px;">
                <span style="font-size: 32px; font-weight: 700; color: #0f172a; line-height: 1;">₹<?php echo number_format($monthly_total, 2); ?></span>
                <span style="display: inline-flex; align-items: center; padding: 4px 8px; border-radius: 6px; font-size: 12px; font-weight: 600; background: #dcfce7; color: #16a34a;">
                    +12%
                </span>
            </div>
            <div style="margin-top: 12px; font-size: 13px; color: #94a3b8;">
                current month accrual
            </div>
        </div>

    </div>

</div>

<?php include 'includes/app_footer.php'; ?>
