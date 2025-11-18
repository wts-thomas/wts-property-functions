<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Build a label → canonical Community title map
 * Includes:
 *   - Post titles
 *   - ACF alternate titles
 */
if ( ! function_exists( 'es_get_community_label_map' ) ) {
    function es_get_community_label_map() {
        static $map = null;

        if ( $map !== null ) return $map;

        $map = [];

        $q = new WP_Query([
            'post_type'      => 'post_communities',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'orderby'        => 'title',
            'order'          => 'ASC',
            'no_found_rows'  => true,
        ]);

        foreach ( (array) $q->posts as $cid ) {
            $canonical = get_the_title( $cid );
            if ( ! $canonical ) continue;

            // Map canonical
            $map[ $canonical ] = $canonical;

            // Map alternate title
            $alt = get_post_meta( $cid, 'cf_legalname_alternate_title', true );
            if ( $alt && $alt !== $canonical ) {
                $map[ $alt ] = $canonical;
            }
        }

        return $map;
    }
}

/**
 * Auto-fill community-selection when subdivision matches
 */
if ( ! function_exists( 'es_autofill_community_selection_from_subdivision' ) ) {
    add_action( 'save_post_properties', 'es_autofill_community_selection_from_subdivision', 5, 3 );
    function es_autofill_community_selection_from_subdivision( $post_id, $post, $update ) {

        if ( ! is_admin() ) return;
        if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) return;
        if ( empty( $_POST['es_property'] ) ) return;

        $es = &$_POST['es_property'];

        $subdivision = trim( $es['subdivisionname'] ?? '' );
        $community   = trim( $es['community-selection'] ?? '' );

        if ( $community !== '' ) return; // Don’t override manually filled values
        if ( $subdivision === '' ) return;

        $map = es_get_community_label_map();

        if ( isset( $map[ $subdivision ] ) ) {
            $es['community-selection'] = $map[ $subdivision ];
        }
    }
}