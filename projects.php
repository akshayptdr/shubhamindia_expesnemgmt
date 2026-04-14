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

// Search, Status & Pagination Logic
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status_filter = isset($_GET['status']) ? trim($_GET['status']) : '';
$page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$perPage = 5;

$sql = "SELECT p.*, e.name as manager_name, e.avatar as manager_avatar, c.name as creator_name, c.emp_id as creator_emp_id,
               (SELECT 
                  (SELECT IFNULL(SUM(amount), 0) FROM payment_requests WHERE project_id = p.id AND status IN ('Approved', 'Paid')) - 
                  (SELECT IFNULL(SUM(pr2.amount - (SELECT IFNULL(SUM(amount), 0) FROM payment_request_invoices WHERE payment_request_id = pr2.id)), 0)
                   FROM payment_requests pr2
                   WHERE pr2.project_id = p.id AND pr2.status = 'Paid' AND pr2.voucher_approved_at IS NOT NULL)
               ) as utilized_budget
        FROM projects p 
        LEFT JOIN employees e ON p.project_manager_id = e.id
        LEFT JOIN employees c ON p.created_by = c.id";

$countSql = "SELECT COUNT(*) 
             FROM projects p 
             LEFT JOIN employees e ON p.project_manager_id = e.id
             LEFT JOIN employees c ON p.created_by = c.id";

$conditions = [];
$params = [];

if ($search !== '') {
    $conditions[] = "(p.project_code LIKE :search OR p.location LIKE :search OR e.name LIKE :search)";
    $params[':search'] = "%$search%";
}

if ($status_filter !== '') {
    $conditions[] = "p.status = :status";
    $params[':status'] = $status_filter;
}

if ($conditions) {
    $where = " WHERE " . implode(" AND ", $conditions);
    $sql .= $where;
    $countSql .= $where;
}

// Get total count
$stmtCount = $pdo->prepare($countSql);
foreach ($params as $key => $val) {
    $stmtCount->bindValue($key, $val, PDO::PARAM_STR);
}
$stmtCount->execute();
$totalProjects = $stmtCount->fetchColumn();

$totalPages = ceil($totalProjects / $perPage);
if ($page > $totalPages && $totalPages > 0) {
    $page = $totalPages;
}

$offset = ($page - 1) * $perPage;

$sql .= " ORDER BY p.created_at DESC LIMIT :limit OFFSET :offset";

