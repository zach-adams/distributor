<?php
namespace Distributor\ExternalConnections;

use \Distributor\Authentications\WordPressBasicAuth as WordPressBasicAuth;
use WP_Mock\Functions;
use WP_Mock\Tools\TestCase;

class WordPressExternalConnectionTest extends TestCase {

	public function setUp(): void {

		$this->auth       = new WordPressBasicAuth( array() );
		$this->connection = new WordPressExternalConnection( 'name', 'url', 1, $this->auth );

	}

	/**
	 * Helper function to mock get_post_meta.
	 */
	public function setup_post_meta_mock( $post_meta ) {
		$get_post_meta = function( $post_id, $key = '', $single = false ) use ( $post_meta ) {
			if ( empty( $key ) ) {
				return $post_meta;
			}

			if ( isset( $post_meta[ $key ] ) ) {
				if ( $single ) {
					return $post_meta[ $key ][0];
				}
				return $post_meta[ $key ];
			}

			return '';
		};

		\WP_Mock::userFunction(
			'get_post_meta',
			array(
				'return' => $get_post_meta,
			)
		);
	}

	/**
	 * Test creating a WordPressExternalConnection object
	 *
	 * @since  0.8
	 * @group WordPressExternalConnection
	 * @runInSeparateProcess
	 */
	public function test_construct() {
		// Now test a successful creation
		$auth = new WordPressBasicAuth( array() );

		$connection = new WordPressExternalConnection( 'name', 'url', 1, $auth );

		$this->assertTrue( is_a( $connection, '\Distributor\ExternalConnection' ) );

		// Check connection properties
		$this->assertTrue( ! empty( $connection->name ) );
		$this->assertTrue( ! empty( $connection->base_url ) );
		$this->assertTrue( ! empty( $connection->id ) );
		$this->assertTrue( ! empty( $connection->auth_handler ) );
	}

