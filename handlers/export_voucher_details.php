<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/voucher_details.php';

voucher_detail_require_access(false);

$req = voucher_detail_request();
$format = $_GET['format'] ?? 'excel';
if (!$req['project_id'] || !$req['category'] || !in_array($format, ['excel', 'zip'], true)) {
    http_response_code(400);
    die('Invalid request');
}

$categories = voucher_categories();
$category_label = $categories[$req['category']]['label'];
$rows = voucher_detail_rows($pdo, $req['project_id'], $req['category'], $req['employee_ids'], $req['from_period'], $req['to_period']);
if (empty($rows)) {
    die('No vouchers found for this selection.');
}

$project_name = $rows[0]['project_name'];
$base_name = 'Vouchers_' . voucher_safe_name($project_name) . '_' . voucher_safe_name($category_label) . '_' . date('Y-m-d');

function voucher_row_cells($r)
{
    return [
        $r['request_no'],
        $r['invoice_no'],
        $r['invoice_date'],
        $r['employee_name'],
        $r['vendor'],
        trim($r['expense_type'] . ($r['expense_subtype'] ? ' - ' . $r['expense_subtype'] : '')),
        $r['amount'],
        $r['remarks'],
    ];
}
$headers = ['Request No', 'Invoice No', 'Invoice Date', 'Employee', 'Vendor', 'Type / Detail', 'Amount', 'Remark'];

if ($format === 'excel') {
    header('Content-Type: application/xml');
    header('Content-Disposition: attachment; filename="' . $base_name . '.xml"');

    $x = function ($v) { return htmlspecialchars((string) $v, ENT_XML1); };

    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<?mso-application progid="Excel.Sheet"?>' . "\n";
    echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">' . "\n";
    echo '<Styles>
      <Style ss:ID="title"><Font ss:Size="14" ss:Bold="1" ss:Color="#1a56db"/></Style>
      <Style ss:ID="subtitle"><Font ss:Size="10" ss:Color="#6b7280"/></Style>
      <Style ss:ID="header"><Font ss:Bold="1" ss:Color="#FFFFFF"/><Interior ss:Color="#1a56db" ss:Pattern="Solid"/><Alignment ss:Horizontal="Center"/></Style>
      <Style ss:ID="money"><NumberFormat ss:Format="#,##0.00"/><Alignment ss:Horizontal="Right"/></Style>
      <Style ss:ID="total"><Font ss:Bold="1"/><NumberFormat ss:Format="#,##0.00"/><Alignment ss:Horizontal="Right"/></Style>
      <Style ss:ID="default"></Style>
    </Styles>' . "\n";
    echo '<Worksheet ss:Name="Vouchers"><Table>' . "\n";
    echo '<Column ss:Width="90"/><Column ss:Width="90"/><Column ss:Width="80"/><Column ss:Width="120"/><Column ss:Width="120"/><Column ss:Width="170"/><Column ss:Width="80"/><Column ss:Width="180"/>' . "\n";
    echo '<Row><Cell ss:StyleID="title"><Data ss:Type="String">' . $x($project_name . ' - ' . $category_label) . ' Vouchers</Data></Cell></Row>' . "\n";
    $period = 'From: ' . ($req['from_period'] ?: 'N/A') . '    To: ' . ($req['to_period'] ?: 'N/A');
    echo '<Row><Cell ss:StyleID="subtitle"><Data ss:Type="String">' . $x($period) . '</Data></Cell></Row>' . "\n";
    echo '<Row></Row>' . "\n";
    echo '<Row>';
    foreach ($headers as $h) {
        echo '<Cell ss:StyleID="header"><Data ss:Type="String">' . $x($h) . '</Data></Cell>';
    }
    echo '</Row>' . "\n";

    $total = 0;
    foreach ($rows as $r) {
        $cells = voucher_row_cells($r);
        $total += (float) $r['amount'];
        echo '<Row>';
        foreach ($cells as $i => $v) {
            if ($i === 6) {
                echo '<Cell ss:StyleID="money"><Data ss:Type="Number">' . (float) $v . '</Data></Cell>';
            } else {
                echo '<Cell ss:StyleID="default"><Data ss:Type="String">' . $x($v) . '</Data></Cell>';
            }
        }
        echo '</Row>' . "\n";
    }
    echo '<Row><Cell ss:Index="6" ss:StyleID="total"><Data ss:Type="String">TOTAL</Data></Cell><Cell ss:StyleID="total"><Data ss:Type="Number">' . $total . '</Data></Cell></Row>' . "\n";
    echo '</Table></Worksheet></Workbook>';
    exit;
}

// ZIP of the uploaded voucher files, with an index.csv listing every voucher
if (!class_exists('ZipArchive')) {
    die('ZIP support (PHP zip extension) is not available on this server.');
}

$tmp = tempnam(sys_get_temp_dir(), 'vch');
$zip = new ZipArchive();
if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
    die('Could not create ZIP file.');
}

$index = fopen('php://temp', 'w+');
fwrite($index, "\xEF\xBB\xBF");
fputcsv($index, array_merge($headers, ['File In ZIP']));

$missing = [];
$added = 0;
foreach ($rows as $i => $r) {
    $abs = voucher_file_abs_path($r['file_path']);
    $zip_name = '';
    if ($abs !== null) {
        $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
        $zip_name = sprintf('%03d_%s_%s_%s_%s.%s', $i + 1,
            voucher_safe_name($r['request_no'], 20),
            voucher_safe_name($r['invoice_no'], 20),
            voucher_safe_name($r['vendor'], 20),
            number_format((float) $r['amount'], 2, '.', ''),
            $ext);
        $zip->addFile($abs, $zip_name);
        $added++;
    } else {
        $missing[] = $r['request_no'] . ' / invoice ' . ($r['invoice_no'] ?: '-') . ' (' . ($r['file_path'] ?: 'no file') . ')';
    }
    fputcsv($index, array_merge(voucher_row_cells($r), [$zip_name !== '' ? $zip_name : 'FILE MISSING']));
}

rewind($index);
$zip->addFromString('index.csv', stream_get_contents($index));
fclose($index);
if (!empty($missing)) {
    $zip->addFromString('missing_files.txt', "These vouchers have no file on the server:\r\n" . implode("\r\n", $missing));
}
$zip->close();

if ($added === 0) {
    unlink($tmp);
    die('None of the vouchers for this selection have an uploaded file.');
}

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $base_name . '.zip"');
header('Content-Length: ' . filesize($tmp));
readfile($tmp);
unlink($tmp);
exit;
