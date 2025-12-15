package main

import (
	"database/sql"
	"encoding/json"
	"fmt"
	"log"
	"net/http"
	"os"
	"strconv"
	"strings"

	_ "github.com/go-sql-driver/mysql"
	"github.com/restream/reindexer/v5"
	_ "github.com/restream/reindexer/v5/bindings/cproto"
)

var db *sql.DB
var rx *reindexer.Reindexer

func getEnv(key, defaultValue string) string {
	value := os.Getenv(key)
	if value == "" {
		return defaultValue
	}
	return value
}

type Product struct {
	ID        int64  `json:"id"`
	Name      string `json:"name"`
	Article   string `json:"article"`
	CreatedAt string `json:"created_at"`
	UpdatedAt string `json:"updated_at"`
	SKUs      []SKU  `json:"skus,omitempty"`
}

type SKU struct {
	ID        int64       `json:"id"`
	ProductID int64       `json:"product_id"`
	Count     int         `json:"count"`
	Barcode   *string     `json:"barcode"`
	CreatedAt string      `json:"created_at"`
	UpdatedAt string      `json:"updated_at"`
	Options   []SKUOption `json:"options,omitempty"`
}

type SKUOption struct {
	OptionId          string  `json:"option_name_id"`
	OptionName        string  `json:"option_name"`
	OptionDisplayName string  `json:"option_display_name"`
	ValueId           string  `json:"value_id"`
	Value             string  `json:"value"`
	IsRange           bool    `json:"is_range"`
	RangeEndValue     *string `json:"range_end_value,omitempty"`
}

func initDB() error {
	// Get connection string from environment or use default
	connStr := getEnv("MYSQL_DSN", "root:rootpassword@tcp(localhost:3306)/mydb?parseTime=true")

	var err error
	db, err = sql.Open("mysql", connStr)
	if err != nil {
		return fmt.Errorf("error opening database: %w", err)
	}

	// Test the connection
	if err = db.Ping(); err != nil {
		return fmt.Errorf("error connecting to database: %w", err)
	}

	log.Println("Successfully connected to Percona database")
	return nil
}

func initReindexer() error {
	// Get Reindexer DSN from environment or use default
	reindexerDSN := getEnv("REINDEXER_DSN", "cproto://localhost:6534/products")

	rx = reindexer.NewReindex(reindexerDSN, reindexer.WithCreateDBIfMissing())

	// Open or create database for ReindexerProduct
	dbName := getEnv("REINDEXER_DB", "products_db")

	if err := rx.OpenNamespace(dbName, reindexer.DefaultNamespaceOptions(), ReindexerProduct{}); err != nil {
		log.Printf("Creating new namespace: %s", dbName)
	}

	log.Printf("Successfully connected to Reindexer (DSN: %s, DB: %s)", reindexerDSN, dbName)
	return nil
}

func handler(w http.ResponseWriter, r *http.Request) {
	// Query the database
	var version string
	err := db.QueryRow("SELECT VERSION()").Scan(&version)
	if err != nil {
		http.Error(w, "Database error", http.StatusInternalServerError)
		log.Printf("Database query error: %v", err)
		return
	}

	fmt.Fprintf(w, "one word\n\nDatabase connected: Percona %s", version)
}

type ProductsResponse struct {
	Products      []Product `json:"products"`
	NextProductID *int64    `json:"next_product_id"`
	Count         int       `json:"count"`
}

type ProductIDs struct {
	ProductID      int64   `json:"product_id"`
	OptionIDs      []int64 `json:"option_ids"`
	OptionValueIDs []int64 `json:"option_value_ids"`
}

type ProductIDsResponse struct {
	ProductIDs    []ProductIDs `json:"product_ids"`
	NextProductID *int64       `json:"next_product_id"`
	Count         int          `json:"count"`
}

// ReindexerProduct is the struct stored in Reindexer
type ReindexerProduct struct {
	ProductID      int64   `reindex:"product_id,hash,pk" json:"product_id"`
	OptionIDs      []int64 `reindex:"option_ids" json:"option_ids"`
	OptionValueIDs []int64 `reindex:"option_value_ids" json:"option_value_ids"`
}

