<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * REST controller for the Photo Gallery extension.
 *
 * Routes (namespace pcio-vis/v1)
 * ──────────────────────────────────
 *   GET    /galleries                       list (manage)
 *   POST   /galleries                       create (manage)
 *   GET    /galleries/{id}                  read  (manage)
 *   PUT    /galleries/{id}                  update (manage)
 *   DELETE /galleries/{id}                  delete (manage)
 *
 *   GET    /galleries/{id}/photos           list photos (manage)
 *   POST   /galleries/{id}/photos           add photo from media library (upload)
 *   PUT    /galleries/{id}/photos/{pid}     update caption/status/cover (manage)
 *   DELETE /galleries/{id}/photos/{pid}     remove photo (manage)
 *   POST   /galleries/{id}/photos/reorder   persist new order (manage)
 *
 * Public/visibility-gated reading is handled by the frontend renderer
 * (shortcode + /vis/galleries route), not by these management endpoints.
 */
class PCIO_VIS_Gallery_Rest {

    public function register(): void {
        $ns = PCIO_VIS_REST_NS;

        register_rest_route( $ns, '/galleries', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'list_galleries' ],
                'permission_callback' => [ $this, 'can_manage' ],
            ],
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'create_gallery' ],
                'permission_callback' => [ $this, 'can_manage' ],
            ],
        ] );

        register_rest_route( $ns, '/galleries/(?P<id>\d+)', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'get_gallery' ],
                'permission_callback' => [ $this, 'can_manage' ],
                'args'                => [ 'id' => self::id_arg() ],
            ],
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [ $this, 'update_gallery' ],
                'permission_callback' => [ $this, 'can_manage' ],
                'args'                => [ 'id' => self::id_arg() ],
            ],
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [ $this, 'delete_gallery' ],
                'permission_callback' => [ $this, 'can_manage' ],
                'args'                => [ 'id' => self::id_arg() ],
            ],
        ] );

        register_rest_route( $ns, '/galleries/(?P<id>\d+)/photos', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'list_photos' ],
                'permission_callback' => [ $this, 'can_manage' ],
                'args'                => [ 'id' => self::id_arg() ],
            ],
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'add_photo' ],
                'permission_callback' => [ $this, 'can_upload' ],
                'args'                => [ 'id' => self::id_arg() ],
            ],
        ] );

        register_rest_route( $ns, '/galleries/(?P<id>\d+)/photos/reorder', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'reorder_photos' ],
                'permission_callback' => [ $this, 'can_manage' ],
                'args'                => [ 'id' => self::id_arg() ],
            ],
        ] );

        register_rest_route( $ns, '/galleries/(?P<id>\d+)/photos/(?P<pid>\d+)', [
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [ $this, 'update_photo' ],
                'permission_callback' => [ $this, 'can_manage' ],
                'args'                => [ 'id' => self::id_arg(), 'pid' => self::id_arg() ],
            ],
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [ $this, 'delete_photo' ],
                'permission_callback' => [ $this, 'can_manage' ],
                'args'                => [ 'id' => self::id_arg(), 'pid' => self::id_arg() ],
            ],
        ] );

        register_rest_route( $ns, '/gallery-settings', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'get_settings' ],
                'permission_callback' => [ $this, 'can_manage' ],
            ],
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'save_settings' ],
                'permission_callback' => [ $this, 'can_manage' ],
            ],
        ] );
    }

    private static function id_arg(): array {
        return [
            'validate_callback' => static fn( $v ) => is_numeric( $v ),
            'sanitize_callback' => 'absint',
        ];
    }

    // ── Permissions ───────────────────────────────────────────────

    /** Manage galleries (create/edit/delete). */
    public function can_manage(): bool {
        return current_user_can( 'vis_gallery_manage' );
    }

    /** Upload photos to a gallery. */
    public function can_upload(): bool {
        if ( ! current_user_can( 'upload_files' ) ) {
            return false;
        }
        return current_user_can( 'vis_gallery_manage' ) ||
               current_user_can( 'vis_gallery_upload' );
    }

    // ── Settings handlers ─────────────────────────────────────────

    public function get_settings(): WP_REST_Response {
        return new WP_REST_Response( [
            'autocreate' => '1' === get_option( PCIO_VIS_Gallery_Integration::OPT_AUTOCREATE, '0' ),
        ], 200 );
    }

    public function save_settings( WP_REST_Request $req ): WP_REST_Response {
        $data = (array) $req->get_json_params();
        update_option(
            PCIO_VIS_Gallery_Integration::OPT_AUTOCREATE,
            ! empty( $data['autocreate'] ) ? '1' : '0'
        );
        return new WP_REST_Response( [
            'autocreate' => '1' === get_option( PCIO_VIS_Gallery_Integration::OPT_AUTOCREATE, '0' ),
        ], 200 );
    }

    // ── Gallery handlers ──────────────────────────────────────────

    public function list_galleries( WP_REST_Request $req ): WP_REST_Response {
        try {
            return new WP_REST_Response( PCIO_VIS_Gallery_DB::get_all(), 200 );
        } catch ( \Throwable $e ) {
            return new WP_REST_Response( [ 'message' => $e->getMessage() ], 500 );
        }
    }

    public function get_gallery( WP_REST_Request $req ): WP_REST_Response {
        $gallery = PCIO_VIS_Gallery_DB::get( (int) $req->get_param( 'id' ) );
        if ( ! $gallery ) {
            return new WP_REST_Response( [ 'message' => __( 'Gallery not found.', 'pcio-vis-gallery' ) ], 404 );
        }
        $gallery['photos'] = PCIO_VIS_Gallery_DB::get_photos( $gallery['id'] );
        return new WP_REST_Response( $gallery, 200 );
    }

    public function create_gallery( WP_REST_Request $req ): WP_REST_Response {
        try {
            $id = PCIO_VIS_Gallery_DB::create( (array) $req->get_json_params() );
            if ( ! $id ) {
                return new WP_REST_Response( [ 'message' => __( 'Could not create gallery.', 'pcio-vis-gallery' ) ], 500 );
            }
            return new WP_REST_Response( PCIO_VIS_Gallery_DB::get( (int) $id ), 201 );
        } catch ( \Throwable $e ) {
            return new WP_REST_Response( [ 'message' => $e->getMessage() ], 500 );
        }
    }

    public function update_gallery( WP_REST_Request $req ): WP_REST_Response {
        try {
            $id = (int) $req->get_param( 'id' );
            if ( ! PCIO_VIS_Gallery_DB::get( $id ) ) {
                return new WP_REST_Response( [ 'message' => __( 'Gallery not found.', 'pcio-vis-gallery' ) ], 404 );
            }
            PCIO_VIS_Gallery_DB::update( $id, (array) $req->get_json_params() );
            return new WP_REST_Response( PCIO_VIS_Gallery_DB::get( $id ), 200 );
        } catch ( \Throwable $e ) {
            return new WP_REST_Response( [ 'message' => $e->getMessage() ], 500 );
        }
    }

    public function delete_gallery( WP_REST_Request $req ): WP_REST_Response {
        try {
            if ( ! PCIO_VIS_Gallery_DB::delete( (int) $req->get_param( 'id' ) ) ) {
                return new WP_REST_Response( [ 'message' => __( 'Could not delete gallery.', 'pcio-vis-gallery' ) ], 500 );
            }
            return new WP_REST_Response( null, 204 );
        } catch ( \Throwable $e ) {
            return new WP_REST_Response( [ 'message' => $e->getMessage() ], 500 );
        }
    }

    // ── Photo handlers ────────────────────────────────────────────

    public function list_photos( WP_REST_Request $req ): WP_REST_Response {
        return new WP_REST_Response(
            PCIO_VIS_Gallery_DB::get_photos( (int) $req->get_param( 'id' ) ),
            200
        );
    }

    public function add_photo( WP_REST_Request $req ): WP_REST_Response {
        try {
            $gallery_id = (int) $req->get_param( 'id' );
            if ( ! PCIO_VIS_Gallery_DB::get( $gallery_id ) ) {
                return new WP_REST_Response( [ 'message' => __( 'Gallery not found.', 'pcio-vis-gallery' ) ], 404 );
            }

            $body          = (array) $req->get_json_params();
            $attachment_id = absint( $body['attachment_id'] ?? 0 );
            if ( ! $attachment_id || 'attachment' !== get_post_type( $attachment_id ) ) {
                return new WP_REST_Response( [ 'message' => __( 'Invalid attachment.', 'pcio-vis-gallery' ) ], 400 );
            }
            $mime = (string) get_post_mime_type( $attachment_id );
            if ( ! str_starts_with( $mime, 'image/' ) && ! str_starts_with( $mime, 'video/' ) ) {
                return new WP_REST_Response( [ 'message' => __( 'Only image and video files are allowed.', 'pcio-vis-gallery' ) ], 400 );
            }

            // Verify the caller can edit this specific media item (owns it or is an editor+).
            if ( ! current_user_can( 'edit_post', $attachment_id ) ) {
                return new WP_REST_Response( [ 'message' => __( 'You do not have permission to use this attachment.', 'pcio-vis-gallery' ) ], 403 );
            }

            // Members uploading their own photos go to moderation; managers' are approved.
            $status = current_user_can( 'vis_gallery_manage' ) ? 'approved' : 'pending';

            $pid = PCIO_VIS_Gallery_DB::add_photo( $gallery_id, [
                'attachment_id' => $attachment_id,
                'caption'       => sanitize_text_field( $body['caption'] ?? '' ),
                'uploaded_by'   => get_current_user_id(),
                'status'        => $status,
            ] );
            if ( ! $pid ) {
                return new WP_REST_Response( [ 'message' => __( 'Could not add photo.', 'pcio-vis-gallery' ) ], 500 );
            }
            return new WP_REST_Response( PCIO_VIS_Gallery_DB::get_photo( $pid ), 201 );
        } catch ( \Throwable $e ) {
            return new WP_REST_Response( [ 'message' => $e->getMessage() ], 500 );
        }
    }

    public function update_photo( WP_REST_Request $req ): WP_REST_Response {
        try {
            $pid   = (int) $req->get_param( 'pid' );
            $photo = PCIO_VIS_Gallery_DB::get_photo( $pid );
            if ( ! $photo || $photo['gallery_id'] !== (int) $req->get_param( 'id' ) ) {
                return new WP_REST_Response( [ 'message' => __( 'Photo not found.', 'pcio-vis-gallery' ) ], 404 );
            }
            PCIO_VIS_Gallery_DB::update_photo( $pid, (array) $req->get_json_params() );
            return new WP_REST_Response( PCIO_VIS_Gallery_DB::get_photo( $pid ), 200 );
        } catch ( \Throwable $e ) {
            return new WP_REST_Response( [ 'message' => $e->getMessage() ], 500 );
        }
    }

    public function delete_photo( WP_REST_Request $req ): WP_REST_Response {
        try {
            $pid   = (int) $req->get_param( 'pid' );
            $photo = PCIO_VIS_Gallery_DB::get_photo( $pid );
            if ( ! $photo || $photo['gallery_id'] !== (int) $req->get_param( 'id' ) ) {
                return new WP_REST_Response( [ 'message' => __( 'Photo not found.', 'pcio-vis-gallery' ) ], 404 );
            }
            PCIO_VIS_Gallery_DB::delete_photo( $pid );
            return new WP_REST_Response( null, 204 );
        } catch ( \Throwable $e ) {
            return new WP_REST_Response( [ 'message' => $e->getMessage() ], 500 );
        }
    }

    public function reorder_photos( WP_REST_Request $req ): WP_REST_Response {
        try {
            $gallery_id = (int) $req->get_param( 'id' );
            $body       = (array) $req->get_json_params();
            $order      = array_map( 'absint', (array) ( $body['order'] ?? [] ) );
            PCIO_VIS_Gallery_DB::reorder_photos( $gallery_id, $order );
            return new WP_REST_Response( PCIO_VIS_Gallery_DB::get_photos( $gallery_id ), 200 );
        } catch ( \Throwable $e ) {
            return new WP_REST_Response( [ 'message' => $e->getMessage() ], 500 );
        }
    }
}
