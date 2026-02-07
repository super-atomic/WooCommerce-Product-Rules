jQuery(function($){
    // Initialize select2 for product search fields
    function initSelect2() {
        $('.wprules-product-search').select2({
            ajax: {
                url: wprulesAjax.ajaxurl,
                dataType: 'json',
                delay: 250,
                data: function(params) {
                    return {
                        q: params.term,
                        action: 'wprules_search_products',
                        security: wprulesAjax.nonce
                    };
                },
                processResults: function(data) {
                    return data;
                }
            },
            minimumInputLength: 2,
            allowClear: true
        });
    }
    
    initSelect2();
    
    // Inline editing functionality
    $(document).on('click', '.wprules-edit-row', function(e) {
        e.preventDefault();
        var $row = $(this).closest('tr');
        var ruleId = $(this).data('rule-id');
        
        // Hide all view mode elements, show all edit mode elements in this row
        $row.find('.wprules-view-mode').hide();
        $row.find('.wprules-edit-mode').show();
        
        // Destroy existing select2 instances
        $row.find('.wprules-product-search').select2('destroy');
        
        // Initialize select2 for product search fields in edit mode
        setTimeout(function() {
            $row.find('.wprules-product-search').each(function() {
                $(this).select2({
                    ajax: {
                        url: wprulesAjax.ajaxurl,
                        dataType: 'json',
                        delay: 250,
                        data: function(params) {
                            return {
                                q: params.term,
                                action: 'wprules_search_products',
                                security: wprulesAjax.nonce
                            };
                        },
                        processResults: function(data) {
                            return data;
                        }
                    },
                    minimumInputLength: 2,
                    allowClear: true
                });
            });
            
            // Initialize select2 for user roles field
            $row.find('select[name="user_roles[]"]').select2({
                placeholder: 'Select roles (optional)',
                allowClear: true
            });
        }, 100);
        
        // Toggle fields based on rule type
        toggleFieldsForRow($row);
        
        // Listen for rule type changes
        $row.find('select[name="rule_type"]').off('change').on('change', function() {
            toggleFieldsForRow($row);
        });
    });
    
    // Cancel editing
    $(document).on('click', '.wprules-cancel-row', function(e) {
        e.preventDefault();
        var $row = $(this).closest('tr');
        
        // Show view mode, hide edit mode
        $row.find('.wprules-edit-mode').hide();
        $row.find('.wprules-view-mode').show();
        
        // Destroy select2 instances in edit mode
        $row.find('.wprules-product-search, select[name="user_roles[]"]').select2('destroy');
    });
    
    // Save row
    $(document).on('click', '.wprules-save-row', function(e) {
        e.preventDefault();
        var $row = $(this).closest('tr');
        var ruleId = $(this).data('rule-id');
        var $button = $(this);
        
        // Disable button during save
        $button.prop('disabled', true).text('Saving...');
        
        // Collect form data
        var formData = {
            action: 'wprules_save_rule_inline',
            rule_id: ruleId,
            product_ids: $row.find('select[name="product_ids[]"]').val() || [],
            target_ids: $row.find('select[name="target_ids[]"]').val() || [],
            rule_type: $row.find('select[name="rule_type"]').val(),
            scope: $row.find('select[name="scope"]').val(),
            match_type: $row.find('select[name="match_type"]').val(),
            limit_qty: $row.find('input[name="limit_qty"]').val() || '',
            user_roles: $row.find('select[name="user_roles[]"]').val() || [],
            security: wprulesAjax.nonce
        };
        
        // Submit via AJAX
        $.ajax({
            url: wprulesAjax.ajaxurl,
            type: 'POST',
            data: formData,
            success: function(response) {
                if (response.success) {
                    // Reload page to show updated data
                    location.reload();
                } else {
                    alert('Error saving rule: ' + (response.data || 'Unknown error'));
                    $button.prop('disabled', false).text('Save');
                }
            },
            error: function() {
                alert('Error saving rule. Please try again.');
                $button.prop('disabled', false).text('Save');
            }
        });
    });
    
    // Toggle fields based on rule type for a specific row
    function toggleFieldsForRow($row) {
        var type = $row.find('select[name="rule_type"]').val();
        if (type === 'dependencies' || type === 'restrictions') {
            $row.find('.wprules-field-related-product').show();
            $row.find('.wprules-field-max-qty').hide();
        } else if (type === 'limit') {
            $row.find('.wprules-field-related-product').hide();
            $row.find('.wprules-field-max-qty').show();
        } else {
            $row.find('.wprules-field-related-product, .wprules-field-max-qty').hide();
        }
    }
});