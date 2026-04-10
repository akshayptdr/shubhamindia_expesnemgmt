<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

if ($_SESSION['user_role'] !== 'Director') {
    header("Location: index.php");
    exit;
}

require 'includes/db.php';

// Pagination
$limit = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Status Filter
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'Pending';

$where = "WHERE 1=1";
if ($status_filter !== 'All') {
    $where .= " AND bcr.status = '$status_filter'";
}

// Fetch requests
$sql = "SELECT bcr.*, p.project_name, p.project_code, e.name as requested_by_name
        FROM budget_change_requests bcr
        JOIN projects p ON bcr.project_id = p.id
        JOIN employees e ON bcr.requested_by = e.id
        $where
        ORDER BY bcr.created_at DESC
        LIMIT :limit OFFSET :offset";

$stmt = $pdo->prepare($sql);
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$requests = $stmt->fetchAll();

// Count for pagination
$count_stmt = $pdo->query("SELECT COUNT(*) FROM budget_change_requests bcr $where");
$total_rows = $count_stmt->fetchColumn();
$total_pages = ceil($total_rows / $limit);

// Fetch Summary Stats
$stats_sql = "SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) as pending,
    SUM(CASE WHEN status = 'Approved' THEN 1 ELSE 0 END) as approved,
    SUM(CASE WHEN status = 'Rejected' THEN 1 ELSE 0 END) as rejected
FROM budget_change_requests";
$stats = $pdo->query($stats_sql)->fetch();

$page_title = 'Budget Change Requests';
$current_page = 'budget_change';
include 'includes/app_header.php';
?>

