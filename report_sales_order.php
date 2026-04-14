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

// Filters
$from_period = isset($_GET['from_period']) ? $_GET['from_period'] : '';
$to_period = isset($_GET['to_period']) ? $_GET['to_period'] : '';
$project_ids = isset($_GET['project_ids']) ? array_map('intval', (array) $_GET['project_ids']) : [];
$project_ids = array_filter($project_ids, function($id) { return $id > 0; });

$page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$perPage = 10;

// Fetch all projects for dropdown
$projStmt = $pdo->query("SELECT id, COALESCE(project_name, project_code) AS display_name FROM projects ORDER BY COALESCE(project_name, project_code) ASC");
$allProjects = $projStmt->fetchAll();

// Build query
$query = "SELECT 
    id,
    COALESCE(project_name, project_code) AS project_name,
    budget AS total_budget,
    sales_order_value
FROM projects";

$countQuery = "SELECT COUNT(*) FROM projects";

$where = [];
$params = [];

if (!empty($project_ids)) {
    $placeholders = [];
    foreach ($project_ids as $idx => $pid) {
        $key = ':proj_' . $idx;
        $placeholders[] = $key;
        $params[$key] = $pid;
    }
    $where[] = "id IN (" . implode(',', $placeholders) . ")";
}

if ($from_period !== '') {
    $where[] = "created_at >= :from_period";
    $params[':from_period'] = $from_period;
}

if ($to_period !== '') {
    $where[] = "created_at <= :to_period";
    $params[':to_period'] = $to_period . ' 23:59:59';
}

if (!empty($where)) {
    $whereClause = " WHERE " . implode(" AND ", $where);
    $query .= $whereClause;
    $countQuery .= $whereClause;
}

// Get total count
$stmtCount = $pdo->prepare($countQuery);
$stmtCount->execute($params);
$totalRecords = $stmtCount->fetchColumn();

$totalPages = ceil($totalRecords / $perPage);
if ($page > $totalPages && $totalPages > 0) {
    $page = $totalPages;
}

$offset = ($page - 1) * $perPage;

$query .= " ORDER BY project_name ASC LIMIT :limit OFFSET :offset";
$stmt = $pdo->prepare($query);

foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value, PDO::PARAM_STR);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

$stmt->execute();
$records = $stmt->fetchAll();

$current_page = 'report_sales_order';
$page_title = 'Sales Order Report';
$show_search = false;
include 'includes/app_header.php';
?>

