<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}
require '../includes/db.php';

$format = isset($_GET['format']) ? $_GET['format'] : 'csv';
$from_period = isset($_GET['from_period']) ? $_GET['from_period'] : '';
$to_period = isset($_GET['to_period']) ? $_GET['to_period'] : '';
$employee_ids = isset($_GET['employee_ids']) ? array_map('intval', (array) $_GET['employee_ids']) : [];
$employee_ids = array_filter($employee_ids, function($id) { return $id > 0; });

// Build query
$query = "SELECT 
    e.id,
    e.name AS employee_name,
    COALESCE(SUM(CASE WHEN pr.status = 'Paid' THEN pr.amount ELSE 0 END), 0) AS total_advance,
    COALESCE(SUM(pri.amount), 0) AS total_voucher,
    (SELECT COALESCE(SUM(pr3.amount - (SELECT COALESCE(SUM(amount), 0) FROM payment_request_invoices WHERE payment_request_id = pr3.id)), 0)
     FROM payment_requests pr3 
     WHERE pr3.employee_id = e.id AND pr3.status = 'Paid' AND pr3.voucher_approved_at IS NOT NULL) AS set_off,
    (SELECT COALESCE(SUM(CASE WHEN pr3.voucher_approved_at IS NULL THEN (pr3.amount - (SELECT COALESCE(SUM(amount), 0) FROM payment_request_invoices WHERE payment_request_id = pr3.id)) ELSE 0 END), 0)
     FROM payment_requests pr3 
     WHERE pr3.employee_id = e.id AND pr3.status = 'Paid') AS total_pending
FROM employees e
INNER JOIN payment_requests pr ON e.id = pr.employee_id AND pr.status = 'Paid'
LEFT JOIN payment_request_invoices pri ON pr.id = pri.payment_request_id";

$where = [];
$params = [];

if (!empty($employee_ids)) {
    $placeholders = [];
    foreach ($employee_ids as $idx => $eid) {
        $key = ':emp_' . $idx;
        $placeholders[] = $key;
        $params[$key] = $eid;
    }
    $where[] = "e.id IN (" . implode(',', $placeholders) . ")";
}

if ($from_period !== '') {
    $where[] = "pr.created_at >= :from_period";
    $params[':from_period'] = $from_period;
}

if ($to_period !== '') {
    $where[] = "pr.created_at <= :to_period";
    $params[':to_period'] = $to_period . ' 23:59:59';
}

if (!empty($where)) {
    $query .= " WHERE " . implode(" AND ", $where);
}

$query .= " GROUP BY e.id, e.name ORDER BY e.name ASC";
$stmt = $pdo->prepare($query);

foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value, PDO::PARAM_STR);
}

$stmt->execute();
$records = $stmt->fetchAll();

// Implementation of FIFO logic for status tags in exports
foreach ($records as &$row) {
    if (isset($row['id'])) {
        $emp_id = $row['id'];
        
        // Settlements (Surpluses generated - using Gross)
        $settSql = "SELECT pr.id, COALESCE(p.project_name, p.project_code) as project,
                    (pr.amount - (SELECT COALESCE(SUM(amount), 0) FROM payment_request_invoices WHERE payment_request_id = pr.id)) as surplus_amount
                    FROM payment_requests pr
                    JOIN projects p ON pr.project_id = p.id
                    WHERE pr.employee_id = :eid AND pr.status = 'Paid' AND pr.voucher_approved_at IS NOT NULL
                    ORDER BY pr.voucher_approved_at ASC";
        $settStmt = $pdo->prepare($settSql);
        $settStmt->execute([':eid' => $emp_id]);
        $settlements = $settStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Consumptions (Credits used)
        $consSql = "SELECT pr.id, COALESCE(p.project_name, p.project_code) as project, pr.used_set_off_amount
                    FROM payment_requests pr
                    JOIN projects p ON pr.project_id = p.id
                    WHERE pr.employee_id = :eid AND pr.status = 'Paid' AND pr.used_set_off_amount > 0
                    ORDER BY pr.created_at ASC";
        $consStmt = $pdo->prepare($consSql);
        $consStmt->execute([':eid' => $emp_id]);
        $consumptions = $consStmt->fetchAll(PDO::FETCH_ASSOC);
        
        $tag_parts = [];
        $total_consumed = array_sum(array_column($consumptions, 'used_set_off_amount'));
        $temp_consumed = $total_consumed;
        
        foreach ($settlements as $sett) {
            $amt = (float)$sett['surplus_amount'];
            if ($temp_consumed >= $amt) {
                $status = "Utilized";
                // Try to find project name from bottom of consumptions stack (FIFO approximation)
                if (!empty($consumptions)) {
                    $row_cons = array_pop($consumptions);
                    $status = $row_cons['project'];
                }
                $temp_consumed -= $amt;
            } elseif ($temp_consumed > 0) {
                $status = "Partial";
                $temp_consumed = 0;
            } else {
                $status = "Cash in Hand";
            }
            $tag_parts[] = "₹" . number_format($amt, 0) . ": " . $status;
        }
        $row['status_tags'] = !empty($tag_parts) ? " (" . implode(", ", $tag_parts) . ")" : "";
    } else {
        $row['status_tags'] = "";
    }
}
unset($row);

