<?php if( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed early ?>

<div id="ctgen-thumb-list">
    <h3><?php esc_html_e( 'Custom Thumbnails', 'custom-thumbnail-generator' ); ?></h3>
    <hr />
    <table class="widefat ctgen-list-table ctgen-custom-size-list">
        <thead>
            <tr>
                <th class="ctgen-title-cell"><?php esc_html_e( 'Name', 'custom-thumbnail-generator' ); ?></th>
                <th class="ctgen-text-center"><?php esc_html_e( 'Width', 'custom-thumbnail-generator' ); ?></th>
                <th class="ctgen-text-center"><?php esc_html_e( 'Height', 'custom-thumbnail-generator' ); ?></th>
                <th class="ctgen-text-center"><?php esc_html_e( 'Crop', 'custom-thumbnail-generator' ); ?></th>
                <th class="ctgen-text-center"><?php esc_html_e( 'Action', 'custom-thumbnail-generator' ); ?></th>
            </tr>
        </thead>
        <?php $sizes = get_option( 'ctgen_custom_image_sizes', array() ); ?>
        <tbody class="ctgen-sizes-list">
            <tr>
                <?php foreach( $sizes as $slug => $size ): ?>
                    <?php $crop = ( ! empty( esc_html( $size[ 'crop' ] ) ) ) ? 1 : 0; ?>
                    <tr class="ctgen-1m-0-false">
                        <td class="ctgen-title-cell"><?php echo wp_kses_post( $slug ); ?> </td>
                        <td class="ctgen-text-center"><?php echo esc_html( $size[ 'width' ] ); ?>px</td>
                        <td class="ctgen-text-center"><?php echo esc_html( $size[ 'height' ] ); ?>px</td>
                        <td class="ctgen-text-center"><?php echo $size[ 'crop' ] ? '<span class="yes">✔</span>' : '<span class="no">✖</span>'; ?></td>
                        <td class="ctgen-text-center">
                            <button title="<?php esc_attr_e( 'Regenerate', 'custom-thumbnail-generator' ); ?>"
                                data-width="<?php echo esc_attr( $size[ 'width' ] ); ?>"
                                data-height="<?php echo esc_attr( $size[ 'height' ] ) ?>"
                                data-crop="<?php echo esc_attr( $crop ) ?>"    
                                data-slug="<?php echo esc_attr( $slug ); ?>" 
                                class="button ctgen-regen-thumbs">
                                    <i class="fa fa-recycle" aria-hidden="true"></i>
                            </button>
                            <button title="<?php esc_attr_e( 'Remove', 'custom-thumbnail-generator' ); ?>" 
                                data-width="<?php echo esc_attr( $size[ 'width' ] ); ?>"
                                data-height="<?php echo esc_attr( $size[ 'height' ] ) ?>"
                                data-crop="<?php echo esc_attr( $crop ) ?>"
                                data-slug="<?php echo esc_attr( $slug ); ?>" class="button ctgen-delete-custom-size">
                                    <i class="fa fa-trash" aria-hidden="true"></i>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tr>
        </tbody>
    </table>
    <?php if( empty( $sizes ) ) : ?>
    <div id="ctgen-no-custom-sizes">
        <?php esc_html_e( 'You don\'t have any custom thumbnail sizes yet!', 'custom-thumbnail-generator' ); ?> 
        <a id="ctgen-btn-shift-focus"><?php esc_html_e( 'CREATE NOW', 'custom-thumbnail-generator' ); ?></a>.<br />
        <?php esc_html_e( 'They will remain accessible across the website even you switch the theme.', 'custom-thumbnail-generator' ); ?>
    </div>
    <?php endif; ?>
</div>