<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// log
if (!function_exists('wc_elepay_log')) {
    function wc_elepay_log(...$args) {
        foreach ($args as $arg) {
            echo '<br/>===========================<br/>';

            if (is_array($arg) || is_object($arg)) {
                print_r($arg);
            } else {
                echo $arg;
            }
        }
    }
}