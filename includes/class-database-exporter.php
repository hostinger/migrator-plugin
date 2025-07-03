<?php
/**
 * Unified Database Exporter
 *
 * Combines best practices from both regular and fallback database export methods.
 * Features: chunked processing, smart resume, optimized queries, comprehensive error handling.
 *
 * @package CustomMigrator
 */

/**
 * Unified Database Exporter class.
 */
class Custom_Migrator_Database_Exporter {

    /**
     * The filesystem handler.
     *
     * @var Custom_Migrator_Filesystem
     */
    private $filesystem;

    /**
     * Export configuration.
     *
     * @var array
     */
    private $config;

    /**
     * Current export state.
     *
     * @var array
     */
    private $state;

    /**
     * Database connection.
     *
     * @var mysqli
     */
    private $mysqli;

    /**
     * Current temp file path for export.
     *
     * @var string
     */
    private $temp_file_path;

    /**
     * Initialize the class.
     *
     * @param array $config Export configuration.
     */
    public function __construct($config = array()) {
        $this->filesystem = new Custom_Migrator_Filesystem();
        
        // Default configuration with best practices
        $this->config = array_merge(array(
            'timeout' => 25,           // Max execution time per chunk (seconds)
            'batch_size' => 500,       // Rows per batch
            'transaction_size' => 100, // INSERTs per transaction
            'memory_limit' => '256M',  // Memory limit for export
            'compression' => 'auto',   // auto, gzip, none
            'resume' => true,          // Enable resume capability
            'charset' => 'utf8mb4',    // Database charset
            'chunk_tables' => 5,       // Max tables per chunk
        ), $config);

        $this->state = array(
            'tables_processed' => 0,
            'current_table' => null,
            'table_offset' => 0,
            'total_tables' => 0,
            'start_time' => 0,
            'bytes_written' => 0,
            'rows_exported' => 0,
            'all_tables' => array(), // Cache for table list to avoid repeated queries
        );
    }

    /**
     * Export database to SQL file.
     *
     * @param string $sql_file Output file path.
     * @param array  $resume_state Optional resume state.
     * @return array Export result with state information.
     * @throws Exception If export fails.
     */
    public function export($sql_file, $resume_state = null) {
        $this->state['start_time'] = microtime(true);
        
        // Load resume state if provided and resume is enabled
        if ($resume_state && $this->config['resume']) {
            $this->state = array_merge($this->state, $resume_state);
            $this->filesystem->log("Resuming database export from table {$this->state['tables_processed']}");
        } else {
            if ($resume_state && !$this->config['resume']) {
                $this->filesystem->log("Resume state provided but resume disabled - starting fresh database export");
            } else {
                $this->filesystem->log("Starting fresh database export");
            }
        }

        try {
            // Initialize database connection
            $this->initialize_connection();
            
            // Setup output file
            $output_file = $this->setup_output_file($sql_file);
            
            // Process tables in chunks
            $result = $this->process_tables_chunked($output_file);
            
            // Finalize export
            if ($result['completed']) {
                $this->finalize_export($output_file, $sql_file);
            }
            
            return $result;
            
        } catch (Exception $e) {
            $this->cleanup_on_error();
            throw $e;
        } finally {
            $this->cleanup_connection();
        }
    }

