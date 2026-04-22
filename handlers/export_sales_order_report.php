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
$project_ids = isset($_GET['project_ids']) ? array_map('intval', (array) $_GET['project_ids']) : [];
$project_ids = array_filter($project_ids, function($id) { return $id > 0; });

// Build query
$query = "SELECT 
    COALESCE(p.project_name, p.project_code) AS project_name,
    p.budget AS total_budget,
    p.sales_order_value,
    e.name AS project_manager,
    ((SELECT IFNULL(SUM(amount), 0) FROM payment_requests WHERE project_id = p.id AND status IN ('Approved', 'Paid')) - 
     (SELECT IFNULL(SUM(pr.amount - (SELECT IFNULL(SUM(inv.amount), 0) FROM payment_request_invoices inv WHERE inv.payment_request_id = pr.id)), 0)
      FROM payment_requests pr
      WHERE pr.project_id = p.id AND pr.status = 'Paid' AND pr.set_off_at IS NOT NULL)) as utilized_budget,
    (SELECT IFNULL(SUM(pri.amount), 0) 
     FROM payment_request_invoices pri 
     JOIN payment_requests pr ON pri.payment_request_id = pr.id 
     WHERE pr.project_id = p.id AND pr.status IN ('Pending', 'Approved', 'Paid')) as voucher_amount
FROM projects p
LEFT JOIN employees e ON p.project_manager_id = e.id";

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
    $query .= " WHERE " . implode(" AND ", $where);
}

$query .= " ORDER BY project_name ASC";
$stmt = $pdo->prepare($query);

foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value, PDO::PARAM_STR);
}

$stmt->execute();
$records = $stmt->fetchAll();

$filename = 'Sales_Order_Report_' . date('Y-m-d');
$fromLabel = $from_period ? date('d-m-Y', strtotime($from_period)) : 'N/A';
$toLabel = $to_period ? date('d-m-Y', strtotime($to_period)) : 'N/A';

