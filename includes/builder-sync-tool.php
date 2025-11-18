<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Builder Sync Tool
 *
 * Tools → Builder Sync Tool
 * Processes 10 properties per run and fills es_property_builder-selection
 * when a builder text value matches any Builder Title or Alt Title
 * (case-insensitive). Each property is only processed once.
 */

add_action( 'admin_menu', function() {
    add_submenu_page(
        'tools.php',
        'Builder Sync Tool',
        'Builder Sync Tool',
        'manage_options',
        'builder-sync-tool',
        'wts_render_builder_sync_tool'
    );
});

/**
 * Render tool page
 */
function wts_render_builder_sync_tool() {
    ?>
    <div class="wrap">
        <h1>Builder Sync Tool</h1>

        <p>
            Click below to process <strong>10 Properties at a time</strong>.
            This fills the <code>builder-selection</code> field when the MLS-fed
            <code>builder</code> value (or any builder-related text) matches a
            Builder Title or Alternate Title (case-insensitive). Each property is
            only processed once.
        </p>

        <form method="post">
            <?php wp_nonce_field( 'wts_run_builder_sync', 'wts_builder_sync_nonce' ); ?>
            <p>
                <input type="submit" class="button button-primary"
                       value="Process Next 10 Properties">
            </p>
        </form>

        <?php
        if ( isset( $_POST['wts_builder_sync_nonce'] ) &&
             wp_verify_nonce( $_POST['wts_builder_sync_nonce'], 'wts_run_builder_sync' ) ) {
            wts_run_builder_sync_batch();
        }
        ?>
    </div>
    <?php
}

/**
 * Batch processor: Updates 10 properties per run
 */
function wts_run_builder_sync_batch() {

    // Normalizer for case-insensitive keys
    $normalize = function( $value ) {
        $value = trim( (string) $value );
        if ( $value === '' ) return '';
        if ( function_exists( 'mb_strtoupper' ) ) {
            return mb_strtoupper( $value, 'UTF-8' );
        }
        return strtoupper( $value );
    };

    // Build map: NORMALIZED label (title or alt) → canonical Builder title
    $label_map = array();

    $builders = new WP_Query( array(
        'post_type'      => 'post_builders',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
    ) );

    foreach ( (array) $builders->posts as $bid ) {
        $title = get_the_title( $bid );
        if ( ! $title ) continue;

        $canonical = $title;

        $canon_key = $normalize( $title );
        if ( $canon_key !== '' ) {
            $label_map[ $canon_key ] = $canonical;
        }

        $alt = get_post_meta( $bid, 'cf_legalname_alternate_title', true );
        if ( $alt && $alt !== $title ) {
            $alt_key = $normalize( $alt );
            if ( $alt_key !== '' ) {
                $label_map[ $alt_key ] = $canonical;
            }
        }
    }

    // Find up to 10 properties that:
    //  - have builder-selection unset/empty/__none__
    //  - and have NOT yet been processed by this tool
    $q = new WP_Query( array(
        'post_type'      => 'properties',
        'post_status'    => 'publish',
        'posts_per_page' => 10,
        'meta_query'     => array(
            'relation' => 'AND',
            array(
                'relation' => 'OR',
                array(
                    'key'     => 'es_property_builder-selection',
                    'value'   => '',
                    'compare' => '='
                ),
                array(
                    'key'     => 'es_property_builder-selection',
                    'value'   => '__none__',
                    'compare' => '='
                ),
                array(
                    'key'     => 'es_property_builder-selection',
                    'compare' => 'NOT EXISTS'
                ),
            ),
            array(
                'key'     => 'wts_builder_sync_done',
                'compare' => 'NOT EXISTS',
            ),
        ),
    ) );

    if ( ! $q->have_posts() ) {
        echo '<div class="notice notice-success"><p>All properties have been processed!</p></div>';
        return;
    }

    echo '<div class="notice notice-info"><p>Click again to process another batch.</p></div>';
    echo '<h2>Batch Results</h2><ul>';

    foreach ( $q->posts as $post ) {
        $post_id = $post->ID;
        $title   = $post->post_title;

        // Attempt to discover a builder name from meta
        $builder = '';

        // 1) Primary known key: MLS-fed "builder"
        $candidates = array(
            'builder',              // most important
            'Builder',
            'buildername',
            'BuilderName',
        );

        foreach ( $candidates as $key ) {
            $val = trim( get_post_meta( $post_id, $key, true ) );
            if ( $val !== '' ) {
                $builder = $val;
                break;
            }
        }

        // 2) If not found, scan any meta key containing "builder"
        if ( $builder === '' ) {
            $all_meta = get_post_meta( $post_id );
            foreach ( $all_meta as $meta_key => $values ) {
                if ( stripos( $meta_key, 'builder' ) === false ) {
                    continue;
                }

                // Skip the selection fields themselves
                if ( $meta_key === 'es_property_builder-selection' || $meta_key === 'builder-selection' ) {
                    continue;
                }

                $maybe = is_array( $values )
                    ? trim( (string) end( $values ) )
                    : trim( (string) $values );

                if ( $maybe !== '' ) {
                    $builder = $maybe;
                    break;
                }
            }
        }

        if ( $builder === '' ) {
            echo '<li><strong>' . esc_html( $title ) . '</strong>: no builder found. Skipped.</li>';
            update_post_meta( $post_id, 'wts_builder_sync_done', 1 );
            continue;
        }

        $b_key = $normalize( $builder );

        if ( $b_key !== '' && isset( $label_map[ $b_key ] ) ) {
            $canonical = $label_map[ $b_key ];

            // Write to Estatik’s real meta key
            update_post_meta( $post_id, 'es_property_builder-selection', $canonical );

            // Optional backup meta for custom use
            update_post_meta( $post_id, 'builder-selection', $canonical );

            echo '<li><strong>' . esc_html( $title ) . '</strong>: matched <code>'
                 . esc_html( $builder ) . '</code> → <code>'
                 . esc_html( $canonical ) . '</code>.</li>';
        } else {
            echo '<li><strong>' . esc_html( $title ) . '</strong>: builder <code>'
                 . esc_html( $builder ) . '</code> not found in Builders. Skipped.</li>';
        }

        // Mark as processed (matched or skipped)
        update_post_meta( $post_id, 'wts_builder_sync_done', 1 );
    }

    echo '</ul>';
}