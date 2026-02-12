<?php
/**
 * REST API: WP_REST_Form_Analytics_Controller class
 *
 * @package WordPress
 * @subpackage REST_API
 * @since 6.7.0
 */

/**
 * Core class used to retrieve form analytics via the REST API.
 *
 * @since 6.7.0
 *
 * @see WP_REST_Controller
 */
class WP_REST_Form_Analytics_Controller extends WP_REST_Controller {

	/**
	 * Constructor.
	 *
	 * @since 6.7.0
	 */
	public function __construct() {
		$this->namespace = 'wp/v2';
		$this->rest_base = 'forms/(?P<form_id>[\d]+)/analytics';
	}

	/**
	 * Registers the routes for form analytics.
	 *
	 * @since 6.7.0
	 *
	 * @see register_rest_route()
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
					'args'                => array(
						'form_id' => array(
							'description' => __( 'The form ID.' ),
							'type'        => 'integer',
							'required'    => true,
						),
					),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * Checks if a given request has access to get analytics.
	 *
	 * @since 6.7.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error True if the request has read access, WP_Error otherwise.
	 */
	public function get_item_permissions_check( $request ) {
		$form = get_post( $request['form_id'] );

		if ( ! $form || 'form' !== $form->post_type ) {
			return new WP_Error(
				'rest_form_invalid_id',
				__( 'Invalid form ID.' ),
				array( 'status' => 404 )
			);
		}

		if ( ! current_user_can( 'edit_post', $form->ID ) ) {
			return new WP_Error(
				'rest_cannot_read_analytics',
				__( 'Sorry, you are not allowed to view analytics for this form.' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Retrieves analytics for a form.
	 *
	 * @since 6.7.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function get_item( $request ) {
		$form_id = $request['form_id'];

		// Get total submissions
		$total_submissions = (int) get_post_meta( $form_id, '_submission_count', true );

		// Get submissions by date (last 30 days)
		$submissions_by_date = $this->get_submissions_by_date( $form_id, 30 );

		// Get submission sources (referers)
		$submission_sources = $this->get_submission_sources( $form_id );

		// Get popular fields (most frequently filled)
		$popular_fields = $this->get_popular_fields( $form_id );

		$data = array(
			'total_submissions'   => $total_submissions,
			'total_views'         => 0, // Placeholder - would require view tracking
			'conversion_rate'     => 0.0, // Placeholder - would require view tracking
			'submissions_by_date' => $submissions_by_date,
			'popular_fields'      => $popular_fields,
			'submission_sources'  => $submission_sources,
		);

		$response = rest_ensure_response( $data );

		return $response;
	}

	/**
	 * Gets submissions grouped by date.
	 *
	 * @since 6.7.0
	 *
	 * @param int $form_id Number of days to retrieve.
	 * @param int $days    Number of days.
	 * @return array Submissions by date.
	 */
	protected function get_submissions_by_date( $form_id, $days = 30 ) {
		global $wpdb;

		$date_from = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(comment_date) as date, COUNT(*) as count
				FROM {$wpdb->comments}
				WHERE comment_post_ID = %d
				AND comment_type = 'form_submission'
				AND comment_date >= %s
				GROUP BY DATE(comment_date)
				ORDER BY date ASC",
				$form_id,
				$date_from
			)
		);

		$by_date = array();
		foreach ( $results as $result ) {
			$by_date[] = array(
				'date'  => $result->date,
				'count' => (int) $result->count,
			);
		}

		return $by_date;
	}

	/**
	 * Gets submission sources (referers).
	 *
	 * @since 6.7.0
	 *
	 * @param int $form_id Form ID.
	 * @return array Submission sources.
	 */
	protected function get_submission_sources( $form_id ) {
		$args = array(
			'post_id' => $form_id,
			'type'    => 'form_submission',
			'status'  => 'approve',
			'number'  => 100, // Limit for performance
		);

		$comments = get_comments( $args );
		$sources  = array();

		foreach ( $comments as $comment ) {
			$referer = get_comment_meta( $comment->comment_ID, '_submission_referer', true );

			if ( ! empty( $referer ) ) {
				$parsed = wp_parse_url( $referer );
				$host   = isset( $parsed['host'] ) ? $parsed['host'] : __( 'Direct' );

				if ( ! isset( $sources[ $host ] ) ) {
					$sources[ $host ] = 0;
				}
				$sources[ $host ]++;
			}
		}

		arsort( $sources );

		$sources_array = array();
		foreach ( $sources as $source => $count ) {
			$sources_array[] = array(
				'source' => $source,
				'count'  => $count,
			);
		}

		return array_slice( $sources_array, 0, 10 ); // Top 10 sources
	}

	/**
	 * Gets popular fields (most frequently filled).
	 *
	 * @since 6.7.0
	 *
	 * @param int $form_id Form ID.
	 * @return array Popular fields.
	 */
	protected function get_popular_fields( $form_id ) {
		$fields = get_post_meta( $form_id, '_form_fields', true );

		if ( empty( $fields ) || ! is_array( $fields ) ) {
			return array();
		}

		$args = array(
			'post_id' => $form_id,
			'type'    => 'form_submission',
			'status'  => 'approve',
			'number'  => 100, // Limit for performance
		);

		$comments     = get_comments( $args );
		$field_counts = array();

		// Initialize field counts
		foreach ( $fields as $field ) {
			if ( isset( $field['id'] ) ) {
				$field_counts[ $field['id'] ] = array(
					'field_id' => $field['id'],
					'label'    => isset( $field['label'] ) ? $field['label'] : '',
					'count'    => 0,
				);
			}
		}

		// Count field usage
		foreach ( $comments as $comment ) {
			$submission_data = get_comment_meta( $comment->comment_ID, '_submission_data', true );

			if ( is_array( $submission_data ) ) {
				foreach ( $submission_data as $field_id => $value ) {
					if ( isset( $field_counts[ $field_id ] ) && ! empty( $value ) ) {
						$field_counts[ $field_id ]['count']++;
					}
				}
			}
		}

		// Sort by count
		usort(
			$field_counts,
			function ( $a, $b ) {
				return $b['count'] - $a['count'];
			}
		);

		return array_values( $field_counts );
	}

	/**
	 * Retrieves the analytics schema, conforming to JSON Schema.
	 *
	 * @since 6.7.0
	 *
	 * @return array Item schema data.
	 */
	public function get_item_schema() {
		if ( $this->schema ) {
			return $this->add_additional_fields_schema( $this->schema );
		}

		$schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'form_analytics',
			'type'       => 'object',
			'properties' => array(
				'total_submissions' => array(
					'description' => __( 'Total number of submissions.' ),
					'type'        => 'integer',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'total_views' => array(
					'description' => __( 'Total number of form views.' ),
					'type'        => 'integer',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'conversion_rate' => array(
					'description' => __( 'Form conversion rate (submissions / views).' ),
					'type'        => 'number',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'submissions_by_date' => array(
					'description' => __( 'Submissions grouped by date.' ),
					'type'        => 'array',
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'date'  => array( 'type' => 'string' ),
							'count' => array( 'type' => 'integer' ),
						),
					),
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'popular_fields' => array(
					'description' => __( 'Most frequently filled fields.' ),
					'type'        => 'array',
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'field_id' => array( 'type' => 'string' ),
							'label'    => array( 'type' => 'string' ),
							'count'    => array( 'type' => 'integer' ),
						),
					),
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'submission_sources' => array(
					'description' => __( 'Top submission sources (referers).' ),
					'type'        => 'array',
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'source' => array( 'type' => 'string' ),
							'count'  => array( 'type' => 'integer' ),
						),
					),
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
			),
		);

		$this->schema = $schema;
		return $this->add_additional_fields_schema( $this->schema );
	}
}
