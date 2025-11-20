<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Add submenu under Tools
 */
add_action( 'admin_menu', function() {
    add_submenu_page(
        'tools.php',                  // Parent: Tools
        'Community Sync Tool',        // Page title
        'Community Sync Tool',        // Menu title
        'manage_options',             // Capability
        'community-sync-tool',        // Menu slug
        'wts_render_community_sync_tool' // Callback
    );
});

/**
 * Render tool page
 */
function wts_render_community_sync_tool() {
    ?>
    <div class="wrap">
        <h1>Community Sync Tool</h1>

        <p>
            Click below to process <strong>2 Properties at a time</strong>.
            This fills the <code>community-selection</code> field when the
            subdivision matches any Community Title or Alternate Title
            (case-insensitive). Each property is only processed once.
        </p>

        <form method="post">
            <?php wp_nonce_field( 'wts_run_sync', 'wts_sync_nonce' ); ?>
            <p>
                <input type="submit" class="button button-primary"
                       value="Process Next 2 Properties">
            </p>
        </form>

        <?php
        if ( isset( $_POST['wts_sync_nonce'] ) &&
             wp_verify_nonce( $_POST['wts_sync_nonce'], 'wts_run_sync' ) ) {
            wts_run_community_sync_batch();
        }
        ?>
    </div>
    <?php
}

/**
 * Batch processor: Updates properties per run
 */
function wts_run_community_sync_batch() {

    // Normalizer for case-insensitive keys
    $normalize = function( $value ) {
        $value = trim( (string) $value );
        if ( $value === '' ) return '';
        if ( function_exists( 'mb_strtoupper' ) ) {
            return mb_strtoupper( $value, 'UTF-8' );
        }
        return strtoupper( $value );
    };

    // Build map: NORMALIZED label (title or alt) → canonical Community title
    $label_map = [];

    $communities = new WP_Query([
        'post_type'      => 'post_communities',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
    ]);

    foreach ( (array) $communities->posts as $cid ) {
        $title = get_the_title( $cid );
        if ( ! $title ) continue;

        $canonical = $title;

        // Canonical title key
        $canon_key = $normalize( $title );
        if ( $canon_key !== '' ) {
            $label_map[ $canon_key ] = $canonical;
        }

        // Alternate title key
        $alt = get_post_meta( $cid, 'cf_legalname_alternate_title', true );
        if ( $alt && $alt !== $title ) {
            $alt_key = $normalize( $alt );
            if ( $alt_key !== '' ) {
                $label_map[ $alt_key ] = $canonical;
            }
        }
    }

    // Find properties where:
    //  - es_property_community-selection is empty/__none__/missing
    //  - AND we have NOT already processed them (no wts_community_sync_done flag)
    $q = new WP_Query([
        'post_type'      => 'properties',
        'post_status'    => 'publish',
        'posts_per_page' => 2,
        'meta_query'     => [
            'relation' => 'AND',
            // Community-selection is unset/empty
            [
                'relation' => 'OR',
                [
                    'key'     => 'es_property_community-selection',
                    'value'   => '',
                    'compare' => '='
                ],
                [
                    'key'     => 'es_property_community-selection',
                    'value'   => '__none__',
                    'compare' => '='
                ],
                [
                    'key'     => 'es_property_community-selection',
                    'compare' => 'NOT EXISTS'
                ],
            ],
            // Not yet processed by the sync tool
            [
                'key'     => 'wts_community_sync_done',
                'compare' => 'NOT EXISTS',
            ],
        ],
    ]);

    if ( ! $q->have_posts() ) {
        echo '<div class="notice notice-success"><p>All properties have been processed!</p></div>';
        return;
    }

    echo '<div class="notice notice-info"><p>Click again to process another batch.</p></div>';
    echo '<h2>Batch Results</h2><ul>';

    foreach ( $q->posts as $post ) {
        $post_id = $post->ID;
        $title   = $post->post_title;

        // Step 1: Try the known subdivision keys first
        $subdivision = '';

        $candidates = [ 'subdivisionname', 'SubdivisionName' ];

        foreach ( $candidates as $key ) {
            $val = trim( get_post_meta( $post_id, $key, true ) );
            if ( $val !== '' ) {
                $subdivision = $val;
                break;
            }
        }

        // Step 2: If still empty, scan all meta keys that contain "subdivision"
        if ( $subdivision === '' ) {
            $all_meta = get_post_meta( $post_id );
            foreach ( $all_meta as $meta_key => $values ) {
                if ( stripos( $meta_key, 'subdivision' ) === false ) {
                    continue;
                }

                $maybe = is_array( $values )
                    ? trim( (string) end( $values ) )
                    : trim( (string) $values );

                if ( $maybe !== '' ) {
                    $subdivision = $maybe;
                    break;
                }
            }
        }

        if ( $subdivision === '' ) {
            echo '<li><strong>' . esc_html( $title ) . '</strong>: no subdivision assigned. Skipped.</li>';
            // Mark as processed so we don’t keep hitting it
            update_post_meta( $post_id, 'wts_community_sync_done', 1 );
            continue;
        }

        $sub_key = $normalize( $subdivision );

        if ( $sub_key !== '' && isset( $label_map[ $sub_key ] ) ) {
            $canonical = $label_map[ $sub_key ];

            // Write to Estatik’s real meta key
            update_post_meta( $post_id, 'es_property_community-selection', $canonical );

            // Optional backup meta for custom code
            update_post_meta( $post_id, 'community-selection', $canonical );

            echo '<li><strong>' . esc_html( $title ) . '</strong>: matched <code>'
                 . esc_html( $subdivision ) . '</code> → <code>'
                 . esc_html( $canonical ) . '</code>.</li>';
        } else {
            echo '<li><strong>' . esc_html( $title ) . '</strong>: subdivision <code>'
                 . esc_html( $subdivision ) . '</code> not found in Communities. Skipped.</li>';
        }

        // In all cases (matched or skipped), mark this property as processed
        update_post_meta( $post_id, 'wts_community_sync_done', 1 );
    }

    echo '</ul>';
}