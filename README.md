# WooCommerce Product Rules

A powerful WordPress plugin that adds advanced product dependency, restriction, and purchase limit rules for WooCommerce stores. Control which products can be purchased together, require prerequisite products, and set quantity limits with flexible matching options.

## Features

### 🎯 Rule Types

- **Dependencies**: Require customers to purchase specific products before adding others to cart
  - Support for "ALL" or "ANY" matching (require all dependencies or just one)
  - Checks both cart contents and purchase history
  - Works seamlessly with product bundles

- **Restrictions**: Prevent products from being added if conflicting products are already in cart or previously purchased
  - Smart bundle detection (allows bundled products to coexist)
  - Respects product visibility settings

- **Purchase Limits**: Set maximum quantity limits per product

### 👥 User Role Support

- Apply rules to specific user roles
- Rules can apply to all users or be role-specific
- Flexible role detection from WordPress roles, user meta, and custom fields

### 🚀 Performance

- Built-in caching system reduces database queries by 90%+
- Automatic cache invalidation on rule changes
- Cache management interface with statistics

### 📊 Admin Features

- **Intuitive Interface**: Clean admin panel under WooCommerce menu
- **Inline Editing**: Edit rules directly from the rules table
- **Bulk Actions**: Delete multiple rules at once
- **Pagination & Sorting**: Handle large rule sets efficiently
- **Product Search**: Search products by ID, SKU, or title with Select2
- **Custom Error Messages**: Customize error messages for each rule type
- **Import/Export**: CSV import/export for backup and migration
  - Export includes product titles for easy editing
  - Import supports multiple formats (ID, ID - Title, or Title search)

### 🛒 Cart Validation

- Validates on add-to-cart
- Validates entire cart on checkout
- Clear, user-friendly error messages with product links
- Respects product visibility (Members Only plugin integration)

### 📦 Bundle Support

- Works with WooCommerce Product Bundles
- Supports YITH WooCommerce Product Bundles
- Handles composite products
- Smart dependency checking within bundles

## Requirements

- WordPress 5.0+
- WooCommerce 3.0+
- PHP 7.2+

## Installation

1. Upload the plugin files to `/wp-content/plugins/woocommerce-product-rules/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Navigate to **WooCommerce > Product Rules** to start creating rules

## Usage

### Creating a Dependency Rule

1. Go to **WooCommerce > Product Rules**
2. Select the product(s) that require dependencies
3. Choose "Dependencies" as the rule type
4. Select the target products that must be purchased first
5. Choose "Any" (at least one) or "All" (all required)
6. Optionally select specific user roles
7. Click "Save Rule"

### Creating a Restriction Rule

1. Select the product(s) that should be restricted
2. Choose "Restrictions" as the rule type
3. Select the conflicting products
4. If any of the conflicting products are in cart or previously purchased, the restricted product cannot be added

### Setting Purchase Limits

1. Select the product(s) to limit
2. Choose "Limit" as the rule type
3. Enter the maximum quantity allowed
4. Save the rule

### Customizing Error Messages

1. Go to the **Error Message Settings** tab
2. Customize messages for dependencies, restrictions, and limits
3. Use placeholders: `%s` for product names, `%d` for numbers
4. HTML formatting is supported

### Importing/Exporting Rules

1. Go to the **Import/Export** tab
2. Click "Export Rules" to download a CSV file
3. Edit the CSV in Excel or Google Sheets
4. Use "Import Rules" to upload your changes
5. Choose whether to overwrite existing rules

## Technical Details

- Uses custom database table: `wp_wc_product_rules`
- Integrates with WooCommerce hooks: `woocommerce_add_to_cart_validation` and `woocommerce_check_cart_items`
- Caching uses WordPress object cache (compatible with Redis, Memcached, etc.)
- Supports Members Only plugin for product visibility checks
- Fully compatible with WooCommerce Product Bundles and composite products

## Hooks & Filters

The plugin provides several hooks for developers:

- `wprules_rule_created` - Fired when a rule is created
- `wprules_rule_updated` - Fired when a rule is updated
- `wprules_rule_deleted` - Fired when a rule is deleted
- `wprules_user_roles` - Filter to modify user roles for rule matching
- `wprules_get_hidden_roles_for_product` - Filter to modify hidden roles for product visibility

## Support

For issues, feature requests, or contributions, please use the GitHub Issues page.

## License

This plugin is provided as-is for use with WooCommerce.

## Changelog

### Version 1.2.1
- Initial release with full feature set
- Dependency rules with ALL/ANY support
- Restriction rules
- Purchase limits
- User role support
- Import/Export functionality
- Caching system
- Bundle product support

