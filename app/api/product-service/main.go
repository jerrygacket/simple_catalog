package main

import (
	"encoding/json"
	"fmt"
	"log"
	"net/http"
	"os"
	"strconv"
	"strings"

	"database/sql"

	_ "github.com/go-sql-driver/mysql"
	"github.com/restream/reindexer/v5"
	_ "github.com/restream/reindexer/v5/bindings/cproto"
)

var rx *reindexer.Reindexer
var db *sql.DB

// ReindexerProduct matches the structure stored in Reindexer
type ReindexerProduct struct {
	ProductID      int64   `reindex:"product_id,hash,pk" json:"product_id"`
	OptionIDs      []int64 `reindex:"option_ids" json:"option_ids"`
	OptionValueIDs []int64 `reindex:"option_value_ids" json:"option_value_ids"`
}

// ProductSearchResponse is the response for product search
type ProductSearchResponse struct {
	Products []ReindexerProduct `json:"products"`
	Meta     MetaInfo           `json:"meta"`
	Facets   map[int64]int      `json:"facets"`
}

// MetaInfo contains pagination and total count information
type MetaInfo struct {
	TotalCount  int  `json:"total_count"`
	TotalPages  int  `json:"total_pages"`
	CurrentPage int  `json:"current_page"`
	NextPage    *int `json:"next_page"`
	Count       int  `json:"count"`
}

// OptionFilter represents filters for a specific option
type OptionFilter struct {
	OptionID       int64
	OptionValueIDs []int64
}

// Option represents an option with its values
type Option struct {
	ID          int64         `json:"id"`
	Name        string        `json:"name"`
	DisplayName string        `json:"display_name"`
	Values      []OptionValue `json:"values"`
}

// OptionValue represents a value for an option
type OptionValue struct {
	ID    int64  `json:"id"`
	Value string `json:"value"`
}

func getEnv(key, defaultValue string) string {
	value := os.Getenv(key)
	if value == "" {
		return defaultValue
	}
	return value
}

func initReindexer() error {
	reindexerDSN := getEnv("REINDEXER_DSN", "cproto://localhost:6534/products")
	rx = reindexer.NewReindex(reindexerDSN, reindexer.WithCreateDBIfMissing())

	dbName := getEnv("REINDEXER_DB", "products_db")

	if err := rx.OpenNamespace(dbName, reindexer.DefaultNamespaceOptions(), ReindexerProduct{}); err != nil {
		return fmt.Errorf("error opening namespace: %w", err)
	}

	log.Printf("Successfully connected to Reindexer (DSN: %s, DB: %s)", reindexerDSN, dbName)
	return nil
}

func initDB() error {
	connStr := getEnv("MYSQL_DSN", "root:rootpassword@tcp(localhost:3306)/mydb?parseTime=true")

	var err error
	db, err = sql.Open("mysql", connStr)
	if err != nil {
		return fmt.Errorf("error opening database: %w", err)
	}

	if err = db.Ping(); err != nil {
		return fmt.Errorf("error connecting to database: %w", err)
	}

	log.Println("Successfully connected to MySQL database")
	return nil
}

