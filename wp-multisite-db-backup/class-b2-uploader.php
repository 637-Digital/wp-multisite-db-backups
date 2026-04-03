<?php
/**
 * MSB_B2_Uploader
 *
 * Uploads backup files to Backblaze B2 via the S3-compatible API.
 * Uses cURL directly for PUT uploads so binary gzip data is streamed
 * via CURLOPT_INFILE — avoiding null-byte truncation bugs in WordPress's
 * wp_remote_request string-body handling.
 *
 * Implements AWS Signature Version 4 signing.
 */

defined( 'ABSPATH' ) || exit;

class MSB_B2_Uploader {

    private string $endpoint;
    private string $bucket;
    private string $key_id;
    private string $app_key;
    private string $prefix;
    private string $region;

    public function __construct( array $settings ) {
        $this->endpoint = rtrim( $settings['endpoint'] ?? '', '/' );
        $this->bucket   = $settings['bucket']  ?? '';
        $this->key_id   = $settings['key_id']  ?? '';
        $this->app_key  = $settings['app_key'] ?? '';
        $this->prefix   = $settings['prefix']  ?? 'per-site-backups/';

        if ( preg_match( '#s3\.([^.]+)\.backblazeb2\.com#', $this->endpoint, $m ) ) {
            $this->region = $m[1];
        } else {
            $this->region = 'us-east-005';
        }
    }

    // ─── Public: Upload ───────────────────────────────────────────────────────

    /**
     * Upload a local file to B2 using cURL streaming (binary-safe).
     *
     * @return true|WP_Error
     */
    public function upload( string $local_path, string $file_name, int $blog_id, string $slug ) {
        if ( ! $this->is_configured() ) {
            return new WP_Error( 'b2_not_configured', 'B2 settings are incomplete. Please configure them in Network Admin → Settings → Site Backups.' );
        }

        $date_path  = current_time( 'Y/m/d' );
        $object_key = $this->prefix . $date_path . '/' . $file_name;

        $fh = fopen( $local_path, 'rb' );
        if ( $fh === false ) {
            return new WP_Error( 'b2_file_open', "Could not open {$local_path} for reading." );
        }

        $stat      = fstat( $fh );
        $file_size = (int) ( $stat['size'] ?? 0 );

        if ( $file_size <= 0 ) {
            fclose( $fh );
            return new WP_Error( 'b2_file_size', "Could not determine a valid file size for {$local_path}." );
        }

        $payload_hash = hash_file( 'sha256', $local_path );

        if ( $payload_hash === false ) {
            fclose( $fh );
            return new WP_Error( 'b2_hash_failed', "Could not hash {$local_path}." );
        }

        $url     = $this->endpoint . '/' . $this->bucket . '/' . ltrim( $object_key, '/' );
        $headers = $this->sign_request( 'PUT', $object_key, $payload_hash );

        $curl_headers   = [];
        $curl_headers[] = 'Content-Type: application/octet-stream';
        $curl_headers[] = 'Content-Length: ' . $file_size;
        $curl_headers[] = 'Expect:';
        foreach ( $headers as $k => $v ) {
            if ( strtolower( $k ) === 'host' ) {
                continue;
            }
            $curl_headers[] = $k . ': ' . $v;
        }

        $request_headers_sent = '';
        $stderr               = fopen( 'php://temp', 'w+' );

        rewind( $fh );

        $ch = curl_init( $url );
        curl_setopt_array( $ch, [
            CURLOPT_UPLOAD         => true,
            CURLOPT_PUT            => true,
            CURLOPT_INFILE         => $fh,
            CURLOPT_INFILESIZE     => $file_size,
            CURLOPT_HTTPHEADER     => $curl_headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 300,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_VERBOSE        => true,
            CURLOPT_STDERR         => $stderr,
            CURLINFO_HEADER_OUT    => true,
        ] );

        $response_body        = curl_exec( $ch );
        $http_code            = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
        $curl_error           = curl_error( $ch );
        $curl_info            = curl_getinfo( $ch );
        $request_headers_sent = curl_getinfo( $ch, CURLINFO_HEADER_OUT );

        $verbose_log = '';
        if ( is_resource( $stderr ) ) {
            rewind( $stderr );
            $verbose_log = stream_get_contents( $stderr );
            fclose( $stderr );
        }

        curl_close( $ch );
        fclose( $fh );

        $debug = implode( "\n", [
            '--- Upload Diagnostics ---',
            "URL:              {$url}",
            "Local path:       {$local_path}",
            "fstat size:       {$file_size} bytes",
            'filesize():       ' . filesize( $local_path ) . ' bytes',
            "payload_hash:     {$payload_hash}",
            "HTTP code:        {$http_code}",
            'Bytes uploaded:   ' . ( $curl_info['size_upload'] ?? 'n/a' ),
            'cURL error:       ' . ( $curl_error ?: 'none' ),
            "Request headers:\n" . $request_headers_sent,
            'Response body:    ' . substr( (string) $response_body, 0, 500 ),
            "Verbose log:\n" . substr( $verbose_log, 0, 4000 ),
        ] );

        error_log( '[MSB Debug] ' . $debug );

        if ( $curl_error ) {
            return new WP_Error( 'b2_curl_error', "B2 upload cURL error: {$curl_error}\n\n{$debug}" );
        }

        if ( $http_code !== 200 ) {
            return new WP_Error( 'b2_upload_http_error', "B2 upload returned HTTP {$http_code}: {$response_body}\n\n{$debug}" );
        }

        return true;
    }