// toReindexerProduct converts ProductIDs to ReindexerProduct
func (p *ProductIDs) toReindexerProduct() *ReindexerProduct {
	return &ReindexerProduct{
		ProductID:      p.ProductID,
		OptionIDs:      p.OptionIDs,
		OptionValueIDs: p.OptionValueIDs,
	}
}

func getProducts(fromID int64, count int) (*ProductsResponse, error) {
	// First query: Get products with their options grouped
	productsQuery := `
		SELECT 
			p.id, 
			p.name, 
			p.article, 
			p.created_at, 
			p.updated_at
		FROM products p
		WHERE p.id >= ?
		ORDER BY p.id
		LIMIT ?`

	rows, err := db.Query(productsQuery, fromID, count+1)
	if err != nil {
		return nil, fmt.Errorf("database query error: %w", err)
	}
	defer rows.Close()

	var products []Product
	var productIDs []int64

	for rows.Next() {
		var p Product
		err := rows.Scan(&p.ID, &p.Name, &p.Article, &p.CreatedAt, &p.UpdatedAt)
		if err != nil {
			return nil, fmt.Errorf("error scanning product: %w", err)
		}

		if len(products) < count {
			products = append(products, p)
			productIDs = append(productIDs, p.ID)
		}
	}

	if err = rows.Err(); err != nil {
		return nil, fmt.Errorf("error iterating products: %w", err)
	}

	// Determine next product ID
	var nextProductID *int64
	if len(products) > 0 {
		// Check if there are more products
		var nextID sql.NullInt64
		err = db.QueryRow("SELECT id FROM products WHERE id > ? ORDER BY id LIMIT 1", products[len(products)-1].ID).Scan(&nextID)
		if err == nil && nextID.Valid {
			nextProductID = &nextID.Int64
		}
	}

	// If no products found, return empty response
	if len(productIDs) == 0 {
		return &ProductsResponse{
			Products:      []Product{},
			NextProductID: nil,
			Count:         0,
		}, nil
	}

	// Second query: Get SKUs with their options for the retrieved products
	// Build placeholders for IN clause
	placeholders := make([]string, len(productIDs))
	args := make([]interface{}, len(productIDs))
	for i, id := range productIDs {
		placeholders[i] = "?"
		args[i] = id
	}

	skusQuery := fmt.Sprintf(`
		SELECT 
			s.id,
			s.product_id,
			s.count,
			s.barcode,
			s.created_at,
			s.updated_at,
			GROUP_CONCAT(DISTINCT o.id ORDER BY o.name SEPARATOR '|') as option_ids,
			GROUP_CONCAT(DISTINCT o.name ORDER BY o.name SEPARATOR '|') as option_names,
			GROUP_CONCAT(DISTINCT o.display_name ORDER BY o.name SEPARATOR '|') as option_display_names,
			GROUP_CONCAT(DISTINCT ov.id ORDER BY o.name SEPARATOR '|') as option_values_ids,
			GROUP_CONCAT(DISTINCT ov.value ORDER BY o.name SEPARATOR '|') as option_values,
			GROUP_CONCAT(DISTINCT so.is_range ORDER BY o.name SEPARATOR '|') as is_ranges,
			GROUP_CONCAT(DISTINCT ov_end.value ORDER BY o.name SEPARATOR '|') as range_end_values
		FROM skus s
		LEFT JOIN sku_options so ON s.id = so.sku_id
		LEFT JOIN option_values ov ON so.option_value_id = ov.id
		LEFT JOIN options o ON ov.option_id = o.id
		LEFT JOIN option_values ov_end ON so.range_end_value_id = ov_end.id
		WHERE s.product_id IN (%s)
		GROUP BY s.id
		ORDER BY s.product_id, s.id`, strings.Join(placeholders, ","))

	skuRows, err := db.Query(skusQuery, args...)
	if err != nil {
		return nil, fmt.Errorf("error querying SKUs: %w", err)
	}
	defer skuRows.Close()

	// Map to store SKUs by product_id
	productSKUs := make(map[int64][]SKU)

	for skuRows.Next() {
		var (
			sku                                                                       SKU
			barcode                                                                   sql.NullString
			optionIds, optionNames, optionDisplayNames, optionValuesIds, optionValues sql.NullString
			isRanges, rangeEndValues                                                  sql.NullString
		)

		err := skuRows.Scan(
			&sku.ID,
			&sku.ProductID,
			&sku.Count,
			&barcode,
			&sku.CreatedAt,
			&sku.UpdatedAt,
			&optionIds,
			&optionNames,
			&optionDisplayNames,
			&optionValuesIds,
			&optionValues,
			&isRanges,
			&rangeEndValues,
		)
		if err != nil {
			return nil, fmt.Errorf("error scanning SKU: %w", err)
		}

		if barcode.Valid {
			sku.Barcode = &barcode.String
		}

		// Parse grouped options
		if optionNames.Valid && optionValues.Valid {
			ids := strings.Split(optionIds.String, "|")
			names := strings.Split(optionNames.String, "|")
			displayNames := strings.Split(optionDisplayNames.String, "|")
			valuesIds := strings.Split(optionValuesIds.String, "|")
			values := strings.Split(optionValues.String, "|")
			ranges := strings.Split(isRanges.String, "|")
			endValues := []string{}
			if rangeEndValues.Valid {
				endValues = strings.Split(rangeEndValues.String, "|")
			}

			sku.Options = []SKUOption{}
			for i := 0; i < len(names) && i < len(values); i++ {
				option := SKUOption{
					OptionId:          ids[i],
					OptionName:        names[i],
					OptionDisplayName: displayNames[i],
					ValueId:           valuesIds[i],
					Value:             values[i],
					IsRange:           len(ranges) > i && ranges[i] == "1",
				}
				if len(endValues) > i && endValues[i] != "" {
					option.RangeEndValue = &endValues[i]
				}
				sku.Options = append(sku.Options, option)
			}
		}

		productSKUs[sku.ProductID] = append(productSKUs[sku.ProductID], sku)
	}

	if err = skuRows.Err(); err != nil {
		return nil, fmt.Errorf("error iterating SKUs: %w", err)
	}

	// Attach SKUs to products
	for i := range products {
		if skus, exists := productSKUs[products[i].ID]; exists {
			products[i].SKUs = skus
		} else {
			products[i].SKUs = []SKU{}
		}
	}

	response := &ProductsResponse{
		Products:      products,
		NextProductID: nextProductID,
		Count:         len(products),
	}

	return response, nil
}

