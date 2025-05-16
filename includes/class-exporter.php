<?php
/**
 * The class responsible for exporting the website.
 *
 * @package CustomMigrator
 */

/**
 * Exporter class for WordPress website migration.
 */
class Custom_Migrator_Exporter {

    /**
     * The filesystem handler.
     *
     * @var Custom_Migrator_Filesystem
     */
    private $filesystem;

    /**
     * The database handler.
     *
     * @var Custom_Migrator_Database
     */
    private $database;

    /**
     * The metadata handler.
     *
     * @var Custom_Migrator_Metadata
     */
    private $metadata;

    /**
     * The file extension for exported content.
     * 
     * @var string
     */
    private $file_extension = 'hstgr';

    /**
     * Paths to exclude from export.
     * 
     * @var array
     */
    private $exclusion_paths = [];

    /**
     * Initialize the class.
     */
    public function __construct() {
        $this->filesystem = new Custom_Migrator_Filesystem();
        $this->database = new Custom_Migrator_Database();
        $this->metadata = new Custom_Migrator_Metadata();

        // Define exclusion paths
        $this->set_exclusion_paths();
    }

    /**
     * Set paths to be excluded from export.
     */
    private function set_exclusion_paths() {
        $wp_content_dir = WP_CONTENT_DIR;

        $this->exclusion_paths = [
            // Migration and backup directories
            $wp_content_dir . '/ai1wm-backups',
            $wp_content_dir . '/hostinger-migration-archives',
            $wp_content_dir . '/updraft',
            $wp_content_dir . '/backup',
            $wp_content_dir . '/backups',
            $wp_content_dir . '/vivid-migration-backups',
            $wp_content_dir . '/migration-backups',
            
            // Plugin-specific exclusions
            $wp_content_dir . '/plugins/custom-migrator',
            $wp_content_dir . '/plugins/all-in-one-migration',
            $wp_content_dir . '/plugins/updraftplus',
            
            // Cache directories
            $wp_content_dir . '/cache',
            $wp_content_dir . '/wp-cache',
            $wp_content_dir . '/et_cache',
            $wp_content_dir . '/w3tc',
            $wp_content_dir . '/wp-rocket-config',
            
            // Temporary directories
            $wp_content_dir . '/temp',
            $wp_content_dir . '/tmp'
        ];

        // Allow filtering of exclusion paths via a WordPress filter
        $this->exclusion_paths = apply_filters('custom_migrator_export_exclusion_paths', $this->exclusion_paths);
    }

    /**
     * Run the export process.
     * 
     * @return bool Whether the export completed successfully.
     */
    public function export() {
        // Get export paths with descriptive filenames
        $file_paths = $this->filesystem->get_export_file_paths();
        $hstgr_file = $file_paths['hstgr'];
        $meta_file = $file_paths['metadata'];
        $sql_file = $file_paths['sql'];

        // Check if we're resuming a previously paused export
        $resume_info_file = $this->filesystem->get_export_dir() . '/export-resume-info.json';
        $is_resuming = file_exists($resume_info_file);

        if ($is_resuming) {
            // Update status to resuming
            $this->filesystem->write_status('resuming');
            $this->filesystem->log('Resuming export process');
        } else {
            // Update status to exporting
            $this->filesystem->write_status('exporting');
            $this->filesystem->log('Starting export process');
        }

        try {
            // Step 1: Create metadata file (only if not resuming)
            if (!$is_resuming) {
                $this->filesystem->log('Generating metadata...');
                $meta = $this->metadata->generate();
                
                // Add export file info to metadata
                $meta['export_info']['file_format'] = $this->file_extension;
                $meta['export_info']['exporter_version'] = CUSTOM_MIGRATOR_VERSION;
                
                file_put_contents($meta_file, wp_json_encode($meta, JSON_PRETTY_PRINT));
                $this->filesystem->log('Metadata generated successfully');
            }

            // Step 2: Export database as plain SQL (not compressed) - only if not resuming
            if (!$is_resuming && !file_exists($sql_file) && !file_exists($sql_file . '.gz')) {
                $this->filesystem->log('Exporting database...');
                $this->database->export($sql_file);
                $this->filesystem->log('Database exported successfully to ' . basename($sql_file));
            } else if ($is_resuming) {
                $this->filesystem->log('Skipping database export as we are resuming content export');
            }

            // Step 3: Export wp-content files
            $this->filesystem->log('Exporting wp-content files...');
            $content_export_result = $this->export_wp_content($hstgr_file);
            
            // Check if export was paused - if so, return true to indicate successful pause
            if ($content_export_result === 'paused') {
                return true;
            }
            
            $this->filesystem->log('wp-content files exported successfully');

            // Update status to done
            $this->filesystem->write_status('done');
            $this->filesystem->log('Export completed successfully');
            
            return true;
        } catch (Exception $e) {
            // Update status to error with specific message
            $error_message = 'Export failed: ' . $e->getMessage();
            $this->filesystem->write_status('error: ' . $error_message);
            $this->filesystem->log($error_message);
            
            // Add additional debugging information if possible
            if (defined('WP_DEBUG') && WP_DEBUG) {
                $this->filesystem->log('Error trace: ' . $e->getTraceAsString());
            }
            
            return false;
        }
    }

