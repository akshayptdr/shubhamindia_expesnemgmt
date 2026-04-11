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
    p.id,
    COALESCE(p.project_name, p.project_code) AS project_name,
    (SELECT COALESCE(SUM(pr2.amount), 0) FROM payment_requests pr2 WHERE pr2.project_id = p.id AND pr2.status = 'Paid') AS total_advance,
    SUM(CASE WHEN (pri.expense_subtype = 'Hotel' OR pri.expense_type = 'ACCOMMODATION') THEN pri.amount ELSE 0 END) AS hotel_bill,
    SUM(CASE WHEN pri.expense_type = 'MATERIAL EXPENSES' THEN pri.amount ELSE 0 END) AS material_bill,
    SUM(CASE WHEN pri.expense_type = 'FOOD & REFRESHMENT' THEN pri.amount ELSE 0 END) AS refreshments_bill,
    SUM(CASE WHEN pri.expense_type = 'Travel Expenses' THEN pri.amount ELSE 0 END) AS traveling_bill,
    SUM(CASE WHEN pri.expense_type = 'LABOUR' THEN pri.amount ELSE 0 END) AS labour_bill,
    SUM(CASE WHEN pr.cost_center LIKE '%contract%' 
        AND pri.expense_type NOT IN ('ACCOMMODATION', 'MATERIAL EXPENSES', 'FOOD & REFRESHMENT', 'Travel Expenses', 'LABOUR')
        AND (pri.expense_subtype <> 'Hotel' OR pri.expense_subtype IS NULL)
        THEN pri.amount ELSE 0 END) AS contract_bill,
    SUM(CASE WHEN pr.cost_center LIKE '%aarya%' 
        AND pri.expense_type NOT IN ('ACCOMMODATION', 'MATERIAL EXPENSES', 'FOOD & REFRESHMENT', 'Travel Expenses', 'LABOUR')
        AND (pri.expense_subtype <> 'Hotel' OR pri.expense_subtype IS NULL)
        THEN pri.amount ELSE 0 END) AS aarya_bill,
    (SELECT COALESCE(SUM(pr4.amount - (SELECT COALESCE(SUM(amount), 0) FROM payment_request_invoices WHERE payment_request_id = pr4.id)), 0)
     FROM payment_requests pr4 
     WHERE pr4.project_id = p.id AND pr4.status = 'Paid' AND pr4.voucher_approved_at IS NOT NULL) AS total_set_off,
    (SELECT COALESCE(SUM(CASE WHEN pr3.voucher_approved_at IS NULL THEN (pr3.amount - (SELECT COALESCE(SUM(amount), 0) FROM payment_request_invoices WHERE payment_request_id = pr3.id)) ELSE 0 END), 0)
     FROM payment_requests pr3 
     WHERE pr3.project_id = p.id AND pr3.status = 'Paid') AS total_pending
FROM projects p
INNER JOIN payment_requests pr ON p.id = pr.project_id AND pr.status = 'Paid'
LEFT JOIN payment_request_invoices pri ON pr.id = pri.payment_request_id";

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

$query .= " GROUP BY p.id, p.project_name, p.project_code ORDER BY project_name ASC";
$stmt = $pdo->prepare($query);

foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value, PDO::PARAM_STR);
}

$stmt->execute();
$records = $stmt->fetchAll();

$filename = 'Voucher_Report_' . date('Y-m-d');
$fromLabel = $from_period ? date('d-m-Y', strtotime($from_period)) : 'N/A';
$toLabel = $to_period ? date('d-m-Y', strtotime($to_period)) : 'N/A';

