<?php
/**
 * Fail-closed, runner-only ownership journal. No credentials enter public proof.
 */

declare(strict_types=1);

final class Wstm126_Cleanup {
	private string $path;
	private array $file_identity;
	private array $state;
	private string $written = '';
	private Closure $read;
	private Closure $delete;
	private Closure $inspect;
	private ?array $raw_identity = null;
	private string $raw_digest = '';
	private int $raw_size = 0;
	private int $raw_count = 0;

	public function __construct( string $directory, string $webroot, string $artifact_directory, string $owner, string $boundary, string $run, callable $read, callable $delete, ?callable $inspect = null ) {
		self::check( 1 === preg_match( '/^[a-f0-9]{32}$/D', $owner ) && in_array( $boundary, array( 'direct', 'permission', 'ability', 'http', 'individual' ), true ), 'Invalid journal identity.' );
		$directory = self::directory( $directory );
		foreach ( array( $webroot, $artifact_directory ) as $excluded ) {
			$excluded = self::directory( $excluded );
			self::check( $directory !== $excluded && ! str_starts_with( $directory . '/', $excluded . '/' ), 'Journal must be outside webroot and artifacts.' );
		}
		if ( DIRECTORY_SEPARATOR !== '\\' ) {
			self::check( 0700 === ( fileperms( $directory ) & 0777 ), 'Journal directory must be private.' );
		}
		$this->read = Closure::fromCallable( $read );
		$this->delete = Closure::fromCallable( $delete );
		$this->inspect = null === $inspect ? static fn( string $path ): array => array( 'linked' => is_link( $path ), 'stat' => lstat( $path ) ) : Closure::fromCallable( $inspect );
		$this->path = $directory . '/' . $owner . '-' . $boundary . '.json';
		self::check( ! is_link( $this->path ) && ! file_exists( $this->path ), 'Journal ownership collision.' );
		$mask = umask( 0077 );
		try {
			$file = fopen( $this->path, 'x+b' );
		} finally {
			umask( $mask );
		}
		self::check( false !== $file, 'Cannot exclusively create journal.' );
		$this->file_identity = fstat( $file );
		fclose( $file );
		$this->state = array(
			'version' => 1, 'owner' => $owner, 'boundary' => $boundary, 'run' => $run,
			'baseline' => self::digest( ( $this->read )() ), 'plans' => array(),
			'actors' => array(), 'posts' => array(), 'credentials' => array(), 'sessions' => array(),
		);
		$this->save();
	}

	public function observation_created(): void {
		self::check( isset( $this->state['plans']['observation'] ), 'Missing observation creation plan.' );
		$this->state['plans']['observation']['resolved'] = true;
		$this->save();
	}

	private static function check( bool $condition, string $message ): void {
		if ( ! $condition ) { throw new RuntimeException( $message ); }
	}

	private static function directory( string $directory ): string {
		$real = realpath( $directory );
		self::check( false !== $real && is_dir( $directory ), 'Missing journal boundary directory.' );
		$cursor = rtrim( $directory, '/\\' );
		while ( dirname( $cursor ) !== $cursor ) {
			self::check( ! is_link( $cursor ), 'Symlink in journal path.' );
			$cursor = dirname( $cursor );
		}
		return str_replace( '\\', '/', $real );
	}

	private function open() {
		return $this->open_owned( $this->path, $this->file_identity );
	}

	private function open_owned( string $path, array $identity ) {
		clearstatcache( true, $path );
		self::check( ! is_link( $path ) && is_file( $path ), 'Journal is missing or linked.' );
		$observed = ( $this->inspect )( $path );
		self::check( false === $observed['linked'] && is_array( $observed['stat'] ), 'Journal is missing or linked.' );
		$stat = $observed['stat'];
		self::check( $stat['ino'] === $identity['ino'] && $stat['dev'] === $identity['dev'] && $stat['uid'] === $identity['uid'] && $stat['gid'] === $identity['gid'] && 1 === $stat['nlink'], 'Journal file ownership changed.' );
		if ( DIRECTORY_SEPARATOR !== '\\' ) { self::check( 0600 === ( $stat['mode'] & 0777 ), 'Journal permissions changed.' ); }
		$file = fopen( $path, 'r+b' );
		self::check( false !== $file && flock( $file, LOCK_EX ), 'Cannot lock journal.' );
		$opened = fstat( $file );
		self::check( $opened['ino'] === $stat['ino'] && $opened['dev'] === $stat['dev'], 'Journal changed while opening.' );
		return $file;
	}

