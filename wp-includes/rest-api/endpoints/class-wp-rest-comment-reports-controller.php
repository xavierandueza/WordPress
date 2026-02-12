<?php
/**
 * REST API: WP_REST_Comment_Reports_Controller class
 *
 * @package WordPress
 * @subpackage REST_API
 * @since 6.8.0
 */

/**
 * Core controller used to report and view reports on comments via the REST API.
 *
 * @since 6.8.0
 *
 * @see WP_REST_Controller
 */
class WP_REST_Comment_Reports_Controller extends WP_REST_Controller {

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
	 * Registers the routes for comment reports.
	 *
	 * @since 6.8.0
	 *
	 * @see register_rest_route()
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/reports',
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
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
					'args'                => array(
						'reason' => array(
							'description' => __( 'The reason for reporting the comment.' ),
							'type'        => 'string',
							'enum'        => array( 'spam', 'abuse', 'off-topic', 'other' ),
							'default'     => 'other',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * Checks if a given request has access to create a report.
	 *
	 * Any authenticated user can report a comment.
	 *
	 * @since 6.8.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error True if the request has access, WP_Error object otherwise.
	 */
	public function create_item_permissions_check( $request ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'rest_cannot_report_comment',
				__( 'Sorry, you must be logged in to report a comment.' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		$comment = get_comment( $request['id'] );

		if ( ! $comment ) {
			return new WP_Error(
				'rest_comment_invalid_id',
				__( 'Invalid comment ID.' ),
				array( 'status' => 404 )
			);
		}

		return true;
	}

	/**
	 * Creates a report for a comment.
	 *
	 * @since 6.8.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, WP_Error on failure.
	 */
	public function create_item( $request ) {
		$comment_id = (int) $request['id'];
		$user_id    = get_current_user_id();
		$reason     = ! empty( $request['reason'] ) ? $request['reason'] : 'other';

		$result = wp_report_comment( $comment_id, $user_id, $reason );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$report = array(
			'user_id' => $user_id,
			'reason'  => $reason,
			'date'    => mysql_to_rfc3339( current_time( 'mysql' ) ),
		);

		$response = rest_ensure_response( $report );
		$response->set_status( 201 );

		return $response;
	}

	/**
	 * Checks if a given request has access to view reports.
	 *
	 * @since 6.8.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error True if the request has access, WP_Error object otherwise.
	 */
	public function get_items_permissions_check( $request ) {
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
				'rest_cannot_view_reports',
				__( 'Sorry, you are not allowed to view reports for this comment.' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Retrieves the reports for a comment.
	 *
	 * @since 6.8.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response Response object.
	 */
	public function get_items( $request ) {
		$comment_id = (int) $request['id'];
		$reports    = wp_get_comment_reports( $comment_id );

		$data = array();

		foreach ( $reports as $report ) {
			$report_data = array(
				'user_id' => $report['user_id'],
				'reason'  => $report['reason'],
				'date'    => ! empty( $report['date'] ) ? mysql_to_rfc3339( $report['date'] ) : null,
			);

			$data[] = $report_data;
		}

		return rest_ensure_response( $data );
	}

	/**
	 * Retrieves the comment report schema, conforming to JSON Schema.
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
			'title'      => 'comment-report',
			'type'       => 'object',
			'properties' => array(
				'user_id' => array(
					'description' => __( 'The ID of the user who filed the report.' ),
					'type'        => 'integer',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'reason'  => array(
					'description' => __( 'The reason for reporting the comment.' ),
					'type'        => 'string',
					'enum'        => array( 'spam', 'abuse', 'off-topic', 'other' ),
					'context'     => array( 'view', 'edit' ),
				),
				'date'    => array(
					'description' => __( 'The date the report was filed.' ),
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
