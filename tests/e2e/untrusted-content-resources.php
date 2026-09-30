<?php

declare(strict_types=1);

require_once __DIR__ . '/untrusted-content-files.php';

/**
 * Private, source-bound fixture ledger. No WordPress permission hooks are changed.
 *
 * Call intent before each write, then register its result immediately. Password
 * names are bound verbatim to their actor's intent, including adversarial text.
 * user_created() journals identity before capability setup; user() seals the
 * required capabilities afterward. Unsealed actors cannot authorize cleanup.
 * MCP clientInfo names are "<run>:<boundary>:<role>".
 * Only resume($journalPath, $binding) opens an existing journal; the run comes
 * from its validated private state. Cleanup checkpoints include the public proof.
 * Lifecycle-owned regular wire files may share the existing private directory.
 * After a successful runner/wire verdict and original CLI + HTTP attestation,
 * a fresh process may retire($expectedProof). cleanup() never retires the journal.
 */
final class Wstm108_Resources {
	private const ROLES = array( 'administrator', 'subscriber', 'reader' );
	private const BOUNDARIES = array( 'gateway', 'individual' );
	private const TYPES = array( 'post', 'page', 'wstm108_record', 'attachment', 'revision' );
	private const INTENTS = array(
		'user' => array( 'role' ), 'password' => array( 'id', 'name' ), 'session' => array( 'id', 'boundary' ),
		'post' => array( 'author', 'type', 'slug', 'parent' ), 'comment' => array( 'author', 'post', 'parent' ),
		'file' => array( 'path', 'sha256' ), 'mutation' => array( 'resource', 'id' ),
	);
	private const LIMIT = 501;
	private string $path;
	private array $snapshot;
	private array $state;
	private bool $independentlyAnchored = false;

	public function __construct( string $journalPath, array $binding, string $run ) {
		self::binding( $binding, $run );
		$parent = Wstm108_Files::directory( dirname( $journalPath ) );
		self::ensure( 'Windows' === PHP_OS_FAMILY || 0700 === ( $parent['mode'] & 0777 ) );
		$this->path = $journalPath;
		$this->state = array(
			'version' => 1, 'binding' => $binding, 'run' => $run, 'parent' => $parent,
			'intents' => array(), 'users' => array(), 'ready_users' => array(), 'passwords' => array(), 'sessions' => array(),
			'posts' => array(), 'comments' => array(), 'files' => array(), 'upload' => null,
			'deleted_files' => array(),
			'phase' => 'recording', 'cleanup' => array(), 'failed' => 0, 'first_error' => null, 'proof' => null,
		);
		$this->snapshot = Wstm108_Files::create( $this->path, $this->encode() );
		$this->state['journal_identity'] = $this->snapshot['identity'];
		$this->save();
		$this->independentlyAnchored = true;
	}

	/** Enroll this creation-time anchor outside the journal before creating actors. */
	public function journal_identity(): array {
		self::ensure( $this->independentlyAnchored );
		$this->guard();
		return $this->snapshot['identity'];
	}

	private static function ensure( bool $condition ): void {
		if ( ! $condition ) {
			// Never include paths, IDs, credentials, tokens or external API messages.
			throw new RuntimeException( 'WSTM108 Resource ownership or completeness check failed; retain private evidence.' );
		}
	}

	private static function binding( array $binding, string $run ): void {
		self::binding_schema( $binding );
		self::ensure( 1 === preg_match( '/^wstm108-[a-f0-9]{16}$/D', $run ) );
	}

	private static function binding_schema( array $binding ): void {
		self::ensure( array_keys( $binding ) === array( 'owner', 'project', 'source_sha', 'tree_sha', 'package_sha256' ) );
		foreach ( array( 'owner' => '/^[a-f0-9]{32}$/D', 'project' => '/^[a-z0-9][a-z0-9_-]*$/D',
			'source_sha' => '/^[a-f0-9]{40}$/D', 'tree_sha' => '/^[a-f0-9]{40}$/D' ) as $key => $pattern ) {
			self::ensure( is_string( $binding[ $key ] ) && 1 === preg_match( $pattern, $binding[ $key ] ) );
		}
		self::ensure( null === $binding['package_sha256'] || ( is_string( $binding['package_sha256'] ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $binding['package_sha256'] ) ) );
	}

	public static function resume( string $journalPath, array $binding, ?array $expectedIdentity = null ): self {
		self::binding_schema( $binding );
		$snapshot = Wstm108_Files::file( $journalPath );
		if ( null !== $expectedIdentity ) {
			self::identity_schema( $expectedIdentity, 0100000 );
			self::ensure( $expectedIdentity === $snapshot['identity'] );
		}
		$state = json_decode( $snapshot['bytes'], true, 512, JSON_THROW_ON_ERROR );
		self::ensure( is_array( $state ) && is_string( $state['run'] ?? null ) );
		self::binding( $binding, $state['run'] );
		self::ensure( is_array( $state ) && 1 === ( $state['version'] ?? null )
			&& $binding === ( $state['binding'] ?? null )
			&& $snapshot['identity'] === ( $state['journal_identity'] ?? null )
			&& Wstm108_Files::directory( dirname( $journalPath ) ) === ( $state['parent'] ?? null )
			&& ( 'Windows' === PHP_OS_FAMILY || ( 0600 === ( $snapshot['identity']['mode'] & 0777 ) && 0700 === ( $state['parent']['mode'] & 0777 ) ) ) );
		foreach ( array( 'intents', 'users', 'ready_users', 'passwords', 'sessions', 'posts', 'comments', 'files', 'deleted_files', 'cleanup' ) as $key ) {
			self::ensure( isset( $state[ $key ] ) && is_array( $state[ $key ] ) );
		}
		foreach ( $state['files'] as &$file ) {
			self::ensure( is_array( $file ) && array_keys( $file ) === array( 'identity', 'sha256', 'bytes_base64' ) && is_string( $file['bytes_base64'] ) );
			$bytes = base64_decode( $file['bytes_base64'], true );
			self::ensure( is_string( $bytes ) && base64_encode( $bytes ) === $file['bytes_base64'] );
			unset( $file['bytes_base64'] );
			$file['bytes'] = $bytes;
		}
		unset( $file );
		self::ensure( in_array( $state['phase'] ?? null, array( 'recording', 'cleaning', 'cleaned' ), true )
			&& is_int( $state['failed'] ?? null ) && $state['failed'] >= 0 && array_key_exists( 'first_error', $state )
			&& array_key_exists( 'upload', $state ) );
		$instance = ( new ReflectionClass( self::class ) )->newInstanceWithoutConstructor();
		$instance->path = $journalPath;
		$instance->snapshot = $snapshot;
		$instance->state = $state;
		$instance->independentlyAnchored = null !== $expectedIdentity;
		$instance->validate_state();
		return $instance;
	}

	private static function is_list( array $value ): bool {
		return array() === $value || array_keys( $value ) === range( 0, count( $value ) - 1 );
	}

