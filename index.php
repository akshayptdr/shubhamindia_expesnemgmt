<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

if (isset($_SESSION['user_role']) && in_array($_SESSION['user_role'], ['Fitter', 'Senior Fitter', 'Engineer', 'Senior Engineer'])) {
    header("Location: payment_requests.php");
    exit;
}
require 'includes/db.php';

// Calculate KPIs
// 1. Pending Requests
$stmt_pending = $pdo->query("SELECT COUNT(*) FROM payment_requests WHERE status = 'Pending'");
$pending_requests = $stmt_pending->fetchColumn() ?: 0;

// 2. Payment Done Today (using paid_at for real-time accuracy)
$stmt_paid_today = $pdo->query("SELECT COUNT(*) FROM payment_requests WHERE DATE(paid_at) = CURDATE()");
$paid_today = $stmt_paid_today->fetchColumn() ?: 0;

// 3. Monthly Paid Total (using paid_at for accounting accuracy)
$stmt_monthly = $pdo->query("SELECT SUM(amount) FROM payment_requests WHERE status = 'Paid' AND MONTH(paid_at) = MONTH(CURDATE()) AND YEAR(paid_at) = YEAR(CURDATE())");
$monthly_total = $stmt_monthly->fetchColumn() ?: 0;

// NEW: Data for Charts
// 1. Status Distribution
$stmt_status = $pdo->query("SELECT status, COUNT(*) as count FROM payment_requests GROUP BY status");
$status_dist = $stmt_status->fetchAll(PDO::FETCH_ASSOC);