	private function save(): void {
		$file = $this->open();
		try {
			self::check( stream_get_contents( $file ) === $this->written, 'Journal content changed before write.' );
			$bytes = json_encode( $this->state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES );
			self::check( ftruncate( $file, 0 ) && rewind( $file ) && strlen( $bytes ) === fwrite( $file, $bytes ) && fflush( $file ), 'Cannot durably write journal.' );
			if ( function_exists( 'fsync' ) ) { self::check( fsync( $file ), 'Cannot sync journal.' ); }
			$this->written = $bytes;
		} finally { fclose( $file ); }
	}

	public function plan( string $key, array $identity ): void {
		self::check( ! isset( $this->state['plans'][ $key ] ), 'Duplicate creation plan.' );
		$this->state['plans'][ $key ] = array( 'identity' => $identity, 'resolved' => false );
		$this->save();
	}

	public function created( string $key, string $kind, string $reference, array $identity ): void {
		self::check( isset( $this->state['plans'][ $key ] ) && false === $this->state['plans'][ $key ]['resolved'], 'Missing pending creation plan.' );
		self::check( in_array( $kind, array( 'actors', 'posts', 'credentials' ), true ) && ! isset( $this->state[ $kind ][ $reference ] ), 'Creation ownership collision.' );
		self::check( count( $this->state[ $kind ] ) < ( 'posts' === $kind ? 5 : 2 ), 'Fixture creation bound exceeded.' );
		foreach ( $this->state['plans'][ $key ]['identity'] as $field => $expected ) {
			self::check( array_key_exists( $field, $identity ) && $identity[ $field ] === $expected, 'Creation did not match ownership plan.' );
		}
		$this->state[ $kind ][ $reference ] = $identity;
		$this->state['plans'][ $key ]['resolved'] = true;
		$this->save();
	}

	public function observed_session( string $plan, int $actor, string $session, string $url, bool $closed = false ): void {
		self::check( isset( $this->state['actors'][ $actor ] ) && '' !== $session, 'Session has no owned actor.' );
		$ref = hash( 'sha256', $session );
		$identity = array( 'actor' => $actor, 'url' => hash( 'sha256', $url ), 'closed' => false );
		if ( $closed ) {
			self::check( isset( $this->state['sessions'][ $ref ] ) && $identity === $this->state['sessions'][ $ref ], 'Unowned session deletion.' );
			$this->state['sessions'][ $ref ]['closed'] = true;
		} else {
			self::check( isset( $this->state['plans'][ $plan ] ), 'Missing session creation plan.' );
			if ( isset( $this->state['sessions'][ $ref ] ) ) {
				self::check( $identity === $this->state['sessions'][ $ref ], 'Session ownership collision.' );
			}
			$this->state['sessions'][ $ref ] = $identity;
			$this->state['plans'][ $plan ]['resolved'] = true;
		}
		$this->save();
	}

	public function response( array $witness ): void {
		$this->state['http'][] = $witness;
		$this->save();
	}

	private static function stream_hash( $file ): string {
		$position = ftell( $file );
		self::check( false !== $position && rewind( $file ), 'Cannot inspect private evidence bytes.' );
		$hash = hash_init( 'sha256' );
		hash_update_stream( $hash, $file );
		self::check( 0 === fseek( $file, $position ), 'Cannot restore private evidence position.' );
		return hash_final( $hash );
	}

