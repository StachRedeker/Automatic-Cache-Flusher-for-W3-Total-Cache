<?php
/**
 * Uninstall: remove the pending follow-up purge event (on every site of a network).
 * The plugin stores no options, so there is nothing else to clean up.
 *
 * @package AutomaticCacheFlusherForW3TC
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'get_sites' ) ) {
	$acfw3tc_offset = 0;
	do {
		$acfw3tc_ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => 100,
				'offset' => $acfw3tc_offset,
			)
		);
		foreach ( (array) $acfw3tc_ids as $acfw3tc_id ) {
			switch_to_blog( (int) $acfw3tc_id );
			wp_clear_scheduled_hook( 'acfw3tc_followup_purge' );
			restore_current_blog();
		}
		$acfw3tc_offset += 100;
	} while ( is_array( $acfw3tc_ids ) && 100 === count( $acfw3tc_ids ) );
} else {
	wp_clear_scheduled_hook( 'acfw3tc_followup_purge' );
}
