# WP Multisite DB Backups

A WordPress plugin for multisite networks that automatically exports each site's database and uploads backups to Backblaze B2.

## Description

WP Multisite DB Backups is a network-activated WordPress plugin designed specifically for WordPress multisite installations. It provides automated daily database backups for each site in your network, with secure cloud storage via Backblaze B2.

### Features

- **Automated Daily Backups**: Runs automatically via WordPress cron at 2:00 AM daily
- **Per-Site Database Export**: Each site in the multisite network gets its own individual database backup
- **Backblaze B2 Integration**: Securely uploads backups to Backblaze B2 cloud storage
- **Backup Retention**: Automatically retains 14 days of backups per site
- **Manual Backup Trigger**: Run backups on-demand from the admin panel
- **Backup Logging**: Complete logging of all backup operations with status tracking
- **Network Admin Settings**: Centralized configuration in the Network Admin settings
- **Filterable Log View**: Search and filter backup history by site

## Requirements

- WordPress 5.8 or higher
- PHP 7.4 or higher
- WordPress Multisite installation
- Backblaze B2 account with:
  - Application Key ID
  - Application Key
  - Bucket name
  - S3-compatible endpoint URL

## Installation

### Standard Installation

1. Download the plugin files
2. Upload to `/wp-content/plugins/wp-multisite-db-backups/`
3. Network activate the plugin through the 'Plugins' menu in Network Admin
4. Configure your B2 settings under **Settings > Site Backups** in Network Admin

### Git Installation

```bash
cd /path/to/wordpress/wp-content/plugins/
git clone https://github.com/637-Digital/wp-multisite-db-backups.git
```

Then network activate the plugin in WordPress admin.

## Configuration

After network activation, navigate to **My Sites > Network Admin > Settings > Site Backups** to configure:

| Setting | Description | Example |
|---------|-------------|---------|
| B2 Endpoint | Your B2 S3-compatible endpoint | `https://s3.us-west-004.backblazeb2.com` |
| Bucket Name | Your B2 bucket name | `my-wordpress-backups` |
| Key ID | Your B2 Application Key ID | `002xxxxx` |
| Application Key | Your B2 Application Key | `Kxxx...` |
| Folder Prefix | Prefix for backup files in bucket | `per-site-backups/` |

### Backblaze B2 Setup

1. Create a Backblaze B2 account at [backblaze.com](https://www.backblaze.com/)
2. Create a private bucket for your backups
3. Generate an Application Key with write access to your bucket
4. Note your Key ID, Application Key, and S3 endpoint URL

## Usage

### Automatic Backups

Once configured, the plugin will automatically:
- Export each site's database at 2:00 AM daily
- Upload the backup to your configured B2 bucket
- Log the result (success or failure)
- Clean up backups older than 14 days

### Manual Backups

To run a backup immediately:

1. Go to **Network Admin > Settings > Site Backups**
2. Click **Run Full Backup Now**
3. View the log to confirm completion

### Viewing Backup History

The backup log shows:
- Date and time of each backup
- Site identification (Blog ID and slug)
- Status (success/failed)
- Filename and size
- Duration
- Error messages (if any)

Use the filter box to search for specific sites.

## File Structure

```
wp-multisite-db-backups/
├── wp-multisite-db-backup.php           # Main plugin file
├── wp-multisite-db-backup/
│   ├── multi-site-content-backup.php    # Core plugin logic
│   ├── class-db-exporter.php            # Database export handler
│   └── class-b2-uploader.php            # B2 upload handler
├── README.md                            # This file
└── LICENSE                              # GPL v2 license
```

## Database Table

The plugin creates a table `{prefix}_msb_backup_log` to store backup history:

```sql
id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
run_date    DATETIME
blog_id     BIGINT UNSIGNED
site_slug   VARCHAR(100)
status      ENUM('success','failed')
file_name   VARCHAR(255)
file_size   BIGINT UNSIGNED
duration_s  FLOAT
message     TEXT
```

## Troubleshooting

### Backups not running automatically

- Check that WordPress cron is functioning (WP-Cron)
- Verify the plugin is network-activated
- Check server error logs

### Upload failures

- Verify B2 credentials are correct
- Ensure the Application Key has write permissions
- Check that the bucket exists and is accessible
- Verify the endpoint URL matches your bucket region

### Database export errors

- Ensure the server has sufficient disk space for temporary files
- Check PHP memory limits for large databases
- Verify database user has proper permissions

### Log table not created

- Deactivate and reactivate the plugin network-wide
- Check database permissions for CREATE TABLE
- Check WordPress database upgrade routine

## Security Notes

- Store B2 credentials securely
- Use dedicated Application Keys with limited permissions
- Enable bucket encryption in Backblaze B2
- Regularly rotate your B2 Application Keys
- Keep backup files in private buckets only

## Contributing

Contributions are welcome! Please submit pull requests or issues via GitHub.

## License

This plugin is licensed under the GPL v2 or later.

## Credits

Developed by [637 Digital Solutions](https://www.637digital.com)