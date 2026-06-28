<?php
/**
 * Fired when Brasth Document Sync is uninstalled.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$docsync_wp_autoload = __DIR__ . '/vendor/autoload.php';

if ( file_exists( $docsync_wp_autoload ) ) {
	require_once $docsync_wp_autoload;
}

delete_option( 'docsync_wp_settings' );
delete_metadata( 'user', 0, '_docsync_wp_google_token', '', true );

if ( class_exists( DocSyncWP\Cron\SyncCron::class ) ) {
	DocSyncWP\Cron\SyncCron::unschedule();
} else {
	wp_clear_scheduled_hook( 'docsync_wp_sync_sources' );
	wp_clear_scheduled_hook( 'docsync_wp_sync_source' );
}

$full_cleanup = defined( 'DOCSYNC_WP_FULL_UNINSTALL' ) && DOCSYNC_WP_FULL_UNINSTALL;
$full_cleanup = (bool) apply_filters( 'docsync_wp_full_uninstall', $full_cleanup );

if ( ! $full_cleanup ) {
	return;
}

foreach (
	array(
		'_docsync_wp_google_file_id',
		'_docsync_wp_google_doc_url',
		'_docsync_wp_google_title',
		'_docsync_wp_google_modified_time',
		'_docsync_wp_google_version',
		'_docsync_wp_last_hash',
		'_docsync_wp_last_synced_at',
		'_docsync_wp_last_sync_method',
		'_docsync_wp_layout_preset',
		'_docsync_wp_last_layout_fingerprint',
		'_docsync_wp_sync_owner_user_id',
		'_docsync_wp_export_format',
		'_docsync_wp_sync_status',
		'_docsync_wp_sync_error',
		'_docsync_wp_sync_progress',
		'_docsync_wp_sync_step',
		'_docsync_wp_sync_message',
		'_docsync_wp_sync_started_at',
		'_docsync_wp_sync_updated_at',
		'_docsync_wp_sync_error_code',
		'_docsync_wp_sync_events',
	) as $meta_key
) {
	delete_metadata( 'post', 0, $meta_key, '', true );
}
