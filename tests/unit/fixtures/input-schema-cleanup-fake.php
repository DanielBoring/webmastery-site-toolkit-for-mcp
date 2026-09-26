<?php

declare(strict_types=1);

/** Isolated storage adapter: exercises the actual cleanup engine without WordPress. */
final class Wstm126_Cleanup_Fake {
	public array $rows = array(
		'posts' => array(), 'postmeta' => array(), 'users' => array(), 'usermeta' => array(),
		'terms' => array(), 'term_taxonomy' => array(), 'term_relationships' => array(),
		'comments' => array(), 'commentmeta' => array(), 'links' => array(),
		'credentials' => array(), 'observation' => array(), 'cron' => array( 'original schedule' ),
	);
	public array $deleted = array();
	public string $retain = '';
	public int $reads = 0;
	public int $fresh_snapshot_bytes = 0;
	public ?Closure $during_delete = null;
	public string $run = 'wstm126-synthetic';

	public function read(): array {
		++$this->reads;
		if ( $this->fresh_snapshot_bytes > 0 ) {
			$snapshot = $this->rows;
			// Allocate on every read, rather than sharing a stored payload through COW.
			$snapshot['posts'][] = array(
				'ID' => '900', 'post_author' => '900', 'post_type' => 'page',
				'post_name' => 'foreign-baseline', 'post_date' => '2000-01-01 00:00:00',
				'guid' => 'https://example.test/?p=900', 'post_parent' => '0',
				'post_content' => str_repeat( 'x', $this->fresh_snapshot_bytes ),
			);
			return $snapshot;
		}
		return $this->rows;
	}

	public function seed( Wstm126_Cleanup $journal ): void {
		$journal->plan( 'observation', array( 'owner' => $this->run ) );
		$this->rows['observation'] = array( array( 'owner' => $this->run ) );
		$journal->observation_created();
		$actor = array( 'ID' => '40', 'user_login' => 'synthetic-actor', 'user_email' => 'actor@example.test', 'user_registered' => '2026-01-01 00:00:00' );
		$journal->plan( 'actor', array( 'user_login' => $actor['user_login'], 'user_email' => $actor['user_email'] ) );
		$this->rows['users'][] = $actor;
		$this->rows['usermeta'][] = array( 'user_id' => '40', 'meta_key' => 'capabilities' );
		$journal->created( 'actor', 'actors', '40', Wstm126_Cleanup::actor_identity( $actor ) );
		$post = array( 'ID' => '70', 'post_author' => '40', 'post_type' => 'page', 'post_name' => 'synthetic-marker', 'post_date' => '2026-01-01 00:00:00', 'guid' => 'https://example.test/?p=70', 'post_parent' => '0', 'post_title' => 'Original' );
		$journal->plan( 'post', array( 'post_name' => $post['post_name'], 'post_author' => '40', 'post_type' => 'page' ) );
		$this->rows['posts'][] = $post;
		$this->rows['postmeta'][] = array( 'post_id' => '70', 'meta_key' => 'owned' );
		$this->rows['term_relationships'][] = array( 'object_id' => '70', 'term_taxonomy_id' => '5', 'term_order' => '0' );
		$journal->created( 'post', 'posts', '70', Wstm126_Cleanup::post_identity( $post ) );
		$credential = array( 'actor' => 40, 'uuid' => 'synthetic-uuid', 'name' => $this->run, 'created' => 1234 );
		$journal->plan( 'credential', array( 'actor' => 40, 'name' => $this->run ) );
		$this->rows['credentials']['synthetic-uuid'] = $credential;
		$journal->created( 'credential', 'credentials', 'synthetic-uuid', $credential );
	}

