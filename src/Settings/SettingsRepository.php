<?php
/**
 * Site-level settings storage.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Settings;

use DocSyncWP\Security\EncryptionService;
use DocSyncWP\Sync\Layout\LayoutPresetRegistry;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Reads, validates, and stores Brasth Document Sync settings.
 */
final class SettingsRepository {
	public const OPTION_NAME = 'docsync_wp_settings';

	private const DEFAULT_SCOPE_MODE      = 'drive_file';
	private const DEFAULT_POST_STATUS     = 'draft';
	private const DEFAULT_EXPORT_FORMAT   = 'html_zip';
	private const DEFAULT_SYNC_INTERVAL   = 'off';
	private const DEFAULT_CONNECTION_MODE = 'self_managed';
	private const DEFAULT_ELEMENTOR_SYNC  = false;
	private const DEFAULT_TELEMETRY       = false;

	/**
	 * Layout preset registry.
	 *
	 * @var LayoutPresetRegistry
	 */
	private LayoutPresetRegistry $layout_presets;

	/**
	 * Encryption service.
	 *
	 * @var EncryptionService
	 */
	private EncryptionService $encryption;

	/**
	 * Constructor.
	 *
	 * @param EncryptionService         $encryption     Encryption service.
	 * @param LayoutPresetRegistry|null $layout_presets Layout preset registry.
	 */
	public function __construct( EncryptionService $encryption, ?LayoutPresetRegistry $layout_presets = null ) {
		$this->encryption     = $encryption;
		$this->layout_presets = $layout_presets ?? new LayoutPresetRegistry();
	}

