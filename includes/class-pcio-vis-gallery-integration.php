<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Deep integration between the Photo Gallery extension and the core
 * Member Event system (events, navigation, capabilities).
 *
 * All wiring here depends on hooks added to the core plugin (Phase 2):
 *   • pcio_me_event_saved / pcio_me_event_deleted  (auto-create / cleanup)
 *   • pcio_me_after_event_main                     (gallery section on event page)
 *   • pcio_me_nav_items                            (front-end "Galleries" nav)
 *   • pcio_me_all_caps / pcio_me_cap_labels        (caps in the Vis Roles UI)
 *
 * Each hook degrades gracefully if the core plugin predates it, so the
 * extension keeps working (just without the deep integration).
 */
class PCIO_VIS_Gallery_Integration {

    /** Option storing whether to auto-create a gallery for every new event. */
    const OPT_AUTOCREATE = 'pcio_me_gallery_autocreate';

    public function register(): void {
        // Event lifecycle.
        add_action( 'pcio_me_event_saved',   [ $this, 'on_event_saved' ], 10, 3 );
        add_action( 'pcio_me_event_deleted', [ $this, 'on_event_deleted' ] );

        // Front-end gallery tab on the event detail page.
        add_filter( 'pcio_me_event_tabs', [ $this, 'add_event_tab' ], 10, 2 );

        // Front-end navigation.
        add_filter( 'pcio_me_nav_items', [ $this, 'add_nav_item' ], 10, 2 );

        // Card on the /vis/ landing page.
        add_action( 'pcio_me_home_cards', [ $this, 'render_home_card' ] );

        // Surface gallery caps in the Vis Roles admin matrix.
        add_filter( 'pcio_me_all_caps',   [ $this, 'register_caps' ] );
        add_filter( 'pcio_me_cap_labels', [ $this, 'register_cap_labels' ] );
    }

    // ── Event lifecycle ───────────────────────────────────────────

    /**
     * Auto-create a gallery for a newly created event when the option is on.
     *
     * @param int   $event_id
     * @param array $data
     * @param bool  $is_new
     */
    public function on_event_saved( int $event_id, array $data, bool $is_new = false ): void {
        if ( ! $is_new ) {
            return;
        }
        if ( '1' !== get_option( self::OPT_AUTOCREATE, '0' ) ) {
            return;
        }
        if ( PCIO_VIS_Gallery_DB::get_by_event( $event_id ) ) {
            return; // already has one
        }
        $title = trim( (string) ( $data['title'] ?? '' ) );
        PCIO_VIS_Gallery_DB::create( [
            'event_id'   => $event_id,
            'title'      => $title !== '' ? $title : __( 'Event gallery', 'pcio-vis-gallery' ),
            'visibility' => 'participants',
        ] );
    }

    /**
     * Remove the linked gallery when its event is deleted.
     */
    public function on_event_deleted( int $event_id ): void {
        $gallery = PCIO_VIS_Gallery_DB::get_by_event( $event_id );
        if ( $gallery ) {
            PCIO_VIS_Gallery_DB::delete( (int) $gallery['id'] );
        }
    }

    // ── Event page gallery tab ────────────────────────────────────

    /**
     * Register a "Gallery" tab on the event detail page via the core
     * `pcio_me_event_tabs` filter. The tab links out to the dedicated
     * galleries pages instead of rendering the photo grid inline, so the
     * images are never displayed twice.
     *
     * @param array $tabs     Existing extra tab definitions.
     * @param int   $event_id Current event ID.
     */
    public function add_event_tab( array $tabs, int $event_id ): array {
        if ( ! $event_id || ! current_user_can( 'vis_gallery_view' ) ) {
            return $tabs;
        }

        $html = $this->event_tab_html( $event_id );
        if ( '' === $html ) {
            return $tabs;
        }

        // Card styling for the tab content.
        wp_enqueue_style( 'pcio-me-gallery', plugins_url( 'assets/gallery.css', PCIO_VIS_GALLERY_FILE ), [], '1.0.1' );

        $tabs[] = [
            'id'    => 'gallery',
            'label' => __( 'Gallery', 'pcio-vis-gallery' ),
            'html'  => $html,
        ];
        return $tabs;
    }

