<?php
header('Content-Type: application/json');
require_once '../includes/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit;
}

session_start();
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

try {
    $requestId = $_POST['payment_request_id'] ?? null;
    $invoiceNo = $_POST['invoice_no'] ?? null;
    $invoiceDate = $_POST['invoice_date'] ?? null;
    $vendor = $_POST['vendor'] ?? null;
    $amount = $_POST['amount'] ?? null;
    $remarks = $_POST['remarks'] ?? null;

    $expenseType = $_POST['expense_type'] ?? null;
    $expenseSubtype = $_POST['expense_subtype'] ?? null;
    $expenseDetail = $_POST['expense_detail'] ?? null;
    $km = $_POST['km'] ?? null;
    $rate = $_POST['rate'] ?? null;

    if (!$requestId || !$amount) {
        throw new Exception('Missing required fields: Request ID and Amount are mandatory.');
    }

    // Validate if the request is paid and amount does not exceed the remaining limit
    $stmtPR = $pdo->prepare("SELECT amount, status, employee_id FROM payment_requests WHERE id = ?");
    $stmtPR->execute([$requestId]);
    $prData = $stmtPR->fetch(PDO::FETCH_ASSOC);

    if (!$prData) {
        throw new Exception("Payment request not found.");
    }

    $prAmount = (float) ($prData['amount'] ?? 0);
    $prStatus = $prData['status'] ?? '';
    $prEmployeeId = $prData['employee_id'] ?? 0;

    // Ownership check
    if ($prEmployeeId != $_SESSION['user_id']) {
        throw new Exception("Unauthorized: Only the creator of this request can add invoices.");
    }

    if ($prStatus !== 'Paid') {
        throw new Exception("Invoices can only be added to requests that have been marked as 'Paid'.");
    }

    $stmtInv = $pdo->prepare("SELECT IFNULL(SUM(amount), 0) FROM payment_request_invoices WHERE payment_request_id = ?");
    $stmtInv->execute([$requestId]);
    $totalInvoiced = (float) $stmtInv->fetchColumn();

    $remainingAmount = max(0, $prAmount - $totalInvoiced);
    if ((float)$amount > $remainingAmount) {
        throw new Exception("The invoice amount (₹" . $amount . ") exceeds the remaining approved limit (₹" . $remainingAmount . ").");
    }

    // Ensure empty strings are treated as NULL
    $invoiceNo = !empty($invoiceNo) ? $invoiceNo : null;
    $invoiceDate = !empty($invoiceDate) ? $invoiceDate : null;
    $vendor = !empty($vendor) ? $vendor : null;
    $expenseType = !empty($expenseType) ? $expenseType : null;
    $expenseSubtype = !empty($expenseSubtype) ? $expenseSubtype : null;
    $expenseDetail = !empty($expenseDetail) ? $expenseDetail : null;
    $km = !empty($km) ? (float) $km : null;
    $rate = !empty($rate) ? (float) $rate : null;

    // Handle file upload
    if (!isset($_FILES['invoice_file']) || $_FILES['invoice_file']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('File upload failed');
    }

    $file = $_FILES['invoice_file'];
    $allowedTypes = ['application/pdf', 'image/jpeg', 'image/png'];
    if (!in_array($file['type'], $allowedTypes)) {
        throw new Exception('Invalid file type. Only PDF, JPG, and PNG are allowed.');
    }

    $uploadDir = '../uploads/invoices/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $newFileName = 'inv_' . time() . '_' . uniqid() . '.' . $extension;
    $targetPath = $uploadDir . $newFileName;

    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        throw new Exception('Failed to save uploaded file');
    }

    // Save to database
    $stmt = $pdo->prepare("INSERT INTO payment_request_invoices (payment_request_id, invoice_no, vendor, amount, expense_type, expense_subtype, expense_detail, km, rate, invoice_date, remarks, file_path) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $requestId,
        $invoiceNo,
        $vendor,
        $amount,
        $expenseType,
        $expenseSubtype,
        $expenseDetail,
        $km,
        $rate,
        $invoiceDate,
        $remarks,
        'uploads/invoices/' . $newFileName
    ]);

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>