<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/e2e/bounded-list-plan.php';

final class BoundedShardPlanTest extends TestCase {
	private static function state(): array {
		return array(
			'cpt' => 'w121_0123456789ab',
			'catalog' => array(
				'post' => range( 20000, 1 ), 'attachment' => range( 30000, 20001 ),
				'page' => range( 30203, 30001 ), 'w121_0123456789ab' => range( 30408, 30204 ),
			),
		);
	}

	public function test_exact_exhaustive_disjoint_plan_preserves_whole_sequences(): void {
		$seen = array();
		$traversals = array();
		$edges = 0;
		foreach ( Wstm121Plan::WORKERS as $shard => $count ) {
			$jobs = Wstm121Plan::jobs( self::state(), $shard );
			self::assertCount( $count, $jobs );
			$sequences = array();
			foreach ( $jobs as $job ) {
				self::assertArrayNotHasKey( $job['coverage_key'], $seen );
				$seen[ $job['coverage_key'] ] = true;
				if ( 'traversal' === $job['kind'] ) {
					$sequences[ $job['sequence_key'] ][] = $job['page'];
					$traversals[ $job['sequence_key'] ] = true;
					self::assertSame( 100, $job['per_page'] );
				} else {
					++$edges;
				}
			}
			self::assertCount( 6, $sequences );
			foreach ( $sequences as $pages ) {
				self::assertSame( range( 1, count( $pages ) ), $pages, 'A traversal cannot be split or reset between pages.' );
			}
		}
		self::assertCount( 4960, $seen );
		self::assertCount( 42, $traversals );
		self::assertSame( 82, $edges );
		self::assertSame( 17280, array_sum( Wstm121Plan::PHASE_SECONDS ) );
		self::assertLessThan( Wstm121Plan::NATIVE_SECONDS, array_sum( Wstm121Plan::PHASE_SECONDS ) );
		self::assertLessThan( 21600, Wstm121Plan::JOB_SECONDS );
	}

	public function test_default_full_and_tie_cases_keep_the_original_input_semantics(): void {
		$jobs = array_column( Wstm121Plan::jobs( self::state(), 'posts' ), null, 'coverage_key' );
		self::assertSame( array(), $jobs['posts:cold:dense:edge:default']['input'] );
		self::assertTrue( $jobs['posts:cold:dense:edge:default']['controlled_default_summary'] );
		self::assertSame( array( 'fields' => 'full', 'per_page' => 100 ), $jobs['posts:warm:dense:edge:full']['input'] );
		self::assertSame( array( 'orderby' => 'title', 'order' => 'ASC', 'page' => 2, 'per_page' => 100 ), $jobs['posts:cold:dense:edge:tie:title:ASC']['input'] );
		$scores = array_column( Wstm121Plan::jobs( self::state(), 'seo' ), null, 'coverage_key' );
		self::assertSame( array( 'post_type' => 'post' ), $scores['seo:cold:dense:edge:default']['input'] );
		self::assertSame( 10, $scores['seo:cold:dense:edge:default']['per_page'] );
	}

	public function test_smaller_or_reordered_fixtures_cannot_be_used_as_acceptance(): void {
		foreach ( array( 'small', 'duplicate', 'order', 'float', 'namespace' ) as $mutation ) {
			$state = self::state();
			if ( 'small' === $mutation ) { array_pop( $state['catalog']['post'] ); }
			if ( 'duplicate' === $mutation ) { $state['catalog']['post'][1] = $state['catalog']['post'][0]; }
			if ( 'order' === $mutation ) { $state['catalog']['post'] = array_reverse( $state['catalog']['post'] ); }
			if ( 'float' === $mutation ) { $state['catalog']['post'][0] = 20000.0; }
			if ( 'namespace' === $mutation ) { $state['cpt'] = 'post'; }
			try {
				Wstm121Plan::jobs( $state, 'posts' );
				self::fail( 'Invalid fixture accepted: ' . $mutation );
			} catch ( RuntimeException $error ) {
				self::assertNotSame( '', $error->getMessage() );
			}
		}
	}

	public function test_typed_counter_and_hash_guards_reject_coercion(): void {
		foreach ( array( -1, 1.0, '1', true, null ) as $value ) {
			try {
				Wstm121Plan::unsigned( $value, 'counter' );
				self::fail( 'Invalid counter accepted.' );
			} catch ( RuntimeException $error ) {
				self::assertStringContainsString( 'nonnegative integer', $error->getMessage() );
			}
		}
		self::assertSame( 0, Wstm121Plan::unsigned( 0, 'counter' ) );
		$this->expectException( RuntimeException::class );
		Wstm121Plan::digest( str_repeat( 'z', 64 ) );
	}
}