	/**
	 * Get normalized settings.
	 *
	 * @return array<string,mixed>
	 */
	public function get(): array {
		$stored = get_option( self::OPTION_NAME, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$defaults = $this->defaults();
		$settings = array_intersect_key( array_merge( $defaults, $stored ), $defaults );
		$settings = $this->sanitizeScalarSettings( $settings );

		$enabled_post_types = $this->sanitizeEnabledPostTypes( $settings['enabled_post_types'], false );

		if ( is_wp_error( $enabled_post_types ) ) {
			$enabled_post_types = array( 'post' );
		}

		$settings['enabled_post_types'] = $enabled_post_types;

		if ( ! $this->isValidScopeMode( $settings['scope_mode'] ) ) {
			$settings['scope_mode'] = self::DEFAULT_SCOPE_MODE;
		}

		if ( ! $this->isValidPostStatus( $settings['default_post_status'] ) ) {
			$settings['default_post_status'] = self::DEFAULT_POST_STATUS;
		}

		if ( ! $this->isValidExportFormat( $settings['default_export_format'] ) ) {
			$settings['default_export_format'] = self::DEFAULT_EXPORT_FORMAT;
		}

		if ( ! $this->isValidSyncInterval( $settings['sync_interval'] ) ) {
			$settings['sync_interval'] = self::DEFAULT_SYNC_INTERVAL;
		}

		if ( ! $this->isValidConnectionMode( $settings['connection_mode'] ) ) {
			$settings['connection_mode'] = self::DEFAULT_CONNECTION_MODE;
		}

		if ( ! $this->layout_presets->isValidPresetId( $settings['default_layout_preset'] ) ) {
			$settings['default_layout_preset'] = LayoutPresetRegistry::DEFAULT_EXISTING_INSTALL;
		}

		return $settings;
	}

	/**
	 * Save settings.
	 *
	 * Accepted keys are internal snake_case settings plus client_secret for
	 * a plaintext secret that should be encrypted before storage.
	 *
	 * @param array<string,mixed> $values Settings to save.
	 * @return array<string,mixed>|WP_Error
	 */
	public function save( array $values ): array|WP_Error {
		$unknown_keys = array_diff( array_keys( $values ), $this->writableKeys() );

		if ( array() !== $unknown_keys ) {
			return new WP_Error(
				'docsync_wp_unknown_settings',
				__( 'Brasth Document Sync received unknown settings.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 400 )
			);
		}

		$current  = $this->get();
		$settings = $current;

		foreach ( $this->scalarWritableKeys() as $key ) {
			if ( array_key_exists( $key, $values ) ) {
				$settings[ $key ] = $values[ $key ];
			}
		}

		$settings = $this->sanitizeScalarSettings( $settings );

		if ( array_key_exists( 'enabled_post_types', $values ) ) {
			$enabled_post_types = $this->sanitizeEnabledPostTypes( $values['enabled_post_types'], true );

			if ( is_wp_error( $enabled_post_types ) ) {
				return $enabled_post_types;
			}

			$settings['enabled_post_types'] = $enabled_post_types;
		}

		if ( ! $this->isValidScopeMode( $settings['scope_mode'] ) ) {
			return new WP_Error(
				'docsync_wp_invalid_scope_mode',
				__( 'Brasth Document Sync received an unsupported Google scope mode.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 400 )
			);
		}

		if ( ! $this->isValidPostStatus( $settings['default_post_status'] ) ) {
			return new WP_Error(
				'docsync_wp_invalid_post_status',
				__( 'Brasth Document Sync received an unsupported default post status.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 400 )
			);
		}

		if ( ! $this->isValidExportFormat( $settings['default_export_format'] ) ) {
			return new WP_Error(
				'docsync_wp_invalid_export_format',
				__( 'Brasth Document Sync received an unsupported export format.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 400 )
			);
		}

		if ( ! $this->isValidSyncInterval( $settings['sync_interval'] ) ) {
			return new WP_Error(
				'docsync_wp_invalid_sync_interval',
				__( 'Brasth Document Sync received an unsupported sync schedule.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 400 )
			);
		}

		if ( ! $this->isValidConnectionMode( $settings['connection_mode'] ) ) {
			return new WP_Error(
				'docsync_wp_invalid_connection_mode',
				__( 'Brasth Document Sync received an unsupported Google connection mode.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 400 )
			);
		}

		if ( ! $this->layout_presets->isValidPresetId( $settings['default_layout_preset'] ) ) {
			return new WP_Error(
				'docsync_wp_invalid_layout_preset',
				__( 'Brasth Document Sync received an unsupported default synced layout.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 400 )
			);
		}

		$settings['elementor_sync_enabled'] = $this->sanitizeBooleanSetting( $settings['elementor_sync_enabled'] );
		$settings['telemetry_enabled']      = $this->sanitizeBooleanSetting( $settings['telemetry_enabled'] );

		if ( $settings['telemetry_enabled'] ) {
			$settings['telemetry_site_id'] = '' !== $current['telemetry_site_id']
				? $current['telemetry_site_id']
				: $this->generateTelemetrySiteId();
		} else {
			$settings['telemetry_site_id'] = '';
		}

		if ( array_key_exists( 'client_secret', $values ) ) {
			$client_secret = sanitize_text_field( (string) $values['client_secret'] );

			if ( '' === $client_secret ) {
				$settings['encrypted_client_secret'] = '';
			} else {
				$encrypted = $this->encryption->encrypt( $client_secret );

				if ( is_wp_error( $encrypted ) ) {
					return $encrypted;
				}

				$settings['encrypted_client_secret'] = $encrypted;
			}
		}

		update_option( self::OPTION_NAME, $settings, false );

		return $this->get();
	}

	/**
	 * Get settings safe for REST responses or admin config.
	 *
	 * @return array<string,mixed>
	 */
	public function getPublicSettings(): array {
		$settings = $this->get();

		return array(
			'client_id'              => $settings['client_id'],
			'scope_mode'             => $settings['scope_mode'],
			'enabled_post_types'     => $settings['enabled_post_types'],
			'default_post_status'    => $settings['default_post_status'],
			'default_export_format'  => $settings['default_export_format'],
			'default_layout_preset'  => $settings['default_layout_preset'],
			'sync_interval'          => $settings['sync_interval'],
			'connection_mode'        => $settings['connection_mode'],
			'elementor_sync_enabled' => $settings['elementor_sync_enabled'],
			'telemetry_enabled'      => $settings['telemetry_enabled'],
			'has_client_id'          => '' !== $settings['client_id'],
			'has_client_secret'      => '' !== $settings['encrypted_client_secret'],
			'has_required_settings'  => '' !== $settings['client_id'] && '' !== $settings['encrypted_client_secret'],
		);
	}

	/**
	 * Get the decrypted client secret.
	 *
	 * @return string|WP_Error
	 */
	public function getClientSecret(): string|WP_Error {
		$settings = $this->get();

		return $this->encryption->decrypt( (string) $settings['encrypted_client_secret'] );
	}

	/**
	 * Get enabled post type names.
	 *
	 * @return array<int,string>
	 */
	public function getEnabledPostTypes(): array {
		$settings = $this->get();

		return is_array( $settings['enabled_post_types'] ) ? $settings['enabled_post_types'] : array( 'post' );
	}

	/**
	 * Whether Elementor sync is enabled in the global settings.
	 */
	public function isElementorSyncEnabled(): bool {
		$settings = $this->get();

		return (bool) ( $settings['elementor_sync_enabled'] ?? false );
	}

	/**
	 * Whether anonymous telemetry is enabled.
	 */
	public function isTelemetryEnabled(): bool {
		$settings = $this->get();

		return (bool) ( $settings['telemetry_enabled'] ?? false );
	}

	/**
	 * Get the private telemetry install identifier.
	 */
	public function getTelemetrySiteId(): string {
		$settings = $this->get();

		return is_string( $settings['telemetry_site_id'] ) ? $settings['telemetry_site_id'] : '';
	}

	/**
	 * Get layout presets available to the block editor sync path.
	 *
	 * @return array<int,array{id:string,label:string,description:string}>
	 */
	public function getAvailableLayoutPresets(): array {
		return $this->layout_presets->getAvailablePresets();
	}

	/**
	 * Get public post types supported by Brasth Document Sync.
	 *
	 * @return array<int,array{name:string,label:string}>
	 */
	public function getAvailablePostTypes(): array {
		$post_types = get_post_types( array( 'public' => true ), 'objects' );
		$available  = array();

		foreach ( $post_types as $post_type => $object ) {
			if ( ! is_string( $post_type ) || ! is_object( $object ) ) {
				continue;
			}

			if ( ! $this->isSupportedPostTypeObject( $post_type, $object ) ) {
				continue;
			}

			$available[ $post_type ] = array(
				'name'  => $post_type,
				'label' => isset( $object->labels->singular_name ) && is_string( $object->labels->singular_name )
					? $object->labels->singular_name
					: $object->label,
			);
		}

		if ( ! isset( $available['post'] ) ) {
			$post = get_post_type_object( 'post' );

			if ( null !== $post ) {
				$available['post'] = array(
					'name'  => 'post',
					'label' => isset( $post->labels->singular_name ) && is_string( $post->labels->singular_name )
						? $post->labels->singular_name
						: $post->label,
				);
			}
		}

		if ( ! isset( $available['page'] ) ) {
			$page = get_post_type_object( 'page' );

			if ( null !== $page ) {
				$available['page'] = array(
					'name'  => 'page',
					'label' => isset( $page->labels->singular_name ) && is_string( $page->labels->singular_name )
						? $page->labels->singular_name
						: $page->label,
				);
			}
		}

		$ordered = array();

		foreach ( array( 'post', 'page' ) as $required_post_type ) {
			if ( isset( $available[ $required_post_type ] ) ) {
				$ordered[ $required_post_type ] = $available[ $required_post_type ];
				unset( $available[ $required_post_type ] );
			}
		}

		ksort( $available );

		return array_values( array_merge( $ordered, $available ) );
	}

	/**
	 * Whether a post type is available for syncing.
	 *
	 * @param string $post_type Post type name.
	 */
	public function isPostTypeAvailable( string $post_type ): bool {
		$object = get_post_type_object( $post_type );

		return null !== $object && $this->isSupportedPostTypeObject( $post_type, $object );
	}

	/**
	 * Default settings.
	 *
	 * @return array<string,mixed>
	 */
	private function defaults(): array {
		return array(
			'client_id'               => '',
			'encrypted_client_secret' => '',
			'scope_mode'              => self::DEFAULT_SCOPE_MODE,
			'enabled_post_types'      => array( 'post' ),
			'default_post_status'     => self::DEFAULT_POST_STATUS,
			'default_export_format'   => self::DEFAULT_EXPORT_FORMAT,
			'default_layout_preset'   => LayoutPresetRegistry::DEFAULT_EXISTING_INSTALL,
			'sync_interval'           => self::DEFAULT_SYNC_INTERVAL,
			'connection_mode'         => self::DEFAULT_CONNECTION_MODE,
			'elementor_sync_enabled'  => self::DEFAULT_ELEMENTOR_SYNC,
			'telemetry_enabled'       => self::DEFAULT_TELEMETRY,
			'telemetry_site_id'       => '',
		);
	}

	/**
	 * Keys callers may save.
	 *
	 * @return array<int,string>
	 */
	private function writableKeys(): array {
		return array_merge( $this->scalarWritableKeys(), array( 'client_secret', 'enabled_post_types' ) );
	}

	/**
	 * Scalar keys callers may save directly.
	 *
	 * @return array<int,string>
	 */
	private function scalarWritableKeys(): array {
		return array(
			'client_id',
			'scope_mode',
			'default_post_status',
			'default_export_format',
			'default_layout_preset',
			'sync_interval',
			'connection_mode',
			'elementor_sync_enabled',
			'telemetry_enabled',
		);
	}

	/**
	 * Sanitize scalar settings.
	 *
	 * @param array<string,mixed> $settings Settings.
	 * @return array<string,mixed>
	 */
	private function sanitizeScalarSettings( array $settings ): array {
		$settings['client_id']               = sanitize_text_field( (string) $settings['client_id'] );
		$settings['encrypted_client_secret'] = is_string( $settings['encrypted_client_secret'] ) ? $settings['encrypted_client_secret'] : '';
		$settings['scope_mode']              = sanitize_key( (string) $settings['scope_mode'] );
		$settings['default_post_status']     = sanitize_key( (string) $settings['default_post_status'] );
		$settings['default_export_format']   = sanitize_key( (string) $settings['default_export_format'] );
		$settings['default_layout_preset']   = sanitize_key( (string) $settings['default_layout_preset'] );
		$settings['sync_interval']           = sanitize_key( (string) $settings['sync_interval'] );
		$settings['connection_mode']         = sanitize_key( (string) $settings['connection_mode'] );
		$settings['elementor_sync_enabled']  = $this->sanitizeBooleanSetting( $settings['elementor_sync_enabled'] ?? self::DEFAULT_ELEMENTOR_SYNC );
		$settings['telemetry_enabled']       = $this->sanitizeBooleanSetting( $settings['telemetry_enabled'] ?? self::DEFAULT_TELEMETRY );
		$settings['telemetry_site_id']       = sanitize_text_field( (string) ( $settings['telemetry_site_id'] ?? '' ) );

		return $settings;
	}

	/**
	 * Generate a private install identifier for telemetry hashing.
	 */
	private function generateTelemetrySiteId(): string {
		return wp_generate_uuid4();
	}

	/**
	 * Sanitize a boolean setting.
	 *
	 * @param mixed $value Setting value.
	 */
	private function sanitizeBooleanSetting( mixed $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_string( $value ) ) {
			$value = strtolower( trim( $value ) );

			return in_array( $value, array( '1', 'true', 'yes', 'on' ), true );
		}

		return (bool) $value;
	}

	/**
	 * Sanitize and validate enabled post types.
	 *
	 * @param mixed $value  Candidate post types.
	 * @param bool  $strict Whether invalid values should fail.
	 * @return array<int,string>|WP_Error
	 */
	private function sanitizeEnabledPostTypes( mixed $value, bool $strict ): array|WP_Error {
		if ( ! is_array( $value ) ) {
			if ( $strict ) {
				return new WP_Error(
					'docsync_wp_invalid_post_types',
					__( 'Brasth Document Sync enabled post types must be an array.', 'brasth-document-sync-for-google-docs' ),
					array( 'status' => 400 )
				);
			}

			$value = array( 'post' );
		}

		$post_types = array();

		foreach ( $value as $post_type ) {
			if ( ! is_string( $post_type ) ) {
				if ( $strict ) {
					return new WP_Error(
						'docsync_wp_invalid_post_type',
						__( 'Brasth Document Sync received an invalid post type.', 'brasth-document-sync-for-google-docs' ),
						array( 'status' => 400 )
					);
				}

				continue;
			}

			$post_types[] = sanitize_key( $post_type );
		}

		$post_types = array_values( array_unique( array_filter( $post_types ) ) );

		if ( ! in_array( 'post', $post_types, true ) && $this->isPostTypeAvailable( 'post' ) ) {
			array_unshift( $post_types, 'post' );
		}

		if ( array() === $post_types ) {
			$post_types = array( 'post' );
		}

		foreach ( $post_types as $post_type ) {
			if ( ! $this->isPostTypeAvailable( $post_type ) ) {
				if ( $strict ) {
					return new WP_Error(
						'docsync_wp_unsupported_post_type',
						sprintf(
							/* translators: %s: post type name. */
							__( 'The "%s" post type cannot be used for Brasth Document Sync.', 'brasth-document-sync-for-google-docs' ),
							$post_type
						),
						array( 'status' => 400 )
					);
				}

				$post_types = array_values(
					array_filter(
						$post_types,
						static function ( string $candidate ) use ( $post_type ): bool {
							return $candidate !== $post_type;
						}
					)
				);
			}
		}

		return array() === $post_types ? array( 'post' ) : $post_types;
	}

	/**
	 * Whether a scope mode is supported.
	 *
	 * @param string $scope_mode Scope mode.
	 */
	private function isValidScopeMode( string $scope_mode ): bool {
		return self::DEFAULT_SCOPE_MODE === $scope_mode;
	}

	/**
	 * Whether a post status is supported as a default.
	 *
	 * @param string $post_status Post status.
	 */
	private function isValidPostStatus( string $post_status ): bool {
		$post_stati = get_post_stati( array( 'internal' => false ), 'names' );

		return in_array( $post_status, $post_stati, true );
	}

	/**
	 * Whether an export format is supported.
	 *
	 * @param string $export_format Export format.
	 */
	private function isValidExportFormat( string $export_format ): bool {
		return self::DEFAULT_EXPORT_FORMAT === $export_format;
	}

	/**
	 * Whether a sync interval is supported by WP-Cron.
	 *
	 * @param string $sync_interval Sync interval.
	 */
	private function isValidSyncInterval( string $sync_interval ): bool {
		return in_array( $sync_interval, array( 'off', 'hourly', 'twicedaily', 'daily' ), true );
	}

	/**
	 * Whether a Google connection mode is supported.
	 *
	 * @param string $connection_mode Connection mode.
	 */
	private function isValidConnectionMode( string $connection_mode ): bool {
		return self::DEFAULT_CONNECTION_MODE === $connection_mode;
	}

	/**
	 * Whether the post type object is supported by Brasth Document Sync.
	 *
	 * @param string $post_type        Post type name.
	 * @param object $post_type_object Post type object.
	 */
	private function isSupportedPostTypeObject( string $post_type, object $post_type_object ): bool {
		if ( in_array( $post_type, array( 'post', 'page' ), true ) ) {
			return true;
		}

		return ! empty( $post_type_object->public ) && empty( $post_type_object->_builtin );
	}
}
