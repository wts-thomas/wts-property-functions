<?php
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'plugin_community_listings_shortcode' ) ) {
    function plugin_community_listings_shortcode( $atts ) {

        $post_title = get_the_title();

        $atts = shortcode_atts([
            'community-selection' => $post_title
        ], $atts);

        $shortcode = '[es_my_listing 
            layout="grid-4"
            show_sort="0"
            show_layouts="0"
            show_page_title="0"
            approximate-age="New, Under Construction, Model - Not for Sale"
            es_type="147, 726, 143"
            es_status="146, 144"
            community-selection="' . esc_attr( $atts['community-selection'] ) . '"
        ]';

        return do_shortcode( $shortcode );
    }

    add_shortcode( 'plugin_community_listings', 'plugin_community_listings_shortcode' );
}