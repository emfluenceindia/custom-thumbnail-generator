<?php
/**
 * Settings page template: plugin header, add-size form and bulk regeneration controls.
 *
 * @package Custom_Thumbnail_Generator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed early.
}
?>

<?php CTGEN_Functions::ctgen_render_page_header( __( 'Custom Thumbnail Generator', 'custom-thumbnail-generator' ) ); ?>
<?php settings_errors( 'general' ); ?>
<?php echo ctgen_webp_auto_convert()->render_settings_card(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside render_settings_card(). ?>
<div id="ctgen-action-container">
	<div id="ctgen-form-div">
		<fieldset class="ctgen-form-container">
			<h3><?php esc_html_e( 'Add New Thumbnail Size', 'custom-thumbnail-generator' ); ?></h3>
			<hr />
			<form id="ctgen-form" method="post">
				<table class="ctgen-form-table">
					<tr>
						<th><?php esc_html_e( 'Width', 'custom-thumbnail-generator' ); ?>&nbsp;</th>
						<td>
							<input type="number" min="25" max="10000" step="1" name="ctgen-width" id="ctgen-width" required /> px
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Height', 'custom-thumbnail-generator' ); ?>&nbsp;</th>
						<td>
							<input type="number" min="25" max="10000" step="1" name="ctgen-height" id="ctgen-height" required /> px
						</td>
					</tr>
					<tr>
						<td>&nbsp;</td>
						<td>
							<input type="checkbox" name="ctgen-crop" id="ctgen-crop" value="1" />
							&nbsp;<label for="ctgen-crop"><?php esc_html_e( 'Allow Crop', 'custom-thumbnail-generator' ); ?></label>
						</td>
					</tr>
					<tr>
						<td>&nbsp;</td>
						<td>
							<input type="submit" value="<?php esc_attr_e( 'Add Thumbnail Size', 'custom-thumbnail-generator' ); ?>" class="button button-primary" id="ctgen-addsise" name="ctgen-addsize" />
						</td>
					</tr>
				</table>

				<div id="ctgen-form-response"></div>
			</form>
		</fieldset>
		
		<div id="ctgen-generator-ui">
			<hr />
			<p> <?php esc_html_e( 'Hit the button below to (re)generate all thumbnails.', 'custom-thumbnail-generator' ); ?> </p>
			<button id="ctgen-regenerate" class="button button-secondary">
				<i class="fa fa-recycle" aria-hidden="true"></i>&nbsp; <?php esc_html_e( 'Regenerate Thumbnails', 'custom-thumbnail-generator' ); ?> 
			</button>
			<p><?php esc_html_e( 'For a specific thumbnail size, hit the associated recycle button ', 'custom-thumbnail-generator' ); ?> ( <i class="fa fa-recycle ctgen-regen-thumbs inline" aria-hidden="true"></i> )  <?php esc_html_e( ' in the table.', 'custom-thumbnail-generator' ); ?> &nbsp;<i class="fa fa-hand-o-right ctgen-arrow-pointer" aria-hidden="true"></i></p>
			<div id="ctgen-progressbar">
				<div id="ctgen-progress"></div>
				<div id="ctgen-percentage-increment"></div>
			</div>
			<div id="ctgen-generator-status"></div>
		</div>

		<div id="ctgen-webp-ui" data-state="idle">
			<hr />
			<p><?php esc_html_e( 'Create WebP copies of existing JPEG and PNG images: the full-size image and all of its thumbnails. Original images and thumbnails are not changed.', 'custom-thumbnail-generator' ); ?></p>
			<button type="button" id="ctgen-webp-convert-all" class="button button-secondary">
				<?php esc_html_e( 'Convert All Thumbnails to WebP', 'custom-thumbnail-generator' ); ?>
			</button>
			<p><?php esc_html_e( 'For a specific thumbnail size, use the WebP button in its table row.', 'custom-thumbnail-generator' ); ?></p>
			<div id="ctgen-webp-progressbar" role="progressbar" aria-label="<?php esc_attr_e( 'WebP conversion progress', 'custom-thumbnail-generator' ); ?>" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
				<div id="ctgen-webp-progress"></div>
				<div id="ctgen-webp-percentage" aria-hidden="true"></div>
			</div>
			<p id="ctgen-webp-current"></p>
			<p id="ctgen-webp-counts"></p>
			<div id="ctgen-webp-status" role="status" aria-live="polite"></div>
			<ul id="ctgen-webp-errors"></ul>
			<div class="notice notice-info inline ctgen-webp-gif-notice">
				<p><strong><?php esc_html_e( 'WebP conversion:', 'custom-thumbnail-generator' ); ?></strong> <?php esc_html_e( 'Animated GIFs are not convertible to WebP without losing their animation, so GIF images are not converted. JPEG and PNG images are converted.', 'custom-thumbnail-generator' ); ?></p>
			</div>
		</div>
	</div>

	<div id="ctgen-tables-column">
		<?php require_once __DIR__ . '/ctgen-custom-thumb-list-html.php'; ?>
		<?php
		// Included from CTGEN_Functions::ctgen_render_settings_page_form(), so $this is that instance.
		$this->ctgen_render_default_thumbnail_list_table();
		?>
	</div>
</div>

