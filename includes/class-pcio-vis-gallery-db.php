<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables; caching would hide real-time admin changes.

/**
 * Database layer for the Photo Gallery extension.
 *
 * Tables
 * ──────
 *   me_gallery        – one row per gallery (a "deck" of photos). event_id is
 *                       NULL for orphan galleries placed via [pcio_me_gallery id="N"].
 *   me_gallery_photo  – one row per photo; each references a WordPress media
 *                       attachment (images live in the standard uploads folder).
 *
 * Images are NOT duplicated — only the attachment_id is stored, so every photo
 * is a normal WP Media Library item, manageable from the WP admin.
 *
 * Visibility model (column `visibility`):
 *   public        – anyone, even logged-out visitors
 *   logged_in     – any logged-in user
 *   participants  – members signed up to the linked event (falls back to
 *                   logged_in when the gallery has no event)
 *   roles         – only the Vis roles listed in `visibility_roles` (JSON)
 *
 * Photo moderation (column `status`): approved | pending | rejected.
 * Only "approved" photos are shown on the frontend. The pending/rejected
 * states exist so a member-upload workflow can be added later without a
 * schema change.
 */
class PCIO_VIS_Gallery_DB {

    const TABLE_GALLERY = 'me_gallery';
    const TABLE_PHOTO   = 'me_gallery_photo';

    const DB_VERSION = '1.1.1';

    const VISIBILITIES = [ 'public', 'logged_in', 'participants', 'roles' ];
    const STATUSES      = [ 'approved', 'pending', 'rejected' ];

    public static function table_gallery(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_GALLERY;
    }

    public static function table_photo(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_PHOTO;
    }

    /**
     * Register gallery tables with the core change-history service.
     *
     * @param array<string,array> $tables Existing history table definitions.
     * @return array<string,array>
     */
    public static function register_history_tables( array $tables ): array {
        $tables['gallery_galleries'] = [
            'table'     => self::TABLE_GALLERY,
            'label'     => __( 'Gallery', 'pcio-vis-gallery' ),
            'title_col' => 'title',
            'weight'    => 6,
            'large'     => [ 'description' ],
            'fields'    => [
                'event_id'             => __( 'Event', 'pcio-vis-gallery' ),
                'title'                => __( 'Title', 'pcio-vis-gallery' ),
                'description'          => __( 'Description', 'pcio-vis-gallery' ),
                'cover_attachment_id'  => __( 'Cover image', 'pcio-vis-gallery' ),
                'visibility'           => __( 'Visibility', 'pcio-vis-gallery' ),
                'visibility_roles'     => __( 'Visibility roles', 'pcio-vis-gallery' ),
            ],
            'ignore'    => [ 'created', 'updated' ],
            'links'     => static function ( array $row ): array {
                return [ 'event_id' => (int) ( $row['event_id'] ?? 0 ) ];
            },
        ];

        $tables['gallery_photos'] = [
            'table'     => self::TABLE_PHOTO,
            'label'     => __( 'Gallery photo', 'pcio-vis-gallery' ),
            'title'     => [ self::class, 'history_photo_title' ],
            'weight'    => 3,
            'fields'    => [
                'gallery_id'           => __( 'Gallery', 'pcio-vis-gallery' ),
                'attachment_id'        => __( 'Attachment', 'pcio-vis-gallery' ),
                'poster_attachment_id' => __( 'Poster image', 'pcio-vis-gallery' ),
                'caption'              => __( 'Caption', 'pcio-vis-gallery' ),
                'sort_order'           => __( 'Sort order', 'pcio-vis-gallery' ),
                'uploaded_by'          => __( 'Uploaded by', 'pcio-vis-gallery' ),
                'status'               => __( 'Status', 'pcio-vis-gallery' ),
            ],
            'ignore'    => [ 'created', 'updated' ],
            'links'     => static function ( array $row ): array {
                return self::history_photo_links( $row );
            },
        ];

        return $tables;
    }

