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
	public ?Closure $during_delete = null;
	public string $run = 'wstm126-synthetic';

	public function read(): array {
		++$this->reads;
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
