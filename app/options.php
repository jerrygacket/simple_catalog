<?php
// Database configuration
$db_host = 'percona80_fs';
$db_user = 'root';
$db_pass = 'root';
$db_name = 'simple_fs';

// Connect to database using PDO
try {
    $dsn = "mysql:host=$db_host;dbname=$db_name;charset=utf8mb4";
    $pdo = new PDO($dsn, $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "Connected to database successfully.\n";
} catch (PDOException $e) {
    die('Connection failed: ' . $e->getMessage());
}

// Clear existing data
echo "Clearing existing options and option_values...\n";
$pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
$pdo->exec("TRUNCATE TABLE sku_options");
$pdo->exec("TRUNCATE TABLE option_values");
//$pdo->exec("DELETE FROM option_values");
$pdo->exec("TRUNCATE TABLE options");
$pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");
//$pdo->exec("DELETE FROM options");
//$pdo->exec("ALTER TABLE options AUTO_INCREMENT = 1");
//$pdo->exec("ALTER TABLE option_values AUTO_INCREMENT = 1");
echo "Cleared.\n\n";

// Seed data structure
$options_data = [
    [
        'name' => 'size',
        'display_name' => 'Size',
        'values' => [
            ['value' => '1', 'numeric_value' => 1, 'step' => 1.0],
            ['value' => '2', 'numeric_value' => 2, 'step' => 1.0],
            ['value' => '3', 'numeric_value' => 3, 'step' => 1.0],
            ['value' => '4', 'numeric_value' => 4, 'step' => 1.0],
            ['value' => '5', 'numeric_value' => 5, 'step' => 1.0],
            ['value' => '6', 'numeric_value' => 6, 'step' => 1.0],
            ['value' => '7', 'numeric_value' => 7, 'step' => 1.0],
            ['value' => '8', 'numeric_value' => 8, 'step' => 1.0],
            ['value' => '9', 'numeric_value' => 9, 'step' => 1.0],
            ['value' => '10', 'numeric_value' => 10, 'step' => 1.0],
        ]
    ],
    [
        'name' => 'shoe_size',
        'display_name' => 'Shoe Size (US)',
        'values' => [
            ['value' => '5.0', 'numeric_value' => 5.0, 'step' => 0.5],
            ['value' => '5.5', 'numeric_value' => 5.5, 'step' => 0.5],
            ['value' => '6.0', 'numeric_value' => 6.0, 'step' => 0.5],
            ['value' => '6.5', 'numeric_value' => 6.5, 'step' => 0.5],
            ['value' => '7.0', 'numeric_value' => 7.0, 'step' => 0.5],
            ['value' => '7.5', 'numeric_value' => 7.5, 'step' => 0.5],
            ['value' => '8.0', 'numeric_value' => 8.0, 'step' => 0.5],
            ['value' => '8.5', 'numeric_value' => 8.5, 'step' => 0.5],
            ['value' => '9.0', 'numeric_value' => 9.0, 'step' => 0.5],
            ['value' => '9.5', 'numeric_value' => 9.5, 'step' => 0.5],
            ['value' => '10.0', 'numeric_value' => 10.0, 'step' => 0.5],
            ['value' => '10.5', 'numeric_value' => 10.5, 'step' => 0.5],
            ['value' => '11.0', 'numeric_value' => 11.0, 'step' => 0.5],
            ['value' => '11.5', 'numeric_value' => 11.5, 'step' => 0.5],
            ['value' => '12.0', 'numeric_value' => 12.0, 'step' => 0.5],
        ]
    ],
    [
        'name' => 'color',
        'display_name' => 'Color',
        'values' => [
            ['value' => 'Red', 'numeric_value' => null, 'step' => 1.0],
            ['value' => 'Blue', 'numeric_value' => null, 'step' => 1.0],
            ['value' => 'Green', 'numeric_value' => null, 'step' => 1.0],
            ['value' => 'Black', 'numeric_value' => null, 'step' => 1.0],
            ['value' => 'White', 'numeric_value' => null, 'step' => 1.0],
            ['value' => 'Gray', 'numeric_value' => null, 'step' => 1.0],
            ['value' => 'Navy', 'numeric_value' => null, 'step' => 1.0],
            ['value' => 'Brown', 'numeric_value' => null, 'step' => 1.0],
            ['value' => 'Yellow', 'numeric_value' => null, 'step' => 1.0],
            ['value' => 'Orange', 'numeric_value' => null, 'step' => 1.0],
        ]
    ],
    [
        'name' => 'material',
        'display_name' => 'Material',
        'values' => [
            ['value' => 'Cotton', 'numeric_value' => null, 'step' => 1.0],
            ['value' => 'Polyester', 'numeric_value' => null, 'step' => 1.0],
            ['value' => 'Wool', 'numeric_value' => null, 'step' => 1.0],
            ['value' => 'Silk', 'numeric_value' => null, 'step' => 1.0],
            ['value' => 'Linen', 'numeric_value' => null, 'step' => 1.0],
            ['value' => 'Nylon', 'numeric_value' => null, 'step' => 1.0],
            ['value' => 'Leather', 'numeric_value' => null, 'step' => 1.0],
            ['value' => 'Denim', 'numeric_value' => null, 'step' => 1.0],
        ]
    ],
    [
        'name' => 'weight',
        'display_name' => 'Weight (kg)',
        'values' => [
            ['value' => '0.5', 'numeric_value' => 0.5, 'step' => 0.1],
            ['value' => '1.0', 'numeric_value' => 1.0, 'step' => 0.1],
            ['value' => '1.5', 'numeric_value' => 1.5, 'step' => 0.1],
            ['value' => '2.0', 'numeric_value' => 2.0, 'step' => 0.1],
            ['value' => '2.5', 'numeric_value' => 2.5, 'step' => 0.1],
            ['value' => '3.0', 'numeric_value' => 3.0, 'step' => 0.1],
            ['value' => '3.5', 'numeric_value' => 3.5, 'step' => 0.1],
            ['value' => '4.0', 'numeric_value' => 4.0, 'step' => 0.1],
            ['value' => '4.5', 'numeric_value' => 4.5, 'step' => 0.1],
            ['value' => '5.0', 'numeric_value' => 5.0, 'step' => 0.1],
        ]
    ],
];

// Insert options and option values
echo "Seeding options and option_values...\n\n";

foreach ($options_data as $option_data) {
    // Insert option
    $sql = "INSERT INTO options (name, display_name) VALUES (:name, :display_name)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':name' => $option_data['name'],
        ':display_name' => $option_data['display_name']
    ]);

    $option_id = $pdo->lastInsertId();
    echo "Inserted option: {$option_data['display_name']} (ID: $option_id)\n";

    // Insert option values
    $sql_values = "INSERT INTO option_values (option_id, value, numeric_value, step) 
                   VALUES (:option_id, :value, :numeric_value, :step)";
    $stmt_values = $pdo->prepare($sql_values);

    foreach ($option_data['values'] as $value_data) {
        $stmt_values->execute([
            ':option_id' => $option_id,
            ':value' => $value_data['value'],
            ':numeric_value' => $value_data['numeric_value'],
            ':step' => $value_data['step']
        ]);
    }

    echo "  - Inserted " . count($option_data['values']) . " values\n";
}

echo "\nSeeding completed successfully!\n";
echo "Total options: " . count($options_data) . "\n";

// Display summary
$result = $pdo->query("SELECT COUNT(*) as total FROM options");
$total_options = $result->fetch(PDO::FETCH_ASSOC)['total'];

$result = $pdo->query("SELECT COUNT(*) as total FROM option_values");
$total_values = $result->fetch(PDO::FETCH_ASSOC)['total'];

echo "\nDatabase summary:\n";
echo "- Options: $total_options\n";
echo "- Option values: $total_values\n";
?>