func searchProducts(filters []OptionFilter, page, count int) (*ProductSearchResponse, error) {
	dbName := getEnv("REINDEXER_DB", "products_db")

	// Calculate offset
	offset := page * count

	// Build query for total count
	totalQuery := rx.Query(dbName)

	// Apply filters - product must have at least one value from each option
	if len(filters) > 0 {
		for _, filter := range filters {
			// For each option, product must have at least one of the specified values
			if len(filter.OptionValueIDs) > 0 {
				// Create OR condition for option values within this option
				for i, valueID := range filter.OptionValueIDs {
					if i == 0 {
						totalQuery = totalQuery.Where("option_value_ids", reindexer.EQ, valueID)
					} else {
						totalQuery = totalQuery.Or().Where("option_value_ids", reindexer.EQ, valueID)
					}
				}
			}
		}
	}

	// Get total count
	totalQuery = totalQuery.ReqTotal()
	totalIterator := totalQuery.Exec()
	defer totalIterator.Close()

	totalCount := totalIterator.TotalCount()

	// Build new query for paginated results
	resultsQuery := rx.Query(dbName)

	// Apply same filters
	if len(filters) > 0 {
		for _, filter := range filters {
			if len(filter.OptionValueIDs) > 0 {
				for i, valueID := range filter.OptionValueIDs {
					if i == 0 {
						resultsQuery = resultsQuery.Where("option_value_ids", reindexer.EQ, valueID)
					} else {
						resultsQuery = resultsQuery.Or().Where("option_value_ids", reindexer.EQ, valueID)
					}
				}
			}
		}
	}

	// Apply pagination
	resultsQuery = resultsQuery.Limit(count).Offset(offset)

	// Execute query
	resultsIterator := resultsQuery.Exec()
	defer resultsIterator.Close()

	var products []ReindexerProduct
	for resultsIterator.Next() {
		product := resultsIterator.Object().(*ReindexerProduct)
		products = append(products, *product)
	}

	if err := resultsIterator.Error(); err != nil {
		return nil, fmt.Errorf("error executing query: %w", err)
	}

	// Calculate facets - get all option_value_ids counts with current filters
	facets := make(map[int64]int)
	facetQuery := rx.Query(dbName)

	// Apply same filters for facet calculation
	if len(filters) > 0 {
		for _, filter := range filters {
			if len(filter.OptionValueIDs) > 0 {
				for i, valueID := range filter.OptionValueIDs {
					if i == 0 {
						facetQuery = facetQuery.Where("option_value_ids", reindexer.EQ, valueID)
					} else {
						facetQuery = facetQuery.Or().Where("option_value_ids", reindexer.EQ, valueID)
					}
				}
			}
		}
	}

	// Execute facet query
	facetIterator := facetQuery.Exec()
	defer facetIterator.Close()

	for facetIterator.Next() {
		product := facetIterator.Object().(*ReindexerProduct)
		for _, ovID := range product.OptionValueIDs {
			facets[ovID]++
		}
	}

	// Calculate pagination meta
	totalPages := (totalCount + count - 1) / count
	var nextPage *int
	if page < totalPages-1 {
		np := page + 1
		nextPage = &np
	}

	meta := MetaInfo{
		TotalCount:  totalCount,
		TotalPages:  totalPages,
		CurrentPage: page,
		NextPage:    nextPage,
		Count:       len(products),
	}

	response := &ProductSearchResponse{
		Products: products,
		Meta:     meta,
		Facets:   facets,
	}

	return response, nil
}

func parseFilters(r *http.Request) ([]OptionFilter, error) {
	var filters []OptionFilter

	// Parse filters from query string
	// Expected format: filters[optionID]=valueID1,valueID2
	query := r.URL.Query()

	for key := range query {
		if strings.HasPrefix(key, "filters[") && strings.HasSuffix(key, "]") {
			// Extract option ID
			optionIDStr := strings.TrimPrefix(key, "filters[")
			optionIDStr = strings.TrimSuffix(optionIDStr, "]")

			optionID, err := strconv.ParseInt(optionIDStr, 10, 64)
			if err != nil {
				return nil, fmt.Errorf("invalid option ID: %s", optionIDStr)
			}

			// Parse value IDs
			valuesStr := query.Get(key)
			if valuesStr == "" {
				continue
			}

			valueStrs := strings.Split(valuesStr, ",")
			var valueIDs []int64

			for _, vStr := range valueStrs {
				vStr = strings.TrimSpace(vStr)
				if vStr == "" {
					continue
				}

				valueID, err := strconv.ParseInt(vStr, 10, 64)
				if err != nil {
					return nil, fmt.Errorf("invalid value ID: %s", vStr)
				}

				valueIDs = append(valueIDs, valueID)
			}

			if len(valueIDs) > 0 {
				filters = append(filters, OptionFilter{
					OptionID:       optionID,
					OptionValueIDs: valueIDs,
				})
			}
		}
	}

	return filters, nil
}

