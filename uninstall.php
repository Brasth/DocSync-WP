<?php
/**
 * Fired when DocSync WP is uninstalled.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// No persistent plugin data is created by the scaffold.
