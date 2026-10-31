<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Wstm206\State;

require_once __DIR__ . '/fixtures/featured-image-stubs.php';

final class FeaturedImageTest extends TestCase {
	protected function setUp(): void {
		State::$abilities = State::$calls = State::$images = State::$caps = State::$denied_ids = array();
		State::$fail_set = State::$fail_remove = State::$missing_url = false;
		State::$posts = array(
			10 => (object) array( 'ID' => 10, 'post_type' => 'post' ),
			11 => (object) array( 'ID' => 11, 'post_type' => 'page' ),
			20 => (object) array( 'ID' => 20, 'post_type' => 'attachment', 'post_mime_type' => 'image/png' ),
			21 => (object) array( 'ID' => 21, 'post_type' => 'attachment', 'post_mime_type' => 'image/png' ),
			22 => (object) array( 'ID' => 22, 'post_type' => 'attachment', 'post_mime_type' => 'application/pdf' ),
		);
		Wstm206\Webmastery_MCP_Featured_Image::register();
	}

	private function execute( string $slug, array $input ): array {
		return State::$abilities[ $slug . '-featured-image' ]['execute_callback']( $input );
	}

	private function called( string $operation ): array {
		return array_values( array_filter( State::$calls, static fn( $call ) => $operation === $call[0] ) );
	}

	public function test_full_is_the_unchanged_default_and_explicit_full_projection_for_posts_and_pages(): void {
		foreach ( array( 10, 11 ) as $post_id ) {
			foreach ( array( array(), array( 'response_format' => 'full' ) ) as $projection ) {
				State::$images = State::$calls = array();
				foreach ( array( 'set', 'remove' ) as $slug ) {
					$result = $this->execute( $slug, array( 'post_id' => $post_id ) + ( 'set' === $slug ? array( 'attachment_id' => 20 ) : array() ) + $projection );
					self::assertTrue( $result['success'] );
					self::assertSame( str_repeat( 'Body', 15000 ), $result['data']['content'] );
					self::assertSame( $post_id, $result['data']['id'] );
				}
				self::assertCount( 2, $this->called( 'normalize' ) );
				self::assertSame( array(), $this->called( 'thumbnail' ), 'Full projection adds no previous-state reads.' );
				self::assertSame( array(), $this->called( 'url' ) );
			}
		}
	}

	public function test_compact_tracks_real_previous_and_current_ids_without_normalizing_content(): void {
		foreach ( array( 10, 11 ) as $post_id ) {
			foreach ( array( array( 'set', 20, 0, 20 ), array( 'set', 21, 20, 21 ), array( 'remove', 0, 21, 0 ), array( 'remove', 0, 0, 0 ) ) as $case ) {
				list( $slug, $attachment, $previous, $current ) = $case;
				State::$calls = array();
				$result = $this->execute( $slug, array( 'post_id' => $post_id, 'response_format' => 'compact' ) + ( $attachment ? array( 'attachment_id' => $attachment ) : array() ) );
				self::assertSame( array( 'success' => true, 'data' => array(
					'post_id' => $post_id, 'featured_image_id' => $current,
					'featured_image_url' => $current ? 'https://example.test/' . $current . '.png' : null,
					'previous_featured_image_id' => $previous, 'untrusted_fields' => array( 'featured_image_url' ),
				) ), $result );
				self::assertSame( $current, State::$images[ $post_id ] ?? 0 );
				self::assertSame( array(), $this->called( 'normalize' ) );
				self::assertSame( array( 'post', 'cap' ), array_column( array_slice( State::$calls, 0, 2 ), 0 ) );
			}
		}
	}

	public function test_invalid_projection_is_rejected_before_authorization_reads_or_writes(): void {
		foreach ( array( null, false, 1, array(), (object) array(), '', 'COMPACT', ' compact', 'summary' ) as $format ) {
			foreach ( State::$abilities as $ability ) {
				foreach ( array( 'permission_callback', 'execute_callback' ) as $callback ) {
					State::$calls = array();
					$result = $ability[ $callback ]( array( 'post_id' => 10, 'response_format' => $format ) + ( isset( $ability['input_schema']['properties']['attachment_id'] ) ? array( 'attachment_id' => 20 ) : array() ) );
					$result = is_wp_error( $result ) ? Webmastery_MCP_Response::from_wp_error( $result ) : $result;
					self::assertSame( 'invalid_input', $result['error']['code'] );
					self::assertSame( array(), State::$calls );
				}
			}
		}
	}

