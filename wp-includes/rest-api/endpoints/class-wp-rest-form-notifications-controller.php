<?php
/**
 * REST API: WP_REST_Form_Notifications_Controller class
 *
 * @package WordPress
 * @subpackage REST_API
 * @since 6.7.0
 */

/**
 * Core class used to manage form notifications via the REST API.
 *
 * @since 6.7.0
 *
 * @see WP_REST_Controller
 */
class WP_REST_Form_Notifications_Controller extends WP_REST_Controller {

	/**
	 * Constructor.
	 *
	 * @since 6.7.0
	 */
	public function __construct() {
		$this->namespace = 'wp/v2';
		$this->rest_base = 'forms/(?P<form_id>[\d]+)/notifications';
	}

	/**
	 * Registers the routes for form notifications.
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

		// Single notification endpoint
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<notification_id>[a-zA-Z0-9_-]+)',
			array(
				'args'   => array(
					'form_id' => array(
						'description' => __( 'The form ID.' ),
						'type'        => 'integer',
						'required'    => true,
					),
					'notification_id' => array(
						'description' => __( 'Unique identifier for the notification.' ),
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
	 * Checks if a given request has access to get notifications.
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
				'rest_cannot_read_notifications',
				__( 'Sorry, you are not allowed to view notifications for this form.' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Retrieves all notifications for a form.
	 *
	 * @since 6.7.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function get_items( $request ) {
		$form_id       = $request['form_id'];
		$notifications = get_post_meta( $form_id, '_form_notifications', true );

		if ( empty( $notifications ) || ! is_array( $notifications ) ) {
			$notifications = array();
		}

		$response_notifications = array();
		foreach ( $notifications as $notification ) {
			$data                     = $this->prepare_item_for_response( $notification, $request );
			$response_notifications[] = $this->prepare_response_for_collection( $data );
		}

		return rest_ensure_response( $response_notifications );
	}

	/**
	 * Checks if a given request has access to get a specific notification.
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
	 * Retrieves a single notification.
	 *
	 * @since 6.7.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function get_item( $request ) {
		$form_id         = $request['form_id'];
		$notification_id = $request['notification_id'];
		$notifications   = get_post_meta( $form_id, '_form_notifications', true );

		if ( empty( $notifications ) || ! is_array( $notifications ) ) {
			return new WP_Error(
				'rest_notification_invalid_id',
				__( 'Invalid notification ID.' ),
				array( 'status' => 404 )
			);
		}

		$notification = null;
		foreach ( $notifications as $n ) {
			if ( isset( $n['id'] ) && $n['id'] === $notification_id ) {
				$notification = $n;
				break;
			}
		}

		if ( ! $notification ) {
			return new WP_Error(
				'rest_notification_invalid_id',
				__( 'Invalid notification ID.' ),
				array( 'status' => 404 )
			);
		}

		$data = $this->prepare_item_for_response( $notification, $request );

		return rest_ensure_response( $data );
	}

	/**
	 * Checks if a given request has access to create a notification.
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
	 * Creates a single notification.
	 *
	 * @since 6.7.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function create_item( $request ) {
		$form_id       = $request['form_id'];
		$notifications = get_post_meta( $form_id, '_form_notifications', true );

		if ( empty( $notifications ) || ! is_array( $notifications ) ) {
			$notifications = array();
		}

		$new_notification = $this->prepare_item_for_database( $request );

		if ( is_wp_error( $new_notification ) ) {
			return $new_notification;
		}

		// Generate unique ID if not provided
		if ( empty( $new_notification['id'] ) ) {
			$new_notification['id'] = 'notification_' . wp_generate_password( 8, false );
		}

		// Check for duplicate ID
		foreach ( $notifications as $notification ) {
			if ( isset( $notification['id'] ) && $notification['id'] === $new_notification['id'] ) {
				return new WP_Error(
					'rest_notification_duplicate_id',
					__( 'A notification with this ID already exists.' ),
					array( 'status' => 400 )
				);
			}
		}

		$notifications[] = $new_notification;
		update_post_meta( $form_id, '_form_notifications', $notifications );

		$data     = $this->prepare_item_for_response( $new_notification, $request );
		$response = rest_ensure_response( $data );
		$response->set_status( 201 );

		return $response;
	}

	/**
	 * Checks if a given request has access to update a notification.
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
	 * Updates a single notification.
	 *
	 * @since 6.7.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function update_item( $request ) {
		$form_id         = $request['form_id'];
		$notification_id = $request['notification_id'];
		$notifications   = get_post_meta( $form_id, '_form_notifications', true );

		if ( empty( $notifications ) || ! is_array( $notifications ) ) {
			return new WP_Error(
				'rest_notification_invalid_id',
				__( 'Invalid notification ID.' ),
				array( 'status' => 404 )
			);
		}

		$notification_index = null;
		foreach ( $notifications as $index => $notification ) {
			if ( isset( $notification['id'] ) && $notification['id'] === $notification_id ) {
				$notification_index = $index;
				break;
			}
		}

		if ( null === $notification_index ) {
			return new WP_Error(
				'rest_notification_invalid_id',
				__( 'Invalid notification ID.' ),
				array( 'status' => 404 )
			);
		}

		$updated_notification = $this->prepare_item_for_database( $request, $notifications[ $notification_index ] );

		if ( is_wp_error( $updated_notification ) ) {
			return $updated_notification;
		}

		// Preserve ID
		$updated_notification['id'] = $notification_id;

		$notifications[ $notification_index ] = $updated_notification;
		update_post_meta( $form_id, '_form_notifications', $notifications );

		$data = $this->prepare_item_for_response( $updated_notification, $request );

		return rest_ensure_response( $data );
	}

	/**
	 * Checks if a given request has access to delete a notification.
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
	 * Deletes a single notification.
	 *
	 * @since 6.7.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function delete_item( $request ) {
		$form_id         = $request['form_id'];
		$notification_id = $request['notification_id'];
		$notifications   = get_post_meta( $form_id, '_form_notifications', true );

		if ( empty( $notifications ) || ! is_array( $notifications ) ) {
			return new WP_Error(
				'rest_notification_invalid_id',
				__( 'Invalid notification ID.' ),
				array( 'status' => 404 )
			);
		}

		$notification_index = null;
		$notification_data  = null;
		foreach ( $notifications as $index => $notification ) {
			if ( isset( $notification['id'] ) && $notification['id'] === $notification_id ) {
				$notification_index = $index;
				$notification_data  = $notification;
				break;
			}
		}

		if ( null === $notification_index ) {
			return new WP_Error(
				'rest_notification_invalid_id',
				__( 'Invalid notification ID.' ),
				array( 'status' => 404 )
			);
		}

		$previous = $this->prepare_item_for_response( $notification_data, $request );

		array_splice( $notifications, $notification_index, 1 );
		update_post_meta( $form_id, '_form_notifications', $notifications );

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
	 * Prepares a single notification for creation or update.
	 *
	 * @since 6.7.0
	 *
	 * @param WP_REST_Request $request              Request object.
	 * @param array           $existing_notification Optional. Existing notification data for updates.
	 * @return array|WP_Error Prepared notification array or WP_Error.
	 */
	protected function prepare_item_for_database( $request, $existing_notification = array() ) {
		$notification = $existing_notification;

		if ( isset( $request['name'] ) ) {
			$notification['name'] = sanitize_text_field( $request['name'] );
		}

		if ( isset( $request['to'] ) ) {
			if ( is_array( $request['to'] ) ) {
				$notification['to'] = array_map( 'sanitize_email', $request['to'] );
			} else {
				$notification['to'] = sanitize_email( $request['to'] );
			}
		}

		if ( isset( $request['subject'] ) ) {
			$notification['subject'] = sanitize_text_field( $request['subject'] );
		}

		if ( isset( $request['message'] ) ) {
			$notification['message'] = wp_kses_post( $request['message'] );
		}

		if ( isset( $request['conditions'] ) ) {
			$notification['conditions'] = $request['conditions'];
		}

		if ( isset( $request['enabled'] ) ) {
			$notification['enabled'] = (bool) $request['enabled'];
		}

		// Validate required name
		if ( empty( $notification['name'] ) ) {
			return new WP_Error(
				'rest_notification_missing_name',
				__( 'Notification name is required.' ),
				array( 'status' => 400 )
			);
		}

		// Validate required to
		if ( empty( $notification['to'] ) ) {
			return new WP_Error(
				'rest_notification_missing_to',
				__( 'Notification recipient(s) required.' ),
				array( 'status' => 400 )
			);
		}

		return $notification;
	}

