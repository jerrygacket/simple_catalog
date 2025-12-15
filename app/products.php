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

if (trim($argv[1] ?? '') !== 'add') {
    // Clear existing data
    echo "Clearing existing products, skus, and sku_options...\n";
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
    $pdo->exec("TRUNCATE TABLE sku_options");
//$pdo->exec("DELETE FROM sku_options");
//$pdo->exec("DELETE FROM skus");
    $pdo->exec("TRUNCATE TABLE skus");
//$pdo->exec("DELETE FROM products");
    $pdo->exec("TRUNCATE TABLE products");
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");
//$pdo->exec("ALTER TABLE products AUTO_INCREMENT = 1");
//$pdo->exec("ALTER TABLE skus AUTO_INCREMENT = 1");
//$pdo->exec("ALTER TABLE sku_options AUTO_INCREMENT = 1");
    echo "Cleared.\n\n";
}


// Get option values from database
function getOptionValuesByName($pdo, $option_name)
{
    $sql = "SELECT ov.id, ov.value, ov.numeric_value 
            FROM option_values ov
            JOIN options o ON ov.option_id = o.id
            WHERE o.name = :name
            ORDER BY ov.numeric_value, ov.value";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':name' => $option_name]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getLastArticle($pdo)
{
    $sql = "SELECT article 
            FROM products order by id desc limit 1";
    $stmt = $pdo->query($sql);
    return $stmt->fetch(PDO::FETCH_COLUMN);
}

// Helper function to find option value ID
function findOptionValueId($values, $search_value)
{
    foreach ($values as $val) {
        if (strcasecmp($val['value'], $search_value) == 0) {
            return $val['id'];
        }
    }
    return null;
}

// Helper function to find size value ID by numeric value
function findSizeValueId($sizes, $numeric_value)
{
    foreach ($sizes as $size) {
        if ($size['numeric_value'] == $numeric_value) {
            return $size['id'];
        }
    }
    return null;
}

// Get all sizes, colors, materials
$sizes = getOptionValuesByName($pdo, 'size');
$colors = getOptionValuesByName($pdo, 'color');
$materials = getOptionValuesByName($pdo, 'material');

if (empty($sizes) || empty($colors) || empty($materials)) {
    die("Error: Please run the options seeder first!\n");
}

$start = 0;
if (trim($argv[1] ?? '') === 'add') {
    $start = intval(intval(getLastArticle($pdo))/1000);
}
//var_dump($start);die();
$end = $start + 100;
// Products data
for ($k = $start; $k < $end; $k++) {
    $products_data = [];
    for ($i = 0; $i < 1000; $i++) {
        $material = $materials[rand(0, count($materials) - 1)]['value'];
        $color = $colors[rand(0, count($colors) - 1)]['value'];
        $skus = [];
        $is_range = rand(0, 1);
        for ($j = rand(0, 9); $j < 10; $j++) {
            $min = rand(1, $is_range ? 8 : 10);
            $skus[] = [
                'size_range' => [$min, $is_range ? rand($min + 1, 10) : $min],
                'color' => $color,
                'material' => $material,
                'count' => rand(0, 10),
                'barcode' => 'BC' . str_pad(($k * 10000 + $i * 100 + $j), 6, '0', STR_PAD_LEFT) . rand(1000, 9999),
            ];
        }
        $products_data[] = [
            'name' => 'Classic T-Shirt ' . str_pad(($k * 1000 + $i), 7, '0', STR_PAD_LEFT),
            'article' => str_pad(($k * 1000 + $i), 7, '0', STR_PAD_LEFT),
            'skus' => $skus
        ];
    }
    echo "Seeding products and SKUs...\n\n";

    $total_products = 0;
    $total_skus = 0;

    foreach ($products_data as $product_data) {
        // Insert product
        $sql = "INSERT INTO products (name, article) VALUES (:name, :article)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':name' => $product_data['name'],
            ':article' => $product_data['article']
        ]);

        $product_id = $pdo->lastInsertId();
        $total_products++;
        echo "Inserted product: {$product_data['name']} (ID: $product_id)\n";

        // Insert SKUs for this product
        foreach ($product_data['skus'] as $sku_data) {
            // Generate barcode
//            $barcode = 'BC' . str_pad($product_id, 4, '0', STR_PAD_LEFT) . rand(1000, 9999);

            // Insert SKU
            $sql_sku = "INSERT INTO skus (product_id, count, barcode) VALUES (:product_id, :count, :barcode)";
            $stmt_sku = $pdo->prepare($sql_sku);
            $stmt_sku->execute([
                ':product_id' => $product_id,
                ':count' => $sku_data['count'],
                ':barcode' => $sku_data['barcode']
            ]);

            $sku_id = $pdo->lastInsertId();
            $total_skus++;

            // Insert SKU options (size)
            $size_from = $sku_data['size_range'][0];
            $size_to = $sku_data['size_range'][1];
            $size_from_id = findSizeValueId($sizes, $size_from);
            $size_to_id = findSizeValueId($sizes, $size_to);

            if ($size_from_id && $size_to_id) {
                $is_range = ($size_from !== $size_to) ? 1 : 0;
                $sql_opt = "INSERT INTO sku_options (sku_id, option_value_id, is_range, range_end_value_id) 
                        VALUES (:sku_id, :option_value_id, :is_range, :range_end_value_id)";
                $stmt_opt = $pdo->prepare($sql_opt);
                $stmt_opt->execute([
                    ':sku_id' => $sku_id,
                    ':option_value_id' => $size_from_id,
                    ':is_range' => $is_range,
                    ':range_end_value_id' => $is_range ? $size_to_id : null
                ]);
            }

            // Insert SKU options (color)
            if (isset($sku_data['color'])) {
                $color_id = findOptionValueId($colors, $sku_data['color']);
                if ($color_id) {
                    $sql_opt = "INSERT INTO sku_options (sku_id, option_value_id, is_range) 
                            VALUES (:sku_id, :option_value_id, 0)";
                    $stmt_opt = $pdo->prepare($sql_opt);
                    $stmt_opt->execute([
                        ':sku_id' => $sku_id,
                        ':option_value_id' => $color_id
                    ]);
                }
            }

            // Insert SKU options (material)
            if (isset($sku_data['material'])) {
                $material_id = findOptionValueId($materials, $sku_data['material']);
                if ($material_id) {
                    $sql_opt = "INSERT INTO sku_options (sku_id, option_value_id, is_range) 
                            VALUES (:sku_id, :option_value_id, 0)";
                    $stmt_opt = $pdo->prepare($sql_opt);
                    $stmt_opt->execute([
                        ':sku_id' => $sku_id,
                        ':option_value_id' => $material_id
                    ]);
                }
            }
        }

        echo "  - Inserted " . count($product_data['skus']) . " SKUs\n";
    }

    echo "\nSeeding completed successfully!\n";
    echo "\nDatabase summary:\n";
    echo "- Products: $total_products\n";
    echo "- SKUs: $total_skus\n";

