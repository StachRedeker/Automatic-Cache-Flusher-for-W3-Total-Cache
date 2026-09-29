=== Automatic Cache Flusher for W3 Total Cache ===
Contributors: stachredeker
Tags: w3 total cache, cache, elementor, flush cache, page cache
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.0.0
License: GPLv3 or later
License URI: https://gnu.org/licenses/gpl-3.0.html
Donate link: https://ko-fi.com/stachredeker

Purges the W3 Total Cache page cache after updates, plugin (de)activation and theme switches, and fixes unstyled Elementor pages.

== Description ==

W3 Total Cache recommends clearing your cache after a (plugin) update to prevent cache conflicts, but it does not do this for you. It shows a banner with a 'clear cache' button, and you have to click it yourself. That does not help when your updates run automatically, or from a tool like ManageWP, MainWP or Installatron.

This plugin purges the W3 Total Cache page cache for you. No settings, no buttons.

= When does it purge? =

* After a plugin, theme or WordPress update (and after installing a plugin or theme over an existing one).
* After a translation update.
* When a plugin is activated or deactivated.
* When you switch themes.
* Whenever Elementor throws away its generated CSS files (see below).

However many of these happen at once, the cache is purged only once per page load.

= Why this matters for Elementor sites =

Elementor stores the styling of your pages in separate CSS files (in `wp-content/uploads/elementor/css/`). Since Elementor 3.33 it deletes all of those files whenever a plugin is activated, deactivated or updated, when you switch themes, and when you use Elementor → Tools → Clear Files & Data (and after some of its own updates and settings changes). Elementor builds the files again the next time a page is generated.

W3 Total Cache, however, keeps serving the saved copies of your pages. Those copies still point at the CSS files that were just deleted, so visitors get pages without styling until someone clears the cache by hand.

This plugin notices when Elementor deletes its CSS files and purges the page cache right after. Then it visits your home page once in the background, so Elementor rebuilds its CSS and W3 Total Cache stores a fresh copy. Two minutes later it purges once more, to catch a page that a visitor happened to get cached in the meantime.

You do not need Elementor to use this plugin. Without Elementor it simply purges after updates, (de)activations and theme switches.

= Only the page cache =

By default the plugin purges only the page cache (W3 Total Cache's "Purge All Caches" is not used). That is all that is needed to fix outdated pages. "Purge All Caches" also empties the object, database and minify caches, and it makes Elementor delete its CSS files once more. If you want it anyway, see the FAQ.

= For developers =

* `acfw3tc_flush_scope` (filter): `'page'` (default) or `'all'` (W3 Total Cache's "Purge All Caches").
* `acfw3tc_enable_warmup` (filter): return `false` to skip the background visit to your home page.
* `acfw3tc_triggers` (filter): the list of triggers that lead to a purge. Default: `upgrade`, `translation`, `core_updated`, `plugin_activated`, `plugin_deactivated`, `theme_switched`, `elementor_css_cleared`.
* `acfw3tc_purged` (action): fires after the plugin asked W3 Total Cache to purge, with the reasons, scope, W3 Total Cache function and phase (`immediate` or `followup`).

Example, to also empty all other W3 Total Cache caches:

`add_filter( 'acfw3tc_flush_scope', function () { return 'all'; } );`

= Privacy notices =

This plugin does not:

* track users;
* write any user personal data to the database;
* send any data to external servers (the background visit goes to your own home page);
* use cookies.

== Installation ==

1. Install and activate W3 Total Cache.
2. Install and activate this plugin.

That's it. If W3 Total Cache is not active, this plugin does nothing.

== Frequently Asked Questions ==

= Does it need Elementor? =

No. Without Elementor it purges the page cache after updates, plugin (de)activation and theme switches. With Elementor it also purges whenever Elementor deletes its generated CSS files.

= Does it work on multisite? =

Yes. It purges the cache of the site on which something changed. W3 Total Cache's functions work per site.

= Why a second purge after two minutes? =

While your site is changing, a visitor may be served a page that was generated at just the wrong moment, and W3 Total Cache stores that page. The second purge removes it. This uses WP-Cron, which runs when someone visits a page that is not served from the cache (W3 Total Cache's cached pages do not start WP-Cron). On a quiet site it may therefore run a bit later than two minutes. If you run WP-Cron from a real server cron job, it runs on time.

= What is the background visit to my home page? =

Right after the purge, the plugin loads your home page twice in the background (once normally, once with `?acfw3tc-warmup=1`). This makes Elementor rebuild its CSS files straight away and lets W3 Total Cache store a fresh copy. You will see these visits in your server log with the user agent `ACF-W3TC-Warmup`. The requests do not wait for an answer. You can switch them off with the `acfw3tc_enable_warmup` filter.

= Does it purge after translation updates? =

Yes. New translations change text on your pages, so a purge is useful, and version 1 of this plugin did the same. Only the page cache is purged, which is cheap. If you do not want this, remove `translation` with the `acfw3tc_triggers` filter. (Elementor itself deletes its CSS on translation updates too, unless its "Optimized CSS files" feature is on; that is always caught.)

= Does it purge when I save a page in Elementor? =

No, W3 Total Cache already does that itself for the page you saved.

== Support ==

Issues with 'Automatic Cache Flusher for W3 Total Cache' fall within the scope of the support, issues with W3 Total Cache do not. Please use the appropriate forums, [issues on Github](https://github.com/StachRedeker/Automatic-Cache-Flusher-for-W3-Total-Cache/issues), or send an [email](mailto:info@stachredeker.nl).

== Changelog ==

= 2.0.0 =
* New: purges when Elementor deletes its generated CSS files (Elementor 3.33 and later do this on every plugin activation, deactivation, update and theme switch), so visitors no longer get unstyled pages.
* New: also purges after plugin activation and deactivation, theme switches and WordPress core updates.
* New: only one purge per page load, no matter how many things changed.
* New: background visit to the home page after the purge, so Elementor rebuilds its CSS and W3 Total Cache stores a fresh copy.
* New: one follow-up purge two minutes later (WP-Cron).
* Changed: purges only the page cache by default instead of all W3 Total Cache caches. Use the `acfw3tc_flush_scope` filter to get the old behaviour.
* Changed: no longer purges after a bulk update in which nothing was selected.
* Changed: no longer prints a `console.log` script into the update screen.
* New: filters `acfw3tc_flush_scope`, `acfw3tc_enable_warmup`, `acfw3tc_triggers`, action `acfw3tc_purged`.
* Requires WordPress 5.8 and PHP 7.4 or later.

= 1.0.1 =
* Minor wording changes.

= 1.0.0 =
* Stable version of the plugin.

== Upgrade Notice ==

= 2.0.0 =
Fixes unstyled Elementor pages after plugin (de)activation and updates. Now purges only the page cache by default. Requires PHP 7.4 and WordPress 5.8.