    /**
     * Initialize database connection with optimal settings.
     */
    private function initialize_connection() {
        $this->mysqli = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
        
        if ($this->mysqli->connect_error) {
            throw new Exception('Database connection failed: ' . $this->mysqli->connect_error);
        }

        // Set optimal connection parameters
        $this->mysqli->set_charset($this->config['charset']);
        
        // Comprehensive charset and encoding setup
        $charset_queries = array(
            "SET NAMES {$this->config['charset']} COLLATE {$this->config['charset']}_unicode_ci",
            "SET CHARACTER_SET_CLIENT = {$this->config['charset']}",
            "SET CHARACTER_SET_RESULTS = {$this->config['charset']}",
            "SET CHARACTER_SET_CONNECTION = {$this->config['charset']}",
            "SET SESSION sql_mode = ''",
            "SET SESSION wait_timeout = 300",
            "SET SESSION interactive_timeout = 300",
        );

        foreach ($charset_queries as $query) {
            $this->mysqli->query($query);
        }

        // Log connection details
        $charset_result = $this->mysqli->query("SELECT @@character_set_connection");
        if ($charset_result) {
            $charset_row = $charset_result->fetch_array();
            $this->filesystem->log("Database connection established with charset: " . $charset_row[0]);
            $charset_result->free();
        }
    }

    /**
     * Setup output file for writing.
     *
     * @param string $sql_file Target SQL file path.
     * @return resource File handle.
     */
    private function setup_output_file($sql_file) {
        // Determine if we're resuming or starting fresh
        $is_resume = ($this->state['tables_processed'] > 0);
        $mode = $is_resume ? 'a' : 'w';
        
        // For compression, we'll use temp file approach
        // Use consistent temp file path (deterministic naming)
        $this->temp_file_path = $this->get_temp_file_path($sql_file);
        $this->state['temp_file_path'] = $this->temp_file_path;
        
        // ENHANCED: Validate directory and permissions before attempting file creation
        $temp_dir = dirname($this->temp_file_path);
        if (!is_dir($temp_dir)) {
            $this->filesystem->log("Creating export directory: $temp_dir");
            if (!wp_mkdir_p($temp_dir)) {
                throw new Exception('Cannot create export directory: ' . $temp_dir);
            }
        }
        
        if (!is_writable($temp_dir)) {
            throw new Exception('Export directory is not writable: ' . $temp_dir . ' (permissions: ' . substr(sprintf('%o', fileperms($temp_dir)), -4) . ')');
        }
        
        // Clean up any old temp files if starting fresh (not resume)
        if (!$is_resume) {
            $this->cleanup_old_temp_files($temp_dir);
        }
        
        $handle = fopen($this->temp_file_path, $mode);
        if (!$handle) {
            $error = error_get_last();
            $error_msg = $error ? $error['message'] : 'Unknown error';
            throw new Exception('Cannot create SQL output file: ' . $this->temp_file_path . ' (Error: ' . $error_msg . ')');
        }

        // Write header only if starting fresh
        if (!$is_resume) {
            $this->write_sql_header($handle);
        }

        return $handle;
    }

    /**
     * Write SQL file header with metadata.
     *
     * @param resource $handle File handle.
     */
    private function write_sql_header($handle) {
        $header = array(
            "-- WordPress Database Export",
            "-- Generated by Custom Migrator Unified Database Exporter",
            "-- Date: " . gmdate('Y-m-d H:i:s') . " GMT",
            "-- Host: " . DB_HOST,
            "-- Database: " . DB_NAME,
            "-- Export Charset: {$this->config['charset']}",
            "-- WordPress Charset: " . get_option('blog_charset', 'UTF-8'),
            "",
            "/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;",
            "/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;",
            "/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;",
            "/*!40101 SET NAMES {$this->config['charset']} */;",
            "/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;",
            "/*!40103 SET TIME_ZONE='+00:00' */;",
            "/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;",
            "/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;",
            "/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;",
            "/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;",
            "",
            "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";",
            "SET AUTOCOMMIT = 0;",
            "START TRANSACTION;",
            "",
        );

        foreach ($header as $line) {
            fwrite($handle, $line . "\n");
        }
    }

