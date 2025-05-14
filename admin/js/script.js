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
    
    // S3 Upload form
    $("#upload-to-s3").on("click", function(e) {
        e.preventDefault();
        
        // Validate that at least one URL is provided
        var hstgrUrl = $("input[name='s3_url_hstgr']").val().trim();
        var sqlUrl = $("input[name='s3_url_sql']").val().trim();
        var metaUrl = $("input[name='s3_url_metadata']").val().trim();
        
        if (!hstgrUrl && !sqlUrl && !metaUrl) {
            alert("Please provide at least one pre-signed URL for upload.");
            return false;
        }
        
        // Show loading indicator
        $(this).prop('disabled', true);
        $("#s3-upload-spinner").addClass("is-active");
        $("#s3-upload-status").show().html('<div class="spinner is-active" style="float: none; margin: 0 10px 0 0;"></div><span>Starting S3 upload...</span>');
        
        // Start the S3 upload via AJAX
        $.ajax({
            url: cm_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'cm_upload_to_s3',
                nonce: cm_ajax.nonce,
                s3_url_hstgr: hstgrUrl,
                s3_url_sql: sqlUrl,
                s3_url_metadata: metaUrl
            },
            success: function(response) {
                if (response.success) {
                    // Start checking S3 upload status
                    startS3StatusCheck();
                } else {
                    // Show error
                    alert("Error: " + (response.data ? response.data.message : "Upload failed."));
                    $("#upload-to-s3").prop('disabled', false);
                    $("#s3-upload-spinner").removeClass("is-active");
                    $("#s3-upload-status").html('<span style="color: red;">Upload failed: ' + (response.data ? response.data.message : "Unknown error") + '</span>');
                }
            },
            error: function() {
                alert("Server error. Please try again.");
                $("#upload-to-s3").prop('disabled', false);
                $("#s3-upload-spinner").removeClass("is-active");
                $("#s3-upload-status").html('<span style="color: red;">Server error. Please try again.</span>');
            }
        });
    });
    
    // Function to check S3 upload status
    function startS3StatusCheck() {
        // Check status every 3 seconds
        var s3StatusInterval = setInterval(function() {
            $.ajax({
                url: cm_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'cm_check_s3_status',
                    nonce: cm_ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        var status = response.data.status;
                        var message = response.data.message || "Uploading...";
                        
                        if (status === 'starting') {
                            $("#s3-upload-status").html('<div class="spinner is-active" style="float: none; margin: 0 10px 0 0;"></div><span>Starting S3 upload...</span>');
                        } else if (status.startsWith('uploading_')) {
                            var currentFile = response.data.current_file;
                            $("#s3-upload-status").html('<div class="spinner is-active" style="float: none; margin: 0 10px 0 0;"></div><span>Uploading ' + currentFile + ' file...</span>');
                        } else if (status === 'done') {
                            clearInterval(s3StatusInterval);
                            $("#s3-upload-status").html('<span style="color: green;"><span class="dashicons dashicons-yes"></span> Upload completed successfully!</span>');
                            $("#upload-to-s3").prop('disabled', false);
                            $("#s3-upload-spinner").removeClass("is-active");
                            
                            // Optionally, reload the page after a short delay
                            setTimeout(function() {
                                window.location.href = window.location.href.split('?')[0] + '?page=custom-migrator&s3_upload=success';
                            }, 2000);
                        }
                    } else {
                        // Error occurred
                        clearInterval(s3StatusInterval);
                        $("#s3-upload-status").html('<span style="color: red;">Error: ' + (response.data ? response.data.message : "Upload failed.") + '</span>');
                        $("#upload-to-s3").prop('disabled', false);
                        $("#s3-upload-spinner").removeClass("is-active");
                    }
                },
                error: function() {
                    // Network error, but keep trying
                    $("#s3-upload-status").html('<div class="spinner is-active" style="float: none; margin: 0 10px 0 0;"></div><span>Checking upload status...</span>');
                }
            });
        }, 3000);
    }
    
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