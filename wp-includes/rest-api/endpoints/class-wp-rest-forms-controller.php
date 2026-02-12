<?php
/**
 * REST API: WP_REST_Forms_Controller class
 *
 * @package WordPress
 * @subpackage REST_API
 * @since 6.7.0
 */

/**
 * Core class used to manage forms via the REST API.
 *
 * @since 6.7.0
 *
 * @see WP_REST_Controller
 */
class WP_REST_Forms_Controller extends WP_REST_Controller {

	/**
	 * Constructor.
	 *
	 * @since 6.7.0
	 */
	public function __construct() {
		$this->namespace = 'wp/v2';
		$this->rest_base = 'forms';
	}

	/**
	 * Registers the routes for forms.
	 *
	 * @since 6.7.0
	 *
	 * @see register_rest_route()
	 */
	public function register_routes() {
		// Collection endpoint: GET/POST /wp/v2/forms
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

		// Single resource endpoint: GET/PUT/DELETE /wp/v2/forms/(?P<id>[\d]+)
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			array(
				'args'   => array(
					'id' => array(
						'description' => __( 'Unique identifier for the form.' ),
						'type'        => 'integer',
					),
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
					'args'                => array(
						'context' => $this->get_context_param( array( 'default' => 'view' ) ),
					),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
					'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::EDITABLE ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'delete_item_permissions_check' ),
					'args'                => array(
						'force' => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'Whether to bypass trash and force deletion.' ),
						),
					),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * Checks if a given request has access to get forms.
	 *
	 * @since 6.7.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error True if the request has read access, WP_Error otherwise.
	 */
	public function get_items_permissions_check( $request ) {
		if ( 'edit' === $request['context'] && ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error(
				'rest_forbidden_context',
				__( 'Sorry, you are not allowed to edit forms.' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error(
				'rest_cannot_view_forms',
				__( 'Sorry, you are not allowed to view forms.' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Retrieves a collection of forms.
	 *
	 * @since 6.7.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function get_items( $request ) {
		$args = array(
			'post_type'   => 'form',
			'post_status' => 'any',
		);

		// Handle pagination
		if ( isset( $request['per_page'] ) ) {
			$args['posts_per_page'] = $request['per_page'];
		}

		if ( isset( $request['page'] ) ) {
			$args['paged'] = $request['page'];
		}

		// Handle search
		if ( ! empty( $request['search'] ) ) {
			$args['s'] = $request['search'];
		}

		// Handle author filter
		if ( ! empty( $request['author'] ) ) {
			$args['author__in'] = (array) $request['author'];
		}

		if ( ! empty( $request['author_exclude'] ) ) {
			$args['author__not_in'] = (array) $request['author_exclude'];
		}

		// Handle ordering
		if ( ! empty( $request['orderby'] ) ) {
			$args['orderby'] = $request['orderby'];
		}

		if ( ! empty( $request['order'] ) ) {
			$args['order'] = $request['order'];
		}

		// Handle include/exclude
		if ( ! empty( $request['include'] ) ) {
			$args['post__in'] = (array) $request['include'];
		}

		if ( ! empty( $request['exclude'] ) ) {
			$args['post__not_in'] = (array) $request['exclude'];
		}

		$query = new WP_Query( $args );

		$forms = array();
		foreach ( $query->posts as $post ) {
			$data    = $this->prepare_item_for_response( $post, $request );
			$forms[] = $this->prepare_response_for_collection( $data );
		}

		$response = rest_ensure_response( $forms );

		// Add pagination headers
		$response->header( 'X-WP-Total', (int) $query->found_posts );
		$response->header( 'X-WP-TotalPages', (int) $query->max_num_pages );

		return $response;
	}

	/**
	 * Checks if a given request has access to get a specific form.
	 *
	 * @since 6.7.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error True if the request has read access, WP_Error otherwise.
	 */
	public function get_item_permissions_check( $request ) {
		$form = $this->get_form( $request['id'] );

		if ( is_wp_error( $form ) ) {
			return $form;
		}

		if ( 'edit' === $request['context'] && ! current_user_can( 'edit_post', $form->ID ) ) {
			return new WP_Error(
				'rest_forbidden_context',
				__( 'Sorry, you are not allowed to edit this form.' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		if ( ! current_user_can( 'read_post', $form->ID ) && ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error(
				'rest_cannot_view_form',
				__( 'Sorry, you are not allowed to view this form.' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Retrieves one form from the collection.
	 *
	 * @since 6.7.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function get_item( $request ) {
		$form = $this->get_form( $request['id'] );

		if ( is_wp_error( $form ) ) {
			return $form;
		}

		$data     = $this->prepare_item_for_response( $form, $request );
		$response = rest_ensure_response( $data );

		return $response;
	}

	/**
	 * Checks if a given request has access to create a form.
	 *
	 * @since 6.7.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error True if the request has access to create, WP_Error otherwise.
	 */
	public function create_item_permissions_check( $request ) {
		if ( ! current_user_can( 'publish_posts' ) ) {
			return new WP_Error(
				'rest_cannot_create_form',
				__( 'Sorry, you are not allowed to create forms.' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		if ( ! empty( $request['author'] ) && get_current_user_id() !== $request['author'] && ! current_user_can( 'edit_others_posts' ) ) {
			return new WP_Error(
				'rest_cannot_edit_others',
				__( 'Sorry, you are not allowed to create forms as this user.' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Creates a single form.
	 *
	 * @since 6.7.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function create_item( $request ) {
		$prepared_form = $this->prepare_item_for_database( $request );

		if ( is_wp_error( $prepared_form ) ) {
			return $prepared_form;
		}

		$prepared_form['post_type'] = 'form';

		// Set author to current user if not specified
		if ( empty( $prepared_form['post_author'] ) ) {
			$prepared_form['post_author'] = get_current_user_id();
		}

		// Set default status
		if ( empty( $prepared_form['post_status'] ) ) {
			$prepared_form['post_status'] = 'draft';
		}

		$form_id = wp_insert_post( wp_slash( $prepared_form ), true );

		if ( is_wp_error( $form_id ) ) {
			if ( 'db_insert_error' === $form_id->get_error_code() ) {
				$form_id->add_data( array( 'status' => 500 ) );
			} else {
				$form_id->add_data( array( 'status' => 400 ) );
			}
			return $form_id;
		}

		$form = get_post( $form_id );

		// Handle meta fields
		if ( isset( $request['form_settings'] ) ) {
			update_post_meta( $form_id, '_form_settings', $request['form_settings'] );
		}

		if ( isset( $request['form_status'] ) ) {
			update_post_meta( $form_id, '_form_status', sanitize_key( $request['form_status'] ) );
		}

		// Initialize submission count
		update_post_meta( $form_id, '_submission_count', 0 );
		update_post_meta( $form_id, '_form_version', 1 );

		do_action( 'rest_insert_form', $form, $request, true );

		$request->set_param( 'context', 'edit' );
		$response = $this->prepare_item_for_response( $form, $request );
		$response = rest_ensure_response( $response );
		$response->set_status( 201 );
		$response->header( 'Location', rest_url( sprintf( '%s/%s/%d', $this->namespace, $this->rest_base, $form_id ) ) );

		return $response;
	}

	/**
	 * Checks if a given request has access to update a form.
	 *
	 * @since 6.7.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error True if the request has access to update, WP_Error otherwise.
	 */
	public function update_item_permissions_check( $request ) {
		$form = $this->get_form( $request['id'] );

		if ( is_wp_error( $form ) ) {
			return $form;
		}

		if ( ! current_user_can( 'edit_post', $form->ID ) ) {
			return new WP_Error(
				'rest_cannot_update_form',
				__( 'Sorry, you are not allowed to update this form.' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		if ( ! empty( $request['author'] ) && get_current_user_id() !== $request['author'] && ! current_user_can( 'edit_others_posts' ) ) {
			return new WP_Error(
				'rest_cannot_edit_others',
				__( 'Sorry, you are not allowed to update forms as this user.' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Updates a single form.
	 *
	 * @since 6.7.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function update_item( $request ) {
		$form = $this->get_form( $request['id'] );

		if ( is_wp_error( $form ) ) {
			return $form;
		}

		$prepared_form = $this->prepare_item_for_database( $request );

		if ( is_wp_error( $prepared_form ) ) {
			return $prepared_form;
		}

		$prepared_form['ID'] = $form->ID;

		$form_id = wp_update_post( wp_slash( $prepared_form ), true );

		if ( is_wp_error( $form_id ) ) {
			if ( 'db_update_error' === $form_id->get_error_code() ) {
				$form_id->add_data( array( 'status' => 500 ) );
			} else {
				$form_id->add_data( array( 'status' => 400 ) );
			}
			return $form_id;
		}

		$form = get_post( $form_id );

		// Handle meta fields
		if ( isset( $request['form_settings'] ) ) {
			update_post_meta( $form_id, '_form_settings', $request['form_settings'] );
		}

		if ( isset( $request['form_status'] ) ) {
			update_post_meta( $form_id, '_form_status', sanitize_key( $request['form_status'] ) );
		}

		do_action( 'rest_update_form', $form, $request, false );

		$request->set_param( 'context', 'edit' );
		$response = $this->prepare_item_for_response( $form, $request );

		return rest_ensure_response( $response );
	}

	/**
	 * Checks if a given request has access to delete a form.
	 *
	 * @since 6.7.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error True if the request has access to delete, WP_Error otherwise.
	 */
	public function delete_item_permissions_check( $request ) {
		$form = $this->get_form( $request['id'] );

		if ( is_wp_error( $form ) ) {
			return $form;
		}

		if ( ! current_user_can( 'delete_post', $form->ID ) ) {
			return new WP_Error(
				'rest_cannot_delete_form',
				__( 'Sorry, you are not allowed to delete this form.' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Deletes a single form.
	 *
	 * @since 6.7.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function delete_item( $request ) {
		$form = $this->get_form( $request['id'] );

		if ( is_wp_error( $form ) ) {
			return $form;
		}

		$id          = $form->ID;
		$force       = (bool) $request['force'];
		$supports_trash = ( EMPTY_TRASH_DAYS > 0 );

		$request->set_param( 'context', 'edit' );

		if ( $force ) {
			$previous = $this->prepare_item_for_response( $form, $request );
			$result   = wp_delete_post( $id, true );
			$response = new WP_REST_Response();
			$response->set_data(
				array(
					'deleted'  => true,
					'previous' => $previous->get_data(),
				)
			);
		} else {
			if ( ! $supports_trash ) {
				return new WP_Error(
					'rest_trash_not_supported',
					sprintf( __( "The form does not support trashing. Set '%s' to delete." ), 'force=true' ),
					array( 'status' => 501 )
				);
			}

			if ( 'trash' === $form->post_status ) {
				return new WP_Error(
					'rest_already_trashed',
					__( 'The form has already been deleted.' ),
					array( 'status' => 410 )
				);
			}

			$result = wp_trash_post( $id );
			$form   = get_post( $id );
			$response = $this->prepare_item_for_response( $form, $request );
		}

		if ( ! $result ) {
			return new WP_Error(
				'rest_cannot_delete',
				__( 'The form cannot be deleted.' ),
				array( 'status' => 500 )
			);
		}

		do_action( 'rest_delete_form', $form, $response, $request );

		return $response;
	}

	/**
	 * Prepares a single form for creation or update.
	 *
	 * @since 6.7.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return array|WP_Error Prepared form array or WP_Error.
	 */
	protected function prepare_item_for_database( $request ) {
		$prepared_form = array();

		if ( isset( $request['title'] ) ) {
			if ( is_string( $request['title'] ) ) {
				$prepared_form['post_title'] = sanitize_text_field( $request['title'] );
			} elseif ( ! empty( $request['title']['raw'] ) ) {
				$prepared_form['post_title'] = sanitize_text_field( $request['title']['raw'] );
			}
		}

		if ( isset( $request['description'] ) ) {
			if ( is_string( $request['description'] ) ) {
				$prepared_form['post_content'] = wp_kses_post( $request['description'] );
			} elseif ( isset( $request['description']['raw'] ) ) {
				$prepared_form['post_content'] = wp_kses_post( $request['description']['raw'] );
			}
		}

		if ( isset( $request['status'] ) ) {
			$prepared_form['post_status'] = sanitize_key( $request['status'] );
		}

		if ( isset( $request['author'] ) ) {
			$user_obj = get_userdata( $request['author'] );
			if ( ! $user_obj ) {
				return new WP_Error(
					'rest_invalid_author',
					__( 'Invalid author ID.' ),
					array( 'status' => 400 )
				);
			}
			$prepared_form['post_author'] = $request['author'];
		}

		return $prepared_form;
	}

	/**
	 * Prepares a single form output for response.
	 *
	 * @since 6.7.0
	 *
	 * @param WP_Post         $form    Form post object.
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response object.
	 */
	public function prepare_item_for_response( $form, $request ) {
		$fields = $this->get_fields_for_response( $request );
		$data   = array();

		if ( rest_is_field_included( 'id', $fields ) ) {
			$data['id'] = $form->ID;
		}

		if ( rest_is_field_included( 'title', $fields ) ) {
			$data['title'] = array(
				'raw'      => $form->post_title,
				'rendered' => get_the_title( $form->ID ),
			);
		}

		if ( rest_is_field_included( 'description', $fields ) ) {
			$data['description'] = array(
				'raw'      => $form->post_content,
				'rendered' => apply_filters( 'the_content', $form->post_content ),
			);
		}

		if ( rest_is_field_included( 'status', $fields ) ) {
			$data['status'] = $form->post_status;
		}

		if ( rest_is_field_included( 'author', $fields ) ) {
			$data['author'] = (int) $form->post_author;
		}

		if ( rest_is_field_included( 'form_settings', $fields ) ) {
			$data['form_settings'] = get_post_meta( $form->ID, '_form_settings', true );
			if ( empty( $data['form_settings'] ) ) {
				$data['form_settings'] = new stdClass();
			}
		}

		if ( rest_is_field_included( 'form_status', $fields ) ) {
			$form_status = get_post_meta( $form->ID, '_form_status', true );
			$data['form_status'] = ! empty( $form_status ) ? $form_status : 'active';
		}

		if ( rest_is_field_included( 'submission_count', $fields ) ) {
			$data['submission_count'] = (int) get_post_meta( $form->ID, '_submission_count', true );
		}

		if ( rest_is_field_included( 'created_date', $fields ) ) {
			$data['created_date'] = mysql_to_rfc3339( $form->post_date );
		}

		if ( rest_is_field_included( 'created_date_gmt', $fields ) ) {
			$data['created_date_gmt'] = mysql_to_rfc3339( $form->post_date_gmt );
		}

		if ( rest_is_field_included( 'modified_date', $fields ) ) {
			$data['modified_date'] = mysql_to_rfc3339( $form->post_modified );
		}

		if ( rest_is_field_included( 'modified_date_gmt', $fields ) ) {
			$data['modified_date_gmt'] = mysql_to_rfc3339( $form->post_modified_gmt );
		}

		$context = ! empty( $request['context'] ) ? $request['context'] : 'view';
		$data    = $this->filter_response_by_context( $data, $context );

		$response = new WP_REST_Response( $data );
		$response = rest_ensure_response( $response );

		return apply_filters( 'rest_prepare_form', $response, $form, $request );
	}

	/**
	 * Retrieves the form's schema, conforming to JSON Schema.
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
			'title'      => 'form',
			'type'       => 'object',
			'properties' => array(
				'id'    => array(
					'description' => __( 'Unique identifier for the form.' ),
					'type'        => 'integer',
					'context'     => array( 'view', 'edit', 'embed' ),
					'readonly'    => true,
				),
				'title' => array(
					'description' => __( 'The title of the form.' ),
					'type'        => array( 'string', 'object' ),
					'context'     => array( 'view', 'edit' ),
					'properties'  => array(
						'raw' => array(
							'description' => __( 'Title for the form, as it exists in the database.' ),
							'type'        => 'string',
							'context'     => array( 'edit' ),
						),
						'rendered' => array(
							'description' => __( 'HTML title for the form, transformed for display.' ),
							'type'        => 'string',
							'context'     => array( 'view', 'edit' ),
							'readonly'    => true,
						),
					),
					'arg_options' => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
				'description' => array(
					'description' => __( 'The description of the form.' ),
					'type'        => array( 'string', 'object' ),
					'context'     => array( 'view', 'edit' ),
					'properties'  => array(
						'raw' => array(
							'description' => __( 'Description for the form, as it exists in the database.' ),
							'type'        => 'string',
							'context'     => array( 'edit' ),
						),
						'rendered' => array(
							'description' => __( 'HTML description for the form, transformed for display.' ),
							'type'        => 'string',
							'context'     => array( 'view', 'edit' ),
							'readonly'    => true,
						),
					),
					'arg_options' => array(
						'sanitize_callback' => 'wp_kses_post',
					),
				),
				'status' => array(
					'description' => __( 'The post status for the form.' ),
					'type'        => 'string',
					'enum'        => array( 'publish', 'draft', 'pending', 'private', 'trash' ),
					'context'     => array( 'view', 'edit' ),
					'arg_options' => array(
						'sanitize_callback' => 'sanitize_key',
					),
				),
				'author' => array(
					'description' => __( 'The ID for the author of the form.' ),
					'type'        => 'integer',
					'context'     => array( 'view', 'edit' ),
				),
				'form_settings' => array(
					'description' => __( 'Form settings configuration.' ),
					'type'        => 'object',
					'context'     => array( 'view', 'edit' ),
					'properties'  => array(
						'redirect_url' => array(
							'type'        => 'string',
							'description' => __( 'URL to redirect to after form submission.' ),
						),
						'submit_button_text' => array(
							'type'        => 'string',
							'description' => __( 'Text for the submit button.' ),
						),
						'success_message' => array(
							'type'        => 'string',
							'description' => __( 'Message to display after successful submission.' ),
						),
					),
				),
				'form_status' => array(
					'description' => __( 'The status of the form (active, inactive, archived).' ),
					'type'        => 'string',
					'enum'        => array( 'active', 'inactive', 'archived' ),
					'context'     => array( 'view', 'edit' ),
					'default'     => 'active',
					'arg_options' => array(
						'sanitize_callback' => 'sanitize_key',
					),
				),
				'submission_count' => array(
					'description' => __( 'Total number of submissions for this form.' ),
					'type'        => 'integer',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'created_date' => array(
					'description' => __( 'The date the form was created, in the site timezone.' ),
					'type'        => 'string',
					'format'      => 'date-time',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'created_date_gmt' => array(
					'description' => __( 'The date the form was created, as GMT.' ),
					'type'        => 'string',
					'format'      => 'date-time',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'modified_date' => array(
					'description' => __( 'The date the form was last modified, in the site timezone.' ),
					'type'        => 'string',
					'format'      => 'date-time',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'modified_date_gmt' => array(
					'description' => __( 'The date the form was last modified, as GMT.' ),
					'type'        => 'string',
					'format'      => 'date-time',
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

		$params['context']['default'] = 'view';

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

		$params['search'] = array(
			'description'       => __( 'Limit results to those matching a string.' ),
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'validate_callback' => 'rest_validate_request_arg',
		);

		$params['author'] = array(
			'description'       => __( 'Limit result set to forms assigned to specific authors.' ),
			'type'              => 'array',
			'items'             => array( 'type' => 'integer' ),
			'default'           => array(),
			'sanitize_callback' => 'wp_parse_id_list',
			'validate_callback' => 'rest_validate_request_arg',
		);

		$params['author_exclude'] = array(
			'description'       => __( 'Ensure result set excludes forms assigned to specific authors.' ),
			'type'              => 'array',
			'items'             => array( 'type' => 'integer' ),
			'default'           => array(),
			'sanitize_callback' => 'wp_parse_id_list',
			'validate_callback' => 'rest_validate_request_arg',
		);

		$params['include'] = array(
			'description'       => __( 'Limit result set to specific IDs.' ),
			'type'              => 'array',
			'items'             => array( 'type' => 'integer' ),
			'default'           => array(),
			'sanitize_callback' => 'wp_parse_id_list',
			'validate_callback' => 'rest_validate_request_arg',
		);

		$params['exclude'] = array(
			'description'       => __( 'Ensure result set excludes specific IDs.' ),
			'type'              => 'array',
			'items'             => array( 'type' => 'integer' ),
			'default'           => array(),
			'sanitize_callback' => 'wp_parse_id_list',
			'validate_callback' => 'rest_validate_request_arg',
		);

		$params['orderby'] = array(
			'description'       => __( 'Sort collection by form attribute.' ),
			'type'              => 'string',
			'default'           => 'date',
			'enum'              => array( 'date', 'id', 'title', 'modified', 'author' ),
			'sanitize_callback' => 'sanitize_key',
			'validate_callback' => 'rest_validate_request_arg',
		);

		$params['order'] = array(
			'description'       => __( 'Order sort attribute ascending or descending.' ),
			'type'              => 'string',
			'default'           => 'desc',
			'enum'              => array( 'asc', 'desc' ),
			'sanitize_callback' => 'sanitize_key',
			'validate_callback' => 'rest_validate_request_arg',
		);

		return $params;
	}

	/**
	 * Retrieves a form by ID.
	 *
	 * @since 6.7.0
	 *
	 * @param int $id Form ID.
	 * @return WP_Post|WP_Error Form post object if ID is valid, WP_Error otherwise.
	 */
	protected function get_form( $id ) {
		$error = new WP_Error(
			'rest_form_invalid_id',
			__( 'Invalid form ID.' ),
			array( 'status' => 404 )
		);

		if ( (int) $id <= 0 ) {
			return $error;
		}

		$form = get_post( (int) $id );

		if ( empty( $form ) || empty( $form->ID ) || 'form' !== $form->post_type ) {
			return $error;
		}

		return $form;
	}
}
