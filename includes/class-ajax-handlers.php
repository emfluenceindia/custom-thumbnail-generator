<?php

class CTGEN_Ajax_Handlers {

    public function __construct() {
        /** Action hooks */
        add_action( 'wp_ajax_ctgen_add_custom_size', array( $this, 'ctgen_ajax_add_custom_size' ) );
        add_action( 'wp_ajax_ctgen_remove_custom_size', array( $this, 'ctgen_ajax_remove_custom_size' ) );

        add_action( 'wp_ajax_ctgen_get_attachments', array( $this, 'ctgen_get_all_attachments' ) );
        add_action( 'wp_ajax_ctgen_regenerate_single', array( $this, 'ctgen_regenerate_single_attachment_thumbnail' ) );
        add_action( 'wp_ajax_ctgen_regenerate_single_slug', array( $this, 'ctgen_regenerate_thumbs_for_single_size' ) );

        add_action( 'wp_ajax_ctgen_reload_thumb_list', array( $this, 'ctgen_ajax_load_thumb_list' ) );
    }

    /** Add new thumbnail size */
    function ctgen_ajax_add_custom_size() {
        check_ajax_referer( 'ctgen_media_actions', 'security' );

        if( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'You are not authorized to perform this action!' );
        }

        $fld_validation_message = __( 'Not all field values are supplied!', 'custom-thumbnail-generator' );

        if( ! isset( $_POST[ 'ctgen-width' ] ) || ! isset( $_POST[ 'ctgen-height' ] ) ) {
            wp_send_json_error( $fld_validation_message );
        }

        $width  = absint( sanitize_text_field( wp_unslash( $_POST[ 'ctgen-width' ] ) ) );
        $height = absint( sanitize_text_field( wp_unslash( $_POST[ 'ctgen-height' ] ) ) );
        $crop   = sanitize_text_field( isset( $_POST[ 'ctgen-crop' ] ) );
        $slug   = 'ctgen_' . $width . 'x' . $height;

        $sizes = get_option( 'ctgen_custom_image_sizes', array() );;

        $str_message = "";

        if( isset( $sizes[ $slug ] ) ) {
            $str_message = "The requested thumbnail size ($width px x $height px) already exists!";
            wp_send_json_error( $str_message );
            die();
        }

        $sizes[ $slug ] = compact( 'width', 'height', 'crop' );
        update_option( 'ctgen_custom_image_sizes', $sizes );

        // Register this image size immediately
        add_image_size( $slug, $width, $height, $crop );

        $registered_sizes = wp_get_registered_image_subsizes();
        wp_send_json_success( array(
            'custom_sizes' => $registered_sizes,
        ) );

