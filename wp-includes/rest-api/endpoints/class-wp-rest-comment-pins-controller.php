<?php
/**
 * REST API: WP_REST_Comment_Pins_Controller class
 *
 * @package WordPress
 * @subpackage REST_API
 * @since 6.8.0
 */

/**
 * Core controller used to pin and unpin comments via the REST API.
 *
 * @since 6.8.0
 *
 * @see WP_REST_Controller
 */
class WP_REST_Comment_Pins_Controller extends WP_REST_Controller {

	/**
	 * Constructor.
	 *
	 * @since 6.8.0
	 */
	public function __construct() {
		$this->namespace = 'wp/v2';
		$this->rest_base = 'comments';
	}

	/**
	 * Registers the routes for comment pins.
	 *
	 * @since 6.8.0
	 *
	 * @see register_rest_route()
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/pin',
			array(
				'args' => array(
					'id' => array(
						'description' => __( 'Unique identifier for the comment.' ),
						'type'        => 'integer',
						'required'    => true,
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'pin_item' ),
					'permission_callback' => array( $this, 'pin_item_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'unpin_item' ),
					'permission_callback' => array( $this, 'unpin_item_permissions_check' ),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * Checks if a given request has access to pin a comment.
	 *
	 * @since 6.8.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error True if the request has access, WP_Error object otherwise.
	 */
	public function pin_item_permissions_check( $request ) {
		$comment = get_comment( $request['id'] );

		if ( ! $comment ) {
			return new WP_Error(
				'rest_comment_invalid_id',
				__( 'Invalid comment ID.' ),
				array( 'status' => 404 )
			);
		}

		if ( ! current_user_can( 'moderate_comments' ) ) {
			return new WP_Error(
				'rest_cannot_pin_comment',
				__( 'Sorry, you are not allowed to pin this comment.' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Checks if a given request has access to unpin a comment.
	 *
	 * @since 6.8.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error True if the request has access, WP_Error object otherwise.
	 */
	public function unpin_item_permissions_check( $request ) {
		return $this->pin_item_permissions_check( $request );
	}

	/**
	 * Pins a comment.
	 *
	 * @since 6.8.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, WP_Error on failure.
	 */
	public function pin_item( $request ) {
		$comment = get_comment( $request['id'] );

		$result = wp_pin_comment( $comment->comment_ID );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$response = $this->prepare_item_for_response( $comment, $request );

		return rest_ensure_response( $response );
	}

	/**
	 * Unpins a comment.
	 *
	 * @since 6.8.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, WP_Error on failure.
	 */
	public function unpin_item( $request ) {
		$comment = get_comment( $request['id'] );

		$result = wp_unpin_comment( $comment->comment_ID );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$response = $this->prepare_item_for_response( $comment, $request );

		return rest_ensure_response( $response );
	}

	/**
	 * Prepares a single comment pin response.
	 *
	 * @since 6.8.0
	 *
	 * @param WP_Comment      $comment Comment object.
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response object.
	 */
	public function prepare_item_for_response( $comment, $request ) {
		$fields = $this->get_fields_for_response( $request );
		$data   = array();

		if ( rest_is_field_included( 'id', $fields ) ) {
			$data['id'] = (int) $comment->comment_ID;
		}

		if ( rest_is_field_included( 'post', $fields ) ) {
			$data['post'] = (int) $comment->comment_post_ID;
		}

		if ( rest_is_field_included( 'pinned', $fields ) ) {
			$data['pinned'] = wp_is_comment_pinned( $comment->comment_ID );
		}

		if ( rest_is_field_included( 'pinned_date', $fields ) ) {
			$pinned_date = get_comment_meta( $comment->comment_ID, '_pinned', true );
			$data['pinned_date'] = $pinned_date ? mysql_to_rfc3339( $pinned_date ) : null;
		}

		$context = ! empty( $request['context'] ) ? $request['context'] : 'view';
		$data    = $this->add_additional_fields_to_object( $data, $request );
		$data    = $this->filter_response_by_context( $data, $context );

		return rest_ensure_response( $data );
	}

	/**
	 * Retrieves the comment pin schema, conforming to JSON Schema.
	 *
	 * @since 6.8.0
	 *
	 * @return array Item schema data.
	 */
	public function get_item_schema() {
		if ( $this->schema ) {
			return $this->add_additional_fields_schema( $this->schema );
		}

		$this->schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'comment-pin',
			'type'       => 'object',
			'properties' => array(
				'id'          => array(
					'description' => __( 'Unique identifier for the comment.' ),
					'type'        => 'integer',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'post'        => array(
					'description' => __( 'The ID of the associated post object.' ),
					'type'        => 'integer',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'pinned'      => array(
					'description' => __( 'Whether the comment is pinned.' ),
					'type'        => 'boolean',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'pinned_date' => array(
					'description' => __( 'The date the comment was pinned, as GMT.' ),
					'type'        => array( 'string', 'null' ),
					'format'      => 'date-time',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
			),
		);

		return $this->add_additional_fields_schema( $this->schema );
	}
}
