# Custom Migrator Plugin

A WordPress plugin for exporting websites to `.hstgr` binary format archives with high-performance batch processing.

## Batch Processing Configuration

The plugin uses an optimized batch processing system with industry-standard approaches for maximum performance and reliability.

### Processing Parameters

| Parameter | Value | Description |
|-----------|--------|-------------|
| **Batch Size** | 10,000 files | Maximum files processed per batch cycle |
| **Execution Time** | 10 seconds | Maximum time per batch before pausing |
| **Memory Threshold** | 90% | Pause when memory usage exceeds this limit |
| **Chunk Size** | 512KB | File buffer size for reading/writing |
| **Pause Check Frequency** | Every 1,000 files | How often to check pause conditions |
| **Progress Logging** | Every 5,000 files | Frequency of progress log entries |
| **I/O Throttling** | Every 1,000 files | Throttling applied to prevent system overload |
| **Throttle Delay** | 0.01 seconds | Brief pause duration when throttling |

### Processing Approach Comparison

| Aspect | Traditional Approach | Custom Migrator | Notes |
|--------|---------------------|-----------------|-------|
| **Timeout Logic** | Time-based only (10 seconds) | Time + file count + memory | We use additional safeguards |
| **File Counting** | Processes until timeout | Counts files + checks timeout | We limit files per batch |
| **File Discovery** | Pre-enumerates all files to CSV | Direct streaming (no CSV) | We eliminated CSV bottleneck |
| **Memory Management** | Basic timeout handling | Aggressive 90% monitoring | We proactively manage memory |
| **Resume Tracking** | Archive + file byte offsets | Binary offset + file path | We track more resume state |

### Memory Management Strategy

The plugin uses an **aggressive memory management approach**:

- **Allows memory to grow** to 90% of PHP's `memory_limit`
- **Pauses and restarts** when threshold is reached
- **Preserves resume state** with binary offset tracking
- **Maximizes performance** while maintaining stability

### Example Memory Calculation

```php
// If PHP memory_limit = 512M
$memory_limit = 512 * 1024 * 1024;  // 536,870,912 bytes
$threshold = $memory_limit * 0.9;    // 483,183,821 bytes (90%)

// Plugin pauses when memory_get_usage(true) > $threshold
```

### Batch Processing Flow

1. **Start Processing**: Begin processing files with 10-second timer
2. **Check Conditions**: Every 1,000 files, verify:
   - Execution time < 10 seconds
   - Memory usage < 90% limit
   - Files processed < 10,000 batch size
3. **Pause if Needed**: Save state and schedule resume via HTTP request
4. **Resume Processing**: Continue from exact file position with clean memory
5. **Complete**: When all files processed successfully

### Key Processing Innovations

**Traditional time-only approach:**
```php
// Process files until 10 seconds elapsed
while ($files_remaining && (microtime(true) - $start) < 10) {
    process_file();
}
```

**Our enhanced approach:**
```php
// Process up to 10,000 files OR 10 seconds OR 90% memory
while ($files < 10000 && (microtime(true) - $start) < 10 && memory_usage() < 90%) {
    process_file();
    if ($files % 1000 === 0) check_conditions();
}
```

### Resume Mechanism

The plugin maintains detailed resume state:

```json
{
  "files_processed": 15000,
  "bytes_processed": 2147483648,
  "last_file_path": "/path/to/last/processed/file.jpg",
  "archive_offset": 2151858023,
  "skipped_count": 45,
  "last_update": 1640995200
}
```

### Performance Benefits

- **High Throughput**: Processes thousands of files per batch
- **Memory Efficient**: Uses aggressive thresholds with safe restart
- **Resume Capability**: Never loses progress on interruption
- **Session Independent**: Works without browser/admin session
- **Automation Ready**: Supports scripted/curl-based automation

## File Structure

```
custom-migrator/
├── includes/
│   ├── class-exporter.php      # Main export logic with batch processing
│   ├── class-core.php          # Core plugin functionality
│   ├── class-filesystem.php    # File system operations
│   ├── class-database.php      # Database export handling
│   └── class-metadata.php      # Metadata generation
├── admin/
│   ├── class-admin.php         # Admin interface
│   └── js/script.js           # Frontend JavaScript
└── custom-migrator.php        # Main plugin file
```

## Export Output

The plugin generates three files:

1. **`.hstgr`** - Binary archive with 4375-byte headers per file
2. **`.sql.gz`** - Compressed database dump
3. **`.metadata`** - JSON metadata with export information

## Technical Notes

- Uses `RecursiveDirectoryIterator` for efficient file discovery
- Implements binary archive format with structured headers
- Supports large file processing with streaming
- Handles exclusion paths for backup/cache directories
- Maintains compatibility with existing import tools 