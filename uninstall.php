<?php
// Deleting the plugin removes its settings. Nothing else is stored.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'hw_delivery_settings' );
