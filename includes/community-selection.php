<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Populate the Estatik "community-selection" dropdown
 * with Community titles + ACF alternate titles.
 */
add_action( 'admin_enqueue_scripts', function() {

    if ( ! function_exists( 'get_current_screen' ) ) return;

    $screen = get_current_screen();
    if ( ! $screen ) return;
    if ( $screen->post_type !== 'properties' ) return;
    if ( ! in_array( $screen->base, ['post', 'post-new'], true ) ) return;

    $choices = [];

    $q = new WP_Query([
        'post_type'      => 'post_communities',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'orderby'        => 'title',
        'order'          => 'ASC',
        'fields'         => 'ids'
    ]);

    foreach ( $q->posts as $cid ) {
        $canonical = get_the_title( $cid );
        if ( ! $canonical ) continue;

        // Main title option
        $choices[] = [
            'value' => $canonical,
            'label' => $canonical
        ];

        // Alternate title option
        $alt = get_post_meta( $cid, 'cf_legalname_alternate_title', true );
        if ( $alt && $alt !== $canonical ) {
            $choices[] = [
                'value' => $canonical, // still store canonical
                'label' => $alt        // show MLS-style label
            ];
        }
    }

    wp_register_script( 'es-fill-community-select', false, [], null, true );
    wp_enqueue_script( 'es-fill-community-select' );

      wp_add_inline_script(
         'es-fill-community-select',
         'window.esCommunitySelectData = ' . wp_json_encode([
            'placeholder' => "— Select a community —",
            'choices'     => $choices,
            'selectors'   => [
                  'select[name="community-selection"]',
                  'select[name$="[community-selection]"]'
            ]
         ]) . ';'
      );

    add_action( 'admin_print_footer_scripts', function() {
        ?>
        <script>
        (function(){
            const data = window.esCommunitySelectData;
            if (!data) return;

            function init() {
                const selects = [];

                (data.selectors || []).forEach(sel => {
                    document.querySelectorAll(sel).forEach(el => selects.push(el));
                });

                selects.forEach(sel => {
                    const current = sel.value;
                    sel.innerHTML = '';

                    if (data.placeholder) {
                        sel.add(new Option(data.placeholder, ''));
                    }

                    (data.choices || []).forEach(opt => {
                        sel.add(new Option(opt.label, opt.value));
                    });

                    if (current) {
                        sel.value = current;
                        sel.dispatchEvent(new Event('change'));
                    }
                });
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', init);
            } else {
                init();
            }
        })();
        </script>
        <?php
    });

});


/**
 * On save: if "community-selection" is empty and SubdivisionName
 * matches a Community title OR its ACF alternate title
 * (case-insensitive), fill "community-selection" with the canonical title.
 */
add_action( 'save_post_properties', 'wts_es_autofill_community_selection_from_subdivision', 5, 3 );
function wts_es_autofill_community_selection_from_subdivision( $post_id, $post, $update ) {

    // Only in admin on real saves
    if ( ! is_admin() ) return;
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
    if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) return;

    if ( empty( $_POST['es_property'] ) || ! is_array( $_POST['es_property'] ) ) {
        return;
    }

    // Work with the Estatik payload directly so Estatik saves it
    $es = &$_POST['es_property'];

    $subdivision_raw = isset( $es['subdivisionname'] ) ? $es['subdivisionname'] : '';
    $community_raw   = isset( $es['community-selection'] ) ? $es['community-selection'] : '';

    $subdivision = trim( wp_unslash( $subdivision_raw ) );
    $community   = trim( wp_unslash( $community_raw ) );

    // Don't overwrite a manually selected community
    if ( $community !== '' ) {
        return;
    }

    if ( $subdivision === '' ) {
        return;
    }

    // Normalizer for case-insensitive keys
    $normalize = function( $value ) {
        $value = trim( (string) $value );
        if ( $value === '' ) return '';
        if ( function_exists( 'mb_strtoupper' ) ) {
            return mb_strtoupper( $value, 'UTF-8' );
        }
        return strtoupper( $value );
    };

    // Build map: NORMALIZED label (title or alt) → canonical title
    static $label_to_canonical = null;

    if ( $label_to_canonical === null ) {
        $label_to_canonical = [];

        $q = new WP_Query([
            'post_type'      => 'post_communities',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ]);

        foreach ( (array) $q->posts as $cid ) {
            $title = get_the_title( $cid );
            if ( ! $title ) continue;

            $canonical = $title;

            // Canonical title key
            $canon_key = $normalize( $title );
            if ( $canon_key !== '' ) {
                $label_to_canonical[ $canon_key ] = $canonical;
            }

            // Alternate title key
            $alt = get_post_meta( $cid, 'cf_legalname_alternate_title', true );
            if ( $alt && $alt !== $title ) {
                $alt_key = $normalize( $alt );
                if ( $alt_key !== '' ) {
                    $label_to_canonical[ $alt_key ] = $canonical;
                }
            }
        }
    }

    // Look up subdivision in a case-insensitive way
    $sub_key = $normalize( $subdivision );

    if ( $sub_key !== '' && isset( $label_to_canonical[ $sub_key ] ) ) {
        // e.g. "EBERLY TRAILS" → "Eberly Trails"
        $es['community-selection'] = $label_to_canonical[ $sub_key ];
    }
}