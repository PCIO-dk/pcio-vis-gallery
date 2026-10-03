<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Frontend rendering + access control for galleries.
 *
 * Provides two entry points:
 *   1. Shortcode  [pcio_me_gallery id="N"]  — embed one gallery on any WP page.
 *   2. URL routes:
 *        /vis/galleries/        — member-accessible index of galleries.
 *        /vis/galleries/{id}/   — a single gallery.
 *
 * The extension registers its OWN rewrite rules and query vars because the
 * core plugin validates pcio_me_page against a fixed whitelist and would
 * reject an unknown sub-page.
 *
 * Visibility is enforced here (server side) for every render path.
 */
class PCIO_VIS_Gallery_Frontend {

    public function register(): void {
        add_action( 'init',              [ $this, 'register_rewrites' ] );
        add_action( 'template_redirect', [ $this, 'serve_templates'   ] );
        add_shortcode( 'pcio_me_gallery', [ $this, 'shortcode' ] );
    }

    // ── Rewrites ──────────────────────────────────────────────────

    public function register_rewrites(): void {
        // Management routes (must be registered before the numeric single rule).
        add_rewrite_rule(
            '^vis/galleries/manage/(\d+)/?$',
            'index.php?pcio_me_gallery=edit&pcio_me_gallery_id=$matches[1]',
            'top'
        );
        add_rewrite_rule(
            '^vis/galleries/manage/?$',
            'index.php?pcio_me_gallery=manage',
            'top'
        );
        add_rewrite_rule(
            '^vis/galleries/(\d+)/?$',
            'index.php?pcio_me_gallery=single&pcio_me_gallery_id=$matches[1]',
            'top'
        );
        add_rewrite_rule(
            '^vis/galleries/?$',
            'index.php?pcio_me_gallery=index',
            'top'
        );
        add_rewrite_tag( '%pcio_me_gallery%',    '([^&]+)' );
        add_rewrite_tag( '%pcio_me_gallery_id%', '(\d+)' );
    }

    // ── Template serving for /vis/galleries[...] ──────────────────

    public function serve_templates(): void {
        $view = get_query_var( 'pcio_me_gallery' );
        if ( ! $view ) {
            return;
        }

        if ( ! is_user_logged_in() ) {
            auth_redirect();
            exit;
        }

        // ── Management views (own standalone /vis/ shell, styled like the
        //    member list). Requires the manage capability. ────────────────
        if ( $view === 'manage' || $view === 'edit' ) {
            $this->serve_manage( $view );
            return;
        }

        $assets = plugins_url( 'assets/', PCIO_VIS_GALLERY_FILE );
        wp_enqueue_style( 'pcio-me-gallery', $assets . 'gallery.css', [], '1.1.0' );
        wp_enqueue_script( 'pcio-me-gallery', $assets . 'gallery.js', [], '1.2.0', true );

        if ( $view === 'single' ) {
            $gallery_id = (int) get_query_var( 'pcio_me_gallery_id' );
            $gallery    = PCIO_VIS_Gallery_DB::get( $gallery_id );
            if ( ! $gallery ) {
                $this->render_404();
                exit;
            }
            if ( ! self::user_can_view( $gallery ) ) {
                $this->render_denied();
                exit;
            }
            wp_add_inline_script(
                'pcio-me-gallery',
                'window.PCIO_VIS_GALLERY = ' . wp_json_encode( $this->js_data( $gallery_id ) ) . ';',
                'before'
            );
            $template = PCIO_VIS_GALLERY_DIR . 'templates/gallery.php';
        } else {
            wp_add_inline_script(
                'pcio-me-gallery',
                'window.PCIO_VIS_GALLERY = ' . wp_json_encode( $this->js_data( 0 ) ) . ';',
                'before'
            );
            $template = PCIO_VIS_GALLERY_DIR . 'templates/galleries.php';
        }

        if ( file_exists( $template ) ) {
            // Render the gallery inside a minimal full-page shell.
            get_header();
            include $template;
            get_footer();
            exit;
        }
    }

    // ── Management views (list + edit) ────────────────────────────

