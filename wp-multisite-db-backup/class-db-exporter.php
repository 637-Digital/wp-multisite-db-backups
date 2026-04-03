<?php
/**
 * MSB_DB_Exporter
 *
 * Exports all database tables for a single multisite blog to a gzipped SQL file.
 * Uses mysqldump via shell_exec() when available; falls back to a pure-PHP export.
 */

defined( 'ABSPATH' ) || exit;

class MSB_DB_Exporter {

    private int    $blog_id;
    private string $slug;

    public function __construct( int $blog_id, string $slug ) {
        $this->blog_id = $blog_id;
        $this->slug    = $slug;
    }

    // ─── Public ──────────────────────────────────────────────────────────────

    /**
     * Run the export.
     *
     * @return array{path: string, name: string}|WP_Error
     */
    public function export() {
        $tmp_dir = $this->get_tmp_dir();
        if ( is_wp_error( $tmp_dir ) ) {
            return $tmp_dir;
        }

        $date      = current_time( 'Y-m-d' );
        $file_name = "{$date}_site{$this->blog_id}_{$this->slug}.sql.gz";
        $file_path = trailingslashit( $tmp_dir ) . $file_name;

        $tables = $this->get_tables();
        if ( empty( $tables ) ) {
            return new WP_Error( 'no_tables', "No tables found for blog ID {$this->blog_id}." );
        }

        if ( $this->shell_exec_available() ) {
            $result = $this->export_via_mysqldump( $tables, $file_path );
        } else {
            $result = $this->export_via_php( $tables, $file_path );
        }

        return $result;
    }

    // ─── Table Discovery ─────────────────────────────────────────────────────

    private function get_tables(): array {
        global $wpdb;

        if ( $this->blog_id === 1 ) {
            // Main site: all wp_ tables EXCEPT wp_{digits}_ subsite prefixes.
            $all = $wpdb->get_col( "SHOW TABLES LIKE 'wp\_%'" );
            return array_values( array_filter( $all, static function ( $table ) {
                return ! preg_match( '/^wp_\d+_/', $table );
            } ) );
        }

        // Subsite: tables with wp_{blog_id}_ prefix.
        $like = 'wp_' . $wpdb->esc_like( $this->blog_id . '_' ) . '%';
        return $wpdb->get_col( $wpdb->prepare( "SHOW TABLES LIKE %s", $like ) );
    }

    // ─── Temp Directory ──────────────────────────────────────────────────────

    private function get_tmp_dir(): string|WP_Error {
        $primary = '/tmp/msb-backups';

        if ( $this->ensure_dir( $primary ) ) {
            return $primary;
        }

        $uploads  = wp_upload_dir();
        $fallback = $uploads['basedir'] . '/msb-backups';

        if ( $this->ensure_dir( $fallback ) ) {
            $htaccess = $fallback . '/.htaccess';
            if ( ! file_exists( $htaccess ) ) {
                file_put_contents( $htaccess, "deny from all\n" );
            }
            return $fallback;
        }

        $admin_email = get_site_option( 'admin_email' );
        wp_mail(
            $admin_email,
            '[Multi-Site Content Backup] Critical: Cannot write temp files',
            "Neither /tmp/msb-backups nor the uploads fallback directory is writable. Backup aborted."
        );

        return new WP_Error( 'no_tmp_dir', 'No writable temp directory available.' );
    }

    private function ensure_dir( string $path ): bool {
        if ( ! is_dir( $path ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
            @mkdir( $path, 0755, true );
        }
        return is_dir( $path ) && is_writable( $path );
    }

    // ─── Shell Detection ─────────────────────────────────────────────────────

    private function shell_exec_available(): bool {
        if ( ! function_exists( 'shell_exec' ) ) {
            return false;
        }
        $disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );
        return ! in_array( 'shell_exec', $disabled, true );
    }

    // ─── mysqldump Path ──────────────────────────────────────────────────────

    /**
     * @param string[] $tables
     */
    private function export_via_mysqldump( array $tables, string $out_path ): array|WP_Error {
        $user     = DB_USER;
        $password = DB_PASSWORD;
        $host     = DB_HOST;
        $db       = DB_NAME;

        $tables_str = implode( ' ', array_map( 'escapeshellarg', $tables ) );

        $cmd = sprintf(
            'MYSQL_PWD=%s mysqldump -u%s -h%s --single-transaction --skip-lock-tables %s %s 2>&1 | gzip > %s',
            escapeshellarg( $password ),
            escapeshellarg( $user ),
            escapeshellarg( $host ),
            escapeshellarg( $db ),
            $tables_str,
            escapeshellarg( $out_path )
        );

        $output = shell_exec( $cmd );

        if ( ! file_exists( $out_path ) || filesize( $out_path ) < 20 ) {
            return new WP_Error(
                'mysqldump_failed',
                "mysqldump failed for blog ID {$this->blog_id}. Output: " . (string) $output
            );
        }

        return [ 'path' => $out_path, 'name' => basename( $out_path ) ];
    }

    // ─── Pure-PHP Fallback ───────────────────────────────────────────────────

    /**
     * @param string[] $tables
     */
    private function export_via_php( array $tables, string $out_path ): array|WP_Error {
        global $wpdb;

        $sql  = "-- Multi-Site Content Backup | Blog ID {$this->blog_id} | Generated: " . current_time( 'mysql' ) . "\n";
        $sql .= "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n";

        foreach ( $tables as $table ) {
            $sql .= $this->dump_table( $table );
        }

        $gz = gzencode( $sql, 6 );
        if ( $gz === false ) {
            return new WP_Error( 'gzip_failed', "gzencode() failed for blog ID {$this->blog_id}." );
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        $written = file_put_contents( $out_path, $gz );
        if ( $written === false ) {
            return new WP_Error( 'write_failed', "Could not write export file: {$out_path}" );
        }

        return [ 'path' => $out_path, 'name' => basename( $out_path ) ];
    }

    private function dump_table( string $table ): string {
        global $wpdb;

        $sql  = "-- Table: {$table}\n";
        $sql .= "DROP TABLE IF EXISTS `{$table}`;\n";

        $create = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N );
        if ( $create ) {
            $sql .= $create[1] . ";\n\n";
        }

        $offset     = 0;
        $batch_size = 500;

        while ( true ) {
            $rows = $wpdb->get_results(
                $wpdb->prepare( "SELECT * FROM `{$table}` LIMIT %d OFFSET %d", $batch_size, $offset ),
                ARRAY_A
            );

            if ( empty( $rows ) ) {
                break;
            }

            $columns = '`' . implode( '`, `', array_keys( $rows[0] ) ) . '`';
            foreach ( $rows as $row ) {
                $values = array_map( [ $this, 'escape_value' ], array_values( $row ) );
                $sql   .= "INSERT INTO `{$table}` ({$columns}) VALUES (" . implode( ', ', $values ) . ");\n";
            }

            $offset += $batch_size;

            if ( count( $rows ) < $batch_size ) {
                break;
            }
        }

        $sql .= "\n";
        return $sql;
    }

    private function escape_value( mixed $value ): string {
        if ( $value === null ) {
            return 'NULL';
        }

        return "'" . esc_sql( $value ) . "'";
    }
}
