<?php
/**
 * Plugin Name: WooCommerce Product Rules
 * Description: Adds product dependency/restriction rules for WooCommerce (multi-product, ALL/ANY support).
 * Version: 1.2.1
 * Author: RetroKevin
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WC_Product_Rules {

    public function __construct() {
        register_activation_hook( __FILE__, [ $this, 'install' ] );
        add_action( 'plugins_loaded', [ $this, 'init' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend_styles' ] );
    }

    public function install() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wc_product_rules';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            product_ids LONGTEXT NOT NULL,
            rule_type VARCHAR(40) NOT NULL,
            target_ids LONGTEXT DEFAULT NULL,
            scope VARCHAR(20) NOT NULL DEFAULT 'both',
            limit_qty INT DEFAULT NULL,
            match_type VARCHAR(10) NOT NULL DEFAULT 'any',
            user_roles LONGTEXT DEFAULT NULL,
            PRIMARY KEY (id)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
        
        // Add user_roles column if it doesn't exist (for existing installations)
        $column_exists = $wpdb->get_results( "SHOW COLUMNS FROM $table_name LIKE 'user_roles'" );
        if ( empty( $column_exists ) ) {
            $wpdb->query( "ALTER TABLE $table_name ADD COLUMN user_roles LONGTEXT DEFAULT NULL AFTER match_type" );
        }
    }

    public function init() {
        // Check if WooCommerce is active
        if ( ! class_exists( 'WooCommerce' ) ) {
            add_action( 'admin_notices', [ $this, 'woocommerce_missing_notice' ] );
            return;
        }

        // Cache Manager
        require_once plugin_dir_path( __FILE__ ) . 'includes/class-wprules-cache.php';
        new WPRules_Cache();

        // Admin
        if ( is_admin() ) {
            require_once plugin_dir_path( __FILE__ ) . 'includes/class-wprules-admin.php';
            new WPRules_Admin();
        }

        // Validator
        require_once plugin_dir_path( __FILE__ ) . 'includes/class-wprules-validator.php';
        new WPRules_Validator();
    }

    public function woocommerce_missing_notice() {
        echo '<div class="error"><p><strong>WooCommerce Product Rules</strong> requires WooCommerce to be installed and active.</p></div>';
    }

    /**
     * Enqueue frontend styles for custom error message styling
     */
    public function enqueue_frontend_styles() {
        // Only enqueue on WooCommerce pages
        if ( ! class_exists( 'WooCommerce' ) ) {
            return;
        }

        $css_file = plugin_dir_path( __FILE__ ) . 'assets/css/wprules-frontend.css';
        if ( file_exists( $css_file ) ) {
            wp_enqueue_style(
                'wprules-frontend',
                plugin_dir_url( __FILE__ ) . 'assets/css/wprules-frontend.css',
                [],
                filemtime( $css_file )
            );
        }
    }
}

new WC_Product_Rules();