    /**
     * Export WordPress content files to archive with resume capability.
     *
     * @param string $hstgr_file The path to the archive file.
     * @return string|bool True on success, 'paused' if export was paused, or throws an Exception.
     * @throws Exception If the export fails.
     */
    private function export_wp_content($hstgr_file) {
        $wp_content_dir = WP_CONTENT_DIR;
        
        // Check if this is a resume operation
        $resume_info_file = $this->filesystem->get_export_dir() . '/export-resume-info.json';
        $resume_data = array();
        $files_already_processed = 0;
        
        if (file_exists($resume_info_file)) {
            $resume_data = json_decode(file_get_contents($resume_info_file), true);
            $files_already_processed = isset($resume_data['files_processed']) ? (int)$resume_data['files_processed'] : 0;
            $skipped_count = isset($resume_data['skipped_count']) ? (int)$resume_data['skipped_count'] : 0;
            $bytes_processed = isset($resume_data['bytes_processed']) ? (int)$resume_data['bytes_processed'] : 0;
            $this->filesystem->log("Resuming export from file " . $files_already_processed);
        } else {
            $skipped_count = 0;
            $bytes_processed = 0;
        }
        
        // For a new export or if resuming an existing one
        $append_mode = $files_already_processed > 0 ? 'ab' : 'wb';
        
        // Open the archive file for writing
        $hstgr_fp = fopen($hstgr_file, $append_mode);
        if (!$hstgr_fp) {
            throw new Exception('Cannot create or open archive file');
        }

        // Create a recursive iterator for wp-content directory
        $directory_iterator = new RecursiveDirectoryIterator(
            $wp_content_dir, 
            RecursiveDirectoryIterator::SKIP_DOTS 
        );
        
        $iterator = new RecursiveIteratorIterator(
            $directory_iterator,
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        // Track the number of files processed
        $files_count = $files_already_processed;
        $start_time = microtime(true);
        
        // Only write header if this is a new export
        if ($files_already_processed === 0) {
            // Write archive header with format info
            $header = "# Hostinger Migration File\n";
            $header .= "# Format: HSTGR-1.0\n";
            $header .= "# Date: " . gmdate('Y-m-d H:i:s') . "\n";
            $header .= "# Generator: Hostinger Migrator v" . CUSTOM_MIGRATOR_VERSION . "\n\n";
            fwrite($hstgr_fp, $header);
        }

        // If resuming, we need to skip files we've already processed
        $current_file_index = 0;
        
        // Set a sensible time limit - process for at most 5 minutes then save state
        $max_execution_time = 300; // 5 minutes
        $execution_start = time();
        
        // Loop through each file
        foreach ($iterator as $file) {
            // Check if we need to skip this file (already processed in previous run)
            if ($current_file_index < $files_already_processed) {
                $current_file_index++;
                continue;
            }
            
            // Check if we're approaching the time limit
            if (time() - $execution_start > $max_execution_time) {
                // Save our progress and resume later
                $this->filesystem->log("Approaching time limit, saving state after processing $files_count files");
                
                // Save resume information
                $resume_data = array(
                    'files_processed' => $files_count,
                    'skipped_count' => $skipped_count,
                    'bytes_processed' => $bytes_processed,
                    'last_update' => time()
                );
                
                file_put_contents($resume_info_file, json_encode($resume_data));
                
                // Write current stats to the end of the file - these will be overwritten on resume
                $temp_stats = "\n__temp_stats__\n";
                $temp_stats .= "processed_files:" . $files_count . "\n";
                $temp_stats .= "skipped_files:" . $skipped_count . "\n";
                $temp_stats .= "processed_size:" . $bytes_processed . "\n";
                $temp_stats .= "resume:true\n";
                $temp_stats .= "__to_be_continued__\n";
                
                fwrite($hstgr_fp, $temp_stats);
                fclose($hstgr_fp);
                
                // Update the status to paused so the frontend knows
                $this->filesystem->write_status('paused');
                
                // Schedule the export to continue
                wp_schedule_single_event(time() + 5, 'cm_run_export');
                
                // Return paused status
                return 'paused';
            }
            
            $real_path = $file->getRealPath();
            
            // Check if the file is in any of the exclusion paths
            $is_excluded = false;
            foreach ($this->exclusion_paths as $excluded_path) {
                if (strpos($real_path, $excluded_path) === 0) {
                    $is_excluded = true;
                    $skipped_count++;
                    break;
                }
            }
            
            if ($is_excluded) {
                $current_file_index++;
                continue;
            }
            
            $files_count++;
            $current_file_index++;
            
            // Create the relative path within wp-content
            $relative_path = 'wp-content/' . substr($real_path, strlen($wp_content_dir) + 1);
            
            // Calculate file hash for integrity check
            $file_md5 = md5_file($real_path);
            $file_size = filesize($real_path);
            $bytes_processed += $file_size;
            
            // Log periodically to show progress
            if ($files_count % 1000 === 0) {
                $elapsed = microtime(true) - $start_time;
                $rate = $files_count / $elapsed;
                $bytes_rate = ($bytes_processed / $elapsed) / (1024 * 1024); // MB/s
                $this->filesystem->log(sprintf(
                    "Processed %d files (%.2f MB). Rate: %.2f files/sec (%.2f MB/s)",
                    $files_count,
                    $bytes_processed / (1024 * 1024),
                    $rate,
                    $bytes_rate
                ));
            }
            
            // Write file header with metadata
            $file_header = "__file__:" . $relative_path . "\n";
            $file_header .= "__size__:" . $file_size . "\n";
            $file_header .= "__md5__:" . $file_md5 . "\n";
            fwrite($hstgr_fp, $file_header);
            
            // Write file content using binary safe file operations
            $file_handle = fopen($real_path, 'rb');
            if ($file_handle) {
                // Use an optimized buffer size for better performance
                $buffer_size = 8192 * 16; // 128KB buffer
                while (!feof($file_handle)) {
                    $buffer = fread($file_handle, $buffer_size);
                    if ($buffer === false) {
                        // Handle read error
                        $this->filesystem->log("Error reading file: " . $real_path);
                        break;
                    }
                    
                    $write_result = fwrite($hstgr_fp, $buffer);
                    if ($write_result === false || $write_result != strlen($buffer)) {
                        // Handle write error
                        $this->filesystem->log("Error writing to archive: " . $real_path);
                        break;
                    }
                }
                fclose($file_handle);
            }
            
            // Write file footer
            fwrite($hstgr_fp, "\n__endfile__\n");
        }
        
        // If we get here, the export is complete
        // Delete resume info file if it exists
        if (file_exists($resume_info_file)) {
            @unlink($resume_info_file);
        }
        
        // Write archive footer with statistics
        $footer = "\n__stats__\n";
        $footer .= "total_files:" . $files_count . "\n";
        $footer .= "skipped_files:" . $skipped_count . "\n";
        $footer .= "total_size:" . $bytes_processed . "\n";
        $footer .= "export_time:" . round(microtime(true) - $start_time, 2) . "\n";
        $footer .= "__done__\n";
        
        fwrite($hstgr_fp, $footer);
        fclose($hstgr_fp);
        
        $total_time = microtime(true) - $start_time;
        $this->filesystem->log(sprintf(
            "Exported %d files (%.2f MB) in %.2f seconds, skipped %d files",
            $files_count,
            $bytes_processed / (1024 * 1024),
            $total_time,
            $skipped_count
        ));
        
        return true;
    }
}