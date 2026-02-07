<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WPRules_Validator {

    public function __construct() {
        // Only initialize if WooCommerce is active
        if ( ! class_exists( 'WooCommerce' ) ) {
            return;
        }
        
        add_filter( 'woocommerce_add_to_cart_validation', [ $this, 'validate_add_to_cart' ], 10, 3 );
        add_action( 'woocommerce_check_cart_items', [ $this, 'validate_cart' ] );
        
        // Debug functionality disabled to prevent errors
        // Uncomment the lines below if you need to debug
        // add_action( 'wp_ajax_wprules_debug_product_visibility', [ $this, 'debug_product_visibility' ] );
        // add_action( 'template_redirect', [ $this, 'maybe_show_debug' ] );
    }
    
    /**
     * Check if debug should be shown (via GET parameter)
     */
    public function maybe_show_debug() {
        if ( ! isset( $_GET['wprules_debug'] ) || ! isset( $_GET['product_id'] ) ) {
            return;
        }
        
        if ( ! is_user_logged_in() || ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Unauthorized - You must be logged in as an administrator.' );
        }
        
        $this->debug_product_visibility();
        exit;
    }

    /**
     * Get all rules (cached)
     * @return array of rule objects
     */
    private function get_all_rules() {
        $cache = new WPRules_Cache();
        return $cache->get_all_rules();
    }

    /**
     * Get rules for specific product (cached)
     * @return array of rule objects
     */
    private function get_rules_for_product( $product_id ) {
        $cache = new WPRules_Cache();
        return $cache->get_rules_for_product( $product_id );
    }

    /**
     * Get custom error messages from settings
     */
    private function get_error_messages() {
        return get_option( 'wprules_error_messages', [
            'dependencies_message' => '%s cannot be added to cart.<br>You must first purchase %s of the following products:<br>%s',
            'restrictions_message' => '%s cannot be added to cart.<br>You already have the following conflicting products:<br>%s',
            'limit_message' => '%s cannot be added to cart. This product has a purchase limit of %d. You currently have %d in your cart.',
            'cart_violation_message' => 'Some products in your cart violate purchase rules. Please review your cart and adjust.',
        ]);
    }

    /**
     * Helpers
     */
    
    /**
     * Check if rule applies to current user's role
     * @param object $rule The rule object
     * @return bool True if rule applies to user, false otherwise
     */
    private function rule_applies_to_user( $rule ) {
        // If no user roles specified, rule applies to all users
        if ( empty( $rule->user_roles ) ) {
            return true;
        }

        $rule_roles = json_decode( $rule->user_roles, true );
        if ( ! is_array( $rule_roles ) || empty( $rule_roles ) ) {
            return true;
        }

        // Get current user's roles
        $user = wp_get_current_user();
        if ( ! $user || ! $user->ID ) {
            // Guest users - check if any rule allows guests (none by default)
            return false;
        }

        // Check WordPress roles
        $user_roles = $user->roles;
        if ( ! is_array( $user_roles ) ) {
            $user_roles = [];
        }

        // Also check user meta for role assignments (common in custom setups)
        $user_meta_roles = get_user_meta( $user->ID, 'user_role', true );
        if ( ! empty( $user_meta_roles ) ) {
            if ( is_array( $user_meta_roles ) ) {
                $user_roles = array_merge( $user_roles, $user_meta_roles );
            } else {
                $user_roles[] = $user_meta_roles;
            }
        }

        // Check for role in various meta fields (common patterns)
        $meta_fields_to_check = [ 'role', 'user_role', 'customer_role', 'ct_role', 'ma_role' ];
        foreach ( $meta_fields_to_check as $meta_field ) {
            $meta_value = get_user_meta( $user->ID, $meta_field, true );
            if ( ! empty( $meta_value ) ) {
                if ( is_array( $meta_value ) ) {
                    $user_roles = array_merge( $user_roles, $meta_value );
                } else {
                    $user_roles[] = $meta_value;
                }
            }
        }

        // Also check if user has the role as a capability
        foreach ( $rule_roles as $required_role ) {
            if ( $user->has_cap( $required_role ) ) {
                return true;
            }
        }

        // Remove duplicates and check if user has any of the required roles
        $user_roles = array_unique( $user_roles );
        
        // Allow filtering of user roles for custom implementations
        $user_roles = apply_filters( 'wprules_user_roles', $user_roles, $user->ID );
        
        // Normalize roles for comparison (case-insensitive, handle WordPress role naming conventions)
        $normalized_user_roles = array_map( 'strtolower', $user_roles );
        $normalized_rule_roles = array_map( 'strtolower', $rule_roles );
        
        // Check exact match first (case-sensitive)
        foreach ( $rule_roles as $required_role ) {
            if ( in_array( $required_role, $user_roles, true ) ) {
                return true;
            }
        }
        
        // Check case-insensitive match (WordPress roles are often lowercase)
        foreach ( $normalized_rule_roles as $normalized_required_role ) {
            if ( in_array( $normalized_required_role, $normalized_user_roles, true ) ) {
                return true;
            }
        }
        
        // Also check if role name might be stored differently (e.g., "ct-adult" vs "CT-Adult")
        // WordPress often stores roles in lowercase or with underscores
        foreach ( $rule_roles as $required_role ) {
            $role_variations = [
                strtolower( $required_role ),
                str_replace( '-', '_', strtolower( $required_role ) ),
                str_replace( '_', '-', strtolower( $required_role ) ),
            ];
            
            foreach ( $role_variations as $variation ) {
                if ( in_array( $variation, $normalized_user_roles, true ) ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if a product is visible to the current user based on Members Only plugin settings
     * @param int $product_id The product ID to check
     * @return bool True if product is visible to user, false if hidden
     */
    private function is_product_visible_to_user( $product_id ) {
        $product_id = intval( $product_id );
        
        if ( ! $product_id ) {
            return true; // Invalid product ID, default to visible
        }
        
        $user = wp_get_current_user();
        
        // If no user, check if product is visible to guests
        if ( ! $user || ! $user->ID ) {
            // Check if product is hidden from guests (no logged-in users)
            $hide_from_roles = $this->get_hidden_roles_for_product( $product_id );
            // If there are any hidden roles, assume guests might be restricted
            // But we'll be lenient - if no specific guest restriction, allow it
            return true;
        }
        
        // Get roles that this product is hidden from
        $hide_from_roles = $this->get_hidden_roles_for_product( $product_id );
        
        // If no restrictions, product is visible
        if ( empty( $hide_from_roles ) ) {
            return true;
        }
        
        // Get current user's roles
        $user_roles = $user->roles;
        if ( ! is_array( $user_roles ) ) {
            $user_roles = [];
        }
        
        // Also check user meta for custom role assignments
        $meta_fields_to_check = [ 
            'user_role', 
            'customer_role', 
            'ct_role', 
            'ma_role',
            'wp_capabilities', // WordPress capabilities
            'members_only_role', // Members Only plugin might store here
            'wc_members_only_role',
        ];
        foreach ( $meta_fields_to_check as $meta_field ) {
            $meta_value = get_user_meta( $user->ID, $meta_field, true );
            if ( ! empty( $meta_value ) ) {
                if ( is_array( $meta_value ) ) {
                    // For wp_capabilities, keys are the role names
                    if ( $meta_field === 'wp_capabilities' ) {
                        $user_roles = array_merge( $user_roles, array_keys( $meta_value ) );
                    } else {
                        $user_roles = array_merge( $user_roles, $meta_value );
                    }
                } else {
                    $user_roles[] = $meta_value;
                }
            }
        }
        
        // Check all user meta keys that might contain role information (fallback)
        // Look for meta keys containing "role" or "ct" or "ma"
        global $wpdb;
        $role_meta = $wpdb->get_results( $wpdb->prepare( "
            SELECT meta_key, meta_value 
            FROM {$wpdb->usermeta} 
            WHERE user_id = %d 
            AND (meta_key LIKE %s OR meta_key LIKE %s OR meta_key LIKE %s)
        ", $user->ID, '%role%', '%ct%', '%ma%' ) );
        
        foreach ( $role_meta as $meta ) {
            // Skip if we already checked this key
            if ( in_array( $meta->meta_key, $meta_fields_to_check, true ) ) {
                continue;
            }
            
            $value = maybe_unserialize( $meta->meta_value );
            if ( is_array( $value ) ) {
                $user_roles = array_merge( $user_roles, $value );
            } elseif ( is_string( $value ) && ! empty( $value ) ) {
                // Check if it looks like a role name (contains CT- or MA-, case-insensitive)
                if ( preg_match( '/^(ct|ma|CT|MA)-/i', $value ) ) {
                    $user_roles[] = $value;
                }
            }
        }
        
        $user_roles = array_unique( $user_roles );
        
        // Normalize for comparison (case-insensitive)
        $normalized_user_roles = array_map( 'strtolower', $user_roles );
        $normalized_hide_roles = array_map( 'strtolower', $hide_from_roles );
        
        // Check if user has any role that the product is hidden from
        // First try normalized (case-insensitive) comparison
        foreach ( $normalized_hide_roles as $hidden_role ) {
            if ( in_array( $hidden_role, $normalized_user_roles, true ) ) {
                return false; // User has a role that's hidden from this product
            }
        }
        
        // Also check exact matches (case-sensitive)
        foreach ( $hide_from_roles as $hidden_role ) {
            if ( in_array( $hidden_role, $user_roles, true ) ) {
                return false;
            }
        }
        
        // Product is visible to this user
        return true;
    }
    
    /**
     * Get the list of user roles that a product is hidden from (Members Only plugin)
     * @param int $product_id The product ID
     * @return array Array of role names
     */
    private function get_hidden_roles_for_product( $product_id ) {
        $product_id = intval( $product_id );
        $hidden_roles = [];
        
        // First, try to use the Members Only plugin's own functions if available
        if ( function_exists( 'wc_members_only_get_hide_from_roles' ) ) {
            $plugin_roles = wc_members_only_get_hide_from_roles( $product_id );
            if ( ! empty( $plugin_roles ) ) {
                if ( is_array( $plugin_roles ) ) {
                    $hidden_roles = array_merge( $hidden_roles, $plugin_roles );
                } else {
                    $hidden_roles[] = $plugin_roles;
                }
            }
        }
        
        // Check if there's a filter from the Members Only plugin
        $filtered_roles = apply_filters( 'wc_members_only_hide_from_roles', [], $product_id );
        if ( ! empty( $filtered_roles ) && is_array( $filtered_roles ) ) {
            $hidden_roles = array_merge( $hidden_roles, $filtered_roles );
        }
        
        // Check common meta keys used by Members Only plugins
        $meta_keys_to_check = [
            'wcmo_hide_from_user_roles', // WooCommerce Members Only - confirmed meta key
            '_wcmo_hide_from_user_roles', // With underscore prefix (common WordPress pattern)
            '_members_only_hide_from_roles',
            '_wc_members_only_hide_from_roles',
            '_hide_from_roles',
            '_wc_members_only_restricted_roles',
            'members_only_hide_from_roles',
            '_wcmo_hide_from_roles', // WooCommerce Members Only common abbreviation
            '_wc_mo_hide_from_roles',
            'wc_members_only_hide_from_roles',
            '_members_only_restricted_roles',
        ];
        
        foreach ( $meta_keys_to_check as $meta_key ) {
            $value = get_post_meta( $product_id, $meta_key, true );
            if ( ! empty( $value ) ) {
                // First, try to unserialize if it's a PHP serialized string
                $unserialized = maybe_unserialize( $value );
                if ( $unserialized !== $value ) {
                    // Value was unserialized, use the unserialized version
                    $value = $unserialized;
                }
                
                if ( is_array( $value ) ) {
                    $hidden_roles = array_merge( $hidden_roles, $value );
                } elseif ( is_string( $value ) ) {
                    // Try to decode if it's JSON
                    $decoded = json_decode( $value, true );
                    if ( is_array( $decoded ) ) {
                        $hidden_roles = array_merge( $hidden_roles, $decoded );
                    } else {
                        // Might be comma-separated
                        $hidden_roles = array_merge( $hidden_roles, array_map( 'trim', explode( ',', $value ) ) );
                    }
                } elseif ( is_numeric( $value ) ) {
                    // Some plugins store role IDs instead of names
                    $role_obj = get_role_by( 'id', $value );
                    if ( $role_obj ) {
                        $hidden_roles[] = $role_obj->name;
                    }
                }
            }
        }
        
        // Check all post meta that might contain "hide" or "role" in the key name (for debugging/fallback)
        // This is a last resort to find the data
        if ( empty( $hidden_roles ) ) {
            global $wpdb;
            if ( $wpdb ) {
                $all_meta = $wpdb->get_results( $wpdb->prepare( "
                    SELECT meta_key, meta_value 
                    FROM {$wpdb->postmeta} 
                    WHERE post_id = %d 
                    AND (meta_key LIKE %s OR meta_key LIKE %s OR meta_key LIKE %s)
                ", $product_id, '%hide%', '%role%', '%members%' ) );
                
                if ( is_array( $all_meta ) ) {
                    foreach ( $all_meta as $meta ) {
                        // Check if key contains both "hide" and "role", or just "hide" with "from"
                        if ( ( stripos( $meta->meta_key, 'hide' ) !== false && stripos( $meta->meta_key, 'role' ) !== false ) ||
                             ( stripos( $meta->meta_key, 'hide' ) !== false && stripos( $meta->meta_key, 'from' ) !== false ) ) {
                            $value = maybe_unserialize( $meta->meta_value );
                            if ( is_array( $value ) ) {
                                $hidden_roles = array_merge( $hidden_roles, $value );
                            } elseif ( is_string( $value ) ) {
                                $decoded = json_decode( $value, true );
                                if ( is_array( $decoded ) ) {
                                    $hidden_roles = array_merge( $hidden_roles, $decoded );
                                } else {
                                    $hidden_roles = array_merge( $hidden_roles, array_map( 'trim', explode( ',', $value ) ) );
                                }
                            }
                        }
                    }
                }
            }
        }
        
        // Also check if there's a filter/hook from Members Only plugin
        $hidden_roles = apply_filters( 'wprules_get_hidden_roles_for_product', $hidden_roles, $product_id );
        
        // Remove empty values and duplicates
        $hidden_roles = array_filter( array_unique( $hidden_roles ) );
        
        return array_values( $hidden_roles );
    }

    /**
     * Generate clickable product link with thumbnail and title (no ID)
     */
    private function get_product_link( $product_id ) {
        $product_id = intval( $product_id );
        $product_title = get_the_title( $product_id );
        $product_url = get_permalink( $product_id );
        
        // Get product thumbnail
        $thumbnail_id = get_post_thumbnail_id( $product_id );
        $thumbnail = '';
        if ( $thumbnail_id ) {
            $thumbnail_url = wp_get_attachment_image_src( $thumbnail_id, 'thumbnail' );
            if ( $thumbnail_url ) {
                $thumbnail = sprintf(
                    '<img src="%s" alt="%s" class="wprules-product-thumbnail" />',
                    esc_url( $thumbnail_url[0] ),
                    esc_attr( $product_title )
                );
            }
        }
        
        // If no thumbnail, use a placeholder or just the text
        if ( empty( $thumbnail ) ) {
            $thumbnail = '<span class="wprules-product-thumbnail-placeholder"></span>';
        }
        
        if ( $product_url ) {
            return sprintf( 
                '<a href="%s" target="_blank" rel="noopener" class="wprules-product-link">%s<span class="wprules-product-title">%s</span></a>', 
                esc_url( $product_url ), 
                $thumbnail,
                esc_html( $product_title )
            );
        } else {
            // Fallback to plain text if no permalink available
            return sprintf(
                '<span class="wprules-product-link">%s<span class="wprules-product-title">%s</span></span>',
                $thumbnail,
                esc_html( $product_title )
            );
        }
    }

    private function cart_product_ids() {
        $ids = [];
        foreach ( WC()->cart->get_cart() as $item ) {
            $ids[ intval( $item['product_id'] ) ] = $item['quantity'];
        }
        return $ids;
    }

    private function in_cart_any( array $targets ) {
        foreach ( WC()->cart->get_cart() as $item ) {
            if ( in_array( intval( $item['product_id'] ), $targets, true ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if any of the target products are in cart, excluding items that are
     * children (bundled/composite) of the given parent product ID.
     */
    private function in_cart_any_excluding_children( array $targets, $parent_product_id ) {
        $parent_product_id = intval( $parent_product_id );
        $cart = WC()->cart ? WC()->cart->get_cart() : [];

        foreach ( $cart as $cart_item_key => $item ) {
            $item_product_id = intval( $item['product_id'] );
            if ( ! in_array( $item_product_id, $targets, true ) ) {
                continue;
            }

            if ( $this->is_child_of_parent_product( $cart, $cart_item_key, $parent_product_id ) ) {
                // Ignore child of the same parent product being validated
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * Determine if the given cart item is a child (bundled/composite) of a cart line
     * whose product_id matches the provided parent product ID.
     */
    private function is_child_of_parent_product( array $cart, $cart_item_key, $parent_product_id ) {
        if ( isset( $cart[ $cart_item_key ]['bundled_by'] ) ) {
            $parent_key = $cart[ $cart_item_key ]['bundled_by'];
            if ( isset( $cart[ $parent_key ] ) ) {
                return intval( $cart[ $parent_key ]['product_id'] ) === intval( $parent_product_id );
            }
        }

        if ( isset( $cart[ $cart_item_key ]['composite_parent'] ) ) {
            $parent_key = $cart[ $cart_item_key ]['composite_parent'];
            if ( isset( $cart[ $parent_key ] ) ) {
                return intval( $cart[ $parent_key ]['product_id'] ) === intval( $parent_product_id );
            }
        }

        return false;
    }

    /**
     * Get bundled product IDs from a bundle product
     * Supports WooCommerce Product Bundles and other common bundle plugins
     * @param int $product_id The bundle product ID
     * @return array Array of bundled product IDs
     */
    private function get_bundled_product_ids( $product_id ) {
        $product_id = intval( $product_id );
        $bundled_ids = [];
        
        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            return $bundled_ids;
        }

        // Check for WooCommerce Product Bundles plugin
        if ( class_exists( 'WC_Product_Bundle' ) && $product->is_type( 'bundle' ) ) {
            $bundled_items = $product->get_bundled_items();
            if ( $bundled_items ) {
                foreach ( $bundled_items as $bundled_item ) {
                    $bundled_product_id = $bundled_item->get_product_id();
                    if ( $bundled_product_id ) {
                        $bundled_ids[] = intval( $bundled_product_id );
                    }
                }
            }
        }
        // Check for YITH WooCommerce Product Bundles
        elseif ( class_exists( 'YITH_WCPB_Product_Bundle' ) && $product->is_type( 'yith_bundle' ) ) {
            $bundled_items = $product->get_bundled_items();
            if ( $bundled_items ) {
                foreach ( $bundled_items as $bundled_item ) {
                    $bundled_product_id = isset( $bundled_item['product_id'] ) ? $bundled_item['product_id'] : ( isset( $bundled_item->product_id ) ? $bundled_item->product_id : null );
                    if ( $bundled_product_id ) {
                        $bundled_ids[] = intval( $bundled_product_id );
                    }
                }
            }
        }
        // Generic check using product meta (for custom bundle solutions)
        else {
            // Check if product has bundled items stored in meta
            $bundled_items = get_post_meta( $product_id, '_bundled_items', true );
            if ( is_array( $bundled_items ) ) {
                foreach ( $bundled_items as $item ) {
                    $bundled_product_id = isset( $item['product_id'] ) ? $item['product_id'] : ( isset( $item->product_id ) ? $item->product_id : null );
                    if ( $bundled_product_id ) {
                        $bundled_ids[] = intval( $bundled_product_id );
                    }
                }
            }
            
            // Also check for _bundle_data meta (another common format)
            if ( empty( $bundled_ids ) ) {
                $bundle_data = get_post_meta( $product_id, '_bundle_data', true );
                if ( is_array( $bundle_data ) ) {
                    foreach ( $bundle_data as $item_id => $item_data ) {
                        $bundled_product_id = isset( $item_data['product_id'] ) ? $item_data['product_id'] : intval( $item_id );
                        if ( $bundled_product_id ) {
                            $bundled_ids[] = intval( $bundled_product_id );
                        }
                    }
                }
            }
        }

        return array_unique( $bundled_ids );
    }

    /**
     * Get the parent bundle product ID if the given product is a bundled item in the cart
     * @param int $product_id The product ID to check
     * @return int|null The parent bundle product ID, or null if not found
     */
    private function get_parent_bundle_id_from_cart( $product_id ) {
        $product_id = intval( $product_id );
        $cart = WC()->cart ? WC()->cart->get_cart() : [];

        // First, check if this product is in the cart as a bundled item (has bundled_by key)
        foreach ( $cart as $cart_item_key => $item ) {
            $item_product_id = intval( $item['product_id'] );
            
            // Check if this cart item is the product we're looking for and it's a bundled item
            if ( $item_product_id === $product_id ) {
                // Check if this item is bundled by another product
                if ( isset( $item['bundled_by'] ) ) {
                    $parent_key = $item['bundled_by'];
                    if ( isset( $cart[ $parent_key ] ) ) {
                        return intval( $cart[ $parent_key ]['product_id'] );
                    }
                }
                
                // Check for composite parent
                if ( isset( $item['composite_parent'] ) ) {
                    $parent_key = $item['composite_parent'];
                    if ( isset( $cart[ $parent_key ] ) ) {
                        return intval( $cart[ $parent_key ]['product_id'] );
                    }
                }
            }
        }

        // Second, check all bundles in the cart to see if any contain this product
        foreach ( $cart as $cart_item_key => $item ) {
            $item_product_id = intval( $item['product_id'] );
            
            // Skip if this item is itself a bundled item
            if ( isset( $item['bundled_by'] ) || isset( $item['composite_parent'] ) ) {
                continue;
            }
            
            // Check if this cart item is a bundle that contains our product
            $bundled_ids = $this->get_bundled_product_ids( $item_product_id );
            if ( ! empty( $bundled_ids ) && in_array( $product_id, $bundled_ids, true ) ) {
                return $item_product_id;
            }
        }

        return null;
    }

    /**
     * Get all product IDs that are in the same bundle as the given product
     * @param int $product_id The product ID to check
     * @return array Array of product IDs in the same bundle (excluding the product itself)
     */
    private function get_sibling_bundled_products( $product_id ) {
        $product_id = intval( $product_id );
        $sibling_ids = [];
        
        $parent_bundle_id = $this->get_parent_bundle_id_from_cart( $product_id );
        if ( $parent_bundle_id === null ) {
            return $sibling_ids;
        }
        
        // Get all bundled products in the parent bundle
        $all_bundled_ids = $this->get_bundled_product_ids( $parent_bundle_id );
        
        // Return all bundled products except the current one
        foreach ( $all_bundled_ids as $bundled_id ) {
            if ( $bundled_id !== $product_id ) {
                $sibling_ids[] = $bundled_id;
            }
        }
        
        return $sibling_ids;
    }

    private function cart_has_product_qty( $product_id ) {
        $qty = 0;
        foreach ( WC()->cart->get_cart() as $item ) {
            if ( intval( $item['product_id'] ) === intval( $product_id ) ) {
                $qty += intval( $item['quantity'] );
            }
        }
        return $qty;
    }

    /**
     * Validate on add to cart
     */
    public function validate_add_to_cart( $passed, $product_id, $quantity ) {
        if ( ! $passed ) return false;

        $user_id = get_current_user_id();
        $user = wp_get_current_user();
        $user_email = $user ? $user->user_email : '';

        $product_id = intval( $product_id );
        $rules = $this->get_rules_for_product( $product_id );
        if ( empty( $rules ) ) return $passed;

        foreach ( $rules as $rule ) {
            // decode arrays
            $product_ids = json_decode( $rule->product_ids, true );
            if ( ! is_array( $product_ids ) ) continue;

            // skip if current product not included in rule (double-check for safety)
            if ( ! in_array( $product_id, array_map('intval', $product_ids), true ) ) {
                continue;
            }

            // skip if rule doesn't apply to current user's role
            if ( ! $this->rule_applies_to_user( $rule ) ) {
                continue;
            }

            $target_ids = $rule->target_ids ? json_decode( $rule->target_ids, true ) : [];
            if ( ! is_array( $target_ids ) ) $target_ids = [];

            // --- DEPENDENCIES: respect ALL vs ANY ---
            if ( $rule->rule_type === 'dependencies' ) {
                // Get sibling products in the same bundle (if this is a bundled product)
                $sibling_bundled_products = $this->get_sibling_bundled_products( $product_id );
                
                $missing = [];

                if ( isset( $rule->match_type ) && $rule->match_type === 'any' ) {
                    // ANY target suffices
                    $found = false;
                    foreach ( $target_ids as $tid ) {
                        $tid = intval( $tid );
                        $in_cart = $this->in_cart_any( [ $tid ] );
                        $bought = $user_id ? wc_customer_bought_product( $user_email, $user_id, $tid ) : false;
                        if ( $bought === null ) $bought = false; // Handle null return
                        
                        // Also check if the dependency is satisfied by a sibling product in the same bundle
                        $in_bundle = ! empty( $sibling_bundled_products ) && in_array( $tid, $sibling_bundled_products, true );

                        if ( $in_cart || $bought || $in_bundle ) {
                            $found = true;
                            break;
                        }
                    }
                    if ( ! $found ) {
                        foreach ( $target_ids as $tid ) {
                            $tid = intval( $tid );
                            // Only include products that are visible to the current user
                            if ( $this->is_product_visible_to_user( $tid ) ) {
                                $missing[] = $this->get_product_link( $tid );
                            }
                        }
                    }
                } else {
                    // ALL targets required
                    foreach ( $target_ids as $tid ) {
                        $tid = intval( $tid );
                        $in_cart = $this->in_cart_any( [ $tid ] );
                        $bought = $user_id ? wc_customer_bought_product( $user_email, $user_id, $tid ) : false;
                        if ( $bought === null ) $bought = false; // Handle null return
                        
                        // Also check if the dependency is satisfied by a sibling product in the same bundle
                        $in_bundle = ! empty( $sibling_bundled_products ) && in_array( $tid, $sibling_bundled_products, true );

                        if ( ! $in_cart && ! $bought && ! $in_bundle ) {
                            // Only include products that are visible to the current user
                            if ( $this->is_product_visible_to_user( $tid ) ) {
                                $missing[] = $this->get_product_link( $tid );
                            }
                        }
                    }
                }

                if ( ! empty( $missing ) ) {
                    $messages = $this->get_error_messages();
                    $match_text = ( isset($rule->match_type) && $rule->match_type === 'all' ) ? 'all' : 'at least one';
                    $products_list = '<div class="wprules-product-links">' . implode( '', array_map( function( $link ) {
                        return '<div class="wprules-product-item">' . $link . '</div>';
                    }, $missing ) ) . '</div>';
                    wc_add_notice( sprintf(
                        $messages['dependencies_message'],
                        '<strong>' . esc_html( get_the_title( $product_id ) ) . '</strong>',
                        $match_text,
                        $products_list
                    ), 'error', [ 'is_html' => true ] );
                    return false;
                }
            }

            // --- RESTRICTIONS ---
            if ( $rule->rule_type === 'restrictions' ) {
                // Get bundled product IDs if this is a bundle product
                $bundled_product_ids = $this->get_bundled_product_ids( $product_id );
                
                // Check if this product is a bundled item in the cart and get its parent bundle
                $parent_bundle_id = $this->get_parent_bundle_id_from_cart( $product_id );
                
                $found = [];
                foreach ( $target_ids as $tid ) {
                    $tid = intval( $tid );
                    
                    // Skip restriction if the target product is part of this bundle
                    // This allows bundles to contain products that would normally be restricted
                    if ( ! empty( $bundled_product_ids ) && in_array( $tid, $bundled_product_ids, true ) ) {
                        continue;
                    }
                    
                    // Skip restriction if this product is a bundled item and the target is its parent bundle
                    // This allows bundled products to exist within their parent bundle
                    if ( $parent_bundle_id !== null && $tid === $parent_bundle_id ) {
                        continue;
                    }
                    
                    $in_cart = $this->in_cart_any_excluding_children( [ $tid ], $product_id );
                    $bought = $user_id ? wc_customer_bought_product( $user_email, $user_id, $tid ) : false;
                    if ( $bought === null ) $bought = false; // Handle null return

                    if ( $in_cart || $bought ) {
                        // Only include products that are visible to the current user
                        if ( $this->is_product_visible_to_user( $tid ) ) {
                            $found[] = $this->get_product_link( $tid );
                        }
                    }
                }

                if ( ! empty( $found ) ) {
                    $messages = $this->get_error_messages();
                    $products_list = '<div class="wprules-product-links">' . implode( '', array_map( function( $link ) {
                        return '<div class="wprules-product-item">' . $link . '</div>';
                    }, $found ) ) . '</div>';
                    wc_add_notice( sprintf(
                        $messages['restrictions_message'],
                        '<strong>' . esc_html( get_the_title( $product_id ) ) . '</strong>',
                        $products_list
                    ), 'error', [ 'is_html' => true ] );
                    return false;
                }
            }

            // --- LIMIT ---
            if ( $rule->rule_type === 'limit' && $rule->limit_qty ) {
                $current_in_cart = $this->cart_product_ids();
                $current_qty = isset( $current_in_cart[ $product_id ] ) ? intval( $current_in_cart[ $product_id ] ) : 0;
                $new_total = $current_qty + intval( $quantity );

                if ( $new_total > intval( $rule->limit_qty ) ) {
                    $messages = $this->get_error_messages();
                    wc_add_notice( sprintf(
                        $messages['limit_message'],
                        '<strong>' . esc_html( get_the_title( $product_id ) ) . '</strong>',
                        intval( $rule->limit_qty ),
                        $current_qty
                    ), 'error', [ 'is_html' => true ] );
                    return false;
                }
            }
        }

        return $passed;
    }

    /**
     * Validate the entire cart (catch edits/adds done directly in cart)
     */
    public function validate_cart() {
        if ( ! WC()->cart ) return;

        foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
            $product_id = intval( $cart_item['product_id'] );
            $quantity = intval( $cart_item['quantity'] );

            // Validate current cart quantities (not adding new ones)
            if ( ! $this->validate_cart_item( $product_id, $quantity ) ) {
                $messages = $this->get_error_messages();
                wc_add_notice( $messages['cart_violation_message'], 'error', [ 'is_html' => true ] );
                // Don't break — show the message and let user adjust
                return;
            }
        }
    }

    /**
     * Validate a single cart item (for existing cart validation)
     */
    private function validate_cart_item( $product_id, $quantity ) {
        $rules = $this->get_rules_for_product( $product_id );

        foreach ( $rules as $rule ) {
            $product_ids = json_decode( $rule->product_ids, true );
            if ( ! in_array( $product_id, $product_ids ) ) continue;

            // skip if rule doesn't apply to current user's role
            if ( ! $this->rule_applies_to_user( $rule ) ) {
                continue;
            }

            // --- DEPENDENCIES: respect ALL vs ANY ---
            if ( $rule->rule_type === 'dependencies' ) {
                $user_id = get_current_user_id();
                $user_email = $user_id ? get_userdata( $user_id )->user_email : '';
                $target_ids = $rule->target_ids ? json_decode( $rule->target_ids, true ) : [];
                if ( ! is_array( $target_ids ) ) $target_ids = [];

                // Get sibling products in the same bundle (if this is a bundled product)
                $sibling_bundled_products = $this->get_sibling_bundled_products( $product_id );

                $missing = [];

                if ( isset( $rule->match_type ) && $rule->match_type === 'any' ) {
                    // ANY target suffices
                    $found = false;
                    foreach ( $target_ids as $tid ) {
                        $tid = intval( $tid );
                        $in_cart = $this->in_cart_any( [ $tid ] );
                        $bought = $user_id ? wc_customer_bought_product( $user_email, $user_id, $tid ) : false;
                        if ( $bought === null ) $bought = false; // Handle null return
                        
                        // Also check if the dependency is satisfied by a sibling product in the same bundle
                        $in_bundle = ! empty( $sibling_bundled_products ) && in_array( $tid, $sibling_bundled_products, true );

                        if ( $in_cart || $bought || $in_bundle ) {
                            $found = true;
                            break;
                        }
                    }
                    if ( ! $found ) {
                        foreach ( $target_ids as $tid ) {
                            $tid = intval( $tid );
                            // Only include products that are visible to the current user
                            if ( $this->is_product_visible_to_user( $tid ) ) {
                                $missing[] = $this->get_product_link( $tid );
                            }
                        }
                    }
                } else {
                    // ALL targets required
                    foreach ( $target_ids as $tid ) {
                        $tid = intval( $tid );
                        $in_cart = $this->in_cart_any( [ $tid ] );
                        $bought = $user_id ? wc_customer_bought_product( $user_email, $user_id, $tid ) : false;
                        if ( $bought === null ) $bought = false; // Handle null return
                        
                        // Also check if the dependency is satisfied by a sibling product in the same bundle
                        $in_bundle = ! empty( $sibling_bundled_products ) && in_array( $tid, $sibling_bundled_products, true );

                        if ( ! $in_cart && ! $bought && ! $in_bundle ) {
                            // Only include products that are visible to the current user
                            if ( $this->is_product_visible_to_user( $tid ) ) {
                                $missing[] = $this->get_product_link( $tid );
                            }
                        }
                    }
                }

                if ( ! empty( $missing ) ) {
                    $messages = $this->get_error_messages();
                    $match_text = ( isset($rule->match_type) && $rule->match_type === 'all' ) ? 'all' : 'at least one';
                    $products_list = '<div class="wprules-product-links">' . implode( '', array_map( function( $link ) {
                        return '<div class="wprules-product-item">' . $link . '</div>';
                    }, $missing ) ) . '</div>';
                    wc_add_notice( sprintf(
                        $messages['dependencies_message'],
                        '<strong>' . esc_html( get_the_title( $product_id ) ) . '</strong>',
                        $match_text,
                        $products_list
                    ), 'error', [ 'is_html' => true ] );
                    return false;
                }
            }

            // --- RESTRICTIONS ---
            if ( $rule->rule_type === 'restrictions' ) {
                $user_id = get_current_user_id();
                $user_email = $user_id ? get_userdata( $user_id )->user_email : '';
                $target_ids = $rule->target_ids ? json_decode( $rule->target_ids, true ) : [];
                if ( ! is_array( $target_ids ) ) $target_ids = [];

                // Get bundled product IDs if this is a bundle product
                $bundled_product_ids = $this->get_bundled_product_ids( $product_id );
                
                // Check if this product is a bundled item in the cart and get its parent bundle
                $parent_bundle_id = $this->get_parent_bundle_id_from_cart( $product_id );

                $found = [];
                foreach ( $target_ids as $tid ) {
                    $tid = intval( $tid );
                    
                    // Skip restriction if the target product is part of this bundle
                    // This allows bundles to contain products that would normally be restricted
                    if ( ! empty( $bundled_product_ids ) && in_array( $tid, $bundled_product_ids, true ) ) {
                        continue;
                    }
                    
                    // Skip restriction if this product is a bundled item and the target is its parent bundle
                    // This allows bundled products to exist within their parent bundle
                    if ( $parent_bundle_id !== null && $tid === $parent_bundle_id ) {
                        continue;
                    }
                    
                    $in_cart = $this->in_cart_any_excluding_children( [ $tid ], $product_id );
                    $bought = $user_id ? wc_customer_bought_product( $user_email, $user_id, $tid ) : false;
                    if ( $bought === null ) $bought = false; // Handle null return

                    if ( $in_cart || $bought ) {
                        // Only include products that are visible to the current user
                        if ( $this->is_product_visible_to_user( $tid ) ) {
                            $found[] = $this->get_product_link( $tid );
                        }
                    }
                }

                if ( ! empty( $found ) ) {
                    $messages = $this->get_error_messages();
                    $products_list = '<div class="wprules-product-links">' . implode( '', array_map( function( $link ) {
                        return '<div class="wprules-product-item">' . $link . '</div>';
                    }, $found ) ) . '</div>';
                    wc_add_notice( sprintf(
                        $messages['restrictions_message'],
                        '<strong>' . esc_html( get_the_title( $product_id ) ) . '</strong>',
                        $products_list
                    ), 'error', [ 'is_html' => true ] );
                    return false;
                }
            }

            // --- LIMIT ---
            if ( $rule->rule_type === 'limit' && $rule->limit_qty ) {
                if ( $quantity > intval( $rule->limit_qty ) ) {
                    $messages = $this->get_error_messages();
                    wc_add_notice( sprintf(
                        $messages['limit_message'],
                        '<strong>' . esc_html( get_the_title( $product_id ) ) . '</strong>',
                        intval( $rule->limit_qty ),
                        $quantity
                    ), 'error', [ 'is_html' => true ] );
                    return false;
                }
            }
        }

        return true;
    }
    
    /**
     * Debug function to check product visibility (for troubleshooting)
     * Access via: ?wprules_debug=1&product_id=123 (add to any page URL)
     * Or via AJAX: /wp-admin/admin-ajax.php?action=wprules_debug_product_visibility&product_id=123
     */
    public function debug_product_visibility() {
        try {
            // Get product ID
            $product_id = 0;
            if ( isset( $_GET['product_id'] ) ) {
                $product_id = intval( $_GET['product_id'] );
            } elseif ( isset( $_REQUEST['product_id'] ) ) {
                $product_id = intval( $_REQUEST['product_id'] );
            }
            
            if ( ! $product_id ) {
                if ( wp_doing_ajax() ) {
                    wp_send_json_error( [ 'message' => 'Product ID required' ] );
                } else {
                    wp_die( 'Product ID required. Add ?wprules_debug=1&product_id=YOUR_PRODUCT_ID to any page URL.' );
                }
                return;
            }
            
            // Check if product exists
            if ( ! get_post( $product_id ) ) {
                if ( wp_doing_ajax() ) {
                    wp_send_json_error( [ 'message' => 'Product not found' ] );
                } else {
                    wp_die( 'Product not found.' );
                }
                return;
            }
            
            $user = wp_get_current_user();
            if ( ! $user || ! $user->ID ) {
                if ( wp_doing_ajax() ) {
                    wp_send_json_error( [ 'message' => 'User not logged in' ] );
                } else {
                    wp_die( 'User not logged in.' );
                }
                return;
            }
            
            $hidden_roles = $this->get_hidden_roles_for_product( $product_id );
            
            // Get all post meta for this product
            global $wpdb;
            $all_meta = [];
            if ( $wpdb ) {
                $all_meta = $wpdb->get_results( $wpdb->prepare( "
                    SELECT meta_key, meta_value 
                    FROM {$wpdb->postmeta} 
                    WHERE post_id = %d
                    ORDER BY meta_key
                ", $product_id ) );
                if ( ! is_array( $all_meta ) ) {
                    $all_meta = [];
                }
            }
            
            $user_roles = [];
            if ( $user && isset( $user->roles ) && is_array( $user->roles ) ) {
                $user_roles = $user->roles;
            }
            
            $meta_fields_to_check = [ 'user_role', 'customer_role', 'ct_role', 'ma_role' ];
            foreach ( $meta_fields_to_check as $meta_field ) {
                $meta_value = get_user_meta( $user->ID, $meta_field, true );
                if ( ! empty( $meta_value ) ) {
                    if ( is_array( $meta_value ) ) {
                        $user_roles = array_merge( $user_roles, $meta_value );
                    } else {
                        $user_roles[] = $meta_value;
                    }
                }
            }
            $user_roles = array_unique( $user_roles );
            
            // Get all user meta that might contain role info
            $all_user_meta = [];
            if ( $wpdb ) {
                $all_user_meta = $wpdb->get_results( $wpdb->prepare( "
                    SELECT meta_key, meta_value 
                    FROM {$wpdb->usermeta} 
                    WHERE user_id = %d
                    AND (meta_key LIKE %s OR meta_key LIKE %s OR meta_key LIKE %s)
                    ORDER BY meta_key
                ", $user->ID, '%role%', '%ct%', '%ma%' ) );
                if ( ! is_array( $all_user_meta ) ) {
                    $all_user_meta = [];
                }
            }
            
            $is_visible = $this->is_product_visible_to_user( $product_id );
            
            $product_title = get_the_title( $product_id );
            if ( empty( $product_title ) ) {
                $product_title = '(No title)';
            }
            
            $output = '<html><head><title>WPRules Debug</title><style>body{font-family:monospace;padding:20px;background:#f5f5f5;}pre{background:#fff;padding:15px;border:1px solid #ddd;overflow:auto;}</style></head><body>';
            $output .= '<h1>WPRules Product Visibility Debug</h1>';
            $output .= '<pre>';
            $output .= "Product ID: {$product_id}\n";
            $output .= "Product Title: " . esc_html( $product_title ) . "\n\n";
            $output .= "=== HIDDEN ROLES FOR PRODUCT ===\n";
            $output .= print_r( $hidden_roles, true ) . "\n\n";
            $output .= "=== CURRENT USER INFO ===\n";
            $output .= "User ID: " . ( $user->ID ?? 'N/A' ) . "\n";
            $output .= "User Login: " . ( $user->user_login ?? 'N/A' ) . "\n";
            $output .= "User Email: " . ( $user->user_email ?? 'N/A' ) . "\n";
            $output .= "WordPress Roles: " . print_r( $user->roles ?? [], true ) . "\n";
            $output .= "All Detected User Roles: " . print_r( $user_roles, true ) . "\n\n";
            $output .= "=== USER META (Role Related) ===\n";
            if ( ! empty( $all_user_meta ) ) {
                foreach ( $all_user_meta as $meta ) {
                    $value = maybe_unserialize( $meta->meta_value );
                    $output .= "  " . esc_html( $meta->meta_key ) . ": " . print_r( $value, true ) . "\n";
                }
            } else {
                $output .= "  (No relevant user meta found)\n";
            }
            $output .= "\n=== PRODUCT POST META (Relevant Keys) ===\n";
            $found_relevant = false;
            foreach ( $all_meta as $meta ) {
                if ( stripos( $meta->meta_key, 'hide' ) !== false || 
                     stripos( $meta->meta_key, 'role' ) !== false || 
                     stripos( $meta->meta_key, 'members' ) !== false ||
                     stripos( $meta->meta_key, 'wc_mo' ) !== false ||
                     stripos( $meta->meta_key, 'wcmo' ) !== false ) {
                    $found_relevant = true;
                    $value = maybe_unserialize( $meta->meta_value );
                    $output .= "  " . esc_html( $meta->meta_key ) . ": " . print_r( $value, true ) . "\n";
                }
            }
            if ( ! $found_relevant ) {
                $output .= "  (No relevant meta keys found - showing ALL meta keys)\n";
                foreach ( $all_meta as $meta ) {
                    $value = maybe_unserialize( $meta->meta_value );
                    $output .= "  " . esc_html( $meta->meta_key ) . ": " . print_r( $value, true ) . "\n";
                }
            }
            $output .= "\n=== RESULT ===\n";
            $output .= "Is Product Visible to Current User: " . ( $is_visible ? 'YES ✓' : 'NO ✗' ) . "\n";
            $output .= '</pre>';
            $output .= '</body></html>';
            
            if ( wp_doing_ajax() ) {
                wp_send_json_success( [ 'output' => $output ] );
            } else {
                echo $output;
                wp_die();
            }
        } catch ( Exception $e ) {
            if ( wp_doing_ajax() ) {
                wp_send_json_error( [ 'message' => 'Error: ' . $e->getMessage() ] );
            } else {
                wp_die( 'Error: ' . esc_html( $e->getMessage() ) );
            }
        }
    }
}