        // $str_message = "The requested thumbnail size ($width px x $height px) has been generated";
        // wp_send_json_success( $str_message );
    }

    /** Load custom thumbnail list table */
    function ctgen_ajax_load_thumb_list() {
        check_ajax_referer( 'ctgen_media_actions', 'security' );

        if( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'You are not authorized to perform this action!' );
        }

        $html_template = CTGEN_TEMPLATE_DIR . 'ctgen-custom-thumb-list.html.php';
        ob_start();
        include( $html_template );
        $template = ob_get_clean();

        wp_send_json_success(
            array(
                'success'   => true,
                'data_list' => $template
            )
        );
    }

    /** Get all attachments */
    function ctgen_get_all_attachments() {
        check_ajax_referer( 'ctgen_media_actions', 'security' );

        if( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'You are not authorized to perform this action!' );
        }

        $query_args = array(
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => -1,
            'orderby'        => 'DATE',
            'order'          => 'ASC',
            'fields'         => 'ids',
        );

        $query = new WP_Query( $query_args );

        wp_send_json_success( array( 'ids' => $query->posts ) );
    }

    /** Generate thumbnails for a single attachment by attachment ID */
    function ctgen_regenerate_single_attachment_thumbnail() {
        check_ajax_referer( 'ctgen_media_actions', 'security' );

        if( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'You are not authorized to perform this action!' );
        }

        if( ! isset( $_POST['attachment_id'] ) || empty( $_POST['attachment_id'] ) ) {
            wp_send_json_error( array( 'message' => 'Invalid argument' ) );
        }

        $id = (int)$_POST['attachment_id'];
        if( ! $id ) {
            wp_send_json_error( array( 'message' => 'Invalid ID' ) );
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        $metadata = wp_generate_attachment_metadata( $id, get_attached_file( $id ) );

        if( $metadata ) {
            wp_update_attachment_metadata( $id, $metadata );
            wp_send_json_success( array( 'message' => 'Success' ) );
        } else {
            wp_send_json_error( array( 'message' => 'Failed' ) );
        }
    }

    /**
     * When regenerating thumbnail or a particular slug
     * Useful when a new size is added and the thumbnails are regenerated for that size only
     */
    function ctgen_regenerate_thumbs_for_single_size() {
        check_ajax_referer( 'ctgen_media_actions', 'security' );

        if( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'You are not authorized to perform this action!' );
        }

        if( ! isset( $_POST['slug'] ) || empty( $_POST['slug'] ) || ! is_string( $_POST['slug'] ) ) {
            wp_send_json_error( array( 'message' => 'Invalid thumbnail size info.' ) );
        }

        $target_size = sanitize_text_field( wp_unslash( $_POST['slug'] ) );
        $registered_thumb_sizes = wp_get_registered_image_subsizes();

        if( ! isset( $registered_thumb_sizes[$target_size] ) ) {
            wp_send_json_error( 
                array( 
                    'success' => false, 
                    'message' => 'Supplied thumb size is not defined.' 
                ) 
            );
        }

        $query_args = array(
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => -1,
            'post_mime_type' => 'image',
        );

        $attachments = get_posts( $query_args );

        if( 0 === count( $attachments ) ) {
            wp_send_json_error(
                array(
                    'success' => false,
                    'message' => 'Media library contains no image. Thumbnail generation failed.'
                )
            );
        }

        $count = 0;

        foreach( $attachments as $att ) {
            $id     = $att->ID;
            $file   = get_attached_file( $id );
            $editor = wp_get_image_editor( $file );

            if( is_wp_error( $editor ) ) continue;

            // Resize just the target size
            $thumb_width  = $registered_thumb_sizes[$target_size]['width'];
            $thumb_height = $registered_thumb_sizes[$target_size]['height'];
            $thumb_crop   = $registered_thumb_sizes[$target_size]['crop'];

            $editor->resize( $thumb_width, $thumb_height, $thumb_crop );
            $saved = $editor->save();

            if( is_wp_error( $saved ) ) continue;

            // Update metadata for the specific size
            $meta = wp_get_attachment_metadata( $id );
            $meta['sizes'][$target_size] = array(
                'file'      => basename( $saved['file'] ),
                'width'     => $saved['width'],
                'height'    => $saved['height'],
                'mime-type' => $saved['mime-type'],
            );

            wp_update_attachment_metadata( $id, $meta );
            
            $count++;
        }

        wp_send_json_success( array(
            'success' => true,
            'message' => $count . ' ' . $target_size .  ' thumbnails generated successfully.',
        ) );
    }

    /** Remove a custom thumbnail size without removing any existing thumbnail files */
    function ctgen_ajax_remove_custom_size() {
        check_ajax_referer( 'ctgen_media_actions', 'security' );

        if( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'You are not authorized to perform this action!' );
        }

        if( ! isset( $_POST['slug'] ) || empty( $_POST['slug'] ) ) {
            wp_send_json_error( 
                array(
                    'status'  => 'failed', 
                    'message' => 'There has been a critical error! Plase trye again later.'
                ) 
            );
        }

        $slug = sanitize_text_field( wp_unslash( $_POST['slug'] ) );

        $sizes = get_option( 'ctgen_custom_image_sizes', array() );

        if( isset( $sizes[ $slug ] ) ) {
            unset( $sizes[ $slug ] );
            update_option( 'ctgen_custom_image_sizes', $sizes );
        }

        wp_send_json_success(
            array(
                'success'    => true,
                'size_slug'  => $slug,
                'message'    => 'Thumbnail ' . $slug . ' removed',
            )
        );
    }
}