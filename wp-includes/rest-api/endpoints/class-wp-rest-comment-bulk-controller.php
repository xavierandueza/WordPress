<?php
/**
 * REST API: WP_REST_Comment_Bulk_Controller class
 *
 * @package WordPress
 * @subpackage REST_API
 * @since 6.8.0
 */

/**
 * Core controller used to perform bulk moderation actions on comments via the REST API.
 *
 * @since 6.8.0
 *
 * @see WP_REST_Controller
 */
class WP_REST_Comment_Bulk_Controller extends WP_REST_Controller {

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
	 * Registers the routes for comment bulk actions.
	 *
	 * @since 6.8.0
	 *
	 * @see register_rest_route()
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/bulk',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'bulk_action' ),
					'permission_callback' => array( $this, 'bulk_action_permissions_check' ),
					'args'                => array(
						'comment_ids' => array(
							'description' => __( 'Array of comment IDs to perform the action on.' ),
							'type'        => 'array',
							'items'       => array(
								'type' => 'integer',
							),
							'required'    => true,
							'minItems'    => 1,
							'maxItems'    => 100,
						),
						'action'      => array(
							'description' => __( 'The bulk action to perform.' ),
							'type'        => 'string',
							'enum'        => array( 'approve', 'hold', 'spam', 'unspam', 'trash', 'untrash' ),
							'required'    => true,
						),
					),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * Checks if a given request has access to perform a bulk action.
	 *
	 * @since 6.8.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error True if the request has access, WP_Error object otherwise.
	 */
	public function bulk_action_permissions_check( $request ) {
		if ( ! current_user_can( 'moderate_comments' ) ) {
			return new WP_Error(
				'rest_cannot_bulk_moderate',
				__( 'Sorry, you are not allowed to moderate comments.' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Performs a bulk action on multiple comments.
	 *
	 * @since 6.8.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response Response object.
	 */
	public function bulk_action( $request ) {
		$comment_ids = $request['comment_ids'];
		$action      = $request['action'];
		$results     = array();

		foreach ( $comment_ids as $comment_id ) {
			$comment_id = (int) $comment_id;
			$comment    = get_comment( $comment_id );

			if ( ! $comment ) {
				$results[] = array(
					'id'      => $comment_id,
					'success' => false,
					'error'   => __( 'Invalid comment ID.' ),
				);
				continue;
			}

			if ( ! current_user_can( 'edit_comment', $comment_id ) ) {
				$results[] = array(
					'id'      => $comment_id,
					'success' => false,
					'error'   => __( 'Sorry, you are not allowed to edit this comment.' ),
				);
				continue;
			}

			$action_result = $this->perform_action( $comment_id, $action );

			if ( is_wp_error( $action_result ) ) {
				$results[] = array(
					'id'      => $comment_id,
					'success' => false,
					'error'   => $action_result->get_error_message(),
				);
			} else {
				$results[] = array(
					'id'      => $comment_id,
					'success' => true,
					'status'  => $this->get_comment_status_label( $action ),
				);
			}
		}

		return rest_ensure_response(
			array(
				'action'  => $action,
				'results' => $results,
			)
		);
	}

	/**
	 * Performs the specified action on a single comment.
	 *
	 * @since 6.8.0
	 *
	 * @param int    $comment_id Comment ID.
	 * @param string $action     The action to perform.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	private function perform_action( $comment_id, $action ) {
		switch ( $action ) {
			case 'approve':
				$result = wp_set_comment_status( $comment_id, 'approve' );
				break;

			case 'hold':
				$result = wp_set_comment_status( $comment_id, 'hold' );
				break;

			case 'spam':
				$result = wp_spam_comment( $comment_id );
				break;

			case 'unspam':
				$result = wp_unspam_comment( $comment_id );
				break;

			case 'trash':
				$result = wp_trash_comment( $comment_id );
				break;

			case 'untrash':
				$result = wp_untrash_comment( $comment_id );
				break;

			default:
				return new WP_Error(
					'rest_invalid_action',
					__( 'Invalid bulk action.' ),
					array( 'status' => 400 )
				);
		}

		if ( ! $result ) {
			return new WP_Error(
				'rest_action_failed',
				/* translators: %s: The moderation action that failed. */
				sprintf( __( 'Could not %s the comment.' ), $action ),
				array( 'status' => 500 )
			);
		}

		return true;
	}

	/**
	 * Maps an action name to a human-readable comment status label.
	 *
	 * @since 6.8.0
	 *
	 * @param string $action The action performed.
	 * @return string The resulting status label.
	 */
	private function get_comment_status_label( $action ) {
		$status_map = array(
			'approve' => 'approved',
			'hold'    => 'hold',
			'spam'    => 'spam',
			'unspam'  => 'approved',
			'trash'   => 'trash',
			'untrash' => 'approved',
		);

		return isset( $status_map[ $action ] ) ? $status_map[ $action ] : $action;
	}

	/**
	 * Retrieves the comment bulk action schema, conforming to JSON Schema.
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
			'title'      => 'comment-bulk',
			'type'       => 'object',
			'properties' => array(
				'action'  => array(
					'description' => __( 'The bulk action that was performed.' ),
					'type'        => 'string',
					'enum'        => array( 'approve', 'hold', 'spam', 'unspam', 'trash', 'untrash' ),
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'results' => array(
					'description' => __( 'Array of per-comment results.' ),
					'type'        => 'array',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'id'      => array(
								'description' => __( 'The comment ID.' ),
								'type'        => 'integer',
							),
							'success' => array(
								'description' => __( 'Whether the action was successful.' ),
								'type'        => 'boolean',
							),
							'status'  => array(
								'description' => __( 'The resulting comment status.' ),
								'type'        => 'string',
							),
							'error'   => array(
								'description' => __( 'Error message if the action failed.' ),
								'type'        => 'string',
							),
						),
					),
				),
			),
		);

		return $this->add_additional_fields_schema( $this->schema );
	}
}
