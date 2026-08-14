<?php
/**
 * REST controller for feedback ticket submission.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Rest;

use DocSyncWP\Feedback\FeedbackRateLimiter;
use DocSyncWP\Feedback\FeedbackService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Validates authenticated feedback and forwards it to the feedback service.
 */
final class FeedbackController {
	private const MAX_PAYLOAD_BYTES      = 12288;
	private const MAX_DESCRIPTION_LENGTH = 10000;
	private const MAX_TITLE_LENGTH       = 120;
	private const MIN_DESCRIPTION_LENGTH = 1;
	private const MIN_TITLE_LENGTH       = 1;

	private const TYPES = array( 'bug', 'feature', 'question' );

	/**
	 * Feedback service.
	 *
	 * @var FeedbackService
	 */
	private FeedbackService $feedback;

	/**
	 * Feedback rate limiter.
	 *
	 * @var FeedbackRateLimiter
	 */
	private FeedbackRateLimiter $rate_limiter;

	/**
	 * Constructor.
	 *
	 * @param FeedbackService     $feedback     Feedback service.
	 * @param FeedbackRateLimiter $rate_limiter Feedback rate limiter.
	 */
	public function __construct( FeedbackService $feedback, FeedbackRateLimiter $rate_limiter ) {
		$this->feedback     = $feedback;
		$this->rate_limiter = $rate_limiter;
	}

	/**
	 * Register controller routes.
	 *
	 * @param string $rest_namespace REST namespace.
	 */
	public function registerRoutes( string $rest_namespace ): void {
		register_rest_route(
			$rest_namespace,
			'/feedback',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'submitFeedback' ),
				'permission_callback' => array( RestPermissions::class, 'canUseAuthenticatedRest' ),
			)
		);
	}

	/**
	 * Validate and relay a feedback report.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function submitFeedback( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( strlen( $request->get_body() ) > self::MAX_PAYLOAD_BYTES ) {
			return new WP_Error(
				'docsync_wp_feedback_payload_too_large',
				__( 'Feedback is too large to submit.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 413 )
			);
		}

		$params = $request->get_json_params();

		if ( ! is_array( $params ) ) {
			$params = $request->get_body_params();
		}

		if ( ! is_array( $params ) ) {
			return $this->invalidPayload();
		}

		$unknown_keys = array_diff( array_keys( $params ), array( 'description', 'title', 'type' ) );

		if ( array() !== $unknown_keys ) {
			return new WP_Error(
				'docsync_wp_unknown_feedback_fields',
				__( 'Feedback contains unsupported fields.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 400 )
			);
		}

		$type        = isset( $params['type'] ) && is_string( $params['type'] ) ? sanitize_key( $params['type'] ) : '';
		$title       = isset( $params['title'] ) && is_string( $params['title'] ) ? sanitize_text_field( $params['title'] ) : '';
		$description = isset( $params['description'] ) && is_string( $params['description'] ) ? sanitize_textarea_field( $params['description'] ) : '';

		if ( ! in_array( $type, self::TYPES, true ) ) {
			return new WP_Error(
				'docsync_wp_feedback_invalid_type',
				__( 'Choose a valid feedback type.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 400 )
			);
		}

		if ( strlen( $title ) < self::MIN_TITLE_LENGTH || strlen( $title ) > self::MAX_TITLE_LENGTH ) {
			return new WP_Error(
				'docsync_wp_feedback_invalid_title',
				__( 'Feedback titles must not be empty and cannot exceed 120 characters.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 400 )
			);
		}

		if ( strlen( $description ) < self::MIN_DESCRIPTION_LENGTH || strlen( $description ) > self::MAX_DESCRIPTION_LENGTH ) {
			return new WP_Error(
				'docsync_wp_feedback_invalid_description',
				__( 'Feedback details must not be empty and cannot exceed 10,000 characters.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 400 )
			);
		}

		if ( ! $this->rate_limiter->consume( get_current_user_id(), $this->requestIp() ) ) {
			return new WP_Error(
				'docsync_wp_feedback_rate_limited',
				__( 'You have submitted too much feedback recently. Please try again later.', 'brasth-document-sync-for-google-docs' ),
				array(
					'retry_after' => HOUR_IN_SECONDS,
					'status'      => 429,
				)
			);
		}

		$result = $this->feedback->submit(
			array(
				'description' => $description,
				'title'       => $title,
				'type'        => $type,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array(
				'issueNumber' => $result['issueNumber'],
				'issueUrl'    => $result['issueUrl'],
			)
		);
	}

	/**
	 * Get a validated request IP for the short-lived rate-limit key.
	 */
	private function requestIp(): string {
		$ip = filter_input( INPUT_SERVER, 'REMOTE_ADDR', FILTER_VALIDATE_IP );

		return is_string( $ip ) ? $ip : 'unknown';
	}

	/**
	 * Return a consistent malformed-payload error.
	 */
	private function invalidPayload(): WP_Error {
		return new WP_Error(
			'docsync_wp_invalid_feedback_payload',
			__( 'Feedback must be submitted as an object.', 'brasth-document-sync-for-google-docs' ),
			array( 'status' => 400 )
		);
	}
}
