<?php
/**
 * Front-end template: index of galleries the current user can view.
 * Served at /vis/galleries/ by PCIO_VIS_Gallery_Frontend::serve_templates().
 *
 * @package pcio-vis-gallery
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$pcio_me_galleries = PCIO_VIS_Gallery_Frontend::get_viewable();
?>
<div class="pcio-me-gallery-wrap">
    <div class="pcio-me-gallery__header">
        <h1 class="pcio-me-gallery__title"><?php esc_html_e( 'Galleries', 'pcio-vis-gallery' ); ?></h1>
        <?php if ( current_user_can( 'vis_gallery_manage' ) ) : ?>
            <a class="pcio-me-gallery__manage-btn"
               href="<?php echo esc_url( home_url( 'vis/galleries/manage/' ) ); ?>">
                <?php esc_html_e( 'Manage galleries', 'pcio-vis-gallery' ); ?>
            </a>
        <?php endif; ?>
    </div>

    <?php if ( empty( $pcio_me_galleries ) ) : ?>
        <p class="pcio-me-gallery__empty"><?php esc_html_e( 'No galleries available.', 'pcio-vis-gallery' ); ?></p>
    <?php else : ?>
        <div class="pcio-me-galleries__grid">
            <?php foreach ( $pcio_me_galleries as $pcio_me_g ) : ?>
                <a class="pcio-me-gallery-card"
                   href="<?php echo esc_url( home_url( 'vis/galleries/' . (int) $pcio_me_g['id'] . '/' ) ); ?>">
                    <?php if ( ! empty( $pcio_me_g['cover_url'] ) ) : ?>
                        <img class="pcio-me-gallery-card__cover"
                             src="<?php echo esc_url( $pcio_me_g['cover_url'] ); ?>"
                             alt="<?php echo esc_attr( $pcio_me_g['title'] ); ?>"
                             loading="lazy">
                    <?php else : ?>
                        <span class="pcio-me-gallery-card__cover"></span>
                    <?php endif; ?>
                    <span class="pcio-me-gallery-card__body">
                        <span class="pcio-me-gallery-card__title"><?php echo esc_html( $pcio_me_g['title'] ); ?></span>
                        <span class="pcio-me-gallery-card__meta">
                            <?php
                            if ( ! empty( $pcio_me_g['event_title'] ) ) {
                                echo esc_html( $pcio_me_g['event_title'] ) . ' · ';
                            }
                            printf(
                                /* translators: %d: number of photos */
                                esc_html( _n( '%d photo', '%d photos', (int) $pcio_me_g['photo_count'], 'pcio-vis-gallery' ) ),
                                (int) $pcio_me_g['photo_count']
                            );
                            ?>
                        </span>
                    </span>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