	/** Persist exact bytes before any parser or redactor can reject the response. */
	public function raw_response( int $status, string $body ): void {
		$path = $this->path . '.wire.jsonl';
		if ( null === $this->raw_identity ) {
			$this->state['wire'] = array( 'pending' => true, 'file' => basename( $path ) );
			$this->save();
			self::check( ! file_exists( $path ) && ! is_link( $path ), 'Private wire ownership collision.' );
			$mask = umask( 0077 );
			try { $file = fopen( $path, 'x+b' ); } finally { umask( $mask ); }
			self::check( false !== $file, 'Cannot exclusively create private wire evidence.' );
			$this->raw_identity = fstat( $file );
			fclose( $file );
			$this->raw_digest = hash( 'sha256', '' );
		}
		$file = $this->open_owned( $path, $this->raw_identity );
		try {
			self::check( $this->raw_size === fstat( $file )['size'] && $this->raw_digest === self::stream_hash( $file ), 'Private wire evidence changed.' );
			$line = json_encode( array( 'sequence' => $this->raw_count, 'status' => $status, 'bytes' => strlen( $body ), 'sha256' => hash( 'sha256', $body ), 'body_base64' => base64_encode( $body ) ), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES ) . "\n";
			self::check( 0 === fseek( $file, 0, SEEK_END ) && strlen( $line ) === fwrite( $file, $line ) && fflush( $file ), 'Cannot persist private raw response.' );
			if ( function_exists( 'fsync' ) ) { self::check( fsync( $file ), 'Cannot sync private raw response.' ); }
			$this->raw_size += strlen( $line );
			++$this->raw_count;
			$this->raw_digest = self::stream_hash( $file );
		} finally { fclose( $file ); }
		$this->state['wire'] = array( 'pending' => false, 'file' => basename( $path ), 'bytes' => $this->raw_size, 'sha256' => $this->raw_digest, 'responses' => $this->raw_count );
		$this->save();
	}

	private function verify_wire(): void {
		if ( ! isset( $this->state['wire'] ) ) { return; }
		self::check( false === $this->state['wire']['pending'] && null !== $this->raw_identity, 'Private wire creation is unresolved.' );
		$file = $this->open_owned( $this->path . '.wire.jsonl', $this->raw_identity );
		try {
			self::check( $this->raw_size === fstat( $file )['size'] && $this->raw_digest === self::stream_hash( $file ), 'Private wire evidence changed.' );
		} finally { fclose( $file ); }
	}

	private function retire_wire(): void {
		if ( null === $this->raw_identity ) { return; }
		$this->verify_wire();
		$path = $this->path . '.wire.jsonl';
		self::check( unlink( $path ), 'Cannot retire exact private wire spool.' );
		clearstatcache( true, $path );
		self::check( ! file_exists( $path ) && ! is_link( $path ), 'Private wire spool remains.' );
		$this->state['wire']['retired'] = true;
		$this->save();
		$this->raw_identity = null;
	}

	private function retained_wire(): ?array {
		if ( null === $this->raw_identity ) { return null; }
		return array( 'file' => basename( $this->path ) . '.wire.jsonl', 'bytes' => $this->raw_size, 'sha256' => $this->raw_digest, 'responses' => $this->raw_count, 'retained' => true );
	}

	public static function actor_identity( array $row ): array {
		$identity = array();
		foreach ( array( 'ID', 'user_login', 'user_email', 'user_registered' ) as $key ) {
			self::check( isset( $row[ $key ] ), 'Incomplete actor identity.' );
			$identity[ $key ] = (string) $row[ $key ];
		}
		return $identity;
	}

	public static function post_identity( array $row ): array {
		$identity = array();
		foreach ( array( 'ID', 'post_author', 'post_type', 'post_name', 'post_date', 'guid' ) as $key ) {
			self::check( isset( $row[ $key ] ), 'Incomplete post identity.' );
			$identity[ $key ] = (string) $row[ $key ];
		}
		return $identity;
	}

	public static function credential_identity( int $actor, array $credential ): array {
		foreach ( array( 'uuid', 'name', 'created', 'password' ) as $key ) {
			self::check( isset( $credential[ $key ] ), 'Incomplete credential identity.' );
		}
		return array( 'actor' => $actor, 'uuid' => $credential['uuid'], 'name' => $credential['name'], 'created' => $credential['created'], 'verifier_sha256' => hash( 'sha256', $credential['password'] ) );
	}

	private static function digest( array $snapshot ): array {
		$result = array();
		foreach ( $snapshot as $table => $rows ) { $result[ $table ] = hash( 'sha256', serialize( $rows ) ); }
		return $result;
	}