<!-- Page Content -->
<div class="page-content">
    <div class="page-header">
        <div class="page-title">
            <h2>Sales Order Report</h2>
            <p>Summary of Project Budgets and Sales Order Values.</p>
        </div>
        <div style="display: flex; gap: 8px;">
            <button onclick="exportReport('csv')" class="btn-primary" style="background: #059669; font-size: 13px; padding: 8px 14px;">
                <i class="ph ph-file-csv"></i> CSV
            </button>
            <button onclick="exportReport('excel')" class="btn-primary" style="background: #1d6f42; font-size: 13px; padding: 8px 14px;">
                <i class="ph ph-file-xls"></i> Excel
            </button>
            <button onclick="exportPDF()" class="btn-primary" style="background: #dc2626; font-size: 13px; padding: 8px 14px;">
                <i class="ph ph-file-pdf"></i> PDF
            </button>
        </div>
    </div>

    <!-- Filter Bar -->
    <form method="GET" action="report_sales_order.php" style="display: flex; gap: 16px; align-items: flex-end; margin-bottom: 24px; flex-wrap: wrap;">
        <div class="form-group" style="flex: 0 0 auto;">
            <label class="form-label">Project</label>
            <div class="multi-select-dropdown" id="projectDropdown" style="width: 250px;">
                <div class="multi-select-trigger" onclick="toggleMultiSelect()">
                    <span class="multi-select-label" id="multiSelectLabel">All Projects</span>
                    <i class="ph ph-caret-down multi-select-arrow"></i>
                </div>
                <div class="multi-select-menu" id="multiSelectMenu">
                    <div class="multi-select-search">
                        <input type="text" placeholder="Search..." id="projSearchInput" oninput="filterProjects()">
                    </div>
                    <div class="multi-select-options" id="multiSelectOptions">
                        <?php foreach ($allProjects as $proj): ?>
                            <label class="multi-select-option">
                                <input type="checkbox" name="project_ids[]" value="<?php echo $proj['id']; ?>"
                                    <?php echo in_array($proj['id'], $project_ids) ? 'checked' : ''; ?>
                                    onchange="updateLabel()">
                                <span><?php echo htmlspecialchars($proj['display_name']); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="form-group" style="flex: 0 0 auto;">
            <label class="form-label">From Period</label>
            <input type="date" name="from_period" class="form-input" value="<?php echo htmlspecialchars($from_period); ?>" style="width: 170px;">
        </div>
        <div class="form-group" style="flex: 0 0 auto;">
            <label class="form-label">To Period</label>
            <input type="date" name="to_period" class="form-input" value="<?php echo htmlspecialchars($to_period); ?>" style="width: 170px;">
        </div>
        <button type="submit" class="btn-primary" style="height: 42px;">
            <i class="ph ph-funnel"></i> Filter
        </button>
        <a href="report_sales_order.php" class="btn-secondary" style="height: 42px; display: flex; align-items: center; text-decoration: none;">
            Clear
        </a>
    </form>

    <!-- Data Table -->
    <div class="data-table-container">
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>Project Name</th>
                        <th style="text-align: right;">Sales Order Value</th>
                        <th style="text-align: right;">Budget</th>
                        <th style="text-align: right;">% of SO vs Budget</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($records) > 0): ?>
                        <?php foreach ($records as $row): ?>
                            <?php 
                                $so_val = $row['sales_order_value'];
                                $budget = $row['total_budget'];
                                $percentage = ($so_val > 0) ? ($budget / $so_val) * 100 : 0;
                            ?>
                            <tr>
                                <td>
                                    <span class="font-medium"><?php echo htmlspecialchars($row['project_name']); ?></span>
                                </td>
                                <td style="text-align: right;">
                                    <span class="font-medium" style="color: #16a34a;">₹<?php echo number_format($so_val, 2); ?></span>
                                </td>
                                <td style="text-align: right;">
                                    <span class="font-medium">₹<?php echo number_format($budget, 2); ?></span>
                                </td>
                                <td style="text-align: right;">
                                    <span class="font-medium" style="color: #4f46e5;"><?php echo number_format($percentage, 2); ?>%</span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="4" style="text-align: center; padding: 40px; color: var(--text-secondary);">
                                No records found.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="table-footer">
            <div class="table-info">
                Showing <?php echo $totalRecords > 0 ? $offset + 1 : 0; ?> to
                <?php echo min($offset + $perPage, $totalRecords); ?> of <?php echo $totalRecords; ?>
                records
            </div>
            <div class="pagination">
                <?php
                    $projParams = '';
                    foreach ($project_ids as $pid) { $projParams .= '&project_ids[]=' . $pid; }
                ?>
                <?php if ($page > 1): ?>
                    <a href="?page=<?php echo $page - 1; ?><?php echo $projParams; ?>&from_period=<?php echo urlencode($from_period); ?>&to_period=<?php echo urlencode($to_period); ?>" class="page-btn"><i class="ph ph-caret-left"></i></a>
                <?php else: ?>
                    <button class="page-btn" disabled style="opacity: 0.5; cursor: not-allowed;"><i class="ph ph-caret-left"></i></button>
                <?php endif; ?>

                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <a href="?page=<?php echo $i; ?><?php echo $projParams; ?>&from_period=<?php echo urlencode($from_period); ?>&to_period=<?php echo urlencode($to_period); ?>"
                        class="page-btn <?php echo $i === $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                <?php endfor; ?>

                <?php if ($page < $totalPages): ?>
                    <a href="?page=<?php echo $page + 1; ?><?php echo $projParams; ?>&from_period=<?php echo urlencode($from_period); ?>&to_period=<?php echo urlencode($to_period); ?>" class="page-btn"><i class="ph ph-caret-right"></i></a>
                <?php else: ?>
                    <button class="page-btn" disabled style="opacity: 0.5; cursor: not-allowed;"><i class="ph ph-caret-right"></i></button>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<style>
