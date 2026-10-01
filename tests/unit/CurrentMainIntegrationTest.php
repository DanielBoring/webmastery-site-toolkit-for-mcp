<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/fixtures/ci-diagnostic-transition.php';

final class CurrentMainIntegrationTest extends TestCase {
	private function read( string $path ): string {
		return file_get_contents( dirname( __DIR__, 2 ) . '/' . $path );
	}

	public function test_main_and_existing_documentation_intents_are_both_preserved(): void {
		$readme = $this->read( 'README.md' );
		foreach ( array( 'Maintainers: equivalent permission', 'Production PHP uses PHPCS-enforced short', 'WordPress 7.1.2 with MCP Adapter', 'declared WordPress 6.9 / PHP 8.0 plugin minimums are unchanged' ) as $text ) {
			self::assertStringContainsString( $text, $readme );
		}
		$strategy = $this->read( 'docs/qa-strategy.md' );
		foreach ( array( 'synthetic controller-component job', 'exact ten', 'Local `composer qa` remains PHP-only.', 'wordpress:7.1.2-php8.2-apache', 'not genuine PHP 8.0 runtime evidence' ) as $text ) {
			self::assertStringContainsString( $text, $strategy );
		}
		$changelog = $this->read( '.github/REPOSITORY_CHANGELOG.md' );
		foreach ( array( 'Classify normal connected Unix stream rows', 'Promote the current compatibility QA baseline', 'Update PHPStan from 2.2.14 to 2.2.15', 'reviewed exact outer integration' ) as $text ) {
			self::assertStringContainsString( $text, $changelog );
		}
		foreach ( array( $readme, $strategy, $changelog, $this->read( 'phpstan-baseline.neon' ) ) as $text ) {
			self::assertDoesNotMatchRegularExpression( '/^(?:<<<<<<<|=======|>>>>>>>)/m', $text );
		}
	}

	public function test_main_alignment_is_already_present_and_no_stale_baseline_is_reintroduced(): void {
		$map = Wstm167CurrentMainTransition::load();
		$source = $this->read( 'tests/unit/CompatibilityBaselinesTest.php' );
		self::assertSame( 1, preg_match( '/\tpublic function testRepositoryBaselineImageMatrixAndReadmeStayAligned\(\): void \{\n.*?^\t\}\n/ms', $source, $method ) );
		self::assertSame( $map['main_alignment_method_sha256'], hash( 'sha256', $method[0] ) );
		$baseline = json_decode( $this->read( '.github/compatibility-versions.json' ), true, 512, JSON_THROW_ON_ERROR );
		self::assertSame( '7.1.2', $baseline['wordpress'] ); self::assertSame( '0.6.1', $baseline['mcp_adapter'] );
		self::assertStringContainsString( '${WORDPRESS_IMAGE:-wordpress:7.1.2-php8.2-apache}', $this->read( 'docker-compose.yml' ) );
		$phpstan = $this->read( 'phpstan-baseline.neon' );
		self::assertSame( 17, preg_match_all( '/^\s*message:/m', $phpstan ) );
		preg_match_all( '/^\s*count: (\d+)/m', $phpstan, $counts ); self::assertSame( 30, array_sum( array_map( 'intval', $counts[1] ) ) );
		self::assertStringNotContainsString( 'nullCoalesce.offset', $phpstan );
		self::assertStringNotContainsString( 'path: includes/class-posts.php', $phpstan );
		$lock = json_decode( $this->read( 'composer.lock' ), true, 512, JSON_THROW_ON_ERROR );
		$versions = array_column( $lock['packages-dev'], 'version', 'name' ); self::assertSame( '2.2.15', $versions['phpstan/phpstan'] );
	}

	public function test_exact_additive_bridge_retains_every_historical_seal_and_guard_dependency(): void {
		$map = Wstm167CurrentMainTransition::load();
		self::assertSame( 'a761873df07406dcde676d14f80a78283b572af2', $map['base_commit'] );
		self::assertSame( '545e7599f6f7ad081f08d9abdedd6c8a31f76d6c', $map['main_commit'] );
		self::assertSame( 'd6bbca72dcd3f823bffee69b3cd03e4ff51232cc324d919b05191103d11c53ee', Wstm167SelectedListenerTransition::SEAL );
		self::assertSame( Wstm167SelectedListenerTransition::SEAL, hash( 'sha256', $this->read( 'tests/unit/fixtures/selected-listener-transition.json' ) ) );
		Wstm167CiDiagnosticTransition::verify_dependencies( fn( $path ) => $this->read( $path ) );
		foreach ( $map['files'] as $path => $binding ) {
			self::assertSame( $binding['baseline_raw_sha256'], hash( 'sha256', Wstm167CurrentMainTransition::restore( $path, $this->read( $path ) ) ), $path );
		}
		foreach ( $map['dependencies'] as $path => $hash ) { self::assertSame( $hash, hash( 'sha256', $this->read( $path ) ), $path ); }
	}

	public function test_missing_foreign_and_old_raw_sources_cannot_bypass_current_generation(): void {
		$map = Wstm167CurrentMainTransition::load();
		foreach ( array_merge( array_keys( $map['files'] ), array_keys( $map['dependencies'] ) ) as $path ) {
			foreach ( array( false, '', $this->read( $path ) . "\nforeign" ) as $foreign ) {
				try { Wstm167CiDiagnosticTransition::verify_dependencies( fn( $entry ) => $entry === $path ? $foreign : $this->read( $entry ) ); self::fail( 'Unreviewed generation accepted.' ); }
				catch ( RuntimeException $error ) { self::assertMatchesRegularExpression( '/^CI diagnostic (?:dependency|current source) drift: /', $error->getMessage() ); }
			}
		}
		foreach ( $map['files'] as $path => $binding ) {
			$current = $this->read( $path );
			foreach ( array( Wstm167CurrentMainTransition::restore( $path, $current ), str_replace( "\n", "\r\n", $current ) ) as $foreign ) {
				try { Wstm167SelectedListenerTransition::restore( $path, $foreign ); self::fail( 'Old raw fallback accepted.' ); }
				catch ( RuntimeException $error ) { self::assertSame( 'CI diagnostic current source drift: ' . $path, $error->getMessage() ); }
			}
		}
	}

	public function test_forged_or_omitted_bridge_cannot_reseal_accepted_history(): void {
		$json = $this->read( 'tests/unit/fixtures/current-main-transition.json' ); $map = Wstm167CurrentMainTransition::load();
		$forgeries = array( '', $json . "\n" );
		foreach ( array_keys( $map ) as $key ) { $foreign = $map; unset( $foreign[ $key ] ); $forgeries[] = json_encode( $foreign, JSON_THROW_ON_ERROR ); }
		foreach ( array( 'hunks', 'start', 'before', 'after', 'current_raw_sha256', 'baseline_raw_sha256' ) as $key ) { $forgeries[] = str_replace( '"' . $key . '"', '"' . $key . '_foreign"', $json ); }
		foreach ( $forgeries as $foreign ) {
			try { Wstm167CurrentMainTransition::load( $foreign ); self::fail( 'Forged bridge accepted.' ); }
			catch ( RuntimeException $error ) { self::assertSame( 'Current main transition seal mismatch.', $error->getMessage() ); }
		}
	}
}