	private function validate_state(): void {
		self::ensure( array_keys( $this->state ) === array(
			'version', 'binding', 'run', 'parent', 'intents', 'users', 'ready_users', 'passwords', 'sessions',
			'posts', 'comments', 'files', 'upload', 'deleted_files', 'phase', 'cleanup', 'failed', 'first_error', 'proof', 'journal_identity',
		) );
		self::identity_schema( $this->state['parent'], 0040000 );
		self::identity_schema( $this->state['journal_identity'], 0100000 );
		self::ensure( self::is_list( $this->state['intents'] ) && self::is_list( $this->state['deleted_files'] ) );
		foreach ( $this->state['users'] as $role => $id ) {
			self::ensure( in_array( $role, self::ROLES, true ) && is_int( $id ) && $id > 0 );
		}
		self::ensure( count( array_unique( $this->state['users'] ) ) === count( $this->state['users'] ) );
		foreach ( $this->state['ready_users'] as $role => $id ) {
			self::ensure( isset( $this->state['users'][ $role ] ) && $id === $this->state['users'][ $role ] );
		}
		foreach ( $this->state['passwords'] as $role => $record ) {
			self::ensure( in_array( $role, self::ROLES, true ) && is_array( $record )
				&& array_keys( $record ) === array( 'id', 'uuid', 'name' ) && isset( $this->state['users'][ $role ] )
				&& $record['id'] === $this->state['users'][ $role ] && is_string( $record['uuid'] ) && '' !== $record['uuid']
				&& is_string( $record['name'] ) && '' !== $record['name'] );
		}
		foreach ( $this->state['sessions'] as $key => $record ) {
			self::ensure( is_array( $record ) && array_keys( $record ) === array( 'id', 'token', 'boundary' )
				&& in_array( $record['boundary'], self::BOUNDARIES, true ) && is_string( $record['token'] ) && '' !== $record['token'] );
			$role = array_search( $record['id'], $this->state['users'], true );
			self::ensure( is_string( $role ) && $key === $record['boundary'] . ':' . $role );
		}
		foreach ( $this->state['posts'] as $id => $record ) {
			self::ensure( is_int( $id ) && $id > 0 && is_array( $record )
				&& isset( $record['author'], $record['type'], $record['slug'], $record['parent'] )
				&& in_array( $record['author'], $this->state['users'], true ) && in_array( $record['type'], self::TYPES, true )
				&& is_string( $record['slug'] ) && is_int( $record['parent'] ) && $record['parent'] >= 0
				&& ( 0 === $record['parent'] || isset( $this->state['posts'][ $record['parent'] ] ) ) );
			self::ensure( array_keys( $record ) === ( 'attachment' === $record['type']
				? array( 'author', 'type', 'slug', 'parent', 'file' ) : array( 'author', 'type', 'slug', 'parent' ) ) );
			if ( 'revision' === $record['type'] ) {
				self::ensure( 0 !== $record['parent'] && $record['author'] === $this->state['posts'][ $record['parent'] ]['author']
					&& 'revision' !== $this->state['posts'][ $record['parent'] ]['type'] );
			} else {
				self::ensure( $this->owned_slug( $record['slug'] ) );
			}
			$parents = array( $id );
			for ( $parent = $record['parent']; 0 !== $parent; $parent = $this->state['posts'][ $parent ]['parent'] ) {
				self::ensure( ! in_array( $parent, $parents, true ) && isset( $this->state['posts'][ $parent ]['parent'] )
					&& is_int( $this->state['posts'][ $parent ]['parent'] ) );
				$parents[] = $parent;
			}
			if ( 'attachment' === $record['type'] ) {
				self::ensure( isset( $record['file'] ) && is_string( $record['file'] ) && isset( $this->state['files'][ $record['file'] ] ) );
			}
		}
		foreach ( $this->state['comments'] as $id => $record ) {
			self::ensure( is_int( $id ) && $id > 0 && is_array( $record )
				&& array_keys( $record ) === array( 'author', 'post', 'parent' )
				&& in_array( $record['author'], $this->state['users'], true ) && is_int( $record['post'] ) && isset( $this->state['posts'][ $record['post'] ] )
				&& is_int( $record['parent'] ) && ( 0 === $record['parent'] || isset( $this->state['comments'][ $record['parent'] ] ) ) );
			$parents = array( $id );
			for ( $parent = $record['parent']; 0 !== $parent; $parent = $this->state['comments'][ $parent ]['parent'] ) {
				self::ensure( ! in_array( $parent, $parents, true ) && isset( $this->state['comments'][ $parent ]['parent'] )
					&& is_int( $this->state['comments'][ $parent ]['parent'] ) );
				$parents[] = $parent;
			}
		}
		foreach ( $this->state['files'] as $path => $file ) {
			self::ensure( is_string( $path ) && is_array( $file ) && array_keys( $file ) === array( 'identity', 'sha256', 'bytes' )
				&& is_array( $file['identity'] ) && is_string( $file['bytes'] ) && hash( 'sha256', $file['bytes'] ) === $file['sha256'] );
			self::identity_schema( $file['identity'], 0100000 );
			self::ensure( is_array( $this->state['upload'] ) && isset( $this->state['upload']['path'] )
				&& dirname( $path ) === $this->state['upload']['path'] && str_starts_with( basename( $path ), $this->state['run'] . '-' ) );
		}
		foreach ( $this->state['deleted_files'] as $path ) {
			self::ensure( is_string( $path ) && isset( $this->state['files'][ $path ] ) );
		}
		self::ensure( count( array_unique( $this->state['deleted_files'] ) ) === count( $this->state['deleted_files'] ) );
		foreach ( $this->state['intents'] as $intent ) {
			self::ensure( is_array( $intent ) && array_keys( $intent ) === array( 'kind', 'details', 'resolved' )
				&& is_string( $intent['kind'] ) && isset( self::INTENTS[ $intent['kind'] ] )
				&& is_array( $intent['details'] ) && array_keys( $intent['details'] ) === self::INTENTS[ $intent['kind'] ] && is_bool( $intent['resolved'] ) );
			$this->intent_schema( $intent );
		}
		$this->registration_schema();
		$upload = $this->state['upload'];
		self::ensure( null === $upload || ( is_array( $upload ) && isset( $upload['path'], $upload['root'], $upload['root_identity'] )
			&& is_string( $upload['path'] ) && is_string( $upload['root'] )
			&& $upload['path'] === $upload['root'] . DIRECTORY_SEPARATOR . $this->state['run']
			&& is_array( $upload['root_identity'] ) && array_key_exists( 'identity', $upload )
			&& ( null === $upload['identity'] || ( is_array( $upload['identity'] ) && isset( $upload['active_root_identity'] ) && is_array( $upload['active_root_identity'] ) ) ) ) );
		if ( null !== $upload ) {
			self::ensure( array_keys( $upload ) === ( null === $upload['identity']
				? array( 'path', 'root', 'root_identity', 'identity' ) : array( 'path', 'root', 'root_identity', 'identity', 'active_root_identity' ) ) );
			self::identity_schema( $upload['root_identity'], 0040000 );
			if ( null !== $upload['identity'] ) {
				self::identity_schema( $upload['identity'], 0040000 );
				self::identity_schema( $upload['active_root_identity'], 0040000 );
				self::ensure( self::owned_upload_parent( $upload['root_identity'], $upload['active_root_identity'] ) );
			}
		}
		foreach ( $this->state['cleanup'] as $label => $value ) {
			self::ensure( in_array( $label, array_merge( $this->labels(), array( 'post-order', 'proof-integrity', 'recovery-resumed' ) ), true )
				&& in_array( $value, array( true, 'Resource verification failed; private evidence retained.', 'Private journal unavailable; retain runtime.' ), true ) );
		}
		$errors = array_filter( $this->state['cleanup'], static fn( $value ) => true !== $value );
		self::ensure( $this->state['failed'] >= count( $errors )
			&& ( ( 0 === $this->state['failed'] && null === $this->state['first_error'] && array() === $errors )
				|| ( $this->state['failed'] > 0 && is_string( $this->state['first_error'] ) && isset( $errors[ $this->state['first_error'] ] ) ) ) );
		self::ensure( 'recording' === $this->state['phase']
			? null === $this->state['proof'] && array() === $this->state['cleanup'] && 0 === $this->state['failed']
			: $this->state['proof'] === $this->assemble_proof( $this->ledger_complete() ) );
	}

	private static function identity_schema( array $identity, int $type ): void {
		$keys = array_keys( $identity );
		sort( $keys );
		self::ensure( array( 'dev', 'gid', 'ino', 'mode', 'nlink', 'uid' ) === $keys );
		foreach ( $identity as $value ) {
			self::ensure( is_int( $value ) );
		}
		self::ensure( $type === ( $identity['mode'] & 0170000 ) && $identity['nlink'] >= 1
			&& ( 0100000 !== $type || 1 === $identity['nlink'] ) );
	}

	private function intent_schema( array $intent ): void {
		$d = $intent['details'];
		$kind = $intent['kind'];
		$matches = 0;
		if ( 'user' === $kind ) {
			self::ensure( in_array( $d['role'], self::ROLES, true ) );
			$matches = isset( $this->state['users'][ $d['role'] ] ) ? 1 : 0;
		} elseif ( in_array( $kind, array( 'password', 'session' ), true ) ) {
			self::ensure( is_int( $d['id'] ) );
			$role = array_search( $d['id'], $this->state['users'], true );
			self::ensure( is_string( $role ) );
			if ( 'password' === $kind ) {
				self::ensure( is_string( $d['name'] ) && '' !== $d['name'] );
				$matches = isset( $this->state['passwords'][ $role ] ) ? 1 : 0;
			} else {
				self::ensure( in_array( $d['boundary'], self::BOUNDARIES, true ) );
				$matches = isset( $this->state['sessions'][ $d['boundary'] . ':' . $role ] ) ? 1 : 0;
			}
		} elseif ( in_array( $kind, array( 'post', 'comment' ), true ) ) {
			self::ensure( in_array( $d['author'], $this->state['users'], true ) && is_int( $d['parent'] ) && $d['parent'] >= 0 );
			if ( 'post' === $kind ) {
				self::ensure( in_array( $d['type'], self::TYPES, true ) && 'revision' !== $d['type']
					&& is_string( $d['slug'] ) && $this->owned_slug( $d['slug'] )
					&& ( 0 === $d['parent'] || isset( $this->state['posts'][ $d['parent'] ] ) ) );
			} else {
				self::ensure( is_int( $d['post'] ) && isset( $this->state['posts'][ $d['post'] ] )
					&& ( 0 === $d['parent'] || isset( $this->state['comments'][ $d['parent'] ] ) ) );
			}
			foreach ( $this->state[ $kind . 's' ] as $record ) {
				$matches += array_intersect_key( $record, $d ) === $d ? 1 : 0;
			}
		} elseif ( 'file' === $kind ) {
			self::ensure( is_string( $d['path'] ) && is_string( $d['sha256'] ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $d['sha256'] )
				&& isset( $this->state['upload']['path'] ) && dirname( $d['path'] ) === $this->state['upload']['path']
				&& str_starts_with( basename( $d['path'] ), $this->state['run'] . '-' ) );
			$matches = isset( $this->state['files'][ $d['path'] ] ) && $d['sha256'] === $this->state['files'][ $d['path'] ]['sha256'] ? 1 : 0;
		} else {
			self::ensure( in_array( $d['resource'], array( 'post', 'comment' ), true ) && is_int( $d['id'] )
				&& isset( $this->state[ $d['resource'] . 's' ][ $d['id'] ] ) && true === $intent['resolved'] );
			$matches = 1;
		}
		self::ensure( ! $intent['resolved'] || $matches >= 1 );
	}