    /**
     * Process tables in chunks with timeout protection.
     *
     * @param resource $output_handle Output file handle.
     * @return array Processing result.
     */
    private function process_tables_chunked($output_handle) {
        // Get all tables if not already loaded
        if ($this->state['total_tables'] === 0) {
            $tables = $this->get_all_tables();
            $this->state['total_tables'] = count($tables);
        } else {
            $tables = $this->get_all_tables();
        }

        $chunk_start_time = microtime(true);
        $tables_processed_in_chunk = 0;

        // Process tables starting from where we left off
        for ($i = $this->state['tables_processed']; $i < $this->state['total_tables']; $i++) {
            $table = $tables[$i];
            $this->state['current_table'] = $table;

            // Export table structure and data
            $table_result = $this->export_table_complete($output_handle, $table);
            
            $this->state['tables_processed']++;
            $this->state['rows_exported'] += $table_result['rows'];
            $this->state['bytes_written'] += $table_result['bytes'];
            $tables_processed_in_chunk++;

            // Check timeout (leave buffer for cleanup)
            $elapsed = microtime(true) - $chunk_start_time;
            if ($elapsed > ($this->config['timeout'] - 2)) {
                $this->filesystem->log("Chunk timeout reached after {$elapsed}s, processed {$tables_processed_in_chunk} tables");
                break;
            }

            // Check chunk size limit
            if ($tables_processed_in_chunk >= $this->config['chunk_tables']) {
                $this->filesystem->log("Chunk size limit reached, processed {$tables_processed_in_chunk} tables");
                break;
            }

            // Small pause to prevent overwhelming
            usleep(5000); // 0.005 seconds
        }

        $total_elapsed = microtime(true) - $this->state['start_time'];
        $chunk_elapsed = microtime(true) - $chunk_start_time;

        // Determine if export is complete
        $completed = ($this->state['tables_processed'] >= $this->state['total_tables']);

        $result = array(
            'completed' => $completed,
            'tables_processed' => $this->state['tables_processed'],
            'total_tables' => $this->state['total_tables'],
            'rows_exported' => $this->state['rows_exported'],
            'bytes_written' => $this->state['bytes_written'],
            'chunk_time' => round($chunk_elapsed, 2),
            'total_time' => round($total_elapsed, 2),
            'state' => $this->state,
        );

        if ($completed) {
            $this->filesystem->log("Database export completed: {$this->state['total_tables']} tables, {$this->state['rows_exported']} rows in {$total_elapsed}s");
            $result['message'] = "Database export completed successfully";
        } else {
            $progress = round(($this->state['tables_processed'] / $this->state['total_tables']) * 100, 1);
            $result['message'] = "Database export progress: {$this->state['tables_processed']}/{$this->state['total_tables']} tables ({$progress}%)";
            $this->filesystem->log($result['message'] . " - chunk time: {$chunk_elapsed}s");
        }

        return $result;
    }

    /**
     * Export complete table (structure + data) with optimizations.
     *
     * @param resource $output_handle Output file handle.
     * @param string   $table Table name.
     * @return array Export statistics.
     */
    private function export_table_complete($output_handle, $table) {
        $table_start_time = microtime(true);
        $bytes_written = 0;
        $rows_exported = 0;

        // Export table structure
        $bytes_written += $this->export_table_structure($output_handle, $table);

        // Export table data with optimization
        $data_result = $this->export_table_data_optimized($output_handle, $table);
        $bytes_written += $data_result['bytes'];
        $rows_exported += $data_result['rows'];

        $table_time = microtime(true) - $table_start_time;
        
        // Log progress for large tables
        if ($rows_exported > 1000 || $table_time > 1) {
            $this->filesystem->log("Table `{$table}`: {$rows_exported} rows, " . 
                                 $this->format_bytes($bytes_written) . " in " . 
                                 round($table_time, 2) . "s");
        }

        return array(
            'rows' => $rows_exported,
            'bytes' => $bytes_written,
            'time' => $table_time
        );
    }

