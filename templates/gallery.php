<?php
/**
 * Front-end template: a single gallery.
 * Served at /vis/galleries/{id}/ by PCIO_VIS_Gallery_Frontend::serve_templates().
 * Access control + the JS payload (PCIO_VIS_GALLERY) are set up before include.
 *
 * The grid itself is rendered client-side by gallery.js into
 * #pcio-me-gallery-single (so cover/order/captions always reflect the latest
 * data), with a no-JS fallback message.
 *
 * @package pcio-vis-gallery
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="pcio-me-gallery-wrap">
    <div id="pcio-me-gallery-single">
        <noscript>
            <p class="pcio-me-gallery__empty">
                <?php esc_html_e( 'JavaScript is required to view this gallery.', 'pcio-vis-gallery' ); ?>
            </p>
        </noscript>
    </div>
</div>