	private function owned_posts( array $snapshot ): array {
		$ids = array_map( 'intval', array_keys( $this->state['posts'] ) );
		foreach ( $snapshot['posts'] as $row ) {
			if ( 'revision' === $row['post_type'] && in_array( (int) $row['post_parent'], $ids, true ) ) {
				$parent = $this->state['posts'][ $row['post_parent'] ] ?? null;
				self::check( is_array( $parent ) && (string) $row['post_author'] === $parent['post_author']
					&& (string) $row['post_name'] === $row['post_parent'] . '-revision-v1', 'Foreign revision ownership.' );
				$ids[] = (int) $row['ID'];
			}
		}
		self::check( count( $ids ) <= 32, 'Fixture revision bound exceeded.' );
		return $ids;
	}

	private function without_owned( array $snapshot ): array {
		$posts = $this->owned_posts( $snapshot );
		$actors = array_map( 'intval', array_keys( $this->state['actors'] ) );
		foreach ( array( 'posts' => array( 'ID', $posts ), 'postmeta' => array( 'post_id', $posts ), 'users' => array( 'ID', $actors ), 'usermeta' => array( 'user_id', $actors ) ) as $table => [ $key, $ids ] ) {
			$snapshot[ $table ] = array_values( array_filter( $snapshot[ $table ], static fn( $row ) => ! in_array( (int) $row[ $key ], $ids, true ) ) );
		}
		$snapshot['observation'] = array();
		return $snapshot;
	}

	private function validate( array $snapshot ): void {
		foreach ( $this->state['plans'] as $plan ) { self::check( true === $plan['resolved'], 'Unresolved creation; preserve ownership evidence.' ); }
		foreach ( array( 'actors' => 'users', 'posts' => 'posts' ) as $kind => $table ) {
			$rows = array_column( $snapshot[ $table ], null, 'ID' );
			foreach ( $this->state[ $kind ] as $id => $identity ) {
				self::check( isset( $rows[ $id ] ) && $identity === ( 'actors' === $kind ? self::actor_identity( $rows[ $id ] ) : self::post_identity( $rows[ $id ] ) ), 'Owned identity changed; no cleanup allowed.' );
			}
		}
		$owned = $this->owned_posts( $snapshot );
		foreach ( $snapshot['posts'] as $row ) {
			self::check( ! isset( $this->state['actors'][ $row['post_author'] ] ) || in_array( (int) $row['ID'], $owned, true ), 'Actor owns unexpected content; implicit deletion refused.' );
		}
		foreach ( $this->state['credentials'] as $uuid => $identity ) {
			self::check( isset( $snapshot['credentials'][ $uuid ] ) && $identity === $snapshot['credentials'][ $uuid ], 'Credential identity changed.' );
		}
		self::check( count( $this->state['credentials'] ) === count( $this->owned_credentials( $snapshot ) ), 'Unexpected actor credential.' );
		self::check( array( array( 'owner' => $this->state['run'] ) ) === $snapshot['observation'], 'Observation ownership changed.' );
		$this->validate_scope( $snapshot );
	}

	private function validate_scope( array $snapshot ): void {
		$projection = $this->without_owned( $snapshot );
		$projection['credentials'] = array_diff_key( $snapshot['credentials'], $this->state['credentials'] );
		self::check( $this->state['baseline'] === self::digest( $projection ), 'Preexisting state or cron changed.' );
	}

	private function owned_credentials( array $snapshot ): array {
		return array_filter( $snapshot['credentials'], fn( $row ) => isset( $this->state['actors'][ $row['actor'] ] ) );
	}

	private function validate_actors( array $snapshot ): void {
		$actors = array_column( $snapshot['users'], null, 'ID' );
		foreach ( $this->state['actors'] as $id => $identity ) {
			self::check( isset( $actors[ $id ] ) && $identity === self::actor_identity( $actors[ $id ] ), 'Actor changed during cleanup.' );
		}
		foreach ( $this->state['credentials'] as $uuid => $identity ) {
			self::check( isset( $snapshot['credentials'][ $uuid ] ) && $identity === $snapshot['credentials'][ $uuid ], 'Credential changed during cleanup.' );
		}
		self::check( count( $this->state['credentials'] ) === count( $this->owned_credentials( $snapshot ) ), 'Credential set changed during cleanup.' );
		self::check( array( array( 'owner' => $this->state['run'] ) ) === $snapshot['observation'], 'Observation changed during cleanup.' );
	}

