<?php
/**
 * The class responsible for uploading files to S3 using pre-signed URLs.
 *
 * @package CustomMigrator
 */

/**
 * S3 Uploader class.
 */
class Custom_Migrator_S3_Uploader {

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
     * Update S3 upload status.
     *
     * @param string $status The upload status to save.
     */
    private function update_s3_status($status) {
        $status_file = WP_CONTENT_DIR . '/hostinger-migration-archives/s3-upload-status.txt';
        file_put_contents($status_file, $status);
    }

    /**
     * Upload files to S3 using pre-signed URLs.
     *
     * @param array $presigned_urls Array of file types and their pre-signed URLs.
     * @return array Result of the upload process.
     */
    public function upload_to_s3($presigned_urls) {
        // Try to increase PHP execution time limit
        @set_time_limit(0);  // Try to remove the time limit
        @ini_set('max_execution_time', 3600); // Try to set to 1 hour
        
        // Initialize results array
        $results = array(
            'success' => true,
            'messages' => array(),
            'uploaded' => array()
        );

        // Get file paths
        $file_paths = $this->filesystem->get_export_file_paths();
        
        // Update status to starting
        $this->update_s3_status("starting");
        
        // Start logging
        $this->filesystem->log("Starting S3 upload process");
        
        // Loop through each file type
        foreach ($presigned_urls as $file_type => $presigned_url) {
            // Skip if no URL provided
            if (empty($presigned_url)) {
                $this->filesystem->log("No pre-signed URL provided for $file_type file. Skipping.");
                $results['messages'][] = "No pre-signed URL provided for $file_type file.";
                continue;
            }
            
            // Preserve encoded slashes in the URL
            $presigned_url = $this->preserve_encoded_slashes($presigned_url);
            
            // Check if file exists
            if (!isset($file_paths[$file_type]) || !file_exists($file_paths[$file_type])) {
                $this->filesystem->log("File not found for $file_type: " . (isset($file_paths[$file_type]) ? $file_paths[$file_type] : 'undefined path'));
                $results['messages'][] = "File not found for $file_type.";
                $results['success'] = false;
                continue;
            }
            
            // Update status to show current file being uploaded
            $this->update_s3_status("uploading_$file_type");
            
            // Log file details
            $file_path = $file_paths[$file_type];
            $file_size = filesize($file_path);
            $this->filesystem->log("Uploading $file_type file: " . basename($file_path) . " (" . $this->filesystem->format_file_size($file_size) . ")");
            
            try {
                // Attempt to upload the file
                $upload_result = $this->upload_file_to_s3($presigned_url, $file_path);
                
                if ($upload_result['success']) {
                    $this->filesystem->log("Successfully uploaded $file_type file to S3");
                    $results['messages'][] = "Successfully uploaded $file_type file to S3.";
                    $results['uploaded'][] = $file_type;
                } else {
                    $this->filesystem->log("Failed to upload $file_type file: " . $upload_result['message']);
                    $results['messages'][] = "Failed to upload $file_type file: " . $upload_result['message'];
                    $results['success'] = false;
                }
            } catch (Exception $e) {
                $this->filesystem->log("Error uploading $file_type file: " . $e->getMessage());
                $results['messages'][] = "Error uploading $file_type file: " . $e->getMessage();
                $results['success'] = false;
            }
        }
        
        // Update final status
        if ($results['success']) {
            $this->update_s3_status("done");
            $this->filesystem->log("S3 upload process completed successfully");
        } else {
            $this->update_s3_status("error: Upload failed");
            $this->filesystem->log("S3 upload process completed with errors");
        }
        
        return $results;
    }
    
    /**
     * Ensures that URL-encoded slashes in X-Amz-Credential are preserved.
     *
     * @param string $presigned_url The pre-signed URL
     * @return string The URL with properly preserved encoded slashes
     */
    private function preserve_encoded_slashes($presigned_url) {
        $original_url = $presigned_url;
        
        // First check if the URL contains encoded slashes (%2F)
        if (strpos($presigned_url, '%2F') !== false) {
            // URL has encoded slashes, which is good. Log and return as is.
            $this->filesystem->log("Pre-signed URL contains properly encoded slashes (%2F)");
            return $presigned_url;
        }
        
        // If we get here, the URL might have decoding issues
        $this->filesystem->log("Pre-signed URL does not contain encoded slashes, checking format...");
        
        // Check if credential is missing slashes completely
        if (preg_match('/X-Amz-Credential=([A-Za-z0-9]{20})([0-9]{8})(eu-north-1)s3aws4_request/', $presigned_url, $matches)) {
            // Found malformed credential, attempt to fix
            $this->filesystem->log("Found malformed X-Amz-Credential without slashes, attempting to fix");
            
            $access_key = $matches[1];
            $date = $matches[2];
            $region = $matches[3];
            
            // Create properly encoded credential
            $encoded_credential = $access_key . '%2F' . $date . '%2F' . $region . '%2Fs3%2Faws4_request';
            
            // Replace the malformed credential with the fixed one
            $fixed_url = preg_replace(
                '/X-Amz-Credential=[A-Za-z0-9]{20}[0-9]{8}eu-north-1s3aws4_request/',
                'X-Amz-Credential=' . $encoded_credential,
                $presigned_url
            );
            
            if ($fixed_url !== $presigned_url) {
                $this->filesystem->log("Fixed X-Amz-Credential by adding encoded slashes (%2F)");
                return $fixed_url;
            }
        }
        
        // Check if it contains unencoded slashes (/)
        if (preg_match('/X-Amz-Credential=([^&]+)\/([^&]+)\/([^&]+)\/s3\/aws4_request/', $presigned_url, $matches)) {
            // Has unencoded slashes, encode them
            $this->filesystem->log("Found X-Amz-Credential with unencoded slashes, encoding them");
            
            $access_key = $matches[1];
            $date = $matches[2];
            $region = $matches[3];
            
            // Create properly encoded credential
            $encoded_credential = $access_key . '%2F' . $date . '%2F' . $region . '%2Fs3%2Faws4_request';
            
            // Replace the unencoded credential with the encoded one
            $fixed_url = str_replace(
                $matches[0],
                'X-Amz-Credential=' . $encoded_credential,
                $presigned_url
            );
            
            if ($fixed_url !== $presigned_url) {
                $this->filesystem->log("Fixed X-Amz-Credential by encoding slashes to %2F");
                return $fixed_url;
            }
        }
        
        // If we get here, we couldn't fix the URL
        $this->filesystem->log("Could not determine proper format for X-Amz-Credential");
        return $original_url;
    }