// 2. Monthly Trend (Last 6 Months)
$stmt_trend = $pdo->query("SELECT DATE_FORMAT(request_date, '%b %Y') as month, SUM(amount) as total 
                          FROM payment_requests 
                          WHERE status IN ('Approved', 'Paid')
                          AND request_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
                          GROUP BY YEAR(request_date), MONTH(request_date), DATE_FORMAT(request_date, '%b %Y')
                          ORDER BY YEAR(request_date), MONTH(request_date)");
$trend_data = $stmt_trend->fetchAll(PDO::FETCH_ASSOC);

// 3. Project Expenditure (Top 5)
$stmt_projects = $pdo->query("SELECT p.project_name, SUM(pr.amount) as total 
                             FROM payment_requests pr 
                             JOIN projects p ON pr.project_id = p.id 
                             WHERE pr.status IN ('Approved', 'Paid')
                             GROUP BY pr.project_id, p.project_name 
                             ORDER BY total DESC 
                             LIMIT 5");
$project_dist = $stmt_projects->fetchAll(PDO::FETCH_ASSOC);


$page_title = 'Dashboard';
$current_page = 'dashboard';
include 'includes/app_header.php';
?>

<!-- Chart.js CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<div class="page-content" style="padding-top: 24px;">
    <div style="margin-bottom: 32px;">
        <h1 style="font-size: 28px; font-weight: 700; color: var(--text-primary); margin: 0;">Overview Dashboard</h1>
        <p style="color: var(--text-secondary); margin-top: 4px; font-size: 14px;">Welcome back! Here's what's happening with your payment requests.</p>
    </div>

    <!-- KPI Display grid -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 24px;">
        
        <!-- Pending Requests Card -->
        <div style="background: white; border-radius: 12px; padding: 24px; border: 1px solid var(--border-color); box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 24px;">
                <h3 style="font-size: 15px; font-weight: 500; color: #64748b; margin: 0;">Pending Requests</h3>
                <div style="width: 40px; height: 40px; border-radius: 10px; background: #fffbeb; display: flex; align-items: center; justify-content: center; color: #d97706;">
                    <i class="ph ph-clipboard-text" style="font-size: 22px;"></i>
                </div>
            </div>
            <div style="display: flex; align-items: baseline; gap: 12px;">
                <span style="font-size: 32px; font-weight: 700; color: #0f172a; line-height: 1;"><?php echo number_format($pending_requests); ?></span>
            </div>
        </div>

        <!-- Payment Done Today Card -->
        <div style="background: white; border-radius: 12px; padding: 24px; border: 1px solid var(--border-color); box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 24px;">
                <h3 style="font-size: 15px; font-weight: 500; color: #64748b; margin: 0;">Payment Done Today</h3>
                <div style="width: 40px; height: 40px; border-radius: 10px; background: #f0fdf4; display: flex; align-items: center; justify-content: center; color: #16a34a;">
                    <i class="ph ph-check-circle" style="font-size: 22px;"></i>
                </div>
            </div>
            <div style="display: flex; align-items: baseline; gap: 12px;">
                <span style="font-size: 32px; font-weight: 700; color: #0f172a; line-height: 1;"><?php echo number_format($paid_today); ?></span>
            </div>
        </div>

        <!-- Monthly Paid Total Card -->
        <div style="background: white; border-radius: 12px; padding: 24px; border: 1px solid var(--border-color); box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 24px;">
                <h3 style="font-size: 15px; font-weight: 500; color: #64748b; margin: 0;">Monthly Paid Total</h3>
                <div style="width: 40px; height: 40px; border-radius: 10px; background: #e0e7ff; display: flex; align-items: center; justify-content: center; color: #4338ca;">
                    <i class="ph ph-money" style="font-size: 22px;"></i>
                </div>
            </div>
            <div style="display: flex; align-items: baseline; gap: 12px;">
                <span style="font-size: 32px; font-weight: 700; color: #0f172a; line-height: 1;">₹<?php echo number_format($monthly_total, 2); ?></span>
            </div>
            <div style="margin-top: 12px; font-size: 13px; color: #94a3b8;">
                current month processed
            </div>
        </div>
    </div>

    <!-- Graphical Dashboard Grid -->
    <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 24px; margin-top: 32px;">
        <!-- Expenditure Trend Chart -->
        <div style="background: white; border-radius: 12px; padding: 24px; border: 1px solid var(--border-color); box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);">
            <h3 style="font-size: 16px; font-weight: 600; color: #1e293b; margin: 0 0 20px 0;">Expenditure Trend (Last 6 Months)</h3>
            <div style="height: 360px; position: relative;">
                <canvas id="trendChart"></canvas>
            </div>
        </div>

        <!-- Status Distribution Chart -->
        <div style="background: white; border-radius: 12px; padding: 24px; border: 1px solid var(--border-color); box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);">
            <h3 style="font-size: 16px; font-weight: 600; color: #1e293b; margin: 0 0 20px 0;">Request Overview</h3>
            <div style="height: 360px; position: relative;">
                <canvas id="statusChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Bottom Row: Top Projects -->
    <div style="margin-top: 24px; background: white; border-radius: 12px; padding: 24px; border: 1px solid var(--border-color); box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);">
        <h3 style="font-size: 16px; font-weight: 600; color: #1e293b; margin: 0 0 20px 0;">Top 5 Projects by Approved Spending</h3>
        <div style="height: 300px; position: relative;">
            <canvas id="projectChart"></canvas>
        </div>
    </div>

</div>

<script>
// Data from PHP
const trendLabels = <?php echo json_encode(array_column($trend_data, 'month')); ?>;
const trendAmounts = <?php echo json_encode(array_column($trend_data, 'total')); ?>;

const statusLabels = <?php echo json_encode(array_column($status_dist, 'status')); ?>;
const statusCounts = <?php echo json_encode(array_column($status_dist, 'count')); ?>;

const projectLabels = <?php echo json_encode(array_column($project_dist, 'project_name')); ?>;
const projectTotals = <?php echo json_encode(array_column($project_dist, 'total')); ?>;

// 1. Expenditure Trend Chart
new Chart(document.getElementById('trendChart'), {
    type: 'line',
    data: {
        labels: trendLabels,
        datasets: [{
            label: 'Total approved (₹)',
            data: trendAmounts,
            borderColor: '#1a56db',
            backgroundColor: 'rgba(26, 86, 219, 0.1)',
            fill: true,
            tension: 0.4,
            pointRadius: 4,
            borderWidth: 3
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            y: { beginAtZero: true, grid: { color: '#f1f5f9' } },
            x: { grid: { display: false } }
        }
    }
});

// 2. Status Distribution Chart
const statusColors = {
    'Pending': '#d97706',
    'Approved': '#16a34a',
    'Paid': '#1e293b',
    'Rejected': '#dc2626'
};
const bgColors = statusLabels.map(label => statusColors[label] || '#94a3b8');

new Chart(document.getElementById('statusChart'), {
    type: 'doughnut',
    data: {
        labels: statusLabels,
        datasets: [{
            data: statusCounts,
            backgroundColor: bgColors,
            borderWidth: 0,
            hoverOffset: 10
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { position: 'bottom', labels: { usePointStyle: true, padding: 20 } }
        },
        cutout: '70%'
    }
});

// 3. Top Projects Chart
new Chart(document.getElementById('projectChart'), {
    type: 'bar',
    data: {
        labels: projectLabels,
        datasets: [{
            label: 'Expenditure (₹)',
            data: projectTotals,
            backgroundColor: '#1a56db',
            borderRadius: 8,
            barThickness: 40
        }]
    },
    options: {
        indexAxis: 'y',
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            x: { beginAtZero: true, grid: { color: '#f1f5f9' } },
            y: { grid: { display: false } }
        }
    }
});
</script>

<?php include 'includes/app_footer.php'; ?>