.multi-select-dropdown { position: relative; }
.multi-select-trigger {
    display: flex; align-items: center; justify-content: space-between;
    padding: 10px 14px; border: 1px solid var(--border-color); border-radius: 8px;
    background: white; cursor: pointer; font-size: 14px; color: var(--text-primary);
    transition: var(--transition); min-height: 42px;
}
.multi-select-trigger:hover { border-color: #cbd5e1; }
.multi-select-arrow { font-size: 14px; color: var(--text-secondary); transition: transform 0.25s ease; }
.multi-select-dropdown.open .multi-select-arrow { transform: rotate(180deg); }
.multi-select-menu {
    display: none; position: absolute; top: calc(100% + 4px); left: 0; width: 100%;
    background: white; border: 1px solid var(--border-color); border-radius: 8px;
    box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1); z-index: 100; max-height: 260px;
    overflow: hidden; flex-direction: column;
}
.multi-select-dropdown.open .multi-select-menu { display: flex; }
.multi-select-search { padding: 8px; border-bottom: 1px solid var(--border-color); }
.multi-select-search input {
    width: 100%; padding: 8px 10px; border: 1px solid var(--border-color); border-radius: 6px;
    font-size: 13px; outline: none; font-family: inherit;
}
.multi-select-options { overflow-y: auto; max-height: 200px; padding: 4px 0; }
.multi-select-option {
    display: flex; align-items: center; gap: 10px; padding: 8px 14px;
    cursor: pointer; font-size: 14px; color: var(--text-primary); transition: background 0.15s;
}
.multi-select-option:hover { background: #f3f4f6; }
.multi-select-option input[type="checkbox"] { width: 16px; height: 16px; accent-color: var(--primary-color); cursor: pointer; }
.multi-select-label { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; flex: 1; }
</style>

<script>
    function toggleMultiSelect() { document.getElementById('projectDropdown').classList.toggle('open'); }
    document.addEventListener('click', function(e) {
        const dd = document.getElementById('projectDropdown');
        if (dd && !dd.contains(e.target)) { dd.classList.remove('open'); }
    });
    function updateLabel() {
        const checked = document.querySelectorAll('#multiSelectOptions input[type="checkbox"]:checked');
        const label = document.getElementById('multiSelectLabel');
        if (checked.length === 0) { label.textContent = 'All Projects'; }
        else if (checked.length === 1) { label.textContent = checked[0].parentElement.querySelector('span').textContent.trim(); }
        else { label.textContent = checked.length + ' projects selected'; }
    }
    function filterProjects() {
        const search = document.getElementById('projSearchInput').value.toLowerCase();
        document.querySelectorAll('.multi-select-option').forEach(opt => {
            const name = opt.querySelector('span').textContent.toLowerCase();
            opt.style.display = name.includes(search) ? 'flex' : 'none';
        });
    }
    updateLabel();
</script>

<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js"></script>

<script>
    function getFilterParams() {
        let params = '';
        const checkboxes = document.querySelectorAll('#multiSelectOptions input[type="checkbox"]:checked');
        checkboxes.forEach(cb => { params += '&project_ids[]=' + cb.value; });
        const from = document.querySelector('input[name="from_period"]');
        const to = document.querySelector('input[name="to_period"]');
        if (from && from.value) params += '&from_period=' + encodeURIComponent(from.value);
        if (to && to.value) params += '&to_period=' + encodeURIComponent(to.value);
        return params;
    }

    function exportReport(format) {
        const params = getFilterParams();
        window.location.href = 'handlers/export_sales_order_report.php?format=' + format + params;
    }

    function exportPDF() {
        const { jsPDF } = window.jspdf;
        const doc = new jsPDF();

        doc.setFontSize(16);
        doc.setTextColor(26, 86, 219);
        doc.text('Sales Order Report', 14, 18);

        doc.setFontSize(10);
        doc.setTextColor(107, 114, 128);
        doc.text('Shubham India - ' + new Date().toLocaleDateString('en-IN'), 14, 25);

        const fromVal = document.querySelector('input[name="from_period"]').value;
        const toVal = document.querySelector('input[name="to_period"]').value;
        const fromText = fromVal ? new Date(fromVal).toLocaleDateString('en-IN') : 'N/A';
        const toText = toVal ? new Date(toVal).toLocaleDateString('en-IN') : 'N/A';
        doc.text('From Period: ' + fromText + '    To Period: ' + toText, 14, 31);

        const table = document.querySelector('.data-table-container table');
        const headers = [['Project Name', 'Sales Value (Rs.)', 'Budget (Rs.)', '% of SO vs Budget']];
        const rows = [];

        table.querySelectorAll('tbody tr').forEach(tr => {
            const row = [];
            tr.querySelectorAll('td').forEach(td => {
                let text = td.textContent.trim();
                text = text.replace(/₹/g, '').trim();
                row.push(text);
            });
            if (row.length > 0 && row[0] !== 'No records found.') rows.push(row);
        });

        doc.autoTable({
            head: headers,
            body: rows,
            startY: 38,
            styles: { fontSize: 10, cellPadding: 4 },
            headStyles: { fillColor: [26, 86, 219], textColor: 255, fontStyle: 'bold' },
            alternateRowStyles: { fillColor: [249, 250, 251] },
            columnStyles: {
                1: { halign: 'right' },
                2: { halign: 'right' },
                3: { halign: 'right' }
            },
            margin: { left: 14, right: 14 }
        });

        doc.save('Sales_Order_Report_' + new Date().toISOString().slice(0,10) + '.pdf');
    }
</script>

<?php include 'includes/app_footer.php'; ?>
