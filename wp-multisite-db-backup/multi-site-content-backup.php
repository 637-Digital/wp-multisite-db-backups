<?php
/**
 * Plugin Name: Multi-Site Content Backup
 * Plugin URI:  https://github.com/637-Digital/multi-site-cron-backup
 * Description: Per-site database export for Hope Ignites multisite — uploads to Backblaze B2.
 * Version:     0.1.0
 * Network:     true
 * Author:      637 Digital
 */

defined( 'ABSPATH' ) || exit;

// ─── Constants ───────────────────────────────────────────────────────────────
define( 'MSB_VERSION',   '0.1.0' );
define( 'MSB_DIR',       plugin_dir_path( __FILE__ ) );
define( 'MSB_CRON_HOOK', 'msb_run_backups' );
define( 'MSB_LOG_TABLE', 'wp_msb_backup_log' );

// ─── Autoload classes ─────────────────────────────────────────────────────────
require_once MSB_DIR . 'class-db-exporter.php';
require_once MSB_DIR . 'class-b2-uploader.php';

// ─── Bootstrap ───────────────────────────────────────────────────────────────
add_action( 'init', 'msb_maybe_create_log_table' );
add_action( MSB_CRON_HOOK, 'msb_run_backups' );
add_action( 'init', 'msb_schedule_cron' );

// Network settings page.
add_action( 'network_admin_menu', 'msb_register_settings_page' );
add_action( 'network_admin_edit_msb_save_settings', 'msb_save_settings' );

// Manual "Run Now" via admin-post.
add_action( 'admin_post_msb_run_now', 'msb_handle_run_now' );

// ─── Cron Registration ────────────────────────────────────────────────────────
function msb_schedule_cron() {
    if ( ! wp_next_scheduled( MSB_CRON_HOOK ) ) {
        wp_schedule_event( strtotime( 'tomorrow 02:00:00' ), 'daily', MSB_CRON_HOOK );
    }
}

// ─── Log Table ────────────────────────────────────────────────────────────────
function msb_maybe_create_log_table() {
    global $wpdb;

    if ( get_site_option( 'msb_db_version' ) === MSB_VERSION ) {
        return;
    }

    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE IF NOT EXISTS " . MSB_LOG_TABLE . " (
        id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        run_date    DATETIME NOT NULL,
        blog_id     BIGINT UNSIGNED NOT NULL,
        site_slug   VARCHAR(100) NOT NULL,
        status      ENUM('success','failed') NOT NULL,
        file_name   VARCHAR(255) DEFAULT NULL,
        file_size   BIGINT UNSIGNED DEFAULT NULL,
        duration_s  FLOAT DEFAULT NULL,
        message     TEXT DEFAULT NULL,
        PRIMARY KEY (id),
        INDEX idx_blog_id (blog_id),
        INDEX idx_run_date (run_date)
    ) ENGINE=InnoDB {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );

    update_site_option( 'msb_db_version', MSB_VERSION );
}

// ─── Log Writer ───────────────────────────────────────────────────────────────
function msb_log( array $entry ) {
    global $wpdb;

    $wpdb->insert(
        MSB_LOG_TABLE,
        [
            'run_date'   => current_time( 'mysql' ),
            'blog_id'    => $entry['blog_id'],
            'site_slug'  => $entry['site_slug'],
            'status'     => $entry['status'],
            'file_name'  => $entry['file_name']  ?? null,
            'file_size'  => $entry['file_size']  ?? null,
            'duration_s' => $entry['duration_s'] ?? null,
            'message'    => $entry['message']    ?? null,
        ],
        [ '%s', '%d', '%s', '%s', '%s', '%d', '%f', '%s' ]
    );
}

