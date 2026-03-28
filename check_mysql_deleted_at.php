<?php
// Check if deleted_at column exists in MySQL calls table
require_once __DIR__ . '/vendor/autoload.php';

$host = getenv('DB_HOST') ?: 'localhost';
$db = getenv('DB_DATABASE') ?: 'pbx-reporting-db';
$user = getenv('DB_USERNAME') ?: 'root';
$pass = getenv('DB_PASSWORD') ?: '';
$port = getenv('DB_PORT') ?: 3306;

try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "Connected to: $db\n\n";
    
    // Check calls table columns
    $sql = "SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'calls'";
    $result = $pdo->prepare($sql)->execute([$db]);
    $columns = $pdo->query($sql, [$db])->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Columns in calls table:\n";
    $hasDeletedAt = false;
    foreach ($columns as $col) {
        echo "  - {$col['COLUMN_NAME']} ({$col['COLUMN_TYPE']}) " . ($col['IS_NULLABLE'] === 'YES' ? 'NULL' : 'NOT NULL') . "\n";
        if ($col['COLUMN_NAME'] === 'deleted_at') {
            $hasDeletedAt = true;
        }
    }
    
    echo "\n" . ($hasDeletedAt ? "✓ deleted_at column EXISTS" : "✗ deleted_at column NOT FOUND") . "\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
