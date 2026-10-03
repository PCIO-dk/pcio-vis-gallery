<?php
/**
 * Front-end template: Gallery edit page.
 * Served at /vis/galleries/manage/{id}/
 *
 * Access control (login + vis_gallery_manage) and existence of the gallery
 * are enforced by PCIO_VIS_Gallery_Frontend::serve_manage() before this file
 * is included.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

show_admin_bar( false );
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Vis &mdash; <?php esc_html_e( 'Edit gallery', 'pcio-vis-gallery' ); ?></title>
    <?php wp_head(); ?>
</head>
<body class="pcio-me-body">

<div id="pcio-me-wrap">

    <?php $pcio_me_active_page = 'galleries'; require PCIO_VIS_PLUGIN_DIR . 'templates/_nav.php'; ?>

    <main class="me-main">
        <div id="pcio-meg-edit-app">
            <div class="me-loading">
                <div class="me-spinner"></div>
                <span><?php esc_html_e( 'Loading…', 'pcio-vis-gallery' ); ?></span>
            </div>
        </div>
    </main>

</div>

<?php wp_footer(); ?>
</body>
</html>
