# Automatic Cache Flusher for W3 Total Cache
> This plugin flushes the W3 Total Cache after a plugin update.

## Description

W3 Total Cache recommends clearing your cache after a (plugin) update to prevent cache conflicts. Currently, there is no setting that does this automatically for you. W3 Total Cache shows a banner with a 'clear cache' button after an update, but you need to click it yourself. What a waste of precious mouse clicks. Also, the banner 'solution' is not practical if you use 3rd party software to update your plugins, such as Installatron or ManageWP.

### When you need this plugin

You need this plugin if you are looking for a simple, no-code solution for preventing cache conflicts with W3 Total Cache after a plugin update.

### When you do not need this plugin

If you are familiar with child themes, then you can add some code to your child theme to achieve the same functionality:

```php
function w3tc_flush_on_plugin_update( $upgrader_object, $options ) {
    if ( function_exists( 'w3tc_flush_all' ) ) {
        w3tc_flush_all();
        echo '<script>console.log("W3 Total Cache flushed.")</script>';
    } else {
        echo '<script>console.log("W3 Total Cache is not installed.")</script>';
    }
}
add_action( 'upgrader_process_complete', 'w3tc_flush_on_plugin_update', 10, 2 );
```

### Privacy notices

This plugin does not:

- track users;
- write any user personal data to the database;
- send any data to external servers;
- use cookies.

## Installation

After installing and activating, the plugin works automatically.

## Support

Issues with 'Automatic Cache Flusher for W3 Total Cache' fall within the scope of the support, issues with W3 Total Cache do not. Please use the appropriate forums, [issues on Github](https://github.com/StachRedeker/Automatic-Cache-Flusher-for-W3-Total-Cache/issues), or send an [email](mailto:info@stachredeker.nl).

## Licence

This project is licensed under the GPL-3.0 license - see the [LICENSE](LICENSE) file for details.


## Changelog

v1.0. Stable version of the plugin.