    // ─── Public: Prune Old Backups ────────────────────────────────────────────

    /**
     * Delete B2 objects for a given site slug older than $keep_days.
     *
     * @return true|WP_Error
     */
    public function prune_old_backups( string $slug, int $keep_days = 14 ) {
        if ( ! $this->is_configured() ) {
            return true;
        }

        $cutoff  = strtotime( "-{$keep_days} days", current_time( 'timestamp' ) );
        $objects = $this->list_objects( $this->prefix );

        if ( is_wp_error( $objects ) ) {
            return $objects;
        }

        foreach ( $objects as $obj ) {
            $key = $obj['key'];
            if ( strpos( $key, "_{$slug}.sql.gz" ) === false ) {
                continue;
            }
            if ( preg_match( '#/(\d{4})/(\d{2})/(\d{2})/#', $key, $m ) ) {
                $file_ts = mktime( 0, 0, 0, (int) $m[2], (int) $m[3], (int) $m[1] );
                if ( $file_ts < $cutoff ) {
                    $this->delete_object( $key );
                }
            }
        }

        return true;
    }

    // ─── Private: List Objects ────────────────────────────────────────────────

    private function list_objects( string $prefix ): array|WP_Error {
        $qs           = '?list-type=2&prefix=' . rawurlencode( $prefix ) . '&max-keys=1000';
        $base_url     = $this->endpoint . '/' . $this->bucket . '/';
        $url          = $base_url . $qs;
        $payload_hash = hash( 'sha256', '' );
        $headers      = $this->sign_request( 'GET', '', $payload_hash, $qs );

        $response = wp_remote_get( $url, [ 'headers' => $headers, 'timeout' => 30 ] );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) {
            return new WP_Error( 'b2_list_error', 'B2 list failed: HTTP ' . $code . ' ' . wp_remote_retrieve_body( $response ) );
        }

        $xml     = simplexml_load_string( wp_remote_retrieve_body( $response ) );
        $objects = [];

        if ( $xml && isset( $xml->Contents ) ) {
            foreach ( $xml->Contents as $item ) {
                $objects[] = [
                    'key'           => (string) $item->Key,
                    'last_modified' => (string) $item->LastModified,
                ];
            }
        }

