<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Builder Selection — populate Estatik select with Builder CPT titles + alt titles
 * CPT: post_builders
 * Alt title field: cf_legalname_alternate_title
 *
 * Existing MLS-fed text field: builder (in es_property[builder])
 * Our canonical dropdown field: builder-selection
 * Estatik meta for dropdown:  es_property_builder-selection
 */

add_action( 'admin_enqueue_scripts', function() {

    if ( ! function_exists( 'get_current_screen' ) ) return;

    $screen = get_current_screen();
    if ( ! $screen ) return;
    if ( $screen->post_type !== 'properties' ) return;
    if ( ! in_array( $screen->base, array( 'post', 'post-new' ), true ) ) return;

    $choices = array();

    $q = new WP_Query( array(
        'post_type'      => 'post_builders',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'orderby'        => 'title',
        'order'          => 'ASC',
        'fields'         => 'ids',
        'no_found_rows'  => true,
    ) );

    foreach ( (array) $q->posts as $bid ) {
        $canonical = get_the_title( $bid );
        if ( ! $canonical ) {
            continue;
        }

        // Main builder name
        $choices[] = array(
            'value' => $canonical,
            'label' => $canonical,
        );

        // Alternate title (if present)
        $alt = get_post_meta( $bid, 'cf_legalname_alternate_title', true );
        if ( $alt && $alt !== $canonical ) {
            $choices[] = array(
                'value' => $canonical, // still store canonical
                'label' => $alt,       // MLS-style alt label
            );
        }
    }

    wp_register_script( 'es-fill-builder-select', false, array(), null, true );
    wp_enqueue_script( 'es-fill-builder-select' );

    wp_add_inline_script(
        'es-fill-builder-select',
        'window.esBuilderSelectData = ' . wp_json_encode( array(
            'placeholder' => "— Select a builder —",
            'choices'     => $choices,
            'selectors'   => array(
                'select[name="builder-selection"]',
                'select[name$="[builder-selection]"]',
            ),
        ) ) . ';'
    );

    add_action( 'admin_print_footer_scripts', function() {
        ?>
        <script>
        (function(){
            const data = window.esBuilderSelectData;
            if (!data) return;

            function init() {
                const selects = [];

                (data.selectors || []).forEach(sel => {
                    document.querySelectorAll(sel).forEach(el => selects.push(el));
                });

                selects.forEach(sel => {
                    // IMPORTANT: look at data-value first (Estatik stores the saved value there)
                    const current = sel.getAttribute('data-value') || sel.value;

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
 * On save: if "builder-selection" is empty, try to auto-fill it from the
 * MLS-fed "builder" text (or any builder-ish field) using a
 * case-insensitive match against Builder titles + alt titles.
 */
add_action( 'save_post_properties', 'wts_es_autofill_builder_selection_from_meta', 5, 3 );
function wts_es_autofill_builder_selection_from_meta( $post_id, $post, $update ) {

    // Only in admin on normal saves
    if ( ! is_admin() ) return;
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
    if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) return;

    if ( empty( $_POST['es_property'] ) || ! is_array( $_POST['es_property'] ) ) {
        return;
    }

    $es = &$_POST['es_property'];

    $builder_selection = isset( $es['builder-selection'] )
        ? trim( wp_unslash( $es['builder-selection'] ) )
        : '';

    // Don't overwrite if already chosen
    if ( $builder_selection !== '' ) {
        return;
    }

    // Try to find a source builder name from the Estatik payload

    $builder_source = '';

    // 1) Explicit MLS-fed field: "builder"
    if ( isset( $es['builder'] ) && trim( $es['builder'] ) !== '' ) {
        $builder_source = trim( wp_unslash( $es['builder'] ) );

    // 2) Fallback: any other es_property key containing "builder"
    } else {
        foreach ( $es as $key => $val ) {
            if ( stripos( $key, 'builder' ) !== false && is_string( $val ) ) {
                $maybe = trim( wp_unslash( $val ) );
                if ( $maybe !== '' ) {
                    $builder_source = $maybe;
                    break;
                }
            }
        }
    }

    if ( $builder_source === '' ) {
        return;
    }

    // Normalizer
    $normalize = function( $value ) {
        $value = trim( (string) $value );
        if ( $value === '' ) return '';
        if ( function_exists( 'mb_strtoupper' ) ) {
            return mb_strtoupper( $value, 'UTF-8' );
        }
        return strtoupper( $value );
    };

    // Map: NORMALIZED (title or alt) → canonical title
    static $label_to_canonical = null;

    if ( $label_to_canonical === null ) {
        $label_to_canonical = array();

        $q = new WP_Query( array(
            'post_type'      => 'post_builders',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ) );

        foreach ( (array) $q->posts as $bid ) {
            $title = get_the_title( $bid );
            if ( ! $title ) continue;

            $canonical = $title;

            $canon_key = $normalize( $title );
            if ( $canon_key !== '' ) {
                $label_to_canonical[ $canon_key ] = $canonical;
            }

            $alt = get_post_meta( $bid, 'cf_legalname_alternate_title', true );
            if ( $alt && $alt !== $title ) {
                $alt_key = $normalize( $alt );
                if ( $alt_key !== '' ) {
                    $label_to_canonical[ $alt_key ] = $canonical;
                }
            }
        }
    }

    $src_key = $normalize( $builder_source );

    if ( $src_key !== '' && isset( $label_to_canonical[ $src_key ] ) ) {
        $es['builder-selection'] = $label_to_canonical[ $src_key ];
    }
}