	/**
	 * This test has been greatly simplified to handle testing that the push
	 * method returns true, or an instance of WP_Error.
	 *
	 * An elaborated test case would verify that each WP_Error returns the
	 * error id, and error message it specifies.
	 *
	 * This is needed so the method parse_type_items_link() can return a valid URL
	 * otherwise that method will return false, rending our test false as well.
	 * Valid response body, with JSON encoded body
	 *
	 * @group WordPressExternalConnection
	 * @since  0.8
	 * @runInSeparateProcess
	 */
	public function test_push() {
		$this->setup_post_meta_mock( array() );
		\WP_Mock::userFunction( 'do_action_deprecated' );
		\WP_Mock::userFunction( 'untrailingslashit' );
		\WP_Mock::userFunction( 'get_the_title' );
		\WP_Mock::userFunction( 'wp_remote_post' );
		\WP_Mock::userFunction( 'esc_html__' );
		\WP_Mock::userFunction( 'get_bloginfo' );
		\WP_Mock::passthruFunction( 'absint' );

		\WP_Mock::userFunction(
			'get_current_blog_id', [
				'return' => 1,
			]
		);

		\WP_Mock::userFunction(
			'get_option', [
				'args'   => [ 'page_for_posts' ],
				'return' => 0,
			]
		);

		\WP_Mock::userFunction(
			'use_block_editor_for_post_type', [
				'return' => false,
			]
		);

		$post_type = 'foo';

		$body = json_encode(
			[
				'id'       => 123,
				$post_type => [
					'_links' => [
						'wp:items' => [
							0 => [
								'href' => 'http://url.com',
							],
						],
					],
				],
			]
		);

		$post = (object) [
			'post_content' => 'my post content',
			'post_type'    => $post_type,
			'post_excerpt' => 'post excerpt',
			'post_name'    => 'slug',
			'post_status'  => 'publish',
			'ID'           => 1,
		];

		\WP_Mock::userFunction(
			'get_post', [
				'args'   => [ Functions::anyOf( $post->ID, $post ) ],
				'return' => $post,
			]
		);

		\WP_Mock::userFunction(
			'get_post_type', [
				'return' => $post_type,
			]
		);

		\WP_Mock::userFunction(
			'has_blocks', [
				'return' => false,
			]
		);

		\WP_Mock::userFunction(
			'wp_generate_password', [
				'return' => '12345',
			]
		);

		\WP_Mock::userFunction(
			'wp_remote_request', [
				'return' => $body,
			]
		);

		\WP_Mock::userFunction(
			'wp_remote_retrieve_body', [
				'return' => $body,
			]
		);

		\WP_Mock::userFunction(
			'wp_remote_retrieve_headers', [
				'return' => [],
			]
		);

		/**
		 * We will test the util prepare functions later
		 */

		\WP_Mock::userFunction(
			'get_attached_media', [
				'return' => [],
			]
		);

		\WP_Mock::userFunction(
			'get_post_thumbnail_id', [
				'return' => 0,
			]
		);

		\WP_Mock::userFunction(
			'\Distributor\Utils\prepare_media', [
				'return' => [],
			]
		);

		\WP_Mock::userFunction(
			'\Distributor\Utils\prepare_taxonomy_terms', [
				'return' => [],
			]
		);

		\WP_Mock::userFunction(
			'\Distributor\Utils\prepare_meta', [
				'return' => [],
			]
		);

		\WP_Mock::userFunction( 'get_permalink' );

		\WP_Mock::userFunction(
			'remove_filter', [
				'times' => 2,
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $this->connection->push( 0 ) );
		$this->assertTrue( is_array( $this->connection->push( 1 ) ) );

		/**
		 * Let's ensure \Distributor\Subscriptions\create_subscription is called when the X-Distributor header is
		 * returned by the remote API
		 */

		\WP_Mock::userFunction(
			'wp_remote_retrieve_headers', [
				'return' => [
					'X-Distributor' => true,
				],
			]
		);

		\WP_Mock::userFunction(
			'\Distributor\Subscriptions\create_subscription', [
				'times'  => 0,
				'return' => [
					'X-Distributor' => true,
				],
			]
		);

		$this->assertTrue( is_array( $this->connection->push( 1 ) ) );
	}

	/**
	 * Test if the pull method returns an array.
	 *
	 * @since  0.8
	 * @group WordPressExternalConnection
	 * @runInSeparateProcess
	 */
	public function test_pull() {
		$this->setup_post_meta_mock( array() );
		$post_id = 123;

		\WP_Mock::userFunction( 'untrailingslashit' );
		\WP_Mock::userFunction( 'sanitize_text_field' );

		remote_get_setup();

		\WP_Mock::passthruFunction( 'wp_slash' );
		\WP_Mock::passthruFunction( 'update_post_meta' );
		\WP_Mock::userFunction( 'get_current_user_id' );
		\WP_Mock::userFunction( 'delete_post_meta' );

		\WP_Mock::userFunction(
			'apply_filters_deprecated',
			[
				'return' => function( $name, $args ) {
					return $args[0];
				},
			]
		);

		\WP_Mock::userFunction(
			'wp_remote_retrieve_headers', [
				'return' => [
					'X-Distributor' => 'yes',
				],
			]
		);

		\WP_Mock::userFunction(
			'wp_insert_post', [
				'return' => 2,
			]
		);

		\WP_Mock::userFunction(
			'get_attached_media', [
				'return' => [],
			]
		);

		\WP_Mock::userFunction(
			'get_allowed_mime_types', [
				'return' => [],
			]
		);

		$pull_actual = $this->connection->pull(
			[
				[
					'remote_post_id' => $post_id,
					'post_type'      => 'post',
				],
			]
		);

		$this->assertIsArray( $pull_actual );
	}

	/**
	 * Handles mocking the correct remote request to receive a WP_Post instance.
	 *
	 * @since  0.8
	 * @group WordPressExternalConnection
	 * @runInSeparateProcess
	 */
	public function test_remote_get() {

		remote_get_setup();

		\WP_Mock::passThruFunction( 'untrailingslashit' );
		\WP_Mock::userFunction( 'get_current_user_id' );

		\WP_Mock::userFunction(
			'wp_remote_retrieve_response_code', [
				'return' => 200,
			]
		);

		\WP_Mock::userFunction(
			'wp_remote_retrieve_headers', [
				'times'  => 1,
				'return' => [
					'X-Distributor' => true,
				],
			]
		);

		\WP_Mock::userFunction(
			'post_type_exists', [
				'args'   => [ '' ],
				'return' => false,
			]
		);

		\WP_Mock::userFunction(
			'post_type_exists', [
				'args'   => [ 'post' ],
				'return' => true,
			]
		);

		\WP_Mock::userFunction(
			'post_type_supports', [
				'args'   => [ \WP_Mock\Functions::type( 'string' ), 'editor' ],
				'return' => true,
			]
		);

		$actual = $this->connection->remote_get(
			[
				'id'        => 111,
				'post_type' => 'post',
			]
		);

		$this->assertInstanceOf( \WP_Post::class, $actual );
	}

	/**
	 * Check that the connection does not return an error
	 *
	 * @since 0.8
	 * @group WordPressExternalConnection
	 * @runInSeparateProcess
	 */
	public function test_check_connections_no_errors() {

		\WP_Mock::userFunction(
			'wp_remote_retrieve_body', [
				'return' => json_encode(
					[
						'routes' => 'my routes',
					]
				),
			]
		);

		\WP_Mock::userFunction(
			'wp_remote_retrieve_headers', [
				'return' => [
					'Link' => null,
				],
			]
		);

		\WP_Mock::userFunction(
			'wp_remote_retrieve_response_code', [
				'return' => 200,
			]
		);

		\WP_Mock::userFunction( 'wp_remote_request' );
		\WP_Mock::userFunction( 'untrailingslashit' );

		$check = $this->connection->check_connections();

		$this->assertTrue( ! empty( $check['errors']['no_distributor'] ) );
	}

	/**
	 * Check that the connection properly returns a no distributor warning
	 *
	 * @since 1.0
	 * @group WordPressExternalConnection
	 * @runInSeparateProcess
	 */
	public function test_check_connections_no_distributor() {
		\WP_Mock::userFunction(
			'wp_remote_retrieve_body', [
				'return' => json_encode(
					[
						'routes' => 'my routes',
					]
				),
			]
		);

		\WP_Mock::userFunction( 'wp_remote_request' );
		\WP_Mock::userFunction( 'untrailingslashit' );

		\WP_Mock::userFunction(
			'wp_remote_retrieve_response_code', [
				'return' => 200,
			]
		);

		\WP_Mock::userFunction(
			'wp_remote_retrieve_headers', [
				'return' => [
					'X-Distributor' => true,
					'Link'          => null,
				],
			]
		);

		$this->assertTrue( empty( $this->connection->check_connections()['errors']['no_distributor'] ) );
	}
}
