<?php
/**
 * REST API: WP_REST_Form_Submissions_Controller class
 *
 * @package WordPress
 * @subpackage REST_API
 * @since 6.7.0
 */

/**
 * Core class used to manage form submissions via the REST API.
 *
 * @since 6.7.0
 *
 * @see WP_REST_Controller
 */
class WP_REST_Form_Submissions_Controller extends WP_REST_Controller {

	/**
	 * Constructor.
	 *
	 * @since 6.7.0
	 */
	public function __construct() {
		$this->namespace = 'wp/v2';
		$this->rest_base = 'forms/(?P<form_id>[\d]+)/submissions';
	}

	/**
	 * Registers the routes for form submissions.
	 *
	 * @since 6.7.0
	 *
	 * @see register_rest_route()
	 */
	public function register_routes() {
		// Collection endpoint
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => $this->get_collection_params(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
					'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::CREATABLE ),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);

		// Single submission endpoint
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<submission_id>[\d]+)',
			array(
				'args'   => array(
					'form_id' => array(
						'description' => __( 'The form ID.' ),
						'type'        => 'integer',
						'required'    => true,
					),
					'submission_id' => array(
						'description' => __( 'Unique identifier for the submission.' ),
						'type'        => 'integer',
						'required'    => true,
					),
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'delete_item_permissions_check' ),
					'args'                => array(
						'force' => array(
							'type'        => 'boolean',
							'default'     => true,
							'description' => __( 'Whether to bypass trash and force deletion.' ),
						),
					),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * Checks if a given request has access to get submissions.
	 *
	 * @since 6.7.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error True if the request has read access, WP_Error otherwise.
	 */
	public function get_items_permissions_check( $request ) {
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
				'rest_cannot_read_submissions',
				__( 'Sorry, you are not allowed to view submissions for this form.' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Retrieves all submissions for a form.
	 *
	 * @since 6.7.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function get_items( $request ) {
		$form_id = $request['form_id'];

		$args = array(
			'post_id'      => $form_id,
			'type'         => 'form_submission',
			'status'       => 'approve',
			'number'       => isset( $request['per_page'] ) ? $request['per_page'] : 10,
			'offset'       => isset( $request['page'] ) ? ( $request['page'] - 1 ) * ( isset( $request['per_page'] ) ? $request['per_page'] : 10 ) : 0,
			'orderby'      => 'comment_date_gmt',
			'order'        => 'DESC',
		);

		$comments_query = new WP_Comment_Query( $args );
		$submissions    = $comments_query->comments;

		$response_submissions = array();
		foreach ( $submissions as $submission ) {
			$data                   = $this->prepare_item_for_response( $submission, $request );
			$response_submissions[] = $this->prepare_response_for_collection( $data );
		}

		$response = rest_ensure_response( $response_submissions );

		// Get total count
		$count_args          = $args;
		$count_args['count'] = true;
		unset( $count_args['number'], $count_args['offset'] );
		$total_comments = get_comments( $count_args );

		$response->header( 'X-WP-Total', (int) $total_comments );
		$per_page = isset( $request['per_page'] ) ? $request['per_page'] : 10;
		$response->header( 'X-WP-TotalPages', (int) ceil( $total_comments / $per_page ) );

		return $response;
	}

	/**
	 * Checks if a given request has access to get a specific submission.
	 *
	 * @since 6.7.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error True if the request has read access, WP_Error otherwise.
	 */
	public function get_item_permissions_check( $request ) {
		return $this->get_items_permissions_check( $request );
	}

	/**
	 * Retrieves a single submission.
	 *
	 * @since 6.7.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function get_item( $request ) {
		$submission_id = $request['submission_id'];
		$submission    = get_comment( $submission_id );

		if ( ! $submission || 'form_submission' !== $submission->comment_type ) {
			return new WP_Error(
				'rest_submission_invalid_id',
				__( 'Invalid submission ID.' ),
				array( 'status' => 404 )
			);
		}

		if ( (int) $submission->comment_post_ID !== (int) $request['form_id'] ) {
			return new WP_Error(
				'rest_submission_form_mismatch',
				__( 'Submission does not belong to this form.' ),
				array( 'status' => 400 )
			);
		}

		$data = $this->prepare_item_for_response( $submission, $request );

		return rest_ensure_response( $data );
	}

	/**
	 * Checks if a given request has access to create a submission.
	 *
	 * @since 6.7.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error True if the request has access to create, WP_Error otherwise.
	 */
	public function create_item_permissions_check( $request ) {
		$form = get_post( $request['form_id'] );

		if ( ! $form || 'form' !== $form->post_type ) {
			return new WP_Error(
				'rest_form_invalid_id',
				__( 'Invalid form ID.' ),
				array( 'status' => 404 )
			);
		}

		// Allow public submissions (no authentication required)
		return true;
	}

	/**
	 * Creates a single submission.
	 *
	 * @since 6.7.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function create_item( $request ) {
		$form_id = $request['form_id'];

		// Validate submission data
		if ( empty( $request['data'] ) || ! is_array( $request['data'] ) ) {
			return new WP_Error(
				'rest_invalid_submission_data',
				__( 'Submission data is required.' ),
				array( 'status' => 400 )
			);
		}

		// Create comment (submission)
		$comment_data = array(
			'comment_post_ID'  => $form_id,
			'comment_type'     => 'form_submission',
			'comment_approved' => 1,
			'comment_content'  => '',
			'comment_author'   => '',
			'comment_author_email' => '',
		);

		$submission_id = wp_insert_comment( $comment_data );

		if ( ! $submission_id || is_wp_error( $submission_id ) ) {
			return new WP_Error(
				'rest_submission_create_failed',
				__( 'Failed to create submission.' ),
				array( 'status' => 500 )
			);
		}

		// Store submission data as comment meta
		add_comment_meta( $submission_id, '_submission_data', $request['data'] );

		// Store IP address
		$ip_address = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		add_comment_meta( $submission_id, '_submission_ip', $ip_address );

		// Store user agent
		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		add_comment_meta( $submission_id, '_submission_user_agent', $user_agent );

		// Store referer
		$referer = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
		add_comment_meta( $submission_id, '_submission_referer', $referer );

		// Increment submission count
		$count = get_post_meta( $form_id, '_submission_count', true );
		update_post_meta( $form_id, '_submission_count', (int) $count + 1 );

		$submission = get_comment( $submission_id );
		$data       = $this->prepare_item_for_response( $submission, $request );
		$response   = rest_ensure_response( $data );
		$response->set_status( 201 );

		return $response;
	}

	/**
	 * Checks if a given request has access to delete a submission.
	 *
	 * @since 6.7.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error True if the request has access to delete, WP_Error otherwise.
	 */
	public function delete_item_permissions_check( $request ) {
		return $this->get_items_permissions_check( $request );
	}

	/**
	 * Deletes a single submission.
	 *
	 * @since 6.7.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function delete_item( $request ) {
		$submission_id = $request['submission_id'];
		$submission    = get_comment( $submission_id );

		if ( ! $submission || 'form_submission' !== $submission->comment_type ) {
			return new WP_Error(
				'rest_submission_invalid_id',
				__( 'Invalid submission ID.' ),
				array( 'status' => 404 )
			);
		}

		if ( (int) $submission->comment_post_ID !== (int) $request['form_id'] ) {
			return new WP_Error(
				'rest_submission_form_mismatch',
				__( 'Submission does not belong to this form.' ),
				array( 'status' => 400 )
			);
		}

		$force    = (bool) $request['force'];
		$previous = $this->prepare_item_for_response( $submission, $request );

		$result = wp_delete_comment( $submission_id, $force );

		if ( ! $result ) {
			return new WP_Error(
				'rest_cannot_delete_submission',
				__( 'The submission cannot be deleted.' ),
				array( 'status' => 500 )
			);
		}

		// Decrement submission count
		$form_id = $request['form_id'];
		$count   = get_post_meta( $form_id, '_submission_count', true );
		update_post_meta( $form_id, '_submission_count', max( 0, (int) $count - 1 ) );

		$response = new WP_REST_Response();
		$response->set_data(
			array(
				'deleted'  => true,
				'previous' => $previous->get_data(),
			)
		);

		return $response;
	}

	/**
	 * Prepares a single submission output for response.
	 *
	 * @since 6.7.0
	 *
	 * @param WP_Comment      $submission Submission comment object.
	 * @param WP_REST_Request $request    Request object.
	 * @return WP_REST_Response Response object.
	 */
	public function prepare_item_for_response( $submission, $request ) {
		$submission_data = get_comment_meta( $submission->comment_ID, '_submission_data', true );
		$ip_address      = get_comment_meta( $submission->comment_ID, '_submission_ip', true );
		$user_agent      = get_comment_meta( $submission->comment_ID, '_submission_user_agent', true );
		$referer         = get_comment_meta( $submission->comment_ID, '_submission_referer', true );

		$data = array(
			'id'             => (int) $submission->comment_ID,
			'form_id'        => (int) $submission->comment_post_ID,
			'data'           => ! empty( $submission_data ) ? $submission_data : new stdClass(),
			'ip_address'     => ! empty( $ip_address ) ? $ip_address : '',
			'user_agent'     => ! empty( $user_agent ) ? $user_agent : '',
			'referer'        => ! empty( $referer ) ? $referer : '',
			'submitted_date' => mysql_to_rfc3339( $submission->comment_date ),
			'submitted_date_gmt' => mysql_to_rfc3339( $submission->comment_date_gmt ),
			'status'         => 'approved' === $submission->comment_approved ? 'read' : 'unread',
		);

		$response = new WP_REST_Response( $data );

		return apply_filters( 'rest_prepare_form_submission', $response, $submission, $request );
	}

	/**
	 * Retrieves the submission's schema, conforming to JSON Schema.
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
			'title'      => 'form_submission',
			'type'       => 'object',
			'properties' => array(
				'id' => array(
					'description' => __( 'Unique identifier for the submission.' ),
					'type'        => 'integer',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'form_id' => array(
					'description' => __( 'The parent form ID.' ),
					'type'        => 'integer',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'data' => array(
					'description' => __( 'Submission data as field_id => value pairs.' ),
					'type'        => 'object',
					'required'    => true,
					'context'     => array( 'view', 'edit' ),
				),
				'ip_address' => array(
					'description' => __( 'IP address of the submitter.' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'user_agent' => array(
					'description' => __( 'User agent of the submitter.' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'referer' => array(
					'description' => __( 'Referer URL where the form was submitted.' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'submitted_date' => array(
					'description' => __( 'The date the submission was created, in the site timezone.' ),
					'type'        => 'string',
					'format'      => 'date-time',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'submitted_date_gmt' => array(
					'description' => __( 'The date the submission was created, as GMT.' ),
					'type'        => 'string',
					'format'      => 'date-time',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'status' => array(
					'description' => __( 'The status of the submission.' ),
					'type'        => 'string',
					'enum'        => array( 'read', 'unread' ),
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
			),
		);

		$this->schema = $schema;
		return $this->add_additional_fields_schema( $this->schema );
	}

	/**
	 * Retrieves the query params for collections.
	 *
	 * @since 6.7.0
	 *
	 * @return array Collection parameters.
	 */
	public function get_collection_params() {
		$params = parent::get_collection_params();

		$params['per_page'] = array(
			'description'       => __( 'Maximum number of items to return in result set.' ),
			'type'              => 'integer',
			'default'           => 10,
			'minimum'           => 1,
			'maximum'           => 100,
			'sanitize_callback' => 'absint',
			'validate_callback' => 'rest_validate_request_arg',
		);

		$params['page'] = array(
			'description'       => __( 'Current page of the collection.' ),
			'type'              => 'integer',
			'default'           => 1,
			'minimum'           => 1,
			'sanitize_callback' => 'absint',
			'validate_callback' => 'rest_validate_request_arg',
		);

		return $params;
	}
}
