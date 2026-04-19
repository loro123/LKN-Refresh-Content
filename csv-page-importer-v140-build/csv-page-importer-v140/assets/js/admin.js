/**
 * CSV Page Importer Admin JavaScript
 * Version: 1.2.1
 */

jQuery(document).ready(function($) {
    // Toggle Divi options
    $('#use_divi').change(function() {
        if ($(this).is(':checked')) {
            $('#divi-options').show();
        } else {
            $('#divi-options').hide();
            $('#divi-library-options').hide();
            $('#header-options').hide();
            $('#cta-options').hide();
        }
    });
    
    // Toggle Divi library options
    $('#use_divi_library').change(function() {
        if ($(this).is(':checked')) {
            $('#divi-library-options').show();
        } else {
            $('#divi-library-options').hide();
            $('#header-options').hide();
            $('#cta-options').hide();
        }
    });
    
    // Toggle header options
    $('#use_header').change(function() {
        if ($(this).is(':checked')) {
            $('#header-options').show();
        } else {
            $('#header-options').hide();
        }
    });
    
    // Toggle CTA options
    $('#use_cta').change(function() {
        if ($(this).is(':checked')) {
            $('#cta-options').show();
        } else {
            $('#cta-options').hide();
        }
    });
});