        return $objects;
    }

    // ─── Private: Delete Object ───────────────────────────────────────────────

    private function delete_object( string $key ): void {
        $url          = $this->endpoint . '/' . $this->bucket . '/' . ltrim( $key, '/' );
        $payload_hash = hash( 'sha256', '' );
        $headers      = $this->sign_request( 'DELETE', $key, $payload_hash );

        wp_remote_request( $url, [
            'method'  => 'DELETE',
            'headers' => $headers,
            'timeout' => 30,
        ] );
    }

    // ─── Private: AWS SigV4 Signing ───────────────────────────────────────────

    /**
     * @param string $payload_hash  Pre-computed SHA256 hex of the request body.
     *                              Use hash('sha256','') for empty bodies (GET/DELETE).
     *                              Use hash_file('sha256', $path) for file uploads.
     */
    private function sign_request(
        string $method,
        string $object_key,
        string $payload_hash,
        string $query = ''
    ): array {
        $service      = 's3';
        $algorithm    = 'AWS4-HMAC-SHA256';
        $now          = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
        $date_stamp   = $now->format( 'Ymd' );
        $amz_datetime = $now->format( 'Ymd\THis\Z' );

        $host = parse_url( $this->endpoint, PHP_URL_HOST );

        // Backblaze S3 endpoint is used in path-style mode:
        // https://host/bucket/key
        // The canonical URI therefore must include the bucket name.
        $canonical_uri = '/' . trim( $this->bucket, '/' );
        if ( $object_key !== '' ) {
            $canonical_uri .= '/' . ltrim( $object_key, '/' );
        } else {
            $canonical_uri .= '/';
        }

        $canonical_qs = '';
        if ( $query !== '' ) {
            $qs_raw = ltrim( $query, '?' );
            parse_str( $qs_raw, $qs_params );
            ksort( $qs_params );
            $canonical_qs = http_build_query( $qs_params, '', '&', PHP_QUERY_RFC3986 );
        }

        $signed_headers_map = [
            'host'                 => $host,
            'x-amz-content-sha256' => $payload_hash,
            'x-amz-date'           => $amz_datetime,
        ];
        ksort( $signed_headers_map );

        $canonical_headers  = '';
        $signed_headers_str = '';
        foreach ( $signed_headers_map as $hkey => $hval ) {
            $canonical_headers  .= $hkey . ':' . trim( $hval ) . "\n";
            $signed_headers_str .= $hkey . ';';
        }
        $signed_headers_str = rtrim( $signed_headers_str, ';' );

        $canonical_request = implode( "\n", [
            $method,
            $canonical_uri,
            $canonical_qs,
            $canonical_headers,
            $signed_headers_str,
            $payload_hash,
        ] );

        $credential_scope = implode( '/', [ $date_stamp, $this->region, $service, 'aws4_request' ] );

        $string_to_sign = implode( "\n", [
            $algorithm,
            $amz_datetime,
            $credential_scope,
            hash( 'sha256', $canonical_request ),
        ] );

        $kDate    = hash_hmac( 'sha256', $date_stamp, 'AWS4' . $this->app_key, true );
        $kRegion  = hash_hmac( 'sha256', $this->region, $kDate, true );
        $kService = hash_hmac( 'sha256', $service, $kRegion, true );
        $kSigning = hash_hmac( 'sha256', 'aws4_request', $kService, true );

        $signature = hash_hmac( 'sha256', $string_to_sign, $kSigning );

        $authorization = sprintf(
            '%s Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            $algorithm,
            $this->key_id,
            $credential_scope,
            $signed_headers_str,
            $signature
        );

        return [
            'Host'                 => $host,
            'x-amz-date'           => $amz_datetime,
            'x-amz-content-sha256' => $payload_hash,
            'Authorization'        => $authorization,
        ];
    }

    // ─── Private: Config Check ────────────────────────────────────────────────

    private function is_configured(): bool {
        return $this->endpoint !== ''
            && $this->bucket   !== ''
            && $this->key_id   !== ''
            && $this->app_key  !== '';
    }
}