func loadToReindexer() error {
	fromID := int64(0)
	batchSize := 1000
	totalLoaded := 0
	dbName := getEnv("REINDEXER_DB", "products_db")

	log.Printf("Starting data load to Reindexer...")

	for {
		// Get product IDs batch
		response, err := productsIds(fromID, batchSize)
		if err != nil {
			return fmt.Errorf("error getting product IDs: %w", err)
		}

		// If no products returned, we're done
		if len(response.ProductIDs) == 0 {
			break
		}

		// Upsert each product's IDs to Reindexer
		for _, productIDs := range response.ProductIDs {
			// Transform to ReindexerProduct
			reindexerProduct := productIDs.toReindexerProduct()

			err := rx.Upsert(dbName, reindexerProduct)
			if err != nil {
				log.Printf("Error upserting product %d to Reindexer: %v", productIDs.ProductID, err)
				continue
			}
		}

		totalLoaded += len(response.ProductIDs)
		log.Printf("Loaded %d products to Reindexer (total: %d)", len(response.ProductIDs), totalLoaded)

		// If there's no next page, we're done
		if response.NextProductID == nil {
			break
		}

		// Move to next batch
		fromID = *response.NextProductID
	}

	log.Printf("Completed loading %d products to Reindexer", totalLoaded)
	return nil
}

func loadToReindexerHandler(w http.ResponseWriter, r *http.Request) {
	// Run in background
	go func() {
		if err := loadToReindexer(); err != nil {
			log.Printf("Error loading to Reindexer: %v", err)
		}
	}()

	w.Header().Set("Content-Type", "application/json")
	json.NewEncoder(w).Encode(map[string]string{
		"status":  "started",
		"message": "Loading to Reindexer started in background. Check logs for progress.",
	})
}

