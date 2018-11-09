<?php

/**
 * Add per-post Publicize Connection data.
 *
 * { # Post Object
 *   ...
 *   jetpack_publicize_connections: { # Defined below in this file. See schema for more detail.
 *     id:           (string)  Connection unique_id
 *     service_name: (string)  Service slug
 *     display_name: (string)  User name/display name of user/connection on Service
 *     enabled:      (boolean) Is this connection slated to be shared to? context=edit only
 *     done:         (boolean) Is this post (or connection) done sharing? context=edit only
 *     toggleable:   (boolean) Can the current user change the `enabled` setting for this Connection+Post? context=edit only
 *   }
 *   ...
 *   meta: { # Not defined in this file. Handled in modules/publicize/publicize.php via `register_meta()`
 *     jetpack_publicize_message: (string) The message to use instead of the post's title when sharing.
 *   }
 *   ...
 * }
 *
 * @since 6.8.0
 */
class WPCOM_REST_API_V2_Post_Publicize_Connections_Field extends WPCOM_REST_API_V2_Field_Controller {
	protected $object_type = 'post';
	protected $field_name = 'jetpack_publicize_connections';

	/**
	 * Registers the jetpack_publicize_connections field. Called
	 * automatically on `rest_api_init()`.
	 */
	public function register_fields() {
		$this->object_type = get_post_types_by_support( 'publicize' );

		foreach ( $this->object_type as $post_type ) {
			// Adds meta support for those post types that don't already have it.
			// Only runs during REST API requests, so it doesn't impact UI.
			if ( ! post_type_supports( $post_type, 'custom-fields' ) ) {
				add_post_type_support( $post_type, 'custom-fields' );
			}
		}

		parent::register_fields();
	}

	/**
	 * Defines data structure and what elements are visible in which contexts
	 */
	public function get_schema() {
		return array(
			'$schema' => 'http://json-schema.org/draft-04/schema#',
			'title' => 'jetpack-publicize-post-connections',
			'type' => 'array',
			'context' => array( 'view', 'edit' ),
			'items' => $this->post_connection_schema(),
			'default' => array(),
		);
	}

	private function post_connection_schema() {
		return array(
			'$schema' => 'http://json-schema.org/draft-04/schema#',
			'title' => 'jetpack-publicize-post-connection',
			'type' => 'object',
			'properties' => array(
				'id' => array(
					'description' => __( 'Unique identifier for the Publicize Connection', 'jetpack' ),
					'type' => 'string',
					'context' => array( 'view', 'edit' ),
					'readonly' => true,
				),
				'service_name' => array(
					'description' => __( 'Alphanumeric identifier for the Publicize Service', 'jetpack' ),
					'type' => 'string',
					'context' => array( 'view', 'edit' ),
					'readonly' => true,
				),
				'display_name' => array(
					'description' => __( 'Username of the connected account', 'jetpack' ),
					'type' => 'string',
					'context' => array( 'view', 'edit' ),
					'readonly' => true,
				),
				'enabled' => array(
					'description' => __( 'Whether to share to this connection', 'jetpack' ),
					'type' => 'boolean',
					'context' => array( 'edit' ),
				),
				'done' => array(
					'description' => __( 'Whether Publicize has already finished sharing for this post', 'jetpack' ),
					'type' => 'boolean',
					'context' => array( 'edit' ),
					'readonly' => true,
				),
				'toggleable' => array(
					'description' => __( 'Whether `enable` can be changed for this post/connection', 'jetpack' ),
					'type' => 'boolean',
					'context' => array( 'edit' ),
					'readonly' => true,
				),
			),
		);
	}

	/**
	 * @param int $post_id
	 * @return true|WP_Error
	 */
	function permission_check( $post_id ) {
		global $publicize;

		if ( $publicize->current_user_can_access_publicize_data( $post_id ) ) {
			return true;
		}

		return new WP_Error(
			'invalid_user_permission_publicize',
			Jetpack_Core_Json_Api_Endpoints::$user_permissions_error_msg,
			array( 'status' => Jetpack_Core_Json_Api_Endpoints::rest_authorization_required_code() )
		);
	}

