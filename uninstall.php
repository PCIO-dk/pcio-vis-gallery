<?php
/**
 * Fired when the plugin is uninstalled.
 * Drops custom tables, removes options, and revokes capabilities from all roles.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

require_once __DIR__ . '/includes/class-pcio-vis-gallery-db.php';

PCIO_VIS_Gallery_DB::drop_tables();

delete_option( 'pcio_meg_db_version' );
delete_option( 'pcio_me_gallery_autocreate' );

$pcio_meg_caps = [ 'vis_gallery_view', 'vis_gallery_upload', 'vis_gallery_manage', 'vis_gallery_moderate' ];
foreach ( wp_roles()->role_objects as $role ) {
    foreach ( $pcio_meg_caps as $pcio_meg_cap ) {
        $role->remove_cap( $pcio_meg_cap );
    }
}