    // ── Schema ────────────────────────────────────────────────────

    public static function install(): void {
        global $wpdb;

        if ( get_option( 'pcio_meg_db_version' ) === self::DB_VERSION ) {
            return; // schema already current
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $gallery = self::table_gallery();
        $photo   = self::table_photo();

        dbDelta(
            "CREATE TABLE IF NOT EXISTS {$gallery} (
                id                  INT          NOT NULL AUTO_INCREMENT,
                event_id            INT                   DEFAULT NULL,
                title               VARCHAR(200) NOT NULL DEFAULT '',
                description         TEXT,
                cover_attachment_id BIGINT       NOT NULL DEFAULT 0,
                visibility          VARCHAR(20)  NOT NULL DEFAULT 'public',
                visibility_roles    TEXT,
                created             DATETIME     DEFAULT CURRENT_TIMESTAMP,
                updated             DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                KEY idx_gallery_event (event_id)
                ) {$charset};"
        );

        dbDelta(
            "CREATE TABLE IF NOT EXISTS {$photo} (
                id                   INT          NOT NULL AUTO_INCREMENT,
                gallery_id           INT          NOT NULL,
                attachment_id        BIGINT       NOT NULL DEFAULT 0,
                poster_attachment_id BIGINT                DEFAULT NULL,
                caption              VARCHAR(255) NOT NULL DEFAULT '',
                sort_order    INT          NOT NULL DEFAULT 0,
                uploaded_by   BIGINT                DEFAULT NULL,
                status        VARCHAR(10)  NOT NULL DEFAULT 'approved',
                created       DATETIME     DEFAULT CURRENT_TIMESTAMP,
                updated       DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                KEY idx_photo_gallery (gallery_id, sort_order),
                KEY idx_photo_status (status)
                ) {$charset};"
        );

        // maybe_add_column is reliable for existing tables where dbDelta silently skips ALTER TABLE.
        maybe_add_column( $photo, 'poster_attachment_id', "ALTER TABLE {$photo} ADD COLUMN `poster_attachment_id` BIGINT DEFAULT NULL AFTER `attachment_id`" );