    /**
     * Export table structure with collation compatibility fixes.
     *
     * @param resource $output_handle Output file handle.
     * @param string   $table Table name.
     * @return int Bytes written.
     */
    private function export_table_structure($output_handle, $table) {
        $result = $this->mysqli->query("SHOW CREATE TABLE `{$table}`");
        if (!$result) {
            $this->filesystem->log("Warning: Could not get CREATE TABLE for `{$table}`: " . $this->mysqli->error);
            return 0;
        }

        $row = $result->fetch_array();
        if (!$row) {
            $result->free();
            return 0;
        }

        $create_table = $row[1];
        $result->free();

        // Apply collation compatibility fixes for different MySQL versions.
        // Based on All-in-One WP Migration's intelligent approach using WordPress database capabilities.
        $create_table = $this->fix_collation_compatibility($create_table);

        $structure_sql = array(
            "",
            "-- --------------------------------------------------------",
            "-- Table structure for table `{$table}`",
            "-- --------------------------------------------------------",
            "",
            "DROP TABLE IF EXISTS `{$table}`;",
            $create_table . ";",
            "",
        );

        $bytes_written = 0;
        foreach ($structure_sql as $line) {
            $bytes = fwrite($output_handle, $line . "\n");
            $bytes_written += $bytes;
        }

        return $bytes_written;
    }

    /**
     * Fix collation compatibility issues for different MySQL versions.
     * Based on All-in-One WP Migration's intelligent approach using WordPress database capabilities.
     *
     * @param string $create_table The CREATE TABLE statement.
     * @return string Fixed CREATE TABLE statement.
     */
    private function fix_collation_compatibility($create_table) {
        global $wpdb;
        
        $original_create_table = $create_table;
        
        // Use WordPress database capability detection (like AI1WM)
        static $search = array();
        static $replace = array();
        
        // Initialize collation mappings based on target database capabilities
        if (empty($search) || empty($replace)) {
            if (!$wpdb->has_cap('utf8mb4_520')) {
                if (!$wpdb->has_cap('utf8mb4')) {
                    // MySQL < 5.5 (no UTF8MB4 support at all) - matches AI1WM exactly
                    $search = array('utf8mb4_0900_ai_ci', 'utf8mb4_unicode_520_ci', 'utf8mb4');
                    $replace = array('utf8_unicode_ci', 'utf8_unicode_ci', 'utf8');
                    $this->filesystem->log("Collation compatibility: Targeting MySQL < 5.5 (no UTF8MB4 support)");
                } else {
                    // MySQL 5.5-5.6 (has UTF8MB4 but no 520 collation) - matches AI1WM exactly
                    $search = array('utf8mb4_0900_ai_ci', 'utf8mb4_unicode_520_ci');
                    $replace = array('utf8mb4_unicode_ci', 'utf8mb4_unicode_ci');
                    $this->filesystem->log("Collation compatibility: Targeting MySQL 5.5-5.6 (UTF8MB4 without 520 collation)");
                }
            } else {
                // MySQL 5.7+ (has UTF8MB4_520 support, but not 0900)
                $search = array('utf8mb4_0900_ai_ci');
                $replace = array('utf8mb4_unicode_520_ci');
                $this->filesystem->log("Collation compatibility: Targeting MySQL 5.7+ (UTF8MB4_520 support)");
            }
        }

        // Apply collation replacements
        $replacements_made = array();
        foreach ($search as $index => $old_collation) {
            if (strpos($create_table, $old_collation) !== false) {
                $new_collation = $replace[$index];
                $create_table = str_replace($old_collation, $new_collation, $create_table);
                $replacements_made[] = "$old_collation → $new_collation";
            }
        }

        // Log replacements made
        if (!empty($replacements_made)) {
            $this->filesystem->log("Collation compatibility fixes applied: " . implode(', ', $replacements_made));
        }

        return $create_table;
    }