$stmt = $pdo->prepare($sql);
foreach ($params as $key => $val) {
    $stmt->bindValue($key, $val, PDO::PARAM_STR);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$projects = $stmt->fetchAll();

// Fetch employees for project manager dropdown
$stmtEmployees = $pdo->query("SELECT id, name FROM employees WHERE status = 'Active' AND role IN ('Project Manager', 'Senior Project Manager') ORDER BY name ASC");
$employees = $stmtEmployees->fetchAll();

$current_page = 'projects';
$page_title = 'Projects';

// Fetch KPI statistics for Projects
$project_kpi_query = "SELECT 
    COUNT(*) as total_count,
    SUM(CASE WHEN status = 'Active' THEN 1 ELSE 0 END) as active_count,
    SUM(CASE WHEN status = 'Completed' THEN 1 ELSE 0 END) as completed_count,
    SUM(CASE WHEN status = 'In Progress' THEN 1 ELSE 0 END) as in_progress_count,
    SUM(budget) as total_budget_sum
FROM projects";
$project_kpi_stats = $pdo->query($project_kpi_query)->fetch();

include 'includes/app_header.php';
?>

<!-- Page Content -->
<div class="page-content">
    <div class="page-header">
        <div class="page-title">
            <h2>Projects</h2>
            <p>Monitor project allocations and expenditure efficiency across the organization.</p>
        </div>
        <div style="display: flex; align-items: center; gap: 24px;">
            <button class="btn-primary" id="openModalBtn">
                <i class="ph ph-plus"></i>
                Add New Project
            </button>
        </div>
    </div>

    <!-- KPI Dashboard for Projects -->
    <div class="kpi-grid" style="display: grid; grid-template-columns: repeat(5, 1fr); gap: 24px; margin-bottom: 32px;">
        <a href="?status=&search=<?php echo urlencode($search); ?>"
            class="kpi-card"
            style="text-decoration: none; background: white; padding: 20px 24px; border-radius: 12px; border: 1px solid <?php echo $status_filter === '' ? '#1a56db' : 'var(--border-color)'; ?>; display: flex; align-items: center; gap: 20px; transition: all 0.2s;">
            <div style="width: 44px; height: 44px; background: #eff6ff; border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                <i class="ph ph-folder-simple" style="color: #1a56db; font-size: 22px;"></i>
            </div>
            <div>
                <span style="display: block; font-size: 11px; font-weight: 600; color: var(--text-secondary); text-transform: uppercase;">Total Projects</span>
                <span style="font-size: 20px; font-weight: 700; color: var(--text-primary);"><?php echo number_format($project_kpi_stats['total_count']); ?></span>
            </div>
        </a>
        <a href="?status=Active&search=<?php echo urlencode($search); ?>"
            class="kpi-card"
            style="text-decoration: none; background: white; padding: 20px 24px; border-radius: 12px; border: 1px solid <?php echo $status_filter === 'Active' ? '#16a34a' : 'var(--border-color)'; ?>; display: flex; align-items: center; gap: 20px; transition: all 0.2s;">
            <div style="width: 44px; height: 44px; background: #ecfdf5; border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                <i class="ph ph-play-circle" style="color: #16a34a; font-size: 22px;"></i>
            </div>
            <div>
                <span style="display: block; font-size: 11px; font-weight: 600; color: var(--text-secondary); text-transform: uppercase;">Active</span>
                <span style="font-size: 20px; font-weight: 700; color: var(--text-primary);"><?php echo number_format($project_kpi_stats['active_count'] ?: 0); ?></span>
            </div>
        </a>
        <a href="?status=In Progress&search=<?php echo urlencode($search); ?>"
            class="kpi-card"
            style="text-decoration: none; background: white; padding: 20px 24px; border-radius: 12px; border: 1px solid <?php echo $status_filter === 'In Progress' ? '#d97706' : 'var(--border-color)'; ?>; display: flex; align-items: center; gap: 20px; transition: all 0.2s;">
            <div style="width: 44px; height: 44px; background: #fffbeb; border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                <i class="ph ph-lightning" style="color: #d97706; font-size: 22px;"></i>
            </div>
            <div>
                <span style="display: block; font-size: 11px; font-weight: 600; color: var(--text-secondary); text-transform: uppercase;">In Progress</span>
                <span style="font-size: 20px; font-weight: 700; color: var(--text-primary);"><?php echo number_format($project_kpi_stats['in_progress_count'] ?: 0); ?></span>
            </div>
        </a>
        <a href="?status=Completed&search=<?php echo urlencode($search); ?>"
            class="kpi-card"
            style="text-decoration: none; background: white; padding: 20px 24px; border-radius: 12px; border: 1px solid <?php echo $status_filter === 'Completed' ? '#1e293b' : 'var(--border-color)'; ?>; display: flex; align-items: center; gap: 20px; transition: all 0.2s;">
            <div style="width: 44px; height: 44px; background: #f1f5f9; border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                <i class="ph ph-check-circle" style="color: #1e293b; font-size: 22px;"></i>
            </div>
            <div>
                <span style="display: block; font-size: 11px; font-weight: 600; color: var(--text-secondary); text-transform: uppercase;">Completed</span>
                <span style="font-size: 20px; font-weight: 700; color: var(--text-primary);"><?php echo number_format($project_kpi_stats['completed_count'] ?: 0); ?></span>
            </div>
        </a>
        <div class="kpi-card"
            style="text-decoration: none; background: white; padding: 20px 24px; border-radius: 12px; border: 1px solid var(--border-color); display: flex; align-items: center; gap: 20px;">
            <div style="width: 44px; height: 44px; background: #fdf2f8; border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                <i class="ph ph-currency-inr" style="color: #db2777; font-size: 22px;"></i>
            </div>
            <div>
                <span style="display: block; font-size: 11px; font-weight: 600; color: var(--text-secondary); text-transform: uppercase;">Total Budget</span>
                <span style="font-size: 20px; font-weight: 700; color: var(--text-primary);">₹<?php echo number_format($project_kpi_stats['total_budget_sum'] ?: 0); ?></span>
            </div>
        </div>
    </div>

    <!-- Project Filters/Tabs -->
    <div class="table-tabs">
        <a href="?status=&search=<?php echo urlencode($search); ?>"
            class="tab-item <?php echo $status_filter === '' ? 'active' : ''; ?>">All Projects</a>
        <a href="?status=Active&search=<?php echo urlencode($search); ?>"
            class="tab-item <?php echo $status_filter === 'Active' ? 'active' : ''; ?>">Active</a>
        <a href="?status=Completed&search=<?php echo urlencode($search); ?>"
            class="tab-item <?php echo $status_filter === 'Completed' ? 'active' : ''; ?>">Completed</a>
        <a href="?status=In Progress&search=<?php echo urlencode($search); ?>"
            class="tab-item <?php echo $status_filter === 'In Progress' ? 'active' : ''; ?>">In Progress</a>
    </div>

    <!-- Data Table -->
    <div class="data-table-container">
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>ACTIVITY TYPE</th>
                        <th>PROJECT CODE</th>
                        <th>LOCATION</th>
                        <th>SALES VALUE</th>
                        <th>BUDGET</th>
                        <th>UTILIZED BUDGET</th>
                        <th>STATUS</th>
                        <th>PROJECT MANAGER</th>
                        <th style="text-align: right;">ACTIONS</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    foreach ($projects as $index => $project):
                        $utilized_budget = (float) ($project['utilized_budget'] ?? 0);
                        $total_budget = (float) $project['budget'];
                        $percentage = $total_budget > 0 ? ($utilized_budget / $total_budget) * 100 : 0;
                        $barColor = $percentage > 90 ? '#f97316' : '#2563eb';
                        $statusClass = 'status-' . strtolower(str_replace(' ', '-', $project['status']));
                        ?>
                        <tr>
                            <td>
                                <div style="display: flex; flex-direction: column; gap: 2px;">
                                    <span class="activity-type"
                                        style="font-size: 13px; font-weight: 500; color: var(--text-primary);"><?php echo htmlspecialchars($project['activity_type'] ?? 'Project'); ?></span>
                                    <?php if ($project['activity_type'] === 'Projects' && !empty($project['project_category'])): ?>
                                        <span class="project-category"
                                            style="font-size: 11px; color: var(--text-secondary);"><?php echo htmlspecialchars($project['project_category']); ?></span>
                                    <?php elseif (($project['activity_type'] === 'Services' || $project['activity_type'] === 'O&M') && !empty($project['service_type'])): ?>
                                        <span class="service-type"
                                            style="font-size: 11px; color: var(--text-secondary);"><?php echo htmlspecialchars($project['service_type']); ?>
                                            Type</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <span class="project-code">
                                    <?php
                                    if (!empty($project['project_name'])) {
                                        echo htmlspecialchars($project['project_name']);
                                        if (!empty($project['project_code'])) {
                                            echo ' (' . htmlspecialchars($project['project_code']) . ')';
                                        }
                                    } else {
                                        echo htmlspecialchars($project['project_code']);
                                    }
                                    ?>
                                </span>
                            </td>
                            <td>
                                <div class="location-cell">
                                    <i class="ph ph-map-pin"></i>
                                    <?php echo htmlspecialchars($project['location']); ?>
                                </div>
                            </td>
                            <td><span style="font-weight: 600; color: #16a34a;">₹ <?php echo number_format($project['sales_order_value'] ?? 0); ?></span></td>
                            <td><span class="budget-value">₹ <?php echo number_format($project['budget']); ?></span>
                            </td>
                            <td>
                                <div class="budget-cell">
                                    <div class="budget-header">
                                        <span class="budget-amount">₹ <?php echo number_format($utilized_budget); ?></span>
                                        <span class="budget-percent"><?php echo round($percentage); ?>%</span>
                                    </div>
                                    <div class="progress-container">
                                        <div class="progress-bar" style="width: <?php echo $percentage; ?>%;">
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="status-text <?php echo $statusClass; ?>">
                                    <?php echo htmlspecialchars($project['status']); ?>
                                </span>
                            </td>
                            <td>
                                <div class="manager-info">
                                    <span
                                        class="manager-name"><?php echo htmlspecialchars($project['manager_name']); ?></span>
                                </div>
                            </td>
                            <td style="text-align: right;">
                                <a href="project_details.php?id=<?php echo $project['id']; ?>" class="action-btn"
                                    style="display: inline-flex; margin-left: auto; text-decoration: none;">
                                    <i class="ph ph-eye"></i>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="table-footer"
            style="padding: 16px 24px; border-top: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center;">
            <div class="table-info" style="font-size: 14px; color: var(--text-secondary);">
                Showing <?php echo $totalProjects > 0 ? $offset + 1 : 0; ?> to
                <?php echo min($offset + $perPage, $totalProjects); ?> of <?php echo $totalProjects; ?> projects
                found
            </div>
            <div class="pagination" style="padding: 0;">
                <div class="page-controls">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>"
                            class="page-btn">
                            <i class="ph ph-caret-left"></i>
                        </a>
                    <?php else: ?>
                        <button class="page-btn" disabled style="opacity: 0.5; cursor: not-allowed;"><i
                                class="ph ph-caret-left"></i></button>
                    <?php endif; ?>

                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>"
                            class="page-number <?php echo $i === $page ? 'active' : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>

                    <?php if ($page < $totalPages): ?>
                        <a href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>"
                            class="page-btn">
                            <i class="ph ph-caret-right"></i>
                        </a>
                    <?php else: ?>
                        <button class="page-btn" disabled style="opacity: 0.5; cursor: not-allowed;"><i
                                class="ph ph-caret-right"></i></button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
</main>

<!-- Add Project Modal -->
<style>
    .modal {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0, 0, 0, 0.5);
        z-index: 1000;
        align-items: center;
        justify-content: center;
    }

    .modal-content {
        background-color: #fff;
        border-radius: 12px;
        width: 100%;
        max-width: 500px;
        max-height: 90vh;
        overflow-y: auto;
        box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
    }

    .form-group label {
        display: block;
        font-size: 13px;
        font-weight: 500;
        margin-bottom: 8px;
        color: var(--text-primary);
    }

    .form-input,
    .form-select {
        width: 100%;
        padding: 10px 14px;
        border: 1px solid var(--border-color);
        border-radius: 8px;
        font-size: 14px;
        outline: none;
        font-family: inherit;
    }

    .form-input:focus,
    .form-select:focus {
        border-color: var(--primary-color);
    }