	private function validate_content_absent( array $snapshot, array $post_ids ): void {
		foreach ( $snapshot['posts'] as $row ) {
			self::check( ! in_array( (int) $row['ID'], $post_ids, true ) && ! isset( $this->state['actors'][ $row['post_author'] ] ), 'Post or revision retained; actors and credentials preserved.' );
		}
		foreach ( $snapshot['postmeta'] as $row ) {
			self::check( ! in_array( (int) $row['post_id'], $post_ids, true ), 'Post metadata retained; actors preserved.' );
		}
	}

	public function finish( callable $close_sessions, bool $complete, bool $retain_wire = false ): array {
		$proof = array_fill_keys( array( 'identity_validated', 'sessions_closed', 'posts_absent', 'actors_absent', 'credentials_absent', 'observation_absent', 'baseline_restored', 'journal_retired' ), false );
		try {
			$file = $this->open();
			try { $stored = json_decode( stream_get_contents( $file ), true, 512, JSON_THROW_ON_ERROR ); } finally { fclose( $file ); }
			self::check( $stored === $this->state, 'Malformed or modified ownership journal.' );
			$this->verify_wire();
			$before = ( $this->read )();
			$this->validate( $before );
			$proof['identity_validated'] = true;
			$post_ids = $this->owned_posts( $before );
			$this->state['deletion_posts'] = array_map( array( self::class, 'post_identity' ), array_values( array_filter( $before['posts'], static fn( $row ) => in_array( (int) $row['ID'], $post_ids, true ) ) ) );
			$this->save();
			$identities = array_column( $this->state['deletion_posts'], null, 'ID' );
			foreach ( array_reverse( $post_ids ) as $id ) {
				$live = ( $this->read )();
				$this->validate_actors( $live );
				$this->validate_scope( $live );
				$rows = array_column( $live['posts'], null, 'ID' );
				self::check( isset( $rows[ $id ] ) && $identities[ $id ] === self::post_identity( $rows[ $id ] ), 'Post changed during cleanup.' );
				( $this->delete )( 'post', $id );
				$check = ( $this->read )();
				foreach ( $check['posts'] as $row ) { self::check( (int) $row['ID'] !== $id, 'Post deletion vetoed; remaining ownership preserved.' ); }
				foreach ( $check['postmeta'] as $row ) { self::check( (int) $row['post_id'] !== $id, 'Post metadata retained; remaining ownership preserved.' ); }
			}
			$after = ( $this->read )();
			$this->validate_content_absent( $after, $post_ids );
			$proof['posts_absent'] = true;
			$this->validate_actors( $after );
			$this->validate_scope( $after );
			$close_sessions();
			foreach ( $this->state['sessions'] as $session ) { self::check( true === $session['closed'], 'HTTP session has no observed successful DELETE.' ); }
			$proof['sessions_closed'] = true;
			$after = ( $this->read )();
			$this->validate_actors( $after );
			$this->validate_scope( $after );
			$this->validate_content_absent( $after, $post_ids );
			foreach ( $this->state['credentials'] as $uuid => $credential ) {
				$live = ( $this->read )();
				$this->validate_scope( $live );
				$this->validate_content_absent( $live, $post_ids );
				self::check( array( array( 'owner' => $this->state['run'] ) ) === $live['observation'], 'Observation changed before credential revocation.' );
				self::check( isset( $live['credentials'][ $uuid ] ) && $credential === $live['credentials'][ $uuid ], 'Credential replaced before revocation.' );
				$actors = array_column( $live['users'], null, 'ID' );
				$actor = $credential['actor'];
				self::check( isset( $actors[ $actor ] ) && $this->state['actors'][ $actor ] === self::actor_identity( $actors[ $actor ] ), 'Actor replaced before credential revocation.' );
				foreach ( $live['posts'] as $row ) { self::check( ! isset( $this->state['actors'][ $row['post_author'] ] ), 'Actor content appeared before credential revocation.' ); }
				( $this->delete )( 'credential', (int) $actor, (string) $uuid );
				$live = ( $this->read )();
				self::check( ! isset( $live['credentials'][ $uuid ] ), 'Credential revocation vetoed; actor and markers preserved.' );
			}
			$after = ( $this->read )();
			self::check( array() === $this->owned_credentials( $after ), 'Credentials remain; actor deletion refused.' );
			$proof['credentials_absent'] = true;
			$this->state['credentials_revoked'] = true;
			$this->save();
			foreach ( $this->state['actors'] as $id => $identity ) {
				$live = ( $this->read )();
				$this->validate_scope( $live );
				$this->validate_content_absent( $live, $post_ids );
				$actors = array_column( $live['users'], null, 'ID' );
				self::check( isset( $actors[ $id ] ) && $identity === self::actor_identity( $actors[ $id ] ), 'Actor replaced before deletion.' );
				foreach ( $live['posts'] as $row ) { self::check( (int) $row['post_author'] !== (int) $id, 'Actor acquired content before deletion.' ); }
				foreach ( $live['links'] ?? array() as $row ) { self::check( (int) $row['link_owner'] !== (int) $id, 'Actor acquired links before deletion.' ); }
				self::check( array() === $this->owned_credentials( $live ), 'Actor credential reappeared before deletion.' );
				self::check( array( array( 'owner' => $this->state['run'] ) ) === $live['observation'], 'Observation changed before actor deletion.' );
				( $this->delete )( 'actor', (int) $id );
				$live = ( $this->read )();
				foreach ( $live['users'] as $row ) { self::check( (int) $row['ID'] !== (int) $id, 'Actor deletion vetoed.' ); }
				foreach ( $live['usermeta'] as $row ) { self::check( (int) $row['user_id'] !== (int) $id, 'Actor metadata retained.' ); }
				foreach ( $live['credentials'] as $row ) { self::check( (int) $row['actor'] !== (int) $id, 'Actor credential retained.' ); }
			}
			$after = ( $this->read )();
			foreach ( $after['users'] as $row ) { self::check( ! isset( $this->state['actors'][ $row['ID'] ] ), 'Actor remains.' ); }
			foreach ( $after['usermeta'] as $row ) { self::check( ! isset( $this->state['actors'][ $row['user_id'] ] ), 'Actor metadata remains.' ); }
			$proof['actors_absent'] = true;
			self::check( array() === $this->owned_credentials( $after ), 'Actor credentials remain.' );
			$proof['credentials_absent'] = true;
			self::check( array( array( 'owner' => $this->state['run'] ) ) === $after['observation'], 'Observation ownership changed during cleanup.' );
			$projected = $after;
			$projected['observation'] = array();
			self::check( $this->state['baseline'] === self::digest( $projected ), 'Baseline changed during cleanup.' );
			( $this->delete )( 'observation', 0 );
			$after = ( $this->read )();
			self::check( array() === $after['observation'], 'Observation option remains.' );
			$proof['observation_absent'] = true;
			self::check( $this->state['baseline'] === self::digest( $after ), 'Baseline or scheduled fixture events remain.' );
			$proof['baseline_restored'] = true;
			$this->state['proof'] = $proof;
			$this->save();
			if ( $complete ) {
				if ( $retain_wire && null !== $this->raw_identity ) {
					$this->verify_wire();
					$this->state['wire']['retained'] = true;
					$this->save();
					return array( 'proof' => $proof, 'error' => 'Private failed-response evidence retained; stage retirement is blocked.', 'raw_evidence' => $this->retained_wire() );
				}
				$this->retire_wire();
				$file = $this->open();
				fclose( $file );
				self::check( unlink( $this->path ), 'Cannot retire verified journal.' );
				clearstatcache( true, $this->path );
				self::check( ! file_exists( $this->path ) && ! is_link( $this->path ), 'Journal retirement could not be observed.' );
				$proof['journal_retired'] = true;
			}
			return array( 'proof' => $proof, 'error' => null, 'raw_evidence' => $this->retained_wire() );
		} catch ( Throwable $error ) {
			return array( 'proof' => $proof, 'error' => $error->getMessage(), 'raw_evidence' => $this->retained_wire() );
		}
	}
}

