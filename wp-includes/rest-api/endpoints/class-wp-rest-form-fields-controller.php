<?php
/**
 * REST API: WP_REST_Form_Fields_Controller class
 *
 * @package WordPress
 * @subpackage REST_API
 * @since 6.7.0
 */

/**
 * Core class used to manage form fields via the REST API.
 *
 * @since 6.7.0
 *
 * @see WP_REST_Controller
 */
class WP_REST_Form_Fields_Controller extends WP_REST_Controller {

	/**
	 * Constructor.
	 *
	 * @since 6.7.0
	 */
	public function __construct() {
		$this->namespace = 'wp/v2';
		$this->rest_base = 'forms/(?P<form_id>[\d]+)/fields';
	}

	/**
	 * Registers the routes for form fields.
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
					'args'                => array(
						'form_id' => array(
							'description' => __( 'The form ID.' ),
							'type'        => 'integer',
							'required'    => true,
						),
					),
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

		// Single field endpoint
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<field_id>[a-zA-Z0-9_-]+)',
			array(
				'args'   => array(
					'form_id' => array(
						'description' => __( 'The form ID.' ),
						'type'        => 'integer',
						'required'    => true,
					),
					'field_id' => array(
						'description' => __( 'Unique identifier for the field.' ),
						'type'        => 'string',
						'required'    => true,
					),
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
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
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * Checks if a given request has access to get fields.
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
				'rest_cannot_read_fields',
				__( 'Sorry, you are not allowed to view fields for this form.' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Retrieves all fields for a form.
	 *
	 * @since 6.7.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function get_items( $request ) {
		$form_id = $request['form_id'];
		$fields  = get_post_meta( $form_id, '_form_fields', true );

		if ( empty( $fields ) || ! is_array( $fields ) ) {
			$fields = array();
		}

		$response_fields = array();
		foreach ( $fields as $field ) {
			$data              = $this->prepare_item_for_response( $field, $request );
			$response_fields[] = $this->prepare_response_for_collection( $data );
		}

		return rest_ensure_response( $response_fields );
	}

	/**
	 * Checks if a given request has access to get a specific field.
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
	 * Retrieves a single field.
	 *
	 * @since 6.7.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function get_item( $request ) {
		$form_id  = $request['form_id'];
		$field_id = $request['field_id'];
		$fields   = get_post_meta( $form_id, '_form_fields', true );

		if ( empty( $fields ) || ! is_array( $fields ) ) {
			return new WP_Error(
				'rest_field_invalid_id',
				__( 'Invalid field ID.' ),
				array( 'status' => 404 )
			);
		}

		$field = null;
		foreach ( $fields as $f ) {
			if ( isset( $f['id'] ) && $f['id'] === $field_id ) {
				$field = $f;
				break;
			}
		}

		if ( ! $field ) {
			return new WP_Error(
				'rest_field_invalid_id',
				__( 'Invalid field ID.' ),
				array( 'status' => 404 )
			);
		}

		$data = $this->prepare_item_for_response( $field, $request );

		return rest_ensure_response( $data );
	}

	/**
	 * Checks if a given request has access to create a field.
	 *
	 * @since 6.7.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error True if the request has access to create, WP_Error otherwise.
	 */
	public function create_item_permissions_check( $request ) {
		return $this->get_items_permissions_check( $request );
	}

	/**
	 * Creates a single field.
	 *
	 * @since 6.7.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function create_item( $request ) {
		$form_id = $request['form_id'];
		$fields  = get_post_meta( $form_id, '_form_fields', true );

		if ( empty( $fields ) || ! is_array( $fields ) ) {
			$fields = array();
		}

		$new_field = $this->prepare_item_for_database( $request );

		if ( is_wp_error( $new_field ) ) {
			return $new_field;
		}

		// Generate unique ID if not provided
		if ( empty( $new_field['id'] ) ) {
			$new_field['id'] = 'field_' . wp_generate_password( 8, false );
		}

		// Check for duplicate ID
		foreach ( $fields as $field ) {
			if ( isset( $field['id'] ) && $field['id'] === $new_field['id'] ) {
				return new WP_Error(
					'rest_field_duplicate_id',
					__( 'A field with this ID already exists.' ),
					array( 'status' => 400 )
				);
			}
		}

		$fields[] = $new_field;
		update_post_meta( $form_id, '_form_fields', $fields );

		$data = $this->prepare_item_for_response( $new_field, $request );
		$response = rest_ensure_response( $data );
		$response->set_status( 201 );

		return $response;
	}

	/**
	 * Checks if a given request has access to update a field.
	 *
	 * @since 6.7.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error True if the request has access to update, WP_Error otherwise.
	 */
	public function update_item_permissions_check( $request ) {
		return $this->get_items_permissions_check( $request );
	}

