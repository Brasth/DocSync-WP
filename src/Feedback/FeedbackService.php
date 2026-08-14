<?php
/**
 * Relays user feedback to the Brasth feedback Worker.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Feedback;

use WP_Error;

use function wp_json_encode;
use function wp_remote_post;
use function wp_remote_retrieve_body;
use function wp_remote_retrieve_response_code;

defined( 'ABSPATH' ) || exit;

/**
 * Sends validated feedback without calling GitHub from the plugin.
 */
final class FeedbackService {
	private const DEFAULT_ENDPOINT = 'https://feedback.brasth.com/v1/issues';

	/**
	 * Submit feedback to the configured Cloudflare Worker.
	 *
	 * @param array{type:string,title:string,description:string} $feedback Feedback fields.
	 * @return array{issueUrl:string,issueNumber:int}|WP_Error
	 */
	public function submit( array $feedback ): array|WP_Error {
		$endpoint = $this->getEndpoint();

		if ( '' === $endpoint ) {
			return new WP_Error(
				'docsync_wp_feedback_not_configured',
				__( 'Feedback submission is not configured on this site.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 503 )
			);
		}

		$body = wp_json_encode(
			array(
				'type'        => $feedback['type'],
				'title'       => $feedback['title'],
				'description' => $feedback['description'],
				'context'     => $this->context(),
			)
		);

		if ( ! is_string( $body ) ) {
			return new WP_Error(
				'docsync_wp_feedback_encode_failed',
				__( 'The feedback could not be prepared. Please retry.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 500 )
			);
		}

		$headers       = array(
			'Accept'       => 'application/json',
			'Content-Type' => 'application/json',
			'User-Agent'   => 'DocSync-WP/' . $this->pluginVersion(),
		);
		$worker_secret = $this->workerSecret();

		if ( '' !== $worker_secret ) {
			$headers['X-DocSync-Feedback-Secret'] = $worker_secret;
		}

		$response = wp_remote_post(
			$endpoint,
			array(
				'body'        => $body,
				'headers'     => $headers,
				'redirection' => 0,
				'timeout'     => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'docsync_wp_feedback_unavailable',
				__( 'The feedback service could not be reached. Please retry later.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 502 )
			);
		}

		$status  = wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status < 200 || $status >= 300 || ! is_array( $decoded ) ) {
			return new WP_Error(
				'docsync_wp_feedback_failed',
				__( 'The feedback service rejected the report. Please retry later.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 502 )
			);
		}

		$issue_url    = isset( $decoded['issue']['url'] ) && is_string( $decoded['issue']['url'] ) ? esc_url_raw( $decoded['issue']['url'] ) : '';
		$issue_host   = '' !== $issue_url ? wp_parse_url( $issue_url, PHP_URL_HOST ) : '';
		$issue_number = isset( $decoded['issue']['number'] ) && is_numeric( $decoded['issue']['number'] ) ? (int) $decoded['issue']['number'] : 0;

		if ( 'github.com' !== strtolower( (string) $issue_host ) || $issue_number <= 0 ) {
			return new WP_Error(
				'docsync_wp_feedback_invalid_response',
				__( 'The feedback service returned an invalid response. Please retry later.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 502 )
			);
		}

		return array(
			'issueNumber' => $issue_number,
			'issueUrl'    => $issue_url,
		);
	}

	/**
	 * Build non-sensitive runtime context for maintainers.
	 *
	 * @return array{pluginVersion:string,wpVersion:string,phpVersion:string}
	 */
	private function context(): array {
		global $wp_version;

		return array(
			'phpVersion'    => PHP_VERSION,
			'pluginVersion' => $this->pluginVersion(),
			'wpVersion'     => (string) $wp_version,
		);
	}

	/**
	 * Resolve the Worker endpoint.
	 */
	private function getEndpoint(): string {
		$endpoint = defined( 'DOCSYNC_WP_FEEDBACK_ENDPOINT' ) && is_string( DOCSYNC_WP_FEEDBACK_ENDPOINT )
			? DOCSYNC_WP_FEEDBACK_ENDPOINT
			: self::DEFAULT_ENDPOINT;
		$endpoint = apply_filters( 'docsync_wp_feedback_endpoint', $endpoint );

		return is_string( $endpoint ) ? esc_url_raw( $endpoint ) : '';
	}

	/**
	 * Read the optional server-only Worker shared secret.
	 */
	private function workerSecret(): string {
		return defined( 'DOCSYNC_WP_FEEDBACK_WORKER_SECRET' ) && is_string( DOCSYNC_WP_FEEDBACK_WORKER_SECRET )
			? DOCSYNC_WP_FEEDBACK_WORKER_SECRET
			: '';
	}

	/**
	 * Get the plugin version for request metadata.
	 */
	private function pluginVersion(): string {
		return defined( 'DOCSYNC_WP_VERSION' ) ? (string) DOCSYNC_WP_VERSION : 'unknown';
	}
}