	public function test_denied_and_missing_targets_do_not_read_image_state_or_attachment(): void {
		foreach ( array( 'set', 'remove' ) as $slug ) {
			foreach ( array( 999, 20, 10 ) as $post_id ) {
				State::$calls = array();
				State::$caps['edit_post'] = false;
				$result = $this->execute( $slug, array( 'post_id' => $post_id, 'response_format' => 'compact' ) + ( 'set' === $slug ? array( 'attachment_id' => 21 ) : array() ) );
				self::assertSame( 10 === $post_id ? 'forbidden' : 'not_found', $result['error']['code'] );
				self::assertSame( array( array( 'post', $post_id ) ), $this->called( 'post' ) );
				foreach ( array( 'thumbnail', 'url', 'set', 'remove', 'normalize' ) as $operation ) {
					self::assertSame( array(), $this->called( $operation ) );
				}
			}
		}
	}

	public function test_permission_callback_preserves_broad_and_object_capabilities(): void {
		foreach ( array( 10 => 'edit_posts', 11 => 'edit_pages' ) as $id => $cap ) {
			foreach ( State::$abilities as $ability ) {
				foreach ( array( $cap, 'edit_post' ) as $denied_cap ) {
					State::$caps = array( $denied_cap => false );
					State::$calls = array();
					$result = $ability['permission_callback']( array( 'post_id' => $id, 'response_format' => 'compact' ) + ( isset( $ability['input_schema']['properties']['attachment_id'] ) ? array( 'attachment_id' => 20 ) : array() ) );
					self::assertSame( 'forbidden', Webmastery_MCP_Response::from_wp_error( $result )['error']['code'] );
					self::assertSame( array(), $this->called( 'thumbnail' ) );
				}
			}
		}
	}

	public function test_attachment_rejections_and_set_failure_keep_legacy_errors_and_state(): void {
		State::$images[10] = 20;
		foreach ( array( 999 => 'not_found', 10 => 'not_found', 22 => 'unsupported_mime_type', 20 => 'featured_image_failed' ) as $attachment => $reason ) {
			State::$calls = array();
			$result = $this->execute( 'set', array( 'post_id' => 10, 'attachment_id' => $attachment, 'response_format' => 'compact' ) );
			self::assertSame( $reason, $result['error']['reason'] );
			self::assertSame( 20, State::$images[10] );
			self::assertArrayNotHasKey( 'data', $result );
			self::assertSame( array(), $this->called( 'normalize' ) );
			self::assertSame( array(), $this->called( 'url' ) );
			if ( 20 !== $attachment ) {
				self::assertSame( array(), $this->called( 'thumbnail' ) );
			}
		}
		State::$fail_set = true;
		self::assertSame( 'featured_image_failed', $this->execute( 'set', array( 'post_id' => 10, 'attachment_id' => 21, 'response_format' => 'compact' ) )['error']['reason'] );
	}

	public function test_remove_failure_keeps_legacy_success_and_reports_the_actual_remaining_image(): void {
		State::$images[10] = 20;
		State::$fail_remove = true;
		$result = $this->execute( 'remove', array( 'post_id' => 10, 'response_format' => 'compact' ) );
		self::assertTrue( $result['success'] );
		self::assertSame( 20, $result['data']['featured_image_id'] );
		self::assertSame( 20, $result['data']['previous_featured_image_id'] );
	}

	public function test_missing_attachment_url_is_marked_null_without_restoring_content(): void {
		State::$missing_url = true;
		$result = $this->execute( 'set', array( 'post_id' => 10, 'attachment_id' => 20, 'response_format' => 'compact' ) );
		self::assertNull( $result['data']['featured_image_url'] );
		self::assertSame( array( 'featured_image_url' ), $result['data']['untrusted_fields'] );
		self::assertSame( array(), $this->called( 'normalize' ) );
	}

	public function test_unreadable_attachment_url_is_redacted_without_changing_write_eligibility(): void {
		State::$denied_ids = array( 20 );
		$result = $this->execute( 'set', array( 'post_id' => 10, 'attachment_id' => 20, 'response_format' => 'compact' ) );
		self::assertTrue( $result['success'] );
		self::assertSame( 20, $result['data']['featured_image_id'] );
		self::assertNull( $result['data']['featured_image_url'] );
		self::assertSame( array( 'featured_image_url' ), $result['data']['untrusted_fields'] );
		self::assertSame( array(), $this->called( 'url' ) );
		self::assertSame( array(), $this->called( 'normalize' ) );
	}
}
