# Custom Migrator

A high-performance WordPress migration plugin designed for large websites (15-20GB+). Custom Migrator exports your WordPress content and database into separate files with comprehensive metadata for reliable migration between environments.

## Technical Overview

Custom Migrator uses a three-component export strategy:
1. **Site content** (`.hstgr` file): Contains all files from wp-content directory
2. **Database** (`.sql` file): SQL dump with table structures and data
3. **Metadata** (`.json` file): Site configuration, plugin/theme information, and size metrics

## Installation

1. Upload the `custom-migrator` directory to the `/wp-content/plugins/` directory
2. Activate the plugin through WordPress admin interface
3. Navigate to Custom Migrator in the admin menu

## File Format Specifications

### HSTGR Archive Format

The `.hstgr` file is a custom streaming archive format optimized for WordPress content files. It's designed for sequential reading/writing without requiring in-memory indexing, making it suitable for large sites.

#### File Structure

```
# Hostinger Migration File
# Format: HSTGR-1.0
# Date: YYYY-MM-DD HH:MM:SS
# Generator: Custom Migrator vX.X.X

__file__:wp-content/relative/path/to/file1
__size__:1234
__md5__:a1b2c3d4e5f6...
[Raw binary file content]
__endfile__

__file__:wp-content/relative/path/to/file2
__size__:5678
__md5__:f5e4d3c2b1a0...
[Raw binary file content]
__endfile__

[...additional files...]

__stats__
total_files:1234
total_size:1234567890
export_time:123.45
__done__
```

Each file entry consists of:
- A header section with file path, size, and MD5 checksum
- Raw binary file content
- Footer marker (`__endfile__`)

The archive concludes with statistics and a final marker (`__done__`).

### SQL Export Format

The database is exported as standard SQL with:
- Table definitions (CREATE TABLE statements)
- Data inserts in batched chunks (1000 rows per INSERT)
- MySQL compatibility directives

### Metadata JSON Structure

The `site-export.meta.json` file contains structured information about the site:

```json
{
  "site_info": {
    "site_url": "https://example.com",
    "home_url": "https://example.com",
    "wp_version": "6.2.3",
    "php_version": "8.1.10",
    "table_prefix": "wp_",
    "is_multisite": false,
    "site_title": "Example Site",
    "site_desc": "Site description",
    "charset": "UTF-8",
    "language": "en-US",
    "admin_email": "admin@example.com"
  },
  "export_info": {
    "created_at": "2023-12-01T12:00:00Z",
    "created_by": "Custom Migrator v1.0.0",
    "wp_content_version": "a1b2c3...",
    "wp_content_size": 1073741824,
    "wp_content_size_formatted": "1.00 GB"
  },
  "themes": {
    "active_theme": {
      "slug": "theme-slug",
      "name": "Theme Name",
      "version": "1.0"
    },
    "parent_theme": null
  },
  "plugins": {
    "active_plugins": ["plugin1", "plugin2"],
    "network_plugins": [],
    "active_plugins_count": 2,
    "network_plugins_count": 0
  },
  "database": {
    "charset": "utf8mb4",
    "collate": "utf8mb4_unicode_ci",
    "tables_count": 12,
    "total_size_mb": 25.5,
    "total_size_bytes": 26738688,
    "total_size_formatted": "25.50 MB"
  },
  "system": {
    "max_execution_time": 300,
    "memory_limit": "256M",
    "post_max_size": "64M",
    "upload_max_filesize": "32M"
  }
}
```

## Technical Architecture

### Object-Oriented Design

The plugin follows a modular OOP approach with these main components:

#### Core Classes
- `Custom_Migrator_Core`: Plugin initialization and hook registration
- `Custom_Migrator_Admin`: Admin interface and form handling
- `Custom_Migrator_Exporter`: Manages the export workflow

#### Utility Classes
- `Custom_Migrator_Filesystem`: File system operations and path management
- `Custom_Migrator_Database`: Database export with batched processing
- `Custom_Migrator_Metadata`: Site metadata generation and formatting

### Export Process

The export process follows these steps:

1. **Initialization**:
   - Create export directory 
   - Write initial status
   
2. **Metadata Generation**:
   - Collect site information
   - Calculate content and database sizes
   - Generate JSON metadata file
   
3. **Database Export**:
   - Connect to database via mysqli
   - Export table structures
   - Export table data in batches (1000 rows per batch)
   
4. **Content Export**:
   - Create recursive iterator for wp-content directory
   - Process files sequentially
   - Calculate MD5 checksums for integrity verification
   - Write files to archive with metadata
   
5. **Finalization**:
   - Write archive statistics
   - Update export status

### Performance Considerations

The plugin incorporates several performance optimizations for large sites:

- **Memory Efficiency**:
  - Streaming file operations avoid loading entire files into memory
  - Batch processing database records (1000 rows per query)
  - Using iterators for directory traversal
  
- **Processing Optimizations**:
  - Optimized buffer size (128KB) for file read/write operations
  - Progress tracking and logging for large operations
  - Automatic exclusion of the export directory from the archive
  
- **Execution Environment**:
  - Automatic increase of PHP memory limit when possible
  - Removal of PHP time limits during export
  - Background processing via WordPress cron

## Import Process

The import process uses a complementary script that:

1. Downloads files from the source site
2. Extracts the `.hstgr` file to restore content files
3. Generates SQL for search/replace operations
4. Provides instructions for database import

The import script supports integrity checking by validating MD5 checksums and file sizes.

## System Requirements

- WordPress 5.0 or higher
- PHP 7.0 or higher (some features optimized for PHP 7.4+)
- MySQL 5.6 or higher
- Sufficient disk space (at least 2x the site size)
- Recommended PHP settings:
  - `memory_limit`: 256M or higher
  - `max_execution_time`: 300 or higher
  - `post_max_size`: 64M or higher
  - `upload_max_filesize`: 32M or higher

## License

This plugin is licensed under the GPL v2 or later.

## Credits

Developed by Erika