/** Fresh SQL reads deliberately bypass the object cache and deletion return values. */
function wstm126_cleanup_snapshot(): array {
	global $wpdb;
	$snapshot = array();
	foreach ( array( 'posts' => 'ID', 'postmeta' => 'meta_id', 'users' => 'ID', 'usermeta' => 'umeta_id', 'terms' => 'term_id', 'term_taxonomy' => 'term_taxonomy_id', 'term_relationships' => 'object_id, term_taxonomy_id', 'comments' => 'comment_ID', 'commentmeta' => 'meta_id', 'links' => 'link_id' ) as $table => $order ) {
		$rows = $wpdb->get_results( "SELECT * FROM {$wpdb->$table} ORDER BY {$order}", ARRAY_A );
		if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) { throw new RuntimeException( 'Cleanup snapshot query failed.' ); }
		$snapshot[ $table ] = $rows;
	}
	$snapshot['credentials'] = array();
	foreach ( $snapshot['usermeta'] as $row ) {
		if ( '_application_passwords' === $row['meta_key'] ) {
			$credentials = unserialize( $row['meta_value'], array( 'allowed_classes' => false ) );
			if ( ! is_array( $credentials ) ) { throw new RuntimeException( 'Malformed application password references.' ); }
			foreach ( $credentials as $credential ) {
				$snapshot['credentials'][ $credential['uuid'] ] = Wstm126_Cleanup::credential_identity( (int) $row['user_id'], $credential );
			}
		}
	}
	$snapshot['observation'] = array();
	foreach ( array( 'cron', 'wstm126_http' ) as $name ) {
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ), ARRAY_A );
		if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) { throw new RuntimeException( 'Cleanup option query failed.' ); }
		if ( 'cron' === $name ) { $snapshot['cron'] = $rows; continue; }
		foreach ( $rows as $row ) {
			$value = unserialize( $row['option_value'], array( 'allowed_classes' => false ) );
			$snapshot['observation'][] = array( 'owner' => is_array( $value ) ? ( $value['owner'] ?? null ) : null );
		}
	}
	return $snapshot;
}

