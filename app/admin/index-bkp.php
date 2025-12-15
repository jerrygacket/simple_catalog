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
} catch (PDOException $e) {
    die('Connection failed: ' . $e->getMessage());
}

// Function to get all options with their values
function getOptions($pdo) {
    $options = array();

    // Get all options
    $sql = "SELECT id, name, display_name FROM options ORDER BY id";
    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $row) {
        $option = array(
            'id' => $row['id'],
            'name' => $row['name'],
            'display_name' => $row['display_name'],
            'values' => array()
        );

        // Get option values for this option
        $option_id = $row['id'];
        $sql_values = "SELECT id, value, numeric_value, step 
                       FROM option_values 
                       WHERE option_id = $option_id 
                       ORDER BY numeric_value, value";

        $stmt_values = $pdo->query($sql_values);
        $option['values'] = $stmt_values->fetchAll(PDO::FETCH_ASSOC);

        // Determine option type based on characteristics
//        $option['type'] = 'single'; // default
        $option['type'] = 'multiple';
        if (!empty($option['values']) && $option['values'][0]['numeric_value'] !== null) {
            $option['type'] = 'range';
//        } else {
////        } elseif ($row['name'] == 'color') {
//            $option['type'] = 'multiple';
        }

        $options[] = $option;
    }

    return $options;
}