	private function registration_schema(): void {
		$key = static fn( string $kind, array $details ) => json_encode( array( $kind, $details ), JSON_THROW_ON_ERROR );
		$intents = array();
		foreach ( $this->state['intents'] as $intent ) {
			if ( $intent['resolved'] && 'mutation' !== $intent['kind'] ) {
				$intents[] = $key( $intent['kind'], $intent['details'] );
			}
		}
		$records = array();
		foreach ( $this->state['users'] as $role => $id ) {
			$records[] = $key( 'user', array( 'role' => $role ) );
		}
		foreach ( $this->state['passwords'] as $record ) {
			$records[] = $key( 'password', array( 'id' => $record['id'], 'name' => $record['name'] ) );
		}
		foreach ( $this->state['sessions'] as $record ) {
			$records[] = $key( 'session', array( 'id' => $record['id'], 'boundary' => $record['boundary'] ) );
		}
		foreach ( $this->state['posts'] as $record ) {
			if ( 'revision' !== $record['type'] ) {
				unset( $record['file'] );
				$records[] = $key( 'post', $record );
			}
		}
		foreach ( $this->state['comments'] as $record ) {
			$records[] = $key( 'comment', $record );
		}
		foreach ( $this->state['files'] as $path => $record ) {
			$records[] = $key( 'file', array( 'path' => $path, 'sha256' => $record['sha256'] ) );
		}
		sort( $intents );
		sort( $records );
		self::ensure( $intents === $records );
	}

	private function encode( bool $withProof = true ): string {
		$state = $this->state;
		if ( ! $withProof ) {
			unset( $state['proof'] );
		}
		foreach ( $state['files'] as &$file ) {
			$bytes = $file['bytes'];
			unset( $file['bytes'] );
			$file['bytes_base64'] = base64_encode( $bytes );
		}
		unset( $file );
		return json_encode( $state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES );
	}

	private function guard(): void {
		self::ensure( Wstm108_Files::directory( dirname( $this->path ) ) === $this->state['parent'] );
		Wstm108_Files::assert_file( $this->path, $this->snapshot );
	}

	private function save(): void {
		$this->guard();
		$this->state['proof'] = 'recording' === $this->state['phase'] ? null : $this->assemble_proof( $this->ledger_complete() );
		$this->snapshot = Wstm108_Files::update( $this->path, $this->snapshot, $this->encode() );
	}

	private function recording(): void {
		$this->guard();
		self::ensure( 'recording' === $this->state['phase'] );
	}

	private function role( int $id ): string {
		$role = array_search( $id, $this->state['users'], true );
		self::ensure( is_string( $role ) && in_array( $role, self::ROLES, true )
			&& isset( $this->state['ready_users'][ $role ] ) && $id === $this->state['ready_users'][ $role ] );
		$this->actor( $role, $id );
		return $role;
	}

	private function actor( string $role, int $id ): void {
		$this->actor_identity( $role, $id );
		self::ensure( 'reader' !== $role || user_can( $id, 'list_users' ) );
	}

	private function actor_identity( string $role, int $id ): void {
		self::ensure( $id > 0 && in_array( $role, self::ROLES, true ) );
		$user = get_userdata( $id );
		$login = $this->state['run'] . '-' . $role;
		self::ensure( false !== $user && $id === (int) $user->ID && $login === $user->user_login && $login . '@example.test' === $user->user_email
			&& array( $this->state['binding']['owner'] ) === get_user_meta( $id, 'wstm108_owner', false )
			&& array( 'reader' === $role ? 'subscriber' : $role ) === array_values( $user->roles ) );
	}

	/**
	 * Schemas (keys in any order, no extras):
	 * user {role}; password {id,name}; session {id,boundary};
	 * post {author,type,slug,parent}; comment {author,post,parent};
	 * file {path,sha256}; mutation {resource:"post"|"comment",id}.
	 * Password names are exact, unsanitized intent values. Slugs are the exact
	 * anticipated stored post_name, not the title or a replacement fixture input.
	 * Percent-encoded Unicode from WordPress's normal slug derivation is accepted.
	 * Repeated post intents consume one matching occurrence per distinct ID.
	 * Repeated mutation intents append resolved audit entries, not new resources.
	 */
	public function intent( string $kind, array $details ): void {
		$this->recording();
		self::ensure( isset( self::INTENTS[ $kind ] ) && count( $details ) === count( self::INTENTS[ $kind ] )
			&& array() === array_diff( self::INTENTS[ $kind ], array_keys( $details ) ) );
		$details = array_replace( array_fill_keys( self::INTENTS[ $kind ], null ), $details );
		if ( 'user' === $kind ) {
			self::ensure( in_array( $details['role'], self::ROLES, true ) && ! isset( $this->state['users'][ $details['role'] ] ) );
			// Preflight prevents adopting a preexisting run-looking account.
			self::ensure( false === get_user_by( 'login', $this->state['run'] . '-' . $details['role'] )
				&& false === get_user_by( 'email', $this->state['run'] . '-' . $details['role'] . '@example.test' ) );
		} elseif ( in_array( $kind, array( 'password', 'session' ), true ) ) {
			self::ensure( is_int( $details['id'] ) );
			$role = $this->role( $details['id'] );
			if ( 'password' === $kind ) {
				self::ensure( is_string( $details['name'] ) && '' !== $details['name'] && array() === $this->password_list( $details['id'] ) );
			} else {
				self::ensure( in_array( $details['boundary'], self::BOUNDARIES, true ) );
				foreach ( $this->session_list( $details['id'] ) as $session ) {
					self::ensure( ( $session['client_params']['clientInfo']['name'] ?? null ) !== $this->client_name( $role, $details['boundary'] ) );
				}
			}
		} elseif ( 'post' === $kind ) {
			self::ensure( is_int( $details['author'] ) && is_int( $details['parent'] ) && $details['parent'] >= 0
				&& in_array( $details['type'], self::TYPES, true ) && 'revision' !== $details['type']
				&& is_string( $details['slug'] ) && $this->owned_slug( $details['slug'] ) );
			$this->role( $details['author'] );
			self::ensure( 0 === $details['parent'] || isset( $this->state['posts'][ $details['parent'] ] ) );
		} elseif ( 'comment' === $kind ) {
			self::ensure( is_int( $details['author'] ) && is_int( $details['post'] ) && is_int( $details['parent'] )
				&& isset( $this->state['posts'][ $details['post'] ] )
				&& ( 0 === $details['parent'] || isset( $this->state['comments'][ $details['parent'] ] ) ) );
			$this->role( $details['author'] );
		} elseif ( 'file' === $kind ) {
			self::ensure( is_string( $details['path'] ) && is_string( $details['sha256'] ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $details['sha256'] ) );
			$this->file_path( $details['path'] );
			self::ensure( false === @lstat( $details['path'] ) );
		} else {
			self::ensure( in_array( $details['resource'], array( 'post', 'comment' ), true ) && is_int( $details['id'] )
				&& isset( $this->state[ $details['resource'] . 's' ][ $details['id'] ] ) );
		}
		$this->state['intents'][] = array( 'kind' => $kind, 'details' => $details, 'resolved' => 'mutation' === $kind );
		$this->save();
	}

	private function resolve( string $kind, array $details ): void {
		$matches = array();
		foreach ( $this->state['intents'] as $index => $intent ) {
			if ( $kind === $intent['kind'] && ! $intent['resolved'] && $details === $intent['details'] ) {
				$matches[] = $index;
			}
		}
		self::ensure( 'post' === $kind ? count( $matches ) >= 1 : 1 === count( $matches ) );
		$this->state['intents'][ $matches[0] ]['resolved'] = true;
	}

	public function user_created( string $role, int $id ): void {
		$this->guard();
		$this->actor_identity( $role, $id );
		self::ensure( ! isset( $this->state['users'][ $role ] ) && ! in_array( $id, $this->state['users'], true ) );
		$this->resolve( 'user', array( 'role' => $role ) );
		$this->state['users'][ $role ] = $id;
		$this->save();
	}

	public function user( string $role, int $id ): void {
		$this->guard();
		self::ensure( ! isset( $this->state['ready_users'][ $role ] ) );
		if ( ! isset( $this->state['users'][ $role ] ) ) {
			$this->user_created( $role, $id );
		}
		self::ensure( $id === $this->state['users'][ $role ] );
		$this->actor( $role, $id );
		$this->state['ready_users'][ $role ] = $id;
		$this->save();
	}

	private function password_list( int $id ): array {
		$list = WP_Application_Passwords::get_user_application_passwords( $id );
		self::ensure( is_array( $list ) );
		return $list;
	}