	/**
	 * Updates a single field.
	 *
	 * @since 6.7.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function update_item( $request ) {
		$form_id  = $request['form_id'];
		$field_id = $request['field_id'];
		$fields   = get_post_meta( $form_id, '_form_fields', true );

		if ( empty( $fields ) || ! is_array( $fields ) ) {
			return new WP_Error(
				'rest_field_invalid_id',
				__( 'Invalid field ID.' ),
				array( 'status' => 404 )
			);
		}

		$field_index = null;
		foreach ( $fields as $index => $field ) {
			if ( isset( $field['id'] ) && $field['id'] === $field_id ) {
				$field_index = $index;
				break;
			}
		}

		if ( null === $field_index ) {
			return new WP_Error(
				'rest_field_invalid_id',
				__( 'Invalid field ID.' ),
				array( 'status' => 404 )
			);
		}

		$updated_field = $this->prepare_item_for_database( $request, $fields[ $field_index ] );

		if ( is_wp_error( $updated_field ) ) {
			return $updated_field;
		}

		// Preserve ID
		$updated_field['id'] = $field_id;

		$fields[ $field_index ] = $updated_field;
		update_post_meta( $form_id, '_form_fields', $fields );

		$data = $this->prepare_item_for_response( $updated_field, $request );

		return rest_ensure_response( $data );
	}

	/**
	 * Checks if a given request has access to delete a field.
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
	 * Deletes a single field.
	 *
	 * @since 6.7.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function delete_item( $request ) {
		$form_id  = $request['form_id'];
		$field_id = $request['field_id'];
		$fields   = get_post_meta( $form_id, '_form_fields', true );

		if ( empty( $fields ) || ! is_array( $fields ) ) {
			return new WP_Error(
				'rest_field_invalid_id',
				__( 'Invalid field ID.' ),
				array( 'status' => 404 )
			);
		}

		$field_index = null;
		$field_data  = null;
		foreach ( $fields as $index => $field ) {
			if ( isset( $field['id'] ) && $field['id'] === $field_id ) {
				$field_index = $index;
				$field_data  = $field;
				break;
			}
		}

		if ( null === $field_index ) {
			return new WP_Error(
				'rest_field_invalid_id',
				__( 'Invalid field ID.' ),
				array( 'status' => 404 )
			);
		}

		$previous = $this->prepare_item_for_response( $field_data, $request );

		array_splice( $fields, $field_index, 1 );
		update_post_meta( $form_id, '_form_fields', $fields );

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
	 * Prepares a single field for creation or update.
	 *
	 * @since 6.7.0
	 *
	 * @param WP_REST_Request $request       Request object.
	 * @param array           $existing_field Optional. Existing field data for updates.
	 * @return array|WP_Error Prepared field array or WP_Error.
	 */
	protected function prepare_item_for_database( $request, $existing_field = array() ) {
		$field = $existing_field;

		if ( isset( $request['type'] ) ) {
			$valid_types = array( 'text', 'email', 'textarea', 'select', 'checkbox', 'radio', 'number', 'date', 'file' );
			if ( ! in_array( $request['type'], $valid_types, true ) ) {
				return new WP_Error(
					'rest_invalid_field_type',
					__( 'Invalid field type.' ),
					array( 'status' => 400 )
				);
			}
			$field['type'] = sanitize_key( $request['type'] );
		}

		if ( isset( $request['label'] ) ) {
			$field['label'] = sanitize_text_field( $request['label'] );
		}

		if ( isset( $request['placeholder'] ) ) {
			$field['placeholder'] = sanitize_text_field( $request['placeholder'] );
		}

		if ( isset( $request['default_value'] ) ) {
			$field['default_value'] = sanitize_text_field( $request['default_value'] );
		}

		if ( isset( $request['required'] ) ) {
			$field['required'] = (bool) $request['required'];
		}

		if ( isset( $request['validation_rules'] ) ) {
			$field['validation_rules'] = $request['validation_rules'];
		}

		if ( isset( $request['options'] ) ) {
			if ( is_array( $request['options'] ) ) {
				$field['options'] = array_map( 'sanitize_text_field', $request['options'] );
			}
		}

		if ( isset( $request['order'] ) ) {
			$field['order'] = absint( $request['order'] );
		}

		// Validate required label
		if ( empty( $field['label'] ) ) {
			return new WP_Error(
				'rest_field_missing_label',
				__( 'Field label is required.' ),
				array( 'status' => 400 )
			);
		}

		// Validate required type
		if ( empty( $field['type'] ) ) {
			return new WP_Error(
				'rest_field_missing_type',
				__( 'Field type is required.' ),
				array( 'status' => 400 )
			);
		}

		return $field;
	}

