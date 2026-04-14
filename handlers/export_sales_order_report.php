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
    COALESCE(project_name, project_code) AS project_name,
    budget AS total_budget,
    sales_order_value
FROM projects";

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
    
    fputcsv($output, ['Project Name', 'Sales Value (₹)', 'Budget (₹)', '% of SO vs Budget']);
    
    foreach ($records as $row) {
        $so_val = $row['sales_order_value'];
        $budget = $row['total_budget'];
        $percentage = ($so_val > 0) ? ($budget / $so_val) * 100 : 0;
        
        fputcsv($output, [
            $row['project_name'],
            number_format($so_val, 2),
            number_format($budget, 2),
            number_format($percentage, 2) . '%'
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

    echo '<Worksheet ss:Name="Sales Order Report">' . "\n";
    echo '<Table>' . "\n";
    
    echo '<Column ss:Width="200"/><Column ss:Width="130"/><Column ss:Width="130"/><Column ss:Width="130"/>' . "\n";

    echo '<Row><Cell ss:StyleID="title"><Data ss:Type="String">Sales Order Report</Data></Cell></Row>' . "\n";
    echo '<Row><Cell ss:StyleID="subtitle"><Data ss:Type="String">From Period: ' . $fromLabel . '    To Period: ' . $toLabel . '</Data></Cell></Row>' . "\n";
    echo '<Row></Row>' . "\n";
    
    echo '<Row>';
    echo '<Cell ss:StyleID="header"><Data ss:Type="String">Project Name</Data></Cell>';
    echo '<Cell ss:StyleID="header"><Data ss:Type="String">Sales Value (₹)</Data></Cell>';
    echo '<Cell ss:StyleID="header"><Data ss:Type="String">Budget (₹)</Data></Cell>';
    echo '<Cell ss:StyleID="header"><Data ss:Type="String">% of SO vs Budget</Data></Cell>';
    echo '</Row>' . "\n";

    foreach ($records as $row) {
        $so_val = $row['sales_order_value'];
        $budget = $row['total_budget'];
        $percentage = ($so_val > 0) ? ($budget / $so_val) * 100 : 0;

        echo '<Row>';
        echo '<Cell ss:StyleID="default"><Data ss:Type="String">' . htmlspecialchars($row['project_name']) . '</Data></Cell>';
        echo '<Cell ss:StyleID="money"><Data ss:Type="Number">' . $so_val . '</Data></Cell>';
        echo '<Cell ss:StyleID="money"><Data ss:Type="Number">' . $budget . '</Data></Cell>';
        echo '<Cell ss:StyleID="default"><Data ss:Type="String">' . number_format($percentage, 2) . '%</Data></Cell>';
        echo '</Row>' . "\n";
    }

    echo '</Table>' . "\n";
    echo '</Worksheet>' . "\n";
    echo '</Workbook>';
    exit;
}
?>