<div class="page-content">
    <div class="breadcrumb" style="font-size: 11px; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.1em; display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
        <span style="opacity: 0.6;">Main Dashboard</span>
        <i class="ph ph-caret-right" style="font-size: 10px; opacity: 0.4;"></i>
        <span>Budget Change Requests</span>
    </div>

    <div class="page-header" style="display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 32px;">
        <div class="header-content">
            <h1 style="font-size: 28px; font-weight: 700; color: var(--text-primary); margin: 0 0 6px 0;">Budget Change Requests</h1>
            <p style="font-size: 14px; color: var(--text-secondary); margin: 0;">Review and manage budget adjustment proposals.</p>
        </div>
    </div>

    <div class="summary-cards" style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 24px; margin-bottom: 32px;">
        <!-- Total Requests -->
        <div style="background: white; padding: 20px; border-radius: 12px; border: 1px solid var(--border-color); display: flex; align-items: center; gap: 16px;">
            <div style="width: 48px; height: 48px; background: #eff6ff; border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                <i class="ph ph-files" style="font-size: 24px; color: #1d4ed8;"></i>
            </div>
            <div>
                <p style="font-size: 11px; font-weight: 600; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.05em; margin: 0 0 4px 0;">Total Requests</p>
                <h3 style="font-size: 20px; font-weight: 700; color: var(--text-primary); margin: 0;"><?php echo $stats['total']; ?></h3>
            </div>
        </div>
        <!-- Pending -->
        <div style="background: white; padding: 20px; border-radius: 12px; border: 1px solid var(--border-color); display: flex; align-items: center; gap: 16px;">
            <div style="width: 48px; height: 48px; background: #fffbeb; border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                <i class="ph ph-clock" style="font-size: 24px; color: #b45309;"></i>
            </div>
            <div>
                <p style="font-size: 11px; font-weight: 600; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.05em; margin: 0 0 4px 0;">Pending</p>
                <h3 style="font-size: 20px; font-weight: 700; color: var(--text-primary); margin: 0;"><?php echo $stats['pending'] ?: 0; ?></h3>
            </div>
        </div>
        <!-- Approved -->
        <div style="background: white; padding: 20px; border-radius: 12px; border: 1px solid var(--border-color); display: flex; align-items: center; gap: 16px;">
            <div style="width: 48px; height: 48px; background: #f0fdf4; border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                <i class="ph ph-check-circle" style="font-size: 24px; color: #15803d;"></i>
            </div>
            <div>
                <p style="font-size: 11px; font-weight: 600; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.05em; margin: 0 0 4px 0;">Approved</p>
                <h3 style="font-size: 20px; font-weight: 700; color: var(--text-primary); margin: 0;"><?php echo $stats['approved'] ?: 0; ?></h3>
            </div>
        </div>
        <!-- Rejected -->
        <div style="background: white; padding: 20px; border-radius: 12px; border: 1px solid var(--border-color); display: flex; align-items: center; gap: 16px;">
            <div style="width: 48px; height: 48px; background: #fef2f2; border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                <i class="ph ph-warning-circle" style="font-size: 24px; color: #b91c1c;"></i>
            </div>
            <div>
                <p style="font-size: 11px; font-weight: 600; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.05em; margin: 0 0 4px 0;">Rejected</p>
                <h3 style="font-size: 20px; font-weight: 700; color: var(--text-primary); margin: 0;"><?php echo $stats['rejected'] ?: 0; ?></h3>
            </div>
        </div>
    </div>

    <div class="table-container" style="background: white; border-radius: 16px; border: 1px solid var(--border-color); overflow: hidden;">
        <div style="padding: 16px 24px; border-bottom: 1px solid var(--border-color); display: flex; gap: 32px;">
            <?php foreach (['Pending', 'Approved', 'Rejected', 'All'] as $st): ?>
                <a href="?status=<?php echo $st; ?>" style="text-decoration: none; font-size: 14px; font-weight: 600; color: <?php echo $status_filter === $st ? '#1a56db' : 'var(--text-secondary)'; ?>; border-bottom: 2px solid <?php echo $status_filter === $st ? '#1a56db' : 'transparent'; ?>; padding-bottom: 12px;">
                    <?php echo $st; ?> Requests
                </a>
            <?php endforeach; ?>
        </div>

        <div class="table-responsive">
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: #fdfdfd; border-bottom: 1px solid var(--border-color);">
                        <th style="text-align: left; padding: 16px 24px; font-size: 12px; font-weight: 600; color: var(--text-secondary); text-transform: uppercase;">ID</th>
                        <th style="text-align: left; padding: 16px 24px; font-size: 12px; font-weight: 600; color: var(--text-secondary); text-transform: uppercase;">Project</th>
                        <th style="text-align: left; padding: 16px 24px; font-size: 12px; font-weight: 600; color: var(--text-secondary); text-transform: uppercase;">Requested By</th>
                        <th style="text-align: left; padding: 16px 24px; font-size: 12px; font-weight: 600; color: var(--text-secondary); text-transform: uppercase;">Amount to Add</th>
                        <th style="text-align: left; padding: 16px 24px; font-size: 12px; font-weight: 600; color: var(--text-secondary); text-transform: uppercase;">Requested Date</th>
                        <th style="text-align: left; padding: 16px 24px; font-size: 12px; font-weight: 600; color: var(--text-secondary); text-transform: uppercase;">Approved Date</th>
                        <th style="text-align: left; padding: 16px 24px; font-size: 12px; font-weight: 600; color: var(--text-secondary); text-transform: uppercase;">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($requests)): ?>
                        <tr>
                            <td colspan="7" style="padding: 40px; text-align: center; color: var(--text-secondary);">No requests found.</td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($requests as $req): ?>
                        <tr style="border-bottom: 1px solid var(--border-color);">
                            <td style="padding: 16px 24px; font-size: 14px; font-weight: 600; color: #1a56db;">#BUD-<?php echo $req['id']; ?></td>
                            <td style="padding: 16px 24px;">
                                <div style="font-size: 14px; font-weight: 600; color: var(--text-primary);"><?php echo htmlspecialchars($req['project_name']); ?></div>
                                <div style="font-size: 12px; color: var(--text-secondary);"><?php echo htmlspecialchars($req['project_code']); ?></div>
                            </td>
                            <td style="padding: 16px 24px; font-size: 14px; color: var(--text-primary);"><?php echo htmlspecialchars($req['requested_by_name']); ?></td>
                            <td style="padding: 16px 24px;">
                                <div style="font-size: 14px; font-weight: 700; color: #16a34a;">₹ <?php echo number_format($req['amount_to_add'], 2); ?></div>
                                <?php 
                                $breakdown = json_decode($req['breakdown'], true);
                                if (!empty($breakdown)): 
                                ?>
                                <div style="margin-top: 4px; display: flex; flex-direction: column; gap: 2px;">
                                    <?php foreach ($breakdown as $item): ?>
                                        <div style="font-size: 10px; color: var(--text-secondary); white-space: nowrap;">
                                            <span style="text-transform: capitalize; opacity: 0.8;"><?php echo htmlspecialchars($item['type']); ?></span>: 
                                            <span style="font-weight: 600; color: var(--text-primary);">₹ <?php echo number_format($item['amount'], 2); ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 16px 24px; font-size: 14px; color: var(--text-secondary);"><?php echo date('M d, Y', strtotime($req['created_at'])); ?></td>
                            <td style="padding: 16px 24px; font-size: 14px; color: var(--text-secondary);">
                                <?php echo $req['approved_at'] ? date('M d, Y', strtotime($req['approved_at'])) : '-'; ?>
                            </td>
                            <td style="padding: 16px 24px;">
                                <span style="padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; 
                                    <?php
                                    if ($req['status'] === 'Approved') echo 'background: #f0fdf4; color: #16a34a;';
                                    elseif ($req['status'] === 'Pending') echo 'background: #fffbeb; color: #d97706;';
                                    else echo 'background: #fef2f2; color: #dc2626;';
                                    ?>">
                                    <?php echo $req['status']; ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
            <div style="padding: 20px 24px; border-top: 1px solid var(--border-color); display: flex; justify-content: flex-end; gap: 8px;">
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <a href="?status=<?php echo $status_filter; ?>&page=<?php echo $i; ?>" style="padding: 8px 12px; border-radius: 8px; border: 1px solid var(--border-color); font-size: 13px; text-decoration: none; <?php echo $i === $page ? 'background: #1a56db; color: white; border-color: #1a56db;' : 'background: white; color: var(--text-secondary);'; ?>"><?php echo $i; ?></a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
</script>

<?php include 'includes/app_footer.php'; ?>
