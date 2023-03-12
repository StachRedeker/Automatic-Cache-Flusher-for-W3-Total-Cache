<?php
/*
Plugin Name: Automatic Cache Flusher for W3 Total Cache
Plugin URI: https://github.com/StachRedeker/Automatic-Cache-Flusher-for-W3-Total-Cache
Description: This plugin flushes the W3 Total Cache after a plugin update.
Version: 1.0
Author: <a href="https://www.stachredeker.nl/">Stach Redeker</a> & <a href="https://colingerritsen.com/">Colin Gerritsen</a>
License: GPL v3 or later
License URI: https://gnu.org/licenses/gpl-3.0.html
*/

function w3tc_flush_on_plugin_update( $upgrader_object, $options ) {
    if ( function_exists( 'w3tc_flush_all' ) ) {
        w3tc_flush_all();
        echo '<script>console.log("W3 Total Cache flushed.")</script>';
    } else {
        echo '<script>console.log("W3 Total Cache is not installed.")</script>';
    }
}
add_action( 'upgrader_process_complete', 'w3tc_flush_on_plugin_update', 10, 2 );

/*
Automatic Cache Flusher for W3 Total Cache is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
any later version.

Automatic Cache Flusher for W3 Total Cache is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with Automatic Cache Flusher for W3 Total Cache. If not, see https://gnu.org/licenses/gpl-3.0.html.
*/
