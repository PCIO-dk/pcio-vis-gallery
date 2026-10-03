<?php
/*
Plugin Name:  PCIO VIS Gallery
Plugin URI:   https://www.pcio.dk/
Description:  Adds event-linked photo galleries to PCIO VIS Member Event. Galleries store images as standard WordPress Media Library attachments and can be embedded with [pcio_me_gallery id="N"] or browsed at /vis/galleries/.
Version:      1.0.0
Author:       PCIO
Author URI:   https://www.pcio.dk
Requires at least: 6.2
Requires PHP: 8.1
Requires Plugins: pcio-vis-member-event
License:      GPL2
License URI:  https://www.gnu.org/licenses/gpl-2.0.html
Text Domain:  pcio-vis-gallery
Domain Path:  /languages
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'PCIO_VIS_GALLERY_FILE', __FILE__ );
define( 'PCIO_VIS_GALLERY_DIR',  plugin_dir_path( __FILE__ ) );

require_once PCIO_VIS_GALLERY_DIR . 'includes/class-pcio-vis-gallery-db.php';
require_once PCIO_VIS_GALLERY_DIR . 'includes/class-pcio-vis-gallery-rest.php';
require_once PCIO_VIS_GALLERY_DIR . 'includes/class-pcio-vis-gallery-frontend.php';
require_once PCIO_VIS_GALLERY_DIR . 'includes/class-pcio-vis-gallery-integration.php';

class PCIO_VIS_Gallery_Plugin {

    /**
     * Custom capabilities and the WP roles they are granted to on activation.
     * (Phase 1: mapped to WP roles directly; a later core change can surface
     * these in the Vis Roles admin UI.)
     */
    const CAP_ROLE_MAP = [
        'vis_gallery_view'     => [ 'subscriber', 'contributor', 'author', 'editor', 'administrator' ],
        'vis_gallery_upload'   => [ 'author', 'editor', 'administrator' ],
        'vis_gallery_manage'   => [ 'editor', 'administrator' ],
        'vis_gallery_moderate' => [ 'editor', 'administrator' ],
    ];

    public static function register(): void {
        add_action( 'init',          [ 'PCIO_VIS_Gallery_DB', 'install' ] );
        add_action( 'rest_api_init', [ __CLASS__, 'register_rest'   ] );

        ( new PCIO_VIS_Gallery_Frontend() )->register();
        ( new PCIO_VIS_Gallery_Integration() )->register();
    }

    // ── Activation / deactivation ─────────────────────────────────

    public static function activate(): void {
        PCIO_VIS_Gallery_DB::install();
        foreach ( self::CAP_ROLE_MAP as $cap => $roles ) {
            foreach ( $roles as $role_slug ) {
                $role = get_role( $role_slug );
                if ( $role ) {
                    $role->add_cap( $cap );
                }
            }
        }
        // Our rewrite rules are registered on 'init'; flush so /vis/galleries works.
        ( new PCIO_VIS_Gallery_Frontend() )->register_rewrites();
        flush_rewrite_rules();
    }

    public static function deactivate(): void {
        foreach ( array_keys( self::CAP_ROLE_MAP ) as $cap ) {
            foreach ( wp_roles()->role_objects as $role ) {
                $role->remove_cap( $cap );
            }
        }
        flush_rewrite_rules();
    }

    

    // ── REST ──────────────────────────────────────────────────────

    public static function register_rest(): void {
        ( new PCIO_VIS_Gallery_Rest() )->register();
    }
}

register_activation_hook( __FILE__, [ 'PCIO_VIS_Gallery_Plugin', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'PCIO_VIS_Gallery_Plugin', 'deactivate' ] );

add_action( 'plugins_loaded', static function (): void {
    if ( ! class_exists( 'PCIO_VIS_Plugin' ) ) {
        return;
    }
    PCIO_VIS_Gallery_Plugin::register();
} );
