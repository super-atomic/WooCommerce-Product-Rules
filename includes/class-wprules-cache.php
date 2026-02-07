<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WPRules_Cache {

    private $cache_group = 'wprules';
    private $cache_expiry = 3600; // 1 hour

    public function __construct() {
        add_action( 'wprules_rule_updated', [ $this, 'clear_rules_cache' ] );
        add_action( 'wprules_rule_deleted', [ $this, 'clear_rules_cache' ] );
        add_action( 'wprules_rule_created', [ $this, 'clear_rules_cache' ] );
    }

    /**
     * Get cached rules for a specific product
     */
    public function get_rules_for_product( $product_id ) {
        $cache_key = 'rules_for_product_' . $product_id;
        $cached = wp_cache_get( $cache_key, $this->cache_group );
        
        if ( $cached !== false ) {
            return $cached;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'wc_product_rules';
        
        $rules = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $table WHERE JSON_CONTAINS(product_ids, %s)",
            '[' . intval( $product_id ) . ']'
        ) );

        wp_cache_set( $cache_key, $rules, $this->cache_group, $this->cache_expiry );
        return $rules;
    }

    /**
     * Get all rules with pagination and sorting
     */
    public function get_rules_paginated( $page = 1, $per_page = 20, $orderby = 'id', $order = 'DESC' ) {
        // Validate orderby and order
        $allowed_orderby = [ 'id', 'rule_type', 'user_roles' ];
        $orderby = in_array( $orderby, $allowed_orderby, true ) ? $orderby : 'id';
        $order = strtoupper( $order ) === 'ASC' ? 'ASC' : 'DESC';
        
        $cache_key = 'rules_paginated_' . $page . '_' . $per_page . '_' . $orderby . '_' . $order;
        $cached = wp_cache_get( $cache_key, $this->cache_group );
        
        if ( $cached !== false ) {
            return $cached;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'wc_product_rules';
        
        $offset = ( $page - 1 ) * $per_page;
        
        // Build ORDER BY clause
        $orderby_clause = "ORDER BY $orderby $order";
        if ( $orderby === 'user_roles' ) {
            // For user_roles, we want NULL values last, then sort alphabetically
            $orderby_clause = "ORDER BY CASE WHEN user_roles IS NULL OR user_roles = '' THEN 1 ELSE 0 END, user_roles $order";
        }
        
        $rules = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $table $orderby_clause LIMIT %d OFFSET %d",
            $per_page,
            $offset
        ) );

        $total = $wpdb->get_var( "SELECT COUNT(*) FROM $table" );

        $result = [
            'rules' => $rules,
            'total' => intval( $total ),
            'pages' => ceil( $total / $per_page ),
            'current_page' => $page
        ];

        wp_cache_set( $cache_key, $result, $this->cache_group, $this->cache_expiry );
        return $result;
    }

    /**
     * Get all rules (for validation - cached)
     */
    public function get_all_rules() {
        $cache_key = 'all_rules';
        $cached = wp_cache_get( $cache_key, $this->cache_group );
        
        if ( $cached !== false ) {
            return $cached;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'wc_product_rules';
        $rules = $wpdb->get_results( "SELECT * FROM $table" );

        wp_cache_set( $cache_key, $rules, $this->cache_group, $this->cache_expiry );
        return $rules;
    }

    /**
     * Clear all rules cache
     */
    public function clear_rules_cache() {
        wp_cache_flush_group( $this->cache_group );
    }

    /**
     * Clear cache for specific product
     */
    public function clear_product_cache( $product_id ) {
        wp_cache_delete( 'rules_for_product_' . $product_id, $this->cache_group );
    }

    /**
     * Get cache statistics
     */
    public function get_cache_stats() {
        global $wpdb;
        $table = $wpdb->prefix . 'wc_product_rules';
        
        return [
            'cache_group' => $this->cache_group,
            'cache_expiry' => $this->cache_expiry,
            'memory_usage' => memory_get_usage( true ),
            'peak_memory' => memory_get_peak_usage( true ),
            'total_rules' => $wpdb->get_var( "SELECT COUNT(*) FROM $table" ),
            'cache_keys' => $this->get_cache_keys_count(),
            'wp_memory_limit' => ini_get( 'memory_limit' ),
            'wp_max_execution_time' => ini_get( 'max_execution_time' )
        ];
    }

    /**
     * Get approximate count of cached keys
     */
    private function get_cache_keys_count() {
        // This is an approximation - WordPress doesn't provide a direct way to count cache keys
        // We'll estimate based on common cache patterns
        $estimated_keys = 0;
        
        // Check for common cache keys
        $common_keys = [
            'all_rules',
            'rules_paginated_1_20',
            'rules_paginated_2_20',
            'rules_for_product_1',
            'rules_for_product_2'
        ];
        
        foreach ( $common_keys as $key ) {
            if ( wp_cache_get( $key, $this->cache_group ) !== false ) {
                $estimated_keys++;
            }
        }
        
        return $estimated_keys > 0 ? $estimated_keys . '+' : '0';
    }
}
