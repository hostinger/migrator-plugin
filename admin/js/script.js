/**
 * Admin JavaScript for Custom Migrator plugin with enhanced error handling and monitoring
 */
jQuery(document).ready(function($) {
    // Enhanced status tracking variables
    var statusCheckInterval = null;
    var s3StatusInterval = null;
    var consecutiveErrors = 0;
    var maxConsecutiveErrors = 5;
    var lastStatusUpdate = null;
    
    // Auto-refresh only if export is in progress
    if ($("#export-progress").length > 0 && $("#export-progress").is(":visible") && 
        $("#export-status-text").text().includes("...") && 
        !$("#export-status-text").text().toLowerCase().includes("done")) {
        
        // Start checking export status
        startStatusCheck();
    }
    
    // Start export via AJAX with enhanced error handling
    $("#start-export").on("click", function(e) {
        e.preventDefault();
        
        // Reset error counter
        consecutiveErrors = 0;
        lastStatusUpdate = null;
        
        // Show loading indicator
        $(this).prop('disabled', true);
        $("#export-progress").show();
        $("#export-status-text").text("Starting export...");
        
        // Clear any existing intervals
        if (statusCheckInterval) {
            clearInterval(statusCheckInterval);
            statusCheckInterval = null;
        }
        
        // Start the export via AJAX
        $.ajax({
            url: cm_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'cm_start_export',
                nonce: cm_ajax.nonce
            },
            timeout: 30000, // 30 second timeout
            success: function(response) {
                if (response.success) {
                    // Export started in background
                    $("#export-status-text").text("Export started successfully. Processing...");
                    startStatusCheck();
                } else {
                    // Show error
                    var errorMsg = response.data ? response.data.message : "Export failed to start.";
                    showError("Error: " + errorMsg);
                    $("#start-export").prop('disabled', false);
                    $("#export-progress").hide();
                }
            },
            error: function(xhr, status, error) {
                var errorMsg = "Server error occurred.";
                if (status === 'timeout') {
                    errorMsg = "Request timed out. The export may still be starting in the background.";
                    $("#export-status-text").text("Export may be starting in background. Checking status...");
                    // Start checking status anyway in case export actually started
                    startStatusCheck();
                } else {
                    showError(errorMsg + " Please try again.");
                    $("#start-export").prop('disabled', false);
                    $("#export-progress").hide();
                }
                
                console.log('Export start error:', {status: status, error: error, xhr: xhr});
            }
        });
    });
    
    // S3 Upload form with enhanced validation and error handling
    $("#upload-to-s3").on("click", function(e) {
        e.preventDefault();
        
        // Validate that at least one URL is provided
        var hstgrUrl = $("input[name='s3_url_hstgr']").val().trim();
        var sqlUrl = $("input[name='s3_url_sql']").val().trim();
        var metaUrl = $("input[name='s3_url_metadata']").val().trim();
        
        if (!hstgrUrl && !sqlUrl && !metaUrl) {
            showError("Please provide at least one pre-signed URL for upload.");
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
            timeout: 60000, // 60 second timeout for S3 start
            success: function(response) {
                if (response.success) {
                    // Start checking S3 upload status
                    startS3StatusCheck();
                } else {
                    // Show error
                    var errorMsg = response.data ? response.data.message : "Upload failed.";
                    showError("Error: " + errorMsg);
                    resetS3Form();
                }
            },
            error: function(xhr, status, error) {
                var errorMsg = status === 'timeout' ? 
                    "Upload request timed out. Please check the status manually." :
                    "Server error occurred. Please try again.";
                showError(errorMsg);
                resetS3Form();
                console.log('S3 upload start error:', {status: status, error: error, xhr: xhr});
            }
        });
    });
    
    // Function to check S3 upload status with enhanced error recovery
    function startS3StatusCheck() {
        var errorCount = 0;
        var maxErrors = 3;
        
        // Clear any existing interval
        if (s3StatusInterval) {
            clearInterval(s3StatusInterval);
        }
        
        // Check status every 3 seconds
        s3StatusInterval = setInterval(function() {
            $.ajax({
                url: cm_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'cm_check_s3_status',
                    nonce: cm_ajax.nonce
                },
                timeout: 15000, // 15 second timeout
                success: function(response) {
                    errorCount = 0; // Reset error count on success
                    
                    if (response.success) {
                        var status = response.data.status;
                        var message = response.data.message || "Uploading...";
                        
                        if (status === 'starting') {
                            $("#s3-upload-status").html('<div class="spinner is-active" style="float: none; margin: 0 10px 0 0;"></div><span>Starting S3 upload...</span>');
                        } else if (status.startsWith('uploading_')) {
                            var currentFile = response.data.current_file || status.replace('uploading_', '');
                            $("#s3-upload-status").html('<div class="spinner is-active" style="float: none; margin: 0 10px 0 0;"></div><span>Uploading ' + currentFile + ' file...</span>');
                        } else if (status === 'done') {
                            clearInterval(s3StatusInterval);
                            $("#s3-upload-status").html('<span style="color: green;"><span class="dashicons dashicons-yes"></span> Upload completed successfully!</span>');
                            resetS3Form();
                            
                            // Optionally, reload the page after a short delay
                            setTimeout(function() {
                                window.location.href = window.location.href.split('?')[0] + '?page=custom-migrator&s3_upload=success';
                            }, 2000);
                        }
                    } else {
                        // Error occurred
                        clearInterval(s3StatusInterval);
                        var errorMsg = response.data ? response.data.message : "Upload failed.";
                        $("#s3-upload-status").html('<span style="color: red;">Error: ' + errorMsg + '</span>');
                        resetS3Form();
                    }
                },
                error: function(xhr, status, error) {
                    errorCount++;
                    console.log('S3 status check error #' + errorCount + ':', {status: status, error: error, xhr: xhr});
                    
                    if (errorCount >= maxErrors) {
                        clearInterval(s3StatusInterval);
                        $("#s3-upload-status").html('<span style="color: red;">Connection error. Please check the status manually.</span>');
                        resetS3Form();
                    } else {
                        // Continue checking but show temporary error
                        $("#s3-upload-status").html('<div class="spinner is-active" style="float: none; margin: 0 10px 0 0;"></div><span>Checking upload status... (retry ' + errorCount + ')</span>');
                    }
                }
            });
        }, 3000);
    }
    
    // Enhanced status checking function with better stuck detection and recovery
    function startStatusCheck() {
        consecutiveErrors = 0;
        lastStatusUpdate = Date.now();
        
        // Clear any existing interval
        if (statusCheckInterval) {
            clearInterval(statusCheckInterval);
        }
        
        // Check status every 3 seconds
        statusCheckInterval = setInterval(function() {
            $.ajax({
                url: cm_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'cm_check_status',
                    nonce: cm_ajax.nonce
                },
                timeout: 15000, // 15 second timeout
                success: function(response) {
                    consecutiveErrors = 0; // Reset error count on success
                    lastStatusUpdate = Date.now();
                    
                    if (response.success) {
                        var status = response.data.status;
                        var timeSinceUpdate = response.data.time_since_update || 0;
                        
                        if (status === 'paused_resuming') {
                            // Display detailed paused status with progress information
                            var progressInfo = response.data.progress;
                            var statusMessage = "Export paused after processing " + 
                                progressInfo.files_processed + " files (" + 
                                progressInfo.bytes_processed + "). Resuming automatically...";
                            
                            $("#export-status-text").text(statusMessage);
                            
                            // Show progress info in a nice format
                            if (progressInfo.last_update) {
                                $("#export-log-preview").html(
                                    '<div class="resume-progress-info">' +
                                    '<p><strong>Progress:</strong> ' + progressInfo.files_processed + ' files processed</p>' +
                                    '<p><strong>Data Processed:</strong> ' + progressInfo.bytes_processed + '</p>' +
                                    '<p><strong>Last Update:</strong> ' + progressInfo.last_update + '</p>' +
                                    '</div>'
                                ).show();
                            }
                        } else if (status === 'done') {
                            clearInterval(statusCheckInterval);
                            $("#export-status-text").text("Export completed successfully! Refreshing...");
                            setTimeout(function() {
                                window.location.reload();
                            }, 2000);
                        } else if (status.endsWith('_stuck')) {
                            // Handle stuck status with appropriate user feedback
                            var baseStatus = status.replace('_stuck', '');
                            var minutes = Math.round(timeSinceUpdate / 60);
                            var statusText = capitalizeFirstLetter(baseStatus) + " (may be stuck - no activity for " + minutes + " minutes)...";
                            $("#export-status-text").text(statusText);
                            
                            // Show warning after extended stuck time
                            if (timeSinceUpdate > 900) { // 15 minutes
                                showWarning("Export appears to be stuck. Consider refreshing the page or restarting the export.");
                            }
                        } else {
                            // Regular status updates with time information
                            var statusText = capitalizeFirstLetter(status) + "...";
                            if (timeSinceUpdate > 300) { // 5+ minutes
                                statusText += " (processing for " + Math.round(timeSinceUpdate / 60) + " min)";
                            }
                            $("#export-status-text").text(statusText);
                            
                            // Update progress log if available
                            if (response.data.recent_log) {
                                var logLines = response.data.recent_log.split("\n");
                                var latestLog = "";
                                // Find the most recent non-empty log line
                                for (var i = logLines.length - 1; i >= 0; i--) {
                                    if (logLines[i].trim()) {
                                        latestLog = logLines[i].trim();
                                        break;
                                    }
                                }
                                if (latestLog) {
                                    $("#export-log-preview").text(latestLog).show();
                                }
                            }
                        }
                    } else {
                        // Error occurred
                        clearInterval(statusCheckInterval);
                        var errorMsg = response.data ? response.data.message : "Export failed.";
                        $("#export-status-text").text("Error: " + errorMsg);
                        $("#start-export").prop('disabled', false);
                        showError("Export error: " + errorMsg);
                        
                        setTimeout(function() {
                            window.location.reload();
                        }, 5000);
                    }
                },
                error: function(xhr, status, error) {
                    consecutiveErrors++;
                    console.log('Status check error #' + consecutiveErrors + ':', {status: status, error: error, xhr: xhr});
                    
                    if (consecutiveErrors >= maxConsecutiveErrors) {
                        clearInterval(statusCheckInterval);
                        $("#export-status-text").text("Connection lost. Please refresh the page to check status.");
                        $("#start-export").prop('disabled', false);
                        showError("Lost connection to server. Please refresh the page to check export status.");
                    } else {
                        // Show temporary connection issue but continue trying
                        $("#export-status-text").text("Checking status... (connection issue, retry " + consecutiveErrors + ")");
                        
                        if (status === 'timeout') {
                            // Increase interval on timeout to reduce server load
                            clearInterval(statusCheckInterval);
                            setTimeout(function() {
                                if (consecutiveErrors < maxConsecutiveErrors) {
                                    startStatusCheck();
                                }
                            }, 5000); // Restart with 5 second delay
                        }
                    }
                }
            });
        }, 3000);
    }
    
    // Helper function to show error messages with proper styling
    function showError(message) {
        // Create or update error notice
        var errorNotice = $('.custom-migrator .notice-error.js-generated');
        if (errorNotice.length === 0) {
            errorNotice = $('<div class="notice notice-error is-dismissible js-generated"><p></p></div>');
            $('.custom-migrator h1').after(errorNotice);
        }
        errorNotice.find('p').text(message);
        errorNotice.show();
        
        // Scroll to top to show error
        $('html, body').animate({scrollTop: 0}, 500);
        
        // Auto-hide after 10 seconds
        setTimeout(function() {
            errorNotice.fadeOut();
        }, 10000);
    }
    
    // Helper function to show warning messages
    function showWarning(message) {
        var warningNotice = $('.custom-migrator .notice-warning.js-generated');
        if (warningNotice.length === 0) {
            warningNotice = $('<div class="notice notice-warning is-dismissible js-generated"><p></p></div>');
            $('.custom-migrator h1').after(warningNotice);
        }
        warningNotice.find('p').text(message);
        warningNotice.show();
        
        // Auto-hide after 15 seconds
        setTimeout(function() {
            warningNotice.fadeOut();
        }, 15000);
    }
    
    // Helper function to reset S3 form
    function resetS3Form() {
        $("#upload-to-s3").prop('disabled', false);
        $("#s3-upload-spinner").removeClass("is-active");
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
    
    // Cleanup intervals when page unloads to prevent memory leaks
    $(window).on('beforeunload', function() {
        if (statusCheckInterval) {
            clearInterval(statusCheckInterval);
            statusCheckInterval = null;
        }
        if (s3StatusInterval) {
            clearInterval(s3StatusInterval);
            s3StatusInterval = null;
        }
    });
    
    // Enhanced error handling for AJAX setup with better logging
    $(document).ajaxError(function(event, xhr, settings, thrownError) {
        // Only handle our plugin's AJAX calls
        if (settings.url === cm_ajax.ajax_url && settings.data && settings.data.includes('cm_')) {
            console.log('AJAX Error Details:', {
                url: settings.url,
                action: settings.data.match(/action=([^&]*)/)?.[1] || 'unknown',
                status: xhr.status,
                statusText: xhr.statusText,
                error: thrownError,
                responseText: xhr.responseText ? xhr.responseText.substring(0, 200) : 'no response'
            });
        }
    });
    
    // Add connection monitoring to detect if the server becomes unresponsive
    var connectionCheckInterval = null;
    
    function startConnectionMonitoring() {
        if (connectionCheckInterval) return; // Already monitoring
        
        connectionCheckInterval = setInterval(function() {
            // Only check connection if we're actively monitoring an export
            if (statusCheckInterval && lastStatusUpdate) {
                var timeSinceLastUpdate = Date.now() - lastStatusUpdate;
                
                // If no successful status update in 60 seconds, show warning
                if (timeSinceLastUpdate > 60000) {
                    showWarning("Connection may be unstable. Export status hasn't updated in " + 
                              Math.round(timeSinceLastUpdate / 1000) + " seconds.");
                }
            }
        }, 30000); // Check every 30 seconds
    }
    
    // Start connection monitoring if export is in progress
    if ($("#export-progress").is(":visible")) {
        startConnectionMonitoring();
    }
});