if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
    
    fputcsv($output, ['Voucher Report']);
    fputcsv($output, ['From Period: ' . $fromLabel, 'To Period: ' . $toLabel]);
    fputcsv($output, []);
    
    fputcsv($output, ['Project Name', 'Adv', 'Hotel', 'Matrial', 'Refresh', 'Travel', 'Labour', 'Contr', 'Aarya', 'Set Off', 'Total', 'Diff']);
    
    $adv_total = 0; $hotel_total = 0; $mat_total = 0; $refresh_total = 0;
    $travel_total = 0; $labour_total = 0; $contract_total = 0; $aarya_total = 0;
    $set_off_total = 0; $g_total = 0; $diff_total = 0;

    foreach ($records as $row) {
        $grand_total = $row['hotel_bill'] + $row['material_bill'] + $row['refreshments_bill'] + 
                      $row['traveling_bill'] + $row['labour_bill'] + $row['contract_bill'] + $row['aarya_bill'];
        $difference = $row['total_pending'];
        
        $adv_total += $row['total_advance'];
        $hotel_total += $row['hotel_bill'];
        $mat_total += $row['material_bill'];
        $refresh_total += $row['refreshments_bill'];
        $travel_total += $row['traveling_bill'];
        $labour_total += $row['labour_bill'];
        $contract_total += $row['contract_bill'];
        $aarya_total += $row['aarya_bill'];
        $set_off_total += $row['total_set_off'];
        $g_total += $grand_total;
        $diff_total += $difference;

        fputcsv($output, [
            $row['project_name'],
            $row['total_advance'],
            $row['hotel_bill'],
            $row['material_bill'],
            $row['refreshments_bill'],
            $row['traveling_bill'],
            $row['labour_bill'],
            $row['contract_bill'],
            $row['aarya_bill'],
            $row['total_set_off'],
            $grand_total,
            $difference
        ]);
    }

    if (!empty($records)) {
        fputcsv($output, []);
        fputcsv($output, ['TOTAL', $adv_total, $hotel_total, $mat_total, $refresh_total, $travel_total, $labour_total, $contract_total, $aarya_total, $set_off_total, $g_total, $diff_total]);
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

    echo '<Worksheet ss:Name="Voucher Report">' . "\n";
    echo '<Table>' . "\n";
    
    echo '<Column ss:Width="200"/>';
    for($i=0; $i<11; $i++) echo '<Column ss:Width="80"/>';
    echo "\n";

    echo '<Row><Cell ss:StyleID="title"><Data ss:Type="String">Voucher Report</Data></Cell></Row>' . "\n";
    echo '<Row><Cell ss:StyleID="subtitle"><Data ss:Type="String">From Period: ' . $fromLabel . '    To Period: ' . $toLabel . '</Data></Cell></Row>' . "\n";
    echo '<Row></Row>' . "\n";
    
    echo '<Row>';
    $headers = ['Project Name', 'Adv', 'Hotel', 'Matrial', 'Refresh', 'Travel', 'Labour', 'Contr', 'Aarya', 'Set Off', 'Total', 'Diff'];
    foreach($headers as $h) {
        echo '<Cell ss:StyleID="header"><Data ss:Type="String">' . $h . '</Data></Cell>';
    }
    echo '</Row>' . "\n";

    $adv_total = 0; $hotel_total = 0; $mat_total = 0; $refresh_total = 0;
    $travel_total = 0; $labour_total = 0; $contract_total = 0; $aarya_total = 0;
    $set_off_total = 0; $g_total = 0; $diff_total = 0;

    foreach ($records as $row) {
        $grand_total = $row['hotel_bill'] + $row['material_bill'] + $row['refreshments_bill'] + 
                      $row['traveling_bill'] + $row['labour_bill'] + $row['contract_bill'] + $row['aarya_bill'];
        $difference = $row['total_pending'];

        $adv_total += $row['total_advance'];
        $hotel_total += $row['hotel_bill'];
        $mat_total += $row['material_bill'];
        $refresh_total += $row['refreshments_bill'];
        $travel_total += $row['traveling_bill'];
        $labour_total += $row['labour_bill'];
        $contract_total += $row['contract_bill'];
        $aarya_total += $row['aarya_bill'];
        $set_off_total += $row['total_set_off'];
        $g_total += $grand_total;
        $diff_total += $difference;

        echo '<Row>';
        echo '<Cell ss:StyleID="default"><Data ss:Type="String">' . htmlspecialchars($row['project_name']) . '</Data></Cell>';
        echo '<Cell ss:StyleID="money"><Data ss:Type="Number">' . $row['total_advance'] . '</Data></Cell>';
        echo '<Cell ss:StyleID="money"><Data ss:Type="Number">' . $row['hotel_bill'] . '</Data></Cell>';
        echo '<Cell ss:StyleID="money"><Data ss:Type="Number">' . $row['material_bill'] . '</Data></Cell>';
        echo '<Cell ss:StyleID="money"><Data ss:Type="Number">' . $row['refreshments_bill'] . '</Data></Cell>';
        echo '<Cell ss:StyleID="money"><Data ss:Type="Number">' . $row['traveling_bill'] . '</Data></Cell>';
        echo '<Cell ss:StyleID="money"><Data ss:Type="Number">' . $row['labour_bill'] . '</Data></Cell>';
        echo '<Cell ss:StyleID="money"><Data ss:Type="Number">' . $row['contract_bill'] . '</Data></Cell>';
        echo '<Cell ss:StyleID="money"><Data ss:Type="Number">' . $row['aarya_bill'] . '</Data></Cell>';
        echo '<Cell ss:StyleID="money"><Data ss:Type="Number">' . $row['total_set_off'] . '</Data></Cell>';
        echo '<Cell ss:StyleID="money"><Data ss:Type="Number">' . $grand_total . '</Data></Cell>';
        echo '<Cell ss:StyleID="money"><Data ss:Type="Number">' . $difference . '</Data></Cell>';
        echo '</Row>' . "\n";
    }

    if (!empty($records)) {
        echo '<Row></Row>' . "\n";
        echo '<Row ss:StyleID="header">';
        echo '<Cell><Data ss:Type="String">TOTAL</Data></Cell>';
        echo '<Cell ss:StyleID="money"><Data ss:Type="Number">' . $adv_total . '</Data></Cell>';
        echo '<Cell ss:StyleID="money"><Data ss:Type="Number">' . $hotel_total . '</Data></Cell>';
        echo '<Cell ss:StyleID="money"><Data ss:Type="Number">' . $mat_total . '</Data></Cell>';
        echo '<Cell ss:StyleID="money"><Data ss:Type="Number">' . $refresh_total . '</Data></Cell>';
        echo '<Cell ss:StyleID="money"><Data ss:Type="Number">' . $travel_total . '</Data></Cell>';
        echo '<Cell ss:StyleID="money"><Data ss:Type="Number">' . $labour_total . '</Data></Cell>';
        echo '<Cell ss:StyleID="money"><Data ss:Type="Number">' . $contract_total . '</Data></Cell>';
        echo '<Cell ss:StyleID="money"><Data ss:Type="Number">' . $aarya_total . '</Data></Cell>';
        echo '<Cell ss:StyleID="money"><Data ss:Type="Number">' . $set_off_total . '</Data></Cell>';
        echo '<Cell ss:StyleID="money"><Data ss:Type="Number">' . $g_total . '</Data></Cell>';
        echo '<Cell ss:StyleID="money"><Data ss:Type="Number">' . $diff_total . '</Data></Cell>';
        echo '</Row>' . "\n";
    }

    echo '</Table>' . "\n";
    echo '</Worksheet>' . "\n";
    echo '</Workbook>';
    exit;
}
