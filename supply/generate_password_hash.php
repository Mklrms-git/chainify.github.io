<?php
// Quick script to generate password hash for supplier123
// Run this file in browser or CLI to get the correct hash

$password = 'supplier123';
$hash = password_hash($password, PASSWORD_DEFAULT);

echo "Password: " . $password . "\n";
echo "Hash: " . $hash . "\n";
echo "\n";
echo "SQL Update Query:\n";
echo "UPDATE users SET password = '" . $hash . "' WHERE username = 'supplier1';\n";
echo "\n";
echo "Or use this in INSERT:\n";
echo "INSERT INTO users (username, email, password, role, full_name, status) VALUES\n";
echo "('supplier1', 'supplier1@supplychain.com', '" . $hash . "', 'supplier', 'Supplier User', 'active');\n";

// Verify the hash
if (password_verify($password, $hash)) {
    echo "\n✓ Hash verification successful!\n";
} else {
    echo "\n✗ Hash verification failed!\n";
}
?>

