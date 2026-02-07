<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WPRules_Admin {

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'scripts' ] );
        add_action( 'wp_ajax_wprules_search_products', [ $this, 'ajax_search_products' ] );
        add_action( 'wp_ajax_wprules_save_rule_inline', [ $this, 'ajax_save_rule_inline' ] );
        
        // Handle export/import
        add_action( 'admin_init', [ $this, 'handle_export' ] );
        add_action( 'admin_init', [ $this, 'handle_import' ] );
    }

    public function menu() {
        add_submenu_page(
            'woocommerce',
            'Product Rules',
            'Product Rules',
            'manage_woocommerce',
            'wc-product-rules',
            [ $this, 'page' ]
        );
    }

    public function scripts( $hook ) {
        if ( $hook !== 'woocommerce_page_wc-product-rules' ) {
            return;
        }

        // enqueue select2 (WooCommerce already provides it) + your admin JS
        wp_enqueue_script( 'select2' );
        wp_enqueue_style( 'select2' );

        wp_enqueue_script(
            'wprules-admin',
            plugin_dir_url( __FILE__ ) . '../assets/js/wprules-admin.js',
            [ 'jquery', 'select2' ],
            filemtime( plugin_dir_path( __FILE__ ) . '../assets/js/wprules-admin.js' ),
            true
        );

        wp_localize_script( 'wprules-admin', 'wprulesAjax', [
            'ajaxurl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'wprules_product_search' )
        ]);

        // Add admin styles for better table display - use admin_head for more reliable loading
        add_action( 'admin_head', function() {
            ?>
            <style type="text/css">
            .wprules-product-list {
                margin: 0 !important;
                margin-top: 0 !important;
                padding: 0 !important;
                list-style: none !important;
            }
            .wprules-product-list li {
                margin: 4px 0 !important;
                padding: 4px 0 !important;
                line-height: 1.4 !important;
                border-bottom: 1px solid #f0f0f1 !important;
            }
            .wprules-product-list li:last-child {
                border-bottom: none !important;
            }
            .wprules-product-id {
                color: #646970 !important;
                font-size: 11px !important;
                font-weight: normal !important;
            }
            .widefat td {
                vertical-align: top !important;
                padding: 12px !important;
            }
            .widefat .wprules-product-list {
                max-width: 400px !important;
            }
            .widefat ul.wprules-product-list {
                margin-top: 0 !important;
                margin-bottom: 0 !important;
            }
            .widefat ul.wprules-product-list.wprules-view-mode {
                margin-top: 0 !important;
            }
            .check-column {
                width: 2.2em !important;
                padding: 11px 0 0 3px !important;
            }
            .check-column input[type="checkbox"] {
                margin: 0 !important;
            }
            .bulkactions {
                margin-bottom: 10px;
            }
            .bulkactions select {
                margin-right: 5px;
            }
            .tablenav .alignleft.actions {
                margin-right: 10px;
            }
            </style>
            <?php
        }, 999 );

        // print inline small script to toggle fields (run after your JS loads)
        add_action( 'admin_print_footer_scripts', function() {
            ?>
            <script>
            jQuery(function($){
                function toggleFields() {
                    var type = $('select[name="rule_type"]').val();
                    if ( type === 'dependencies' || type === 'restrictions' ) {
                        $('.field-related-product').show();
                        $('.field-max-qty').hide();
                    } else if ( type === 'limit' ) {
                        $('.field-related-product').hide();
                        $('.field-max-qty').show();
                    } else {
                        $('.field-related-product, .field-max-qty').hide();
                    }
                }
                toggleFields();
                $(document).on('change', 'select[name="rule_type"]', toggleFields);
                
                // Initialize select2 for user roles field
                if ( $('select[name="user_roles[]"]').length ) {
                    $('select[name="user_roles[]"]').select2({
                        placeholder: 'Select roles (optional)',
                        allowClear: true
                    });
                }
                
                // Bulk actions - select all functionality
                $('#cb-select-all').on('change', function() {
                    $('.rule-checkbox').prop('checked', $(this).prop('checked'));
                });
                
                // Update select all checkbox when individual checkboxes change
                $('.rule-checkbox').on('change', function() {
                    var total = $('.rule-checkbox').length;
                    var checked = $('.rule-checkbox:checked').length;
                    $('#cb-select-all').prop('checked', total === checked);
                });
                
                // Bulk action form submission
                $('#bulk-action-form').on('submit', function(e) {
                    var action = $('#bulk-action-selector').val();
                    if ( ! action ) {
                        e.preventDefault();
                        alert('Please select a bulk action.');
                        return false;
                    }
                    
                    var checked = $('.rule-checkbox:checked').length;
                    if ( checked === 0 ) {
                        e.preventDefault();
                        alert('Please select at least one rule.');
                        return false;
                    }
                    
                    if ( action === 'delete' ) {
                        if ( ! confirm('Are you sure you want to delete ' + checked + ' selected rule(s)? This action cannot be undone.') ) {
                            e.preventDefault();
                            return false;
                        }
                    }
                });
            });
            </script>
            <?php
        });
    }

    /**
     * AJAX product search (returns { results: [ {id, text}, ... ] })
     */
    public function ajax_search_products() {
    check_ajax_referer( 'wprules_product_search', 'security' );

    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error();
    }

    $term = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';

    if ( empty( $term ) ) {
        wp_send_json( [ 'results' => [] ] );
    }

    $found_ids = [];
    
    // First, try searching by product ID if term is numeric
    if ( is_numeric( $term ) ) {
        $product = wc_get_product( intval( $term ) );
        if ( $product && $product->get_status() === 'publish' ) {
            $found_ids[] = $product->get_id();
        }
    }
    
    // Search by SKU
    global $wpdb;
    $sku_results = $wpdb->get_col( $wpdb->prepare( "
        SELECT post_id 
        FROM {$wpdb->postmeta} 
        WHERE meta_key = '_sku' 
        AND meta_value LIKE %s
        AND post_id IN (
            SELECT ID FROM {$wpdb->posts} 
            WHERE post_type = 'product' 
            AND post_status = 'publish'
        )
        LIMIT 20
    ", '%' . $wpdb->esc_like( $term ) . '%' ) );
    
    if ( ! empty( $sku_results ) ) {
        $found_ids = array_merge( $found_ids, array_map( 'intval', $sku_results ) );
    }
    
    // Search by title directly (more reliable than WooCommerce's 's' parameter)
    $title_results = $wpdb->get_col( $wpdb->prepare( "
        SELECT ID 
        FROM {$wpdb->posts} 
        WHERE post_type = 'product' 
        AND post_status = 'publish'
        AND (post_title LIKE %s OR post_content LIKE %s)
        LIMIT 20
    ", '%' . $wpdb->esc_like( $term ) . '%', '%' . $wpdb->esc_like( $term ) . '%' ) );
    
    if ( ! empty( $title_results ) ) {
        $found_ids = array_merge( $found_ids, array_map( 'intval', $title_results ) );
    }
    
    // Also use WooCommerce's search as a fallback
    $args = [
        'status' => 'publish',
        'limit'  => 20,
        'return' => 'ids',
        's'      => $term,
    ];
    
    $products = wc_get_products( $args );
    if ( ! empty( $products ) ) {
        $found_ids = array_merge( $found_ids, $products );
    }
    
    // Remove duplicates and limit results
    $found_ids = array_unique( $found_ids );
    $found_ids = array_slice( $found_ids, 0, 20 );
    
    $results = [];
    foreach ( $found_ids as $id ) {
        $product = wc_get_product( $id );
        if ( $product && $product->get_status() === 'publish' ) {
            $title = get_the_title( $id );
            $sku = $product->get_sku();
            $display_text = $title . ' (#' . $id . ')';
            if ( ! empty( $sku ) ) {
                $display_text .= ' [SKU: ' . $sku . ']';
            }
            $results[] = [
                'id'   => $id,
                'text' => $display_text
            ];
        }
    }

    wp_send_json( [ 'results' => $results ] );
}

    /**
     * AJAX handler for inline rule saving
     */
    public function ajax_save_rule_inline() {
        check_ajax_referer( 'wprules_product_search', 'security' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions' ] );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'wc_product_rules';

        $rule_id = isset( $_POST['rule_id'] ) ? intval( $_POST['rule_id'] ) : 0;
        if ( ! $rule_id ) {
            wp_send_json_error( [ 'message' => 'Invalid rule ID' ] );
        }

        $product_ids = isset( $_POST['product_ids'] ) ? array_map( 'absint', (array) $_POST['product_ids'] ) : [];
        $target_ids  = isset( $_POST['target_ids'] ) ? array_map( 'absint', (array) $_POST['target_ids'] ) : [];
        $rule_type   = isset( $_POST['rule_type'] ) ? sanitize_text_field( $_POST['rule_type'] ) : 'dependencies';
        $limit_qty   = isset( $_POST['limit_qty'] ) && $_POST['limit_qty'] !== '' ? intval( $_POST['limit_qty'] ) : null;
        
        // Handle user roles
        $allowed_roles = [ 'CT-Teen', 'CT-Adult', 'MA-Teen', 'MA-Adult' ];
        $user_roles = [];
        if ( isset( $_POST['user_roles'] ) && is_array( $_POST['user_roles'] ) ) {
            foreach ( $_POST['user_roles'] as $role ) {
                $role = sanitize_text_field( $role );
                if ( in_array( $role, $allowed_roles, true ) ) {
                    $user_roles[] = $role;
                }
            }
        }

        $data = [
            'product_ids' => wp_json_encode( $product_ids ),
            'rule_type'   => $rule_type,
            'target_ids'  => ! empty( $target_ids ) ? wp_json_encode( $target_ids ) : null,
            'limit_qty'   => $limit_qty,
            'match_type'  => isset( $_POST['match_type'] ) && in_array( $_POST['match_type'], [ 'any', 'all' ], true ) ? $_POST['match_type'] : 'any',
            'user_roles'  => ! empty( $user_roles ) ? wp_json_encode( $user_roles ) : null,
        ];

        $result = $wpdb->update( $table, $data, [ 'id' => $rule_id ] );
        
        if ( $result !== false ) {
            do_action( 'wprules_rule_updated', $rule_id );
            wp_send_json_success( [ 'message' => 'Rule updated successfully' ] );
        } else {
            wp_send_json_error( [ 'message' => 'Error updating rule' ] );
        }
    }

    public function page() {
        global $wpdb;
        $table = $wpdb->prefix . 'wc_product_rules';

        // Save / update rule
        if ( isset( $_POST['save_rule'] ) ) {
            check_admin_referer( 'save_rule_nonce' );

            $rule_id = ! empty( $_POST['rule_id'] ) ? intval( $_POST['rule_id'] ) : 0;
            $product_ids = isset( $_POST['product_ids'] ) ? array_map( 'absint', (array) $_POST['product_ids'] ) : [];
            $target_ids  = isset( $_POST['target_ids'] ) ? array_map( 'absint', (array) $_POST['target_ids'] ) : [];
            $rule_type   = isset( $_POST['rule_type'] ) ? sanitize_text_field( $_POST['rule_type'] ) : 'dependencies';
            $limit_qty   = isset( $_POST['limit_qty'] ) && $_POST['limit_qty'] !== '' ? intval( $_POST['limit_qty'] ) : null;
            
            // Handle user roles - allowed roles: CT-Teen, CT-Adult, MA-Teen, MA-Adult
            $allowed_roles = [ 'CT-Teen', 'CT-Adult', 'MA-Teen', 'MA-Adult' ];
            $user_roles = [];
            if ( isset( $_POST['user_roles'] ) && is_array( $_POST['user_roles'] ) ) {
                foreach ( $_POST['user_roles'] as $role ) {
                    $role = sanitize_text_field( $role );
                    if ( in_array( $role, $allowed_roles, true ) ) {
                        $user_roles[] = $role;
                    }
                }
            }

            $data = [
                'product_ids' => wp_json_encode( $product_ids ),
                'rule_type'   => $rule_type,
                'target_ids'  => ! empty( $target_ids ) ? wp_json_encode( $target_ids ) : null,
                'limit_qty'   => $limit_qty,
                'match_type' => isset($_POST['match_type']) && in_array($_POST['match_type'], ['any','all']) ? $_POST['match_type'] : 'any',
                'user_roles'  => ! empty( $user_roles ) ? wp_json_encode( $user_roles ) : null,
            ];

            if ( $rule_id ) {
                $result = $wpdb->update( $table, $data, [ 'id' => $rule_id ] );
                if ( $result !== false ) {
                    do_action( 'wprules_rule_updated', $rule_id );
                    // Redirect to remove edit parameter and show success message
                    wp_redirect( add_query_arg( [ 'page' => 'wc-product-rules', 'updated' => '1' ], admin_url( 'admin.php' ) ) );
                    exit;
                } else {
                    echo '<div class="notice notice-error"><p>Error updating rule. Please try again.</p></div>';
                }
            } else {
                $result = $wpdb->insert( $table, $data );
                if ( $result !== false ) {
                    do_action( 'wprules_rule_created', $wpdb->insert_id );
                    // Redirect to remove any edit parameter and show success message
                    wp_redirect( add_query_arg( [ 'page' => 'wc-product-rules', 'created' => '1' ], admin_url( 'admin.php' ) ) );
                    exit;
                } else {
                    echo '<div class="notice notice-error"><p>Error creating rule. Please try again.</p></div>';
                }
            }
        }

        // Save error message settings
        if ( isset( $_POST['save_settings'] ) ) {
            check_admin_referer( 'wprules_settings_nonce' );
            
            // Allow safe HTML tags in error messages (br, strong, em, etc.)
            $allowed_html = [
                'br' => [],
                'strong' => [],
                'em' => [],
                'b' => [],
                'i' => [],
                'p' => [],
                'div' => [],
                'span' => [],
                'ul' => [],
                'ol' => [],
                'li' => [],
            ];
            
            $settings = [
                'dependencies_message' => wp_kses( $_POST['dependencies_message'], $allowed_html ),
                'restrictions_message' => wp_kses( $_POST['restrictions_message'], $allowed_html ),
                'limit_message' => wp_kses( $_POST['limit_message'], $allowed_html ),
                'cart_violation_message' => wp_kses( $_POST['cart_violation_message'], $allowed_html ),
            ];
            
            update_option( 'wprules_error_messages', $settings );
            echo '<div class="notice notice-success"><p>Error message settings saved successfully!</p></div>';
        }

        // Clear cache
        if ( isset( $_GET['action'] ) && $_GET['action'] === 'clear_cache' ) {
            check_admin_referer( 'clear_cache' );
            $cache = new WPRules_Cache();
            $cache->clear_rules_cache();
            echo '<div class="notice notice-success"><p>Cache cleared successfully! The cache will rebuild automatically on the next page load.</p></div>';
        }

        // Delete single rule
        if ( isset( $_GET['delete'] ) ) {
            $del = intval( $_GET['delete'] );
            check_admin_referer( 'delete_rule_' . $del );
            $result = $wpdb->delete( $table, [ 'id' => $del ] );
            if ( $result !== false ) {
                do_action( 'wprules_rule_deleted', $del );
                echo '<div class="notice notice-success"><p>Rule deleted successfully!</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>Error deleting rule. Please try again.</p></div>';
            }
        }

        // Bulk delete
        if ( isset( $_POST['bulk_action'] ) && $_POST['bulk_action'] === 'delete' && isset( $_POST['rule_ids'] ) && is_array( $_POST['rule_ids'] ) ) {
            check_admin_referer( 'bulk_delete_rules', 'bulk_delete_rules_nonce' );
            
            $rule_ids = array_map( 'absint', $_POST['rule_ids'] );
            $rule_ids = array_filter( $rule_ids );
            
            if ( ! empty( $rule_ids ) ) {
                $deleted = 0;
                $failed = 0;
                
                foreach ( $rule_ids as $rule_id ) {
                    $result = $wpdb->delete( $table, [ 'id' => $rule_id ] );
                    if ( $result !== false ) {
                        $deleted++;
                        do_action( 'wprules_rule_deleted', $rule_id );
                    } else {
                        $failed++;
                    }
                }
                
                if ( $deleted > 0 ) {
                    $message = sprintf( '%d rule(s) deleted successfully.', $deleted );
                    if ( $failed > 0 ) {
                        $message .= ' ' . $failed . ' rule(s) failed to delete.';
                    }
                    echo '<div class="notice notice-success"><p>' . esc_html( $message ) . '</p></div>';
                } else {
                    echo '<div class="notice notice-error"><p>No rules were deleted. Please try again.</p></div>';
                }
            }
        }

        // Show success messages after redirect
        if ( isset( $_GET['updated'] ) && $_GET['updated'] == '1' ) {
            echo '<div class="notice notice-success is-dismissible"><p>Rule updated successfully!</p></div>';
        }
        if ( isset( $_GET['created'] ) && $_GET['created'] == '1' ) {
            echo '<div class="notice notice-success is-dismissible"><p>Rule created successfully!</p></div>';
        }

        // Edit (load rule)
        $edit_rule = null;
        if ( isset( $_GET['edit'] ) ) {
            $edit_id = intval( $_GET['edit'] );
            $edit_rule = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $edit_id ) );
        }

        // Handle pagination and sorting
        $per_page = 20;
        $current_page = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;
        $orderby = isset( $_GET['orderby'] ) ? sanitize_text_field( $_GET['orderby'] ) : 'id';
        $order = isset( $_GET['order'] ) ? sanitize_text_field( $_GET['order'] ) : 'DESC';
        
        $cache = new WPRules_Cache();
        $rules_data = $cache->get_rules_paginated( $current_page, $per_page, $orderby, $order );
        $rules = $rules_data['rules'];
        $total_rules = $rules_data['total'];
        $total_pages = $rules_data['pages'];

        // Get current error message settings or defaults
        $settings = get_option( 'wprules_error_messages', [
            'dependencies_message' => '%s cannot be added to cart.<br>You must first purchase %s of the following products:<br>%s',
            'restrictions_message' => '%s cannot be added to cart.<br>You already have the following conflicting products:<br>%s',
            'limit_message' => '%s cannot be added to cart. This product has a purchase limit of %d. You currently have %d in your cart.',
            'cart_violation_message' => 'Some products in your cart violate purchase rules. Please review your cart and adjust.',
        ]);
        ?>
        <div class="wrap">
            <h1>Product Rules</h1>
            
            <?php
            // Handle tab switching - check POST first (for form submissions), then GET
            $active_tab = 'rules'; // default
            if ( isset( $_POST['active_tab'] ) ) {
                $active_tab = sanitize_text_field( $_POST['active_tab'] );
            } elseif ( isset( $_GET['tab'] ) ) {
                $active_tab = sanitize_text_field( $_GET['tab'] );
            }
            ?>
            
            <h2 class="nav-tab-wrapper">
                <a href="?page=wc-product-rules&tab=rules" class="nav-tab <?php echo $active_tab == 'rules' ? 'nav-tab-active' : ''; ?>">Rules</a>
                <a href="?page=wc-product-rules&tab=settings" class="nav-tab <?php echo $active_tab == 'settings' ? 'nav-tab-active' : ''; ?>">Error Message Settings</a>
                <a href="?page=wc-product-rules&tab=import-export" class="nav-tab <?php echo $active_tab == 'import-export' ? 'nav-tab-active' : ''; ?>">Import/Export</a>
                <a href="?page=wc-product-rules&tab=cache" class="nav-tab <?php echo $active_tab == 'cache' ? 'nav-tab-active' : ''; ?>">Cache Management</a>
            </h2>

            <?php if ( $active_tab == 'rules' ) : ?>
            <form method="post">
                <?php wp_nonce_field( 'save_rule_nonce' ); ?>
                <input type="hidden" name="rule_id" value="<?php echo $edit_rule ? intval( $edit_rule->id ) : ''; ?>">
                <input type="hidden" name="active_tab" value="rules">

                <p><label>Products (select one or more):
                    <select name="product_ids[]" class="wprules-product-search" multiple="multiple" style="width:400px" data-placeholder="Search products...">
                        <?php
                        if ( $edit_rule ) {
                            $pids = json_decode( $edit_rule->product_ids, true );
                            if ( is_array( $pids ) ) {
                                foreach ( $pids as $pid ) {
                                    echo '<option value="' . intval( $pid ) . '" selected>' . esc_html( get_the_title( $pid ) . ' (#' . $pid . ')' ) . '</option>';
                                }
                            }
                        }
                        ?>
                    </select>
                </label></p>

                <p><label>Rule Type:
                    <select name="rule_type">
                        <option value="dependencies" <?php selected( $edit_rule && $edit_rule->rule_type === 'dependencies' ); ?>>Dependencies</option>
                        <option value="restrictions" <?php selected( $edit_rule && $edit_rule->rule_type === 'restrictions' ); ?>>Restrictions</option>
                        <option value="limit" <?php selected( $edit_rule && $edit_rule->rule_type === 'limit' ); ?>>Limit</option>
                    </select>
                </label></p>

                <p class="field-related-product"><label>Target Products (select one or more):
                    <select name="target_ids[]" class="wprules-product-search" multiple="multiple" style="width:400px" data-placeholder="Search products...">
                        <?php
                        if ( $edit_rule && $edit_rule->target_ids ) {
                            $tids = json_decode( $edit_rule->target_ids, true );
                            if ( is_array( $tids ) ) {
                                foreach ( $tids as $tid ) {
                                    echo '<option value="' . intval( $tid ) . '" selected>' . esc_html( get_the_title( $tid ) . ' (#' . $tid . ')' ) . '</option>';
                                }
                            }
                        }
                        ?>
                    </select>
                </label></p>

                <p>
    <label>Match Type:
        <select name="match_type">
            <option value="any" <?php selected( $edit_rule && $edit_rule->match_type === 'any' ); ?>>Any of the targets</option>
            <option value="all" <?php selected( $edit_rule && $edit_rule->match_type === 'all' ); ?>>All targets</option>
        </select>
    </label>
</p>

                <p><label>User Roles (leave empty to apply to all roles):
                    <?php
                    $allowed_roles = [ 'CT-Teen', 'CT-Adult', 'MA-Teen', 'MA-Adult' ];
                    $selected_roles = [];
                    if ( $edit_rule && ! empty( $edit_rule->user_roles ) ) {
                        $selected_roles = json_decode( $edit_rule->user_roles, true );
                        if ( ! is_array( $selected_roles ) ) {
                            $selected_roles = [];
                        }
                    }
                    ?>
                    <select name="user_roles[]" multiple="multiple" style="width:400px" data-placeholder="Select roles (optional)">
                        <?php foreach ( $allowed_roles as $role ) : ?>
                            <option value="<?php echo esc_attr( $role ); ?>" <?php selected( in_array( $role, $selected_roles, true ) ); ?>>
                                <?php echo esc_html( $role ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <br><small>If no roles are selected, the rule applies to all users.</small>
                </label></p>

                <p class="field-max-qty"><label>Max Qty (for limit rule):
                    <input type="number" name="limit_qty" min="1" value="<?php echo $edit_rule && $edit_rule->limit_qty ? intval( $edit_rule->limit_qty ) : ''; ?>">
                </label></p>

                <p><button type="submit" class="button button-primary" name="save_rule">Save Rule</button></p>
            </form>

            <h2>Existing Rules 
                <span class="subtitle">(<?php echo $total_rules; ?> total rules)</span>
            </h2>
            
            <form method="post" id="bulk-action-form">
                <?php wp_nonce_field( 'bulk_delete_rules', 'bulk_delete_rules_nonce' ); ?>
                
                <?php if ( $total_pages > 1 ) : ?>
                <div class="tablenav top">
                    <div class="alignleft actions bulkactions">
                        <select name="bulk_action" id="bulk-action-selector">
                            <option value="">Bulk Actions</option>
                            <option value="delete">Delete</option>
                        </select>
                        <button type="submit" class="button action" id="doaction">Apply</button>
                    </div>
                    <div class="tablenav-pages">
                        <span class="displaying-num"><?php echo $total_rules; ?> items</span>
                        <?php
                        $pagination_args = [
                            'base' => add_query_arg( [ 'paged' => '%#%', 'orderby' => $orderby, 'order' => $order ] ),
                            'format' => '',
                            'prev_text' => '&laquo;',
                            'next_text' => '&raquo;',
                            'total' => $total_pages,
                            'current' => $current_page
                        ];
                        echo paginate_links( $pagination_args );
                        ?>
                    </div>
                </div>
                <?php else : ?>
                <div class="tablenav top">
                    <div class="alignleft actions bulkactions">
                        <select name="bulk_action" id="bulk-action-selector">
                            <option value="">Bulk Actions</option>
                            <option value="delete">Delete</option>
                        </select>
                        <button type="submit" class="button action" id="doaction">Apply</button>
                    </div>
                </div>
                <?php endif; ?>
            
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th class="check-column"><input type="checkbox" id="cb-select-all"></th>
                        <th>ID</th>
                        <th>Products</th>
                        <th>
                            <?php
                            $type_order = ( $orderby === 'rule_type' && $order === 'ASC' ) ? 'DESC' : 'ASC';
                            $type_url = add_query_arg( [ 'orderby' => 'rule_type', 'order' => $type_order, 'paged' => 1 ] );
                            ?>
                            <a href="<?php echo esc_url( $type_url ); ?>" style="text-decoration: none; color: inherit;">
                                Type
                                <?php if ( $orderby === 'rule_type' ) : ?>
                                    <span class="dashicons dashicons-arrow-<?php echo $order === 'ASC' ? 'up' : 'down'; ?>-alt" style="font-size: 14px; vertical-align: middle;"></span>
                                <?php endif; ?>
                            </a>
                        </th>
                        <th>Targets</th>
                        <th>
                            <?php
                            $roles_order = ( $orderby === 'user_roles' && $order === 'ASC' ) ? 'DESC' : 'ASC';
                            $roles_url = add_query_arg( [ 'orderby' => 'user_roles', 'order' => $roles_order, 'paged' => 1 ] );
                            ?>
                            <a href="<?php echo esc_url( $roles_url ); ?>" style="text-decoration: none; color: inherit;">
                                User Roles
                                <?php if ( $orderby === 'user_roles' ) : ?>
                                    <span class="dashicons dashicons-arrow-<?php echo $order === 'ASC' ? 'up' : 'down'; ?>-alt" style="font-size: 14px; vertical-align: middle;"></span>
                                <?php endif; ?>
                            </a>
                        </th>
                        <th>Limit</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                if ( $rules ) {
                    foreach ( $rules as $rule ) {
                        $pids = json_decode( $rule->product_ids, true );
                        $tids = $rule->target_ids ? json_decode( $rule->target_ids, true ) : [];
                        $user_roles = ! empty( $rule->user_roles ) ? json_decode( $rule->user_roles, true ) : [];
                        $allowed_roles = [ 'CT-Teen', 'CT-Adult', 'MA-Teen', 'MA-Adult' ];
                        
                        echo '<tr class="wprules-rule-row" data-rule-id="' . intval( $rule->id ) . '" data-rule-data="' . esc_attr( wp_json_encode( [
                        'product_ids' => $pids,
                        'target_ids' => $tids,
                        'rule_type' => $rule->rule_type,
                        'match_type' => $rule->match_type,
                        'limit_qty' => $rule->limit_qty,
                        'user_roles' => $user_roles
                        ] ) ) . '">';
                        
                        // Checkbox column
                        echo '<th scope="row" class="check-column">';
                        echo '<input type="checkbox" name="rule_ids[]" value="' . intval( $rule->id ) . '" class="rule-checkbox">';
                        echo '</th>';
                        
                        // ID column
                        echo '<td>';
                        echo '<span class="wprules-view-mode">' . intval( $rule->id ) . '</span>';
                        echo '<span class="wprules-edit-mode" style="display:none;">' . intval( $rule->id ) . '</span>';
                        echo '</td>';
                        
                        // Products column
                        echo '<td>';
                        // View mode
                        if ( is_array( $pids ) && ! empty( $pids ) ) {
                            $products_list = '<ul class="wprules-product-list wprules-view-mode">';
                            foreach ( $pids as $pid ) {
                                $product_title = get_the_title( $pid );
                                $products_list .= '<li>' . esc_html( $product_title ) . ' <span class="wprules-product-id">(#' . intval( $pid ) . ')</span></li>';
                            }
                            $products_list .= '</ul>';
                            echo $products_list;
                        } else {
                            echo '<span class="wprules-view-mode">-</span>';
                        }
                        // Edit mode
                        $products_selected = is_array( $pids ) ? $pids : [];
                        $products_options = '';
                        foreach ( $products_selected as $pid ) {
                            $products_options .= '<option value="' . intval( $pid ) . '" selected>' . esc_html( get_the_title( $pid ) . ' (#' . $pid . ')' ) . '</option>';
                        }
                        echo '<span class="wprules-edit-mode" style="display:none;"><select name="product_ids[]" class="wprules-product-search wprules-inline-edit" multiple="multiple" style="width:100%; min-width:200px;" data-placeholder="Search products...">' . $products_options . '</select></span>';
                        echo '</td>';
                        
                        // Type column
                        echo '<td>';
                        echo '<span class="wprules-view-mode">' . esc_html( ucfirst( $rule->rule_type ) ) . '</span>';
                        echo '<span class="wprules-edit-mode" style="display:none;"><select name="rule_type" class="wprules-inline-edit">';
                        echo '<option value="dependencies"' . selected( $rule->rule_type, 'dependencies', false ) . '>Dependencies</option>';
                        echo '<option value="restrictions"' . selected( $rule->rule_type, 'restrictions', false ) . '>Restrictions</option>';
                        echo '<option value="limit"' . selected( $rule->rule_type, 'limit', false ) . '>Limit</option>';
                        echo '</select></span>';
                        echo '</td>';
                        
                        // Targets column
                        echo '<td>';
                        // View mode
                        if ( is_array( $tids ) && ! empty( $tids ) ) {
                            $targets_list = '<ul class="wprules-product-list wprules-view-mode">';
                            foreach ( $tids as $tid ) {
                                $target_title = get_the_title( $tid );
                                $targets_list .= '<li>' . esc_html( $target_title ) . ' <span class="wprules-product-id">(#' . intval( $tid ) . ')</span></li>';
                            }
                            $targets_list .= '</ul>';
                            echo $targets_list;
                        } else {
                            echo '<span class="wprules-view-mode">-</span>';
                        }
                        // Edit mode
                        $targets_selected = is_array( $tids ) ? $tids : [];
                        $targets_options = '';
                        foreach ( $targets_selected as $tid ) {
                            $targets_options .= '<option value="' . intval( $tid ) . '" selected>' . esc_html( get_the_title( $tid ) . ' (#' . $tid . ')' ) . '</option>';
                        }
                        echo '<span class="wprules-edit-mode wprules-field-related-product" style="display:none;"><select name="target_ids[]" class="wprules-product-search wprules-inline-edit" multiple="multiple" style="width:100%; min-width:200px;" data-placeholder="Search products...">' . $targets_options . '</select></span>';
                        echo '</td>';
                        
                        // User Roles column
                        echo '<td>';
                        // View mode
                        if ( is_array( $user_roles ) && ! empty( $user_roles ) ) {
                            echo '<span class="wprules-view-mode">' . esc_html( implode( ', ', $user_roles ) ) . '</span>';
                        } else {
                            echo '<span class="wprules-view-mode"><em>All roles</em></span>';
                        }
                        // Edit mode
                        $roles_options = '';
                        foreach ( $allowed_roles as $role ) {
                            $selected = is_array( $user_roles ) && in_array( $role, $user_roles, true ) ? ' selected' : '';
                            $roles_options .= '<option value="' . esc_attr( $role ) . '"' . $selected . '>' . esc_html( $role ) . '</option>';
                        }
                        echo '<span class="wprules-edit-mode" style="display:none;"><select name="user_roles[]" class="wprules-inline-edit" multiple="multiple" style="width:100%; min-width:150px;" data-placeholder="Select roles (optional)">' . $roles_options . '</select></span>';
                        echo '</td>';
                        
                        // Limit column
                        echo '<td>';
                        echo '<span class="wprules-view-mode">' . ( $rule->limit_qty ? intval( $rule->limit_qty ) : '-' ) . '</span>';
                        echo '<span class="wprules-edit-mode wprules-field-max-qty" style="display:none;"><input type="number" name="limit_qty" class="wprules-inline-edit" min="1" value="' . ( $rule->limit_qty ? intval( $rule->limit_qty ) : '' ) . '" style="width:80px;"></span>';
                        // Hidden match_type field
                        echo '<span class="wprules-edit-mode" style="display:none;"><select name="match_type" class="wprules-inline-edit" style="display:none;">';
                        echo '<option value="any"' . selected( $rule->match_type, 'any', false ) . '>Any</option>';
                        echo '<option value="all"' . selected( $rule->match_type, 'all', false ) . '>All</option>';
                        echo '</select></span>';
                        echo '</td>';
                        
                        // Actions column
                        echo '<td>';
                        echo '<span class="wprules-view-mode">';
                        echo '<a href="#" class="wprules-edit-row" data-rule-id="' . intval( $rule->id ) . '">Edit</a> | ';
                        echo '<a href="' . wp_nonce_url( admin_url( 'admin.php?page=wc-product-rules&delete=' . $rule->id ), 'delete_rule_' . $rule->id ) . '" onclick="return confirm(\'Delete this rule?\')">Delete</a>';
                        echo '</span>';
                        echo '<span class="wprules-edit-mode" style="display:none;">';
                        echo '<button type="button" class="button button-small wprules-save-row" data-rule-id="' . intval( $rule->id ) . '">Save</button> ';
                        echo '<button type="button" class="button button-small wprules-cancel-row">Cancel</button>';
                        echo '</span>';
                        echo '</td>';
                        echo '</tr>';
                    }
                } else {
                    echo '<tr><td colspan="8">No rules yet.</td></tr>';
                }
                ?>
                </tbody>
            </table>
            </form>
            
            <?php if ( $total_pages > 1 ) : ?>
            <div class="tablenav bottom">
                <div class="tablenav-pages">
                    <span class="displaying-num"><?php echo $total_rules; ?> items</span>
                    <?php echo paginate_links( $pagination_args ); ?>
                </div>
            </div>
            <?php endif; ?>
            <?php endif; ?>

            <?php if ( $active_tab == 'settings' ) : ?>
            <h2>Error Message Settings</h2>
            <p>Customize the error messages that customers see when rule violations occur.</p>
            
            <form method="post" style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; margin-top: 20px;">
                <?php wp_nonce_field( 'wprules_settings_nonce' ); ?>
                <input type="hidden" name="active_tab" value="settings">
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="dependencies_message">Dependencies Error Message</label>
                        </th>
                        <td>
                            <textarea id="dependencies_message" name="dependencies_message" rows="3" cols="80" class="large-text"><?php echo esc_textarea( $settings['dependencies_message'] ); ?></textarea>
                            <p class="description">
                                <strong>Placeholders:</strong> %s = product name, %s = "all" or "at least one", %s = list of missing products<br>
                                <strong>Example:</strong> %s has dependencies on %s of the following product(s) before purchase: %s
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="restrictions_message">Restrictions Error Message</label>
                        </th>
                        <td>
                            <textarea id="restrictions_message" name="restrictions_message" rows="3" cols="80" class="large-text"><?php echo esc_textarea( $settings['restrictions_message'] ); ?></textarea>
                            <p class="description">
                                <strong>Placeholders:</strong> %s = product name, %s = list of conflicting products<br>
                                <strong>Example:</strong> %s cannot be purchased due to restrictions - you already have: %s
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="limit_message">Purchase Limit Error Message</label>
                        </th>
                        <td>
                            <textarea id="limit_message" name="limit_message" rows="3" cols="80" class="large-text"><?php echo esc_textarea( $settings['limit_message'] ); ?></textarea>
                            <p class="description">
                                <strong>Placeholders:</strong> %s = product name, %d = limit quantity, %d = current quantity in cart<br>
                                <strong>Example:</strong> %s has a purchase limit of %d. You currently have %d in your cart.
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="cart_violation_message">Cart Violation Message</label>
                        </th>
                        <td>
                            <textarea id="cart_violation_message" name="cart_violation_message" rows="3" cols="80" class="large-text"><?php echo esc_textarea( $settings['cart_violation_message'] ); ?></textarea>
                            <p class="description">
                                <strong>No placeholders</strong> - This is a general message shown when cart validation fails<br>
                                <strong>Example:</strong> Some products in your cart violate purchase rules. Please review your cart and adjust.
                            </p>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <input type="submit" name="save_settings" class="button-primary" value="Save Error Message Settings" />
                </p>
            </form>
            
            <div class="card" style="background: #f9f9f9; padding: 15px; margin-top: 20px; border: 1px solid #ddd;">
                <h3>Message Preview</h3>
                <p>Here's how your custom messages will appear to customers:</p>
                <ul>
                    <li><strong>Dependencies:</strong> "Sample Product has dependencies on at least one of the following product(s) before purchase: Required Product (#123)"</li>
                    <li><strong>Restrictions:</strong> "Sample Product cannot be purchased due to restrictions - you already have: Conflicting Product (#456)"</li>
                    <li><strong>Purchase Limit:</strong> "Sample Product has a purchase limit of 1. You currently have 2 in your cart."</li>
                    <li><strong>Cart Violation:</strong> "Some products in your cart violate purchase rules. Please review your cart and adjust."</li>
                </ul>
            </div>

            <?php endif; ?>

            <?php if ( $active_tab == 'cache' ) : ?>
            <h2>Cache Management</h2>
            <p>Monitor and manage the plugin's caching system for optimal performance.</p>
            
            <?php
            $cache = new WPRules_Cache();
            $stats = $cache->get_cache_stats();
            ?>
            
            <div class="wprules-cache-container" style="display: flex; flex-wrap: wrap; gap: 20px; margin-top: 20px;">
                <!-- Cache Status & Performance - 2/3 width on large screens, full width on small screens -->
                <div class="wprules-cache-status" style="flex: 2 1 300px; min-width: 0;">
                    <div class="card" style="background: #f0f8ff; padding: 20px; border: 1px solid #b3d9ff;">
                        <h3>Cache Status & Performance</h3>
                        
                        <table class="form-table">
                            <tr>
                                <th scope="row">Cache Status</th>
                                <td>
                                    <span style="color: green; font-weight: bold;">✓ Active</span> - Rules are cached for better performance
                                    <p class="description">The caching system automatically stores frequently accessed rules to reduce database queries.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Memory Usage</th>
                                <td>
                                    <strong>Current:</strong> <?php echo size_format( $stats['memory_usage'] ); ?> | 
                                    <strong>Peak:</strong> <?php echo size_format( $stats['peak_memory'] ); ?>
                                    <p class="description">Memory usage for the current PHP process. Peak shows the highest usage during this session.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Cache Group</th>
                                <td>
                                    <code><?php echo esc_html( $stats['cache_group'] ); ?></code>
                                    <p class="description">WordPress cache group identifier for this plugin's cached data.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Cache Expiry</th>
                                <td>
                                    <?php echo esc_html( $stats['cache_expiry'] ); ?> seconds (<?php echo round( $stats['cache_expiry'] / 60, 1 ); ?> minutes)
                                    <p class="description">How long cached data remains valid before being automatically refreshed.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Total Rules</th>
                                <td>
                                    <strong><?php echo intval( $stats['total_rules'] ); ?></strong> rules in database
                                    <p class="description">Total number of rules stored in the database.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Cached Items</th>
                                <td>
                                    <strong><?php echo esc_html( $stats['cache_keys'] ); ?></strong> cached items
                                    <p class="description">Approximate number of items currently cached for faster access.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">System Limits</th>
                                <td>
                                    <strong>Memory Limit:</strong> <?php echo esc_html( $stats['wp_memory_limit'] ); ?> | 
                                    <strong>Max Execution Time:</strong> <?php echo esc_html( $stats['wp_max_execution_time'] ); ?>s
                                    <p class="description">Current WordPress system limits for memory and execution time.</p>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>

                <!-- Cache Actions - 1/3 width on large screens, full width on small screens -->
                <div class="wprules-cache-actions" style="flex: 1 1 250px; min-width: 0;">
                    <div class="card" style="background: #fff; padding: 20px; border: 1px solid #ccd0d4;">
                        <h3>Cache Actions</h3>
                        
                        <div style="margin-bottom: 20px;">
                            <a href="<?php echo wp_nonce_url( admin_url( 'admin.php?page=wc-product-rules&tab=cache&action=clear_cache' ), 'clear_cache' ); ?>" 
                               class="button button-secondary" 
                               onclick="return confirm('Clear all cached rules? This will temporarily slow down the next few page loads as the cache rebuilds.')"
                               style="width: 100%; text-align: center; margin-bottom: 10px;">
                                🗑️ Clear Cache
                            </a>
                            <p class="description" style="font-size: 12px; margin: 0;">
                                <strong>When to use:</strong> After bulk rule changes, if experiencing issues, or during troubleshooting.
                            </p>
                        </div>

                        <div style="border-top: 1px solid #ddd; padding-top: 15px;">
                            <h4 style="margin-top: 0;">Cache Information</h4>
                            <p class="description" style="font-size: 12px; margin: 0;">
                                <strong>What's cached:</strong> Product rules, paginated rule lists, and rule lookups<br><br>
                                <strong>Cache invalidation:</strong> Automatically clears when rules are created, updated, or deleted<br><br>
                                <strong>Performance benefit:</strong> 90%+ reduction in database queries for rule validation
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <style>
            /* Override WordPress global .card max-width */
            .wprules-cache-container .card {
                max-width: none !important;
                width: 100% !important;
            }
            
            /* Responsive styles for cache management */
            @media (max-width: 768px) {
                .wprules-cache-container {
                    flex-direction: column !important;
                }
                
                .wprules-cache-status,
                .wprules-cache-actions {
                    flex: 1 1 100% !important;
                    min-width: 0 !important;
                }
                
                .wprules-cache-container .card {
                    margin-bottom: 0 !important;
                }
            }
            
            @media (max-width: 600px) {
                .wprules-cache-container .form-table th {
                    width: 30% !important;
                }
                
                .wprules-cache-container .form-table td {
                    padding-left: 10px !important;
                }
            }
            </style>
            <?php endif; ?>

            <?php if ( $active_tab == 'import-export' ) : ?>
            <h2>Import/Export Rules</h2>
            <p>Export your product rules to a JSON file for backup or to import into another site. You can also import rules from a previously exported file.</p>
            
            <div style="display: flex; gap: 20px; margin-top: 20px;">
                <!-- Export Section -->
                <div class="card" style="flex: 1; background: #fff; padding: 20px; border: 1px solid #ccd0d4;">
                    <h3>Export Rules</h3>
                    <p>Export all current product rules to a JSON file.</p>
                    <?php
                    global $wpdb;
                    $table = $wpdb->prefix . 'wc_product_rules';
                    $total_rules = $wpdb->get_var( "SELECT COUNT(*) FROM $table" );
                    ?>
                    <p><strong><?php echo intval( $total_rules ); ?></strong> rules will be exported.</p>
                    <form method="post" action="">
                        <?php wp_nonce_field( 'wprules_export', 'wprules_export_nonce' ); ?>
                        <input type="hidden" name="wprules_action" value="export">
                        <p>
                            <button type="submit" class="button button-primary">
                                📥 Export Rules
                            </button>
                        </p>
                    </form>
                </div>

                <!-- Import Section -->
                <div class="card" style="flex: 1; background: #fff; padding: 20px; border: 1px solid #ccd0d4;">
                    <h3>Import Rules</h3>
                    <p>Import rules from a previously exported JSON file.</p>
                    <form method="post" enctype="multipart/form-data" action="">
                        <?php wp_nonce_field( 'wprules_import', 'wprules_import_nonce' ); ?>
                        <input type="hidden" name="wprules_action" value="import">
                        <p>
                            <label for="import_file">
                                <strong>Select CSV file:</strong><br>
                                <input type="file" name="import_file" id="import_file" accept=".csv" required style="margin-top: 10px;">
                            </label>
                        </p>
                        <p>
                            <label>
                                <input type="checkbox" name="import_overwrite" value="1" checked>
                                Overwrite existing rules with same product IDs
                            </label>
                        </p>
                        <p>
                            <button type="submit" class="button button-primary">
                                📤 Import Rules
                            </button>
                        </p>
                    </form>
                </div>
            </div>

            <div class="card" style="background: #f9f9f9; padding: 15px; margin-top: 20px; border: 1px solid #ddd;">
                <h3>Import/Export Information</h3>
                <ul style="margin-left: 20px;">
                    <li><strong>Export format:</strong> CSV file that can be edited in Excel, Google Sheets, or any spreadsheet application</li>
                    <li><strong>Import format:</strong> CSV file exported from this plugin (or manually created following the same format)</li>
                    <li><strong>What's exported:</strong> Product IDs with titles, rule types, target IDs with titles, match types, limits, user roles, and scope</li>
                    <li><strong>Product/Target format:</strong> Exported as "ID - Product Title" (e.g., "27 - CT - 2 Hour Driving Lesson")</li>
                    <li><strong>Import formats supported:</strong> 
                        <ul style="margin-left: 20px; margin-top: 5px;">
                            <li>"27 - Product Title" (ID and title)</li>
                            <li>"27" (just the ID)</li>
                            <li>"Product Title" (searches by title)</li>
                        </ul>
                    </li>
                    <li><strong>Multiple values:</strong> Product IDs and Target IDs use semicolons (;) to separate multiple values</li>
                    <li><strong>User roles:</strong> Use semicolons (;) to separate multiple roles (CT-Teen, CT-Adult, MA-Teen, MA-Adult)</li>
                    <li><strong>What's NOT exported:</strong> Rule IDs (will be auto-generated on import)</li>
                    <li><strong>Overwrite option:</strong> If checked, existing rules for the same products will be replaced. If unchecked, new rules will be added.</li>
                    <li><strong>Rule types:</strong> dependencies, restrictions, or limit</li>
                    <li><strong>Match types:</strong> any or all</li>
                </ul>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Handle export of rules to CSV
     */
    public function handle_export() {
        if ( ! isset( $_POST['wprules_action'] ) || $_POST['wprules_action'] !== 'export' ) {
            return;
        }

        if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'wc-product-rules' ) {
            return;
        }

        check_admin_referer( 'wprules_export', 'wprules_export_nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Unauthorized' );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'wc_product_rules';
        $rules = $wpdb->get_results( "SELECT * FROM $table ORDER BY id" );

        $filename = 'wprules-export-' . date( 'Y-m-d-His' ) . '.csv';
        
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        
        // Open output stream
        $output = fopen( 'php://output', 'w' );
        
        // Add BOM for UTF-8 (helps Excel display special characters correctly)
        fprintf( $output, chr(0xEF).chr(0xBB).chr(0xBF) );
        
        // Write CSV headers
        fputcsv( $output, [
            'Product IDs (ID - Title)',
            'Rule Type',
            'Target IDs (ID - Title)',
            'Match Type',
            'Limit Qty',
            'User Roles',
            'Scope'
        ] );

        // Write rule data
        foreach ( $rules as $rule ) {
            $product_ids = json_decode( $rule->product_ids, true );
            $target_ids = $rule->target_ids ? json_decode( $rule->target_ids, true ) : [];
            $user_roles = $rule->user_roles ? json_decode( $rule->user_roles, true ) : [];

            // Format product IDs with titles
            $product_ids_formatted = [];
            if ( is_array( $product_ids ) ) {
                foreach ( $product_ids as $pid ) {
                    $title = get_the_title( $pid );
                    $product_ids_formatted[] = $pid . ' - ' . $title;
                }
            }

            // Format target IDs with titles
            $target_ids_formatted = [];
            if ( is_array( $target_ids ) && ! empty( $target_ids ) ) {
                foreach ( $target_ids as $tid ) {
                    $title = get_the_title( $tid );
                    $target_ids_formatted[] = $tid . ' - ' . $title;
                }
            }

            fputcsv( $output, [
                implode( ';', $product_ids_formatted ),
                $rule->rule_type,
                ! empty( $target_ids_formatted ) ? implode( ';', $target_ids_formatted ) : '',
                $rule->match_type,
                $rule->limit_qty ? $rule->limit_qty : '',
                is_array( $user_roles ) && ! empty( $user_roles ) ? implode( ';', $user_roles ) : '',
                $rule->scope
            ] );
        }

        fclose( $output );
        exit;
    }

    /**
     * Handle import of rules from CSV
     */
    public function handle_import() {
        if ( ! isset( $_POST['wprules_action'] ) || $_POST['wprules_action'] !== 'import' ) {
            return;
        }

        if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'wc-product-rules' ) {
            return;
        }

        check_admin_referer( 'wprules_import', 'wprules_import_nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Unauthorized' );
        }

        if ( ! isset( $_FILES['import_file'] ) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK ) {
            add_action( 'admin_notices', function() {
                echo '<div class="notice notice-error"><p>Error uploading file. Please try again.</p></div>';
            } );
            return;
        }

        $file = $_FILES['import_file'];
        $file_path = $file['tmp_name'];
        
        // Check if file is readable
        if ( ! is_readable( $file_path ) ) {
            add_action( 'admin_notices', function() {
                echo '<div class="notice notice-error"><p>Cannot read the uploaded file. Please check file permissions.</p></div>';
            } );
            return;
        }

        // Read CSV file
        $handle = fopen( $file_path, 'r' );
        if ( $handle === false ) {
            add_action( 'admin_notices', function() {
                echo '<div class="notice notice-error"><p>Cannot open the uploaded file. Please check the file format.</p></div>';
            } );
            return;
        }

        // Skip BOM if present (UTF-8 BOM)
        $bom = fread( $handle, 3 );
        if ( $bom !== chr(0xEF).chr(0xBB).chr(0xBF) ) {
            rewind( $handle );
        }

        // Read header row
        $headers = fgetcsv( $handle );
        if ( $headers === false ) {
            fclose( $handle );
            add_action( 'admin_notices', function() {
                echo '<div class="notice notice-error"><p>Invalid CSV file. Could not read header row.</p></div>';
            } );
            return;
        }

        // Map headers to expected columns (case-insensitive)
        $header_map = [];
        $expected_headers = [
            'product ids' => 'product_ids',
            'product ids (id - title)' => 'product_ids',
            'rule type' => 'rule_type',
            'target ids' => 'target_ids',
            'target ids (id - title)' => 'target_ids',
            'match type' => 'match_type',
            'limit qty' => 'limit_qty',
            'user roles' => 'user_roles',
            'scope' => 'scope'
        ];

        foreach ( $headers as $index => $header ) {
            $header_lower = strtolower( trim( $header ) );
            if ( isset( $expected_headers[ $header_lower ] ) ) {
                $header_map[ $index ] = $expected_headers[ $header_lower ];
            }
        }

        // Check for required columns
        if ( ! isset( $header_map[ array_search( 'product_ids', $header_map ) ] ) || 
             ! isset( $header_map[ array_search( 'rule_type', $header_map ) ] ) ) {
            fclose( $handle );
            add_action( 'admin_notices', function() {
                echo '<div class="notice notice-error"><p>Invalid CSV format. Required columns: Product IDs, Rule Type</p></div>';
            } );
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'wc_product_rules';
        $overwrite = isset( $_POST['import_overwrite'] ) && $_POST['import_overwrite'] === '1';
        
        $imported = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];
        $row_number = 1; // Start at 1 (header is row 0)

        // Process each row
        while ( ( $row = fgetcsv( $handle ) ) !== false ) {
            $row_number++;
            
            // Skip empty rows
            if ( empty( array_filter( $row ) ) ) {
                continue;
            }

            // Extract data based on header map
            $rule_data = [];
            foreach ( $header_map as $col_index => $field_name ) {
                $value = isset( $row[ $col_index ] ) ? $row[ $col_index ] : '';
                // Decode HTML entities (like &#8211; for em dash)
                $value = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
                $rule_data[ $field_name ] = trim( $value );
            }

            // Validate and parse product_ids (required)
            // Supports formats: "27", "27 - Product Title", or just "Product Title"
            if ( empty( $rule_data['product_ids'] ) ) {
                $errors[] = "Row {$row_number}: Missing Product IDs";
                continue;
            }

            // Split by semicolon, but be careful - product titles might contain semicolons in rare cases
            // Our format is "ID - Title;ID - Title" so we can safely split by semicolon
            $product_ids_raw = array_filter( array_map( 'trim', explode( ';', $rule_data['product_ids'] ) ) );
            $product_ids = [];
            $product_parse_errors = [];
            
            foreach ( $product_ids_raw as $product_input ) {
                $product_input = trim( $product_input );
                if ( empty( $product_input ) ) {
                    continue;
                }
                
                $product_id = null;
                
                // Check if it's in "ID - Title" format (most common from export)
                // Match: number followed by optional whitespace, dash, and rest of string
                if ( preg_match( '/^(\d+)\s*-\s*(.+)$/', $product_input, $matches ) ) {
                    $product_id = absint( $matches[1] );
                    // Verify product exists
                    $product_post = get_post( $product_id );
                    if ( ! $product_post || $product_post->post_type !== 'product' ) {
                        $product_parse_errors[] = "Product ID {$product_id} not found or invalid";
                        continue;
                    }
                    $product_ids[] = $product_id;
                } 
                // Check if it's just a number (ID only)
                elseif ( is_numeric( $product_input ) ) {
                    $product_id = absint( $product_input );
                    // Verify product exists
                    $product_post = get_post( $product_id );
                    if ( ! $product_post || $product_post->post_type !== 'product' ) {
                        $product_parse_errors[] = "Product ID {$product_id} not found or invalid";
                        continue;
                    }
                    $product_ids[] = $product_id;
                }
                // Otherwise, try to find product by title (shouldn't happen with exported CSVs)
                else {
                    // Decode HTML entities in title search
                    $product_input_decoded = html_entity_decode( $product_input, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
                    $product = get_page_by_title( $product_input_decoded, OBJECT, 'product' );
                    if ( $product ) {
                        $product_ids[] = $product->ID;
                    } else {
                        // Try searching by title (partial match)
                        $products = wc_get_products( [
                            'limit' => 1,
                            's' => $product_input_decoded,
                            'return' => 'ids'
                        ] );
                        if ( ! empty( $products ) ) {
                            $product_ids[] = $products[0];
                        } else {
                            $product_parse_errors[] = "Product not found: '{$product_input}'";
                        }
                    }
                }
            }
            
            // Report product parsing errors
            if ( ! empty( $product_parse_errors ) ) {
                $errors[] = "Row {$row_number}: " . implode( ', ', $product_parse_errors );
            }
            
            $product_ids = array_filter( $product_ids ); // Remove zeros and empty

            if ( empty( $product_ids ) ) {
                if ( empty( $product_parse_errors ) ) {
                    $errors[] = "Row {$row_number}: No valid Product IDs found";
                }
                continue;
            }

            // Validate rule_type (required)
            $rule_type = isset( $rule_data['rule_type'] ) ? strtolower( trim( $rule_data['rule_type'] ) ) : '';
            if ( ! in_array( $rule_type, [ 'dependencies', 'restrictions', 'limit' ], true ) ) {
                $errors[] = "Row {$row_number}: Invalid Rule Type (must be: dependencies, restrictions, or limit)";
                continue;
            }

            // Parse target_ids (optional, semicolon-separated)
            // Supports formats: "27", "27 - Product Title", or just "Product Title"
            $target_ids = [];
            if ( ! empty( $rule_data['target_ids'] ) ) {
                $target_ids_raw = array_filter( array_map( 'trim', explode( ';', $rule_data['target_ids'] ) ) );
                
                foreach ( $target_ids_raw as $target_input ) {
                    $target_input = trim( $target_input );
                    if ( empty( $target_input ) ) {
                        continue;
                    }
                    
                    // Check if it's in "ID - Title" format (most common from export)
                    // Match: number followed by optional whitespace, dash, and rest of string
                    if ( preg_match( '/^(\d+)\s*-\s*(.+)$/', $target_input, $matches ) ) {
                        $target_id = absint( $matches[1] );
                        // Verify product exists
                        $product_post = get_post( $target_id );
                        if ( ! $product_post || $product_post->post_type !== 'product' ) {
                            $errors[] = "Row {$row_number}: Target product ID {$target_id} not found or invalid";
                            continue;
                        }
                        $target_ids[] = $target_id;
                    } 
                    // Check if it's just a number (ID only)
                    elseif ( is_numeric( $target_input ) ) {
                        $target_id = absint( $target_input );
                        // Verify product exists
                        $product_post = get_post( $target_id );
                        if ( ! $product_post || $product_post->post_type !== 'product' ) {
                            $errors[] = "Row {$row_number}: Target product ID {$target_id} not found or invalid";
                            continue;
                        }
                        $target_ids[] = $target_id;
                    }
                    // Otherwise, try to find product by title (shouldn't happen with exported CSVs)
                    else {
                        // Decode HTML entities in title search
                        $target_input_decoded = html_entity_decode( $target_input, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
                        $product = get_page_by_title( $target_input_decoded, OBJECT, 'product' );
                        if ( $product ) {
                            $target_ids[] = $product->ID;
                        } else {
                            // Try searching by title (partial match)
                            $products = wc_get_products( [
                                'limit' => 1,
                                's' => $target_input_decoded,
                                'return' => 'ids'
                            ] );
                            if ( ! empty( $products ) ) {
                                $target_ids[] = $products[0];
                            } else {
                                $errors[] = "Row {$row_number}: Target product not found: '{$target_input}'";
                            }
                        }
                    }
                }
                
                $target_ids = array_filter( $target_ids ); // Remove zeros and empty
            }

            // Parse match_type (default: any)
            $match_type = isset( $rule_data['match_type'] ) ? strtolower( trim( $rule_data['match_type'] ) ) : 'any';
            if ( ! in_array( $match_type, [ 'any', 'all' ], true ) ) {
                $match_type = 'any';
            }

            // Parse limit_qty (optional)
            $limit_qty = null;
            if ( ! empty( $rule_data['limit_qty'] ) ) {
                $limit_qty = intval( $rule_data['limit_qty'] );
                if ( $limit_qty <= 0 ) {
                    $limit_qty = null;
                }
            }

            // Parse user_roles (optional, semicolon-separated)
            $allowed_roles = [ 'CT-Teen', 'CT-Adult', 'MA-Teen', 'MA-Adult' ];
            $user_roles = [];
            if ( ! empty( $rule_data['user_roles'] ) ) {
                $roles = array_filter( array_map( 'trim', explode( ';', $rule_data['user_roles'] ) ) );
                foreach ( $roles as $role ) {
                    $role = sanitize_text_field( $role );
                    if ( in_array( $role, $allowed_roles, true ) ) {
                        $user_roles[] = $role;
                    }
                }
            }

            // Parse scope (default: both)
            $scope = isset( $rule_data['scope'] ) ? sanitize_text_field( trim( $rule_data['scope'] ) ) : 'both';
            if ( ! in_array( $scope, [ 'cart', 'checkout', 'both' ], true ) ) {
                $scope = 'both';
            }

            // Normalize arrays for comparison (sort to handle order differences)
            sort( $product_ids, SORT_NUMERIC );
            if ( ! empty( $target_ids ) ) {
                sort( $target_ids, SORT_NUMERIC );
            }
            if ( ! empty( $user_roles ) ) {
                sort( $user_roles );
            }

            // Prepare data for database
            $product_ids_json = wp_json_encode( $product_ids );
            $target_ids_json = ! empty( $target_ids ) ? wp_json_encode( $target_ids ) : null;
            $user_roles_json = ! empty( $user_roles ) ? wp_json_encode( $user_roles ) : null;

            $data = [
                'product_ids' => $product_ids_json,
                'rule_type' => $rule_type,
                'target_ids' => $target_ids_json,
                'limit_qty' => $limit_qty,
                'match_type' => $match_type,
                'scope' => $scope,
                'user_roles' => $user_roles_json,
            ];

            // Always check if rule already exists (to prevent duplicates)
            // Compare by normalized product_ids, rule_type, AND user_roles (since same products can have different rules for different roles)
            $found_existing = false;
            $existing_id = null;
            
            // Get all rules of this type to find matching product_ids
            $all_rules = $wpdb->get_results( $wpdb->prepare( "
                SELECT id, product_ids, user_roles FROM $table 
                WHERE rule_type = %s
            ", $rule_type ) );

            if ( ! empty( $all_rules ) ) {
                foreach ( $all_rules as $existing_rule ) {
                    $existing_product_ids = json_decode( $existing_rule->product_ids, true );
                    $existing_user_roles = $existing_rule->user_roles ? json_decode( $existing_rule->user_roles, true ) : [];
                    
                    if ( is_array( $existing_product_ids ) ) {
                        sort( $existing_product_ids, SORT_NUMERIC );
                        
                        // Compare product IDs first
                        if ( $existing_product_ids === $product_ids ) {
                            // Also compare user roles (normalized)
                            if ( is_array( $existing_user_roles ) ) {
                                sort( $existing_user_roles );
                            } else {
                                $existing_user_roles = [];
                            }
                            
                            // Empty arrays should match (both mean "all roles")
                            $roles_match = ( empty( $existing_user_roles ) && empty( $user_roles ) ) ||
                                          ( $existing_user_roles === $user_roles );
                            
                            if ( $roles_match ) {
                                $found_existing = true;
                                $existing_id = $existing_rule->id;
                                break;
                            }
                        }
                    }
                }
            }

            if ( $found_existing && $existing_id ) {
                if ( $overwrite ) {
                    // Update existing rule
                    $result = $wpdb->update( $table, $data, [ 'id' => $existing_id ] );
                    if ( $result !== false ) {
                        $updated++;
                        do_action( 'wprules_rule_updated', $existing_id );
                    } else {
                        $errors[] = "Row {$row_number}: Failed to update existing rule (ID: {$existing_id}) - " . $wpdb->last_error;
                    }
                } else {
                    // Skip duplicate (overwrite not enabled)
                    $skipped++;
                    // Add to errors list so user knows what was skipped
                    $product_ids_str = implode( ', ', $product_ids );
                    $roles_str = ! empty( $user_roles ) ? ' (' . implode( ', ', $user_roles ) . ')' : ' (all roles)';
                    $errors[] = "Row {$row_number}: Skipped duplicate rule - Products: [{$product_ids_str}], Type: {$rule_type}{$roles_str}";
                }
                continue;
            }

            // Insert new rule (no duplicate found)
            $result = $wpdb->insert( $table, $data );
            if ( $result !== false ) {
                $imported++;
                do_action( 'wprules_rule_created', $wpdb->insert_id );
            } else {
                $db_error = $wpdb->last_error ? $wpdb->last_error : 'Unknown database error';
                $errors[] = "Row {$row_number}: Failed to insert rule - {$db_error}";
            }
        }

        fclose( $handle );

        // Clear cache after import
        $cache = new WPRules_Cache();
        $cache->clear_rules_cache();

        // Show results
        $message = sprintf(
            'Import completed: %d new rules imported, %d rules updated',
            $imported,
            $updated
        );

        if ( $skipped > 0 ) {
            $message .= ', ' . $skipped . ' duplicate(s) skipped';
        }

        if ( ! empty( $errors ) ) {
            $message .= '. ' . count( $errors ) . ' error(s) occurred.';
        } else {
            $message .= '.';
        }

        add_action( 'admin_notices', function() use ( $message, $errors, $imported, $updated, $skipped ) {
            $class = ! empty( $errors ) ? 'notice-warning' : 'notice-success';
            if ( $imported === 0 && $updated === 0 && $skipped === 0 && ! empty( $errors ) ) {
                $class = 'notice-error';
            }
            echo '<div class="notice ' . $class . ' is-dismissible"><p><strong>' . esc_html( $message ) . '</strong></p>';
            if ( ! empty( $errors ) ) {
                echo '<details style="margin-top: 10px;">';
                echo '<summary style="cursor: pointer; font-weight: bold;">Show error details (' . count( $errors ) . ' errors)</summary>';
                echo '<ul style="margin-left: 20px; max-height: 400px; overflow-y: auto; margin-top: 10px;">';
                foreach ( $errors as $error ) {
                    echo '<li style="margin-bottom: 5px;">' . esc_html( $error ) . '</li>';
                }
                echo '</ul>';
                echo '</details>';
            }
            echo '</div>';
        } );
    }
}