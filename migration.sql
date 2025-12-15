-- Drop tables and foreign keys if they exist
-- Drop in reverse order to respect foreign key constraints
-- Compatible with Percona 8.0 / MySQL 8.0+

-- Disable foreign key checks temporarily for cleaner drops
SET FOREIGN_KEY_CHECKS = 0;

-- Drop tables (will also drop associated indexes and foreign keys)
DROP TABLE IF EXISTS option_params;
DROP TABLE IF EXISTS sku_options;
DROP TABLE IF EXISTS params;
DROP TABLE IF EXISTS option_values;
DROP TABLE IF EXISTS options;
DROP TABLE IF EXISTS skus;
DROP TABLE IF EXISTS products;

-- Re-enable foreign key checks
SET FOREIGN_KEY_CHECKS = 1;

-- Drop tables
DROP TABLE IF EXISTS option_params;
DROP TABLE IF EXISTS sku_options;
DROP TABLE IF EXISTS params;
DROP TABLE IF EXISTS option_values;
DROP TABLE IF EXISTS options;
DROP TABLE IF EXISTS skus;
DROP TABLE IF EXISTS products;

-- Products table: base product information
CREATE TABLE products (
                          id BIGINT PRIMARY KEY AUTO_INCREMENT,
                          name VARCHAR(255) NOT NULL,
                          article VARCHAR(100) NOT NULL,
                          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                          updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                          INDEX idx_article (article),
                          INDEX idx_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- SKUs table: specific stock keeping units
CREATE TABLE skus (
                      id BIGINT PRIMARY KEY AUTO_INCREMENT,
                      product_id BIGINT NOT NULL,
                      count INT NOT NULL DEFAULT 0,
                      barcode VARCHAR(50),
                      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                      updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                      FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
                      INDEX idx_product_id (product_id),
                      INDEX idx_barcode (barcode),
                      INDEX idx_count (count)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Options table: different option types (e.g., color, size, material)
CREATE TABLE options (
                         id BIGINT PRIMARY KEY AUTO_INCREMENT,
                         name VARCHAR(100) NOT NULL,
                         display_name VARCHAR(100) NOT NULL,
                         created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                         UNIQUE KEY unique_name (name),
                         INDEX idx_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Option values table: specific values for options (e.g., "Red", "Large", "Cotton")
CREATE TABLE option_values (
                               id BIGINT PRIMARY KEY AUTO_INCREMENT,
                               option_id BIGINT NOT NULL,
                               value VARCHAR(255) NOT NULL,
    -- For range support: numeric representation of value (for sortable/comparable values)
                               numeric_value DECIMAL(10,2) DEFAULT NULL,
    -- Step: increment value for range selections (e.g., 0.5 for sizes like 5.5, 6.0, 6.5)
                               step DECIMAL(10,2) DEFAULT 1.00,
                               created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                               FOREIGN KEY (option_id) REFERENCES options(id) ON DELETE CASCADE,
                               INDEX idx_option_id (option_id),
                               INDEX idx_value (value),
                               INDEX idx_numeric_value (numeric_value)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- SKU options table: links SKUs to their specific option values
-- Supports both single values and ranges
CREATE TABLE sku_options (
                             id BIGINT PRIMARY KEY AUTO_INCREMENT,
                             sku_id BIGINT NOT NULL,
                             option_value_id BIGINT NOT NULL,
    -- For range support: if is_range=true, this represents range start
                             is_range BOOLEAN DEFAULT FALSE,
                             range_end_value_id BIGINT DEFAULT NULL,
                             created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                             FOREIGN KEY (sku_id) REFERENCES skus(id) ON DELETE CASCADE,
                             FOREIGN KEY (option_value_id) REFERENCES option_values(id) ON DELETE CASCADE,
                             FOREIGN KEY (range_end_value_id) REFERENCES option_values(id) ON DELETE CASCADE,
                             UNIQUE KEY unique_sku_option (sku_id, option_value_id),
                             INDEX idx_sku_id (sku_id),
                             INDEX idx_option_value_id (option_value_id),
                             INDEX idx_is_range (is_range)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Params table: additional parameters that can be associated with options
CREATE TABLE params (
                        id BIGINT PRIMARY KEY AUTO_INCREMENT,
                        name VARCHAR(100) NOT NULL,
                        data_type ENUM('string', 'number', 'boolean', 'json') NOT NULL DEFAULT 'string',
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        UNIQUE KEY unique_name (name),
                        INDEX idx_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Option params table: links option values to their parameters with values
CREATE TABLE option_params (
                               id BIGINT PRIMARY KEY AUTO_INCREMENT,
                               option_value_id BIGINT NOT NULL,
                               param_id BIGINT NOT NULL,
                               value TEXT NOT NULL,
                               created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                               FOREIGN KEY (option_value_id) REFERENCES option_values(id) ON DELETE CASCADE,
                               FOREIGN KEY (param_id) REFERENCES params(id) ON DELETE CASCADE,
                               INDEX idx_option_value_id (option_value_id),
                               INDEX idx_param_id (param_id),
                               INDEX idx_param_value (param_id, value(100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Example queries for range-based SKUs:

-- Insert size option values (1-10)
-- INSERT INTO option_values (option_id, value, numeric_value) VALUES
-- (1, '1', 1), (1, '2', 2), (1, '3', 3), ... (1, '10', 10);

-- Insert SKU with size range 1-10
-- First, get the IDs for size values 1 and 10
-- INSERT INTO sku_options (sku_id, option_value_id, is_range, range_end_value_id)
-- VALUES (101, 1, TRUE, 10);

-- Query 1: Find all SKUs that fit a SINGLE size (e.g., size 5)
-- SELECT DISTINCT s.* FROM skus s
-- JOIN sku_options so ON s.id = so.sku_id
-- JOIN option_values ov_start ON so.option_value_id = ov_start.id
-- LEFT JOIN option_values ov_end ON so.range_end_value_id = ov_end.id
-- WHERE (
--   -- Single value match
--   (so.is_range = FALSE AND ov_start.numeric_value = 5)
--   OR
--   -- Range match: SKU range contains the requested size
--   (so.is_range = TRUE AND 5 BETWEEN ov_start.numeric_value AND ov_end.numeric_value)
-- );

-- Query 2: Find all SKUs with OVERLAPPING ranges (e.g., search for sizes 4-6)
-- This finds SKUs where the size range has ANY overlap with requested range
-- Example: SKU with range 2-5 overlaps with search range 4-6 (overlap at 4,5)
-- SELECT DISTINCT s.*
-- FROM skus s
-- JOIN sku_options so ON s.id = so.sku_id
-- JOIN option_values ov_start ON so.option_value_id = ov_start.id
-- LEFT JOIN option_values ov_end ON so.range_end_value_id = ov_end.id
-- WHERE (
--   -- Single value: check if it falls within search range 4-6
--   (so.is_range = FALSE AND ov_start.numeric_value BETWEEN 4 AND 6)
--   OR
--   -- Range overlap: SKU range intersects with search range
--   -- Two ranges overlap if: max(start1,start2) <= min(end1,end2)
--   (so.is_range = TRUE
--    AND ov_start.numeric_value <= 6  -- SKU range starts before search range ends
--    AND ov_end.numeric_value >= 4)   -- SKU range ends after search range starts
-- );

-- Query 3: Find SKUs FULLY CONTAINED in range (e.g., SKU must fit entirely in 4-6)
-- Example: SKU with range 2-5 does NOT qualify because 2,3 are outside 4-6
-- Example: SKU with range 4-6 or 5-5 DOES qualify
-- SELECT DISTINCT s.*
-- FROM skus s
-- JOIN sku_options so ON s.id = so.sku_id
-- JOIN option_values ov_start ON so.option_value_id = ov_start.id
-- LEFT JOIN option_values ov_end ON so.range_end_value_id = ov_end.id
-- WHERE (
--   -- Single value must be within search range
--   (so.is_range = FALSE AND ov_start.numeric_value BETWEEN 4 AND 6)
--   OR
--   -- SKU range must be fully contained in search range
--   (so.is_range = TRUE
--    AND ov_start.numeric_value >= 4
--    AND ov_end.numeric_value <= 6)
-- );

-- Combined query with strictness parameter
-- @search_start: start of search range (e.g., 4)
-- @search_end: end of search range (e.g., 6)
-- @strict_match: TRUE for fully contained, FALSE for overlapping
--
-- SELECT DISTINCT s.*, p.name, p.article,
--   ov_start.numeric_value as range_start,
--   COALESCE(ov_end.numeric_value, ov_start.numeric_value) as range_end,
--   so.is_range
-- FROM skus s
-- JOIN products p ON s.product_id = p.id
-- JOIN sku_options so ON s.id = so.sku_id
-- JOIN option_values ov_start ON so.option_value_id = ov_start.id
-- LEFT JOIN option_values ov_end ON so.range_end_value_id = ov_end.id
-- WHERE
--   CASE
--     -- Strict match: SKU must be fully contained in search range
--     WHEN @strict_match = TRUE THEN
--       (so.is_range = FALSE AND ov_start.numeric_value BETWEEN @search_start AND @search_end)
--       OR
--       (so.is_range = TRUE
--        AND ov_start.numeric_value >= @search_start
--        AND ov_end.numeric_value <= @search_end)
--
--     -- Loose match: any overlap is acceptable
--     ELSE
--       (so.is_range = FALSE AND ov_start.numeric_value BETWEEN @search_start AND @search_end)
--       OR
--       (so.is_range = TRUE
--        AND ov_start.numeric_value <= @search_end
--        AND ov_end.numeric_value >= @search_start)
--   END
-- ORDER BY s.id;