    /**
     * Upload a file to S3 using a pre-signed URL.
     *
     * @param string $presigned_url The pre-signed URL for uploading.
     * @param string $file_path The local path to the file.
     * @return array Result of the upload.
     */
    private function upload_file_to_s3($presigned_url, $file_path) {
        // Check if file exists and is readable
        if (!file_exists($file_path) || !is_readable($file_path)) {
            return array(
                'success' => false,
                'message' => 'File does not exist or is not readable'
            );
        }
        
        // Get content type based on file extension
        $content_type = $this->get_mime_type($file_path);
        $this->filesystem->log("Using content type: " . $content_type . " for file: " . basename($file_path));
        
        // Get file size
        $file_size = filesize($file_path);
        
        // Prepare headers
        $headers = array(
            'Content-Type: ' . $content_type,
            'Content-Length: ' . $file_size
        );
        
        // Log the upload details
        $this->filesystem->log("Uploading file with size: " . $this->filesystem->format_file_size($file_size));
        $this->filesystem->log("Using headers: " . json_encode($headers));
        
        // Initialize cURL session
        $ch = curl_init();
        
        // Log the URL being used (for debugging)
        $this->filesystem->log("Using pre-signed URL: " . substr($presigned_url, 0, 100) . "..." . (strlen($presigned_url) > 100 ? substr($presigned_url, -50) : ""));
        
        // Set cURL options for the upload
        curl_setopt($ch, CURLOPT_URL, $presigned_url);
        curl_setopt($ch, CURLOPT_PUT, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3600); // 1 hour timeout for large files
        
        // Enable verbose debugging
        curl_setopt($ch, CURLOPT_VERBOSE, true);
        $verbose = fopen('php://temp', 'w+');
        curl_setopt($ch, CURLOPT_STDERR, $verbose);
        
        // Set the file for upload
        $file_handle = fopen($file_path, 'rb');
        curl_setopt($ch, CURLOPT_INFILE, $file_handle);
        curl_setopt($ch, CURLOPT_INFILESIZE, $file_size);
        
        // Execute the request
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        
        // Get verbose log
        rewind($verbose);
        $verbose_log = stream_get_contents($verbose);
        fclose($verbose);
        
        // Log detailed info if there was an error
        if ($http_code < 200 || $http_code >= 300) {
            $this->filesystem->log("HTTP Status Code: " . $http_code);
            if (!empty($curl_error)) {
                $this->filesystem->log("CURL Error: " . $curl_error);
            }
            $this->filesystem->log("Response: " . $response);
            $this->filesystem->log("Verbose Log: " . $verbose_log);
        }
        
        // Clean up resources
        fclose($file_handle);
        curl_close($ch);
        
        // Check if upload was successful
        if ($http_code >= 200 && $http_code < 300) {
            return array(
                'success' => true,
                'message' => 'File uploaded successfully'
            );
        } else {
            return array(
                'success' => false,
                'message' => "HTTP Error: " . $http_code . (!empty($curl_error) ? ", CURL Error: " . $curl_error : "")
            );
        }
    }

    /**
     * Get the MIME type of a file.
     *
     * @param string $file_path The path to the file.
     * @return string The MIME type.
     */
    private function get_mime_type($file_path) {
        $file_extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
        
        // Define MIME types for known file extensions
        switch ($file_extension) {
            case 'hstgr':
                return 'application/octet-stream';
            case 'sql':
                return 'text/plain';
            case 'gz':
                return 'application/gzip';
            case 'json':
                return 'application/json';
            case 'txt':
                return 'text/plain';
            default:
                // Try to use PHP's built-in function if available
                if (function_exists('mime_content_type')) {
                    return mime_content_type($file_path);
                }
                // Default for binary data
                return 'application/octet-stream';
        }
    }
}