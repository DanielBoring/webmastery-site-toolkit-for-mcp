<?php

declare(strict_types=1);

namespace Wstm108TypedOracle;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

require_once dirname( __DIR__ ) . '/e2e/untrusted-content-wire.php';

function get_post( $id ): object {
	return (object) array( 'post_type' => 'post', 'post_title' => 'Original "quoted" \\ title' );
}

function get_permalink( $id ): string {
	return 'https://example.test/?p=' . $id;
}

final class UntrustedTypedOracleTest extends TestCase {
	private static function source_function( string $name ): string {
		$source = str_replace( "\r\n", "\n", file_get_contents( dirname( __DIR__ ) . '/e2e/untrusted-content-runner.php' ) );
		self::assertSame( 1, preg_match( '/^function ' . preg_quote( $name, '/' ) . '\b.*?^}/ms', $source, $match ) );
		return $match[0];
	}

	public static function setUpBeforeClass(): void {
		foreach ( array( 'wstm108_provider_keys', 'wstm108_unavailable', 'wstm108_expected_provider' ) as $name ) {
			eval( 'namespace Wstm108TypedOracle; ' . self::source_function( $name ) );
		}
	}

	public function test_exact_two_canonical_details_corrections_preserve_the_rest_of_each_frozen_oracle(): void {
		$ledger = json_decode( file_get_contents( __DIR__ . '/fixtures/untrusted-typed-oracle-ledger.json' ), true, 512, JSON_THROW_ON_ERROR );
		self::assertSame( '546ea69d5af00a4addeb5420cf700e49e225dca56f1bf870f49d0b86b1ca0471', hash( 'sha256', json_encode( $ledger, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION ) ) );
		self::assertSame( 'a05e47e0a868dafe23f8b7371e723e43c23ace35', $ledger['source_sha'] );
		self::assertSame( array( 'wstm108_unavailable', 'wstm108_expected_provider' ), array_column( $ledger['sites'], 'function' ) );
		foreach ( $ledger['sites'] as $site ) {
			$after = self::source_function( $site['function'] );
			self::assertSame( $site['after_sha256'], hash( 'sha256', $after ) );
			$before = str_replace( $site['after'], $site['before'], $after, $count );
			self::assertSame( 1, $count );
			self::assertSame( $site['before_sha256'], hash( 'sha256', $before ) );
		}
	}

	private function provider(): array {
		$expected = wstm108_expected_provider( 42, 'yoast', array() );
		$actual = json_decode( json_encode( $expected ), false, 512, JSON_THROW_ON_ERROR );
		foreach ( $actual->unavailable_fields as $field => $unavailable ) {
			$canonical = \Webmastery_MCP_Response::error( 'forbidden', $unavailable->message, array(), 'metadata_not_readable' );
			$actual->unavailable_fields->$field = json_decode( json_encode( $canonical['error'] ), false, 512, JSON_THROW_ON_ERROR );
		}
		$canonical = \Webmastery_MCP_Response::error( 'unsupported', $actual->generated_head->error->message, array(), 'key_authorization_unavailable' );
		$actual->generated_head->error = json_decode( json_encode( $canonical['error'] ), false, 512, JSON_THROW_ON_ERROR );
		$actual->untrusted_fields = array( 'title', 'url', 'metadata', 'raw_meta' );
		return array( $actual, $expected );
	}

	public function test_canonical_details_objects_and_genuine_empty_stored_lists_are_distinct(): void {
		list( $actual, $expected ) = $this->provider();
		\wstm108_compare_typed_record( $actual, $expected, array( 'title', 'url', 'metadata', 'raw_meta' ), 'provider' );
		self::assertInstanceOf( stdClass::class, $actual->generated_head->error->details );
		self::assertInstanceOf( stdClass::class, $actual->unavailable_fields->title->details );
		self::assertSame( array(), $actual->metadata );
		self::assertSame( array(), $actual->raw_meta );
	}

	public static function mutations(): array {
		return array( 'unavailable-details' => array( 'unavailable-details' ), 'head-details' => array( 'head-details' ), 'genuine-list' => array( 'genuine-list' ) );
	}

	/** @dataProvider mutations */
	public function test_reverting_either_details_literal_or_repairing_a_genuine_list_fails( string $mutation ): void {
		list( $actual, $expected ) = $this->provider();
		if ( 'unavailable-details' === $mutation ) {
			foreach ( $expected['unavailable_fields'] as &$error ) {
				$error['details'] = array();
			}
			unset( $error );
		}
		if ( 'head-details' === $mutation ) {
			$expected['generated_head']['error']['details'] = array();
		}
		if ( 'genuine-list' === $mutation ) {
			$actual->raw_meta = new stdClass();
		}
		$this->expectException( RuntimeException::class );
		\wstm108_compare_typed_record( $actual, $expected, array( 'title', 'url', 'metadata', 'raw_meta' ), 'provider' );
	}
}
