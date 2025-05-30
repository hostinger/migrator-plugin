<?php
/**
 * The class responsible for database operations.
 *
 * @package CustomMigrator
 */

/**
 * Database class.
 */
class Custom_Migrator_Database {

    /**
     * The filesystem handler.
     *
     * @var Custom_Migrator_Filesystem
     */
    private $filesystem;

    /**
     * Initialize the class.
     */
    public function __construct() {
        $this->filesystem = new Custom_Migrator_Filesystem();
    }

    /**
     * Export the database to a SQL file.
     *
     * @param string $sql_file The path to the SQL file.
     * @throws Exception If the database export fails.
     */
    public function export($sql_file) {
        global $wpdb;

        // Check if we should try to compress
        $use_compression = function_exists('gzopen');
        
        // If compression is enabled, ensure .gz extension
        if ($use_compression && substr($sql_file, -3) !== '.gz') {
            $sql_file .= '.gz';
        } elseif (!$use_compression && substr($sql_file, -3) === '.gz') {
            // Remove .gz extension if compression is not available
            $sql_file = substr($sql_file, 0, -3);
        }
        
        // For compressed output, we'll write to a temp file first
        $temp_sql_file = null;
        $output_fp = null;
        
        if ($use_compression) {
            $temp_sql_file = tempnam(sys_get_temp_dir(), 'sql_export_');
            if (!$temp_sql_file) {
                throw new Exception('Cannot create temporary SQL file');
            }
            $output_fp = @fopen($temp_sql_file, 'wb');
        } else {
            // Direct output to the final file if no compression
            $output_fp = @fopen($sql_file, 'wb');
        }
        
        if (!$output_fp) {
            if ($temp_sql_file) {
                @unlink($temp_sql_file);
            }
            throw new Exception('Cannot create SQL file for writing');
        }
        
        // Create a connection using mysqli
        $mysqli = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
        
        if ($mysqli->connect_error) {
            fclose($output_fp);
            if ($temp_sql_file) {
                @unlink($temp_sql_file);
            }
            throw new Exception('Database connection failed: ' . $mysqli->connect_error);
        }

        // Write SQL header information
        $header = "-- WordPress Database Export\n" .
                  "-- Generated by Hostinger Migrator\n" .
                  "-- Date: " . gmdate( 'Y-m-d H:i:s' ) . " GMT\n" .
                  "-- Host: " . DB_HOST . "\n" .
                  "-- Database: " . DB_NAME . "\n\n" .
                  "/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;\n" .
                  "/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;\n" .
                  "/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;\n" .
                  "/*!40101 SET NAMES utf8mb4 */;\n" .
                  "/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;\n" .
                  "/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;\n" .
                  "/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;\n\n";
                  
        fwrite($output_fp, $header);

        // Get all tables
        $tables = [];
        $result = $mysqli->query("SHOW TABLES");
        while ($row = $result->fetch_array()) {
            $tables[] = $row[0];
        }
        $result->free();

        // Track progress
        $total_tables = count($tables);
        $this->filesystem->log("Exporting $total_tables tables...");
        $table_count = 0;

        // Loop through tables
        foreach ($tables as $table) {
            $table_count++;
            
            // Log progress every 5 tables
            if ($table_count % 5 === 0 || $table_count === $total_tables) {
                $this->filesystem->log("Exported $table_count of $total_tables tables");
            }
            
            // Get CREATE TABLE statement
            $result = $mysqli->query("SHOW CREATE TABLE `$table`");
            if ($result) {
                $row = $result->fetch_array();
                $create_table = $row[1];
                $result->free();
                
                // Write table structure
                fwrite($output_fp, "\n\n-- Table structure for table `$table`\n");
                fwrite($output_fp, "DROP TABLE IF EXISTS `$table`;\n");
                fwrite($output_fp, "$create_table;\n\n");
                
                // Get table data using batched queries for better memory management
                $this->export_table_data($mysqli, $output_fp, $table);
            }
        }

        // Write SQL footer
        $footer = "\n/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;\n" .
                  "/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;\n" .
                  "/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;\n" .
                  "/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;\n" .
                  "/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;\n" .
                  "/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;\n";
                  
        fwrite($output_fp, $footer);
        
        // Close file and database connection
        fclose($output_fp);
        $mysqli->close();
        
        // Compress the file if needed
        if ($use_compression && $temp_sql_file) {
            $this->filesystem->log("Compressing SQL file...");
            $gz_success = $this->compress_file($temp_sql_file, $sql_file);
            
            if (!$gz_success) {
                // Fall back to uncompressed file if compression failed
                $this->filesystem->log("Compression failed, using uncompressed SQL file");
                $uncompressed_file = substr($sql_file, 0, -3);
                if (rename($temp_sql_file, $uncompressed_file)) {
                    $sql_file = $uncompressed_file;
                } else {
                    @unlink($temp_sql_file);
                    throw new Exception('Failed to create SQL file');
                }
            } else {
                // Remove the temporary file if compression was successful
                @unlink($temp_sql_file);
            }
        }
        
        $this->filesystem->log("Database export completed successfully to $sql_file");
        
        // Update the stored filenames option to reflect any changes in extension
        $filenames = get_option('custom_migrator_filenames');
        if ($filenames && isset($filenames['sql'])) {
            $filenames['sql'] = basename($sql_file);
            update_option('custom_migrator_filenames', $filenames);
        }
        
        return $sql_file; // Return the actual file path used
    }