        update_option( 'pcio_meg_db_version', self::DB_VERSION );
    }

    // ── Galleries: Read ───────────────────────────────────────────

    public static function drop_tables(): void {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- intentional uninstall schema removal.
        $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . self::TABLE_PHOTO ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- intentional uninstall schema removal.
        $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . self::TABLE_GALLERY ) );
    }

    /**
     * All galleries, newest first, decorated with photo_count, cover_url and
     * the linked event title (when any).
     */
    public static function get_all(): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare( 'SELECT * FROM %i ORDER BY `created` DESC, `id` DESC', self::table_gallery() ),
            ARRAY_A
        ) ?: [];
        return array_map( [ self::class, 'decorate' ], $rows );
    }

    public static function get( int $id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM %i WHERE `id` = %d', self::table_gallery(), $id ),
            ARRAY_A
        );
        return $row ? self::decorate( $row ) : null;
    }

    /** Gallery linked to a given event, or null. */
    public static function get_by_event( int $event_id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE `event_id` = %d ORDER BY `id` ASC LIMIT 1',
                self::table_gallery(),
                $event_id
            ),
            ARRAY_A
        );
        return $row ? self::decorate( $row ) : null;
    }

    // ── Galleries: Write ──────────────────────────────────────────

    /** @return int|false */
    public static function create( array $data ) {
        global $wpdb;
        $result = PCIO_VIS_History::insert( 'gallery_galleries', self::sanitize_gallery( $data ) );
        if ( ! $result ) {
            return false;
        }
        $id = (int) $wpdb->insert_id;
        do_action( 'pcio_me_gallery_saved', $id, $data );
        return $id;
    }

    public static function update( int $id, array $data ): bool {
        $ok = false !== PCIO_VIS_History::update( 'gallery_galleries', self::sanitize_gallery( $data ), [ 'id' => $id ] );
        if ( $ok ) {
            do_action( 'pcio_me_gallery_saved', $id, $data );
        }
        return $ok;
    }

    /** Deletes a gallery and all its photo rows (attachments are left intact). */
    public static function delete( int $id ): bool {
        $gallery = self::get_raw_gallery( $id );
        $links   = $gallery ? [ 'event_id' => (int) ( $gallery['event_id'] ?? 0 ) ] : [];

        PCIO_VIS_History::begin( '', $links );
        PCIO_VIS_History::delete( 'gallery_photos', [ 'gallery_id' => $id ], [ '%d' ] );
        $ok = (bool) PCIO_VIS_History::delete( 'gallery_galleries', [ 'id' => $id ], [ '%d' ] );
        PCIO_VIS_History::commit();
        if ( $ok ) {
            do_action( 'pcio_me_gallery_deleted', $id );
        }
        return $ok;
    }

    // ── Photos: Read ──────────────────────────────────────────────

    /**
     * Photos for a gallery, ordered by sort_order.
     *
     * @param int    $gallery_id
     * @param string $status  '' = all statuses, or one of STATUSES.
     */
    public static function get_photos( int $gallery_id, string $status = '' ): array {
        global $wpdb;
        if ( $status !== '' && in_array( $status, self::STATUSES, true ) ) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT * FROM %i
                     WHERE `gallery_id` = %d AND `status` = %s
                     ORDER BY `sort_order` ASC, `id` ASC',
                    self::table_photo(),
                    $gallery_id,
                    $status
                ),
                ARRAY_A
            ) ?: [];
        } else {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT * FROM %i
                     WHERE `gallery_id` = %d
                     ORDER BY `sort_order` ASC, `id` ASC',
                    self::table_photo(),
                    $gallery_id
                ),
                ARRAY_A
            ) ?: [];
        }
        return array_map( [ self::class, 'decorate_photo' ], $rows );
    }

    public static function get_photo( int $id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM %i WHERE `id` = %d', self::table_photo(), $id ),
            ARRAY_A
        );
        return $row ? self::decorate_photo( $row ) : null;
    }

    public static function count_photos( int $gallery_id, string $status = 'approved' ): int {
        global $wpdb;
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM %i
                 WHERE `gallery_id` = %d AND `status` = %s',
                self::table_photo(),
                $gallery_id,
                $status
            )
        );
    }

    // ── Photos: Write ─────────────────────────────────────────────

    /** @return int|false */
    public static function add_photo( int $gallery_id, array $data ) {
        global $wpdb;
        // Append to the end of the gallery by default.
        $next = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COALESCE(MAX(`sort_order`), -1) + 1 FROM %i WHERE `gallery_id` = %d',
                self::table_photo(),
                $gallery_id
            )
        );
        $row = self::sanitize_photo( $data );
        $row['gallery_id'] = $gallery_id;
        if ( ! isset( $data['sort_order'] ) ) {
            $row['sort_order'] = $next;
        }
        $result = PCIO_VIS_History::insert( 'gallery_photos', $row );
        return $result ? (int) $wpdb->insert_id : false;
    }

    public static function update_photo( int $id, array $data ): bool {
        return false !== PCIO_VIS_History::update( 'gallery_photos', self::sanitize_photo( $data ), [ 'id' => $id ] );
    }

    public static function delete_photo( int $id ): bool {
        return (bool) PCIO_VIS_History::delete( 'gallery_photos', [ 'id' => $id ], [ '%d' ] );
    }

    /**
     * Persist a new photo order.
     *
     * @param int   $gallery_id
     * @param int[] $photo_ids  Photo IDs in the desired display order.
     */
    public static function reorder_photos( int $gallery_id, array $photo_ids ): void {
        $order = 0;
        PCIO_VIS_History::begin(
            __( 'Reorder gallery photos', 'pcio-vis-gallery' ),
            self::history_gallery_links( $gallery_id )
        );
        foreach ( $photo_ids as $pid ) {
            PCIO_VIS_History::update(
                'gallery_photos',
                [ 'sort_order' => $order ],
                [ 'id' => (int) $pid, 'gallery_id' => $gallery_id ],
                [ '%d' ],
                [ '%d', '%d' ]
            );
            ++$order;
        }
        PCIO_VIS_History::commit();
    }

    // ── Internal helpers ──────────────────────────────────────────

    private static function sanitize_gallery( array $data ): array {
        $out = [];

        if ( array_key_exists( 'event_id', $data ) ) {
            $eid              = absint( $data['event_id'] ?? 0 );
            $out['event_id'] = $eid ?: null;
        }
        if ( array_key_exists( 'title', $data ) ) {
            $out['title'] = sanitize_text_field( $data['title'] ?? '' );
        }
        if ( array_key_exists( 'description', $data ) ) {
            $out['description'] = wp_kses_post( $data['description'] ?? '' );
        }
        if ( array_key_exists( 'cover_attachment_id', $data ) ) {
            $out['cover_attachment_id'] = absint( $data['cover_attachment_id'] ?? 0 );
        }
        if ( array_key_exists( 'visibility', $data ) ) {
            $vis               = sanitize_key( $data['visibility'] ?? 'public' );
            $out['visibility'] = in_array( $vis, self::VISIBILITIES, true ) ? $vis : 'public';
        }
        if ( array_key_exists( 'visibility_roles', $data ) ) {
            $roles = $data['visibility_roles'] ?? [];
            if ( is_string( $roles ) ) {
                $decoded = json_decode( $roles, true );
                $roles   = is_array( $decoded ) ? $decoded : array_filter( array_map( 'trim', explode( ',', $roles ) ) );
            }
            $roles                   = array_values( array_unique( array_map( 'sanitize_key', (array) $roles ) ) );
            $out['visibility_roles'] = wp_json_encode( $roles );
        }

        return $out;
    }

    private static function sanitize_photo( array $data ): array {
        $out = [];
        if ( array_key_exists( 'attachment_id', $data ) ) {
            $out['attachment_id'] = absint( $data['attachment_id'] ?? 0 );
        }
        if ( array_key_exists( 'caption', $data ) ) {
            $out['caption'] = sanitize_text_field( $data['caption'] ?? '' );
        }
        if ( array_key_exists( 'sort_order', $data ) ) {
            $out['sort_order'] = (int) $data['sort_order'];
        }
        if ( array_key_exists( 'uploaded_by', $data ) ) {
            $uid                = absint( $data['uploaded_by'] ?? 0 );
            $out['uploaded_by'] = $uid ?: null;
        }
        if ( array_key_exists( 'status', $data ) ) {
            $st             = sanitize_key( $data['status'] ?? 'approved' );
            $out['status'] = in_array( $st, self::STATUSES, true ) ? $st : 'approved';
        }
        if ( array_key_exists( 'poster_attachment_id', $data ) ) {
            $pid                         = absint( $data['poster_attachment_id'] ?? 0 );
            $out['poster_attachment_id'] = $pid ?: null;
        }
        return $out;
    }

    /**
     * Build a history title for a photo row.
     *
     * @param array $row Raw photo row.
     */
    public static function history_photo_title( array $row ): string {
        $caption = trim( (string) ( $row['caption'] ?? '' ) );
        if ( '' !== $caption ) {
            return $caption;
        }

        $attachment_id = (int) ( $row['attachment_id'] ?? 0 );
        if ( $attachment_id > 0 ) {
            $title = get_the_title( $attachment_id );
            if ( '' !== (string) $title ) {
                return (string) $title;
            }
        }

        return __( 'Gallery photo', 'pcio-vis-gallery' );
    }

    /**
     * Resolve history links for a photo row through its parent gallery.
     *
     * @param array $row Raw photo row.
     * @return array{event_id?:int}
     */
    public static function history_photo_links( array $row ): array {
        return self::history_gallery_links( (int) ( $row['gallery_id'] ?? 0 ) );
    }

    /**
     * Resolve history links for a gallery.
     *
     * @param int $gallery_id Gallery ID.
     * @return array{event_id?:int}
     */
    private static function history_gallery_links( int $gallery_id ): array {
        $gallery = $gallery_id > 0 ? self::get_raw_gallery( $gallery_id ) : null;
        return $gallery ? [ 'event_id' => (int) ( $gallery['event_id'] ?? 0 ) ] : [];
    }

    /**
     * Raw gallery row used internally by history registration.
     *
     * @param int $id Gallery ID.
     */
    private static function get_raw_gallery( int $id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM %i WHERE `id` = %d', self::table_gallery(), $id ),
            ARRAY_A
        );
        return $row ?: null;
    }

    /**
     * Casts ids and adds derived fields (cover_url, photo_count, event_title).
     */
    private static function decorate( array $row ): array {
        $row['id']                  = (int) $row['id'];
        $row['event_id']            = isset( $row['event_id'] ) ? (int) $row['event_id'] : 0;
        $row['cover_attachment_id'] = (int) $row['cover_attachment_id'];
        $row['photo_count']         = self::count_photos( $row['id'], 'approved' );

        $row['cover_url'] = $row['cover_attachment_id']
            ? (string) wp_get_attachment_image_url( $row['cover_attachment_id'], 'medium' )
            : '';

        // Fall back to the first approved photo when no explicit cover is set.
        if ( $row['cover_url'] === '' ) {
            $first = self::get_photos( $row['id'], 'approved' );
            $row['cover_url'] = $first ? ( $first[0]['thumb_url'] ?? '' ) : '';
        }

        $decoded                  = json_decode( $row['visibility_roles'] ?? '[]', true );
        $row['visibility_roles'] = is_array( $decoded ) ? $decoded : [];

        $row['event_title'] = '';
        if ( $row['event_id'] && class_exists( 'PCIO_VIS_Events_DB' ) ) {
            $event              = PCIO_VIS_Events_DB::get( $row['event_id'] );
            $row['event_title'] = $event ? (string) ( $event['title'] ?? '' ) : '';
        }

        return $row;
    }

    private static function decorate_photo( array $row ): array {
        $row['id']                   = (int) $row['id'];
        $row['gallery_id']           = (int) $row['gallery_id'];
        $row['attachment_id']        = (int) $row['attachment_id'];
        $row['sort_order']           = (int) $row['sort_order'];
        $row['poster_attachment_id'] = isset( $row['poster_attachment_id'] ) ? (int) $row['poster_attachment_id'] : 0;

        $mime              = $row['attachment_id'] ? (string) get_post_mime_type( $row['attachment_id'] ) : '';
        $row['mime_type']  = $mime;
        $is_video          = str_starts_with( $mime, 'video/' );
        $row['media_type'] = $is_video ? 'video' : 'image';

        // Manual poster image overrides the auto-generated FFmpeg frame.
        if ( $is_video && $row['poster_attachment_id'] ) {
            $row['thumb_url'] = (string) ( wp_get_attachment_image_url( $row['poster_attachment_id'], 'medium' ) ?: '' );
        } else {
            $row['thumb_url'] = $row['attachment_id']
                ? (string) ( wp_get_attachment_image_url( $row['attachment_id'], 'medium' ) ?: '' )
                : '';
        }

        $row['full_url'] = $row['attachment_id']
            ? ( $is_video
                ? (string) ( wp_get_attachment_url( $row['attachment_id'] ) ?: '' )
                : (string) ( wp_get_attachment_image_url( $row['attachment_id'], 'large' ) ?: '' )
              )
            : '';

        return $row;
    }
}
