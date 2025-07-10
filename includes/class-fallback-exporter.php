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
        
        // Reset fallback export state (simplified - no partial files)
        delete_option('cm_fallback_archive_offset');
        delete_option('cm_fallback_content_offset');
        
        $this->filesystem->log("Reset fallback export state: archive_offset=0, content_offset=0");
        
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
        $this->filesystem->log("Fallback export: Creating binary archive with time-based processing...");
        
        // CRITICAL: Check if export is already completed to prevent unnecessary processing
        $status_file = $this->filesystem->get_status_file_path();
        if (file_exists($status_file)) {
            $current_status = trim(file_get_contents($status_file));
            if ($current_status === 'done') {
                $this->filesystem->log("Export already completed (status: done), skipping archive creation step");
                return array(
                    'completed' => true,
                    'next_step' => 'export_database',
                    'message' => 'Export already completed',
                    'params' => $params
                );
            }
        }
        
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
        
        // Get resume parameters (simplified - no partial files)
        $files_processed = isset($params['files_processed']) ? (int)$params['files_processed'] : 0;
        $bytes_processed = isset($params['bytes_processed']) ? (int)$params['bytes_processed'] : 0;
        $csv_offset = isset($params['csv_offset']) ? (int)$params['csv_offset'] : 0;
        $total_files = isset($params['file_count']) ? (int)$params['file_count'] : $this->count_lines_in_csv($content_list_path);
        
        // Use 10-second timeout for LVE-friendly processing
        $timeout_seconds = apply_filters('custom_migrator_timeout', 10);
        $this->filesystem->log("10-second batch processing: timeout={$timeout_seconds}s, resuming from file {$files_processed}");
        
        // Initialize archive file if starting fresh
        if ($csv_offset === 0) {
            $archive_handle = fopen($archive_path, 'wb');
            if (!$archive_handle) {
                throw new Exception('Cannot create archive file');
            }
            fclose($archive_handle);
        }
        
        // Set up resume state (simplified - only 2 offsets)
        $current_archive_offset = (int)get_option('cm_fallback_archive_offset', 0);
        if ($csv_offset === 0) {
            $current_archive_offset = 0;
        }
        update_option('cm_fallback_archive_offset', $current_archive_offset);
        update_option('cm_fallback_content_offset', $csv_offset);
        
        $completed = true;
        $chunk_start_time = microtime(true);
        
        // Use LVE-safe processing pattern
        $result = $this->process_files_lve_safe($content_list_path, $archive_path, $chunk_start_time, $timeout_seconds);
        
        if (isset($result['error'])) {
            throw new Exception($result['error']);
        }
        
        $files_processed += $result['files_processed'];
        $bytes_processed += $result['bytes_written'];
        $completed = $result['is_complete'];
        
        // Update current positions for resume (simplified)
        $current_csv_offset = $result['content_offset'];
        
        $elapsed = microtime(true) - $start_time;
        $progress = $total_files > 0 ? round(($files_processed / $total_files) * 100, 1) : 100;
        
        if ($completed) {
            // Clean up resume options on successful completion
            delete_option('cm_fallback_archive_offset');
            delete_option('cm_fallback_content_offset');
            
            $this->filesystem->log("Archive completed: $files_processed files (" . 
                                 $this->format_bytes($bytes_processed) . ") in " . 
                                 round($elapsed, 1) . " seconds");
            
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
            $this->filesystem->log("Batch completed: {$files_processed}/{$total_files} files ({$progress}%)");
            
            return array(
                'completed' => false,
                'next_step' => 'create_archive',
                'message' => "Archiving in progress: {$files_processed}/{$total_files} files ({$progress}%)",
                'files_processed' => $files_processed,
                'bytes_processed' => $bytes_processed,
                'pause_requested' => true,
                'params' => array_merge($params, array(
                    'files_processed' => $files_processed,
                    'bytes_processed' => $bytes_processed,
                    'csv_offset' => $current_csv_offset,
                    'file_count' => $total_files
                ))
            );
        }
    }



    /**
     * Export database (Step 4) - Chunked processing with timeout protection
     */
    private function fallback_export_database($params) {
        $start_time = microtime(true);
        $this->filesystem->log("Fallback export: Creating database export with unified database exporter...");
        
        // CRITICAL: Check if export is already completed to prevent unnecessary processing
        $status_file = $this->filesystem->get_status_file_path();
        if (file_exists($status_file)) {
            $current_status = trim(file_get_contents($status_file));
            if ($current_status === 'done') {
                $this->filesystem->log("Export already completed (status: done), skipping database export step");
                return array(
                    'completed' => true,
                    'next_step' => 'create_metadata',
                    'message' => 'Export already completed',
                    'params' => $params
                );
            }
        }
        
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
                    'temp_file_path' => isset($params['temp_file_path']) ? $params['temp_file_path'] : null,
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
                        'bytes_written' => $result['bytes_written'],
                        'temp_file_path' => isset($result['state']['temp_file_path']) ? $result['state']['temp_file_path'] : null
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
        
        // CRITICAL: Check if export is already completed to prevent unnecessary processing
        $status_file = $this->filesystem->get_status_file_path();
        if (file_exists($status_file)) {
            $current_status = trim(file_get_contents($status_file));
            if ($current_status === 'done') {
                $this->filesystem->log("Export already completed (status: done), skipping metadata creation step");
                return array(
                    'completed' => true,
                    'next_step' => 'finalize',
                    'message' => 'Export already completed',
                    'params' => $params
                );
            }
        }
        
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
        
        // CRITICAL: Check if export is already completed to prevent infinite loops
        $status_file = $this->filesystem->get_status_file_path();
        if (file_exists($status_file)) {
            $current_status = trim(file_get_contents($status_file));
            if ($current_status === 'done') {
                $this->filesystem->log("Export already completed (status: done), skipping finalize step");
                return array(
                    'completed' => true,
                    'next_step' => null,
                    'message' => 'Export already completed!',
                    'final' => true,
                    'params' => $params
                );
            }
        }
        
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
        
        // Clear performance data on successful export to prevent sticking in "slow" mode
        $this->clear_performance_data_on_success();
        
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
        // CRITICAL FIX: Handle both .sql and .sql.gz extensions properly
        
        // If sql_file already has .gz extension, check for that file directly
        if (substr($sql_file, -3) === '.gz') {
            $compressed_file = $sql_file;
            $uncompressed_file = substr($sql_file, 0, -3); // Remove .gz
        } else {
            $compressed_file = $sql_file . '.gz';
            $uncompressed_file = $sql_file;
        }
        
        // Method 1: Check if the compressed file exists and has content
        if (file_exists($compressed_file) && filesize($compressed_file) > 1024) {
            $this->filesystem->log('Database export found (compressed): ' . basename($compressed_file) . ' (' . $this->format_bytes(filesize($compressed_file)) . ')');
            return true;
        }
        
        // Method 2: Check if the uncompressed file exists and is complete
        if (file_exists($uncompressed_file) && filesize($uncompressed_file) > 1024) {
            // Read last 1024 bytes to check for completion marker
            $handle = fopen($uncompressed_file, 'r');
            if ($handle) {
                fseek($handle, -1024, SEEK_END);
                $tail = fread($handle, 1024);
                fclose($handle);
                
                // Look for SQL completion markers
                if (strpos($tail, '-- Export completed') !== false || 
                    strpos($tail, 'COMMIT;') !== false ||
                    strpos($tail, '/*!40101 SET') !== false) {
                    $this->filesystem->log('Database export found (uncompressed): ' . basename($uncompressed_file) . ' (' . $this->format_bytes(filesize($uncompressed_file)) . ')');
                    return true;
                }
            }
        }
        
        $this->filesystem->log('No completed database export found');
        return false;
    }
    
    /**
     * DEPRECATED: Adaptive batch sizing methods removed.
     * Now using time-based processing (10-second timeout) like regular export method.
     */

    /**
     * Process files using optimized 10-second time-based approach (complete files only)
     */
    private function process_files_lve_safe($csv_file, $archive_file, $start_time, $timeout_seconds) {
        // Simplified state management - only 2 offsets needed (no partial files)
        $archive_bytes_offset = (int)get_option('cm_fallback_archive_offset', 0);
        $content_bytes_offset = (int)get_option('cm_fallback_content_offset', 0);
        
        $files_processed = 0;
        $processed_files_size = 0;
        $completed = true;
        
        // Open CSV file and seek to position
        $content_list = fopen($csv_file, 'r');
        if (!$content_list) {
            return array('error' => 'Cannot open CSV file for reading');
        }
        
        if (fseek($content_list, $content_bytes_offset) !== 0) {
            fclose($content_list);
            return array('error' => 'Cannot seek to CSV position');
        }
        
        // Open archive file (create if doesn't exist)
        $archive_handle = fopen($archive_file, file_exists($archive_file) ? 'r+b' : 'w+b');
        if (!$archive_handle) {
            fclose($content_list);
            return array('error' => 'Cannot open archive file');
        }
        
        if (fseek($archive_handle, $archive_bytes_offset) !== 0) {
            fclose($content_list);
            fclose($archive_handle);
            return array('error' => 'Cannot seek to archive position');
        }
        
        $this->filesystem->log("Starting batch: archive_offset=$archive_bytes_offset, content_offset=$content_bytes_offset");
        
        // Process files with 10-second timeout (complete files only)
        while (($csv_data = fgetcsv($content_list)) !== false) {
            if (count($csv_data) < 4) continue;
            
            list($file_abspath, $file_relpath, $file_size, $file_mtime) = $csv_data;
            $file_size = (int)$file_size;
            $file_mtime = (int)$file_mtime;
            
            // Validate file data
            if (empty($file_abspath) || $file_size < 0 || $file_mtime <= 0) {
                $content_bytes_offset = ftell($content_list);
                continue;
            }
            
            // Check if file exists and is readable
            if (!file_exists($file_abspath) || !is_readable($file_abspath)) {
                $content_bytes_offset = ftell($content_list);
                $this->filesystem->log("Skipping unreadable file: " . basename($file_abspath));
                continue;
            }
            
            // Check timeout before starting next file (but let current file complete)
            if ((microtime(true) - $start_time) > $timeout_seconds && $files_processed > 0) {
                $completed = false;
                $this->filesystem->log("Timeout reached after $files_processed files");
                break;
            }
            
            // Process complete file only (no partial processing)
            $file_bytes_written = 0;
            $file_completed = $this->add_complete_file_to_archive(
                $archive_handle, 
                $file_abspath, 
                $file_relpath, 
                $file_size, 
                $file_mtime, 
                $file_bytes_written
            );
            
            if ($file_completed) {
                // File completed successfully
                $files_processed++;
                $processed_files_size += $file_bytes_written;
                $content_bytes_offset = ftell($content_list);
                $archive_bytes_offset = ftell($archive_handle);
                
                // Log progress every 50 files
                if ($files_processed % 50 === 0) {
                    $elapsed = round(microtime(true) - $start_time, 1);
                    $this->filesystem->log("Progress: $files_processed files in {$elapsed}s (" . 
                                         $this->format_bytes($processed_files_size) . ")");
                }
            } else {
                // File failed - log and continue to next file
                $this->filesystem->log("ERROR: Failed to process " . basename($file_abspath));
                $content_bytes_offset = ftell($content_list);
                continue;
            }
        }
        
        fclose($content_list);
        fclose($archive_handle);
        
        // Save state for next batch (simplified - only 2 offsets)
        update_option('cm_fallback_archive_offset', $archive_bytes_offset);
        update_option('cm_fallback_content_offset', $content_bytes_offset);
        
        // Check if we've processed all files
        if ($completed) {
            $content_list = fopen($csv_file, 'r');
            fseek($content_list, $content_bytes_offset);
            $is_complete = (fgetcsv($content_list) === false);
            fclose($content_list);
        } else {
            $is_complete = false;
        }
        
        $elapsed = round(microtime(true) - $start_time, 1);
        $this->filesystem->log("Batch completed: $files_processed files in {$elapsed}s");
        
        return array(
            'files_processed' => $files_processed,
            'bytes_written' => $processed_files_size,
            'is_complete' => $is_complete,
            'archive_offset' => $archive_bytes_offset,
            'content_offset' => $content_bytes_offset
        );
    }
    
    /**
     * Add complete file to archive (no partial processing) - LVE optimized
     */
    private function add_complete_file_to_archive($archive_handle, $file_path, $file_relpath, $file_size, $file_mtime, &$bytes_written) {
        $bytes_written = 0;
        
        try {
            // Create binary header using CSV data for consistency
            $filename = basename($file_path);
            $file_dir = dirname($file_relpath);
            
            // CRITICAL FIX: Ensure file_size and file_mtime are integers, not strings from CSV
            $file_size = (int)$file_size;
            $file_mtime = (int)$file_mtime;
            
            // Validate the data before creating binary block
            if ($file_size < 0) {
                $this->filesystem->log("ERROR: Invalid file size for " . basename($file_path) . ": $file_size");
                return false;
            }
            
            if ($file_mtime <= 0) {
                $this->filesystem->log("ERROR: Invalid modification time for " . basename($file_path) . ": $file_mtime");
                return false;
            }
            
            // CRITICAL FIX: Try to open the file FIRST before writing any headers
            $file_handle = fopen($file_path, 'rb');
            if (!$file_handle) {
                $this->filesystem->log("ERROR: Cannot open file for reading: " . basename($file_path));
                return false;
            }
            
            $block = Custom_Migrator_Helper::create_binary_block($filename, $file_size, $file_mtime, $file_dir);
            
            // Write header
            if (fwrite($archive_handle, $block) === false) {
                fclose($file_handle);
                return false;
            }
            
            // Copy file content in 256KB chunks (LVE-friendly I/O)
            $chunk_size = 256 * 1024; // 256KB chunks for optimal LVE performance
            while (!feof($file_handle)) {
                $chunk = fread($file_handle, $chunk_size);
                if ($chunk === false || fwrite($archive_handle, $chunk) === false) {
                    fclose($file_handle);
                    return false;
                }
                $bytes_written += strlen($chunk);
            }
            
            fclose($file_handle);
            return true;
            
        } catch (Exception $e) {
            $this->filesystem->log("ERROR adding file: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * DEPRECATED: Old partial file processing method - replaced with complete file processing
     * Add file to archive with proper partial file processing
     * UPDATED: Now uses All-in-One WP Migration's exact approach to prevent corruption
     */
    private function add_file_binary_format($archive_handle, $file_name, $file_relpath, &$file_written, &$file_offset, $start_time, $timeout_seconds, $file_mtime = null, $csv_file_size = null) {
        $file_written = 0;
        
        // Get file info - use CSV data for consistency
        $stat = stat($file_name);
        if ($stat === false) {
            $this->filesystem->log("ERROR: Cannot stat file: " . basename($file_name));
            return false;
        }
        
        // CRITICAL FIX: Use CSV file size instead of stat() to prevent corruption
        $file_size = $csv_file_size !== null ? $csv_file_size : $stat['size'];
        
        // Log file size comparison for debugging
        if ($csv_file_size !== null && $csv_file_size !== $stat['size']) {
            $this->filesystem->log("WARNING: File size mismatch for " . basename($file_name) . " - CSV: $csv_file_size, STAT: {$stat['size']}");
        }
        
        // CRITICAL FIX: Use modification time from CSV file instead of stat() to prevent corruption
        $file_date = $file_mtime !== null ? $file_mtime : $stat['mtime'];
        
        // Validate modification time
        if ($file_date <= 0) {
            $this->filesystem->log("ERROR: Invalid modification time for file: " . basename($file_name) . " (mtime: $file_date)");
            return false;
        }
        
        // Open the file for reading
        $file_handle = fopen($file_name, 'rb');
        if (!$file_handle) {
            $this->filesystem->log("ERROR: Cannot open file for reading: " . basename($file_name));
            return false;
        }
        
        $filename = basename($file_name);
        $file_dir = dirname($file_relpath);
        $completed = true;
        
        try {
            // Write full header with real file size immediately
            if ($file_offset === 0) {
                // CRITICAL VALIDATION: Ensure all header data is valid before creating binary block
                if (empty($filename) || strlen($filename) > 255) {
                    $this->filesystem->log("ERROR: Invalid filename for binary block: " . basename($file_name));
                    fclose($file_handle);
                    return false;
                }
                
                if ($file_size < 0 || $file_size > PHP_INT_MAX) {
                    $this->filesystem->log("ERROR: Invalid file size for binary block: " . basename($file_name) . " (size: $file_size)");
                    fclose($file_handle);
                    return false;
                }
                
                // Create binary block
                $block = Custom_Migrator_Helper::create_binary_block($filename, $file_size, $file_date, $file_dir);
                $expected_size = Custom_Migrator_Helper::get_binary_block_format()['size'];
                
                // CRITICAL VALIDATION: Ensure block was created properly
                if (strlen($block) !== $expected_size) {
                    $this->filesystem->log("ERROR: Binary block size mismatch for: " . basename($file_name) . " (expected: $expected_size, got: " . strlen($block) . ")");
                    fclose($file_handle);
                    return false;
                }
                
                // Write the full header block and flush immediately
                $header_bytes = fwrite($archive_handle, $block);
                if ($header_bytes === false || $header_bytes !== $expected_size) {
                    $this->filesystem->log("ERROR: Failed to write header for: " . basename($file_name));
                    fclose($file_handle);
                    return false;
                }
                fflush($archive_handle);  // Force flush after header
            }
            
            // Seek to the resume position in source file
            if (fseek($file_handle, $file_offset, SEEK_SET) !== 0) {
                $this->filesystem->log("ERROR: Failed to seek to offset $file_offset in: " . basename($file_name));
                fclose($file_handle);
                return false;
            }
            
            // Get current archive position (main loop handles positioning)
            $archive_pos = ftell($archive_handle);
            if ($archive_pos === false) {
                $this->filesystem->log("ERROR: Cannot get archive position for: " . basename($file_name));
                fclose($file_handle);
                return false;
            }
            
            // Read and write file content in chunks with bounds checking
            $chunks_processed = 0;
            $total_bytes_to_write = $file_size - $file_offset;  // Remaining bytes to write
            $bytes_written_this_session = 0;
            
            while (!feof($file_handle) && $bytes_written_this_session < $total_bytes_to_write) {
                // Check timeout BEFORE reading chunk
                $elapsed = microtime(true) - $start_time;
                if ($elapsed > $timeout_seconds) {
                    $completed = false;
                    break;
                }
                
                // Calculate chunk size - don't read more than what's left in the file
                $remaining_bytes = $total_bytes_to_write - $bytes_written_this_session;
                $chunk_size = min(512000, $remaining_bytes);
                
                if ($chunk_size <= 0) {
                    break;  // No more bytes to write
                }
                
                // Read chunk
                $file_content = fread($file_handle, $chunk_size);
                if ($file_content === false) {
                    $this->filesystem->log("ERROR: Failed to read chunk from: " . basename($file_name));
                    fclose($file_handle);
                    return false;
                }
                
                $chunk_length = strlen($file_content);
                if ($chunk_length === 0) {
                    break;  // End of file
                }
                
                // CRITICAL SAFETY CHECK: Ensure we don't exceed file boundaries
                if ($bytes_written_this_session + $chunk_length > $total_bytes_to_write) {
                    $allowed_bytes = $total_bytes_to_write - $bytes_written_this_session;
                    $file_content = substr($file_content, 0, $allowed_bytes);
                    $chunk_length = strlen($file_content);
                    $this->filesystem->log("WARNING: Truncated chunk for: " . basename($file_name) . " to prevent overflow");
                }
                
                // Verify archive position before write
                $current_pos = ftell($archive_handle);
                if ($current_pos !== $archive_pos) {
                    $this->filesystem->log("ERROR: Archive position mismatch for: " . basename($file_name) . " - expected: $archive_pos, actual: $current_pos");
                    fclose($file_handle);
                    return false;
                }
                
                // Write chunk to archive
                $file_bytes = fwrite($archive_handle, $file_content);
                if ($file_bytes === false || $file_bytes !== $chunk_length) {
                    $this->filesystem->log("ERROR: Failed to write chunk for: " . basename($file_name) . " - wrote: $file_bytes, expected: $chunk_length");
                    fclose($file_handle);
                    return false;
                }
                
                // Update positions and flush
                $file_written += $file_bytes;
                $file_offset += $file_bytes;
                $archive_pos += $file_bytes;
                $bytes_written_this_session += $file_bytes;
                fflush($archive_handle);  // Force flush after each chunk
                
                $chunks_processed++;
                
                // Final safety check - ensure we don't exceed file size
                if ($file_written > $file_size) {
                    $this->filesystem->log("CRITICAL ERROR: File overflow detected for: " . basename($file_name) . " - written: $file_written, max: $file_size");
                    fclose($file_handle);
                    return false;
                }
            }
        } finally {
            fclose($file_handle);
        }
        
        return $completed;
    }
} 