/** Preserve only synthetic JSON; unexpected bodies have a bounded hash-only witness. */
function wstm126_safe_body( string $body, array $secrets = array() ): array {
	$witness = array( 'sha256' => hash( 'sha256', $body ), 'bytes' => strlen( $body ), 'redacted' => true );
	foreach ( $secrets as $secret ) {
		if ( is_string( $secret ) && '' !== $secret && str_contains( $body, $secret ) ) { return $witness; }
	}
	if ( strlen( $body ) > 262144 || preg_match( '/authorization|password|session|secret|token|cookie|user_pass|private.?key/i', $body ) ) { return $witness; }
	try { $decoded = json_decode( $body, true, 512, JSON_THROW_ON_ERROR ); } catch ( Throwable $error ) { return $witness; }
	if ( ! is_array( $decoded ) || '2.0' !== ( $decoded['jsonrpc'] ?? null ) ) { return $witness; }
	$witness['body'] = $body;
	$witness['redacted'] = false;
	return $witness;
}

function wstm126_capture_http( Wstm126_Cleanup $journal, int $actor, string $plan, string $url, array $args, int $status, string $body, string $session, array $secrets = array() ): array {
	$journal->raw_response( $status, $body );
	$witness = wstm126_safe_body( $body, array_merge( $secrets, array( $session, $args['headers']['Mcp-Session-Id'] ?? '' ) ) );
	$witness['status'] = $status;
	$journal->response( $witness );
	if ( '' !== $session ) { $journal->observed_session( $plan, $actor, $session, $url ); }
	if ( 'DELETE' === ( $args['method'] ?? '' ) && in_array( $status, array( 200, 202, 204 ), true ) ) {
		$session = $args['headers']['Mcp-Session-Id'] ?? '';
		if ( is_string( $session ) && '' !== $session ) { $journal->observed_session( $plan, $actor, $session, $url, true ); }
	}
	return $witness;
}

function wstm126_safe_failure( string $message, array $secrets = array() ): string {
	foreach ( $secrets as $secret ) {
		if ( is_string( $secret ) && '' !== $secret && str_contains( $message, $secret ) ) { return 'Failure detail withheld (sha256 ' . hash( 'sha256', $message ) . ').'; }
	}
	if ( strlen( $message ) > 512 || preg_match( '/[{}]|authorization|password|secret|token|cookie/i', $message ) ) {
		return 'Failure detail withheld (sha256 ' . hash( 'sha256', $message ) . ').';
	}
	return $message;
}