// Function to get filtered products from database
function getProducts($pdo, $filters = []) {
    $where_conditions = [];
    $having_conditions = [];

    // Base query to get products with SKU counts and total stock
//    $sql = "SELECT DISTINCT
//                p.id,
//                p.name,
//                p.article,
//                COUNT(DISTINCT s.id) as sku_count,
//                SUM(s.count) as total_stock
//            FROM products p
//            INNER JOIN skus s ON p.id = s.product_id";
    // Base query to get products with SKU counts and total stock
    $sql = "SELECT DISTINCT
                p.id
            FROM products p
            INNER JOIN skus s ON p.id = s.product_id";

    // Track if we need to join sku_options table
    $need_sku_options_join = false;

    // Handle in_stock filter
    if (!empty($filters['in_stock'])) {
        $where_conditions[] = "s.count > 0";
    }

    // Collect all option filters that need sku_options join
    $option_filters = [];

    // Process each possible option from the form
    foreach ($filters as $key => $value) {
        // Check for range options (e.g., size_mode, size_from, size_to, size_strict)
        if (preg_match('/^(.+)_mode$/', $key, $matches)) {
            $option_name = $matches[1];
            $mode = $value;

            if ($mode == 'single' && !empty($filters[$option_name . '_single'])) {
                $option_filters[] = [
                    'type' => 'single',
                    'option_name' => $option_name,
                    'value_id' => $filters[$option_name . '_single']
                ];
            } elseif ($mode == 'range' && !empty($filters[$option_name . '_from']) && !empty($filters[$option_name . '_to'])) {
                $option_filters[] = [
                    'type' => 'range',
                    'option_name' => $option_name,
                    'from_id' => $filters[$option_name . '_from'],
                    'to_id' => $filters[$option_name . '_to'],
                    'strict' => !empty($filters[$option_name . '_strict'])
                ];
            }
        }

        // Check for single select options (e.g., material)
        if (!preg_match('/_mode$|_from$|_to$|_single$|_strict$|^in_stock$/', $key)) {
            if (!empty($value) && !is_array($value)) {
                $option_filters[] = [
                    'type' => 'single_select',
                    'option_name' => $key,
                    'value_id' => $value
                ];
            }
        }

        // Check for multiple select options (e.g., color[])
        if (is_array($value) && !empty($value)) {
            $option_filters[] = [
                'type' => 'multiple',
                'option_name' => str_replace('[]', '', $key),
                'value_ids' => array_filter($value)
            ];
        }
    }
//echo '<pre>';var_dump($filters);die;
    // If we have option filters, we need to join and filter
    if (!empty($option_filters)) {
        $need_sku_options_join = true;

        // Join sku_options and option_values
        $sql .= " INNER JOIN sku_options so ON s.id = so.sku_id
                  INNER JOIN option_values ov_start ON so.option_value_id = ov_start.id
                  LEFT JOIN option_values ov_end ON so.range_end_value_id = ov_end.id
                  INNER JOIN options opt ON ov_start.option_id = opt.id";

        // Build conditions for each option filter
        $filter_conditions = [];
//echo '<pre>';print_r($option_filters);echo '</pre>';die();
        foreach ($option_filters as $filter) {
            if ($filter['type'] == 'single') {
                // Single value from range option
                $value_id = intval($filter['value_id']);
                $filter_conditions[] = "(opt.name = '{$filter['option_name']}' AND (
                    (so.is_range = 0 AND ov_start.id = $value_id) OR
                    (so.is_range = 1 AND ov_start.id <= $value_id AND ov_end.id >= $value_id)
                ))";

            } elseif ($filter['type'] == 'range') {
                // Range query
                $from_id = intval($filter['from_id']);
                $to_id = intval($filter['to_id']);

                // Get numeric values for from and to
                $sql_from = "SELECT numeric_value FROM option_values WHERE id = $from_id";
                $sql_to = "SELECT numeric_value FROM option_values WHERE id = $to_id";
                $from_val = $pdo->query($sql_from)->fetchColumn();
                $to_val = $pdo->query($sql_to)->fetchColumn();

                if ($filter['strict']) {
                    // Strict: SKU must be fully contained in search range
                    $filter_conditions[] = "(opt.name = '{$filter['option_name']}' AND (
                        (so.is_range = 0 AND ov_start.numeric_value BETWEEN $from_val AND $to_val) OR
                        (so.is_range = 1 AND ov_start.numeric_value >= $from_val AND ov_end.numeric_value <= $to_val)
                    ))";
                } else {
                    // Loose: any overlap
                    $filter_conditions[] = "(opt.name = '{$filter['option_name']}' AND (
                        (so.is_range = 0 AND ov_start.numeric_value BETWEEN $from_val AND $to_val) OR
                        (so.is_range = 1 AND ov_start.numeric_value <= $to_val AND ov_end.numeric_value >= $from_val)
                    ))";
                }

            } elseif ($filter['type'] == 'single_select') {
                // Single select dropdown
                $value_id = intval($filter['value_id']);
                $filter_conditions[] = "(opt.name = '{$filter['option_name']}' AND ov_start.id = $value_id)";

            } elseif ($filter['type'] == 'multiple') {
                // Multiple select
                if (!empty($filter['value_ids'])) {
                    $value_ids = implode(',', array_map('intval', $filter['value_ids']));
                    $filter_conditions[] = "(opt.name = '{$filter['option_name']}' AND ov_start.id IN ($value_ids))";
                }
            }
        }
//        echo '<pre>';print_r($filter_conditions);echo '</pre>';die();
        if (!empty($filter_conditions)) {
            $where_conditions[] = '(' . implode(' AND ', $filter_conditions) . ')';
        }
    }

    // Add WHERE clause if we have conditions
    if (!empty($where_conditions)) {
        $sql .= " WHERE " . implode(' AND ', $where_conditions);
    }

    // Group by product
    $sql .= " GROUP BY p.id";
//    $sql .= " GROUP BY p.id, p.name, p.article";

    // Add HAVING clause if needed
    if (!empty($having_conditions)) {
        $sql .= " HAVING " . implode(' AND ', $having_conditions);
    }

    // Order by product name
