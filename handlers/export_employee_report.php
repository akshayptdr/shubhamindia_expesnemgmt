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
    e.name AS employee_name,
    COALESCE(SUM(CASE WHEN pr.status = 'Paid' THEN pr.amount ELSE 0 END), 0) AS total_advance,
    COALESCE(SUM(pri.amount), 0) AS total_voucher,
    COALESCE(SUM(CASE WHEN pr.status = 'Paid' THEN pr.amount ELSE 0 END), 0) - COALESCE(SUM(pri.amount), 0) AS set_off
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
    
    fputcsv($output, ['Employee Name', 'Advance (₹)', 'Voucher (₹)', 'Set Off (₹)']);
    
    foreach ($records as $row) {
        fputcsv($output, [
            $row['employee_name'],
            number_format($row['total_advance'], 2),
            number_format($row['total_voucher'], 2),
            number_format(abs($row['set_off']), 2)
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
    echo '<Column ss:Width="180"/><Column ss:Width="120"/><Column ss:Width="120"/><Column ss:Width="120"/>' . "\n";

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
    echo '</Row>' . "\n";

    // Data rows
    foreach ($records as $row) {
        echo '<Row>';
        echo '<Cell ss:StyleID="default"><Data ss:Type="String">' . htmlspecialchars($row['employee_name']) . '</Data></Cell>';
        echo '<Cell ss:StyleID="money"><Data ss:Type="Number">' . $row['total_advance'] . '</Data></Cell>';
        echo '<Cell ss:StyleID="money"><Data ss:Type="Number">' . $row['total_voucher'] . '</Data></Cell>';
        echo '<Cell ss:StyleID="money"><Data ss:Type="Number">' . abs($row['set_off']) . '</Data></Cell>';
        echo '</Row>' . "\n";
    }

    echo '</Table>' . "\n";
    echo '</Worksheet>' . "\n";
    echo '</Workbook>';
    exit;
}
