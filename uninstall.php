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
delete_option( 'docsync_wp_folder_watches' );
delete_option( 'docsync_wp_last_cron_run_at' );
delete_option( 'docsync_wp_sync_continuations' );
delete_option( 'docsync_wp_next_sync_backfill_done' );
delete_metadata( 'user', 0, '_docsync_wp_google_token', '', true );

if ( class_exists( DocSyncWP\Cron\SyncCron::class ) ) {
	DocSyncWP\Cron\SyncCron::unschedule();
} else {
	wp_clear_scheduled_hook( 'docsync_wp_sync_sources' );
	wp_clear_scheduled_hook( 'docsync_wp_sync_source' );
	wp_clear_scheduled_hook( 'docsync_wp_sync_sources_continue' );
}

if ( class_exists( DocSyncWP\Cron\ScheduleBackfill::class ) ) {
	DocSyncWP\Cron\ScheduleBackfill::unschedule();
} else {
	wp_clear_scheduled_hook( 'docsync_wp_backfill_next_sync' );
}

if ( class_exists( DocSyncWP\Sync\FolderWatchService::class ) ) {
	DocSyncWP\Sync\FolderWatchService::unschedule();
} else {
	wp_clear_scheduled_hook( 'docsync_wp_import_folder' );
	wp_clear_scheduled_hook( 'docsync_wp_scan_folder' );
}

if ( class_exists( DocSyncWP\Telemetry\TelemetryCron::class ) ) {
	DocSyncWP\Telemetry\TelemetryCron::unschedule();
} else {
	wp_clear_scheduled_hook( 'docsync_wp_telemetry_checkin' );
}

// Add-content cron events carry per-session or per-job arguments, so every instance is removed.
foreach ( array( 'docsync_wp_import_convert', 'docsync_wp_import_commit', 'docsync_wp_matching_run', 'docsync_wp_import_cleanup' ) as $docsync_wp_hook ) {
	wp_unschedule_hook( $docsync_wp_hook );
}

/*
 * Add-content records: import sessions, matching jobs, and batch idempotency keys.
 * Each record and its lease are found through the plugin's own index option.
 */
foreach (
	array(
		array(
			'index'        => 'docsync_wp_import_session_index',
			'record'       => 'docsync_wp_import_session_',
			'lock'         => 'docsync_wp_import_lock_',
			'lock_suffix'  => '',
			'name_pattern' => '/^[a-f0-9-]{36}$/',
		),
		array(
			'index'        => 'docsync_wp_match_job_index',
			'record'       => 'docsync_wp_match_job_',
			'lock'         => 'docsync_wp_match_job_lock_',
			'lock_suffix'  => '',
			'name_pattern' => '/^[a-f0-9-]{36}$/',
		),
		array(
			'index'        => 'docsync_wp_source_batch_index',
			'record'       => 'docsync_wp_source_batch_',
			'lock'         => 'docsync_wp_source_batch_',
			'lock_suffix'  => '_lock',
			'name_pattern' => '/^[a-f0-9]{64}$/',
		),
	) as $docsync_wp_store
) {
	$docsync_wp_index = get_option( $docsync_wp_store['index'], array() );

	if ( is_array( $docsync_wp_index ) ) {
		foreach ( array_keys( $docsync_wp_index ) as $docsync_wp_record_id ) {
			if ( ! is_string( $docsync_wp_record_id ) || 1 !== preg_match( $docsync_wp_store['name_pattern'], $docsync_wp_record_id ) ) {
				continue;
			}

			delete_option( $docsync_wp_store['record'] . $docsync_wp_record_id );
			delete_option( $docsync_wp_store['lock'] . $docsync_wp_record_id . $docsync_wp_store['lock_suffix'] );
		}
	}

	delete_option( $docsync_wp_store['index'] );
}

delete_metadata( 'user', 0, '_docsync_wp_oauth_continuations', '', true );
delete_metadata( 'user', 0, '_docsync_wp_import_folder_id', '', true );

/*
 * Private upload bytes. Only the plugin-owned `docsync-wp-private` directory is
 * removed: inside uploads, and inside DOCSYNC_WP_PRIVATE_STORAGE_DIR when that
 * is defined. The configured directory itself, symlinks, Drive files, posts,
 * and media are never touched.
 */
$docsync_wp_private_dirs = array();
$docsync_wp_uploads      = wp_upload_dir( null, false );

if ( is_array( $docsync_wp_uploads ) && empty( $docsync_wp_uploads['error'] ) && ! empty( $docsync_wp_uploads['basedir'] ) ) {
	$docsync_wp_private_dirs[] = (string) $docsync_wp_uploads['basedir'];
}

if ( defined( 'DOCSYNC_WP_PRIVATE_STORAGE_DIR' ) && is_string( DOCSYNC_WP_PRIVATE_STORAGE_DIR ) && '' !== trim( DOCSYNC_WP_PRIVATE_STORAGE_DIR ) && path_is_absolute( DOCSYNC_WP_PRIVATE_STORAGE_DIR ) ) {
	$docsync_wp_private_dirs[] = DOCSYNC_WP_PRIVATE_STORAGE_DIR;
}

if ( array() !== $docsync_wp_private_dirs ) {
	if ( ! class_exists( 'WP_Filesystem_Direct' ) ) {
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
	}

	$docsync_wp_filesystem = new WP_Filesystem_Direct( null );

	foreach ( $docsync_wp_private_dirs as $docsync_wp_parent_dir ) {
		$docsync_wp_private_dir = untrailingslashit( wp_normalize_path( $docsync_wp_parent_dir ) ) . '/docsync-wp-private';

		if ( is_link( $docsync_wp_private_dir ) || ! $docsync_wp_filesystem->is_dir( $docsync_wp_private_dir ) ) {
			continue;
		}

		$docsync_wp_real_dir = realpath( $docsync_wp_private_dir );

		if ( false === $docsync_wp_real_dir || 'docsync-wp-private' !== basename( $docsync_wp_real_dir ) ) {
			continue;
		}

		$docsync_wp_filesystem->delete( $docsync_wp_real_dir, true );
	}
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
		'_docsync_wp_next_sync_at',
		'_docsync_wp_sync_interval',
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
		'_docsync_wp_folder_watch_id',
		'_docsync_wp_import_provenance',
		'_docsync_wp_import_kind',
	) as $meta_key
) {
	delete_metadata( 'post', 0, $meta_key, '', true );
}
