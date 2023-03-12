<?php
/*
Plugin Name: Automatic Cache Flusher for W3 Total Cache
Plugin URI: https://github.com/StachRedeker/Automatic-Cache-Flusher-for-W3-Total-Cache
Description: This plugin flushes the W3 Total Cache after a plugin update.
Version: 1.0
Author: <a href="https://www.stachredeker.nl/">Stach Redeker</a> & <a href="https://colingerritsen.com/">Colin Gerritsen</a>
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