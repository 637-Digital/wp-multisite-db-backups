<?php
/**
 * Core functionality for WP Multisite DB Backups
 *
 * @package WP_Multisite_DB_Backups
 * @version 1.0.0
 */

defined( 'ABSPATH' ) || exit;

// ─── Constants ───────────────────────────────────────────────────────────────
if ( ! defined( 'MSB_VERSION' ) ) {
    define( 'MSB_VERSION', '1.0.0' );
}
define( 'MSB_DIR', plugin_dir_path( __FILE__ ) );
define( 'MSB_CRON_HOOK', 'msb_run_backups' );
define( 'MSB_LOG_TABLE', 'wp_msb_backup_log' );

// ─── Autoload classes ─────────────────────────────────────────────────────────
require_once MSB_DIR . 'class-db-exporter.php';
require_once MSB_DIR . 'class-b2-uploader.php';

// ─── Bootstrap ───────────────────────────────────────────────────────────────
add_action( 'init', 'msb_maybe_create_log_table' );
add_action( MSB_CRON_HOOK, 'msb_run_backups' );
add_action( 'init', 'msb_schedule_cron' );
add_filter( 'cron_schedules', 'msb_add_cron_interval' );

// Network settings page.
add_action( 'network_admin_menu', 'msb_register_settings_page' );
add_action( 'network_admin_edit_msb_save_settings', 'msb_save_settings' );

// Manual "Run Now" via admin-post.
add_action( 'admin_post_msb_run_now', 'msb_handle_run_now' );

// ─── Cron Registration ────────────────────────────────────────────────────────
function msb_add_cron_interval( array $schedules ): array {
    $hours = max( 1, (int) get_site_option( 'msb_backup_frequency_hours', 24 ) );
    $schedules['msb_custom_interval'] = [
        'interval' => $hours * HOUR_IN_SECONDS,
        'display'  => sprintf( 'Every %d hour(s)', $hours ),
    ];
    return $schedules;
}

function msb_schedule_cron() {
    // Replace any existing event that isn't using our custom interval (e.g. old 'daily' schedule).
    if ( wp_next_scheduled( MSB_CRON_HOOK ) && wp_get_schedule( MSB_CRON_HOOK ) !== 'msb_custom_interval' ) {
        wp_clear_scheduled_hook( MSB_CRON_HOOK );
    }
    if ( ! wp_next_scheduled( MSB_CRON_HOOK ) ) {
        wp_schedule_event( time() + HOUR_IN_SECONDS, 'msb_custom_interval', MSB_CRON_HOOK );
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
            if ( $settings['email'] && $settings['notify_failure'] ) {
                $subject = sprintf( '[%s] Backup Export Failed: Site %s (ID: %d)', get_network()->site_name, $slug, $blog_id );
                $message = sprintf(
                    "Database export failed for site: %s (Blog ID: %d)\n\nError: %s\n\nLogged at: %s",
                    $slug,
                    $blog_id,
                    $export->get_error_message(),
                    current_time( 'mysql' )
                );
                wp_mail( $settings['email'], $subject, $message );
            }
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
            if ( $settings['email'] && $settings['notify_failure'] ) {
                $subject = sprintf( '[%s] Backup Failed: Site %s (ID: %d)', get_network()->site_name, $slug, $blog_id );
                $message = sprintf(
                    "Backup failed for site: %s (Blog ID: %d)\n\nError: %s\n\nFile: %s\nSize: %s bytes\nDuration: %ss\n\nLogged at: %s",
                    $slug,
                    $blog_id,
                    $upload->get_error_message(),
                    $file_name,
                    size_format( $file_size ),
                    $duration,
                    current_time( 'mysql' )
                );
                wp_mail( $settings['email'], $subject, $message );
            }

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

        if ( $settings['email'] && $settings['notify_success'] ) {
            $subject = sprintf( '[%s] Backup Succeeded: Site %s (ID: %d)', get_network()->site_name, $slug, $blog_id );
            $message = sprintf(
                "Backup completed successfully for site: %s (Blog ID: %d)\n\nFile: %s\nSize: %s\nDuration: %ss\n\nLogged at: %s",
                $slug,
                $blog_id,
                $file_name,
                size_format( $file_size ),
                $duration,
                current_time( 'mysql' )
            );
            wp_mail( $settings['email'], $subject, $message );
        }

        $uploader->prune_old_backups( $slug, 14 );
    }
}

// ─── Settings ─────────────────────────────────────────────────────────────────
function msb_get_settings(): array {
    return [
        'endpoint'         => get_site_option( 'msb_b2_endpoint', '' ),
        'bucket'           => get_site_option( 'msb_b2_bucket',   '' ),
        'key_id'           => get_site_option( 'msb_b2_key_id',   '' ),
        'app_key'          => get_site_option( 'msb_b2_app_key',  '' ),
        'prefix'           => get_site_option( 'msb_b2_prefix',   'per-site-backups/' ),
        'email'            => get_site_option( 'msb_notification_email', '' ),
        'frequency_hours'  => max( 1, (int) get_site_option( 'msb_backup_frequency_hours', 24 ) ),
        'notify_success'   => (bool) get_site_option( 'msb_notify_on_success', false ),
        'notify_failure'   => (bool) get_site_option( 'msb_notify_on_failure', true ),
    ];
}