func productsIds(fromID int64, count int) (*ProductIDsResponse, error) {
	// First query: Get product IDs
	productsQuery := `
		SELECT id
		FROM products
		WHERE id >= ?
		ORDER BY id
		LIMIT ?`

	rows, err := db.Query(productsQuery, fromID, count+1)
	if err != nil {
		return nil, fmt.Errorf("database query error: %w", err)
	}
	defer rows.Close()

	var productIDs []int64

	for rows.Next() {
		var pid int64
		err := rows.Scan(&pid)
		if err != nil {
			return nil, fmt.Errorf("error scanning product ID: %w", err)
		}

		if len(productIDs) < count {
			productIDs = append(productIDs, pid)
		}
	}

	if err = rows.Err(); err != nil {
		return nil, fmt.Errorf("error iterating products: %w", err)
	}

	// Determine next product ID
	var nextProductID *int64
	if len(productIDs) > 0 {
		var nextID sql.NullInt64
		err = db.QueryRow("SELECT id FROM products WHERE id > ? ORDER BY id LIMIT 1", productIDs[len(productIDs)-1]).Scan(&nextID)
		if err == nil && nextID.Valid {
			nextProductID = &nextID.Int64
		}
	}

	// If no products found, return empty response
	if len(productIDs) == 0 {
		return &ProductIDsResponse{
			ProductIDs:    []ProductIDs{},
			NextProductID: nil,
			Count:         0,
		}, nil
	}

	// Build placeholders for IN clause
	placeholders := make([]string, len(productIDs))
	args := make([]interface{}, len(productIDs))
	for i, id := range productIDs {
		placeholders[i] = "?"
		args[i] = id
	}

	// Second query: Get Option IDs and Option Value IDs for these products
	idsQuery := fmt.Sprintf(`
		SELECT 
			s.product_id,
			ov.option_id,
			ov.id as option_value_id
		FROM skus s
		LEFT JOIN sku_options so ON s.id = so.sku_id
		LEFT JOIN option_values ov ON so.option_value_id = ov.id
		WHERE s.product_id IN (%s)
		ORDER BY s.product_id`, strings.Join(placeholders, ","))

	idRows, err := db.Query(idsQuery, args...)
	if err != nil {
		return nil, fmt.Errorf("error querying IDs: %w", err)
	}
	defer idRows.Close()

	// Map to store IDs by product_id
	productsMap := make(map[int64]*ProductIDs)

	// Initialize map with product IDs
	for _, pid := range productIDs {
		productsMap[pid] = &ProductIDs{
			ProductID:      pid,
			OptionIDs:      []int64{},
			OptionValueIDs: []int64{},
		}
	}

	// Track unique IDs per product
	optionIDsMap := make(map[int64]map[int64]bool)
	optionValueIDsMap := make(map[int64]map[int64]bool)

	for idRows.Next() {
		var productID sql.NullInt64
		var optionID, optionValueID sql.NullInt64

		err := idRows.Scan(&productID, &optionID, &optionValueID)
		if err != nil {
			return nil, fmt.Errorf("error scanning IDs: %w", err)
		}

		if !productID.Valid {
			continue
		}

		pid := productID.Int64

		// Initialize maps if needed
		if optionIDsMap[pid] == nil {
			optionIDsMap[pid] = make(map[int64]bool)
			optionValueIDsMap[pid] = make(map[int64]bool)
		}

		// Add Option ID
		if optionID.Valid && !optionIDsMap[pid][optionID.Int64] {
			optionIDsMap[pid][optionID.Int64] = true
		}

		// Add Option Value ID
		if optionValueID.Valid && !optionValueIDsMap[pid][optionValueID.Int64] {
			optionValueIDsMap[pid][optionValueID.Int64] = true
		}
	}

	if err = idRows.Err(); err != nil {
		return nil, fmt.Errorf("error iterating IDs: %w", err)
	}

	// Convert maps to slices
	for pid, pids := range productsMap {
		for optionID := range optionIDsMap[pid] {
			pids.OptionIDs = append(pids.OptionIDs, optionID)
		}
		for optionValueID := range optionValueIDsMap[pid] {
			pids.OptionValueIDs = append(pids.OptionValueIDs, optionValueID)
		}
	}

	// Build response maintaining order
	var result []ProductIDs
	for _, pid := range productIDs {
		result = append(result, *productsMap[pid])
	}

	response := &ProductIDsResponse{
		ProductIDs:    result,
		NextProductID: nextProductID,
		Count:         len(result),
	}

	return response, nil
}

