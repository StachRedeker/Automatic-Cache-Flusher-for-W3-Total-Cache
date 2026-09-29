<?php
/**
 * Plugin Name:       Automatic Cache Flusher for W3 Total Cache
 * Plugin URI:        https://wordpress.org/plugins/automatic-cache-flusher-for-w3-total-cache/
 * Description:       Purges the W3 Total Cache page cache after plugin, theme and core updates, plugin (de)activation, theme switches and whenever Elementor throws away its generated CSS files. No settings needed.
 * Version:           2.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Stach Redeker & Colin Gerritsen
 * Author URI:        https://www.stachredeker.nl
 * License:           GPL v3 or later
 * License URI:       https://gnu.org/licenses/gpl-3.0.html
 * Text Domain:       automatic-cache-flusher-for-w3-total-cache
 *
 * How it works (short version, see readme.txt for the long one):
 *
 * 1. A handful of WordPress/Elementor hooks only RECORD that something changed (a "reason"). They never do work
 *    themselves, so they can never slow down or break an update or an activation.
 * 2. At `shutdown` (priority 1000) the page cache is purged ONCE for the whole request, no matter how many reasons were
 *    recorded. W3 Total Cache only queues purges and executes them at `shutdown` priority 100000, so our call must come
 *    before that.
 * 3. After W3 Total Cache really purged (priority 100010) two non-blocking requests to the home page let Elementor
 *    rebuild its CSS and W3 Total Cache store a fresh copy (see on_warm()).
 * 4. One follow-up purge runs two minutes later (WP-Cron) to catch a page that a visitor got cached in the meantime.
 *
 * Everything is wrapped in try/catch(\Throwable) and nothing is ever printed.
 *
 * Automatic Cache Flusher for W3 Total Cache is free software: you can redistribute it and/or modify it under the terms
 * of the GNU General Public License as published by the Free Software Foundation, either version 3 of the License, or
 * any later version. It is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the
 * implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for more
 * details: https://gnu.org/licenses/gpl-3.0.html
 *
 * @package AutomaticCacheFlusherForW3TC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'ACFW3TC_VERSION' ) ) {
	define( 'ACFW3TC_VERSION', '2.0.0' );
}

if ( ! class_exists( 'ACFW3TC_Flusher' ) ) {

	/**
	 * Records "the site changed" signals and turns them into one W3 Total Cache page-cache purge per request.
	 */
	final class ACFW3TC_Flusher {

		/** WP-Cron hook of the single follow-up purge. */
		const FOLLOWUP_HOOK = 'acfw3tc_followup_purge';

		/** Seconds between the immediate purge and the follow-up purge. */
		const FOLLOWUP_DELAY = 120;

		/** Our purge call. Must stay below W3TC_DELAYED_PRIORITY, or W3 Total Cache will not execute it in this request. */
		const SHUTDOWN_PRIORITY = 1000;

		/** W3TC\CacheFlush::execute_delayed_operations() runs on `shutdown` at this priority (W3 Total Cache 2.x). */
		const W3TC_DELAYED_PRIORITY = 100000;

		/** The warm-up request: after W3 Total Cache really deleted the cached pages. */
		const WARM_PRIORITY = 100010;

		/** Our listeners run after everybody else's (Elementor clears its files at the default priority 10). */
		const HOOK_PRIORITY = PHP_INT_MAX - 10;

		/** User-agent prefix of our own warm-up request; such a request never records a reason. */
		const WARM_UA = 'ACF-W3TC-Warmup';

		/** Upper bound on the reasons kept per request (they are only passed to the `acfw3tc_purged` action). */
		const MAX_REASONS = 12;

		/** @var string[] Reasons recorded in this request (unique, in order). */
		private static $reasons = array();

		/** @var bool The shutdown purge is registered for this request. */
		private static $armed = false;

		/** @var bool The purge of this request already ran (or was deliberately skipped). */
		private static $done = false;

		/** @var bool The warm-up is registered and has not run yet. */
		private static $warm_pending = false;

		/** @var bool We are inside our own purge call (W3TC's flush-all fires Elementor's clear hook itself). */
		private static $flushing = false;

		/** @var bool W3 Total Cache itself was activated or deactivated in this request: leave it alone. */
		private static $w3tc_toggled = false;

		/** @var bool Hooks are registered. */
		private static $hooked = false;

		/**
		 * The trigger keys a site owner can switch off with the `acfw3tc_triggers` filter.
		 *
		 * @return string[]
		 */
		public static function default_triggers() {
			return array(
				'upgrade',               // Plugin, theme or core update/installation (upgrader_process_complete).
				'translation',           // Translation (language pack) update.
				'core_updated',          // _core_updated_successfully.
				'plugin_activated',      // activated_plugin.
				'plugin_deactivated',    // deactivated_plugin.
				'theme_switched',        // switch_theme.
				'elementor_css_cleared', // elementor/core/files/clear_cache (Elementor deleted uploads/elementor/css/*).
			);
		}

		/**
		 * Register the listeners. Called once when the plugin file loads.
		 */
		public static function boot() {
			if ( self::$hooked ) {
				return;
			}
			self::$hooked = true;

			add_action( 'upgrader_process_complete', array( __CLASS__, 'on_upgrader_process_complete' ), self::HOOK_PRIORITY, 2 );
			add_action( '_core_updated_successfully', array( __CLASS__, 'on_core_updated' ), self::HOOK_PRIORITY, 0 );
			add_action( 'activated_plugin', array( __CLASS__, 'on_activated_plugin' ), self::HOOK_PRIORITY, 1 );
			add_action( 'deactivated_plugin', array( __CLASS__, 'on_deactivated_plugin' ), self::HOOK_PRIORITY, 1 );
			add_action( 'switch_theme', array( __CLASS__, 'on_switch_theme' ), self::HOOK_PRIORITY, 0 );
			add_action( 'elementor/core/files/clear_cache', array( __CLASS__, 'on_elementor_clear_cache' ), self::HOOK_PRIORITY, 0 );
			add_action( self::FOLLOWUP_HOOK, array( __CLASS__, 'on_followup' ), 10, 0 );
		}

		/* ---------------------------------------------------------------- listeners: record only */

		/**
		 * `upgrader_process_complete`. WordPress also fires it for bulk-update forms submitted with nothing selected;
		 * those changed nothing and are ignored. Translation updates are recorded under their own key.
		 *
		 * @param mixed $upgrader   WP_Upgrader instance (unused).
		 * @param mixed $hook_extra The upgrade payload: action, type, plugins/themes/plugin/theme, translations.
		 */
		public static function on_upgrader_process_complete( $upgrader = null, $hook_extra = array() ) {
			try {
				$kind = self::classify_upgrade( $hook_extra );
				if ( '' !== $kind ) {
					self::record( $kind );
				}
			} catch ( \Throwable $e ) {
				// Never break an upgrade.
			}
		}

		/**
		 * Classify an `upgrader_process_complete` payload.
		 *
		 * @param mixed $hook_extra Hook payload.
		 * @return string 'upgrade', 'translation' or '' (nothing changed).
		 */
		public static function classify_upgrade( $hook_extra ) {
			if ( empty( $hook_extra ) || ! is_array( $hook_extra ) ) {
				return '';
			}
			$type   = isset( $hook_extra['type'] ) ? (string) $hook_extra['type'] : '';
			$action = isset( $hook_extra['action'] ) ? (string) $hook_extra['action'] : '';

			if ( 'translation' === $type || ! empty( $hook_extra['translations'] ) ) {
				return 'translation';
			}
			if ( 'update' === $action && 'core' !== $type ) {
				$has_items = ! empty( $hook_extra['plugins'] ) || ! empty( $hook_extra['themes'] )
					|| ! empty( $hook_extra['plugin'] ) || ! empty( $hook_extra['theme'] );
				if ( ! $has_items ) {
					return '';
				}
			}
			return 'upgrade';
		}

		/** `_core_updated_successfully`. */
		public static function on_core_updated() {
			self::record( 'core_updated' );
		}

		/**
		 * `activated_plugin`.
		 *
		 * @param mixed $plugin Plugin basename.
		 */
		public static function on_activated_plugin( $plugin = '' ) {
			self::note_w3tc_toggle( $plugin );
			self::record( 'plugin_activated' );
		}

		/**
		 * `deactivated_plugin`.
		 *
		 * @param mixed $plugin Plugin basename.
		 */
		public static function on_deactivated_plugin( $plugin = '' ) {
			self::note_w3tc_toggle( $plugin );
			self::record( 'plugin_deactivated' );
		}

		/** `switch_theme`. */
		public static function on_switch_theme() {
			self::record( 'theme_switched' );
		}

		/** `elementor/core/files/clear_cache`: Elementor just deleted every file in uploads/elementor/css/. */
		public static function on_elementor_clear_cache() {
			self::record( 'elementor_css_cleared' );
		}

		/**
		 * Record a reason and make sure one purge follows at shutdown. No I/O. Never throws.
		 *
		 * @param string $reason Trigger key.
		 */
		public static function record( $reason ) {
			try {
				if ( self::$flushing || self::is_warm_request() ) {
					return;
				}
				if ( ! in_array( $reason, self::enabled_triggers(), true ) ) {
					return;
				}
				if ( ! self::w3tc_available() ) {
					return;
				}
				if ( ! in_array( $reason, self::$reasons, true ) && count( self::$reasons ) < self::MAX_REASONS ) {
					self::$reasons[] = $reason;
				}
				if ( self::$done ) {
					// Something changed again after this request's purge already ran: the follow-up covers it.
					self::schedule_followup();
					return;
				}
				if ( ! self::$armed ) {
					self::$armed = true;
					add_action( 'shutdown', array( __CLASS__, 'on_shutdown' ), self::SHUTDOWN_PRIORITY, 0 );
				}
			} catch ( \Throwable $e ) {
				// Never break an activation, an upgrade or a page request.
			}
		}

		/* ---------------------------------------------------------------- work */

		/**
		 * `shutdown` at SHUTDOWN_PRIORITY: one purge, then the warm-up (after W3 Total Cache ran its queue) and the
		 * follow-up. Never throws, never prints.
		 */
		public static function on_shutdown() {
			try {
				if ( self::$done || ! self::$armed ) {
					return;
				}
				self::$done = true;
				if ( ! self::w3tc_available() ) {
					return;
				}
				self::purge( self::$reasons, 'immediate' );
				self::schedule_followup();
			} catch ( \Throwable $e ) {
				self::$done = true;
			}
		}

		/**
		 * WP-Cron follow-up: one more purge and warm-up. Never schedules another follow-up by itself.
		 */
		public static function on_followup() {
			try {
				if ( ! self::w3tc_available() ) {
					return;
				}
				self::$done = true; // A change later in this cron request leads to at most one new follow-up.
				self::purge( array( 'followup' ), 'followup' );
			} catch ( \Throwable $e ) {
				self::$done = true;
			}
		}

		/**
		 * Queue the W3 Total Cache purge and register the warm-up.
		 *
		 * @param string[] $reasons Why.
		 * @param string   $phase   'immediate' or 'followup'.
		 */
		private static function purge( array $reasons, $phase ) {
			$scope = 'all' === apply_filters( 'acfw3tc_flush_scope', 'page', $reasons ) ? 'all' : 'page';

			self::$flushing = true;
			try {
				$method = self::call_w3tc( $scope );
			} finally {
				self::$flushing = false;
			}
			if ( '' === $method ) {
				return;
			}

			if ( (bool) apply_filters( 'acfw3tc_enable_warmup', true, $reasons ) && self::page_cache_enabled() ) {
				self::register_warm();
			}

			/**
			 * Fires after this plugin asked W3 Total Cache to purge. W3 Total Cache executes the purge at the end of the
			 * request (`shutdown`, priority 100000).
			 *
			 * @param string[] $reasons Trigger keys recorded in this request, or array( 'followup' ).
			 * @param string   $scope   'page' or 'all'.
			 * @param string   $method  The W3 Total Cache function that was called.
			 * @param string   $phase   'immediate' or 'followup'.
			 */
			do_action( 'acfw3tc_purged', array_values( $reasons ), $scope, $method, $phase );
		}

		/**
		 * Call the best available W3 Total Cache function. Page scope: w3tc_flush_posts → w3tc_pgcache_flush (older
		 * versions) → w3tc_flush_all. All scope: w3tc_flush_all, falling back to the page functions when it fails
		 * (it can throw when Elementor was activated in this very request, because W3 Total Cache then calls into an
		 * Elementor that has not finished loading).
		 *
		 * @param string $scope 'page' or 'all'.
		 * @return string The function that was called, '' if none was available.
		 */
		private static function call_w3tc( $scope ) {
			if ( 'all' === $scope && function_exists( 'w3tc_flush_all' ) ) {
				try {
					w3tc_flush_all();
					return 'w3tc_flush_all';
				} catch ( \Throwable $e ) {
					$scope = 'page';
				}
			}
			if ( function_exists( 'w3tc_flush_posts' ) ) {
				w3tc_flush_posts();
				return 'w3tc_flush_posts';
			}
			if ( function_exists( 'w3tc_pgcache_flush' ) ) {
				w3tc_pgcache_flush();
				return 'w3tc_pgcache_flush';
			}
			if ( function_exists( 'w3tc_flush_all' ) ) {
				w3tc_flush_all();
				return 'w3tc_flush_all';
			}
			return '';
		}

		/** Register the warm-up once per request. */
		private static function register_warm() {
			if ( self::$warm_pending ) {
				return;
			}
			self::$warm_pending = true;
			add_action( 'shutdown', array( __CLASS__, 'on_warm' ), self::WARM_PRIORITY, 0 );
		}

		/**
		 * `shutdown` at WARM_PRIORITY: two non-blocking GETs of the home page.
		 *
		 * 1. `/` — W3 Total Cache stores a fresh copy, and Elementor rebuilds its CSS files during that render.
		 * 2. `/?acfw3tc-warmup=1` — W3 Total Cache never serves a query-string URL from its cache (by default), so this one
		 *    always reaches PHP and Elementor always rebuilds the CSS. Needed because W3 Total Cache keeps serving the
		 *    previous copy of a page for up to 30 s after a purge when that copy was less than 30 s old (its `*_old`
		 *    files; its Elementor exception does not work in advanced-cache.php, where Elementor is not loaded yet). The
		 *    rebuilt CSS files keep their names, so even that stale copy then loads its styles.
		 */
		public static function on_warm() {
			try {
				if ( ! self::$warm_pending ) {
					return;
				}
				self::$warm_pending = false;
				$args = array(
					'timeout'     => 1,
					'blocking'    => false,
					'redirection' => 0,
					'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', false ),
					'user-agent'  => self::WARM_UA . '/' . ACFW3TC_VERSION,
				);
				$home = home_url( '/' );
				wp_remote_get( $home, $args );
				wp_remote_get( $home . ( false === strpos( $home, '?' ) ? '?' : '&' ) . 'acfw3tc-warmup=1', $args );
			} catch ( \Throwable $e ) {
				self::$warm_pending = false;
			}
		}

		/**
		 * Keep exactly one follow-up event, due FOLLOWUP_DELAY seconds after the latest purge.
		 */
		private static function schedule_followup() {
			$next = wp_next_scheduled( self::FOLLOWUP_HOOK );
			if ( $next ) {
				wp_unschedule_event( $next, self::FOLLOWUP_HOOK );
			}
			wp_schedule_single_event( time() + self::FOLLOWUP_DELAY, self::FOLLOWUP_HOOK );
		}

		/* ---------------------------------------------------------------- conditions */

		/**
		 * A W3 Total Cache purge function exists and W3 Total Cache was not switched on/off in this request.
		 *
		 * @return bool
		 */
		public static function w3tc_available() {
			if ( self::$w3tc_toggled ) {
				return false;
			}
			return function_exists( 'w3tc_flush_posts' ) || function_exists( 'w3tc_pgcache_flush' ) || function_exists( 'w3tc_flush_all' );
		}

		/**
		 * Best effort: is W3 Total Cache's page cache switched on? Unknown → true.
		 *
		 * @return bool
		 */
		private static function page_cache_enabled() {
			try {
				if ( class_exists( '\W3TC\Dispatcher' ) && method_exists( '\W3TC\Dispatcher', 'config' ) ) {
					$config = \W3TC\Dispatcher::config();
					if ( is_object( $config ) && method_exists( $config, 'get_boolean' ) ) {
						return (bool) $config->get_boolean( 'pgcache.enabled' );
					}
				}
			} catch ( \Throwable $e ) {
				return true;
			}
			return true;
		}

		/**
		 * W3 Total Cache's own plugin file was (de)activated in this request.
		 *
		 * @param mixed $plugin Plugin basename.
		 */
		private static function note_w3tc_toggle( $plugin ) {
			if ( is_string( $plugin ) && 'w3-total-cache.php' === basename( $plugin ) ) {
				self::$w3tc_toggled = true;
			}
		}

		/**
		 * Trigger keys enabled via the `acfw3tc_triggers` filter.
		 *
		 * @return string[]
		 */
		private static function enabled_triggers() {
			$triggers = apply_filters( 'acfw3tc_triggers', self::default_triggers() );
			return is_array( $triggers ) ? array_values( array_map( 'strval', $triggers ) ) : array();
		}

		/**
		 * The current request is our own warm-up.
		 *
		 * @return bool
		 */
		public static function is_warm_request() {
			$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			return 0 === strncmp( $ua, self::WARM_UA, strlen( self::WARM_UA ) );
		}

		/**
		 * Test seam: forget the state of this request.
		 *
		 * @internal
		 */
		public static function reset() {
			self::$reasons      = array();
			self::$armed        = false;
			self::$done         = false;
			self::$warm_pending = false;
			self::$flushing     = false;
			self::$w3tc_toggled = false;
		}

		/**
		 * Test seam: reasons recorded in this request.
		 *
		 * @internal
		 * @return string[]
		 */
		public static function reasons() {
			return self::$reasons;
		}
	}

	ACFW3TC_Flusher::boot();
}
