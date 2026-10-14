<?php
class CTGEN_Functions {

    /** Render default image sizes registered by WordPress and the currently active theme */
    private function ctgen_default_and_theme_based_image_sizes() {
        global $_wp_additional_image_sizes;

        $sizes = array();
        $core_sizes = [ 'thumbnail', 'medium', 'medium_large', 'large' ];

        //Default sizes from WordPress settings
        foreach( $core_sizes as $default_size ) {
            $sizes[ $default_size ] = array(
                'width'  => get_option( "{$default_size}_size_w" ),
                'height' => get_option( "{$default_size}_size_h" ),
                'crop'   => get_option( "{$default_size}_crop" ),
            );
        }

        // Additional sizes registered by add_image_size()
        if( isset( $_wp_additional_image_sizes ) && is_array( $_wp_additional_image_sizes ) ) {
            foreach( $_wp_additional_image_sizes as $name => $attr ) {
                $sizes[ $name ] = array(
                    'width'  => $attr[ 'width' ],
                    'height' => $attr[ 'height' ],
                    'crop'   => $attr[ 'crop' ],
                );
            }
        }

        return $sizes;
    }

    /** Render custom thumbnail sizes registered by Custom Thumbnail Generator */
    public function ctgen_render_default_thumbnail_list_table() {
        $default_sizes = $this->ctgen_default_and_theme_based_image_sizes(); ?>

        <div class="ctgen-form-container">
            <h3><?php esc_html_e( 'Default Thumbnails', 'custom-thumbnail-generator' ); ?></h3>

            <table class="widefat ctgen-list-table">
                <thead>
                    <tr>
                        <th class="ctg-title-cell"><?php esc_html_e( 'Name', 'custom-thumbnail-generator' ); ?></th>
                        <th class="ctg-text-center"><?php esc_html_e( 'Width', 'custom-thumbnail-generator' ); ?></th>
                        <th class="ctg-text-center"><?php esc_html_e( 'Height', 'custom-thumbnail-generator' ); ?></th>
                        <th class="ctg-text-center"><?php esc_html_e( 'Source', 'custom-thumbnail-generator' ); ?></th>
                        <th class="ctg-text-center"><?php esc_html_e( 'Crop', 'custom-thumbnail-generator' ); ?></th>
                    </tr>
                </thead>
                <tbody id="ctg-sizes-list">
                    <?php
                    if( ! empty( $default_sizes ) ) {
                        foreach( $default_sizes as $slug => $size ) { 
                            if( 0 === strpos( strtolower( $slug ), 'ctgen_' ) ) continue; // This is registered by the plugin. We will not consider it in default sizes. ?>

                            <tr>
                                <td class="ctg-title-cell"><?php echo esc_html( $slug ); ?> </td>
                                <td class="ctg-text-center"><?php echo esc_html( $size[ 'width' ] ); ?>px</td>
                                <td class="ctg-text-center"><?php echo esc_html( $size[ 'height' ] ); ?>px</td>
                                <td class="ctg-text-center"><?php echo wp_kses_post( $this->ctgen_get_size_table_title( $slug ) ); ?></td>
                                <td class="ctg-text-center"><?php echo $size[ 'crop' ] ? '<span class="yes">✔</span>' : '<span class="no">✖</span>'; ?></td>
                            </tr>
                        <?php }
                    }
                    ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /** Display thumbnail source */
    private function ctgen_get_size_table_title( $slug ) {
        if( in_array( $slug, CTGEN_NATIVE_THUMB_SIZES ) ) {
            $slug = wp_kses_post( "<span class='ctgen-image-size-register-by'>WordPress Core</span>" );
        } else {
            $slug = wp_kses_post( "<span class='ctgen-image-size-register-by'>" . wp_get_theme()->get( 'Name' ) ) . "</span>";
        }

        return $slug;
    }

    /** Render the form */
    public function ctgen_render_settings_page_form() {
        $html_template = CTGEN_TEMPLATE_DIR . 'ctgen-form.html.php';
    
        if( ! file_exists( $html_template ) ) {
            echo '<p class="ctg-common-error">Error! Template file missing.</p>';
            return;
        }

        include( $html_template );
    }
}