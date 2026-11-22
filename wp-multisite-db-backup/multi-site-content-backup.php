<?php
/**
 * Core functionality for WP Multisite DB Backups
 *
 * @package WP_Multisite_DB_Backups
 * @version 1.0.2
 */

defined( 'ABSPATH' ) || exit;

// ─── Constants ───────────────────────────────────────────────────────────────
if ( ! defined( 'MSB_VERSION' ) ) {
    define( 'MSB_VERSION', '1.0.2' );
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
/**
 * Normalize frequency hours. Empty/invalid values fall back to 24 so a blank
 * settings field cannot accidentally schedule hourly runs.
 */
function msb_normalize_frequency_hours( $hours ): int {
    $hours = (int) $hours;
    return $hours >= 1 ? $hours : 24;
}

function msb_add_cron_interval( array $schedules ): array {
    $hours = msb_normalize_frequency_hours( get_site_option( 'msb_backup_frequency_hours', 24 ) );
    $schedules['msb_custom_interval'] = [
        'interval' => $hours * HOUR_IN_SECONDS,
        'display'  => sprintf( 'Every %d hour(s)', $hours ),
    ];
    return $schedules;
}

/**
 * Next run timestamp in the future, honouring timezone + start time.
 * Keeps adding the frequency until the result is actually after now
 * (avoids scheduling a past timestamp that WP-Cron fires immediately).
 */
function msb_next_run_timestamp( int $frequency_hours, string $start_time ): int {
    $frequency_hours = msb_normalize_frequency_hours( $frequency_hours );
    $tz    = wp_timezone();
    $now   = new DateTimeImmutable( 'now', $tz );
    $parts = explode( ':', $start_time );
    $hour  = max( 0, min( 23, (int) ( $parts[0] ?? 2 ) ) );
    $min   = max( 0, min( 59, (int) ( $parts[1] ?? 0 ) ) );
    $next  = $now->setTime( $hour, $min, 0 );

    // Guard against infinite loops if frequency is somehow zero.
    $safety = 0;
    while ( $next <= $now && $safety < 1000 ) {
        $next = $next->modify( "+{$frequency_hours} hours" );
        $safety++;
    }

    return $next->getTimestamp();
}

/**
 * Inspect all cron entries for our hook (duplicates + stored interval).
 *
 * @return array{count:int,interval:?int}
 */
function msb_cron_event_meta(): array {
    $crons = _get_cron_array();
    $count = 0;
    $interval = null;

    if ( is_array( $crons ) ) {
        foreach ( $crons as $hooks ) {
            if ( empty( $hooks[ MSB_CRON_HOOK ] ) || ! is_array( $hooks[ MSB_CRON_HOOK ] ) ) {
                continue;
            }
            foreach ( $hooks[ MSB_CRON_HOOK ] as $event ) {
                $count++;
                if ( isset( $event['interval'] ) ) {
                    $interval = (int) $event['interval'];
                }
            }
        }
    }

    return [
        'count'    => $count,
        'interval' => $interval,
    ];
}

/**
 * Clear any existing events and schedule exactly one with the current settings.
 */
function msb_reschedule_cron( ?int $frequency_hours = null, ?string $start_time = null ): void {
    wp_clear_scheduled_hook( MSB_CRON_HOOK );

    $hours = msb_normalize_frequency_hours(
        $frequency_hours ?? get_site_option( 'msb_backup_frequency_hours', 24 )
    );
    $start = $start_time ?? get_site_option( 'msb_backup_start_time', '02:00' );
    if ( ! is_string( $start ) || ! preg_match( '/^\d{2}:\d{2}$/', $start ) ) {
        $start = '02:00';
    }

    wp_schedule_event(
        msb_next_run_timestamp( $hours, $start ),
        'msb_custom_interval',
        MSB_CRON_HOOK
    );
}

/**
 * Ensure a single cron event exists with the correct interval.
 * Fixes duplicates and stale intervals left over from earlier settings.
 */
function msb_schedule_cron() {
    $hours             = msb_normalize_frequency_hours( get_site_option( 'msb_backup_frequency_hours', 24 ) );
    $expected_interval = $hours * HOUR_IN_SECONDS;
    $meta              = msb_cron_event_meta();
    $schedule_name     = wp_get_schedule( MSB_CRON_HOOK );

    $needs_reschedule = (
        $meta['count'] !== 1
        || $schedule_name !== 'msb_custom_interval'
        || (int) $meta['interval'] !== $expected_interval
    );

    if ( $needs_reschedule ) {
        msb_reschedule_cron( $hours );
    }
}

function msb_activate_plugin() {
    msb_maybe_create_log_table();
    msb_reschedule_cron();
}

function msb_deactivate_plugin() {
    wp_clear_scheduled_hook( MSB_CRON_HOOK );
    delete_site_transient( 'msb_backup_running' );
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
/**
 * @param bool $force When true (manual "Run Now"), skip the recent-run cooldown.
 */
function msb_run_backups( bool $force = false ) {
    // Prevent overlapping runs (cron + manual, or duplicate cron events).
    if ( get_site_transient( 'msb_backup_running' ) ) {
        return;
    }

    $frequency_hours = msb_normalize_frequency_hours( get_site_option( 'msb_backup_frequency_hours', 24 ) );

    // Scheduled runs: skip if a full run completed too recently (stops WP-Cron
    // catch-up / duplicate events from producing multiple backups per day).
    if ( ! $force ) {
        $last    = (int) get_site_option( 'msb_last_backup_run', 0 );
        $min_gap = (int) ( $frequency_hours * HOUR_IN_SECONDS * 0.75 );
        if ( $last > 0 && ( time() - $last ) < $min_gap ) {
            return;
        }
    }

    set_site_transient( 'msb_backup_running', 1, 30 * MINUTE_IN_SECONDS );

    $sites    = get_sites( [ 'number' => 500, 'fields' => 'ids' ] );
    $settings = msb_get_settings();
    $uploader = new MSB_B2_Uploader( $settings );

    try {
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

            $destination = sprintf(
                '%s (%s)',
                $uploader->get_provider_label(),
                $settings['bucket'] !== '' ? $settings['bucket'] : 'no bucket'
            );

            msb_log( [
                'blog_id'    => $blog_id,
                'site_slug'  => $slug,
                'status'     => 'success',
                'file_name'  => $file_name,
                'file_size'  => $file_size,
                'duration_s' => $duration,
                'message'    => 'Uploaded to ' . $destination . '.',
            ] );

            if ( $settings['email'] && $settings['notify_success'] ) {
                $subject = sprintf( '[%s] Backup Succeeded: Site %s (ID: %d)', get_network()->site_name, $slug, $blog_id );
                $message = sprintf(
                    "Backup completed successfully for site: %s (Blog ID: %d)\n\nDestination: %s\nFile: %s\nSize: %s\nDuration: %ss\n\nLogged at: %s",
                    $slug,
                    $blog_id,
                    $destination,
                    $file_name,
                    size_format( $file_size ),
                    $duration,
                    current_time( 'mysql' )
                );
                wp_mail( $settings['email'], $subject, $message );
            }

            $uploader->prune_old_backups( $slug, 14 );
        }
    } finally {
        update_site_option( 'msb_last_backup_run', time() );
        delete_site_transient( 'msb_backup_running' );
    }
}

// ─── Settings ─────────────────────────────────────────────────────────────────
function msb_get_provider(): string {
    $provider = get_site_option( 'msb_storage_provider', 'b2' );
    return $provider === 'r2' ? 'r2' : 'b2';
}

/**
 * Full settings for the admin UI (both providers + schedule/notifications).
 */
function msb_get_all_settings(): array {
    return [
        'provider'         => msb_get_provider(),
        'b2'               => [
            'endpoint' => get_site_option( 'msb_b2_endpoint', '' ),
            'bucket'   => get_site_option( 'msb_b2_bucket',   '' ),
            'key_id'   => get_site_option( 'msb_b2_key_id',   '' ),
            'app_key'  => get_site_option( 'msb_b2_app_key',  '' ),
            'prefix'   => get_site_option( 'msb_b2_prefix',   'per-site-backups/' ),
        ],
        'r2'               => [
            'endpoint' => get_site_option( 'msb_r2_endpoint', '' ),
            'bucket'   => get_site_option( 'msb_r2_bucket',   '' ),
            'key_id'   => get_site_option( 'msb_r2_key_id',   '' ),
            'app_key'  => get_site_option( 'msb_r2_app_key',  '' ),
            'prefix'   => get_site_option( 'msb_r2_prefix',   'per-site-backups/' ),
        ],
        'email'            => get_site_option( 'msb_notification_email', '' ),
        'frequency_hours'  => msb_normalize_frequency_hours( get_site_option( 'msb_backup_frequency_hours', 24 ) ),
        'start_time'       => get_site_option( 'msb_backup_start_time', '02:00' ),
        'notify_success'   => (bool) get_site_option( 'msb_notify_on_success', false ),
        'notify_failure'   => (bool) get_site_option( 'msb_notify_on_failure', true ),
    ];
}

/**
 * Active storage credentials for the uploader (selected provider only).
 */
function msb_get_settings(): array {
    $all      = msb_get_all_settings();
    $provider = $all['provider'];
    $creds    = $all[ $provider ];

    return array_merge( $creds, [
        'provider'         => $provider,
        'email'            => $all['email'],
        'frequency_hours'  => $all['frequency_hours'],
        'start_time'       => $all['start_time'],
        'notify_success'   => $all['notify_success'],
        'notify_failure'   => $all['notify_failure'],
    ] );
}

function msb_save_settings() {
    check_admin_referer( 'msb_settings' );

    if ( ! current_user_can( 'manage_network_options' ) ) {
        wp_die( 'Unauthorized.' );
    }

    $provider = ( $_POST['msb_storage_provider'] ?? 'b2' ) === 'r2' ? 'r2' : 'b2';
    update_site_option( 'msb_storage_provider', $provider );

    $storage_fields = [
        'msb_b2_endpoint', 'msb_b2_bucket', 'msb_b2_key_id', 'msb_b2_app_key', 'msb_b2_prefix',
        'msb_r2_endpoint', 'msb_r2_bucket', 'msb_r2_key_id', 'msb_r2_app_key', 'msb_r2_prefix',
    ];
    foreach ( $storage_fields as $field ) {
        update_site_option( $field, sanitize_text_field( wp_unslash( $_POST[ $field ] ?? '' ) ) );
    }

    update_site_option( 'msb_notification_email', sanitize_email( wp_unslash( $_POST['msb_notification_email'] ?? '' ) ) );
    update_site_option( 'msb_notify_on_success', isset( $_POST['msb_notify_on_success'] ) ? 1 : 0 );
    update_site_option( 'msb_notify_on_failure', isset( $_POST['msb_notify_on_failure'] ) ? 1 : 0 );

    $new_frequency  = msb_normalize_frequency_hours( $_POST['msb_backup_frequency_hours'] ?? 24 );
    $new_start_time = sanitize_text_field( wp_unslash( $_POST['msb_backup_start_time'] ?? '02:00' ) );
    // Validate HH:MM format; fall back to stored value if invalid.
    if ( ! preg_match( '/^\d{2}:\d{2}$/', $new_start_time ) ) {
        $new_start_time = get_site_option( 'msb_backup_start_time', '02:00' );
    }
    update_site_option( 'msb_backup_frequency_hours', $new_frequency );
    update_site_option( 'msb_backup_start_time', $new_start_time );

    // Always clear + reschedule on save so duplicates/stale intervals cannot linger.
    msb_reschedule_cron( $new_frequency, $new_start_time );

    wp_redirect( add_query_arg( [ 'page' => 'msb-site-backups', 'updated' => '1' ], network_admin_url( 'admin.php' ) ) );
    exit;
}

// ─── Manual Run Now ───────────────────────────────────────────────────────────
function msb_handle_run_now() {
    check_admin_referer( 'msb_run_now' );

    if ( ! current_user_can( 'manage_network_options' ) ) {
        wp_die( 'Unauthorized.' );
    }

    msb_run_backups( true );

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
        'DB Backup Settings',
        'DB Backups',
        'manage_network_options',
        'msb-site-backups',
        'msb_render_settings_page'
    );

    // Move into Settings submenu so it appears in the right place in the nav.
    add_submenu_page(
        'settings.php',
        'DB Backup Settings',
        'DB Backups',
        'manage_network_options',
        'msb-site-backups',
        'msb_render_settings_page'
    );

    // Remove the top-level menu entry — leave only the Settings child.
    remove_menu_page( 'msb-site-backups' );
}

