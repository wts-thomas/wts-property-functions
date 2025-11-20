<?php
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'plugin_builder_listings_shortcode' ) ) {
    function plugin_builder_listings_shortcode( $atts ) {

        $post_title = get_the_title();

        $atts = shortcode_atts([
            'builder-selection' => $post_title
        ], $atts);

        $shortcode = '[es_my_listing 
            layout="grid-4"
            show_sort="0"
            show_layouts="0"
            show_page_title="0"
            approximate-age="New, Under Construction, Model - Not for Sale"
            es_type="147, 726, 143"
            es_status="146, 144"
            builder-selection="' . esc_attr( $atts['builder-selection'] ) . '"
        ]';

        return do_shortcode( $shortcode );
    }

    add_shortcode( 'plugin_builder_listings', 'plugin_builder_listings_shortcode' );
}