    /**
     * Compress a file using gzip.
     *
     * @param string $source Source file path.
     * @param string $destination Destination file path.
     * @return bool True on success, false on failure.
     */
    private function compress_file($source, $destination) {
        if (!function_exists('gzopen')) {
            return false;
        }
        
        $source_handle = @fopen($source, 'rb');
        if (!$source_handle) {
            return false;
        }
        
        $dest_handle = @gzopen($destination, 'wb9'); // Maximum compression
        if (!$dest_handle) {
            fclose($source_handle);
            return false;
        }
        
        // Read source file in smaller chunks to avoid memory issues
        $success = true;
        $chunk_size = 1024 * 1024; // 1MB chunks
        
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
            }
        } catch (Exception $e) {
            $success = false;
        }
        
        // Close file handles
        fclose($source_handle);
        gzclose($dest_handle);
        
        // Verify the compressed file exists and has content
        if ($success && (!file_exists($destination) || filesize($destination) < 50)) {
            $success = false;
        }
        
        return $success;
    }

    /**
     * Export table data with efficient batching.
     *
     * @param mysqli   $mysqli  MySQL connection.
     * @param resource $sql_fp  File pointer for the SQL file.
     * @param string   $table   Table name.
     */
    private function export_table_data($mysqli, $sql_fp, $table) {
        // Get row count
        $count_result = $mysqli->query("SELECT COUNT(*) FROM `$table`");
        $count_row = $count_result->fetch_array();
        $total_rows = $count_row[0];
        $count_result->free();
        
        if ($total_rows === 0) {
            return;
        }
        
        // Get column information
        $columns_result = $mysqli->query("SHOW COLUMNS FROM `$table`");
        $columns = [];
        while ($column = $columns_result->fetch_assoc()) {
            $columns[] = $column;
        }
        $columns_result->free();
        
        // Export in batches to conserve memory
        $batch_size = 1000;
        $offset = 0;
        
        while ($offset < $total_rows) {
            $query = "SELECT * FROM `$table` LIMIT $offset, $batch_size";
            $result = $mysqli->query($query);
            
            if (!$result) {
                $this->filesystem->log("Error querying table `$table`: " . $mysqli->error);
                return;
            }
            
            $insert_head = "INSERT INTO `$table` VALUES\n";
            $first_row = true;
            $values_added = false;
            
            while ($row = $result->fetch_assoc()) {
                if ($first_row) {
                    fwrite($sql_fp, $insert_head);
                    $first_row = false;
                } else {
                    fwrite($sql_fp, ",\n");
                }
                
                $values = [];
                
                foreach ($columns as $column) {
                    $column_name = $column['Field'];
                    $column_type = $column['Type'];
                    $value = $row[$column_name];
                    
                    if (is_null($value)) {
                        $values[] = "NULL";
                    } elseif (strpos($column_type, 'int') === 0 || 
                            strpos($column_type, 'float') === 0 || 
                            strpos($column_type, 'double') === 0 || 
                            strpos($column_type, 'decimal') === 0) {
                        $values[] = $value;
                    } else {
                        $values[] = "'" . $mysqli->real_escape_string($value) . "'";
                    }
                }
                
                fwrite($sql_fp, "(" . implode(',', $values) . ")");
                $values_added = true;
            }
            
            if ($values_added) {
                fwrite($sql_fp, ";\n");
            }
            
            $result->free();
            $offset += $batch_size;
        }
    }
}