//    $sql .= " ORDER BY p.name";
    $sql .= " LIMIT 50";

    $products = [];
    // Execute query
    $stmt = $pdo->query($sql);
    $productIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (!empty($productIds)) {
        $ids = implode(',', $productIds);
        $sql = "SELECT DISTINCT
                p.id,
                p.name,
                p.article,
                COUNT(DISTINCT s.id) as sku_count,
                SUM(s.count) as total_stock
            FROM products p
            INNER JOIN skus s ON p.id = s.product_id
            where p.id in ($ids)
            GROUP BY p.id";
    $stmt = $pdo->query($sql);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

//var_dump($sql);die();
    // For each product, get options summary
    foreach ($products as &$product) {
        $product['options_summary'] = getProductOptionsSummary($pdo, $product['id']);
    }

    return $products;
}

// Function to get options summary for a product
function getProductOptionsSummary($pdo, $product_id) {
    $summary_parts = [];

    // Get all unique options for this product's SKUs
    $sql = "SELECT DISTINCT
                opt.name,
                opt.display_name,
                GROUP_CONCAT(DISTINCT ov.value ORDER BY ov.numeric_value, ov.value SEPARATOR ', ') as all_values,
                MIN(ov.numeric_value) as min_val,
                MAX(ov.numeric_value) as max_val
            FROM skus s
            INNER JOIN sku_options so ON s.id = so.sku_id
            INNER JOIN option_values ov ON so.option_value_id = ov.id OR so.range_end_value_id = ov.id
            INNER JOIN options opt ON ov.option_id = opt.id
            WHERE s.product_id = $product_id
            GROUP BY opt.id, opt.name, opt.display_name";

    $stmt = $pdo->query($sql);
    $options = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($options as $option) {
        if ($option['min_val'] !== null && $option['max_val'] !== null) {
            // Range option
            if ($option['min_val'] == $option['max_val']) {
                $summary_parts[] = "{$option['display_name']}: {$option['min_val']}";
            } else {
                $summary_parts[] = "{$option['display_name']}: {$option['min_val']}-{$option['max_val']}";
            }
        } else {
            // Non-range option
            $values = explode(', ', $option['all_values']);
            if (count($values) > 3) {
                $summary_parts[] = "{$option['display_name']}: " . implode(', ', array_slice($values, 0, 3)) . "...";
            } else {
                $summary_parts[] = "{$option['display_name']}: {$option['all_values']}";
            }
        }
    }

    return implode(', ', $summary_parts);
}

// Get options from database
$options = getOptions($pdo);

// Get filters from GET parameters
$filters = $_GET;

