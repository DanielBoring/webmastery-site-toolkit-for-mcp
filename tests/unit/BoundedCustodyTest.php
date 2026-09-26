<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/e2e/bounded-list-verifier.php';

final class BoundedCustodyTest extends TestCase {
	private string $root;
	private string $data;
	private string $control;

	protected function setUp(): void {
		$this->root = sys_get_temp_dir() . '/wstm121-custody-' . uniqid( '', true );
		$this->data = $this->root . '/data';
		$this->control = $this->data . '.controller';
		self::assertTrue( mkdir( $this->root, 0700 ) );
		self::assertTrue( mkdir( $this->data, 0700 ) );
		self::assertTrue( mkdir( $this->control, 0700 ) );
		file_put_contents( $this->data . '/original.json', '{"value":0,"input":{}}' );
	}

	protected function tearDown(): void {
		foreach ( array( $this->data, $this->control ) as $directory ) {
			foreach ( new DirectoryIterator( $directory ) as $file ) {
				if ( ! $file->isDot() ) {
					unlink( $file->getPathname() );
				}
			}
			rmdir( $directory );
		}
		rmdir( $this->root );
	}

	private function seal( array $files ): string {
		$path = $this->control . '/custody.json';
		file_put_contents( $path, json_encode( array( 'files' => $files ), JSON_THROW_ON_ERROR ) );
		return hash_file( 'sha256', $path );
	}

	private function entry(): array {
		$path = $this->data . '/original.json';
		return array( 'area' => 'data', 'path' => 'original.json', 'bytes' => filesize( $path ), 'sha256' => hash_file( 'sha256', $path ) );
	}

	public function test_external_custody_pin_binds_original_bytes_and_inventory(): void {
		$entry = $this->entry();
		$hash = $this->seal( array( $entry ) );
		self::assertSame( array( $entry ), Wstm121Verifier::custody( $this->data, $hash )['files'] );
		file_put_contents( $this->data . '/original.json', '{"value":0,"input":[]}' );
		$this->expectException( RuntimeException::class );
		Wstm121Verifier::custody( $this->data, $hash );
	}

	public static function invalid_custody(): array {
		return array_map( static fn( $name ) => array( $name ), array( 'external-pin', 'duplicate', 'unknown-file', 'traversal', 'absolute', 'float-size', 'bad-hash', 'missing-file' ) );
	}

	/** @dataProvider invalid_custody */
	public function test_custody_cannot_hide_replacement_missing_or_unowned_files( string $mutation ): void {
		$entry = $this->entry();
		$files = array( $entry );
		switch ( $mutation ) {
			case 'duplicate': $files[] = $entry; break;
			case 'traversal': $files[0]['path'] = '../original.json'; break;
			case 'absolute': $files[0]['path'] = '/original.json'; break;
			case 'float-size': $files[0]['bytes'] = (float) $entry['bytes']; break;
			case 'bad-hash': $files[0]['sha256'] = str_repeat( 'z', 64 ); break;
		}
		$path = $this->control . '/custody.json';
		file_put_contents( $path, json_encode( array( 'files' => $files ), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION ) );
		$hash = hash_file( 'sha256', $path );
		if ( 'external-pin' === $mutation ) { $hash = str_repeat( '0', 64 ); }
		if ( 'unknown-file' === $mutation ) { file_put_contents( $this->data . '/extra.json', '{}' ); }
		if ( 'missing-file' === $mutation ) { unlink( $this->data . '/original.json' ); }
		$this->expectException( RuntimeException::class );
		Wstm121Verifier::custody( $this->data, $hash );
	}

	private function storage_fixture(): array {
		$bytes = filesize( $this->data . '/original.json' );
		$journal = $bytes . "\n";
		file_put_contents( $this->control . '/php-write-bytes.log', $journal );
		file_put_contents( $this->control . '/outcome.json', '{}' );
		file_put_contents( $this->control . '/custody.json', '{}' );
		file_put_contents( $this->data . '/execution.json', '{}' );
		return array( 'namespace_storage' => array(
			'final_record_reserve_bytes' => 34 * 1024 * 1024,
			'php_reserved_bytes' => $bytes, 'php_journal_bytes' => strlen( $journal ),
			'controller_written_bytes' => 0, 'retained_bytes' => $bytes + strlen( $journal ),
			'cumulative_write_bytes' => $bytes + strlen( $journal ),
			'whole_job_observed_bytes' => $bytes + strlen( $journal ), 'whole_job_limit_bytes' => Wstm121Plan::NAMESPACE_BYTES,
		) );
	}