func productsHandler(w http.ResponseWriter, r *http.Request) {
	// Parse query parameters
	pageStr := r.URL.Query().Get("page")
	countStr := r.URL.Query().Get("count")

	// Default values
	page := 0
	count := 10

	// Parse filters
	filters, err := parseFilters(r)
	if err != nil {
		http.Error(w, fmt.Sprintf("Invalid filters: %v", err), http.StatusBadRequest)
		return
	}

	// Parse page
	if pageStr != "" {
		parsed, err := strconv.Atoi(pageStr)
		if err != nil || parsed < 0 {
			http.Error(w, "Invalid page parameter", http.StatusBadRequest)
			return
		}
		page = parsed
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

	// Search products
	response, err := searchProducts(filters, page, count)
	if err != nil {
		http.Error(w, "Search error", http.StatusInternalServerError)
		log.Printf("Error searching products: %v", err)
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
	fmt.Fprint(w, "OK")
}

func optionsHandler(w http.ResponseWriter, r *http.Request) {
	// Query to get all options with their values
	query := `
		SELECT 
			o.id as option_id,
			o.name as option_name,
			o.display_name as option_display_name,
			ov.id as value_id,
			ov.value as value_name
		FROM options o
		LEFT JOIN option_values ov ON o.id = ov.option_id
		ORDER BY o.id, ov.id`

	rows, err := db.Query(query)
	if err != nil {
		http.Error(w, "Database error", http.StatusInternalServerError)
		log.Printf("Error querying options: %v", err)
		return
	}
	defer rows.Close()

	optionsMap := make(map[int64]*Option)
	var optionOrder []int64

	for rows.Next() {
		var optionID, valueID sql.NullInt64
		var optionName, optionDisplayName, valueName sql.NullString

		err := rows.Scan(&optionID, &optionName, &optionDisplayName, &valueID, &valueName)
		if err != nil {
			http.Error(w, "Error scanning results", http.StatusInternalServerError)
			log.Printf("Scan error: %v", err)
			return
		}

		if !optionID.Valid {
			continue
		}

		oid := optionID.Int64

		// Create option if not exists
		if _, exists := optionsMap[oid]; !exists {
			optionsMap[oid] = &Option{
				ID:          oid,
				Name:        optionName.String,
				DisplayName: optionDisplayName.String,
				Values:      []OptionValue{},
			}
			optionOrder = append(optionOrder, oid)
		}

		// Add value if exists
		if valueID.Valid && valueName.Valid {
			optionsMap[oid].Values = append(optionsMap[oid].Values, OptionValue{
				ID:    valueID.Int64,
				Value: valueName.String,
			})
		}
	}

	if err = rows.Err(); err != nil {
		http.Error(w, "Error iterating results", http.StatusInternalServerError)
		log.Printf("Rows error: %v", err)
		return
	}

	// Convert to slice maintaining order
	var options []Option
	for _, oid := range optionOrder {
		options = append(options, *optionsMap[oid])
	}

	// Set content type to JSON
	w.Header().Set("Content-Type", "application/json")

	// Encode and send response
	if err := json.NewEncoder(w).Encode(options); err != nil {
		http.Error(w, "Error encoding JSON", http.StatusInternalServerError)
		log.Printf("JSON encoding error: %v", err)
		return
	}
}

func corsMiddleware(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Access-Control-Allow-Origin", "*")
		w.Header().Set("Access-Control-Allow-Methods", "GET, OPTIONS")
		w.Header().Set("Access-Control-Allow-Headers", "Content-Type")

		if r.Method == "OPTIONS" {
			w.WriteHeader(http.StatusOK)
			return
		}

		next.ServeHTTP(w, r)
	})
}

func main() {
	// Initialize MySQL
	if err := initDB(); err != nil {
		log.Fatal(err)
	}
	defer db.Close()

	// Initialize Reindexer
	if err := initReindexer(); err != nil {
		log.Fatal(err)
	}
	defer rx.Close()

	http.Handle("/options", corsMiddleware(http.HandlerFunc(optionsHandler)))
	http.Handle("/products", corsMiddleware(http.HandlerFunc(productsHandler)))
	http.Handle("/health", corsMiddleware(http.HandlerFunc(healthHandler)))

	port := getEnv("PORT", ":8087")
	log.Printf("Starting product-service on port %s", port)

	if err := http.ListenAndServe(port, nil); err != nil {
		log.Fatal(err)
	}
}
