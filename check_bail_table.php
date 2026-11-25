<?php
try {
    $pdo = new PDO('mysql:host=localhost;dbname=fiscal_system', 'root', '');
    $stmt = $pdo->query('DESCRIBE bail_applications');
    $columns = $stmt->fetchAll();
    echo 'Table exists: ' . count($columns) . ' columns';
} catch (Exception $e) {
    echo 'Table does not exist: ' . $e->getMessage();
}
?>