function msb_save_settings() {
    check_admin_referer( 'msb_settings' );

    if ( ! current_user_can( 'manage_network_options' ) ) {
        wp_die( 'Unauthorized.' );
    }

    $fields = [ 'msb_b2_endpoint', 'msb_b2_bucket', 'msb_b2_key_id', 'msb_b2_app_key', 'msb_b2_prefix', 'msb_notification_email' ];
    foreach ( $fields as $field ) {
        if ( $field === 'msb_notification_email' ) {
            update_site_option( $field, sanitize_email( wp_unslash( $_POST[ $field ] ?? '' ) ) );
        } else {
            update_site_option( $field, sanitize_text_field( wp_unslash( $_POST[ $field ] ?? '' ) ) );
        }
    }

    update_site_option( 'msb_notify_on_success', isset( $_POST['msb_notify_on_success'] ) ? 1 : 0 );
    update_site_option( 'msb_notify_on_failure', isset( $_POST['msb_notify_on_failure'] ) ? 1 : 0 );

    $new_frequency = max( 1, (int) ( $_POST['msb_backup_frequency_hours'] ?? 24 ) );
    $old_frequency = max( 1, (int) get_site_option( 'msb_backup_frequency_hours', 24 ) );
    update_site_option( 'msb_backup_frequency_hours', $new_frequency );

    if ( $new_frequency !== $old_frequency || wp_get_schedule( MSB_CRON_HOOK ) !== 'msb_custom_interval' ) {
        wp_clear_scheduled_hook( MSB_CRON_HOOK );
        wp_schedule_event( time() + $new_frequency * HOUR_IN_SECONDS, 'msb_custom_interval', MSB_CRON_HOOK );
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
        <h1>Multisite Content Backup</h1>

        <p class="description" style="max-width: 800px; margin: 8px 0 16px;">
            Automatically backs up the database of every site in this network to
            <a href="https://www.backblaze.com/cloud-storage" target="_blank" rel="noopener">Backblaze B2</a>
            on a configurable schedule. Each site's database is exported, compressed, and uploaded
            as a separate <code>.sql.gz</code> file organised by site slug. Configure your B2
            credentials below, set how often backups should run, and choose whether to receive
            email notifications on success or failure.
        </p>

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
                    'msb_b2_endpoint'       => [ 'label' => 'B2 Endpoint',       'placeholder' => 'https://s3.us-west-004.backblazeb2.com' ],
                    'msb_b2_bucket'         => [ 'label' => 'Bucket Name',       'placeholder' => 'my-bucket' ],
                    'msb_b2_key_id'         => [ 'label' => 'Key ID',            'placeholder' => '' ],
                    'msb_b2_app_key'        => [ 'label' => 'Application Key',   'placeholder' => '', 'type' => 'password' ],
                    'msb_b2_prefix'         => [ 'label' => 'Folder Prefix',     'placeholder' => 'per-site-backups/' ],
                    'msb_notification_email'=> [ 'label' => 'Notification Email', 'placeholder' => 'admin@example.com' ],
                ];
                foreach ( $fields as $key => $field ) :
                    $type        = $field['type'] ?? 'text';
                    if ( $key === 'msb_notification_email' ) {
                        $setting_key = 'email';
                    } else {
                        $setting_key = str_replace( 'msb_b2_', '', $key );
                    }
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
                <tr>
                    <th>Email Notifications</th>
                    <td>
                        <label>
                            <input type="checkbox"
                                   id="msb_notify_on_failure"
                                   name="msb_notify_on_failure"
                                   value="1"
                                   <?php checked( $settings['notify_failure'] ); ?>>
                            Notify on failed backups
                        </label>
                        <br>
                        <label>
                            <input type="checkbox"
                                   id="msb_notify_on_success"
                                   name="msb_notify_on_success"
                                   value="1"
                                   <?php checked( $settings['notify_success'] ); ?>>
                            Notify on successful backups
                        </label>
                    </td>
                </tr>
                <tr>
                    <th><label for="msb_backup_frequency_hours">Backup Frequency (hours)</label></th>
                    <td>
                        <input type="number"
                               id="msb_backup_frequency_hours"
                               name="msb_backup_frequency_hours"
                               value="<?php echo esc_attr( $settings['frequency_hours'] ); ?>"
                               min="1"
                               step="1"
                               class="small-text">
                        <p class="description">How often to run automatic backups. E.g. enter <strong>24</strong> for daily, <strong>12</strong> for twice a day.</p>
                    </td>
                </tr>
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
