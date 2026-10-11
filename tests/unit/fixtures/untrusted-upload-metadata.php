<?php

declare(strict_types=1);

namespace Wstm108UploadMetadataFixture;

use PHPUnit\Framework\Assert;
use Wstm108_Files;
use Wstm108_Resources;

final class Native {
	public static $uploads;
	public static array $attachment = array();
	public static array $metadata = array();
	public static array $alt = array();
}

function wp_upload_dir( $time, bool $create, bool $refresh ): array {
	Assert::assertNull( $time );
	Assert::assertTrue( $refresh );
	return ( Native::$uploads )( $create );
}

function wstm108_assert( bool $condition, string $message ): void {
	if ( ! $condition ) { throw new \RuntimeException( $message ); }
}

function wp_slash( $value ) { return $value; }
function sanitize_title( string $value ): string { return 'owned-attachment'; }
function is_wp_error( $value ): bool { return false; }

function wp_insert_attachment( array $post, string $file, int $parent, bool $error ): int {
	Assert::assertTrue( $error );
	Assert::assertFileExists( $file );
	Assert::assertSame( 17, $parent );
	Assert::assertSame( array( 'post_title', 'post_excerpt', 'post_mime_type', 'post_status', 'post_author' ), array_keys( $post ) );
	Assert::assertSame( 'image/png', $post['post_mime_type'] );
	Assert::assertSame( 'inherit', $post['post_status'] );
	Assert::assertSame( 23, $post['post_author'] );
	Native::$attachment = array( 'id' => 31, 'file' => $file, 'post' => $post, 'parent' => $parent );
	return 31;
}

function update_post_meta( int $id, string $key, string $value ): void {
	Assert::assertSame( 31, $id );
	Assert::assertSame( '_wp_attachment_image_alt', $key );
	Native::$alt = array( $id, $key, $value );
}

function wp_update_attachment_metadata( int $id, array $metadata ): void {
	Assert::assertSame( 31, $id );
	Assert::assertSame( array( 'width', 'height', 'file' ), array_keys( $metadata ) );
	Assert::assertSame( 1, $metadata['width'] );
	Assert::assertSame( 1, $metadata['height'] );
	Native::$metadata = $metadata;
}

final class Resources {
	public array $attachment_intents = array();
	public array $posts = array();
	private Wstm108_Resources $resources;

	public function __construct( Wstm108_Resources $resources ) { $this->resources = $resources; }
	public function create_upload_directory( string $base ): string { return $this->resources->create_upload_directory( $base ); }
	public function file( string $file ): void { $this->resources->file( $file ); }

	public function intent( string $kind, array $details ): void {
		if ( 'file' === $kind ) {
			$this->resources->intent( $kind, $details );
			return;
		}
		Assert::assertContains( $kind, array( 'post', 'mutation' ) );
		if ( 'post' === $kind ) {
			Assert::assertSame( array( 'author' => 23, 'type' => 'attachment', 'slug' => 'owned-attachment', 'parent' => 17 ), $details );
		} else {
			Assert::assertSame( array( 'resource' => 'post', 'id' => 31 ), $details );
		}
		$this->attachment_intents[] = array( $kind, $details );
	}

	public function post( int $id ): void {
		Assert::assertSame( 31, $id );
		Assert::assertSame( 31, Native::$attachment['id'] );
		$this->posts[] = $id;
	}
}

function execute( string $code, string $root, callable $uploads, Resources $resources ): void {
	Native::$uploads = $uploads;
	Native::$attachment = Native::$metadata = Native::$alt = array();
	$context = array( 'wordpress_root' => $root );
	$product = new class {
		public function wordpress(): void {}
	};
	$run = 'wstm108-0123456789abcdef';
	$payload = 'inert owned attachment fixture';
	$post_id = 17;
	$users = array( 'administrator' => 23 );
	set_error_handler( static function ( int $severity, string $message ): bool {
		if ( 0 === ( error_reporting() & $severity ) ) { return false; }
		throw new \ErrorException( $message, 0, $severity );
	} );
	try {
		eval( 'namespace ' . __NAMESPACE__ . '; use \\Wstm108_Files; ' . $code );
	} finally {
		restore_error_handler();
		Native::$uploads = null;
	}
}
