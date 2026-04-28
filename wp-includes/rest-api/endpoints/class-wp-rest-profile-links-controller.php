<?php
/**
 * REST API: WP_REST_Profile_Links_Controller class
 *
 * @package    WordPress
 * @subpackage REST_API
 * @since      6.8.0
 */

/**
 * Core class to access a user's profile links via the REST API.
 *
 * @since 6.8.0
 *
 * @see   WP_REST_Controller
 */
class WP_REST_Profile_Links_Controller extends WP_REST_Controller {

	/**
	 * Profile Links controller constructor.
	 *
	 * @since 6.8.0
	 */
	public function __construct() {
		$this->namespace = 'wp/v2';
		$this->rest_base = 'users/(?P<user_id>(?:[\d]+|me))/profile-links';
	}

	/**
	 * Registers the REST API routes for the profile links controller.
	 *
	 * @since 6.8.0
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<slug>[\w\-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
					'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::EDITABLE ),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * Checks if a given request has access to update a profile link.
	 *
	 * @since 6.8.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error True if the request has access to update the item, WP_Error object otherwise.
	 */
	public function update_item_permissions_check( $request ) {
		$user = $this->get_user( $request );

		if ( is_wp_error( $user ) ) {
			return $user;
		}

		if ( ! current_user_can( 'edit_user', $user->ID ) ) {
			return new WP_Error(
				'rest_cannot_edit_profile_link',
				__( 'Sorry, you are not allowed to edit profile links for this user.' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Updates a profile link for a user.
	 *
	 * @since 6.8.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function update_item( $request ) {
		$user = $this->get_user( $request );

		if ( is_wp_error( $user ) ) {
			return $user;
		}

		$link = $this->get_profile_link( $user->ID, $request['slug'] );

		if ( is_wp_error( $link ) ) {
			return $link;
		}

		$prepared = $this->prepare_item_for_database( $request );

		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}

		$links = $this->get_profile_links( $user->ID );
		$found = false;

		foreach ( $links as $index => $stored_link ) {
			if ( $stored_link['slug'] === $request['slug'] ) {
				if ( isset( $prepared->title ) ) {
					$links[ $index ]['title'] = $prepared->title;
				}

				if ( isset( $prepared->url ) ) {
					$links[ $index ]['url'] = $prepared->url;
				}

				$links[ $index ]['last_modified'] = time();
				$found = true;
				break;
			}
		}

		if ( ! $found ) {
			return new WP_Error(
				'rest_profile_link_not_found',
				__( 'Profile link not found.' ),
				array( 'status' => 404 )
			);
		}

		$result = update_user_meta( $user->ID, 'wp_profile_links', $links );

		if ( false === $result ) {
			return new WP_Error(
				'rest_profile_link_update_failed',
				__( 'Could not update the profile link.' ),
				array( 'status' => 500 )
			);
		}

		$updated_link = $links[ $index ];

		/**
		 * Fires after a single profile link is updated via the REST API.
		 *
		 * @since 6.8.0
		 *
		 * @param array           $updated_link The updated profile link.
		 * @param WP_REST_Request $request      Request object.
		 */
		do_action( 'rest_after_update_profile_link', $updated_link, $request );

		$request->set_param( 'context', 'edit' );
		return $this->prepare_item_for_response( $updated_link, $request );
	}

	/**
	 * Prepares a profile link for a create or update operation.
	 *
	 * @since 6.8.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return object|WP_Error The prepared item, or WP_Error object on failure.
	 */
	protected function prepare_item_for_database( $request ) {
		$prepared = (object) array();

		if ( isset( $request['title'] ) ) {
			$prepared->title = sanitize_text_field( $request['title'] );
		}

		if ( isset( $request['url'] ) ) {
			$prepared->url = esc_url_raw( $request['url'] );
		}

		/**
		 * Filters a profile link before it is updated via the REST API.
		 *
		 * @since 6.8.0
		 *
		 * @param stdClass        $prepared An object representing a single profile link prepared for updating the database.
		 * @param WP_REST_Request $request  Request object.
		 */
		return apply_filters( 'rest_pre_update_profile_link', $prepared, $request );
	}

	/**
	 * Prepares the profile link for the REST response.
	 *
	 * @since 6.8.0
	 *
	 * @param array           $item    WordPress representation of the item.
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function prepare_item_for_response( $item, $request ) {
		$user = $this->get_user( $request );

		if ( is_wp_error( $user ) ) {
			return $user;
		}

		$fields = $this->get_fields_for_response( $request );

		$prepared = array(
			'slug'          => $item['slug'],
			'title'         => $item['title'],
			'url'           => $item['url'],
			'created'       => gmdate( 'Y-m-d\TH:i:s', $item['created'] ),
			'last_modified' => $item['last_modified'] ? gmdate( 'Y-m-d\TH:i:s', $item['last_modified'] ) : null,
		);

		$prepared = $this->add_additional_fields_to_object( $prepared, $request );
		$prepared = $this->filter_response_by_context( $prepared, $request['context'] );

		$response = new WP_REST_Response( $prepared );

		if ( rest_is_field_included( '_links', $fields ) || rest_is_field_included( '_embedded', $fields ) ) {
			$response->add_links( $this->prepare_links( $user, $item ) );
		}

		/**
		 * Filters the REST API response for a profile link.
		 *
		 * @since 6.8.0
		 *
		 * @param WP_REST_Response $response The response object.
		 * @param array            $item     The profile link array.
		 * @param WP_REST_Request  $request  The request object.
		 */
		return apply_filters( 'rest_prepare_profile_link', $response, $item, $request );
	}

	/**
	 * Prepares links for the request.
	 *
	 * @since 6.8.0
	 *
	 * @param WP_User $user The requested user.
	 * @param array   $item The profile link.
	 * @return array The list of links.
	 */
	protected function prepare_links( WP_User $user, $item ) {
		return array(
			'self' => array(
				'href' => rest_url(
					sprintf(
						'%s/users/%d/profile-links/%s',
						$this->namespace,
						$user->ID,
						$item['slug']
					)
				),
			),
		);
	}

	/**
	 * Gets the requested user.
	 *
	 * @since 6.8.0
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_User|WP_Error The WordPress user associated with the request, or a WP_Error if none found.
	 */
	protected function get_user( $request ) {
		$error = new WP_Error(
			'rest_user_invalid_id',
			__( 'Invalid user ID.' ),
			array( 'status' => 404 )
		);

		$id = $request['user_id'];

		if ( 'me' === $id ) {
			if ( ! is_user_logged_in() ) {
				return new WP_Error(
					'rest_not_logged_in',
					__( 'You are not currently logged in.' ),
					array( 'status' => 401 )
				);
			}

			$user = wp_get_current_user();
		} else {
			$id = (int) $id;

			if ( $id <= 0 ) {
				return $error;
			}

			$user = get_userdata( $id );
		}

		if ( empty( $user ) || ! $user->exists() ) {
			return $error;
		}

		if ( is_multisite() && ! user_can( $user->ID, 'manage_sites' ) && ! is_user_member_of_blog( $user->ID ) ) {
			return $error;
		}

		return $user;
	}

	/**
	 * Gets a profile link by slug for a given user.
	 *
	 * @since 6.8.0
	 *
	 * @param int    $user_id The user ID.
	 * @param string $slug    The profile link slug.
	 * @return array|WP_Error The profile link if found, a WP_Error otherwise.
	 */
	protected function get_profile_link( $user_id, $slug ) {
		$links = $this->get_profile_links( $user_id );

		foreach ( $links as $link ) {
			if ( $link['slug'] === $slug ) {
				return $link;
			}
		}

		return new WP_Error(
			'rest_profile_link_not_found',
			__( 'Profile link not found.' ),
			array( 'status' => 404 )
		);
	}

	/**
	 * Gets all profile links for a given user.
	 *
	 * @since 6.8.0
	 *
	 * @param int $user_id The user ID.
	 * @return array The user's profile links, or an empty array if none exist.
	 */
	protected function get_profile_links( $user_id ) {
		$links = get_user_meta( $user_id, 'wp_profile_links', true );

		if ( ! is_array( $links ) ) {
			return array();
		}

		return $links;
	}

	/**
	 * Retrieves the profile link's schema, conforming to JSON Schema.
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
			'title'      => 'profile-link',
			'type'       => 'object',
			'properties' => array(
				'slug'          => array(
					'description' => __( 'The unique identifier for the profile link.' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit', 'embed' ),
					'readonly'    => true,
				),
				'title'         => array(
					'description' => __( 'The display title of the profile link.' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit', 'embed' ),
					'minLength'   => 1,
				),
				'url'           => array(
					'description' => __( 'The URL of the profile link.' ),
					'type'        => 'string',
					'format'      => 'uri',
					'context'     => array( 'view', 'edit', 'embed' ),
				),
				'created'       => array(
					'description' => __( 'The GMT date the profile link was created.' ),
					'type'        => 'string',
					'format'      => 'date-time',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'last_modified' => array(
					'description' => __( 'The GMT date the profile link was last modified.' ),
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