    /**
     * Render the gallery management list or edit page inside the standard
     * /vis/ shell (same look as the member list), enqueuing the management
     * assets and a window.PCIO_MEG config blob.
     *
     * @param string $view 'manage' (list) or 'edit'.
     */
    private function serve_manage( string $view ): void {
        if ( ! current_user_can( 'vis_gallery_manage' ) ) {
            $this->render_denied();
            exit;
        }

        $assets = plugins_url( 'assets/', PCIO_VIS_GALLERY_FILE );
        // Base member-list styling (shared with the core /vis/ pages).
        wp_enqueue_style( 'pcio-me-app', plugins_url( 'assets/members.css', PCIO_VIS_PLUGIN_FILE ), [], '1.0.1' );
        wp_enqueue_style( 'pcio-meg-manage', $assets . 'gallery-manage.css', [ 'pcio-me-app' ], '1.1.0' );

        if ( $view === 'edit' ) {
            $gallery_id = (int) get_query_var( 'pcio_me_gallery_id' );
            if ( ! PCIO_VIS_Gallery_DB::get( $gallery_id ) ) {
                $this->render_404();
                exit;
            }
            // Rich-text editor (Quill) + media library + drag-sort.
            wp_enqueue_media();
            // Quill is bundled by the core plugin (shared, no external/CDN load).
            wp_enqueue_style( 'quill-snow', plugins_url( 'assets/lib/quill/quill.snow.css', PCIO_VIS_PLUGIN_FILE ), [], '2.0.3' );
            wp_enqueue_script( 'quill', plugins_url( 'assets/lib/quill/quill.js', PCIO_VIS_PLUGIN_FILE ), [], '2.0.3', true );
            wp_enqueue_script( 'pcio-meg-edit', $assets . 'gallery-edit.js', [ 'quill', 'jquery-ui-sortable' ], '1.3.2', true );
            $config              = $this->manage_config();
            $config['view']      = 'edit';
            $config['galleryId'] = $gallery_id;
            wp_add_inline_script( 'pcio-meg-edit', 'window.PCIO_MEG = ' . wp_json_encode( $config ) . ';', 'before' );
            $template = PCIO_VIS_GALLERY_DIR . 'templates/manage-edit.php';
        } else {
            wp_enqueue_script( 'pcio-meg-list', $assets . 'gallery-manage.js', [], '1.1.2', true );
            $config         = $this->manage_config();
            $config['view'] = 'list';
            wp_add_inline_script( 'pcio-meg-list', 'window.PCIO_MEG = ' . wp_json_encode( $config ) . ';', 'before' );
            $template = PCIO_VIS_GALLERY_DIR . 'templates/manage.php';
        }

        if ( file_exists( $template ) ) {
            include $template;
            exit;
        }
    }

    /**
     * Shared config blob for the management list + edit pages.
     */
    private function manage_config(): array {
        $vis_roles = [];
        if ( class_exists( 'PCIO_VIS_Roles_DB' ) ) {
            $vis_roles = array_map(
                static fn( $r ) => [ 'slug' => $r['slug'], 'name' => $r['name'] ],
                PCIO_VIS_Roles_DB::get_all_roles()
            );
        }

        return [
            'rest'         => rest_url( PCIO_VIS_REST_NS ),
            'eventsRest'   => rest_url( PCIO_VIS_REST_NS . '/events' ),
            'nonce'        => wp_create_nonce( 'wp_rest' ),
            'manageUrl'    => home_url( 'vis/galleries/manage/' ),
            'editBase'     => home_url( 'vis/galleries/manage/' ), // + {id}/
            'viewBase'     => home_url( 'vis/galleries/' ),        // + {id}/
            'visibilities' => [
                'public'       => __( 'Public', 'pcio-vis-gallery' ),
                'logged_in'    => __( 'Logged-in users', 'pcio-vis-gallery' ),
                'participants' => __( 'Event participants', 'pcio-vis-gallery' ),
                'roles'        => __( 'Specific roles', 'pcio-vis-gallery' ),
            ],
            'visRoles'     => $vis_roles,
            'i18n'         => $this->manage_i18n(),
        ];
    }