    /**
     * Export table data with advanced optimizations.
     *
     * @param resource $output_handle Output file handle.
     * @param string   $table Table name.
     * @return array Export statistics.
     */
    private function export_table_data_optimized($output_handle, $table) {
        // Get row count
        $count_result = $this->mysqli->query("SELECT COUNT(*) FROM `{$table}`");
        if (!$count_result) {
            return array('rows' => 0, 'bytes' => 0);
        }

        $count_row = $count_result->fetch_array();
        $total_rows = (int)$count_row[0];
        $count_result->free();

        if ($total_rows === 0) {
            return array('rows' => 0, 'bytes' => 0);
        }

        // Get table info for optimization
        $table_info = $this->get_table_info($table);
        $primary_keys = $table_info['primary_keys'];
        $columns = $table_info['columns'];

        $bytes_written = 0;
        $rows_exported = 0;
        $offset = 0;

        // Write data header
        $data_header = array(
            "-- --------------------------------------------------------",
            "-- Dumping data for table `{$table}` ({$total_rows} rows)",
            "-- --------------------------------------------------------",
            "",
        );

        foreach ($data_header as $line) {
            $bytes_written += fwrite($output_handle, $line . "\n");
        }

        // Process in batches with transaction grouping
        $transaction_counter = 0;
        
        while ($offset < $total_rows) {
            // Start transaction
            if ($transaction_counter % $this->config['transaction_size'] === 0) {
                $bytes_written += fwrite($output_handle, "START TRANSACTION;\n");
            }

            // Build optimized query
            $query = $this->build_optimized_query($table, $primary_keys, $offset, $this->config['batch_size']);
            
            $result = $this->mysqli->query($query);
            if (!$result) {
                $this->filesystem->log("Error querying table `{$table}` at offset {$offset}: " . $this->mysqli->error);
                break;
            }

            $batch_rows = 0;
            while ($row = $result->fetch_assoc()) {
                $insert_sql = $this->build_insert_statement($table, $columns, $row);
                $bytes_written += fwrite($output_handle, $insert_sql . "\n");
                
                $batch_rows++;
                $rows_exported++;
                $transaction_counter++;

                // Commit transaction
                if ($transaction_counter % $this->config['transaction_size'] === 0) {
                    $bytes_written += fwrite($output_handle, "COMMIT;\n");
                }
            }

            $result->free();
            $offset += $this->config['batch_size'];

            // Small pause between batches
            if ($batch_rows > 0) {
                usleep(1000); // 0.001 seconds
            }
        }

        // Final commit if needed
        if ($transaction_counter % $this->config['transaction_size'] !== 0) {
            $bytes_written += fwrite($output_handle, "COMMIT;\n");
        }

        $bytes_written += fwrite($output_handle, "\n");

        return array(
            'rows' => $rows_exported,
            'bytes' => $bytes_written
        );
    }

    /**
     * Get comprehensive table information.
     *
     * @param string $table Table name.
     * @return array Table information.
     */
    private function get_table_info($table) {
        // Get columns
        $columns = array();
        $columns_result = $this->mysqli->query("SHOW COLUMNS FROM `{$table}`");
        if ($columns_result) {
            while ($column = $columns_result->fetch_assoc()) {
                $columns[] = $column;
            }
            $columns_result->free();
        }

        // Get primary keys
        $primary_keys = array();
        $keys_result = $this->mysqli->query("SHOW KEYS FROM `{$table}` WHERE Key_name = 'PRIMARY'");
        if ($keys_result) {
            while ($key = $keys_result->fetch_assoc()) {
                $primary_keys[] = $key['Column_name'];
            }
            $keys_result->free();
        }

        return array(
            'columns' => $columns,
            'primary_keys' => $primary_keys
        );
    }