// Get filtered products from database
$products = getProducts($pdo, $filters);
?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Admin Catalog</title>
    </head>
    <body>
    <h1>Product Catalog</h1>

    <form method="GET" action="/">
        <h2>Filter Options</h2>

        <?php foreach ($options as $option): ?>
            <fieldset>
                <legend><?php echo htmlspecialchars($option['display_name']); ?></legend>

                <?php if ($option['type'] === 'range' && !empty($option['values'])): ?>
                    <?php
                    // Get step value from first value in the array
                    $step = isset($option['values'][0]['step']) ? $option['values'][0]['step'] : 1.0;
                    ?>
                    <label>
                        <input type="radio" name="<?php echo $option['name']; ?>_mode" value="single" <?= empty($filters[$option['name'] . '_mode']) || $filters[$option['name'] . '_mode'] === 'single' ? 'checked' : '' ?>>
                        Single Value:
                        <select name="<?php echo $option['name']; ?>_single">
                            <option value="">-- Select --</option>
                            <?php foreach ($option['values'] as $value): ?>
                                <option value="<?php echo $value['id']; ?>" data-numeric="<?php echo $value['numeric_value']; ?>" <?= ($filters[$option['name'] . '_single'] ?? '') == $value['id'] ? 'selected=""' : '' ?>>
                                    <?php echo htmlspecialchars($value['value']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <br>
                    <label>
                        <input type="radio" name="<?php echo $option['name']; ?>_mode" value="range" <?= ($filters[$option['name'] . '_mode'] ?? '') === 'range' ? 'checked' : '' ?>>
                        Range:
                        From
                        <select name="<?php echo $option['name']; ?>_from">
                            <option value="">--</option>
                            <?php foreach ($option['values'] as $value): ?>
                                <option value="<?php echo $value['id']; ?>" data-numeric="<?php echo $value['numeric_value']; ?>" <?= ($filters[$option['name'] . '_from'] ?? '') == $value['id'] ? 'selected=""' : '' ?>>
                                    <?php echo htmlspecialchars($value['value']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        To
                        <select name="<?php echo $option['name']; ?>_to">
                            <option value="">--</option>
                            <?php foreach ($option['values'] as $value): ?>
                                <option value="<?php echo $value['id']; ?>" data-numeric="<?php echo $value['numeric_value']; ?>" <?= ($filters[$option['name'] . '_to'] ?? '') == $value['id'] ? 'selected=""' : '' ?>>
                                    <?php echo htmlspecialchars($value['value']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <br>
                    <small>Step: <?php echo number_format($step, 2); ?></small>
                    <br>
                    <label>
                        <input type="checkbox" name="<?php echo $option['name']; ?>_strict" value="1" <?= !empty($filters[$option['name'] . '_strict']) ? 'checked' : '' ?>>
                        Strict Match (fully contained in range)
                    </label>

                <?php elseif ($option['type'] === 'multiple'): ?>
                    <select name="<?php echo $option['name']; ?>[]" multiple size="6">
                        <option value="">-- Any <?php echo htmlspecialchars($option['display_name']); ?> --</option>
                        <?php foreach ($option['values'] as $value): ?>
                            <option value="<?php echo $value['id']; ?>" <?= in_array($value['id'], $filters[$option['name']] ?? []) ? 'selected=""' : ''?>>
                                <?php echo htmlspecialchars($value['value']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <br><small>Hold Ctrl/Cmd to select multiple</small>

                <?php elseif ($option['type'] === 'single'): ?>
                    <select name="<?php echo $option['name']; ?>">
                        <option value="">-- Any <?php echo htmlspecialchars($option['display_name']); ?> --</option>
                        <?php foreach ($option['values'] as $value): ?>
                            <option value="<?php echo $value['id']; ?>" <?= $value['id'] == ($filters[$option['name']] ?? '') ? 'selected=""' : ''?>>
                                <?php echo htmlspecialchars($value['value']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
            </fieldset>
        <?php endforeach; ?>

        <fieldset>
            <legend>Availability</legend>
            <label>
                <input type="checkbox" name="in_stock" value="1" <?= !empty($filters['in_stock']) ? 'checked' : ''?>>
                In Stock Only (count > 0)
            </label>
        </fieldset>

        <button type="submit">Search Products</button>
        <button type="reset">Clear Filters</button>
    </form>

    <hr>

    <h2>Search Results</h2>
    <p>Found: <strong><?php echo count($products); ?> products</strong></p>

    <table border="1" cellpadding="8" cellspacing="0">
        <thead>
        <tr>
            <th>ID</th>
            <th>Product Name</th>
            <th>Article</th>
            <th>Available SKUs</th>
            <th>Total Stock</th>
            <th>Available Options</th>
            <th>Actions</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($products as $product): ?>
            <tr>
                <td><?php echo $product['id']; ?></td>
                <td><?php echo htmlspecialchars($product['name']); ?></td>
                <td><?php echo htmlspecialchars($product['article']); ?></td>
                <td><?php echo $product['sku_count']; ?></td>
                <td><?php echo $product['total_stock']; ?></td>
                <td><?php echo htmlspecialchars($product['options_summary']); ?></td>
                <td><a href="product.php?id=<?php echo $product['id']; ?>">View Details</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <hr>

    <h3>Pagination</h3>
    <p>
        <a href="?page=1">« First</a>
        <a href="?page=1">‹ Previous</a>
        <strong>1</strong>
        <a href="?page=2">2</a>
        <a href="?page=3">3</a>
        <a href="?page=2">Next ›</a>
        <a href="?page=3">Last »</a>
    </p>
    </body>
    </html>
<?php
// PDO connection is automatically closed when script ends
?>