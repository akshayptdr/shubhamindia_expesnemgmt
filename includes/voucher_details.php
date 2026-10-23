<?php
// Shared helpers for the Voucher Report drill-down (pop-up, Excel and ZIP).
// The category conditions mirror the SUM(CASE ...) columns in report_voucher.php
// (aliases: pr = payment_requests, pri = payment_request_invoices) so totals match.

function voucher_categories()
{
    $other_types = "pri.expense_type NOT IN ('ACCOMMODATION', 'MATERIAL EXPENSES', 'FOOD & REFRESHMENT', 'Travel Expenses', 'LABOUR')
        AND (pri.expense_subtype <> 'Hotel' OR pri.expense_subtype IS NULL)";

    return [
        'hotel' => ['label' => 'Hotel Bill', 'sql' => "(pri.expense_subtype = 'Hotel' OR pri.expense_type = 'ACCOMMODATION')"],
        'material' => ['label' => 'Material', 'sql' => "pri.expense_type = 'MATERIAL EXPENSES'"],
        'refreshments' => ['label' => 'Refreshments', 'sql' => "pri.expense_type = 'FOOD & REFRESHMENT'"],
        'traveling' => ['label' => 'Traveling', 'sql' => "pri.expense_type = 'Travel Expenses'"],
        'labour' => ['label' => 'Labour', 'sql' => "pri.expense_type = 'LABOUR'"],
        'contract' => ['label' => 'Contract', 'sql' => "(pr.cost_center LIKE '%contract%' AND $other_types)"],
        'aarya' => ['label' => 'Aarya', 'sql' => "(pr.cost_center LIKE '%aarya%' AND $other_types)"],
    ];
}

// Reads and validates the request filters shared by the pop-up, Excel and ZIP handlers.
function voucher_detail_request()
{
    $categories = voucher_categories();
    $category = $_GET['category'] ?? '';
    $employee_ids = array_filter(array_map('intval', (array) ($_GET['employee_ids'] ?? [])), function ($id) {
        return $id > 0;
    });

    return [
        'project_id' => (int) ($_GET['project_id'] ?? 0),
        'category' => isset($categories[$category]) ? $category : null,
        'employee_ids' => array_values($employee_ids),
        'from_period' => $_GET['from_period'] ?? '',
        'to_period' => $_GET['to_period'] ?? '',
    ];
}

function voucher_detail_rows($pdo, $project_id, $category, $employee_ids, $from_period, $to_period)
{
    $categories = voucher_categories();
    $where = ["pr.project_id = :project_id", "pr.status = 'Paid'", $categories[$category]['sql']];
    $params = [':project_id' => $project_id];

    if (!empty($employee_ids)) {
        $placeholders = [];
        foreach ($employee_ids as $idx => $eid) {
            $key = ':emp_' . $idx;
            $placeholders[] = $key;
            $params[$key] = $eid;
        }
        $where[] = "pr.employee_id IN (" . implode(',', $placeholders) . ")";
    }
    if ($from_period !== '') {
        $where[] = "pr.created_at >= :from_period";
        $params[':from_period'] = $from_period;
    }
    if ($to_period !== '') {
        $where[] = "pr.created_at <= :to_period";
        $params[':to_period'] = $to_period . ' 23:59:59';
    }

    $sql = "SELECT pri.id, pri.payment_request_id, pri.invoice_no, pri.invoice_date, pri.vendor, pri.amount,
                   pri.expense_type, pri.expense_subtype, pri.expense_detail, pri.remarks, pri.file_path, pri.created_at AS added_at,
                   pr.request_no, pr.cost_center, e.name AS employee_name,
                   COALESCE(NULLIF(TRIM(p.project_name), ''), NULLIF(TRIM(p.project_code), ''), CONCAT('Project #', p.id)) AS project_name
            FROM payment_request_invoices pri
            INNER JOIN payment_requests pr ON pri.payment_request_id = pr.id
            INNER JOIN projects p ON pr.project_id = p.id
            LEFT JOIN employees e ON pr.employee_id = e.id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY pr.created_at ASC, pri.id ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// Absolute path of an uploaded voucher, or null when missing or outside uploads/.
function voucher_file_abs_path($file_path)
{
    if (empty($file_path)) {
        return null;
    }
    $uploads_root = realpath(__DIR__ . '/../uploads');
    $abs = realpath(__DIR__ . '/../' . $file_path);
    if ($uploads_root === false || $abs === false || !is_file($abs)) {
        return null;
    }
    if (strpos($abs, $uploads_root . DIRECTORY_SEPARATOR) !== 0) {
        return null;
    }
    return $abs;
}

function voucher_safe_name($text, $max = 40)
{
    $text = preg_replace('/[^A-Za-z0-9._-]+/', '_', trim((string) $text));
    $text = trim($text, '_.');
    return substr($text !== '' ? $text : 'NA', 0, $max);
}

// Same access rule as report_voucher.php. Call after session_start().
function voucher_detail_require_access($as_json = true)
{
    $denied = !isset($_SESSION['user_id'])
        || in_array($_SESSION['user_role'] ?? '', ['Fitter', 'Senior Fitter', 'Engineer', 'Senior Engineer']);
    if ($denied) {
        http_response_code(403);
        if ($as_json) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Not allowed']);
        } else {
            echo 'Not allowed';
        }
        exit;
    }
}