	private function owned_password( string $role ): array {
		self::ensure( isset( $this->state['passwords'][ $role ], $this->state['users'][ $role ] ) );
		$record = $this->state['passwords'][ $role ];
		self::ensure( $record['id'] === $this->state['users'][ $role ] );
		self::ensure( $role === $this->role( $record['id'] ) );
		$intents = array_filter( $this->state['intents'], static fn( $intent ) =>
			'password' === $intent['kind'] && $intent['resolved']
			&& array( 'id' => $record['id'], 'name' => $record['name'] ) === $intent['details'] );
		self::ensure( 1 === count( $intents ) );
		$list = $this->password_list( $record['id'] );
		// Prior registration proves ownership even if that credential is now absent.
		self::ensure( count( $list ) <= 1 );
		if ( true === ( $this->state['cleanup'][ 'password:' . $role ] ?? null ) ) {
			self::ensure( array() === $list );
		}
		foreach ( $list as $password ) {
			self::ensure( is_array( $password ) && $record['uuid'] === ( $password['uuid'] ?? null )
				&& $record['name'] === ( $password['name'] ?? null ) );
		}
		return $record;
	}

	public function password( int $id, string $uuid, string $name ): void {
		$this->guard();
		$role = $this->role( $id );
		self::ensure( '' !== $uuid && '' !== $name && ! isset( $this->state['passwords'][ $role ] ) );
		$matches = array_values( array_filter( $this->password_list( $id ), static fn( $item ) => ( $item['name'] ?? null ) === $name ) );
		self::ensure( 1 === count( $matches ) && $uuid === ( $matches[0]['uuid'] ?? null ) );
		$this->resolve( 'password', array( 'id' => $id, 'name' => $name ) );
		$this->state['passwords'][ $role ] = array( 'id' => $id, 'uuid' => $uuid, 'name' => $name );
		$this->save();
	}

	private function client_name( string $role, string $boundary ): string {
		return $this->state['run'] . ':' . $boundary . ':' . $role;
	}

	private function session_api( string $method, array $arguments ) {
		$class = '\\WP\\MCP\\Transport\\Infrastructure\\SessionManager';
		$callable = array( $class, $method );
		if ( ! is_callable( $callable ) ) {
			$callable = array( new $class(), $method );
		}
		self::ensure( is_callable( $callable ) );
		return $callable( ...$arguments );
	}

	private function session_list( int $id ): array {
		$list = $this->session_api( 'get_all_user_sessions', array( $id ) );
		self::ensure( is_array( $list ) );
		return $list;
	}

	public function session( int $id, string $token, string $boundary ): void {
		$this->guard();
		$role = $this->role( $id );
		$key = $boundary . ':' . $role;
		self::ensure( '' !== $token && in_array( $boundary, self::BOUNDARIES, true ) && ! isset( $this->state['sessions'][ $key ] ) );
		$list = $this->session_list( $id );
		self::ensure( isset( $list[ $token ] ) && $this->client_name( $role, $boundary ) === ( $list[ $token ]['client_params']['clientInfo']['name'] ?? null ) );
		$this->resolve( 'session', array( 'id' => $id, 'boundary' => $boundary ) );
		$this->state['sessions'][ $key ] = array( 'id' => $id, 'token' => $token, 'boundary' => $boundary );
		$this->save();
	}

	private function owned_slug( string $slug ): bool {
		return 1 === preg_match( '/^' . preg_quote( $this->state['run'], '/' ) . '-(?:[a-z0-9_-]|%[a-f0-9]{2})+$/D', $slug );
	}

	private function post_details( int $id ): array {
		$post = get_post( $id );
		self::ensure( null !== $post && in_array( $post->post_type, self::TYPES, true ) );
		$this->role( (int) $post->post_author );
		$details = array( 'author' => (int) $post->post_author, 'type' => $post->post_type, 'slug' => $post->post_name, 'parent' => (int) $post->post_parent );
		if ( 'revision' === $post->post_type ) {
			self::ensure( isset( $this->state['posts'][ $details['parent'] ] ) && 'revision' !== $this->state['posts'][ $details['parent'] ]['type'] );
		} else {
			self::ensure( $this->owned_slug( $details['slug'] )
				&& ( 0 === $details['parent'] || isset( $this->state['posts'][ $details['parent'] ] ) ) );
		}
		return $details;
	}

	public function post( int $id ): void {
		$this->guard();
		self::ensure( $id > 0 && ! isset( $this->state['posts'][ $id ] ) );
		$details = $this->post_details( $id );
		if ( 'revision' === $details['type'] ) {
			self::ensure( $this->state['posts'][ $details['parent'] ]['author'] === $details['author'] );
		} else {
			$this->resolve( 'post', $details );
		}
		$this->state['posts'][ $id ] = $details;
		if ( 'attachment' === $details['type'] ) {
			// Bind each attachment to its own independently observed original file.
			$path = get_attached_file( $id, true );
			self::ensure( is_string( $path ) && isset( $this->state['files'][ $path ] ) );
			foreach ( $this->state['posts'] as $other => $record ) {
				self::ensure( $other === $id || ( $record['file'] ?? null ) !== $path );
			}
			$this->state['posts'][ $id ]['file'] = $path;
		}
		$this->save();
	}

	private function comment_details( int $id ): array {
		$comment = get_comment( $id );
		self::ensure( null !== $comment && isset( $this->state['posts'][ (int) $comment->comment_post_ID ] )
			&& ( 0 === (int) $comment->comment_parent || isset( $this->state['comments'][ (int) $comment->comment_parent ] ) ) );
		$this->role( (int) $comment->user_id );
		return array( 'author' => (int) $comment->user_id, 'post' => (int) $comment->comment_post_ID, 'parent' => (int) $comment->comment_parent );
	}

	public function comment( int $id ): void {
		$this->guard();
		self::ensure( $id > 0 && ! isset( $this->state['comments'][ $id ] ) );
		$details = $this->comment_details( $id );
		$this->resolve( 'comment', $details );
		$this->state['comments'][ $id ] = $details;
		$this->save();
	}

	public function create_upload_directory( string $existingRoot ): string {
		$this->recording();
		self::ensure( null === $this->state['upload'] );
		$root = Wstm108_Files::directory( $existingRoot );
		$path = $existingRoot . DIRECTORY_SEPARATOR . $this->state['run'];
		self::ensure( false === @lstat( $path ) );
		$this->state['upload'] = array( 'path' => $path, 'root' => $existingRoot, 'root_identity' => $root, 'identity' => null );
		$this->save();
		$mask = umask( 0022 );
		try {
			self::ensure( mkdir( $path, 0755, false ) );
		} finally {
			umask( $mask );
		}
		$this->state['upload']['identity'] = Wstm108_Files::directory( $path );
		// Directory creation legitimately changes only the parent's link count.
		$after = Wstm108_Files::directory( $existingRoot );
		self::ensure( self::owned_upload_parent( $root, $after ) );
		$this->state['upload']['active_root_identity'] = $after;
		self::ensure( 'Windows' === PHP_OS_FAMILY || 0755 === ( $this->state['upload']['identity']['mode'] & 0777 ) );
		$this->save();
		return $path;
	}

	private static function owned_upload_parent( array $before, array $after ): bool {
		$link_count_matches = $after['nlink'] === $before['nlink'] || $after['nlink'] === $before['nlink'] + 1;
		unset( $before['nlink'], $after['nlink'] );
		return $link_count_matches && $before === $after;
	}

	private function file_path( string $path ): void {
		self::ensure( is_array( $this->state['upload'] ) && dirname( $path ) === $this->state['upload']['path']
			&& str_starts_with( basename( $path ), $this->state['run'] . '-' ) );
		self::ensure( Wstm108_Files::directory( dirname( $path ) ) === $this->state['upload']['identity'] );
	}

	public function file( string $path ): void {
		$this->guard();
		$this->file_path( $path );
		self::ensure( ! isset( $this->state['files'][ $path ] ) );
		$snapshot = Wstm108_Files::file( $path );
		self::ensure( 'Windows' === PHP_OS_FAMILY || 0644 === ( $snapshot['identity']['mode'] & 0777 ) );
		$this->resolve( 'file', array( 'path' => $path, 'sha256' => $snapshot['sha256'] ) );
		$this->state['files'][ $path ] = $snapshot;
		$this->save();
	}

	private function posts_query( array $args ): array {
		$query = new WP_Query( array_merge( array(
			'post_type' => array_values( get_post_types() ), 'post_status' => array_values( get_post_stati() ),
			'posts_per_page' => self::LIMIT, 'orderby' => 'ID', 'order' => 'ASC',
			'fields' => 'ids', 'suppress_filters' => true, 'no_found_rows' => true,
		), $args ) );
		self::ensure( is_array( $query->posts ) && count( $query->posts ) < self::LIMIT );
		return array_map( 'intval', $query->posts );
	}

	private function comments_query( array $args ): array {
		$list = get_comments( array_merge( array( 'status' => 'all', 'number' => self::LIMIT, 'orderby' => 'comment_ID', 'order' => 'ASC', 'fields' => 'ids' ), $args ) );
		self::ensure( is_array( $list ) && count( $list ) < self::LIMIT );
		return array_map( 'intval', $list );
	}