// ─── Main Backup Runner ───────────────────────────────────────────────────────
function msb_run_backups() {
    $sites    = get_sites( [ 'number' => 500, 'fields' => 'ids' ] );
    $settings = msb_get_settings();
    $uploader = new MSB_B2_Uploader( $settings );

    foreach ( $sites as $blog_id ) {
        $blog_id = (int) $blog_id;
        $details = get_blog_details( $blog_id );
        $slug    = $details ? sanitize_title( $details->blogname ) : 'site' . $blog_id;

        $start    = microtime( true );
        $exporter = new MSB_DB_Exporter( $blog_id, $slug );
        $export   = $exporter->export();

        if ( is_wp_error( $export ) ) {
            msb_log( [
                'blog_id'   => $blog_id,
                'site_slug' => $slug,
                'status'    => 'failed',
                'message'   => $export->get_error_message(),
            ] );
            continue;
        }

        $file_path = $export['path'];
        $file_name = $export['name'];
        $file_size = filesize( $file_path );

        $upload = $uploader->upload( $file_path, $file_name, $blog_id, $slug );

        $duration = round( microtime( true ) - $start, 2 );

        if ( is_wp_error( $upload ) ) {
            msb_log( [
                'blog_id'    => $blog_id,
                'site_slug'  => $slug,
                'status'     => 'failed',
                'file_name'  => $file_name,
                'file_size'  => $file_size,
                'duration_s' => $duration,
                'message'    => $upload->get_error_message(),
            ] );
            // Retain local file for retry — do not delete.
            continue;
        }

        @unlink( $file_path );

        msb_log( [
            'blog_id'    => $blog_id,
            'site_slug'  => $slug,
            'status'     => 'success',
            'file_name'  => $file_name,
            'file_size'  => $file_size,
            'duration_s' => $duration,
            'message'    => 'Uploaded to B2.',
        ] );

        $uploader->prune_old_backups( $slug, 14 );
    }
}

// ─── Settings ─────────────────────────────────────────────────────────────────
function msb_get_settings(): array {
    return [
        'endpoint' => get_site_option( 'msb_b2_endpoint', '' ),
        'bucket'   => get_site_option( 'msb_b2_bucket',   '' ),
        'key_id'   => get_site_option( 'msb_b2_key_id',   '' ),
        'app_key'  => get_site_option( 'msb_b2_app_key',  '' ),
        'prefix'   => get_site_option( 'msb_b2_prefix',   'per-site-backups/' ),
    ];
}

function msb_save_settings() {
    check_admin_referer( 'msb_settings' );

    if ( ! current_user_can( 'manage_network_options' ) ) {
        wp_die( 'Unauthorized.' );
    }

    $fields = [ 'msb_b2_endpoint', 'msb_b2_bucket', 'msb_b2_key_id', 'msb_b2_app_key', 'msb_b2_prefix' ];
    foreach ( $fields as $field ) {
        update_site_option( $field, sanitize_text_field( wp_unslash( $_POST[ $field ] ?? '' ) ) );
    }

    wp_redirect( add_query_arg( [ 'page' => 'msb-site-backups', 'updated' => '1' ], network_admin_url( 'admin.php' ) ) );
    exit;
}

// ─── Manual Run Now ───────────────────────────────────────────────────────────
function msb_handle_run_now() {
    check_admin_referer( 'msb_run_now' );

    if ( ! current_user_can( 'manage_network_options' ) ) {
        wp_die( 'Unauthorized.' );
    }

    msb_run_backups();

    wp_redirect( add_query_arg( [ 'page' => 'msb-site-backups', 'ran' => '1' ], network_admin_url( 'admin.php' ) ) );
    exit;
}

// ─── Network Admin Settings Page ──────────────────────────────────────────────
function msb_register_settings_page() {
    // add_submenu_page under settings.php generates a broken URL on some WP Engine
    // multisite setups (/network/msb-site-backups instead of settings.php?page=…).
    // Using add_menu_page produces a reliable admin.php?page= URL, then we
    // immediately inject it into the Settings submenu and remove the top-level entry.
    add_menu_page(
        'Site Backups',
        'Site Backups',
        'manage_network_options',
        'msb-site-backups',
        'msb_render_settings_page'
    );

    // Move into Settings submenu so it appears in the right place in the nav.
    add_submenu_page(
        'settings.php',
        'Site Backups',
        'Site Backups',
        'manage_network_options',
        'msb-site-backups',
        'msb_render_settings_page'
    );

    // Remove the top-level menu entry — leave only the Settings child.
    remove_menu_page( 'msb-site-backups' );
}