	public function test_final_records_and_original_journal_are_charged_independently(): void {
		$execution = $this->storage_fixture();
		$actual = Wstm121Verifier::storage( $this->data, $execution );
		self::assertSame( array(
			'retained_bytes' => $execution['namespace_storage']['retained_bytes'] + 6,
			'cumulative_reserved_and_written_bytes' => $execution['namespace_storage']['cumulative_write_bytes'] + 6,
			'final_record_bytes' => 6,
		), $actual );
	}

	public static function invalid_storage(): array {
		return array_map( static fn( $name ) => array( $name ), array(
			'php_reserved_bytes', 'php_journal_bytes', 'controller_written_bytes', 'retained_bytes',
			'cumulative_write_bytes', 'final_record_reserve_bytes', 'whole_job_limit_bytes',
			'float-counter', 'truncated-journal', 'invalid-journal', 'unaccounted-file',
		) );
	}

	/** @dataProvider invalid_storage */
	public function test_retained_bytes_and_reservations_cannot_be_underreported( string $mutation ): void {
		$execution = $this->storage_fixture();
		if ( array_key_exists( $mutation, $execution['namespace_storage'] ) ) {
			$execution['namespace_storage'][ $mutation ] = -1;
		} elseif ( 'float-counter' === $mutation ) {
			$execution['namespace_storage']['php_reserved_bytes'] = (float) $execution['namespace_storage']['php_reserved_bytes'];
		} elseif ( 'truncated-journal' === $mutation ) {
			file_put_contents( $this->control . '/php-write-bytes.log', '20' );
		} elseif ( 'invalid-journal' === $mutation ) {
			file_put_contents( $this->control . '/php-write-bytes.log', "0\n" );
		} else {
			file_put_contents( $this->data . '/unaccounted.bin', 'unexpected' );
		}
		clearstatcache();
		$this->expectException( RuntimeException::class );
		Wstm121Verifier::storage( $this->data, $execution );
	}

	public static function wrong_original_array_shapes(): array {
		return array(
			'empty items object' => array( 'original_operation', '{"response":{"data":{"items":{}}}}' ),
			'numeric marker object' => array( 'original_operation', '{"response":{"data":{"items":[{"untrusted_fields":{"0":"title"}}]}}}' ),
			'empty marker object' => array( 'original_operation', '{"response":{"data":{"items":[{"untrusted_fields":{}}]}}}' ),
			'revision map' => array( 'original_projection', '{"responses":{"revisions-summary":{"data":{"revisions":{"0":{"untrusted_fields":[]}}}}}}' ),
			'projection marker map' => array( 'original_projection', '{"responses":{"get-post":{"data":{"untrusted_fields":{"0":"title"}}}}}' ),
		);
	}

	/** @dataProvider wrong_original_array_shapes */
	public function test_original_json_shape_is_checked_before_associative_decoding( string $method, string $json ): void {
		$reader = new ReflectionMethod( Wstm121Verifier::class, $method );
		$reader->setAccessible( true );
		$path = $this->data . '/original.json';
		$valid = 'original_operation' === $method
			? '{"response":{"data":{"items":[{"untrusted_fields":[]}]}}}'
			: '{"responses":{"get-post":{"data":{"untrusted_fields":[]}},"revisions-summary":{"data":{"revisions":[{"untrusted_fields":[]}]}}}}';
		file_put_contents( $path, $valid );
		self::assertSame( json_decode( $valid, true, 512, JSON_THROW_ON_ERROR ), $reader->invoke( null, $path ) );
		file_put_contents( $path, $json );
		$this->expectException( RuntimeException::class );
		$reader->invoke( null, $path );
	}
}