	/** Read-only, bounded checks cannot be hidden by WP query/meta filters. */
	private function raw_ids( string $table, string $column, int $id ): array {
		global $wpdb;
		$tables = array(
			'posts' => array( 'ID', array( 'ID', 'post_author', 'post_parent' ) ),
			'comments' => array( 'comment_ID', array( 'comment_ID', 'user_id', 'comment_post_ID', 'comment_parent' ) ),
			'postmeta' => array( 'meta_id', array( 'post_id' ) ),
			'commentmeta' => array( 'meta_id', array( 'comment_id' ) ),
			'usermeta' => array( 'umeta_id', array( 'user_id' ) ),
			'users' => array( 'ID', array( 'ID' ) ),
		);
		self::ensure( isset( $tables[ $table ] ) && in_array( $column, $tables[ $table ][1], true ) && $id > 0 );
		$key = $tables[ $table ][0];
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT {$key} FROM {$wpdb->$table} WHERE {$column} = %d ORDER BY {$key} ASC LIMIT 501", $id ) );
		self::ensure( '' === $wpdb->last_error && is_array( $ids ) && count( $ids ) < self::LIMIT );
		return array_map( 'intval', $ids );
	}

	private function recover_intent( array $intent ): void {
		$d = $intent['details'];
		switch ( $intent['kind'] ) {
			case 'user':
				$user = get_user_by( 'login', $this->state['run'] . '-' . $d['role'] );
				self::ensure( false !== $user );
				$this->user( $d['role'], (int) $user->ID );
				break;
			case 'password':
				$this->role( $d['id'] );
				$list = array_values( array_filter( $this->password_list( $d['id'] ), static fn( $item ) => ( $item['name'] ?? null ) === $d['name'] ) );
				self::ensure( 1 === count( $list ) && is_string( $list[0]['uuid'] ?? null ) );
				$this->password( $d['id'], $list[0]['uuid'], $d['name'] );
				break;
			case 'session':
				$role = $this->role( $d['id'] );
				$tokens = array();
				foreach ( $this->session_list( $d['id'] ) as $token => $session ) {
					if ( $this->client_name( $role, $d['boundary'] ) === ( $session['client_params']['clientInfo']['name'] ?? null ) ) {
						$tokens[] = $token;
					}
				}
				self::ensure( 1 === count( $tokens ) && is_string( $tokens[0] ) );
				$this->session( $d['id'], $tokens[0], $d['boundary'] );
				break;
			case 'post':
				$this->role( $d['author'] );
				$ids = array_values( array_diff(
					$this->posts_query( array( 'author' => $d['author'], 'post_type' => $d['type'], 'name' => $d['slug'], 'post_parent' => $d['parent'] ) ),
					array_keys( $this->state['posts'] )
				) );
				self::ensure( 1 === count( $ids ) );
				$this->post( $ids[0] );
				break;
			case 'comment':
				$this->role( $d['author'] );
				$ids = array_values( array_diff( $this->comments_query( array( 'user_id' => $d['author'], 'post_id' => $d['post'], 'parent' => $d['parent'] ) ), array_keys( $this->state['comments'] ) ) );
				self::ensure( 1 === count( $ids ) );
				$this->comment( $ids[0] );
				break;
			case 'file':
				// Lost file results are not independently observed identities.
				self::ensure( false );
				break;
			default:
				self::ensure( false );
		}
	}

	private function recover(): void {
		$failed = false;
		foreach ( $this->state['intents'] as $intent ) {
			if ( $intent['resolved'] ) {
				continue;
			}
			try {
				$this->recover_intent( $intent );
			} catch ( Throwable $error ) {
				// Still recover independently provable credentials on other actors.
				$failed = true;
			}
		}
		foreach ( $this->state['users'] as $role => $id ) {
			try {
				$this->actor( $role, $id );
				foreach ( $this->raw_ids( 'posts', 'post_author', $id ) as $post_id ) {
					if ( ! isset( $this->state['posts'][ $post_id ] ) ) {
						self::ensure( null !== get_post( $post_id ) && 'revision' === get_post( $post_id )->post_type );
						$this->post( $post_id );
					}
				}
			} catch ( Throwable $error ) {
				$failed = true;
			}
		}
		self::ensure( ! $failed );
	}

	private function files_valid(): void {
		$upload = $this->state['upload'];
		self::ensure( is_array( $upload ) && is_array( $upload['identity'] )
			&& Wstm108_Files::directory( $upload['root'] ) === $upload['active_root_identity']
			&& Wstm108_Files::directory( $upload['path'] ) === $upload['identity'] );
		$entries = scandir( $upload['path'] );
		self::ensure( is_array( $entries ) );
		$entries = array_values( array_diff( $entries, array( '.', '..' ) ) );
		$expected = array_map( 'basename', array_diff( array_keys( $this->state['files'] ), $this->state['deleted_files'] ) );
		sort( $entries );
		sort( $expected );
		self::ensure( $entries === $expected );
		foreach ( $this->state['files'] as $path => $snapshot ) {
			$this->file_path( $path );
			if ( in_array( $path, $this->state['deleted_files'], true ) ) {
				self::ensure( false === @lstat( $path ) );
			} else {
				Wstm108_Files::assert_file( $path, $snapshot );
			}
		}
	}

	private function attachment( int $id, array $record ): void {
		self::ensure( isset( $record['file'], $this->state['files'][ $record['file'] ] ) );
		$path = $record['file'];
		$relative = str_replace( '\\', '/', substr( $path, strlen( $this->state['upload']['root'] ) + 1 ) );
		self::ensure( $path === get_attached_file( $id, true ) && $path === get_attached_file( $id )
			&& array( $relative ) === get_post_meta( $id, '_wp_attached_file', false ) );
		$expected = array( 'width' => 1, 'height' => 1, 'file' => $relative );
		$metadata = get_post_meta( $id, '_wp_attachment_metadata', false );
		self::ensure( 1 === count( $metadata ) && is_array( $metadata[0] ) && $expected == $metadata[0]
			&& count( $expected ) === count( $metadata[0] ) && $expected == wp_get_attachment_metadata( $id )
			&& count( $expected ) === count( wp_get_attachment_metadata( $id ) ) );
		foreach ( $expected as $key => $value ) {
			self::ensure( $value === $metadata[0][ $key ] && $value === wp_get_attachment_metadata( $id )[ $key ] );
		}
		self::ensure( array() === get_post_meta( $id, '_wp_attachment_backup_sizes', false )
			&& array() === get_post_meta( $id, '_wp_attachment_original_image', false ) );
		// A foreign attachment must not point into this owned directory.
		$references = $this->posts_query( array( 'post_type' => 'attachment', 'meta_query' => array(
			'relation' => 'OR',
			array( 'key' => '_wp_attached_file', 'value' => $this->state['run'], 'compare' => 'LIKE' ),
			array( 'key' => '_wp_attachment_metadata', 'value' => $this->state['run'], 'compare' => 'LIKE' ),
			array( 'key' => '_wp_attachment_backup_sizes', 'value' => $this->state['run'], 'compare' => 'LIKE' ),
		) ) );
		foreach ( $references as $reference ) {
			self::ensure( isset( $this->state['posts'][ $reference ] ) && 'attachment' === $this->state['posts'][ $reference ]['type'] );
		}
		foreach ( $this->attachment_reference_ids() as $reference ) {
			self::ensure( isset( $this->state['posts'][ $reference ] ) && 'attachment' === $this->state['posts'][ $reference ]['type'] );
		}
	}

