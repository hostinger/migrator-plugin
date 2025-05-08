/**
 * Admin JavaScript for Custom Migrator plugin
 */
jQuery(document).ready(function($) {
    // Auto-refresh only if export is in progress
    if ($("#export-progress").length > 0 && $("#export-progress").is(":visible") && 
        $("#export-status-text").text().includes("...") && 
        !$("#export-status-text").text().toLowerCase().includes("done")) {
        
        // Start checking export status
        startStatusCheck();
    }
    
    // Start export via AJAX
    $("#start-export").on("click", function(e) {
        e.preventDefault();
        
        // Show loading indicator
        $(this).prop('disabled', true);
        $("#export-progress").show();
        $("#export-status-text").text("Starting export...");
        
        // Start the export via AJAX
        $.ajax({
            url: cm_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'cm_start_export',
                nonce: cm_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    // Export started in background
                    startStatusCheck();
                } else {
                    // Show error
                    alert("Error: " + (response.data ? response.data.message : "Export failed to start."));
                    $("#start-export").prop('disabled', false);
                    $("#export-progress").hide();
                }
            },
            error: function() {
                alert("Server error. Please try again.");
                $("#start-export").prop('disabled', false);
                $("#export-progress").hide();
            }
        });
    });
    
    // Status checking function
    function startStatusCheck() {
        // Check status every 3 seconds
        var statusInterval = setInterval(function() {
            $.ajax({
                url: cm_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'cm_check_status',
                    nonce: cm_ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        var status = response.data.status;
                        $("#export-status-text").text(capitalizeFirstLetter(status) + "...");
                        
                        if (status === 'done') {
                            clearInterval(statusInterval);
                            window.location.reload();
                        }
                        
                        // Update progress log if available
                        if (response.data.recent_log) {
                            var logLines = response.data.recent_log.split("\n");
                            var latestLog = logLines[logLines.length - 1];
                            if (latestLog) {
                                $("#export-log-preview").text(latestLog).show();
                            }
                        }
                    } else {
                        // Error occurred
                        clearInterval(statusInterval);
                        $("#export-status-text").text("Error: " + (response.data ? response.data.message : "Export failed."));
                        $("#start-export").prop('disabled', false);
                        setTimeout(function() {
                            window.location.reload();
                        }, 3000);
                    }
                },
                error: function() {
                    // Network error, but keep trying
                    $("#export-status-text").text("Checking status...");
                }
            });
        }, 3000);
    }
    
    // Helper function to capitalize first letter
    function capitalizeFirstLetter(string) {
        return string.charAt(0).toUpperCase() + string.slice(1);
    }
    
    // Format file size in a human-readable format
    function formatFileSize(bytes) {
        if (bytes === 0) return '0 Bytes';
        
        var k = 1024;
        var sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
        var i = Math.floor(Math.log(bytes) / Math.log(k));
        
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }
});