    /**
     * Build the HTML shown inside the event's "Gallery" tab: a link to the
     * existing gallery, or a button to create one.
     *
     * @param int $event_id
     */
    private function event_tab_html( int $event_id ): string {
        $gallery    = PCIO_VIS_Gallery_DB::get_by_event( $event_id );
        $can_manage = current_user_can( 'vis_gallery_manage' );

        ob_start();

        // No gallery yet.
        if ( ! $gallery ) {
            echo '<div class="pcio-me-gallery-tab">';
            if ( $can_manage ) {
                $new_url = add_query_arg( 'for_event', $event_id, home_url( 'vis/galleries/manage/' ) );
                echo '<p class="pcio-me-gallery-tab__msg">' . esc_html__( 'No gallery exists for this event yet.', 'pcio-vis-gallery' ) . '</p>';
                echo '<a class="pcio-me-gallery__manage-btn" href="' . esc_url( $new_url ) . '">'
                    . esc_html__( 'Create gallery', 'pcio-vis-gallery' ) . '</a>';
            } else {
                echo '<p class="pcio-me-gallery-tab__msg">' . esc_html__( 'No gallery has been created for this event yet.', 'pcio-vis-gallery' ) . '</p>';
            }
            echo '</div>';
            return (string) ob_get_clean();
        }

        // Gallery exists: visibility-gate the link for non-managers.
        if ( ! $can_manage && ! PCIO_VIS_Gallery_Frontend::user_can_view( $gallery ) ) {
            ob_end_clean();
            return '';
        }

        $view_url = home_url( 'vis/galleries/' . (int) $gallery['id'] . '/' );

        echo '<div class="pcio-me-gallery-tab">';
        echo '<p class="pcio-me-gallery-tab__msg">' . esc_html__( 'Photos for this event are kept in its gallery.', 'pcio-vis-gallery' ) . '</p>';
        echo '<p class="pcio-me-gallery-tab__actions">';
        echo '<a class="pcio-me-gallery__manage-btn" href="' . esc_url( $view_url ) . '">'
            . esc_html__( 'Open gallery', 'pcio-vis-gallery' ) . '</a>';
        if ( $can_manage ) {
            $manage_url = home_url( 'vis/galleries/manage/' . (int) $gallery['id'] . '/' );
            echo ' <a class="pcio-me-gallery-tab__manage-link" href="' . esc_url( $manage_url ) . '">'
                . esc_html__( 'Manage gallery', 'pcio-vis-gallery' ) . '</a>';
        }
        echo '</p>';
        echo '</div>';

        return (string) ob_get_clean();
    }

    // ── Navigation ────────────────────────────────────────────────

    /**
     * Add a "Galleries" item to the front-end nav for users who can view galleries.
     *
     * @param array  $items
     * @param string $active
     */
    public function add_nav_item( array $items, string $active ): array {
        if ( ! current_user_can( 'vis_gallery_view' ) ) {
            return $items;
        }
        $items['galleries'] = [
            'label' => __( 'Galleries', 'pcio-vis-gallery' ),
            'url'   => home_url( 'vis/galleries/' ),
        ];
        return $items;
    }

    /**
     * Render a "Galleries" card on the /vis/ landing page.
     * Managers land on the management page; everyone else on the index.
     */
    public function render_home_card(): void {
        if ( ! current_user_can( 'vis_gallery_view' ) ) {
            return;
        }

        $can_manage = current_user_can( 'vis_gallery_manage' );
        $url  = $can_manage ? home_url( 'vis/galleries/manage/' ) : home_url( 'vis/galleries/' );
        $desc = $can_manage
            ? __( 'Create and manage event photo galleries.', 'pcio-vis-gallery' )
            : __( 'Browse photo galleries from events.', 'pcio-vis-gallery' );
        ?>
        <a class="me-home-card" href="<?php echo esc_url( $url ); ?>">
            <div class="me-home-card-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>
                    <circle cx="8.5" cy="8.5" r="1.5"/>
                    <polyline points="21 15 16 10 5 21"/>
                </svg>
            </div>
            <div class="me-home-card-body">
                <h2 class="me-home-card-title">
                    <?php esc_html_e( 'Galleries', 'pcio-vis-gallery' ); ?>
                </h2>
                <p class="me-home-card-desc"><?php echo esc_html( $desc ); ?></p>
            </div>
            <div class="me-home-card-arrow">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M5 12h14M12 5l7 7-7 7"/>
                </svg>
            </div>
        </a>
        <?php
    }

    // ── Capabilities in the Vis Roles UI ──────────────────────────

    public function register_caps( array $caps ): array {
        return array_merge( $caps, array_keys( PCIO_VIS_Gallery_Plugin::CAP_ROLE_MAP ) );
    }

    public function register_cap_labels( array $labels ): array {
        return array_merge( $labels, [
            'vis_gallery_view'     => __( 'View galleries', 'pcio-vis-gallery' ),
            'vis_gallery_upload'   => __( 'Upload gallery photos', 'pcio-vis-gallery' ),
            'vis_gallery_manage'   => __( 'Manage galleries', 'pcio-vis-gallery' ),
            'vis_gallery_moderate' => __( 'Moderate all galleries', 'pcio-vis-gallery' ),
        ] );
    }
}