$filename = 'Employee_Wise_Report_' . date('Y-m-d');

if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');

    $output = fopen('php://output', 'w');
    // BOM for Excel UTF-8 compatibility
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
    
    // Date range info
    $fromLabel = $from_period ? date('d-m-Y', strtotime($from_period)) : 'N/A';
    $toLabel = $to_period ? date('d-m-Y', strtotime($to_period)) : 'N/A';
    fputcsv($output, ['Employee Wise Report']);
    fputcsv($output, ['From Period: ' . $fromLabel, 'To Period: ' . $toLabel]);
    fputcsv($output, []);
    
    fputcsv($output, ['Employee Name', 'Advance (₹)', 'Voucher (₹)', 'Set Off (₹)', 'Pending (₹)']);
    
    foreach ($records as $row) {
        fputcsv($output, [
            $row['employee_name'],
            number_format($row['total_advance'], 2),
            number_format($row['total_voucher'], 2),
            number_format(abs($row['set_off']), 2) . $row['status_tags'],
            number_format($row['total_pending'], 2)
        ]);
    }
    
    fclose($output);
    exit;

} elseif ($format === 'excel') {
    header('Content-Type: application/xml');
    header('Content-Disposition: attachment; filename="' . $filename . '.xml"');

    $fromLabel = $from_period ? date('d-m-Y', strtotime($from_period)) : 'N/A';
    $toLabel = $to_period ? date('d-m-Y', strtotime($to_period)) : 'N/A';

    // Excel XML Spreadsheet 2003 format
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<?mso-application progid="Excel.Sheet"?>' . "\n";
    echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
     xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"
     xmlns:html="http://www.w3.org/TR/REC-html40">' . "\n";

    echo '<Styles>
      <Style ss:ID="title"><Font ss:Size="14" ss:Bold="1" ss:Color="#1a56db"/></Style>
      <Style ss:ID="subtitle"><Font ss:Size="10" ss:Color="#6b7280"/></Style>
      <Style ss:ID="header"><Font ss:Bold="1" ss:Color="#FFFFFF"/><Interior ss:Color="#1a56db" ss:Pattern="Solid"/><Alignment ss:Horizontal="Center"/></Style>
      <Style ss:ID="money"><NumberFormat ss:Format="#,##0.00"/><Alignment ss:Horizontal="Right"/></Style>
      <Style ss:ID="default"></Style>
    </Styles>' . "\n";

    echo '<Worksheet ss:Name="Employee Report">' . "\n";
    echo '<Table>' . "\n";
    
    // Column widths
    echo '<Column ss:Width="180"/><Column ss:Width="100"/><Column ss:Width="100"/><Column ss:Width="100"/><Column ss:Width="100"/>' . "\n";

    // Title row
    echo '<Row><Cell ss:StyleID="title"><Data ss:Type="String">Employee Wise Report</Data></Cell></Row>' . "\n";
    // Date range row
    echo '<Row><Cell ss:StyleID="subtitle"><Data ss:Type="String">From Period: ' . $fromLabel . '    To Period: ' . $toLabel . '</Data></Cell></Row>' . "\n";
    // Empty row
    echo '<Row></Row>' . "\n";
    
    // Header row
    echo '<Row>';
    echo '<Cell ss:StyleID="header"><Data ss:Type="String">Employee Name</Data></Cell>';
    echo '<Cell ss:StyleID="header"><Data ss:Type="String">Advance (₹)</Data></Cell>';
    echo '<Cell ss:StyleID="header"><Data ss:Type="String">Voucher (₹)</Data></Cell>';
    echo '<Cell ss:StyleID="header"><Data ss:Type="String">Set Off (₹)</Data></Cell>';
    echo '<Cell ss:StyleID="header"><Data ss:Type="String">Pending (₹)</Data></Cell>';
    echo '</Row>' . "\n";

    // Data rows
    foreach ($records as $row) {
        echo '<Row>';
        echo '<Cell ss:StyleID="default"><Data ss:Type="String">' . htmlspecialchars($row['employee_name']) . '</Data></Cell>';
        echo '<Cell ss:StyleID="money"><Data ss:Type="Number">' . $row['total_advance'] . '</Data></Cell>';
        echo '<Cell ss:StyleID="money"><Data ss:Type="Number">' . $row['total_voucher'] . '</Data></Cell>';
        echo '<Cell ss:StyleID="money"><Data ss:Type="Number">' . abs($row['set_off']) . '</Data></Cell>';
        echo '<Cell ss:StyleID="money"><Data ss:Type="Number">' . $row['total_pending'] . '</Data></Cell>';
        echo '</Row>' . "\n";
        // Tag row if exists
        if (!empty($row['status_tags'])) {
            echo '<Row><Cell ss:StyleID="subtitle" ss:MergeAcross="4"><Data ss:Type="String">' . htmlspecialchars($row['status_tags']) . '</Data></Cell></Row>' . "\n";
        }
    }

    echo '</Table>' . "\n";
    echo '</Worksheet>' . "\n";
    echo '</Workbook>';
    exit;
}