	/**
	 * Prepares a single notification output for response.
	 *
	 * @since 6.7.0
	 *
	 * @param array           $notification Notification data.
	 * @param WP_REST_Request $request      Request object.
	 * @return WP_REST_Response Response object.
	 */
	public function prepare_item_for_response( $notification, $request ) {
		$data = array(
			'id'         => isset( $notification['id'] ) ? $notification['id'] : '',
			'form_id'    => (int) $request['form_id'],
			'name'       => isset( $notification['name'] ) ? $notification['name'] : '',
			'to'         => isset( $notification['to'] ) ? $notification['to'] : '',
			'subject'    => isset( $notification['subject'] ) ? $notification['subject'] : '',
			'message'    => isset( $notification['message'] ) ? $notification['message'] : '',
			'conditions' => isset( $notification['conditions'] ) ? $notification['conditions'] : new stdClass(),
			'enabled'    => isset( $notification['enabled'] ) ? (bool) $notification['enabled'] : true,
		);

		$response = new WP_REST_Response( $data );

		return apply_filters( 'rest_prepare_form_notification', $response, $notification, $request );
	}

	/**
	 * Retrieves the notification's schema, conforming to JSON Schema.
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
			'title'      => 'form_notification',
			'type'       => 'object',
			'properties' => array(
				'id' => array(
					'description' => __( 'Unique identifier for the notification.' ),
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
				'name' => array(
					'description'  => __( 'Notification name.' ),
					'type'         => 'string',
					'required'     => true,
					'context'      => array( 'view', 'edit' ),
					'arg_options'  => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
				'to' => array(
					'description' => __( 'Email address(es) to send notification to.' ),
					'type'        => array( 'string', 'array' ),
					'items'       => array( 'type' => 'string' ),
					'required'    => true,
					'context'     => array( 'view', 'edit' ),
				),
				'subject' => array(
					'description' => __( 'Email subject line.' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
					'arg_options' => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
				'message' => array(
					'description' => __( 'Email message body.' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
					'arg_options' => array(
						'sanitize_callback' => 'wp_kses_post',
					),
				),
				'conditions' => array(
					'description' => __( 'Conditions for when to send the notification.' ),
					'type'        => 'object',
					'context'     => array( 'view', 'edit' ),
				),
				'enabled' => array(
					'description' => __( 'Whether the notification is enabled.' ),
					'type'        => 'boolean',
					'default'     => true,
					'context'     => array( 'view', 'edit' ),
				),
			),
		);

		$this->schema = $schema;
		return $this->add_additional_fields_schema( $this->schema );
	}
}