	public function delete( string $kind, int $id, string $reference = '' ): void {
		$this->deleted[] = array( $kind, $id );
		if ( $this->during_delete ) { ( $this->during_delete )( $this, $kind, $id ); }
		if ( 'post' === $kind ) {
			if ( 'post' !== $this->retain && ! ( 'revision' === $this->retain && 71 === $id ) ) {
				$this->rows['posts'] = array_values( array_filter( $this->rows['posts'], static fn( $row ) => (int) $row['ID'] !== $id ) );
			}
			if ( 'postmeta' !== $this->retain ) {
				$this->rows['postmeta'] = array_values( array_filter( $this->rows['postmeta'], static fn( $row ) => (int) $row['post_id'] !== $id ) );
			}
			if ( 'term_relationships' !== $this->retain ) {
				$this->rows['term_relationships'] = array_values( array_filter( $this->rows['term_relationships'], static fn( $row ) => (int) $row['object_id'] !== $id ) );
			}
		} elseif ( 'credential' === $kind ) {
			if ( 'credential' !== $this->retain ) { unset( $this->rows['credentials'][ $reference ] ); }
		} elseif ( 'actor' === $kind ) {
			if ( 'actor' !== $this->retain ) { $this->rows['users'] = array(); }
			if ( 'usermeta' !== $this->retain ) { $this->rows['usermeta'] = array(); }
			if ( 'credential' !== $this->retain ) { $this->rows['credentials'] = array(); }
		} elseif ( 'observation' !== $this->retain ) {
			$this->rows['observation'] = array();
		}
	}

	public function revision(): void {
		$row = $this->rows['posts'][0];
		$row['ID'] = '71';
		$row['post_type'] = 'revision';
		$row['post_parent'] = '70';
		$row['post_name'] = '70-revision-v1';
		$this->rows['posts'][] = $row;
		$this->rows['postmeta'][] = array( 'post_id' => '71', 'meta_key' => 'owned revision' );
		$this->rows['term_relationships'][] = array( 'object_id' => '71', 'term_taxonomy_id' => '5', 'term_order' => '0' );
	}
}

final class Wstm126_Cleanup_Fake_DB {
	public string $posts = 'fake_posts';
	public string $postmeta = 'fake_postmeta';
	public string $users = 'fake_users';
	public string $usermeta = 'fake_usermeta';
	public string $terms = 'fake_terms';
	public string $term_taxonomy = 'fake_term_taxonomy';
	public string $term_relationships = 'fake_term_relationships';
	public string $comments = 'fake_comments';
	public string $commentmeta = 'fake_commentmeta';
	public string $links = 'fake_links';
	public string $options = 'fake_options';
	public string $last_error = '';
	public array $tables = array();
	public array $queries = array();

	public function prepare( string $sql, string $name ): string {
		return str_replace( '%s', "'" . $name . "'", $sql );
	}

	public function get_results( string $sql, string $format ): array {
		$this->queries[] = $sql;
		if ( preg_match( "/WHERE option_name = '([^']+)'/", $sql, $match ) ) {
			return $this->tables[ $match[1] ] ?? array();
		}
		if ( preg_match( '/FROM fake_([a-z_]+) ORDER BY/', $sql, $match ) ) {
			return $this->tables[ $match[1] ] ?? array();
		}
		throw new RuntimeException( 'Unexpected fake SQL.' );
	}
}