    /**
     * Build optimized SELECT query based on table characteristics.
     *
     * @param string $table Table name.
     * @param array  $primary_keys Primary key columns.
     * @param int    $offset Row offset.
     * @param int    $limit Row limit.
     * @return string Optimized SQL query.
     */
    private function build_optimized_query($table, $primary_keys, $offset, $limit) {
        if (!empty($primary_keys)) {
            // Use JOIN optimization for tables with primary keys (like regular export)
            $key_columns = implode(', ', array_map(function($key) { return "`{$key}`"; }, $primary_keys));
            $using_columns = implode(', ', array_map(function($key) { return "`{$key}`"; }, $primary_keys));
            
            return sprintf(
                'SELECT t1.* FROM `%s` AS t1 JOIN (SELECT %s FROM `%s` ORDER BY %s LIMIT %d, %d) AS t2 USING (%s)',
                $table, $key_columns, $table, $key_columns, $offset, $limit, $using_columns
            );
        } else {
            // Fallback for tables without primary keys
            return "SELECT * FROM `{$table}` LIMIT {$offset}, {$limit}";
        }
    }

    /**
     * Build optimized INSERT statement.
     *
     * @param string $table Table name.
     * @param array  $columns Column definitions.
     * @param array  $row Row data.
     * @return string INSERT statement.
     */
    private function build_insert_statement($table, $columns, $row) {
        $values = array();
        
        foreach ($columns as $column) {
            $column_name = $column['Field'];
            $column_type = strtolower($column['Type']);
            $value = $row[$column_name];
            
            if (is_null($value)) {
                $values[] = 'NULL';
            } elseif ($this->is_numeric_type($column_type)) {
                $values[] = $value;
            } else {
                $values[] = "'" . $this->mysqli->real_escape_string($value) . "'";
            }
        }
        
        return "INSERT INTO `{$table}` VALUES (" . implode(',', $values) . ");";
    }

