<?php
/**
 * Optional anonymous telemetry check-ins.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Telemetry;

use DocSyncWP\Settings\SettingsRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Sends anonymous active-install metadata when the site owner opts in.
 */
final class TelemetryService {
	public const PLUGIN_SLUG     = 'brasth-document-sync-for-google-docs';
	public const CONSENT_VERSION = '2026-06-30';

	private const DEFAULT_ENDPOINT = 'https://telemetry.brasth.com/v1/check-in';

	/**
	 * Settings repository.
	 *
	 * @var SettingsRepository
	 */
	private SettingsRepository $settings;

	/**
	 * Constructor.
	 *
	 * @param SettingsRepository $settings Settings repository.
	 */
	public function __construct( SettingsRepository $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Send one check-in if telemetry is enabled.
	 */
	public function checkIn(): void {
		if ( ! $this->settings->isTelemetryEnabled() ) {
			return;
		}

		$site_id = $this->settings->getTelemetrySiteId();

		if ( '' === $site_id ) {
			return;
		}

		$endpoint = $this->getEndpoint();

		if ( '' === $endpoint ) {
			return;
		}

		$body = wp_json_encode( $this->payload( $site_id ) );

		if ( ! is_string( $body ) ) {
			return;
		}

		wp_remote_post(
			$endpoint,
			array(
				'blocking'    => false,
				'body'        => $body,
				'headers'     => array(
					'Content-Type' => 'application/json',
				),
				'redirection' => 0,
				'timeout'     => 5,
			)
		);
	}

	/**
	 * Build the endpoint URL, allowing staging and tests to replace it.
	 */
	private function getEndpoint(): string {
		$endpoint = apply_filters( 'docsync_wp_telemetry_endpoint', self::DEFAULT_ENDPOINT );

		if ( ! is_string( $endpoint ) ) {
			return '';
		}

		return esc_url_raw( $endpoint );
	}

	/**
	 * Build the anonymous metadata payload.
	 *
	 * @param string $site_id Private install identifier.
	 * @return array<string,string>
	 */
	private function payload( string $site_id ): array {
		global $wp_version;

		return array(
			'siteHash'       => hash( 'sha256', $site_id ),
			'pluginSlug'     => self::PLUGIN_SLUG,
			'pluginVersion'  => defined( 'DOCSYNC_WP_VERSION' ) ? (string) DOCSYNC_WP_VERSION : '',
			'wpVersion'      => (string) $wp_version,
			'phpVersion'     => PHP_VERSION,
			'consentVersion' => self::CONSENT_VERSION,
		);
	}
}