if ( 'cli' === PHP_SAPI && realpath( $argv[0] ?? '' ) === __FILE__ ) {
	$mode = $argv[1] ?? '';
	$directory = $argv[2] ?? '';
	$helper = realpath( $argv[3] ?? dirname( __DIR__, 2 ) . '/e2e/input-schema-cleanup.php' );
	if ( ! in_array( $mode, array( 'memory-success', 'memory-veto', 'memory-wire', 'setup-success', 'setup-date-failure', 'setup-record-failure' ), true )
		|| false === $helper || ! is_file( $helper ) || '128M' !== ini_get( 'memory_limit' ) ) {
		throw new RuntimeException( 'Invalid isolated cleanup fixture invocation.' );
	}
	require_once $helper;
	$emit = static function ( array $record ): void {
		echo json_encode( $record + array( 'memory_bytes' => memory_get_usage( true ), 'peak_bytes' => memory_get_peak_usage( true ) ), JSON_THROW_ON_ERROR ) . "\n";
	};
	$emit( array( 'phase' => 'start', 'mode' => $mode, 'memory_limit_bytes' => 134217728, 'helper_sha256' => hash_file( 'sha256', $helper ) ) );
	if ( str_starts_with( $mode, 'setup-' ) ) {
		function wstm126_require( bool $condition, string $message ): void {
			if ( ! $condition ) { throw new RuntimeException( $message ); }
		}
		if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }
		$GLOBALS['wpdb'] = new Wstm126_Cleanup_Fake_DB();
		$id = 70;
		$type = 'page';
		$seed_date = $seed_gmt = '2000-01-01 00:00:00';
		$GLOBALS['wpdb']->tables['posts'] = array( array(
			'ID' => '70', 'post_author' => '40', 'post_type' => 'page',
			'post_name' => 'synthetic-marker', 'guid' => 'https://example.test/?p=70',
			'post_date' => $seed_date, 'post_date_gmt' => 'setup-date-failure' === $mode ? 'wrong-date' : $seed_gmt,
		) );
		$journal = new class( 'setup-record-failure' === $mode ) {
			public bool $called = false;
			private bool $fail;
			public function __construct( bool $fail ) { $this->fail = $fail; }
			public function created( string $plan, string $kind, string $id, array $identity ): void {
				$this->called = true;
				if ( $this->fail ) { throw new RuntimeException( 'Synthetic journal failure.' ); }
			}
		};
		$source = str_replace( "\r\n", "\n", file_get_contents( dirname( __DIR__, 2 ) . '/e2e/input-schema-runner.php' ) );
		$start_marker = "\$created = array_column( wstm126_cleanup_snapshot()['posts'], null, 'ID' );";
		$end_marker = "\n\t}\n\t\$invoke = static function";
		if ( 1 !== substr_count( $source, $start_marker ) || 1 !== substr_count( $source, $end_marker ) ) {
			throw new RuntimeException( 'Runner setup fragment changed.' );
		}
		$start = strpos( $source, $start_marker );
		$end = strpos( $source, $end_marker );
		if ( $end <= $start ) { throw new RuntimeException( 'Runner setup fragment order changed.' ); }
		$failed = false;
		try { eval( substr( $source, $start, $end - $start ) ); } catch ( RuntimeException $error ) { $failed = true; }
		$emit( array( 'phase' => 'setup-result', 'failed' => $failed, 'created_retained' => isset( $created ), 'journal_called' => $journal->called, 'queries' => count( $GLOBALS['wpdb']->queries ) ) );
		exit( 0 );
	}
	$fake = new Wstm126_Cleanup_Fake();
	$fake->fresh_snapshot_bytes = 33554432;
	$first = $fake->read();
	$allocated = memory_get_usage( false );
	$second = $fake->read();
	$growth = memory_get_usage( false ) - $allocated;
	unset( $first, $second );
	$emit( array( 'phase' => 'independent-reads', 'payload_bytes' => $fake->fresh_snapshot_bytes, 'second_read_growth_bytes' => $growth ) );
	$read = static function () use ( $fake, $emit ): array {
		$emit( array( 'phase' => 'read-begin', 'read_index' => $fake->reads + 1 ) );
		$snapshot = $fake->read();
		$emit( array( 'phase' => 'read-end', 'read_index' => $fake->reads ) );
		return $snapshot;
	};
	$owner = str_repeat( 'c', 32 );
	$journal = new Wstm126_Cleanup( $directory . '/private/invocations', $directory . '/web', $directory . '/artifacts', $owner, 'http', $fake->run, $read, array( $fake, 'delete' ) );
	$fake->seed( $journal );
	$fake->revision();
	$journal->plan( 'session', array( 'actor' => 40 ) );
	$journal->observed_session( 'session', 40, 'synthetic-session', 'http://localhost/owned' );
	$fake->retain = 'memory-veto' === $mode ? 'post' : '';
	if ( 'memory-wire' === $mode ) { $journal->raw_response( 403, 'synthetic-denied-body' ); }
	$closed = false;
	$result = $journal->finish( static function () use ( $journal, &$closed ): void {
		$journal->observed_session( 'session', 40, 'synthetic-session', 'http://localhost/owned', true );
		$closed = true;
	}, true, 'memory-wire' === $mode );
	$path = $directory . '/private/invocations/' . $owner . '-http.json';
	$emit( array(
		'phase' => 'result', 'proof' => $result['proof'], 'error_present' => null !== $result['error'],
		'deletion_kinds' => array_column( $fake->deleted, 0 ), 'sessions_closed' => $closed,
		'journal_retained' => is_file( $path ), 'wire_retained' => is_file( $path . '.wire.jsonl' ),
		'actors_remaining' => count( $fake->rows['users'] ), 'credentials_remaining' => count( $fake->rows['credentials'] ),
		'observation_remaining' => count( $fake->rows['observation'] ),
	) );
}