    private function manage_i18n(): array {
        return [
            // List
            'pageTitle'      => __( 'Galleries', 'pcio-vis-gallery' ),
            'newGallery'     => __( '+ New gallery', 'pcio-vis-gallery' ),
            'newGalleryTitle'=> __( 'Untitled gallery', 'pcio-vis-gallery' ),
            'colId'          => __( 'ID', 'pcio-vis-gallery' ),
            'colDate'        => __( 'Date', 'pcio-vis-gallery' ),
            'colTitle'       => __( 'Title', 'pcio-vis-gallery' ),
            'colEvent'       => __( 'Event', 'pcio-vis-gallery' ),
            'colPhotos'      => __( 'Photos', 'pcio-vis-gallery' ),
            'colVisibility'  => __( 'Visibility', 'pcio-vis-gallery' ),
            'colUpdated'     => __( 'Updated', 'pcio-vis-gallery' ),
            'colActions'     => __( 'Actions', 'pcio-vis-gallery' ),
            'searchPh'       => __( 'Quick search all columns…', 'pcio-vis-gallery' ),
            'filterWord'     => __( 'Filter', 'pcio-vis-gallery' ),
            'empty'          => __( 'No galleries yet.', 'pcio-vis-gallery' ),
            'emptyFiltered'  => __( 'No galleries match your filters.', 'pcio-vis-gallery' ),
            'galleryWord'    => __( 'galleries', 'pcio-vis-gallery' ),
            'showing'        => __( 'Showing', 'pcio-vis-gallery' ),
            'of'             => __( 'of', 'pcio-vis-gallery' ),
            'titleEdit'      => __( 'Edit', 'pcio-vis-gallery' ),
            'titleView'      => __( 'View', 'pcio-vis-gallery' ),
            'titleDelete'    => __( 'Delete', 'pcio-vis-gallery' ),
            'confirmDel'     => __( 'Delete this gallery? Photos stay in the media library.', 'pcio-vis-gallery' ),
            'loadErr'        => __( 'Failed to load: ', 'pcio-vis-gallery' ),
            'autocreateLbl'  => __( 'Automatically create a gallery for every new event', 'pcio-vis-gallery' ),
            // Edit
            'editGallery'    => __( 'Edit gallery', 'pcio-vis-gallery' ),
            'btnBack'        => __( 'All galleries', 'pcio-vis-gallery' ),
            'tabGeneral'     => __( 'General', 'pcio-vis-gallery' ),
            'tabPhotos'      => __( 'Photos', 'pcio-vis-gallery' ),
            'tabPermissions' => __( 'Permissions', 'pcio-vis-gallery' ),
            'fieldTitle'     => __( 'Title', 'pcio-vis-gallery' ),
            'fieldDesc'      => __( 'Description', 'pcio-vis-gallery' ),
            'phDesc'         => __( 'Describe this gallery…', 'pcio-vis-gallery' ),
            'fieldEvent'     => __( 'Linked event', 'pcio-vis-gallery' ),
            'noEvent'        => __( '— none —', 'pcio-vis-gallery' ),
            'fieldCover'     => __( 'Cover image', 'pcio-vis-gallery' ),
            'fieldVisibility'=> __( 'Visibility', 'pcio-vis-gallery' ),
            'rolesLabel'     => __( 'Allowed roles', 'pcio-vis-gallery' ),
            'btnAddPhotos'   => __( 'Add photos / videos', 'pcio-vis-gallery' ),
            'btnSetCover'    => __( 'Set as cover', 'pcio-vis-gallery' ),
            'btnRemove'      => __( 'Remove', 'pcio-vis-gallery' ),
            'coverBadge'     => __( 'Cover', 'pcio-vis-gallery' ),
            'captionPh'      => __( 'Caption…', 'pcio-vis-gallery' ),
            'noPhotos'       => __( 'No photos or videos yet. Add some from the media library.', 'pcio-vis-gallery' ),
            'dragHint'       => __( 'Drag photos to reorder. Changes save automatically.', 'pcio-vis-gallery' ),
            'mediaTitle'     => __( 'Select Photos & Videos', 'pcio-vis-gallery' ),
            'mediaBtn'       => __( 'Add to gallery', 'pcio-vis-gallery' ),
            'statusPending'  => __( 'Pending', 'pcio-vis-gallery' ),
            'stateSaving'    => __( 'Saving…', 'pcio-vis-gallery' ),
            'stateSaved'     => __( '✓ Saved', 'pcio-vis-gallery' ),
            'stateError'     => __( '✕ Error', 'pcio-vis-gallery' ),
            'errTitleReq'    => __( 'Title is required.', 'pcio-vis-gallery' ),
            'loadFail'       => __( 'Failed to load gallery.', 'pcio-vis-gallery' ),
            'btnSetPoster'   => __( 'Set poster image', 'pcio-vis-gallery' ),
            'btnChangePoster'=> __( 'Change poster image', 'pcio-vis-gallery' ),
            'posterTitle'    => __( 'Select Poster Image', 'pcio-vis-gallery' ),
            'posterBtn'      => __( 'Use as poster', 'pcio-vis-gallery' ),
        ];
    }

