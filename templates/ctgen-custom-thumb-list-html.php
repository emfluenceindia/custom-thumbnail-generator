<?php
/**
 * Custom thumbnail sizes table (also re-rendered via the ctgen_reload_thumb_list AJAX action).
 *
 * @package Custom_Thumbnail_Generator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed early.
}
?>

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
		<?php $ctgen_sizes = get_option( 'ctgen_custom_image_sizes', array() ); ?>
		<tbody class="ctgen-sizes-list">
			<tr>
				<?php foreach ( $ctgen_sizes as $ctgen_slug => $ctgen_size ) : ?>
					<?php $ctgen_crop = ( ! empty( esc_html( $ctgen_size['crop'] ) ) ) ? 1 : 0; ?>
					<tr class="ctgen-1m-0-false">
						<td class="ctgen-title-cell"><?php echo wp_kses_post( $ctgen_slug ); ?> </td>
						<td class="ctgen-text-center"><?php echo esc_html( $ctgen_size['width'] ); ?>px</td>
						<td class="ctgen-text-center"><?php echo esc_html( $ctgen_size['height'] ); ?>px</td>
						<td class="ctgen-text-center"><?php echo $ctgen_size['crop'] ? '<span class="yes">✔</span>' : '<span class="no">✖</span>'; ?></td>
						<td class="ctgen-text-center">
							<button title="<?php esc_attr_e( 'Regenerate', 'custom-thumbnail-generator' ); ?>"
								data-width="<?php echo esc_attr( $ctgen_size['width'] ); ?>"
								data-height="<?php echo esc_attr( $ctgen_size['height'] ); ?>"
								data-crop="<?php echo esc_attr( $ctgen_crop ); ?>"    
								data-slug="<?php echo esc_attr( $ctgen_slug ); ?>" 
								class="button ctgen-regen-thumbs">
									<i class="fa fa-recycle" aria-hidden="true"></i>
							</button>
							<button title="<?php esc_attr_e( 'Remove', 'custom-thumbnail-generator' ); ?>" 
								data-width="<?php echo esc_attr( $ctgen_size['width'] ); ?>"
								data-height="<?php echo esc_attr( $ctgen_size['height'] ); ?>"
								data-crop="<?php echo esc_attr( $ctgen_crop ); ?>"
								data-slug="<?php echo esc_attr( $ctgen_slug ); ?>" class="button ctgen-delete-custom-size">
									<i class="fa fa-trash" aria-hidden="true"></i>
							</button>
						</td>
					</tr>
				<?php endforeach; ?>
			</tr>
		</tbody>
	</table>
	<?php if ( empty( $ctgen_sizes ) ) : ?>
	<div id="ctgen-no-custom-sizes">
		<?php esc_html_e( 'You don\'t have any custom thumbnail sizes yet!', 'custom-thumbnail-generator' ); ?> 
		<a id="ctgen-btn-shift-focus"><?php esc_html_e( 'CREATE NOW', 'custom-thumbnail-generator' ); ?></a>.<br />
		<?php esc_html_e( 'They will remain accessible across the website even you switch the theme.', 'custom-thumbnail-generator' ); ?>
	</div>
	<?php endif; ?>
</div>