</style>

<div id="projectModal" class="modal">
    <div class="modal-content project-modal">
        <div class="modal-header"
            style="padding: 24px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: flex-start;">
            <div class="header-text" style="display: flex; flex-direction: column; gap: 4px;">
                <h2 id="modal-title" style="font-size: 18px; font-weight: 600; margin: 0;">Add New Project</h2>
                <p id="modal-subtitle" style="font-size: 14px; color: var(--text-secondary); margin: 0;">Enter the
                    details to initialize a new project.</p>
            </div>
            <button class="close-btn" id="closeProjectModalBtn"
                style="background: none; border: none; font-size: 24px; color: var(--text-secondary); cursor: pointer;">
                <i class="ph ph-x"></i>
            </button>
        </div>

        <form id="project-form" action="handlers/add_project.php" method="POST" style="padding: 24px;">
            <div id="form-error-alert"
                style="display: none; background: #fef2f2; border: 1px solid #fca5a5; color: #991b1b; padding: 12px 16px; border-radius: 8px; margin-bottom: 24px; font-size: 14px; align-items: flex-start; gap: 8px;">
                <i class="ph ph-warning-circle"
                    style="font-size: 20px; color: #ef4444; flex-shrink: 0; margin-top: 2px;"></i>
                <div style="flex-grow: 1;">
                    <strong style="display: block; margin-bottom: 4px;">Validation Error</strong>
                    <span id="form-error-message"></span>
                </div>
            </div>

            <div class="form-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px;">
                <div class="form-group" style="grid-column: span 2;">
                    <label>Activity Type</label>
                    <select name="activity_type" id="activity_type_select" required class="form-select">
                        <option value="">Select Activity Type</option>
                        <option value="Projects">Projects</option>
                        <option value="Services">Services</option>
                        <option value="O&M">O&M</option>
                    </select>
                </div>

                <div class="form-group" id="project_category_group" style="grid-column: span 2; display: none;">
                    <label>Type of Project</label>
                    <select name="project_category" id="project_category_select" class="form-select">
                        <option value="">Select Type</option>
                        <option value="Pre Sale">Pre Sale</option>
                        <option value="Post Sale">Post Sale</option>
                    </select>
                </div>

                <div class="form-group" id="service_type_group" style="grid-column: span 2; display: none;">
                    <label>Service Type</label>
                    <select name="service_type" id="service_type_select" class="form-select">
                        <option value="">Select Service Type</option>
                        <option value="Paid">Paid</option>
                        <option value="Free">Free</option>
                    </select>
                </div>


                <div class="form-group" id="project_name_group" style="grid-column: span 2; display: none;">
                    <label>Project Name</label>
                    <input type="text" name="project_name" id="project_name_input" placeholder="Enter project name"
                        class="form-input">
                </div>

                <div class="form-group" id="project_code_group" style="grid-column: span 2; display: none;">
                    <label>Project Code</label>
                    <input type="text" name="project_code" id="project_code_input" placeholder="e.g. PRJ-2024"
                        class="form-input">
                </div>

                <div class="form-group" style="grid-column: span 2;">
                    <label>Location</label>
                    <div class="input-with-icon" style="position: relative;">
                        <i class="ph ph-map-pin"
                            style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--text-secondary);"></i>
                        <input type="text" name="location" placeholder="Enter site address" required class="form-input"
                            style="padding-left: 40px;">
                    </div>
                </div>

                <div class="form-group" style="grid-column: span 2;">
                    <label>Sales Order Value</label>
                    <div class="input-with-icon" style="position: relative;">
                        <i class="ph ph-currency-inr"
                            style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--text-secondary);"></i>
                        <input type="number" step="0.01" name="sales_order_value" placeholder="0.00" class="form-input"
                            style="padding-left: 40px;">
                    </div>
                </div>

                <div class="form-group" style="grid-column: span 2;">
                    <label>Budget (Auto-calculated from breakdown)</label>
                    <div class="input-with-icon" style="position: relative;">
                        <i class="ph ph-currency-inr"
                            style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--text-secondary);"></i>
                        <input type="number" step="0.01" name="budget" placeholder="0.00" readonly class="form-input"
                            style="padding-left: 40px; background-color: #f3f4f6; cursor: not-allowed;">
                    </div>
                </div>

                <div class="section-divider"
                    style="grid-column: span 2; padding: 16px 0; border-top: 1px solid var(--border-color); margin-top: 8px;">
                    <span
                        style="font-size: 11px; font-weight: 600; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.05em;">INITIAL
                        BUDGET BREAKDOWN</span>
                </div>

                <div id="expense-container"
                    style="grid-column: span 2; display: flex; flex-direction: column; gap: 16px;">
                    <div class="expense-row"
                        style="display: grid; grid-template-columns: 1fr 1fr auto; gap: 16px; align-items: flex-end;">
                        <div class="form-group">
                            <label>Type</label>
                            <select name="expense_type[]" required class="form-select">
                                <option value="">Select Type</option>
                                <option value="contractor - installation work">Contractor - Installation Work
                                </option>
                                <option value="aarya team - material shifting and lifting">Aarya Team - Material
                                    Shifting and Lifting</option>
                                <option value="company team - site expenses">Company Team - Site Expenses</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Budget Amount</label>
                            <div class="input-with-icon" style="position: relative;">
                                <i class="ph ph-currency-inr"
                                    style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--text-secondary);"></i>
                                <input type="number" step="0.01" name="expense_amount[]" placeholder="0.00" required
                                    class="form-input" style="padding-left: 40px;">
                            </div>
                        </div>
                    </div>
                </div>

                <div style="grid-column: span 2;">
                    <button type="button" class="add-more-link" id="addExpenseBtn"
                        style="background: none; border: none; color: #1a56db; font-weight: 500; font-size: 14px; display: flex; align-items: center; gap: 8px; cursor: pointer; padding: 0;">
                        <i class="ph ph-plus-circle" style="font-size: 20px;"></i>
                        Add More Type
                    </button>
                </div>

                <div class="form-group">
                    <label>Start Date</label>
                    <input type="date" name="start_date" required class="form-input">
                </div>

                <div class="form-group">
                    <label>End Date</label>
                    <input type="date" name="end_date" required class="form-input">
                </div>

                <div class="form-group" style="grid-column: span 2;">
                    <label>Project Manager</label>
                    <select name="project_manager_id" required class="form-select">
                        <option value="">Select Manager</option>
                        <?php foreach ($employees as $emp): ?>
                            <option value="<?php echo $emp['id']; ?>"><?php echo htmlspecialchars($emp['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="modal-footer"
                style="padding: 24px; border-top: 1px solid var(--border-color); background: #f9fafb; display: flex; justify-content: flex-end; gap: 12px; margin: 24px -24px -24px;">
                <button type="button" class="btn-ghost" id="cancelProjectBtn"
                    style="background: none; border: none; color: var(--text-secondary); font-weight: 500; cursor: pointer; padding: 10px 16px;">Cancel</button>
                <button type="submit" class="btn-primary" style="padding: 10px 24px;">
                    <i class="ph ph-plus"></i>
                    Add Project
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const modal = document.getElementById('projectModal');
        const openBtn = document.getElementById('openModalBtn');
        const closeBtn = document.getElementById('closeProjectModalBtn');
        const cancelBtn = document.getElementById('cancelProjectBtn');
        const expenseContainer = document.getElementById('expense-container');
        const addExpenseBtn = document.getElementById('addExpenseBtn');
        const activityTypeSelect = document.getElementById('activity_type_select');
        const projectCategoryGroup = document.getElementById('project_category_group');
        const projectCategorySelect = document.getElementById('project_category_select');
        const serviceTypeGroup = document.getElementById('service_type_group');
        const serviceTypeSelect = document.getElementById('service_type_select');
        const projectNameGroup = document.getElementById('project_name_group');
        const projectNameInput = document.getElementById('project_name_input');
        const projectCodeGroup = document.getElementById('project_code_group');
        const projectCodeInput = document.getElementById('project_code_input');

        const updateCategoryAndFields = () => {
            const activityType = activityTypeSelect.value;
            const projectCategory = projectCategorySelect.value;

            // Default hidden states
            projectCategoryGroup.style.display = 'none';
            projectCategorySelect.required = false;
            serviceTypeGroup.style.display = 'none';
            serviceTypeSelect.required = false;
            projectNameGroup.style.display = 'none';
            projectNameInput.required = false;
            projectCodeGroup.style.display = 'none';
            projectCodeInput.required = false;

            if (activityType === 'Projects') {
                projectCategoryGroup.style.display = 'block';
                projectCategorySelect.required = true;

                if (projectCategory === 'Pre Sale') {
                    projectNameGroup.style.display = 'block';
                    projectNameInput.required = true;
                } else if (projectCategory === 'Post Sale') {
                    projectCodeGroup.style.display = 'block';
                    projectCodeInput.required = true;
                }
            } else if (activityType === 'Services' || activityType === 'O&M') {
                serviceTypeGroup.style.display = 'block';
                serviceTypeSelect.required = true;

                projectNameGroup.style.display = 'block';
                projectNameInput.required = true;

                projectCodeGroup.style.display = 'block';
                projectCodeInput.required = true;
            }
        };

        if (activityTypeSelect) {
            activityTypeSelect.addEventListener('change', updateCategoryAndFields);
        }
        if (projectCategorySelect) {
            projectCategorySelect.addEventListener('change', updateCategoryAndFields);
        }
        if (serviceTypeSelect) {
            serviceTypeSelect.addEventListener('change', updateCategoryAndFields);
        }

        if (openBtn) {
            openBtn.addEventListener('click', () => {
                modal.style.display = 'flex';
            });
        }

        const closeModal = () => {
            modal.style.display = 'none';
        };

        if (closeBtn) closeBtn.addEventListener('click', closeModal);
        if (cancelBtn) cancelBtn.addEventListener('click', closeModal);

        window.addEventListener('click', (e) => {
            if (e.target === modal) closeModal();
        });

        const updateExpenseOptions = () => {
            const selects = expenseContainer.querySelectorAll('select[name="expense_type[]"]');
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

        const totalBudgetInput = document.querySelector('input[name="budget"]');

        const calculateTotalBudget = () => {
            const expenseInputs = expenseContainer.querySelectorAll('input[name="expense_amount[]"]');
            let sumExpenses = 0;
            expenseInputs.forEach(input => {
                sumExpenses += parseFloat(input.value) || 0;
            });
            totalBudgetInput.value = sumExpenses.toFixed(2);
        };

        expenseContainer.addEventListener('input', (e) => {
            if (e.target.name === 'expense_amount[]') {
                calculateTotalBudget();
            }
        });

        expenseContainer.addEventListener('change', (e) => {
            if (e.target.name === 'expense_type[]') {
                updateExpenseOptions();
            }
        });

        if (addExpenseBtn) {
            addExpenseBtn.addEventListener('click', () => {
                const newRow = document.createElement('div');
                newRow.className = 'expense-row';
                newRow.style.display = 'grid';
                newRow.style.gridTemplateColumns = '1fr 1fr auto';
                newRow.style.gap = '16px';
                newRow.style.alignItems = 'flex-end';
                newRow.style.marginTop = '8px';
                newRow.innerHTML = `
                <div class="form-group" style="display: flex; flex-direction: column; gap: 8px;">
                    <select name="expense_type[]" required style="width: 100%; padding: 10px 14px; border: 1px solid var(--border-color); border-radius: 8px; font-size: 14px; outline: none;">
                        <option value="">Select Type</option>
                        <option value="contractor - installation work">Contractor - Installation Work</option>
                        <option value="aarya team - material shifting and lifting">Aarya Team - Material Shifting and Lifting</option>
                        <option value="company team - site expenses">Company Team - Site Expenses</option>
                    </select>
                </div>
                <div class="form-group" style="display: flex; flex-direction: column; gap: 8px;">
                    <div class="input-with-icon" style="position: relative;">
                        <i class="ph ph-currency-inr" style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--text-secondary);"></i>
                        <input type="number" step="0.01" name="expense_amount[]" placeholder="0.00" required style="width: 100%; padding: 10px 14px 10px 40px; border: 1px solid var(--border-color); border-radius: 8px; font-size: 14px; outline: none;">
                    </div>
                </div>
                <button type="button" class="remove-row-btn" style="background: none; border: none; color: #ef4444; cursor: pointer; padding: 10px 0;"><i class="ph ph-trash" style="font-size: 18px;"></i></button>
            `;
                expenseContainer.appendChild(newRow);

                newRow.querySelector('.remove-row-btn').addEventListener('click', () => {
                    newRow.remove();
                    updateExpenseOptions();
                    calculateTotalBudget();
                });

                updateExpenseOptions();
            });
        }

        const projectForm = document.getElementById('project-form');
        if (projectForm) {
            projectForm.addEventListener('submit', function (e) {
                // Ensure calculation is final
                calculateTotalBudget();

                const totalBudget = parseFloat(totalBudgetInput.value) || 0;
                if (totalBudget <= 0) {
                    e.preventDefault();
                    const alertBox = document.getElementById('form-error-alert');
                    const alertMsg = document.getElementById('form-error-message');
                    alertMsg.textContent = 'Project budget must be greater than zero. Please add budget amounts to the breakdown items.';
                    alertBox.style.display = 'flex';
                    setTimeout(() => { alertBox.style.display = 'none'; }, 5000);
                }
            });

            // Hide alert when closing modal
            const hideAlert = () => {
                const alertBox = document.getElementById('form-error-alert');
                if (alertBox) alertBox.style.display = 'none';
            };
            if (closeBtn) closeBtn.addEventListener('click', hideAlert);
            if (cancelBtn) cancelBtn.addEventListener('click', hideAlert);
        }
    });
</script>
<?php include 'includes/app_footer.php'; ?>