	private function attachment_reference_ids(): array {
		global $wpdb;
		$references = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ('_wp_attached_file', '_wp_attachment_metadata', '_wp_attachment_backup_sizes', '_wp_attachment_original_image') AND meta_value LIKE %s ORDER BY post_id ASC LIMIT 501",
			'%' . $wpdb->esc_like( $this->state['run'] ) . '%'
		) );
		self::ensure( '' === $wpdb->last_error && is_array( $references ) && count( $references ) < self::LIMIT );
		return array_map( 'intval', $references );
	}

	private function content_valid(): void {
		$this->options_absent();
		$this->files_valid();
		foreach ( $this->state['users'] as $role => $id ) {
			$this->actor( $role, $id );
			foreach ( $this->raw_ids( 'posts', 'post_author', $id ) as $post ) {
				self::ensure( isset( $this->state['posts'][ $post ] ) );
			}
			foreach ( $this->raw_ids( 'comments', 'user_id', $id ) as $comment ) {
				self::ensure( isset( $this->state['comments'][ $comment ] ) );
			}
		}
		foreach ( $this->state['posts'] as $id => $record ) {
			if ( null !== get_post( $id ) ) {
				self::ensure( $this->post_details( $id ) === array_intersect_key( $record, array_flip( array( 'author', 'type', 'slug', 'parent' ) ) ) );
				foreach ( $this->raw_ids( 'posts', 'post_parent', $id ) as $child ) {
					self::ensure( isset( $this->state['posts'][ $child ] ) );
				}
				foreach ( $this->raw_ids( 'comments', 'comment_post_ID', $id ) as $comment ) {
					self::ensure( isset( $this->state['comments'][ $comment ] ) );
				}
				if ( 'attachment' === $record['type'] ) {
					$this->attachment( $id, $record );
				}
			} else {
				$this->post_absent( $id );
			}
		}
		foreach ( $this->state['comments'] as $id => $record ) {
			if ( null !== get_comment( $id ) ) {
				self::ensure( $record === $this->comment_details( $id ) );
			} else {
				$this->comment_absent( $id );
			}
			foreach ( $this->raw_ids( 'comments', 'comment_parent', $id ) as $child ) {
				self::ensure( isset( $this->state['comments'][ $child ] ) );
			}
		}
	}

	private function clients_valid( array $clients ): void {
		$expected = array_keys( $this->state['sessions'] );
		$actual = array_keys( $clients );
		sort( $expected, SORT_STRING );
		sort( $actual, SORT_STRING );
		self::ensure( 6 === count( $expected ) && $expected === $actual );
		foreach ( $clients as $client ) {
			self::ensure( is_object( $client ) && is_callable( array( $client, 'close' ) ) );
		}
	}

	private function credentials_valid(): void {
		foreach ( self::ROLES as $role ) {
			$record = $this->owned_password( $role );
			$expected = array();
			$list = $this->session_list( $record['id'] );
			foreach ( self::BOUNDARIES as $boundary ) {
				$key = $boundary . ':' . $role;
				self::ensure( isset( $this->state['sessions'][ $key ] ) );
				$session = $this->state['sessions'][ $key ];
				self::ensure( $session['id'] === $record['id'] && ! isset( $expected[ $session['token'] ] ) );
				$expected[ $session['token'] ] = $this->client_name( $role, $boundary );
				if ( true === ( $this->state['cleanup'][ 'HTTP:' . $key ] ?? null )
					|| true === ( $this->state['cleanup'][ 'session:' . $key ] ?? null ) ) {
					self::ensure( ! array_key_exists( $session['token'], $list ) );
				}
			}
			foreach ( $list as $token => $session ) {
				self::ensure( isset( $expected[ $token ] ) && is_array( $session )
					&& $expected[ $token ] === ( $session['client_params']['clientInfo']['name'] ?? null ) );
			}
		}
	}

	/** Recheck remaining resources immediately before each destructive call. */
	private function mutation_preflight(): void {
		$this->guard();
		$this->coverage();
		$this->credentials_valid();
		$this->content_valid();
		$this->guard();
	}

	private function check( string $label, callable $action ): bool {
		try {
			$this->guard();
			$action();
			if ( ! isset( $this->state['cleanup'][ $label ] ) ) {
				$this->state['cleanup'][ $label ] = true;
			}
			$this->save();
			return true;
		} catch ( Throwable $error ) {
			// External exceptions can contain authorization headers and session IDs.
			$this->state['cleanup'][ $label ] = 'Resource verification failed; private evidence retained.';
			++$this->state['failed'];
			if ( null === $this->state['first_error'] ) {
				$this->state['first_error'] = $label;
			}
			try {
				$this->save();
			} catch ( Throwable $persistence_error ) {
				$this->state['cleanup']['journal'] = 'Private journal unavailable; retain runtime.';
			}
			return false;
		}
	}

	private function coverage(): void {
		$roles = array_keys( $this->state['users'] );
		sort( $roles );
		$expected = self::ROLES;
		sort( $expected );
		self::ensure( $roles === $expected && count( $this->state['ready_users'] ) === 3
			&& count( $this->state['passwords'] ) === 3 && count( $this->state['sessions'] ) === 6
			&& count( $this->state['posts'] ) > 0 && count( $this->state['comments'] ) > 0 && count( $this->state['files'] ) > 0
			&& is_array( $this->state['upload'] ) && in_array( 'attachment', array_column( $this->state['posts'], 'type' ), true ) );
		foreach ( self::ROLES as $role ) {
			self::ensure( isset( $this->state['passwords'][ $role ], $this->state['ready_users'][ $role ] )
				&& $this->state['users'][ $role ] === $this->state['ready_users'][ $role ] );
			foreach ( self::BOUNDARIES as $boundary ) {
				self::ensure( isset( $this->state['sessions'][ $boundary . ':' . $role ] ) );
			}
		}
		foreach ( $this->state['intents'] as $intent ) {
			self::ensure( true === $intent['resolved'] );
		}
	}

	private function post_absent( int $id ): void {
		self::ensure( null === get_post( $id ) && array() === get_post_meta( $id )
			&& array() === $this->raw_ids( 'posts', 'ID', $id ) && array() === $this->raw_ids( 'postmeta', 'post_id', $id )
			&& array() === $this->raw_ids( 'posts', 'post_parent', $id )
			&& array() === $this->raw_ids( 'comments', 'comment_post_ID', $id )
			&& false === wp_next_scheduled( 'publish_future_post', array( $id ) ) );
		$cron = _get_cron_array();
		self::ensure( is_array( $cron ) );
		foreach ( $cron as $hooks ) {
			self::ensure( is_array( $hooks ) );
			foreach ( $hooks as $events ) {
				self::ensure( is_array( $events ) );
				foreach ( $events as $event ) {
					self::ensure( is_array( $event ) && isset( $event['args'] ) && is_array( $event['args'] ) );
					array_walk_recursive( $event['args'], static function ( $value ) use ( $id ): void {
						self::ensure( $value !== $id && $value !== (string) $id );
					} );
				}
			}
		}
	}

	private function comment_absent( int $id ): void {
		self::ensure( null === get_comment( $id ) && array() === get_comment_meta( $id )
			&& array() === $this->raw_ids( 'comments', 'comment_ID', $id ) && array() === $this->raw_ids( 'commentmeta', 'comment_id', $id )
			&& array() === $this->raw_ids( 'comments', 'comment_parent', $id ) );
	}

	public function cleanup( array $clients ): array {
		if ( 'recording' !== $this->state['phase'] ) {
			$proof = $this->proof();
			if ( $proof['cleanup_complete'] ) {
				return $proof;
			}
			return $this->resume_cleanup( $clients );
		}
		if ( ! $this->check( 'journal', function (): void {
			self::ensure( 'recording' === $this->state['phase'] );
			$this->validate_state();
			$this->state['phase'] = 'cleaning';
		} ) ) {
			return $this->proof();
		}
		$recovered = $this->check( 'recovery', function (): void { $this->recover(); } );
		$coverage = $this->check( 'coverage', function (): void { $this->coverage(); } );
		$options = $this->check( 'options', function (): void { $this->options_absent(); } );
		$client_coverage = $this->check( 'clients', function () use ( $clients ): void { $this->clients_valid( $clients ); } );
		$preflight = $this->check( 'references', function () use ( $recovered, $coverage, $options, $client_coverage ): void {
			self::ensure( $recovered && $coverage && $options && $client_coverage );
			$this->mutation_preflight();
		} );
		$content = $this->cleanup_credentials( $clients, $preflight );
		$index = 0;
		foreach ( array_reverse( $this->state['comments'], true ) as $id => $record ) {
			$ok = $this->check( 'comment:' . ++$index, function () use ( $id, $record, $content ): void {
				self::ensure( $content );
				$this->mutation_preflight();
				if ( null !== get_comment( $id ) ) {
					self::ensure( $record === $this->comment_details( $id ) && true === wp_delete_comment( $id, true ) );
				}
				$this->comment_absent( $id );
			} );
			$content = $content && $ok;
		}
		$this->cleanup_content( $content );
		$this->check( 'final-absence', function (): void { $this->absence(); } );
		$this->check( 'journal-final', function (): void { $this->state['phase'] = 'cleaned'; } );
		return $this->proof();
	}

	/** Interrupted content deletion is never replayed; credential recovery still needs full preflight. */
	private function resume_cleanup( array $clients ): array {
		if ( ! $this->check( 'journal', function (): void { $this->validate_state(); } ) ) {
			return $this->proof();
		}
		if ( 0 === $this->state['failed'] ) {
			$this->check( 'recovery-resumed', static function (): void { self::ensure( false ); } );
		}
		$recovered = $this->check( 'recovery', function (): void {
			$failed = false;
			foreach ( $this->state['intents'] as $intent ) {
				if ( ! $intent['resolved'] && in_array( $intent['kind'], array( 'user', 'password', 'session' ), true ) ) {
					try {
						$this->recover_intent( $intent );
					} catch ( Throwable $error ) {
						$failed = true;
					}
				}
			}
			self::ensure( ! $failed );
		} );
		$coverage = $this->check( 'coverage', function (): void { $this->coverage(); } );
		$client_coverage = $this->check( 'clients', function () use ( $clients ): void { $this->clients_valid( $clients ); } );
		$preflight = $this->check( 'references', function () use ( $recovered, $coverage, $client_coverage ): void {
			self::ensure( $recovered && $coverage && $client_coverage );
			$this->mutation_preflight();
		} );
		$this->cleanup_credentials( $clients, $preflight );
		return $this->proof();
	}

	private function cleanup_credentials( array $clients, bool $preflight ): bool {
		$complete = $preflight;
		foreach ( self::BOUNDARIES as $boundary ) {
			foreach ( self::ROLES as $role ) {
				$key = $boundary . ':' . $role;
				$closed = $this->check( 'HTTP:' . $key, function () use ( $key, $role, $clients, $preflight ): void {
					self::ensure( $preflight );
					$this->mutation_preflight();
					self::ensure( isset( $this->state['sessions'][ $key ], $clients[ $key ] ) );
					$record = $this->state['sessions'][ $key ];
					$this->actor( $role, $record['id'] );
					$this->owned_password( $role );
					self::ensure( false !== $clients[ $key ]->close() );
					self::ensure( ! isset( $this->session_list( $record['id'] )[ $record['token'] ] ) );
				} );
				$revoked = $this->check( 'session:' . $key, function () use ( $key, $role, $boundary, $preflight ): void {
					self::ensure( $preflight );
					$this->mutation_preflight();
					self::ensure( isset( $this->state['sessions'][ $key ] ) );
					$record = $this->state['sessions'][ $key ];
					$this->actor( $role, $record['id'] );
					$this->owned_password( $role );
					$list = $this->session_list( $record['id'] );
					if ( isset( $list[ $record['token'] ] ) ) {
						self::ensure( $this->client_name( $role, $boundary ) === ( $list[ $record['token'] ]['client_params']['clientInfo']['name'] ?? null ) );
						self::ensure( true === $this->session_api( 'delete_session', array( $record['id'], $record['token'] ) ) );
					}
					foreach ( $this->session_list( $record['id'] ) as $token => $session ) {
						self::ensure( $token !== $record['token'] && $this->client_name( $role, $boundary ) !== ( $session['client_params']['clientInfo']['name'] ?? null ) );
					}
				} );
				$complete = $complete && $closed && $revoked;
			}
		}
		foreach ( self::ROLES as $role ) {
			$revoked = $this->check( 'password:' . $role, function () use ( $role, $preflight ): void {
				self::ensure( $preflight );
				$this->mutation_preflight();
				$record = $this->owned_password( $role );
				foreach ( $this->password_list( $record['id'] ) as $password ) {
					if ( $record['uuid'] === ( $password['uuid'] ?? null ) ) {
						self::ensure( $record['name'] === ( $password['name'] ?? null )
							&& true === WP_Application_Passwords::delete_application_password( $record['id'], $record['uuid'] ) );
					}
				}
				self::ensure( array() === $this->password_list( $record['id'] ) );
			} );
			$complete = $complete && $revoked;
		}
		return $complete;
	}

	private function cleanup_content( bool $content ): void {
		// Children first: never rely on WordPress's implicit recursive deletions.
		$pending = $this->state['posts'];
		$index = 0;
		while ( array() !== $pending ) {
			$leaves = array_diff( array_keys( $pending ), array_column( $pending, 'parent' ) );
			if ( array() === $leaves ) {
				$this->check( 'post-order', static function (): void { self::ensure( false ); } );
				$content = false;
				break;
			}
			usort( $leaves, static function ( int $left, int $right ) use ( $pending ): int {
				$priority = static fn( string $type ) => 'attachment' === $type ? 2 : ( 'revision' === $type ? 0 : 1 );
				return $priority( $pending[ $left ]['type'] ) <=> $priority( $pending[ $right ]['type'] );
			} );
			$leaves = array_slice( $leaves, 0, 1 );
			foreach ( $leaves as $id ) {
				$record = $pending[ $id ];
				$ok = $this->check( 'post:' . ++$index, function () use ( $id, $record, $content ): void {
					self::ensure( $content );
					$this->mutation_preflight();
					if ( null !== get_post( $id ) ) {
						$result = 'attachment' === $record['type'] ? wp_delete_attachment( $id, true ) : wp_delete_post( $id, true );
						self::ensure( false !== $result && null !== $result && ! is_wp_error( $result ) );
					}
					$this->post_absent( $id );
					if ( isset( $record['file'] ) ) {
						self::ensure( false === @lstat( $record['file'] ) );
						$this->state['deleted_files'][] = $record['file'];
					}
				} );
				$content = $content && $ok;
				unset( $pending[ $id ] );
			}
		}
		$this->check( 'content-absence', function () use ( &$content ): void {
			try {
				self::ensure( $content );
				foreach ( $this->state['posts'] as $id => $record ) {
					$this->post_absent( $id );
				}
				foreach ( $this->state['comments'] as $id => $record ) {
					$this->comment_absent( $id );
				}
				foreach ( $this->state['users'] as $id ) {
					self::ensure( array() === $this->raw_ids( 'posts', 'post_author', $id )
						&& array() === $this->raw_ids( 'comments', 'user_id', $id ) );
				}
			} catch ( Throwable $error ) {
				$content = false;
				throw $error;
			}
		} );
		$files = $this->check( 'files', function () use ( $content ): void {
			self::ensure( $content );
			$this->files_valid();
		} );
		$index = 0;
		foreach ( $this->state['files'] as $path => $snapshot ) {
			$ok = $this->check( 'file:' . ++$index, function () use ( $path, $snapshot, $files ): void {
				self::ensure( $files );
				$this->mutation_preflight();
				if ( false !== @lstat( $path ) ) {
					Wstm108_Files::remove( $path, $snapshot );
				}
				self::ensure( false === @lstat( $path ) );
				if ( ! in_array( $path, $this->state['deleted_files'], true ) ) {
					$this->state['deleted_files'][] = $path;
				}
			} );
			$files = $files && $ok;
		}
		$files = $this->check( 'upload-directory', function () use ( $files ): void {
			$upload = $this->state['upload'];
			self::ensure( $files && is_array( $upload ) && is_array( $upload['identity'] )
				&& Wstm108_Files::directory( $upload['root'] ) === $upload['active_root_identity']
				&& Wstm108_Files::directory( $upload['path'] ) === $upload['identity']
				&& array( '.', '..' ) === scandir( $upload['path'] ) );
			$this->mutation_preflight();
			self::ensure( rmdir( $upload['path'] ) && false === @lstat( $upload['path'] )
				&& $upload['root_identity'] === Wstm108_Files::directory( $upload['root'] ) );
		} ) && $files;
		$actors = $content && $files;
		foreach ( self::ROLES as $role ) {
			$removed = $this->check( 'actor:' . $role, function () use ( $role, $actors ): void {
				self::ensure( $actors && isset( $this->state['users'][ $role ] )
					&& true === ( $this->state['cleanup'][ 'password:' . $role ] ?? null ) );
				$this->actor_deletion_preflight();
				$id = $this->state['users'][ $role ];
				$this->actor( $role, $id );
				self::ensure( array() === $this->raw_ids( 'posts', 'post_author', $id )
					&& array() === $this->raw_ids( 'comments', 'user_id', $id )
					&& array() === $this->session_list( $id ) && array() === $this->password_list( $id ) );
				self::ensure( true === wp_delete_user( $id ) );
				$this->user_absent( $role, $id );
			} );
			$actors = $actors && $removed;
		}
	}

	private function content_absent(): void {
		clearstatcache();
		$this->options_absent();
		foreach ( $this->state['posts'] as $id => $record ) {
			$this->post_absent( $id );
		}
		foreach ( $this->state['comments'] as $id => $record ) {
			$this->comment_absent( $id );
		}
		foreach ( $this->state['users'] as $id ) {
			self::ensure( array() === $this->raw_ids( 'posts', 'post_author', $id )
				&& array() === $this->raw_ids( 'comments', 'user_id', $id ) );
		}
		self::ensure( array() === $this->attachment_reference_ids() );
		foreach ( $this->state['files'] as $path => $snapshot ) {
			self::ensure( false === @lstat( $path ) );
		}
		$upload = $this->state['upload'];
		self::ensure( false === @lstat( $upload['path'] ) && Wstm108_Files::directory( $upload['root'] ) === $upload['root_identity'] );
	}

	private function user_absent( string $role, int $id ): void {
		global $wpdb;
		self::ensure( false === get_userdata( $id ) && array() === get_user_meta( $id )
			&& array() === $this->raw_ids( 'users', 'ID', $id ) && array() === $this->raw_ids( 'usermeta', 'user_id', $id )
			&& array() === $this->password_list( $id ) && array() === $this->session_list( $id )
			&& array() === $this->raw_ids( 'posts', 'post_author', $id ) && array() === $this->raw_ids( 'comments', 'user_id', $id ) );
		$login = $this->state['run'] . '-' . $role;
		$users = $wpdb->get_col( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->users} WHERE user_login = %s OR user_email = %s ORDER BY ID ASC LIMIT 2",
			$login, $login . '@example.test'
		) );
		self::ensure( '' === $wpdb->last_error && array() === $users );
	}

	private function actor_deletion_preflight(): void {
		$this->guard();
		$this->coverage();
		$this->content_absent();
		foreach ( $this->state['users'] as $role => $id ) {
			if ( true === ( $this->state['cleanup'][ 'actor:' . $role ] ?? null ) ) {
				$this->user_absent( $role, $id );
			} else {
				$this->role( $id );
				$this->owned_password( $role );
				self::ensure( array() === $this->password_list( $id ) && array() === $this->session_list( $id ) );
			}
		}
		$this->guard();
	}

	private function absence(): void {
		$this->coverage();
		$this->content_absent();
		foreach ( $this->state['users'] as $role => $id ) {
			$this->user_absent( $role, $id );
		}
	}

	/** No options are created by this ledger; run-scoped rows are unexpected. */
	private function options_absent(): void {
		global $wpdb;
		$run = $this->state['run'];
		$options = $wpdb->get_col( $wpdb->prepare(
			"SELECT option_id FROM {$wpdb->options} WHERE option_name = %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s ORDER BY option_id ASC LIMIT 501",
			$run, $wpdb->esc_like( $run . '-' ) . '%', $wpdb->esc_like( $run . ':' ) . '%', $wpdb->esc_like( $run . '_' ) . '%'
		) );
		self::ensure( '' === $wpdb->last_error && array() === $options );
	}

	/**
	 * Read-only finalization gate, after a fresh WP bootstrap and original-runtime
	 * attestation. Failure leaves the journal, resources, probe and lock untouched.
	 */
	public function verify_absent(): void {
		try {
			$this->guard();
			$this->validate_state();
			self::ensure( $this->ledger_complete() );
			self::validate_proof( $this->state['proof'], $this->state['binding'] );
			$this->absence();
			$this->guard();
		} catch ( Throwable $error ) {
			// Read APIs can throw messages containing private values.
			self::ensure( false );
		}
	}

	private function labels(): array {
		return self::cleanup_labels( $this->resource_counts() );
	}

	private function resource_counts(): array {
		return array(
			'posts' => count( $this->state['posts'] ),
			'comments' => count( $this->state['comments'] ),
			'files' => count( $this->state['files'] ),
		);
	}

	private static function cleanup_labels( array $counts ): array {
		$labels = array( 'journal', 'recovery', 'coverage', 'options', 'clients', 'references', 'content-absence', 'files', 'upload-directory', 'final-absence', 'journal-final' );
		foreach ( self::ROLES as $role ) {
			$labels[] = 'password:' . $role;
			$labels[] = 'actor:' . $role;
			foreach ( self::BOUNDARIES as $boundary ) {
				$labels[] = 'HTTP:' . $boundary . ':' . $role;
				$labels[] = 'session:' . $boundary . ':' . $role;
			}
		}
		foreach ( array( 'post', 'comment', 'file' ) as $kind ) {
			for ( $index = 1; $index <= $counts[ $kind . 's' ]; ++$index ) {
				$labels[] = $kind . ':' . $index;
			}
		}
		sort( $labels, SORT_STRING );
		return $labels;
	}

	/**
	 * Pure public contract/binding validation with an owner-keyed digest.
	 * Owner is a public run identity, not an authentication secret: anyone
	 * knowing it can rehash public outcomes and the private inventory commitment.
	 * Retirement requires exact private-journal equality and live absence checks.
	 */
	public static function validate_proof( array $proof, array $binding ): void {
		self::binding_schema( $binding );
		self::ensure( array_keys( $proof ) === array(
			'version', 'source_sha', 'tree_sha', 'package_sha256', 'binding_sha256', 'resource_counts',
			'cleanup_complete', 'cleanup', 'failed', 'first_error', 'inventory_sha256', 'cleanup_sha256',
		) );
		self::ensure( 1 === $proof['version'] && true === $proof['cleanup_complete']
			&& 0 === $proof['failed'] && null === $proof['first_error']
			&& $binding['source_sha'] === $proof['source_sha']
			&& $binding['tree_sha'] === $proof['tree_sha']
			&& $binding['package_sha256'] === $proof['package_sha256']
			&& hash( 'sha256', json_encode( $binding, JSON_THROW_ON_ERROR ) ) === $proof['binding_sha256'] );
		self::ensure( is_array( $proof['resource_counts'] ) && array_keys( $proof['resource_counts'] ) === array( 'posts', 'comments', 'files' )
			&& is_array( $proof['cleanup'] ) );
		foreach ( $proof['resource_counts'] as $count ) {
			// Bound work by supplied outcomes before constructing any ordinal labels.
			self::ensure( is_int( $count ) && $count > 0 && $count <= count( $proof['cleanup'] ) );
		}
		self::ensure( self::cleanup_labels( $proof['resource_counts'] ) === array_keys( $proof['cleanup'] ) );
		foreach ( $proof['cleanup'] as $result ) {
			self::ensure( true === $result );
		}
		foreach ( array( 'binding_sha256', 'inventory_sha256', 'cleanup_sha256' ) as $key ) {
			self::ensure( is_string( $proof[ $key ] ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $proof[ $key ] ) );
		}
		$expected = $proof['cleanup_sha256'];
		unset( $proof['cleanup_sha256'] );
		self::ensure( hash_equals( $expected, hash_hmac( 'sha256', json_encode( $proof, JSON_THROW_ON_ERROR ), $binding['owner'] ) ) );
	}

	private function ledger_complete(): bool {
		if ( 'cleaned' !== $this->state['phase'] || 0 !== $this->state['failed'] || null !== $this->state['first_error'] ) {
			return false;
		}
		try {
			$this->coverage();
		} catch ( Throwable $error ) {
			return false;
		}
		$actual = array_keys( $this->state['cleanup'] );
		sort( $actual );
		return $this->labels() === $actual && array() === array_filter( $this->state['cleanup'], static fn( $value ) => true !== $value );
	}

	public function proof(): array {
		$complete = false;
		try {
			$this->guard();
			$complete = $this->ledger_complete();
			self::ensure( 'recording' === $this->state['phase'] || $this->state['proof'] === $this->assemble_proof( $complete ) );
			if ( $complete ) {
				$this->absence();
			}
		} catch ( Throwable $error ) {
			$complete = false;
			if ( 'recording' !== $this->state['phase'] && 0 === $this->state['failed'] ) {
				$this->check( 'proof-integrity', static function (): void { self::ensure( false ); } );
			}
		}
		return $this->assemble_proof( $complete );
	}

	private function assemble_proof( bool $complete ): array {
		$cleanup = $this->state['cleanup'];
		ksort( $cleanup, SORT_STRING );
		$proof = array(
			'version' => 1, 'source_sha' => $this->state['binding']['source_sha'],
			'tree_sha' => $this->state['binding']['tree_sha'], 'package_sha256' => $this->state['binding']['package_sha256'],
			'binding_sha256' => hash( 'sha256', json_encode( $this->state['binding'], JSON_THROW_ON_ERROR ) ),
			'resource_counts' => $this->resource_counts(),
			'cleanup_complete' => $complete, 'cleanup' => $cleanup,
			'failed' => $this->state['failed'], 'first_error' => $this->state['first_error'],
			'inventory_sha256' => hash( 'sha256', $this->encode( false ) ),
		);
		$proof['cleanup_sha256'] = hash_hmac( 'sha256', json_encode( $proof, JSON_THROW_ON_ERROR ), $this->state['binding']['owner'] );
		return $proof;
	}

	private function retirement_ready( array $expectedProof ): void {
		$this->guard();
		self::validate_proof( $expectedProof, $this->state['binding'] );
		self::ensure( $expectedProof === $this->state['proof'] );
		$this->verify_absent();
	}

	/** Read-only preparation. The host binds this target; journal bytes stay private. */
	public function retirement_snapshot( array $expectedProof ): array {
		self::ensure( $this->independentlyAnchored );
		$this->retirement_ready( $expectedProof );
		return array( 'identity' => $this->snapshot['identity'], 'sha256' => $this->snapshot['sha256'] );
	}

	private function assert_retirement_snapshot( array $expectedSnapshot ): void {
		self::ensure( $this->independentlyAnchored
			&& array_keys( $expectedSnapshot ) === array( 'identity', 'sha256' )
			&& is_array( $expectedSnapshot['identity'] ) && is_string( $expectedSnapshot['sha256'] ) );
		self::identity_schema( $expectedSnapshot['identity'], 0100000 );
		self::ensure( 1 === preg_match( '/^[a-f0-9]{64}$/D', $expectedSnapshot['sha256'] )
			&& $expectedSnapshot['identity'] === $this->snapshot['identity']
			&& hash_equals( $expectedSnapshot['sha256'], $this->snapshot['sha256'] ) );
		$this->guard();
	}

	/**
	 * Prepared retirement requires the independently enrolled identity on resume.
	 * The optional caller guard must return true; refusal/exception never deletes.
	 * Legacy proof-only retirement remains self-bound, not independently anchored.
	 */
	public function retire( array $expectedProof, ?array $expectedSnapshot = null, ?callable $beforeMutation = null ): void {
		if ( null !== $expectedSnapshot ) {
			$this->assert_retirement_snapshot( $expectedSnapshot );
		}
		self::ensure( null === $beforeMutation || null !== $expectedSnapshot );
		$this->retirement_ready( $expectedProof );
		if ( null !== $beforeMutation ) {
			$authorized = false;
			try {
				$authorized = $beforeMutation();
			} catch ( Throwable $error ) {
				self::ensure( false );
			}
			self::ensure( true === $authorized );
			$this->retirement_ready( $expectedProof );
		}
		if ( null !== $expectedSnapshot ) {
			$this->assert_retirement_snapshot( $expectedSnapshot );
		}
		Wstm108_Files::remove( $this->path, $this->snapshot );
	}
}
