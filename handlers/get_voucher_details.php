<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/voucher_details.php';

voucher_detail_require_access(true);
header('Content-Type: application/json');

$req = voucher_detail_request();
if (!$req['project_id'] || !$req['category']) {
    echo json_encode(['success' => false, 'error' => 'Invalid project or category']);
    exit;
}

try {
    $rows = voucher_detail_rows($pdo, $req['project_id'], $req['category'], $req['employee_ids'], $req['from_period'], $req['to_period']);
    $categories = voucher_categories();

    $total = 0;
    $project_name = '';
    $out = [];
    foreach ($rows as $r) {
        $total += (float) $r['amount'];
        $project_name = $r['project_name'];
        $has_file = voucher_file_abs_path($r['file_path']) !== null;
        $out[] = [
            'request_id' => (int) $r['payment_request_id'],
            'request_no' => $r['request_no'],
            'invoice_no' => $r['invoice_no'],
            'invoice_date' => $r['invoice_date'],
            'employee_name' => $r['employee_name'],
            'vendor' => $r['vendor'],
            'type' => trim($r['expense_type'] . ($r['expense_subtype'] ? ' - ' . $r['expense_subtype'] : '')),
            'amount' => (float) $r['amount'],
            'remarks' => $r['remarks'],
            'file_url' => $has_file ? $r['file_path'] : null,
            'has_file' => $has_file,
        ];
    }

    echo json_encode([
        'success' => true,
        'category_label' => $categories[$req['category']]['label'],
        'project_name' => $project_name,
        'total' => $total,
        'file_count' => count(array_filter($out, function ($r) { return $r['has_file']; })),
        'rows' => $out,
    ]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