function msb_render_settings_page() {
    global $wpdb;

    $settings = msb_get_all_settings();
    $provider = $settings['provider'];
    $active   = $settings[ $provider ];
    $active_label = $provider === 'r2' ? 'Cloudflare R2' : 'Backblaze B2';
    ?>
    <div class="wrap">
        <h1>DB Backup Settings</h1>

        <p class="description" style="max-width: 800px; margin: 8px 0 16px;">
            Automatically backs up the database of every site in this network to cloud storage
            (
            <a href="https://www.backblaze.com/cloud-storage" target="_blank" rel="noopener">Backblaze B2</a>
            or
            <a href="https://developers.cloudflare.com/r2/" target="_blank" rel="noopener">Cloudflare R2</a>
            )
            on a configurable schedule. Each site's database is exported, compressed, and uploaded
            as a separate <code>.sql.gz</code> file organised by site slug. Choose a destination,
            configure credentials below, set how often backups should run, and choose whether to
            receive email notifications on success or failure.
        </p>

        <?php if ( ! empty( $_GET['updated'] ) ) : ?>
            <div class="notice notice-success"><p>Settings saved.</p></div>
        <?php endif; ?>
        <?php if ( ! empty( $_GET['ran'] ) ) : ?>
            <div class="notice notice-success"><p>Backup run completed. Check the log below — each success row shows which destination received the file.</p></div>
        <?php endif; ?>

        <div class="notice notice-info" style="max-width: 800px;">
            <p>
                <strong>Active destination:</strong>
                <?php echo esc_html( $active_label ); ?>
                <?php if ( $active['bucket'] !== '' ) : ?>
                    — bucket <code><?php echo esc_html( $active['bucket'] ); ?></code>
                <?php endif; ?>
                <?php if ( $active['endpoint'] !== '' ) : ?>
                    at <code><?php echo esc_html( $active['endpoint'] ); ?></code>
                <?php endif; ?>
            </p>
            <p class="description" style="margin: 0;">
                Only this destination is used for manual and scheduled uploads. The other provider’s
                credentials are stored for convenience but are not written to unless selected.
            </p>
        </div>

        <h2>Storage Destination</h2>
        <form method="post" action="<?php echo esc_url( network_admin_url( 'edit.php?action=msb_save_settings' ) ); ?>">
            <?php wp_nonce_field( 'msb_settings' ); ?>
            <table class="form-table">
                <tr>
                    <th>Provider</th>
                    <td>
                        <fieldset>
                            <label style="margin-right: 16px;">
                                <input type="radio"
                                       name="msb_storage_provider"
                                       value="b2"
                                       <?php checked( $provider, 'b2' ); ?>
                                       class="msb-provider-toggle">
                                Backblaze B2
                            </label>
                            <label>
                                <input type="radio"
                                       name="msb_storage_provider"
                                       value="r2"
                                       <?php checked( $provider, 'r2' ); ?>
                                       class="msb-provider-toggle">
                                Cloudflare R2
                            </label>
                        </fieldset>
                        <p class="description">Choose one destination. Credentials for both can be saved, but each backup run uploads to the selected provider only.</p>
                    </td>
                </tr>
            </table>

            <div id="msb-provider-b2" class="msb-provider-fields" <?php echo $provider === 'b2' ? '' : 'style="display:none;"'; ?>>
                <h2>Backblaze B2 Connection</h2>
                <table class="form-table">
                    <?php
                    $b2_fields = [
                        'msb_b2_endpoint' => [ 'label' => 'B2 Endpoint',     'key' => 'endpoint', 'placeholder' => 'https://s3.us-west-004.backblazeb2.com' ],
                        'msb_b2_bucket'   => [ 'label' => 'Bucket Name',     'key' => 'bucket',   'placeholder' => 'my-bucket' ],
                        'msb_b2_key_id'   => [ 'label' => 'Key ID',          'key' => 'key_id',   'placeholder' => '' ],
                        'msb_b2_app_key'  => [ 'label' => 'Application Key', 'key' => 'app_key',  'placeholder' => '', 'type' => 'password' ],
                        'msb_b2_prefix'   => [ 'label' => 'Folder Prefix',   'key' => 'prefix',   'placeholder' => 'per-site-backups/' ],
                    ];
                    foreach ( $b2_fields as $key => $field ) :
                        $type = $field['type'] ?? 'text';
                    ?>
                    <tr>
                        <th><label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $field['label'] ); ?></label></th>
                        <td>
                            <input type="<?php echo esc_attr( $type ); ?>"
                                   id="<?php echo esc_attr( $key ); ?>"
                                   name="<?php echo esc_attr( $key ); ?>"
                                   value="<?php echo esc_attr( $settings['b2'][ $field['key'] ] ?? '' ); ?>"
                                   placeholder="<?php echo esc_attr( $field['placeholder'] ); ?>"
                                   class="regular-text"
                                   autocomplete="off">
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>

            <div id="msb-provider-r2" class="msb-provider-fields" <?php echo $provider === 'r2' ? '' : 'style="display:none;"'; ?>>
                <h2>Cloudflare R2 Connection</h2>
                <table class="form-table">
                    <?php
                    $r2_fields = [
                        'msb_r2_endpoint' => [ 'label' => 'R2 Endpoint',        'key' => 'endpoint', 'placeholder' => 'https://<ACCOUNT_ID>.r2.cloudflarestorage.com' ],
                        'msb_r2_bucket'   => [ 'label' => 'Bucket Name',        'key' => 'bucket',   'placeholder' => 'my-bucket' ],
                        'msb_r2_key_id'   => [ 'label' => 'Access Key ID',      'key' => 'key_id',   'placeholder' => '' ],
                        'msb_r2_app_key'  => [ 'label' => 'Secret Access Key',  'key' => 'app_key',  'placeholder' => '', 'type' => 'password' ],
                        'msb_r2_prefix'   => [ 'label' => 'Folder Prefix',      'key' => 'prefix',   'placeholder' => 'per-site-backups/' ],
                    ];
                    foreach ( $r2_fields as $key => $field ) :
                        $type = $field['type'] ?? 'text';
                    ?>
                    <tr>
                        <th><label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $field['label'] ); ?></label></th>
                        <td>
                            <input type="<?php echo esc_attr( $type ); ?>"
                                   id="<?php echo esc_attr( $key ); ?>"
                                   name="<?php echo esc_attr( $key ); ?>"
                                   value="<?php echo esc_attr( $settings['r2'][ $field['key'] ] ?? '' ); ?>"
                                   placeholder="<?php echo esc_attr( $field['placeholder'] ); ?>"
                                   class="regular-text"
                                   autocomplete="off">
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </table>
                <p class="description" style="max-width: 800px;">
                    Create an R2 API token in the Cloudflare dashboard with Object Read &amp; Write
                    permissions. Use the S3 API endpoint for your account
                    (<code>https://&lt;ACCOUNT_ID&gt;.r2.cloudflarestorage.com</code>).
                </p>
            </div>

            <h2>Schedule &amp; Notifications</h2>
            <table class="form-table">
                <tr>
                    <th><label for="msb_notification_email">Notification Email</label></th>
                    <td>
                        <input type="email"
                               id="msb_notification_email"
                               name="msb_notification_email"
                               value="<?php echo esc_attr( $settings['email'] ); ?>"
                               placeholder="admin@example.com"
                               class="regular-text">
                    </td>
                </tr>
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
                <tr>
                    <th><label for="msb_backup_start_time">Backup Start Time</label></th>
                    <td>
                        <input type="time"
                               id="msb_backup_start_time"
                               name="msb_backup_start_time"
                               value="<?php echo esc_attr( $settings['start_time'] ); ?>">
                        <p class="description">
                            Time of day to run the first backup (uses the site timezone: <strong><?php echo esc_html( wp_timezone_string() ); ?></strong>).
                            Subsequent runs follow the frequency interval above.
                        </p>
                    </td>
                </tr>
                <tr>
                    <th>Next Scheduled Run</th>
                    <td>
                        <?php
                        $next_ts   = wp_next_scheduled( MSB_CRON_HOOK );
                        $cron_meta = msb_cron_event_meta();
                        if ( $next_ts ) {
                            echo esc_html(
                                wp_date( 'Y-m-d H:i:s T', $next_ts )
                            );
                            echo ' <span class="description">(' . esc_html(
                                sprintf( 'every %d hour(s)', $settings['frequency_hours'] )
                            ) . ')</span>';
                        } else {
                            echo '<em>Not scheduled</em>';
                        }
                        if ( $cron_meta['count'] > 1 ) {
                            echo '<p class="description" style="color:#d63638;">Warning: ' . esc_html( (string) $cron_meta['count'] ) . ' cron events found for this hook. Saving settings will collapse them to one.</p>';
                        }
                        ?>
                    </td>
                </tr>
            </table>
            <?php submit_button( 'Save Settings' ); ?>
        </form>
        <script>
        (function () {
            var toggles = document.querySelectorAll('.msb-provider-toggle');
            function syncProviderFields() {
                var selected = 'b2';
                toggles.forEach(function (el) {
                    if (el.checked) selected = el.value;
                });
                document.getElementById('msb-provider-b2').style.display = selected === 'b2' ? '' : 'none';
                document.getElementById('msb-provider-r2').style.display = selected === 'r2' ? '' : 'none';
            }
            toggles.forEach(function (el) {
                el.addEventListener('change', syncProviderFields);
            });
        })();
        </script>

        <h2>Run Backup Now</h2>
        <p class="description">
            This will upload to <strong><?php echo esc_html( $active_label ); ?></strong>
            <?php if ( $active['bucket'] !== '' ) : ?>
                (<code><?php echo esc_html( $active['bucket'] ); ?></code>)
            <?php endif; ?>
            only. Save settings first if you just changed the provider.
        </p>
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

        <p style="margin-top: 32px; color: #646970; font-size: 12px;">
            Built with love, AI &amp; coffee by <a href="https://637digital.com" target="_blank" rel="noopener">637 Digital</a>.
        </p>
    </div>
    <?php
}
