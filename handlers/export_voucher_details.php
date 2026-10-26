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
    if (!class_exists('ZipArchive')) {
        die('Excel export needs the PHP zip extension, which is not available on this server.');
    }

    // Cell = [value, style, isNumber]. Styles: 0 default, 1 title, 2 header, 3 money, 4 total label, 5 total money, 6 subtitle
    $sheet = [];
    $sheet[] = [[$project_name . ' - ' . $category_label . ' Vouchers', 1, false]];
    $sheet[] = [['From: ' . ($req['from_period'] ?: 'N/A') . '    To: ' . ($req['to_period'] ?: 'N/A'), 6, false]];
    $sheet[] = [];
    $head = [];
    foreach ($headers as $h) {
        $head[] = [$h, 2, false];
    }
    $sheet[] = $head;

    $total = 0;
    foreach ($rows as $r) {
        $line = [];
        foreach (voucher_row_cells($r) as $i => $v) {
            $line[] = $i === 6 ? [(float) $v, 3, true] : [(string) $v, 0, false];
        }
        $total += (float) $r['amount'];
        $sheet[] = $line;
    }
    $sheet[] = [5 => ['TOTAL', 4, false], 6 => [$total, 5, true]];

    $col_letters = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];
    $xml_rows = '';
    foreach ($sheet as $ri => $line) {
        $xml_rows .= '<row r="' . ($ri + 1) . '">';
        foreach ($line as $ci => $cell) {
            $ref = $col_letters[$ci] . ($ri + 1);
            if ($cell[2]) {
                $xml_rows .= '<c r="' . $ref . '" s="' . $cell[1] . '"><v>' . $cell[0] . '</v></c>';
            } else {
                $xml_rows .= '<c r="' . $ref . '" s="' . $cell[1] . '" t="inlineStr"><is><t xml:space="preserve">'
                    . htmlspecialchars($cell[0], ENT_XML1) . '</t></is></c>';
            }
        }
        $xml_rows .= '</row>';
    }

    $widths = [14, 16, 14, 22, 22, 32, 14, 36];
    $cols = '';
    foreach ($widths as $i => $w) {
        $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $w . '" customWidth="1"/>';
    }

    $files = [
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
        'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Vouchers" sheetId="1" r:id="rId1"/></sheets></workbook>',
        'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
        'xl/styles.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="5"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="14"/><color rgb="FF1A56DB"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font><font><sz val="10"/><color rgb="FF6B7280"/><name val="Calibri"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF1A56DB"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="7"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/><xf numFmtId="0" fontId="2" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="center"/></xf><xf numFmtId="4" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment horizontal="right"/></xf><xf numFmtId="4" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1" applyNumberFormat="1"/><xf numFmtId="0" fontId="4" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>',
        'xl/worksheets/sheet1.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><cols>' . $cols . '</cols><sheetData>' . $xml_rows . '</sheetData></worksheet>',
    ];

    $tmp = tempnam(sys_get_temp_dir(), 'vxl');
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
        die('Could not create Excel file.');
    }
    foreach ($files as $name => $content) {
        $zip->addFromString($name, $content);
    }
    $zip->close();

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $base_name . '.xlsx"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    unlink($tmp);
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