func productsIdsHandler(w http.ResponseWriter, r *http.Request) {
	// Parse query parameters
	fromIDStr := r.URL.Query().Get("from_id")
	countStr := r.URL.Query().Get("count")

	// Default values
	fromID := int64(0)
	count := 10

	// Parse from_id
	if fromIDStr != "" {
		parsed, err := strconv.ParseInt(fromIDStr, 10, 64)
		if err != nil {
			http.Error(w, "Invalid from_id parameter", http.StatusBadRequest)
			return
		}
		fromID = parsed
	}

	// Parse count
	if countStr != "" {
		parsed, err := strconv.Atoi(countStr)
		if err != nil || parsed <= 0 || parsed > 1000 {
			http.Error(w, "Invalid count parameter (must be 1-1000)", http.StatusBadRequest)
			return
		}
		count = parsed
	}

	// Get product IDs
	response, err := productsIds(fromID, count)
	if err != nil {
		http.Error(w, "Database error", http.StatusInternalServerError)
		log.Printf("Error getting product IDs: %v", err)
		return
	}

	// Set content type to JSON
	w.Header().Set("Content-Type", "application/json")

	// Encode and send response
	if err := json.NewEncoder(w).Encode(response); err != nil {
		http.Error(w, "Error encoding JSON", http.StatusInternalServerError)
		log.Printf("JSON encoding error: %v", err)
		return
	}
}

func productsHandler(w http.ResponseWriter, r *http.Request) {
	// Parse query parameters
	fromIDStr := r.URL.Query().Get("from_id")
	countStr := r.URL.Query().Get("count")

	// Default values
	fromID := int64(0)
	count := 10

	// Parse from_id
	if fromIDStr != "" {
		parsed, err := strconv.ParseInt(fromIDStr, 10, 64)
		if err != nil {
			http.Error(w, "Invalid from_id parameter", http.StatusBadRequest)
			return
		}
		fromID = parsed
	}

	// Parse count
	if countStr != "" {
		parsed, err := strconv.Atoi(countStr)
		if err != nil || parsed <= 0 || parsed > 1000 {
			http.Error(w, "Invalid count parameter (must be 1-1000)", http.StatusBadRequest)
			return
		}
		count = parsed
	}

	// Get products
	response, err := getProducts(fromID, count)
	if err != nil {
		http.Error(w, "Database error", http.StatusInternalServerError)
		log.Printf("Error getting products: %v", err)
		return
	}

	// Set content type to JSON
	w.Header().Set("Content-Type", "application/json")

	// Encode and send response
	if err := json.NewEncoder(w).Encode(response); err != nil {
		http.Error(w, "Error encoding JSON", http.StatusInternalServerError)
		log.Printf("JSON encoding error: %v", err)
		return
	}
}

func healthHandler(w http.ResponseWriter, r *http.Request) {
	if err := db.Ping(); err != nil {
		http.Error(w, "Database unavailable", http.StatusServiceUnavailable)
		return
	}
	fmt.Fprint(w, "OK")
}

func main() {
	// Check for CLI commands
	if len(os.Args) > 1 {
		switch os.Args[1] {
		case "load":
			// Initialize connections
			if err := initDB(); err != nil {
				log.Fatal(err)
			}
			defer db.Close()

			if err := initReindexer(); err != nil {
				log.Fatal(err)
			}
			defer rx.Close()

			// Run load function
			if err := loadToReindexer(); err != nil {
				log.Fatal(err)
			}
			return
		case "help":
			fmt.Println("Available commands:")
			fmt.Println("  load    - Load products from MySQL to Reindexer")
			fmt.Println("  help    - Show this help message")
			fmt.Println("\nRun without arguments to start HTTP server")
			return
		default:
			fmt.Printf("Unknown command: %s\n", os.Args[1])
			fmt.Println("Run 'help' for available commands")
			return
		}
	}

	// Initialize database connection
	if err := initDB(); err != nil {
		log.Fatal(err)
	}
	defer db.Close()

	// Initialize Reindexer
	if err := initReindexer(); err != nil {
		log.Fatal(err)
	}
	defer rx.Close()

	http.HandleFunc("/", handler)
	http.HandleFunc("/products", productsHandler)
	http.HandleFunc("/products/ids", productsIdsHandler)
	http.HandleFunc("/load", loadToReindexerHandler)
	http.HandleFunc("/health", healthHandler)

	port := ":8085"
	log.Printf("Starting server on port %s", port)

	if err := http.ListenAndServe(port, nil); err != nil {
		log.Fatal(err)
	}
}