    /**
     * Check if column type is numeric.
     *
     * @param string $column_type Column type.
     * @return bool True if numeric.
     */
    private function is_numeric_type($column_type) {
        $numeric_types = array('int', 'tinyint', 'smallint', 'mediumint', 'bigint', 
                              'float', 'double', 'decimal', 'numeric', 'bit');
        
        foreach ($numeric_types as $type) {
            if (strpos($column_type, $type) === 0) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Get all database tables.
     *
     * @return array Table names.
     */
    protected function get_all_tables() {
        // Use cached tables if available to avoid repeated queries and logging
        if (!empty($this->state['all_tables'])) {
            return $this->state['all_tables'];
        }
        
        $tables = array();
        $result = $this->mysqli->query("SHOW TABLES");
        
        if ($result) {
            while ($row = $result->fetch_array()) {
                $tables[] = $row[0];
            }
            $result->free();
        }
        
        // Cache tables to avoid repeated queries
        $this->state['all_tables'] = $tables;
        
        // Only log table discovery once (when first discovered)
        if ($this->state['tables_processed'] === 0) {
            $this->filesystem->log("Found " . count($tables) . " database tables to export");
        }
        
        return $tables;
    }

    /**
     * Finalize export with compression and cleanup.
     *
     * @param resource $output_handle Output file handle.
     * @param string   $sql_file Target SQL file.
     */
    private function finalize_export($output_handle, $sql_file) {
        // Write SQL footer (no COMMIT here since table exports handle their own transactions)
        $footer = array(
            "",
            "-- Export completed on " . gmdate('Y-m-d H:i:s') . " GMT",
            "",
            "/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;",
            "/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;",
            "/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;",
            "/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;",
            "/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;",
            "/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;",
            "/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;",
            "/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;",
        );

        foreach ($footer as $line) {
            fwrite($output_handle, $line . "\n");
        }

        fclose($output_handle);

        // Handle compression using the same temp file that was created
        $this->filesystem->log("Finalizing export: temp file = " . $this->temp_file_path . ", target = " . $sql_file);
        $this->handle_compression($this->temp_file_path, $sql_file);
    }

    /**
     * Handle compression with fallback.
     *
     * @param string $temp_file Temporary file path.
     * @param string $sql_file Target SQL file path.
     */
    private function handle_compression($temp_file, $sql_file) {
        // Debug: Check if temp file exists and get its size
        if (!file_exists($temp_file)) {
            throw new Exception("Temp file does not exist: " . $temp_file);
        }
        
        $temp_size = filesize($temp_file);
        $this->filesystem->log("Processing temp file: " . $temp_file . " (size: " . $this->format_bytes($temp_size) . ")");
        
        if ($this->config['compression'] === 'none') {
            // No compression requested
            if (!rename($temp_file, $sql_file)) {
                $error = error_get_last();
                throw new Exception('Failed to move SQL file to final location: ' . ($error ? $error['message'] : 'Unknown error'));
            }
            return;
        }

        $use_compression = ($this->config['compression'] === 'gzip' || 
                           ($this->config['compression'] === 'auto' && function_exists('gzopen')));

        if ($use_compression) {
            // Smart compression - check if file already has .gz extension
            if (substr($sql_file, -3) === '.gz') {
                $compressed_file = $sql_file;
            } else {
                $compressed_file = $sql_file . '.gz';
            }
            
            if ($this->compress_file($temp_file, $compressed_file)) {
                unlink($temp_file);
                $this->filesystem->log("Database export compressed to " . basename($compressed_file));
                
                // Update stored filenames to reflect compression
                $this->update_stored_filename(basename($compressed_file));
            } else {
                // Compression failed, use uncompressed
                $this->filesystem->log("Compression failed, using uncompressed SQL file");
                
                // For uncompressed fallback, remove .gz extension if present
                $uncompressed_file = (substr($sql_file, -3) === '.gz') ? substr($sql_file, 0, -3) : $sql_file;
                
                if (!rename($temp_file, $uncompressed_file)) {
                    $error = error_get_last();
                    throw new Exception('Failed to move SQL file to final location: ' . ($error ? $error['message'] : 'Unknown error'));
                }
                
                // Update stored filenames for uncompressed
                $this->update_stored_filename(basename($uncompressed_file));
            }
        } else {
            // No compression available or requested
            // Remove .gz extension if present since we're not compressing
            $final_file = (substr($sql_file, -3) === '.gz') ? substr($sql_file, 0, -3) : $sql_file;
            
            if (!rename($temp_file, $final_file)) {
                $error = error_get_last();
                throw new Exception('Failed to move SQL file to final location: ' . ($error ? $error['message'] : 'Unknown error'));
            }
            
            // Update stored filenames for uncompressed
            $this->update_stored_filename(basename($final_file));
        }
    }

    /**
     * Compress file using gzip.
     *
     * @param string $source Source file path.
     * @param string $destination Destination file path.
     * @return bool Success status.
     */
    private function compress_file($source, $destination) {
        if (!function_exists('gzopen')) {
            return false;
        }

        $source_handle = @fopen($source, 'rb');
        if (!$source_handle) {
            return false;
        }

        $dest_handle = @gzopen($destination, 'wb6'); // Reduced compression for speed (was wb9)
        if (!$dest_handle) {
            fclose($source_handle);
            return false;
        }

        $success = true;
        $chunk_size = 2 * 1024 * 1024; // 2MB chunks for faster processing
        $total_size = filesize($source);
        $bytes_processed = 0;
        $start_time = microtime(true);
        $last_log_time = $start_time;

        try {
            while (!feof($source_handle)) {
                $buffer = fread($source_handle, $chunk_size);
                if ($buffer === false) {
                    $success = false;
                    break;
                }

                $bytes_written = gzwrite($dest_handle, $buffer);
                if ($bytes_written === false || $bytes_written != strlen($buffer)) {
                    $success = false;
                    break;
                }
                
                $bytes_processed += strlen($buffer);
                $current_time = microtime(true);
                
                // Log progress every 10 seconds to show compression is active
                if (($current_time - $last_log_time) >= 10) {
                    $progress = round(($bytes_processed / $total_size) * 100, 1);
                    $elapsed = round($current_time - $start_time, 1);
                    $this->filesystem->log("Compressing database: {$progress}% ({$this->format_bytes($bytes_processed)}/{$this->format_bytes($total_size)}) - {$elapsed}s elapsed");
                    $last_log_time = $current_time;
                }
            }
        } catch (Exception $e) {
            $this->filesystem->log("Compression error: " . $e->getMessage());
            $success = false;
        }

        fclose($source_handle);
        gzclose($dest_handle);

        $total_time = round(microtime(true) - $start_time, 1);
        
        // Verify compressed file
        if ($success && (!file_exists($destination) || filesize($destination) < 50)) {
            $this->filesystem->log("Compression verification failed");
            $success = false;
        } else if ($success) {
            $compressed_size = filesize($destination);
            $compression_ratio = round((1 - ($compressed_size / $total_size)) * 100, 1);
            $this->filesystem->log("Compression completed in {$total_time}s: {$this->format_bytes($total_size)} → {$this->format_bytes($compressed_size)} ({$compression_ratio}% reduction)");
        }

        return $success;
    }

    /**
     * Get temporary file path for processing.
     * Uses deterministic naming so all chunks use the same temp file.
     *
     * @param string $sql_file Target SQL file path.
     * @return string Temporary file path.
     */
    private function get_temp_file_path($sql_file) {
        $temp_dir = dirname($sql_file);
        
        // Get base name and handle both .sql and .sql.gz extensions properly
        $base_name = basename($sql_file);
        
        // Remove .gz extension if present
        if (substr($base_name, -3) === '.gz') {
            $base_name = substr($base_name, 0, -3);
        }
        
        // Remove .sql extension if present
        if (substr($base_name, -4) === '.sql') {
            $base_name = substr($base_name, 0, -4);
        }
        
        $temp_name = 'db_export_temp_' . $base_name . '.sql';
        return $temp_dir . '/' . $temp_name;
    }

    /**
     * Update stored filename option.
     *
     * @param string $filename New filename.
     */
    private function update_stored_filename($filename) {
        $filenames = get_option('custom_migrator_filenames');
        if ($filenames && is_array($filenames)) {
            $filenames['sql'] = $filename;
            update_option('custom_migrator_filenames', $filenames);
        }
    }

    /**
     * Clean up old temporary database export files.
     *
     * @param string $directory Directory to clean.
     */
    private function cleanup_old_temp_files($directory) {
        if (!is_dir($directory)) {
            return;
        }

        $files = glob($directory . '/db_export_temp_*.sql');
        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file);
                $this->filesystem->log("Cleaned up old temp file: " . basename($file));
            }
        }
    }

    /**
     * Format bytes for human readable output.
     *
     * @param int $bytes Bytes to format.
     * @return string Formatted string.
     */
    private function format_bytes($bytes) {
        $units = array('B', 'KB', 'MB', 'GB');
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= pow(1024, $pow);
        
        return round($bytes, 2) . ' ' . $units[$pow];
    }

    /**
     * Cleanup database connection.
     */
    private function cleanup_connection() {
        if ($this->mysqli) {
            $this->mysqli->close();
            $this->mysqli = null;
        }
    }

    /**
     * Cleanup on error.
     */
    private function cleanup_on_error() {
        $this->cleanup_connection();
        
        // Clean up any temporary files
        // This would be enhanced based on actual temp file management
    }

    /**
     * Get current export state for resume.
     *
     * @return array Current state.
     */
    public function get_state() {
        return $this->state;
    }

    /**
     * Check if export can be resumed.
     *
     * @param string $sql_file SQL file path.
     * @return bool True if resumable.
     */
    public function is_resumable($sql_file) {
        return $this->config['resume'] && 
               file_exists($this->get_temp_file_path($sql_file)) &&
               $this->state['tables_processed'] > 0;
    }
} 