// Display detailed summary
    $result = $pdo->query("SELECT COUNT(*) as total FROM sku_options");
    $total_sku_options = $result->fetch(PDO::FETCH_ASSOC)['total'];
    echo "- SKU options: $total_sku_options\n";

// Show sample data
    echo "\nSample SKUs:\n";
    $sql = "SELECT s.id, s.barcode, s.count, p.name as product_name
        FROM skus s
        JOIN products p ON s.product_id = p.id
        LIMIT 5";
    $result = $pdo->query($sql);
    while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
        echo "  SKU #{$row['id']}: {$row['product_name']} - Barcode: {$row['barcode']}, Stock: {$row['count']}\n";
    }
}
//$products_data = [];
//for ($i = 0; $i < 1_000; $i++) {
//    $material = $materials[rand(0, count($materials) - 1)];
//    $color = $colors[rand(0, count($colors) - 1)];
//    $skus = [];
//    for ($j = rand(0,9); $j < 10; $j++) {
//        $min = rand(1, 10);
//        $skus[] = [
//            ['size_range' => [$min, rand($min, 10)], 'color' => $color, 'material' => $material, 'count' => rand(0,10)],
//        ];
//    }
//    $products_data[] = [
//        'name' => 'Classic T-Shirt ' . str_pad($i, 7, '0', STR_PAD_LEFT),
//        'article' => str_pad($i, 7, '0', STR_PAD_LEFT),
//        'skus' => $skus
//    ];
//}
//$products_data = [
//    [
//        'name' => 'Classic T-Shirt',
//        'article' => 'TSH-001',
//        'skus' => [
//            // Single size SKUs with different colors
//            ['size_range' => [3, 3], 'color' => 'Red', 'count' => 25],
//            ['size_range' => [3, 3], 'color' => 'Blue', 'count' => 30],
//            ['size_range' => [4, 4], 'color' => 'Red', 'count' => 35],
//            ['size_range' => [4, 4], 'color' => 'Blue', 'count' => 40],
//            ['size_range' => [5, 5], 'color' => 'Red', 'count' => 45],
//            ['size_range' => [5, 5], 'color' => 'Blue', 'count' => 50],
//            ['size_range' => [5, 5], 'color' => 'White', 'count' => 20],
//        ]
//    ],
//    [
//        'name' => 'Premium Polo Shirt',
//        'article' => 'PLO-002',
//        'skus' => [
//            // Range SKUs
//            ['size_range' => [1, 5], 'color' => 'Black', 'material' => 'Cotton', 'count' => 50],
//            ['size_range' => [6, 10], 'color' => 'Black', 'material' => 'Cotton', 'count' => 60],
//            ['size_range' => [1, 5], 'color' => 'Navy', 'material' => 'Cotton', 'count' => 45],
//            ['size_range' => [6, 10], 'color' => 'Navy', 'material' => 'Cotton', 'count' => 55],
//            ['size_range' => [3, 7], 'color' => 'White', 'material' => 'Cotton', 'count' => 70],
//        ]
//    ],
//    [
//        'name' => 'Sport Jersey',
//        'article' => 'JER-003',
//        'skus' => [
//            ['size_range' => [4, 4], 'color' => 'Red', 'material' => 'Polyester', 'count' => 15],
//            ['size_range' => [5, 5], 'color' => 'Red', 'material' => 'Polyester', 'count' => 20],
//            ['size_range' => [6, 6], 'color' => 'Red', 'material' => 'Polyester', 'count' => 25],
//            ['size_range' => [4, 4], 'color' => 'Blue', 'material' => 'Polyester', 'count' => 10],
//            ['size_range' => [5, 5], 'color' => 'Blue', 'material' => 'Polyester', 'count' => 15],
//            ['size_range' => [6, 6], 'color' => 'Blue', 'material' => 'Polyester', 'count' => 20],
//        ]
//    ],
//    [
//        'name' => 'Wool Sweater',
//        'article' => 'SWT-005',
//        'skus' => [
//            ['size_range' => [3, 9], 'color' => 'Black', 'material' => 'Wool', 'count' => 40],
//            ['size_range' => [3, 9], 'color' => 'Gray', 'material' => 'Wool', 'count' => 35],
//            ['size_range' => [3, 9], 'color' => 'Brown', 'material' => 'Wool', 'count' => 30],
//        ]
//    ],
//    [
//        'name' => 'Casual Hoodie',
//        'article' => 'HOD-007',
//        'skus' => [
//            ['size_range' => [1, 10], 'color' => 'Black', 'count' => 100],
//            ['size_range' => [1, 10], 'color' => 'Gray', 'count' => 90],
//            ['size_range' => [1, 10], 'color' => 'Navy', 'count' => 80],
//            ['size_range' => [5, 8], 'color' => 'Red', 'count' => 50],
//        ]
//    ],
//    [
//        'name' => 'Summer Dress',
//        'article' => 'DRS-010',
//        'skus' => [
//            ['size_range' => [2, 6], 'color' => 'Blue', 'material' => 'Cotton', 'count' => 45],
//            ['size_range' => [2, 6], 'color' => 'Yellow', 'material' => 'Cotton', 'count' => 40],
//            ['size_range' => [4, 8], 'color' => 'White', 'material' => 'Linen', 'count' => 35],
//        ]
//    ],
//];