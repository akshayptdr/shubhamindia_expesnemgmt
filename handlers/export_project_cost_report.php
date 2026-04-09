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
    p.budget AS project_cost,
    COALESCE(SUM(CASE WHEN pr.status = 'Paid' THEN pr.amount ELSE 0 END), 0) AS total_advance,
    COALESCE((SELECT SUM(pri2.amount) FROM payment_request_invoices pri2 
              INNER JOIN payment_requests pr2 ON pri2.payment_request_id = pr2.id 
              WHERE pr2.project_id = p.id AND pr2.status = 'Paid'), 0) AS total_vouchers
FROM projects p
INNER JOIN payment_requests pr ON p.id = pr.project_id AND pr.status = 'Paid'";

$where = [];
$params = [];

if (!empty($project_ids)) {
    $placeholders = [];
    foreach ($project_ids as $idx => $pid) {
        $key = ':proj_' . $idx;
        $placeholders[] = $key;
        $params[$key] = $pid;
    }
    $where[] = "p.id IN (" . implode(',', $placeholders) . ")";
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

$query .= " GROUP BY p.id, p.project_name, p.project_code, p.budget ORDER BY project_name ASC";
$stmt = $pdo->prepare($query);

foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value, PDO::PARAM_STR);
}

$stmt->execute();
$records = $stmt->fetchAll();

$filename = 'Project_Cost_Report_' . date('Y-m-d');
$fromLabel = $from_period ? date('d-m-Y', strtotime($from_period)) : 'N/A';
$toLabel = $to_period ? date('d-m-Y', strtotime($to_period)) : 'N/A';

if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
    
    fputcsv($output, ['Project Cost Report']);
    fputcsv($output, ['From Period: ' . $fromLabel, 'To Period: ' . $toLabel]);
    fputcsv($output, []);
    
    fputcsv($output, ['Project Name', 'Project Cost (₹)', 'Advance (₹)', 'Vouchers (₹)']);
    
    foreach ($records as $row) {
        fputcsv($output, [
            $row['project_name'],
            number_format($row['project_cost'], 2),
            number_format($row['total_advance'], 2),
            number_format($row['total_vouchers'], 2)
        ]);
    }
    
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

    echo '<Worksheet ss:Name="Project Cost Report">' . "\n";
    echo '<Table>' . "\n";
    
    echo '<Column ss:Width="200"/><Column ss:Width="130"/><Column ss:Width="130"/><Column ss:Width="130"/>' . "\n";

    echo '<Row><Cell ss:StyleID="title"><Data ss:Type="String">Project Cost Report</Data></Cell></Row>' . "\n";
    echo '<Row><Cell ss:StyleID="subtitle"><Data ss:Type="String">From Period: ' . $fromLabel . '    To Period: ' . $toLabel . '</Data></Cell></Row>' . "\n";
    echo '<Row></Row>' . "\n";
    
    echo '<Row>';
    echo '<Cell ss:StyleID="header"><Data ss:Type="String">Project Name</Data></Cell>';
    echo '<Cell ss:StyleID="header"><Data ss:Type="String">Project Cost (₹)</Data></Cell>';
    echo '<Cell ss:StyleID="header"><Data ss:Type="String">Advance (₹)</Data></Cell>';
    echo '<Cell ss:StyleID="header"><Data ss:Type="String">Vouchers (₹)</Data></Cell>';
    echo '</Row>' . "\n";

    foreach ($records as $row) {
        echo '<Row>';
        echo '<Cell ss:StyleID="default"><Data ss:Type="String">' . htmlspecialchars($row['project_name']) . '</Data></Cell>';
        echo '<Cell ss:StyleID="money"><Data ss:Type="Number">' . $row['project_cost'] . '</Data></Cell>';
        echo '<Cell ss:StyleID="money"><Data ss:Type="Number">' . $row['total_advance'] . '</Data></Cell>';
        echo '<Cell ss:StyleID="money"><Data ss:Type="Number">' . $row['total_vouchers'] . '</Data></Cell>';
        echo '</Row>' . "\n";
    }

    echo '</Table>' . "\n";
    echo '</Worksheet>' . "\n";
    echo '</Workbook>';
    exit;
}