    private function js_data( int $gallery_id ): array {
        return [
            'rest'      => rest_url( PCIO_VIS_REST_NS ),
            'nonce'     => wp_create_nonce( 'wp_rest' ),
            'galleryId' => $gallery_id,
            'baseUrl'   => home_url( 'vis/galleries/' ),
            'i18n'      => [
                'index'    => __( 'Galleries', 'pcio-vis-gallery' ),
                'photos'   => __( 'photos', 'pcio-vis-gallery' ),
                'empty'    => __( 'No galleries available.', 'pcio-vis-gallery' ),
                'emptyG'   => __( 'This gallery has no photos or videos yet.', 'pcio-vis-gallery' ),
                'close'    => __( 'Close', 'pcio-vis-gallery' ),
                'prev'     => __( 'Previous', 'pcio-vis-gallery' ),
                'next'     => __( 'Next', 'pcio-vis-gallery' ),
                'loadErr'  => __( 'Failed to load gallery.', 'pcio-vis-gallery' ),
                'back'     => __( '← All galleries', 'pcio-vis-gallery' ),
            ],
        ];
    }

    private function render_404(): void {
        get_header();
        echo '<div class="pcio-me-gallery-wrap"><p>' . esc_html__( 'Gallery not found.', 'pcio-vis-gallery' ) . '</p></div>';
        get_footer();
    }

    private function render_denied(): void {
        get_header();
        echo '<div class="pcio-me-gallery-wrap"><p>' . esc_html__( 'You do not have permission to view this gallery.', 'pcio-vis-gallery' ) . '</p></div>';
        get_footer();
    }

    // ── Shortcode ─────────────────────────────────────────────────

    /**
     * [pcio_me_gallery id="N"] — renders one gallery as a grid + lightbox.
     */
    public function shortcode( $atts ): string {
        $atts = shortcode_atts( [ 'id' => 0 ], $atts, 'pcio_me_gallery' );
        $id   = absint( $atts['id'] );
        if ( ! $id ) {
            return '';
        }

        $gallery = PCIO_VIS_Gallery_DB::get( $id );
        if ( ! $gallery ) {
            return '<p>' . esc_html__( 'Gallery not found.', 'pcio-vis-gallery' ) . '</p>';
        }
        if ( ! self::user_can_view( $gallery ) ) {
            return '<p>' . esc_html__( 'You do not have permission to view this gallery.', 'pcio-vis-gallery' ) . '</p>';
        }

        $assets = plugins_url( 'assets/', PCIO_VIS_GALLERY_FILE );
        wp_enqueue_style( 'pcio-me-gallery', $assets . 'gallery.css', [], '1.1.0' );
        wp_enqueue_script( 'pcio-me-gallery', $assets . 'gallery.js', [], '1.2.0', true );

        $photos = PCIO_VIS_Gallery_DB::get_photos( $id, 'approved' );

        return $this->render_grid( $gallery, $photos );
    }

