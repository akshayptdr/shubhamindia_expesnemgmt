<?php
$dir = 'uploads/invoices';
if (is_writable($dir)) {
    echo "Directory is writable\n";
} else {
    echo "Directory is NOT writable\n";
}
?>