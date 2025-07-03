<?php
/**
 * Fallback Exporter Class
 * 
 * Handles AJAX-based fallback export functionality when regular cron-based export fails.
 * This provides the same functionality as the fallback methods in the core class.
 *
 * @package CustomMigrator
 */

/**
 * Custom Migrator Fallback Exporter
 */
class Custom_Migrator_Fallback_Exporter {

    /**
     * The filesystem handler.
     *
     * @var Custom_Migrator_Filesystem
     */
    private $filesystem;

    /**
     * Fallback exclusion paths for export.
     *
     * @var array
     */
    private $fallback_exclusion_paths;



    /**
     * Constructor
     */
    public function __construct() {
        $this->filesystem = new Custom_Migrator_Filesystem();
        
        // Load the unified database exporter
        require_once dirname(__FILE__) . '/class-database-exporter.php';
    }

    /**
     * Handle fallback export AJAX requests
     */
    public function handle_fallback_export() {
        try {
            // Get parameters from AJAX request first
            $step = isset($_POST['step']) ? sanitize_text_field($_POST['step']) : 'init';
            $params = isset($_POST['params']) ? (array) $_POST['params'] : array();
            
            // CRITICAL: Session-based lock system to prevent duplicate exports while allowing same session to continue
            $lock_file = $this->filesystem->get_export_dir() . '/fallback_export.lock';
            
            // Generate or get session ID for this export
            $session_id = isset($params['session_id']) ? $params['session_id'] : uniqid('fallback_', true);
            
            // Check if another fallback export is already running
            if (file_exists($lock_file)) {
                $lock_content = file_get_contents($lock_file);
                $lock_data = json_decode($lock_content, true);
                
                if (!$lock_data) {
                    // Old format lock file, treat as stale
                    unlink($lock_file);
                    $this->filesystem->log("FALLBACK EXPORT: Removed old format lock file");
                } else {
                    $lock_time = $lock_data['time'];
                    $lock_session = $lock_data['session_id'];
                    $current_time = time();
                    
                    // If lock is older than 5 minutes, consider it stale and remove it
                    if (($current_time - $lock_time) > 300) {
                        unlink($lock_file);
                        $this->filesystem->log("FALLBACK EXPORT: Removed stale lock file (age: " . round(($current_time - $lock_time) / 60, 1) . " minutes)");
                    } else if ($lock_session !== $session_id) {
                        // Different session trying to start export
                        $this->filesystem->log("FALLBACK EXPORT: Another fallback export session is already running, aborting duplicate request");
                        wp_send_json_error(array('message' => 'Another fallback export is already in progress. Please wait for it to complete.'));
                        return;
                    } else {
                        // Same session continuing - this is allowed
                        $this->filesystem->log("FALLBACK EXPORT: Continuing same export session: $session_id");
                    }
                }
            }
            
            // Create or update lock file for this session (only for init step or if no lock exists)
            if ($step === 'init' || !file_exists($lock_file)) {
                if (!is_dir($this->filesystem->get_export_dir())) {
                    wp_mkdir_p($this->filesystem->get_export_dir());
                }
                $lock_data = array(
                    'session_id' => $session_id,
                    'time' => time(),
                    'step' => $step
                );
                file_put_contents($lock_file, json_encode($lock_data));
                $this->filesystem->log("FALLBACK EXPORT: Created lock file for session: $session_id");
            } else {
                // Update existing lock file with current step and time
                $lock_content = file_get_contents($lock_file);
                $lock_data = json_decode($lock_content, true);
                $lock_data['time'] = time();
                $lock_data['step'] = $step;
                file_put_contents($lock_file, json_encode($lock_data));
            }
            
            // CRITICAL: Clear all regular export scheduling to prevent conflicts
            wp_clear_scheduled_hook('cm_run_export');
            wp_clear_scheduled_hook('cm_monitor_export');
            wp_clear_scheduled_hook('cm_run_export_direct');
            
            // Add session_id to params for all subsequent steps
            $params['session_id'] = $session_id;
            
            $this->filesystem->log("FALLBACK EXPORT: Step=$step, Session=$session_id (cleared regular export hooks)");
            
            // Set up execution environment
            @set_time_limit(0);
            @ignore_user_abort(true);
            
            // CRITICAL: Initialize exclusion paths IMMEDIATELY before any processing
            if (!isset($this->fallback_exclusion_paths)) {
                $this->set_fallback_exclusion_paths();
            }
            
            // Initialize fallback export directory
            $export_dir = $this->filesystem->get_export_dir();
            if (!is_dir($export_dir)) {
                wp_mkdir_p($export_dir);
            }
            
            $this->filesystem->log("Fallback export step: $step");
            
            // Process based on step (following All-in-One WP Migration priority sequence)
            switch ($step) {
                case 'init':
                    $result = $this->fallback_export_init($params);
                    break;
                    
                case 'enumerate_files':
                    $result = $this->fallback_enumerate_files($params);
                    break;
                    
                case 'create_archive':
                    $result = $this->fallback_create_archive($params);
                    break;
                    
                case 'export_database':
                    $result = $this->fallback_export_database($params);
                    break;
                    
                case 'create_metadata':
                    $result = $this->fallback_create_metadata($params);
                    break;
                    
                case 'finalize':
                    $result = $this->fallback_finalize($params);
                    break;
                    
                default:
                    wp_send_json_error(array('message' => 'Invalid step: ' . $step));
                    return;
            }
            
            // Remove lock file on successful completion of final step
            if (isset($result['completed']) && isset($result['final']) && $result['final']) {
                $lock_file = $this->filesystem->get_export_dir() . '/fallback_export.lock';
                if (file_exists($lock_file)) {
                    unlink($lock_file);
                    $this->filesystem->log("FALLBACK EXPORT: Removed lock file on completion");
                }
            }
            
            wp_send_json_success($result);
            
        } catch (Exception $e) {
            // Remove lock file on error
            $lock_file = $this->filesystem->get_export_dir() . '/fallback_export.lock';
            if (file_exists($lock_file)) {
                unlink($lock_file);
                $this->filesystem->log("FALLBACK EXPORT: Removed lock file on error");
            }
            
            $this->filesystem->log("Fallback export error: " . $e->getMessage());
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }

    /**
     * Handle fallback export status check
     */
    public function handle_fallback_status() {
        $status_file = $this->filesystem->get_status_file_path();
        
        if (file_exists($status_file)) {
            $status = trim(file_get_contents($status_file));
            wp_send_json_success(array(
                'status' => $status,
                'timestamp' => date('H:i:s')
            ));
        } else {
            wp_send_json_success(array(
                'status' => 'none',
                'timestamp' => date('H:i:s')
            ));
        }
    }

    /**
     * Check if fallback export is currently running
     */
    public function is_fallback_export_active() {
        $status_file = $this->filesystem->get_status_file_path();
        if (file_exists($status_file)) {
            $status = trim(file_get_contents($status_file));
            return strpos($status, 'fallback_') === 0;
        }
        return false;
    }

    /**
     * Initialize fallback export (Step 1) - Enhanced with conflict detection
     */
    private function fallback_export_init($params) {
        $this->filesystem->log("Fallback export: Initializing with conflict detection...");
        
        // ENHANCED: Detect any existing regular export and force complete cleanup
        $status_file = $this->filesystem->get_status_file_path();
        $current_status = '';
        if (file_exists($status_file)) {
            $current_status = trim(file_get_contents($status_file));
            $status_age = time() - filemtime($status_file);
            
            $this->filesystem->log("Detected existing export status: '$current_status' (age: {$status_age}s)");
            $this->filesystem->log("FALLBACK POLICY: Always start fresh - will force stop any existing export process");
        }
        
        // CRITICAL: Force stop and cleanup ALL regular export processes and files
        $this->filesystem->log("=== FALLBACK CLEANUP: Force stopping regular export and removing ALL files ===");
        
        // 1. Clear ALL regular export scheduling and background processes
        wp_clear_scheduled_hook('cm_run_export');
        wp_clear_scheduled_hook('cm_monitor_export');
        wp_clear_scheduled_hook('cm_run_export_direct');
        wp_clear_scheduled_hook('cm_force_continue');
        wp_clear_scheduled_hook('cm_resume_export');
        
        // 2. Force remove ALL database export locks and status files
        $export_dir = $this->filesystem->get_export_dir();
        $db_lock_file = $export_dir . '/database-export.lock';
        $db_status_file = $export_dir . '/database-export-status.json';
        
        if (file_exists($db_lock_file)) {
            @unlink($db_lock_file);
        }
        
        if (file_exists($db_status_file)) {
            @unlink($db_status_file);
        }
        
        // 3. Remove ALL regular export resume and step files
        $resume_files = [
            'export-resume-info.json',
            'export-step.txt',
            'content-list.csv'
        ];
        
        foreach ($resume_files as $file) {
            $file_path = $export_dir . '/' . $file;
            if (file_exists($file_path)) {
                @unlink($file_path);
            }
        }
        
        // 4. Remove all export data files EXCEPT current fallback lock
        $files_to_preserve = [
            'fallback_export.lock',
        ];
        
        if (is_dir($export_dir)) {
            $files = scandir($export_dir);
            foreach ($files as $file) {
                if ($file === '.' || $file === '..' || in_array($file, $files_to_preserve)) {
                    continue;
                }
                
                $file_path = $export_dir . '/' . $file;
                if (is_file($file_path)) {
                    @unlink($file_path);
                }
            }
        }
        
        // Update status
        $this->filesystem->write_status('fallback_initializing');
        
        return array(
            'completed' => true,
            'next_step' => 'enumerate_files',
            'message' => 'Fallback export initialized',
            'params' => $params
        );
    }

    /**
     * Enumerate files for fallback export (Step 2) - IDENTICAL to main export
     */
    private function fallback_enumerate_files($params) {
        $this->filesystem->log("Fallback export: Enumerating files...");
        
        // Update status
        $this->filesystem->write_status('fallback_enumerating');
        
        $export_dir = $this->filesystem->get_export_dir();
        $content_list_path = $export_dir . '/content-list.csv';
        
        // CRITICAL: Ensure exclusion paths are set BEFORE any enumeration
        if (!isset($this->fallback_exclusion_paths)) {
            $this->set_fallback_exclusion_paths();
        }
        
        // Use EXACT same enumeration as main export
        $this->enumerate_content_files($content_list_path);
        
        // Count files for reporting
        $file_count = $this->count_lines_in_csv($content_list_path);
        
        $this->filesystem->log("File enumeration completed. Total: $file_count files");
        
        return array(
            'completed' => true,
            'next_step' => 'create_archive',
            'message' => "Enumerated $file_count files",
            'file_count' => $file_count,
            'params' => array_merge($params, array(
                'file_count' => $file_count
            ))
        );
    }

    /**
     * Set exclusion paths for fallback export - IDENTICAL to main export
     */
    private function set_fallback_exclusion_paths() {
        // Use unified helper class for exclusion paths
        $this->fallback_exclusion_paths = Custom_Migrator_Helper::get_exclusion_paths();
    }

    /**
     * Enumerate content files into CSV using unified enumerator.
     */
    private function enumerate_content_files($content_list_file) {
        // Use unified file enumerator with unlimited execution environment (same as regular export)
        $enumerator = new Custom_Migrator_File_Enumerator($this->filesystem);
        
        $options = array(
            'progress_interval' => 1000,  // More frequent progress updates
            'use_exclusions' => true,
            'validate_files' => true,
            'skip_unreadable' => true,
            'log_errors' => true,
            'use_unlimited_execution' => true  // Enable unlimited execution time for enumeration
        );
        
        $stats = $enumerator->enumerate_to_csv($content_list_file, $options);
        
        // Log final results in format compatible with existing code
        $this->filesystem->log("File enumeration complete: {$stats['files_found']} files");
        
        return $stats;
    }



    /**
     * Count lines in CSV file using unified method.
     */
    private function count_lines_in_csv($csv_file) {
        return Custom_Migrator_File_Enumerator::count_csv_lines($csv_file);
    }

    /**
     * Format bytes for display
     */
    private function format_bytes($bytes) {
        if ($bytes >= 1073741824) {
            return round($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return round($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return round($bytes / 1024, 2) . ' KB';
        } else {
            return $bytes . ' bytes';
        }
    }

    /**
     * Create binary archive (Step 3) - Chunked processing with timeout protection
     */
    private function fallback_create_archive($params) {
        $start_time = microtime(true);
        $this->filesystem->log("Fallback export: Creating binary archive with chunked processing...");
        
        // Update status
        $this->filesystem->write_status('fallback_archiving');
        
        $export_dir = $this->filesystem->get_export_dir();
        $content_list_path = $export_dir . '/content-list.csv';
        
        // Use unique filename from filesystem
        $file_paths = $this->filesystem->get_export_file_paths();
        $archive_path = $file_paths['hstgr'];
        
        if (!file_exists($content_list_path)) {
            throw new Exception('Content list file not found');
        }
        
        // Get resume parameters - EXACTLY like regular export
        $files_processed = isset($params['files_processed']) ? (int)$params['files_processed'] : 0;
        $bytes_processed = isset($params['bytes_processed']) ? (int)$params['bytes_processed'] : 0;
        $csv_offset = isset($params['csv_offset']) ? (int)$params['csv_offset'] : 0;
        $archive_offset = isset($params['archive_offset']) ? (int)$params['archive_offset'] : 0;
        $total_files = isset($params['file_count']) ? (int)$params['file_count'] : $this->count_lines_in_csv($content_list_path);
        
        // Log resume information for debugging
        if ($files_processed > 0) {
            $this->filesystem->log("Resuming archive creation: $files_processed files processed, CSV offset: $csv_offset, archive offset: $archive_offset");
        }
        
        // Open content list first
        $content_list = fopen($content_list_path, 'r');
        if (!$content_list) {
            throw new Exception('Cannot read content list file');
        }
        
        // Seek to CSV offset if resuming - EXACTLY like regular export
        if ($csv_offset > 0) {
            fseek($content_list, $csv_offset);
        }
        
        // Open archive file
        $archive_mode = $archive_offset > 0 ? 'ab' : 'wb';
        $archive_handle = fopen($archive_path, $archive_mode);
        if (!$archive_handle) {
            fclose($content_list);
            throw new Exception('Cannot create archive file');
        }
        
        // CRITICAL FIX: Don't seek when using append mode!
        // Append mode ('ab') automatically positions at end of file
        // The fseek() was causing corruption by seeking to wrong position
        // if ($archive_offset > 0) {
        //     fseek($archive_handle, $archive_offset);
        // }
        
        $files_in_batch = 0;
        $max_batch_size = apply_filters('custom_migrator_max_files_per_batch', 500);
        $timeout_seconds = apply_filters('custom_migrator_timeout', 30);
        $completed = true;
        
        // Process files in chunks with timeout protection
        while (($file_data = fgetcsv($content_list, 0, ',', '"', '\\')) !== false) {
            if (count($file_data) < 4) {
                continue; // Skip malformed lines
            }
            
            list($full_path, $relative_path, $size, $mtime) = $file_data;
            
            // Skip if file no longer exists
            if (!file_exists($full_path) || !is_readable($full_path)) {
                $this->filesystem->log("Warning: Skipping missing/unreadable file: " . basename($full_path));
                continue;
            }
            
            // Process the file using same binary format as main export
            $file_info = [
                'path' => $full_path,
                'relative' => $relative_path,
                'size' => (int)$size
            ];
            
            $result = $this->fallback_add_file_to_archive_direct($archive_handle, $file_info);
            
            if ($result['success']) {
                $files_processed++;
                $bytes_processed += $result['bytes'];
                $files_in_batch++;
                
                // Progress logging every 100 files
                if ($files_processed % 100 === 0) {
                    $progress = round(($files_processed / $total_files) * 100, 1);
                    $this->filesystem->log("Batch progress: $files_processed/$total_files files ($progress%) processed");
                }
            } else {
                $this->filesystem->log("Warning: Failed to process file: " . basename($full_path));
            }
            
                    // Check timeout or batch size limits
        $elapsed = microtime(true) - $start_time;
        if ($elapsed > $timeout_seconds || $files_in_batch >= $max_batch_size) {
            $completed = false;
            break;
        }
    }
    
        // Check if we reached end of file
    if (!$completed && feof($content_list)) {
        $completed = true;
    }
        
        // Get current positions for resume
        $current_csv_offset = ftell($content_list);
        // Archive offset is saved for logging but NOT used for seeking
        // We use append mode ('ab') which automatically positions at end of file
        $current_archive_offset = ftell($archive_handle);
        
            fclose($content_list);
    fclose($archive_handle);
    
    $elapsed = microtime(true) - $start_time;
    $progress = $total_files > 0 ? round(($files_processed / $total_files) * 100, 1) : 100;
    
    if ($completed) {
            $this->filesystem->log("Archive completed: $files_processed files (" . $this->format_bytes($bytes_processed) . ") in " . round($elapsed, 1) . " seconds");
            
            return array(
                'completed' => true,
                'next_step' => 'export_database',
                'message' => "Archive created: $files_processed files ($progress%)",
                'files_processed' => $files_processed,
                'bytes_processed' => $bytes_processed,
                'params' => array_merge($params, array(
                    'archive_completed' => true,
                    'files_processed' => $files_processed,
                    'bytes_processed' => $bytes_processed
                ))
            );
        } else {
            $this->filesystem->log("Batch completed: $files_processed/$total_files files ($progress%)");
            
            return array(
                'completed' => false,
                'next_step' => 'create_archive',
                'message' => "Archiving in progress: $files_processed/$total_files files ($progress%)",
                'files_processed' => $files_processed,
                'bytes_processed' => $bytes_processed,
                'pause_requested' => true,
                'params' => array_merge($params, array(
                    'files_processed' => $files_processed,
                    'bytes_processed' => $bytes_processed,
                    'csv_offset' => $current_csv_offset,
                    'archive_offset' => $current_archive_offset,
                    'file_count' => $total_files
                ))
            );
        }
    }

    /**
     * Add file to archive - Direct method using same binary format as main export
     */
    private function fallback_add_file_to_archive_direct($archive_handle, $file_info) {
        $file_path = $file_info['path'];
        $relative_path = $file_info['relative'];
        $file_size = $file_info['size'];
        
        // Enhanced file validation
        if (!file_exists($file_path) || !is_readable($file_path)) {
            return ['success' => false, 'bytes' => 0];
        }
        
        // ENHANCED: Large file handling and memory management
        $is_large_file = $file_size > 50 * 1024 * 1024; // 50MB threshold
        if ($is_large_file) {
            $this->filesystem->log("Processing large file: " . basename($file_path) . " (" . $this->format_bytes($file_size) . ")");
            
            // Force garbage collection before large file
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
        }
        
        // Get file stats
        $stat = stat($file_path);
        if ($stat === false) {
            return ['success' => false, 'bytes' => 0];
        }
        
        // Prepare file info for binary block - IDENTICAL to main export
        $file_name = basename($file_path);
        $file_date = $stat['mtime'];
        $file_dir = dirname($relative_path);
        
        // Use unified binary block creation from helper
        try {
            $block = Custom_Migrator_Helper::create_binary_block($file_name, $file_size, $file_date, $file_dir);
        } catch (Exception $e) {
            return ['success' => false, 'bytes' => 0];
        }
        
        $expected_size = Custom_Migrator_Helper::get_binary_block_size();
        
        // Write the header block
        $header_written = fwrite($archive_handle, $block);
        if ($header_written === false || $header_written !== $expected_size) {
            return ['success' => false, 'bytes' => 0];
        }
        
        // Open and copy file content
        $file_handle = fopen($file_path, 'rb');
        if (!$file_handle) {
            return ['success' => false, 'bytes' => 0];
        }
        
        $bytes_copied = 0;
        $chunk_size = $is_large_file ? 256 * 1024 : 512000; // Smaller chunks for large files
        
        // ENHANCED: Progress tracking for large files
        $progress_interval = $is_large_file ? (10 * 1024 * 1024) : 0; // 10MB intervals for large files
        $last_progress = 0;
        
        while (!feof($file_handle)) {
            $chunk = fread($file_handle, $chunk_size);
            if ($chunk === false || strlen($chunk) === 0) {
                break;
            }
            
            $written = fwrite($archive_handle, $chunk);
            if ($written === false || $written !== strlen($chunk)) {
                fclose($file_handle);
                return ['success' => false, 'bytes' => $bytes_copied];
            }
            
            $bytes_copied += $written;
            
            // ENHANCED: Progress logging and memory management for large files
            if ($is_large_file && $progress_interval > 0 && ($bytes_copied - $last_progress) >= $progress_interval) {
                $progress = round(($bytes_copied / $file_size) * 100, 1);
                $this->filesystem->log("Large file progress: {$progress}% of " . basename($file_path) . " (" . $this->format_bytes($bytes_copied) . "/" . $this->format_bytes($file_size) . ")");
                $last_progress = $bytes_copied;
                
                // Force garbage collection during large file processing
                if (function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }
            }
        }
        
        fclose($file_handle);
        
        // ENHANCED: Post-large-file memory management
        if ($is_large_file) {
            $this->filesystem->log("Large file completed: " . basename($file_path) . " (" . $this->format_bytes($bytes_copied) . ")");
            
            // Force garbage collection after large file
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
            
            // Flush archive buffer to disk to free memory
            if (function_exists('fflush')) {
                fflush($archive_handle);
            }
            
            // Brief pause to let system recover
            usleep(100000); // 0.1 second pause
        }
        
        // Return total bytes written (header + file content)
        return ['success' => true, 'bytes' => $expected_size + $bytes_copied];
    }

    /**
     * Export database (Step 4) - Chunked processing with timeout protection
     */
    private function fallback_export_database($params) {
        $start_time = microtime(true);
        $this->filesystem->log("Fallback export: Creating database export with unified database exporter...");
        
        // DEBUG: Log the parameters being received
        $this->filesystem->log("Database export params received: " . json_encode($params));
        
        // Update status
        $this->filesystem->write_status('fallback_database');
        
        try {
            // Get the SQL file path
            $file_paths = $this->filesystem->get_export_file_paths();
            $sql_file = $file_paths['sql'];
            
            // CRITICAL: Check if database export is already completed
            if ($this->is_database_export_complete($sql_file)) {
                $this->filesystem->log("Database export already completed, skipping duplicate export");
                return array(
                    'completed' => true,
                    'next_step' => 'create_metadata',
                    'message' => 'Database export already completed',
                    'params' => array_merge($params, array(
                        'database_completed' => true,
                        'tables_processed' => isset($params['total_tables']) ? $params['total_tables'] : 40,
                        'total_tables' => isset($params['total_tables']) ? $params['total_tables'] : 40,
                        'rows_exported' => isset($params['rows_exported']) ? $params['rows_exported'] : 0,
                        'bytes_written' => isset($params['bytes_written']) ? $params['bytes_written'] : 0
                    ))
                );
            }
            
            // Configure unified database exporter for fallback mode
            // Only override values that need to be different from defaults
            $config = array(
                'timeout' => 23,        // AJAX-friendly timeout (2s shorter than default 25s)
                // chunk_tables: 5 (use default - same performance as regular export)
            );
            
            // Create unified database exporter instance
            $db_exporter = new Custom_Migrator_Database_Exporter($config);
            
            // Prepare resume state if we have previous progress
            $resume_state = null;
            if (isset($params['tables_processed']) && $params['tables_processed'] > 0) {
                $resume_state = array(
                    'tables_processed' => (int)$params['tables_processed'],
                    'table_offset' => isset($params['table_offset']) ? (int)$params['table_offset'] : 0,
                    'total_tables' => isset($params['total_tables']) ? (int)$params['total_tables'] : 0,
                    'rows_exported' => isset($params['rows_exported']) ? (int)$params['rows_exported'] : 0,
                    'bytes_written' => isset($params['bytes_written']) ? (int)$params['bytes_written'] : 0,
                );
                $this->filesystem->log("Database export will resume with state: " . json_encode($resume_state));
            } else {
                $this->filesystem->log("Starting fresh database export (no resume state found)");
            }
            
            // Execute database export
            try {
                $result = $db_exporter->export($sql_file, $resume_state);
            } catch (Exception $db_exception) {
                $this->filesystem->log("Database export failed with exception: " . $db_exception->getMessage());
                
                // Return error response instead of throwing - this allows JavaScript to handle it properly
                return array(
                    'completed' => false,
                    'error' => true,
                    'next_step' => null,
                    'message' => 'Database export failed: ' . $db_exception->getMessage(),
                    'params' => $params
                );
            }
            
            $elapsed = round(microtime(true) - $start_time, 2);
            
            if ($result['completed']) {
                $this->filesystem->log("Database export completed in {$elapsed} seconds - {$result['total_tables']} tables, {$result['rows_exported']} rows");
                
                return array(
                    'completed' => true,
                    'next_step' => 'create_metadata',
                    'message' => 'Database exported successfully',
                    'params' => array_merge($params, array(
                        'database_completed' => true,
                        'tables_processed' => $result['tables_processed'],
                        'total_tables' => $result['total_tables'],
                        'rows_exported' => $result['rows_exported'],
                        'bytes_written' => $result['bytes_written']
                    ))
                );
            } else {
                $this->filesystem->log("Database export batch completed in {$elapsed} seconds - {$result['message']}");
                
                return array(
                    'completed' => false,
                    'next_step' => 'export_database',
                    'message' => $result['message'],
                    'pause_requested' => true,
                    'params' => array_merge($params, array(
                        'tables_processed' => $result['tables_processed'],
                        'table_offset' => isset($result['state']['table_offset']) ? $result['state']['table_offset'] : 0,
                        'total_tables' => $result['total_tables'],
                        'rows_exported' => $result['rows_exported'],
                        'bytes_written' => $result['bytes_written']
                    ))
                );
            }
            
        } catch (Exception $e) {
            $this->filesystem->log("Fallback database export error: " . $e->getMessage());
            $this->filesystem->log("Exception details: " . $e->getFile() . " line " . $e->getLine());
            $this->filesystem->log("Stack trace: " . $e->getTraceAsString());
            throw $e;
        }
    }

    /**
     * DEPRECATED: Old database export methods removed.
     * Now using unified Custom_Migrator_Database_Exporter class.
     */

    /**
     * Create metadata file (Step 5) - Use unified metadata generation directly
     */
    private function fallback_create_metadata($params) {
        $start_time = microtime(true);
        $this->filesystem->log("Fallback export: Creating metadata using unified metadata generator...");
        
        // Update status
        $this->filesystem->write_status('fallback_metadata');
        
        try {
            // Get metadata file path
            $file_paths = $this->filesystem->get_export_file_paths();
            $meta_file = $file_paths['metadata'];
            
            // Use unified metadata generation with fallback export configuration
            require_once dirname(__FILE__) . '/class-metadata.php';
            $metadata_generator = new Custom_Migrator_Metadata();
            
            $metadata_options = array(
                'file_format' => 'hstgr',
                'exporter_version' => CUSTOM_MIGRATOR_VERSION,
                'export_type' => 'fallback',
                'export_method' => 'ajax_chunked',
                'custom_fields' => array(
                    'files_processed' => isset($params['files_processed']) ? (int)$params['files_processed'] : 0,
                    'bytes_processed' => isset($params['bytes_processed']) ? (int)$params['bytes_processed'] : 0,
                    'tables_processed' => isset($params['tables_processed']) ? (int)$params['tables_processed'] : 0,
                    'rows_exported' => isset($params['rows_exported']) ? (int)$params['rows_exported'] : 0,
                )
            );
            
            $result = $metadata_generator->generate_and_save($meta_file, $metadata_options);
            
            if ($result) {
                $elapsed_time = microtime(true) - $start_time;
                $this->filesystem->log("Metadata created successfully in " . round($elapsed_time, 2) . " seconds");
                
                return array(
                    'completed' => true,
                    'next_step' => 'finalize',
                    'message' => 'Metadata created successfully',
                    'params' => array_merge($params, array(
                        'metadata_completed' => true,
                        'files_processed' => isset($params['files_processed']) ? $params['files_processed'] : 0,
                        'bytes_processed' => isset($params['bytes_processed']) ? $params['bytes_processed'] : 0
                    ))
                );
            } else {
                throw new Exception('Metadata generation failed');
            }
            
        } catch (Exception $e) {
            $this->filesystem->log("Fallback metadata creation error: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Finalize export (Step 6) - IDENTICAL validation to main export
     */
    private function fallback_finalize($params) {
        $this->filesystem->log("Fallback export: Finalizing with 99% completion validation...");
        
        // Update status
        $this->filesystem->write_status('fallback_finalizing');
        
        // Get file counts for validation
        $export_dir = $this->filesystem->get_export_dir();
        $content_list_path = $export_dir . '/content-list.csv';
        
        // CRITICAL: Final consistency check - ensure at least 99% of enumerated files were archived
        $total_files_enumerated = $this->count_lines_in_csv($content_list_path);
        $files_processed = isset($params['files_processed']) ? (int)$params['files_processed'] : 0;
        
        if ($total_files_enumerated > 0) {
            $completion_rate = $files_processed / $total_files_enumerated;
            
            if ($completion_rate < 0.99) {
                $missing = $total_files_enumerated - $files_processed;
                $error_msg = sprintf(
                    'Fallback export incomplete: Only %d of %d files exported (%.1f%%). Missing %d files.',
                    $files_processed,
                    $total_files_enumerated,
                    $completion_rate * 100,
                    $missing
                );
                
                $this->filesystem->write_status('error: ' . $error_msg);
                $this->filesystem->log('❌ FALLBACK EXPORT VALIDATION FAILED: ' . $error_msg);
                
                // Clean up temporary files before throwing error
                if (file_exists($content_list_path)) {
                    unlink($content_list_path);
                }
                
                throw new Exception($error_msg);
            } else {
                $this->filesystem->log(sprintf(
                    'Fallback export validation passed: %d of %d files (%.1f%% completion)',
                    $files_processed,
                    $total_files_enumerated,
                    $completion_rate * 100
                ));
            }
        }
        
        // Clean up temporary files
        if (file_exists($content_list_path)) {
            unlink($content_list_path);
        }
        
        // CRITICAL: Validate all required files exist before marking export as done
        $file_paths = $this->filesystem->get_export_file_paths();
        $missing_files = Custom_Migrator_Helper::validate_export_files($file_paths);
        if (!empty($missing_files)) {
            $error_msg = 'Fallback export incomplete - missing files: ' . implode(', ', $missing_files);
            $this->filesystem->write_status('error: ' . $error_msg);
            $this->filesystem->log('❌ FALLBACK EXPORT VALIDATION FAILED: ' . $error_msg);
            throw new Exception($error_msg);
        }
        
        // Update final status
        $this->filesystem->write_status('done');
        
        $this->filesystem->log("Fallback export completed successfully with validation!");
        
        return array(
            'completed' => true,
            'next_step' => null,
            'message' => 'Export completed successfully!',
            'final' => true,
            'params' => $params
        );
    }

    /**
     * Check if database export is already completed
     */
    private function is_database_export_complete($sql_file) {
        // Method 1: Check if the compressed file exists and has content
        if (file_exists($sql_file . '.gz') && filesize($sql_file . '.gz') > 1024) {
            $this->filesystem->log('Database export found (compressed): ' . basename($sql_file . '.gz') . ' (' . $this->format_bytes(filesize($sql_file . '.gz')) . ')');
            return true;
        }
        
        // Method 2: Check if the uncompressed file exists and is complete
        if (file_exists($sql_file) && filesize($sql_file) > 1024) {
            // Read last 1024 bytes to check for completion marker
            $handle = fopen($sql_file, 'r');
            if ($handle) {
                fseek($handle, -1024, SEEK_END);
                $tail = fread($handle, 1024);
                fclose($handle);
                
                // Look for SQL completion markers
                if (strpos($tail, '-- Export completed') !== false || 
                    strpos($tail, 'COMMIT;') !== false ||
                    strpos($tail, '/*!40101 SET') !== false) {
                    $this->filesystem->log('Database export found (uncompressed): ' . basename($sql_file) . ' (' . $this->format_bytes(filesize($sql_file)) . ')');
                    return true;
                }
            }
        }
        
        $this->filesystem->log('No completed database export found');
        return false;
    }
} 