    /**
     * Server-rendered grid markup (works without JS; JS upgrades it to a lightbox).
     *
     * @param array $gallery
     * @param array $photos
     */
    public function render_grid( array $gallery, array $photos ): string {
        ob_start();
        ?>
        <div class="pcio-me-gallery" data-gallery-id="<?php echo esc_attr( (string) $gallery['id'] ); ?>">
            <?php if ( ! empty( $gallery['title'] ) ) : ?>
                <h2 class="pcio-me-gallery__title"><?php echo esc_html( $gallery['title'] ); ?></h2>
            <?php endif; ?>
            <?php if ( ! empty( $gallery['description'] ) ) : ?>
                <div class="pcio-me-gallery__desc"><?php echo wp_kses_post( $gallery['description'] ); ?></div>
            <?php endif; ?>

            <?php if ( empty( $photos ) ) : ?>
                <p class="pcio-me-gallery__empty"><?php esc_html_e( 'This gallery has no photos or videos yet.', 'pcio-vis-gallery' ); ?></p>
            <?php else : ?>
                <div class="pcio-me-gallery__grid">
                    <?php foreach ( $photos as $i => $photo ) : ?>
                        <?php $is_video = ( $photo['media_type'] ?? 'image' ) === 'video'; ?>
                        <a class="pcio-me-gallery__thumb<?php echo $is_video ? ' pcio-me-gallery__thumb--video' : ''; ?>"
                           href="<?php echo esc_url( $photo['full_url'] ); ?>"
                           data-index="<?php echo esc_attr( (string) $i ); ?>"
                           data-type="<?php echo esc_attr( $photo['media_type'] ?? 'image' ); ?>"
                           data-mime="<?php echo esc_attr( $photo['mime_type'] ?? '' ); ?>"
                           data-full="<?php echo esc_url( $photo['full_url'] ); ?>"
                           data-poster="<?php echo esc_url( $is_video ? ( $photo['thumb_url'] ?? '' ) : '' ); ?>"
                           data-caption="<?php echo esc_attr( $photo['caption'] ); ?>">
                            <?php if ( ! empty( $photo['thumb_url'] ) ) : ?>
                                <img src="<?php echo esc_url( $photo['thumb_url'] ); ?>"
                                     alt="<?php echo esc_attr( $photo['caption'] ); ?>"
                                     loading="lazy">
                            <?php endif; ?>
                            <?php if ( $is_video ) : ?>
                                <span class="pcio-me-gallery__play" aria-hidden="true"></span>
                            <?php endif; ?>
                            <?php if ( ! empty( $photo['caption'] ) ) : ?>
                                <span class="pcio-me-gallery__caption"><?php echo esc_html( $photo['caption'] ); ?></span>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    // ── Visibility gate ───────────────────────────────────────────

    /**
     * Decide whether the current user may view a gallery.
     *
     * @param array $gallery  Decorated gallery row.
     */
    public static function user_can_view( array $gallery ): bool {
        // Gallery moderators/managers always see everything.
        if ( current_user_can( 'vis_gallery_moderate' ) || current_user_can( 'vis_gallery_manage' ) ) {
            return true;
        }

        $visibility = $gallery['visibility'] ?? 'public';

        switch ( $visibility ) {
            case 'public':
                return true;

            case 'logged_in':
                return is_user_logged_in();

            case 'participants':
                if ( ! is_user_logged_in() ) {
                    return false;
                }
                // No event linked → behave like logged_in.
                $event_id = (int) ( $gallery['event_id'] ?? 0 );
                if ( ! $event_id || ! class_exists( 'PCIO_VIS_Signups_DB' ) ) {
                    return true;
                }
                $member_id = PCIO_VIS_Signups_DB::get_member_id_for_current_user();
                if ( ! $member_id ) {
                    return false;
                }
                return null !== PCIO_VIS_Signups_DB::get_for_member_event( $member_id, $event_id );

            case 'roles':
                if ( ! is_user_logged_in() ) {
                    return false;
                }
                $allowed = (array) ( $gallery['visibility_roles'] ?? [] );
                if ( empty( $allowed ) ) {
                    return false;
                }
                $member_role = self::current_member_vis_role();
                return $member_role !== '' && in_array( $member_role, $allowed, true );
        }

        return false;
    }

    /**
     * Resolve the current user's Vis role slug (from me_member_roles), or ''.
     */
    private static function current_member_vis_role(): string {
        if ( ! class_exists( 'PCIO_VIS_Signups_DB' ) || ! class_exists( 'PCIO_VIS_Roles_DB' ) ) {
            return '';
        }
        $member_id = PCIO_VIS_Signups_DB::get_member_id_for_current_user();
        if ( ! $member_id ) {
            return '';
        }
        $assignment = PCIO_VIS_Roles_DB::get_member_role( $member_id );
        return $assignment ? (string) ( $assignment['vis_role'] ?? '' ) : '';
    }

    /**
     * Galleries the current user may view (used by the /vis/galleries index).
     */
    public static function get_viewable(): array {
        return array_values( array_filter(
            PCIO_VIS_Gallery_DB::get_all(),
            [ self::class, 'user_can_view' ]
        ) );
    }
}