	/**
	 * Prepares a single field output for response.
	 *
	 * @since 6.7.0
	 *
	 * @param array           $field   Field data.
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response object.
	 */
	public function prepare_item_for_response( $field, $request ) {
		$data = array(
			'id'               => isset( $field['id'] ) ? $field['id'] : '',
			'form_id'          => (int) $request['form_id'],
			'type'             => isset( $field['type'] ) ? $field['type'] : 'text',
			'label'            => isset( $field['label'] ) ? $field['label'] : '',
			'placeholder'      => isset( $field['placeholder'] ) ? $field['placeholder'] : '',
			'default_value'    => isset( $field['default_value'] ) ? $field['default_value'] : '',
			'required'         => isset( $field['required'] ) ? (bool) $field['required'] : false,
			'validation_rules' => isset( $field['validation_rules'] ) ? $field['validation_rules'] : new stdClass(),
			'options'          => isset( $field['options'] ) ? $field['options'] : array(),
			'order'            => isset( $field['order'] ) ? (int) $field['order'] : 0,
		);

		$response = new WP_REST_Response( $data );

		return apply_filters( 'rest_prepare_form_field', $response, $field, $request );
	}

	/**
	 * Retrieves the field's schema, conforming to JSON Schema.
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
			'title'      => 'form_field',
			'type'       => 'object',
			'properties' => array(
				'id' => array(
					'description' => __( 'Unique identifier for the field.' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'form_id' => array(
					'description' => __( 'The parent form ID.' ),
					'type'        => 'integer',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'type' => array(
					'description' => __( 'The field type.' ),
					'type'        => 'string',
					'enum'        => array( 'text', 'email', 'textarea', 'select', 'checkbox', 'radio', 'number', 'date', 'file' ),
					'required'    => true,
					'context'     => array( 'view', 'edit' ),
				),
				'label' => array(
					'description'  => __( 'The field label.' ),
					'type'         => 'string',
					'required'     => true,
					'context'      => array( 'view', 'edit' ),
					'arg_options'  => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
				'placeholder' => array(
					'description' => __( 'Placeholder text for the field.' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
					'arg_options' => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
				'default_value' => array(
					'description' => __( 'Default value for the field.' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
					'arg_options' => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
				'required' => array(
					'description' => __( 'Whether the field is required.' ),
					'type'        => 'boolean',
					'default'     => false,
					'context'     => array( 'view', 'edit' ),
				),
				'validation_rules' => array(
					'description' => __( 'Validation rules for the field.' ),
					'type'        => 'object',
					'context'     => array( 'view', 'edit' ),
					'properties'  => array(
						'min'     => array( 'type' => array( 'integer', 'number' ) ),
						'max'     => array( 'type' => array( 'integer', 'number' ) ),
						'pattern' => array( 'type' => 'string' ),
					),
				),
				'options' => array(
					'description' => __( 'Options for select, checkbox, or radio fields.' ),
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'context'     => array( 'view', 'edit' ),
				),
				'order' => array(
					'description' => __( 'Display order of the field.' ),
					'type'        => 'integer',
					'default'     => 0,
					'context'     => array( 'view', 'edit' ),
				),
			),
		);

		$this->schema = $schema;
		return $this->add_additional_fields_schema( $this->schema );
	}
}
