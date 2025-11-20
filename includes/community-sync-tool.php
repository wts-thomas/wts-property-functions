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
            Click below to process <strong>10 Properties at a time</strong>.
            This fills the <code>community-selection</code> field when the
            subdivision matches any Community Title or Alternate Title
            (case-insensitive). Each property is only processed once.
        </p>

        <form method="post">
            <?php wp_nonce_field( 'wts_run_sync', 'wts_sync_nonce' ); ?>
            <p>
                <input type="submit" class="button button-primary"
                       value="Process Next 10 Properties">
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

    /**
     * Build (or reuse) map: NORMALIZED label (title or alt) → canonical Community title
     * Cached in a transient so we don't hit the DB for Communities every click.
     */
    $label_map = get_transient( 'wts_community_label_map' );
    if ( ! is_array( $label_map ) ) {
        $label_map = array();

        $communities = get_posts( array(
            'post_type'      => 'post_communities',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ) );

        foreach ( (array) $communities as $cid ) {
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

        // Cache for 1 hour (adjust as needed)
        set_transient( 'wts_community_label_map', $label_map, HOUR_IN_SECONDS );
    }

    /**
     * Find a very small batch of Properties that:
     *  - have NOT yet been processed by this tool (no wts_community_sync_done meta)
     * We no longer filter by community-selection in the query itself to keep
     * the SQL simple; we handle that in PHP per-property.
     */
    $q = new WP_Query( array(
        'post_type'              => 'properties',
        'post_status'            => 'publish',
        'posts_per_page'         => 10,
        'fields'                 => 'ids',          // only need IDs
        'no_found_rows'          => true,          // don't calc total rows
        'update_post_meta_cache' => false,         // don't pre-cache meta
        'update_post_term_cache' => false,         // no terms needed
        'meta_query'             => array(
            array(
                'key'     => 'wts_community_sync_done',
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

    foreach ( $q->posts as $post_id ) {

        $title = get_the_title( $post_id );

        // If this property already has a community-selection set, just mark done and skip.
        $current_selection = get_post_meta( $post_id, 'es_property_community-selection', true );
        if ( $current_selection !== '' && $current_selection !== '__none__' ) {
            echo '<li><strong>' . esc_html( $title ) . '</strong>: already has community-selection (<code>'
                 . esc_html( $current_selection ) . '</code>). Skipped.</li>';

            update_post_meta( $post_id, 'wts_community_sync_done', 1 );
            continue;
        }

        /**
         * Step 1: Try the known subdivision keys only.
         * This avoids an expensive get_post_meta() over *all* keys.
         */
        $subdivision = '';

        $candidate_keys = array(
            'es_property_subdivisionname', // Estatik-style meta
            'subdivisionname',             // plain key
            'SubdivisionName',             // possible MLS import variation
        );

        foreach ( $candidate_keys as $key ) {
            $val = trim( get_post_meta( $post_id, $key, true ) );
            if ( $val !== '' ) {
                $subdivision = $val;
                break;
            }
        }

        // If still empty, we give up (no more "scan everything" fallback).
        if ( $subdivision === '' ) {
            echo '<li><strong>' . esc_html( $title ) . '</strong>: no subdivision assigned. Skipped.</li>';
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