function msb_render_settings_page() {
    global $wpdb;

    $settings = msb_get_settings();
    ?>
    <div class="wrap">
        <h1>Multi-Site Content Backup</h1>

        <?php if ( ! empty( $_GET['updated'] ) ) : ?>
            <div class="notice notice-success"><p>Settings saved.</p></div>
        <?php endif; ?>
        <?php if ( ! empty( $_GET['ran'] ) ) : ?>
            <div class="notice notice-success"><p>Backup run completed. Check the log below.</p></div>
        <?php endif; ?>

        <h2>B2 Connection Settings</h2>
        <form method="post" action="<?php echo esc_url( network_admin_url( 'edit.php?action=msb_save_settings' ) ); ?>">
            <?php wp_nonce_field( 'msb_settings' ); ?>
            <table class="form-table">
                <?php
                $fields = [
                    'msb_b2_endpoint' => [ 'label' => 'B2 Endpoint',      'placeholder' => 'https://s3.us-west-004.backblazeb2.com' ],
                    'msb_b2_bucket'   => [ 'label' => 'Bucket Name',      'placeholder' => 'my-bucket' ],
                    'msb_b2_key_id'   => [ 'label' => 'Key ID',           'placeholder' => '' ],
                    'msb_b2_app_key'  => [ 'label' => 'Application Key',  'placeholder' => '', 'type' => 'password' ],
                    'msb_b2_prefix'   => [ 'label' => 'Folder Prefix',    'placeholder' => 'per-site-backups/' ],
                ];
                foreach ( $fields as $key => $field ) :
                    $type        = $field['type'] ?? 'text';
                    $setting_key = str_replace( 'msb_b2_', '', $key );
                ?>
                <tr>
                    <th><label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $field['label'] ); ?></label></th>
                    <td>
                        <input type="<?php echo esc_attr( $type ); ?>"
                               id="<?php echo esc_attr( $key ); ?>"
                               name="<?php echo esc_attr( $key ); ?>"
                               value="<?php echo esc_attr( $settings[ $setting_key ] ?? '' ); ?>"
                               placeholder="<?php echo esc_attr( $field['placeholder'] ); ?>"
                               class="regular-text">
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
            <?php submit_button( 'Save Settings' ); ?>
        </form>

        <h2>Run Backup Now</h2>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'msb_run_now' ); ?>
            <input type="hidden" name="action" value="msb_run_now">
            <?php submit_button( 'Run Full Backup Now', 'secondary' ); ?>
        </form>

        <h2>Recent Backup Log</h2>
        <?php
        $table_exists = $wpdb->get_var( "SHOW TABLES LIKE '" . MSB_LOG_TABLE . "'" );
        if ( $table_exists ) :
            $logs = $wpdb->get_results(
                "SELECT * FROM " . MSB_LOG_TABLE . " ORDER BY run_date DESC LIMIT 100"
            );
        else :
            $logs = [];
        endif;
        ?>
        <?php if ( empty( $logs ) ) : ?>
            <p>No log entries yet.</p>
        <?php else : ?>
            <style>
                #msb-log-table { border-collapse: collapse; width: 100%; }
                #msb-log-table th, #msb-log-table td { padding: 6px 10px; border: 1px solid #ccd0d4; text-align: left; font-size: 13px; }
                #msb-log-table th { background: #f0f0f1; }
                .msb-success { color: #00a32a; font-weight: 600; }
                .msb-failed  { color: #d63638; font-weight: 600; }
                #msb-filter  { margin-bottom: 8px; padding: 4px 8px; }
            </style>
            <input type="text" id="msb-filter" placeholder="Filter by site slug…">
            <table id="msb-log-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Blog ID</th>
                        <th>Site</th>
                        <th>Status</th>
                        <th>File</th>
                        <th>Size</th>
                        <th>Duration</th>
                        <th>Message</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $logs as $row ) : ?>
                    <tr>
                        <td><?php echo esc_html( $row->run_date ); ?></td>
                        <td><?php echo esc_html( $row->blog_id ); ?></td>
                        <td><?php echo esc_html( $row->site_slug ); ?></td>
                        <td class="msb-<?php echo esc_attr( $row->status ); ?>"><?php echo esc_html( $row->status ); ?></td>
                        <td><?php echo esc_html( $row->file_name ?? '—' ); ?></td>
                        <td><?php echo $row->file_size ? esc_html( size_format( $row->file_size ) ) : '—'; ?></td>
                        <td><?php echo $row->duration_s ? esc_html( $row->duration_s . 's' ) : '—'; ?></td>
                        <td><?php echo esc_html( $row->message ?? '' ); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <script>
            document.getElementById('msb-filter').addEventListener('input', function() {
                var q = this.value.toLowerCase();
                document.querySelectorAll('#msb-log-table tbody tr').forEach(function(row) {
                    row.style.display = row.cells[2].textContent.toLowerCase().includes(q) ? '' : 'none';
                });
            });
            </script>
        <?php endif; ?>
    </div>
    <?php
}