if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
    
    fputcsv($output, ['Sales Order Report']);
    fputcsv($output, ['From Period: ' . $fromLabel, 'To Period: ' . $toLabel]);
    fputcsv($output, []);
    
    fputcsv($output, ['Project Name', 'Project Manager', 'Sales Value (₹)', 'Project Cost (₹)', 'Advance (₹)', 'Voucher (₹)', 'Project Cost / Sales Order Value', 'Advance / Sales Order Value', 'Voucher / Sales Order Value']);
    
    $total_sov = 0;
    $total_cost = 0;
    $total_advance = 0;
    $total_voucher = 0;
    
    foreach ($records as $row) {
        $so_val = $row['sales_order_value'];
        $budget = $row['total_budget'];
        $utilized = $row['utilized_budget'];
        $voucher = $row['voucher_amount'];
        
        $total_sov += $so_val;
        $total_cost += $budget;
        $total_advance += $utilized;
        $total_voucher += $voucher;
        
        $percentage_cost = ($so_val > 0) ? ($budget / $so_val) * 100 : 0;
        $percentage_advance = ($so_val > 0) ? ($utilized / $so_val) * 100 : 0;
        $percentage_voucher = ($so_val > 0) ? ($voucher / $so_val) * 100 : 0;
        
        fputcsv($output, [
            $row['project_name'],
            $row['project_manager'] ?? 'Not Assigned',
            number_format($so_val, 2),
            number_format($budget, 2),
            number_format($utilized, 2),
            number_format($voucher, 2),
            number_format($percentage_cost, 2) . '%',
            number_format($percentage_advance, 2) . '%',
            number_format($percentage_voucher, 2) . '%'
        ]);
    }

    fputcsv($output, [
        'GRAND TOTAL',
        '',
        number_format($total_sov, 2),
        number_format($total_cost, 2),
        number_format($total_advance, 2),
        number_format($total_voucher, 2),
        '',
        '',
        ''
    ]);
    
    fclose($output);
    exit;

} elseif ($format === 'excel') {
    header('Content-Type: application/xml');
    header('Content-Disposition: attachment; filename="' . $filename . '.xml"');

    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<?mso-application progid="Excel.Sheet"?>' . "\n";
    echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
     xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">' . "\n";

    echo '<Styles>
      <Style ss:ID="title"><Font ss:Size="14" ss:Bold="1" ss:Color="#1a56db"/></Style>
      <Style ss:ID="subtitle"><Font ss:Size="10" ss:Color="#6b7280"/></Style>
      <Style ss:ID="header"><Font ss:Bold="1" ss:Color="#FFFFFF"/><Interior ss:Color="#1a56db" ss:Pattern="Solid"/><Alignment ss:Horizontal="Center"/></Style>
      <Style ss:ID="money"><NumberFormat ss:Format="#,##0.00"/><Alignment ss:Horizontal="Right"/></Style>
      <Style ss:ID="default"></Style>
    </Styles>' . "\n";

    echo '<Worksheet ss:Name="Sales Order Report">' . "\n";
    echo '<Table>' . "\n";
    
    echo '<Column ss:Width="200"/><Column ss:Width="150"/><Column ss:Width="130"/><Column ss:Width="130"/><Column ss:Width="130"/><Column ss:Width="130"/><Column ss:Width="180"/><Column ss:Width="180"/><Column ss:Width="180"/>' . "\n";

    echo '<Row><Cell ss:StyleID="title"><Data ss:Type="String">Sales Order Report</Data></Cell></Row>' . "\n";
    echo '<Row><Cell ss:StyleID="subtitle"><Data ss:Type="String">From Period: ' . $fromLabel . '    To Period: ' . $toLabel . '</Data></Cell></Row>' . "\n";
    echo '<Row></Row>' . "\n";
    
    echo '<Row>';
    echo '<Cell ss:StyleID="header"><Data ss:Type="String">Project Name</Data></Cell>';
    echo '<Cell ss:StyleID="header"><Data ss:Type="String">Project Manager</Data></Cell>';
    echo '<Cell ss:StyleID="header"><Data ss:Type="String">Sales Value (₹)</Data></Cell>';
    echo '<Cell ss:StyleID="header"><Data ss:Type="String">Project Cost (₹)</Data></Cell>';
    echo '<Cell ss:StyleID="header"><Data ss:Type="String">Advance (₹)</Data></Cell>';
    echo '<Cell ss:StyleID="header"><Data ss:Type="String">Voucher (₹)</Data></Cell>';
    echo '<Cell ss:StyleID="header"><Data ss:Type="String">Project Cost / Sales Order Value</Data></Cell>';
    echo '<Cell ss:StyleID="header"><Data ss:Type="String">Advance / Sales Order Value</Data></Cell>';
    echo '<Cell ss:StyleID="header"><Data ss:Type="String">Voucher / Sales Order Value</Data></Cell>';
    echo '</Row>' . "\n";

    $total_sov = 0;
    $total_cost = 0;
    $total_advance = 0;
    $total_voucher = 0;

    foreach ($records as $row) {
        $so_val = $row['sales_order_value'];
        $budget = $row['total_budget'];
        $utilized = $row['utilized_budget'];
        $voucher = $row['voucher_amount'];
        
        $total_sov += $so_val;
        $total_cost += $budget;
        $total_advance += $utilized;
        $total_voucher += $voucher;
        
        $percentage_cost = ($so_val > 0) ? ($budget / $so_val) * 100 : 0;
        $percentage_advance = ($so_val > 0) ? ($utilized / $so_val) * 100 : 0;
        $percentage_voucher = ($so_val > 0) ? ($voucher / $so_val) * 100 : 0;

        echo '<Row>';
        echo '<Cell ss:StyleID="default"><Data ss:Type="String">' . htmlspecialchars($row['project_name']) . '</Data></Cell>';
        echo '<Cell ss:StyleID="default"><Data ss:Type="String">' . htmlspecialchars($row['project_manager'] ?? 'Not Assigned') . '</Data></Cell>';
        echo '<Cell ss:StyleID="money"><Data ss:Type="Number">' . $so_val . '</Data></Cell>';
        echo '<Cell ss:StyleID="money"><Data ss:Type="Number">' . $budget . '</Data></Cell>';
        echo '<Cell ss:StyleID="money"><Data ss:Type="Number">' . $row['utilized_budget'] . '</Data></Cell>';
        echo '<Cell ss:StyleID="money"><Data ss:Type="Number">' . $row['voucher_amount'] . '</Data></Cell>';
        echo '<Cell ss:StyleID="default"><Data ss:Type="String">' . number_format($percentage_cost, 2) . '%</Data></Cell>';
        echo '<Cell ss:StyleID="default"><Data ss:Type="String">' . number_format($percentage_advance, 2) . '%</Data></Cell>';
        echo '<Cell ss:StyleID="default"><Data ss:Type="String">' . number_format($percentage_voucher, 2) . '%</Data></Cell>';
        echo '</Row>' . "\n";
    }

    echo '<Row>';
    echo '<Cell ss:StyleID="header"><Data ss:Type="String">GRAND TOTAL</Data></Cell>';
    echo '<Cell ss:StyleID="header"><Data ss:Type="String"></Data></Cell>';
    echo '<Cell ss:StyleID="money"><Data ss:Type="Number">' . $total_sov . '</Data></Cell>';
    echo '<Cell ss:StyleID="money"><Data ss:Type="Number">' . $total_cost . '</Data></Cell>';
    echo '<Cell ss:StyleID="money"><Data ss:Type="Number">' . $total_advance . '</Data></Cell>';
    echo '<Cell ss:StyleID="money"><Data ss:Type="Number">' . $total_voucher . '</Data></Cell>';
    echo '<Cell ss:StyleID="header"><Data ss:Type="String"></Data></Cell>';
    echo '<Cell ss:StyleID="header"><Data ss:Type="String"></Data></Cell>';
    echo '<Cell ss:StyleID="header"><Data ss:Type="String"></Data></Cell>';
    echo '</Row>' . "\n";

    echo '</Table>' . "\n";
    echo '</Worksheet>' . "\n";
    echo '</Workbook>';
    exit;
}
?>