	/**
	 * Getter permission check
	 * @param array $post_array Response data from Post Endpoint
	 * @return true|WP_Error
	 */
	function get_permission_check( $post_array, $request ) {
		return $this->permission_check( $post_array['id'] );

	}

	/**
	 * Setter permission check
	 * @param WP_Post $post
	 * @return true|WP_Error
	 */
	public function update_permission_check( $value, $post, $request ) {
		return $this->permission_check( $post->ID );
	}

	/**
	 * Getter: Retrieve current list of connected social accounts for a given post.
	 *
	 * @see Publicize::get_filtered_connection_data()
	 *
	 * @param array $post_array Response from Post Endpoint
	 * @param WP_REST_Request
	 *
	 * @return array List of connections
	 */
	public function get( $post_array, $request ) {
		global $publicize;

		$schema = $this->post_connection_schema();
		$properties = array_keys( $schema['properties'] );

		$connections = $publicize->get_filtered_connection_data( $post_array['id'] );

		$output_connections = array();
		foreach ( $connections as $connection ) {
			$output_connection = array();
			foreach ( $properties as $property  ) {
				if ( isset( $connection[$property] ) ) {
					$output_connection[$property] = $connection[$property];
				}
			}

			$output_connection['id'] = (string) $connection['unique_id'];

			$output_connections[] = $output_connection;
		}

		return $output_connections;
	}

	/**
	 * Update the connections slated to be shared to.
	 *
	 * @param array $requested_connections
	 *              Items are eitheer `{ id: (string) }` or `{ service_name: (string) }`
	 * @param WP_Post $post
	 * @param WP_REST_Request
	 */
	public function update( $requested_connections, $post, $request ) {
		global $publicize;

		$available_connections = $publicize->get_filtered_connection_data( $post->ID );

		$changed_connections = array();

		// Build lookup mappings
		$available_connections_by_unique_id = array();
		$available_connections_by_service_name = array();
		foreach ( $available_connections as $available_connection ) {
			$available_connections_by_unique_id[$available_connection['unique_id']] = $available_connection;

			if ( ! isset( $available_connections_by_service_name[$available_connection['service_name']] ) ) {
				$available_connections_by_service_name[$available_connection['service_name']] = array();
			}
			$available_connections_by_service_name[$available_connection['service_name']][] = $available_connection;
		}

		// Handle { service_name: $service_name, enabled: (bool) }
		foreach ( $requested_connections as $requested_connection ) {
			if ( ! isset( $requested_connection['service_name'] ) ) {
				continue;
			}

			if ( ! isset( $available_connections_by_service_name[$requested_connection['service_name']] ) ) {
				continue;
			}

			foreach ( $available_connections_by_service_name[$requested_connection['service_name']] as $available_connection ) {
				$changed_connections[$available_connection['unique_id']] = $requested_connection['enabled'];
			}
		}

		// Handle { id: $id, enabled: (bool) }
		// These override the service_name settings
		foreach ( $requested_connections as $requested_connection ) {
			if ( ! isset( $requested_connection['id'] ) ) {
				continue;
			}

			if ( ! isset( $available_connections_by_unique_id[$requested_connection['id']] ) ) {
				continue;
			}

			$changed_connections[$requested_connection['id']] = $requested_connection['enabled'];
		}

		// Set all changed connections to their new value
		foreach ( $changed_connections as $unique_id => $enabled ) {
			$connection = $available_connections_by_unique_id[$unique_id];

			if ( $connection['done'] || ! $connection['toggleable'] ) {
				continue;
			}

			$available_connections_by_unique_id[$unique_id]['enabled'] = $enabled;
		}

		// For all connections, ensure correct post_meta
		foreach ( $available_connections_by_unique_id as $unique_id => $available_connection ) {
			if ( $available_connection['enabled'] ) {
				delete_post_meta( $post->ID, $publicize->POST_SKIP . $unique_id );
			} else {
				update_post_meta( $post->ID, $publicize->POST_SKIP . $unique_id, 1 );
			}
		}
	}
}

wpcom_rest_api_v2_load_plugin( 'WPCOM_REST_API_V2_Post_Publicize_Connections_Field' );