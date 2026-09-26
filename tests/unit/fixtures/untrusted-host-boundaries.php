<?php

declare(strict_types=1);

require_once dirname( __DIR__, 3 ) . '/scripts/untrusted-host-controller.php';

/** Explicit synthetic boundaries in disposable copies, never a production admission seam. */
final class Wstm108_HostBoundaryFixture {
	private static function require( bool $condition, string $message ): void {
		if ( ! $condition ) { throw new RuntimeException( 'Host boundary fixture: ' . $message ); }
	}

	private static array $substitutions = array();

	/** Source-pinned control-flow model only; never invokes a target or creates a fixture. */
	public static function outer_admission_model( string $source, string $entrypoint, string $platform, bool $explicit, string $selected, string $matrix, array $files ): array {
		$prefixes = array(
			'tests/untrusted-stage-test.sh' => <<<'SH'
#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(pwd)"
WORK="$ROOT/build/untrusted-stage-test-$$"
if [[ "$(uname -s)" != Linux ]]; then
	echo 'BLOCKED: positive host authority and source outer mocks require native POSIX; Windows refusal is separate evidence.' >&2
	exit 78
fi
SH,
			'scripts/test-release-runtime.sh' => <<<'SH'
#!/usr/bin/env bash
set -Eeuo pipefail

REPO_ROOT="$(pwd)"
WORK="$REPO_ROOT/build/release-runtime-tests-$$"
export WORK
if [[ "$(uname -s)" != Linux ]]; then
	echo 'BLOCKED: positive authority and source/original-ZIP outer mocks require native POSIX; native-Windows refusal is separate.' >&2
	exit 78
fi
SH,
		);
		$admission = <<<'SH'
WSTM108_MOCK_MATRIX_PHP="$(readlink -e -- "$(command -v php)")" || {
	echo 'BLOCKED: cannot resolve the pinned native matrix PHP executable.' >&2
	exit 78
}
WSTM108_MOCK_PHP="${WSTM108_HOST_PHP-$WSTM108_MOCK_MATRIX_PHP}"
for php_binary in "$WSTM108_MOCK_MATRIX_PHP" "$WSTM108_MOCK_PHP"; do
	[[ -n "$php_binary" && "$php_binary" == /* && "$php_binary" == "$(readlink -e -- "$php_binary")" \
		&& -f "$php_binary" && -x "$php_binary" && "${php_binary##*/}" =~ ^php([0-9]+(\.[0-9]+)?)?$ \
		&& "$(stat -c %u -- "$php_binary")" == 0 ]] \
		&& (( ( 8#$(stat -c %a -- "$php_binary") & 0022 ) == 0 )) || {
		echo 'BLOCKED: host and matrix PHP must be canonical trusted native PHP executables; explicit selection has no fallback.' >&2
		exit 78
	}
done
export WSTM108_HOST_PHP="$WSTM108_MOCK_PHP"
readonly WSTM108_HOST_PHP WSTM108_MOCK_PHP WSTM108_MOCK_MATRIX_PHP
"$WSTM108_MOCK_PHP" -r 'require $argv[1]; Wstm108_Export::require_host();' "$@ROOT@/scripts/untrusted-export.php"
SH;
		self::require( isset( $prefixes[ $entrypoint ] ), 'only the two owned outer entrypoints are modeled' );
		$root = 'tests/untrusted-stage-test.sh' === $entrypoint ? 'ROOT' : 'REPO_ROOT';
		$expected = str_replace( "\r\n", "\n", $prefixes[ $entrypoint ] . "\n" . str_replace( '@ROOT@', $root, $admission ) . "\n" );
		$source = str_replace( "\r\n", "\n", $source );
		self::require( substr( $source, 0, strlen( $expected ) ) === $expected
			&& 0 === strpos( substr( $source, strlen( $expected ) ), 'mkdir -p "$WORK/' ), 'actual outer prefix differs from native-admission model' );
		$blocked = array( 'classification' => 'SOURCE_MODEL_BLOCKED', 'exit' => 78, 'target_invocations' => array(), 'fixture_writes' => array() );
		if ( 'Linux' !== $platform ) { return $blocked; }
		$selected = $explicit ? $selected : $matrix;
		foreach ( array( $matrix, $selected ) as $path ) {
			$file = $files[ $path ] ?? null;
			if ( '' === $path || '/' !== $path[0] || ! is_array( $file ) || $path !== $file['canonical']
				|| ! $file['regular'] || ! $file['executable'] || 1 !== preg_match( '/^php([0-9]+(\.[0-9]+)?)?$/D', basename( $path ) )
				|| 0 !== $file['uid'] || 0 !== ( $file['mode'] & 0022 ) ) { return $blocked; }
		}
		return array( 'classification' => 'SOURCE_MODEL_ADMITTED_FOR_CAPABILITY_PROBE', 'exit' => null,
			'target_invocations' => array( $selected ), 'fixture_writes' => array() );
	}

	private static function replace( string $source, string $before, string $after, string $site ): string {
		$ending = false !== strpos( $source, "\r\n" ) ? "\r\n" : "\n";
		$without_pairs = str_replace( "\r\n", '', $source );
		self::require( false === strpos( $without_pairs, "\r" )
			&& ( "\n" === $ending || false === strpos( $without_pairs, "\n" ) ), 'uniform exact source line endings required' );
		$before = str_replace( "\n", $ending, str_replace( "\r\n", "\n", $before ) );
		$after = str_replace( "\n", $ending, str_replace( "\r\n", "\n", $after ) );
		self::require( $before !== $after && 1 === substr_count( $source, $before ), 'exact non-noop source substitution must match once' );
		$result = str_replace( $before, $after, $source );
		self::require( 1 === substr_count( $result, $after ) && str_replace( $after, $before, $result ) === $source, 'unchanged remainder and reversible exact site' );
		self::$substitutions[] = array( 'site' => $site, 'before_sha256' => hash( 'sha256', $before ), 'after_sha256' => hash( 'sha256', $after ),
			'unchanged_remainder_sha256' => hash( 'sha256', str_replace( $before, '', $source ) ), 'count' => 1 );
		return $result;
	}

	private static function diagnostic_terminal( string $source, string $root ): string {
		$terminal_catch = <<<'PHP'
	} catch ( Throwable $error ) {
		fwrite( STDERR, "WSTM108 controller refused; private diagnostic channel retained; terminal release outcome must not be inferred.\n" );
		exit( $error->getCode() > 0 && $error->getCode() <= 255 ? $error->getCode() : 1 );
	}
PHP;
		$diagnostic_catch = <<<'PHP'
	} catch ( Throwable $error ) {
		if ( '1' === getenv( 'WSTM108_MOCK_DIAGNOSTIC' ) ) {
			try {
				require_once __DIR__ . '/../tests/unit/fixtures/untrusted-runtime-diagnostic.php';
				Wstm108_SyntheticDiagnostic::record( $error, @DIAGNOSTIC_DIRECTORY@ );
			} catch ( Throwable $diagnostic_error ) {
				fwrite( STDERR, "WSTM108 synthetic diagnostic write failed; original exit retained.\n" );
			}
		}
		fwrite( STDERR, "WSTM108 controller refused; private diagnostic channel retained; terminal release outcome must not be inferred.\n" );
		exit( $error->getCode() > 0 && $error->getCode() <= 255 ? $error->getCode() : 1 );
	}
PHP;
		$diagnostic_catch = str_replace( '@DIAGNOSTIC_DIRECTORY@', var_export( $root . '/first-package-diagnostic', true ), $diagnostic_catch );
		return self::replace( $source, $terminal_catch, $diagnostic_catch, 'scripts/untrusted-host-controller.php::synthetic-terminal-diagnostic' );
	}

	private static function topology_mountinfo( string $root, array $identity ): string {
		Wstm108_HostTopology::stable_identity( $identity );
		self::require( PHP_INT_SIZE >= 8 && $identity['dev'] >= 0 && 0040000 === ( $identity['mode'] & 0170000 )
			&& '/' !== $root, 'synthetic namespace requires a directory device and a non-root WORK' );
		$device = $identity['dev'];
		$major = ( ( $device >> 8 ) & 0xfff ) | ( ( $device >> 32 ) & 0xfffff000 );
		$minor = ( $device & 0xff ) | ( ( $device >> 12 ) & 0xffffff00 );
		$escaped = strtr( $root, array( '\\' => '\\134', ' ' => '\\040' ) );
		// tmpfs is a modeled supported type, not an observation about the host.
		$table = "1 0 0:0 / / rw - wstm108-synthetic-outside synthetic rw\n"
			. "2 1 {$major}:{$minor} {$escaped} {$escaped} rw - tmpfs synthetic rw\n";
		$mounts = Wstm108_HostTopology::mounts( $table );
		self::require( $root === $mounts[1]['point'], 'synthetic WORK must follow the unchanged canonical path policy' );
		return $table;
	}

	private static function topology_admission( string $source, string $root, array $identity ): string {
		$table = self::topology_mountinfo( $root, $identity );
		$identity = Wstm108_HostTopology::stable_identity( $identity );
		$original = <<<'PHP'
	public static function admit( string $endpoint ): array {
		self::require( 'Linux' === PHP_OS_FAMILY && function_exists( 'posix_geteuid' ), 'native-linux-prerequisite' );
		self::require( in_array( $endpoint, array( 'unix:///run/docker.sock', 'unix:///var/run/docker.sock' ), true ), 'nonlocal-endpoint' );
		$socket = realpath( substr( $endpoint, 7 ) );
		self::require( '/run/docker.sock' === $socket, 'unrecognized-socket-alias' );
		$socket_before = self::stat( $socket );
		self::require( 0140000 === ( $socket_before['mode'] & 0170000 ) && 0 === $socket_before['uid'], 'nonroot-or-nonsocket-endpoint' );
		$hint = Wstm108_Files::file( '/run/docker.pid' );
		self::require( 0 === $hint['identity']['uid'] && 0 === ( $hint['identity']['mode'] & 0022 )
			&& 1 === preg_match( '/^[1-9][0-9]{0,9}\n?$/D', $hint['bytes'] ), 'untrusted-pid-discovery-hint' );
		$pid = (int) trim( $hint['bytes'] );
		$before = self::peer( $socket, $pid );
		$mountinfo = self::read( '/proc/self/mountinfo', 4194304 );
		self::require( $mountinfo === self::read( '/proc/' . $pid . '/mountinfo', 4194304 ), 'daemon-mount-table-differs' );
		$mounts = self::mounts( $mountinfo );
		self::require( $before === self::peer( $socket, $pid ) && $socket_before === self::stat( $socket )
			&& $mountinfo === self::read( '/proc/self/mountinfo', 4194304 ), 'peer-or-topology-changed-during-admission' );
		Wstm108_Files::assert_file( '/run/docker.pid', $hint );
		return array( 'endpoint' => $endpoint, 'socket_identity' => $socket_before, 'peer' => $before,
			'mountinfo_sha256' => hash( 'sha256', $mountinfo ), 'mounts' => $mounts );
	}
PHP;
		$mock = <<<'PHP'
	public static function admit( string $endpoint ): array {
		if ('1' !== getenv('WSTM108_MOCK_ONLY') || 'Linux' !== PHP_OS_FAMILY || ! function_exists('posix_geteuid')) {
			throw new RuntimeException('Explicit native Linux synthetic namespace only.');
		}
		$root = @SYNTHETIC_ROOT@;
		$identity = @SYNTHETIC_IDENTITY@;
		$assert_root = static function () use ($root, $identity): void {
			if (getenv('WSTM108_MOCK_ROOT') !== $root || realpath($root) !== $root || posix_geteuid() !== $identity['uid']
				|| self::stable_identity(Wstm108_Files::directory($root)) !== $identity) {
				throw new RuntimeException('Synthetic namespace WORK identity changed.');
			}
		};
		$assert_root();
		// Closed synthetic namespace/type model, never real host or daemon evidence.
		$mounts = self::mounts(base64_decode(@SYNTHETIC_TABLE@, true));
		$authority = (string) getenv('WSTM108_HOST_AUTHORITY_ROOT');
		self::coordinate($authority, $mounts);
		self::native_coordinates(array($root, $authority), $mounts);
		$assert_root();
		return array('endpoint' => $endpoint, 'synthetic_peer_only' => true, 'mounts' => $mounts);
	}
PHP;
		$mock = strtr( $mock, array( '@SYNTHETIC_ROOT@' => var_export( $root, true ),
			'@SYNTHETIC_IDENTITY@' => var_export( $identity, true ), '@SYNTHETIC_TABLE@' => var_export( base64_encode( $table ), true ) ) );
		return self::replace( $source, $original, $mock, 'scripts/untrusted-host-topology.php::admit' );
	}

	public static function install(): void {
		Wstm108_Export::require_host();
		self::require( '1' === getenv( 'WSTM108_MOCK_ONLY' ) && 'Linux' === PHP_OS_FAMILY, 'explicit Linux copy-only mock required' );
		$root = realpath( getenv( 'WSTM108_MOCK_ROOT' ) ?: '' );
		$copy = realpath( getenv( 'WSTM108_MOCK_CHECKOUT' ) ?: '' );
		$origin = realpath( getenv( 'WSTM108_MOCK_ORIGIN' ) ?: '' );
		self::require( is_string( $root ) && is_string( $copy ) && is_string( $origin ) && $copy !== $origin
			&& 0 === strpos( $copy, $root . '/' ) && 0 === strpos( $root, $origin . '/build/' ), 'not an independently named disposable copy' );
		$identity = Wstm108_Files::directory( $root );
		self::require( posix_geteuid() === $identity['uid'], 'synthetic WORK must belong to the invoking user' );
		$files = array();
		$core = array();
		foreach ( array( 'scripts/untrusted-export.php', 'scripts/untrusted-release.php', 'scripts/destructive-retention.sh',
			'tests/e2e/untrusted-content-files.php', 'tests/e2e/untrusted-content-private-wire.php' ) as $path ) {
			$file = Wstm108_Files::file( $copy . '/' . $path );
			self::require( $file['bytes'] === Wstm108_Files::file( $origin . '/' . $path )['bytes'], 'byte-identical retained core' );
			$core[ $path ] = array( 'sha256' => $file['sha256'], 'mode' => $file['identity']['mode'] );
		}
		foreach ( array( 'untrusted-host-topology.php', 'untrusted-authority.php', 'untrusted-host-controller.php', 'untrusted-host-bootstrap.sh' ) as $name ) {
			$files[ $name ] = Wstm108_Files::file( $copy . '/scripts/' . $name );
			self::require( $files[ $name ]['bytes'] === Wstm108_Files::file( $origin . '/scripts/' . $name )['bytes']
				&& false === strpos( $files[ $name ]['bytes'], 'WSTM108_MOCK_' ), 'unaltered source copy and no real-checkout test bypass' );
		}
		$updates = array( 'untrusted-host-topology.php' => self::topology_admission( $files['untrusted-host-topology.php']['bytes'], $root, $identity ) );
		$source = $files['untrusted-authority.php']['bytes'];
		$original = <<<'PHP'
	public static function docker_binary(): array {
		$path = realpath( '/usr/bin/docker' );
		self::require( is_string( $path ), 'pinned native Docker binary is unavailable.' );
		$file = Wstm108_Files::file( $path );
		self::require( 0 === $file['identity']['uid'] && 0 === ( $file['identity']['mode'] & 0022 )
			&& 0 !== ( $file['identity']['mode'] & 0111 ), 'Docker binary is not a trusted native executable.' );
		return array( 'path' => $path, 'file' => self::metadata( $file ) );
	}
PHP;
		$mock = <<<'PHP'
	public static function docker_binary(): array {
		if ('1' !== getenv('WSTM108_MOCK_ONLY')) { throw new RuntimeException('Synthetic executable requires explicit opt-in.'); }
		$path = (string) getenv('WSTM108_MOCK_ROOT') . '/bin/docker';
		return array('path' => $path, 'file' => self::metadata(Wstm108_Files::file($path)));
	}
PHP;
		$updates['untrusted-authority.php'] = self::replace( $source, $original, $mock, 'scripts/untrusted-authority.php::docker_binary' );
		$source = $files['untrusted-host-controller.php']['bytes'];
		$source = self::replace( $source, "\$arguments = array( PHP_BINARY, __DIR__ . '/untrusted-authority.php',",
			"\$arguments = array( getenv( 'WSTM108_MOCK_ROOT' ) . '/bin/php', __DIR__ . '/untrusted-authority.php',", 'scripts/untrusted-host-controller.php::action-executable' );
		$source = self::replace( $source, "\$provenance = \$this->capture( 'helper-provenance', array( PHP_BINARY,",
			"\$provenance = \$this->capture( 'helper-provenance', array( getenv( 'WSTM108_MOCK_ROOT' ) . '/bin/php',", 'scripts/untrusted-host-controller.php::provenance-executable' );
		$terminal = "\t\t\$release->commit( \$this->state, \$this->context, \$precommit );\n\t\treturn 0;";
		$mock_terminal = <<<'PHP'
		// Synthetic interruption controls only in this explicitly copied source.
		$fault = getenv('WSTM108_RELEASE_FAULT');
		if (in_array($fault, array('precommit-evidence', 'companion-replacement'), true)) {
			putenv('WSTM108_HOST_STATE=' . base64_encode(json_encode($this->state, JSON_THROW_ON_ERROR)));
			$argv = array('injected-test-boundary', $fault);
			require __DIR__ . '/../tests/unit/fixtures/untrusted-release-fault.php';
		}
		$release->commit( $this->state, $this->context, $precommit );
		if ('postcommit-ack' === $fault) { return 75; }
		return 0;
PHP;
		$updates['untrusted-host-controller.php'] = self::replace( $source, $terminal, $mock_terminal, 'scripts/untrusted-host-controller.php::named-precommit-postcommit-interruptions' );
		$updates['untrusted-host-controller.php'] = self::diagnostic_terminal( $updates['untrusted-host-controller.php'], $root );
		$source = $files['untrusted-host-bootstrap.sh']['bytes'];
		$source = self::replace( $source, 'php_binary="$(readlink -e -- "$(command -v php)")"', 'php_binary="$(readlink -e -- "${WSTM108_MOCK_PHP:?}")"',
			'scripts/untrusted-host-bootstrap.sh::native-php-executable' );
		$source = self::replace( $source, 'PATH=/usr/bin:/bin HOME="${HOME:?}" ' . chr( 92 ), 'PATH="$WSTM108_MOCK_ROOT/bin:/usr/bin:/bin" HOME="${HOME:?}" ' . chr( 92 ),
			'scripts/untrusted-host-bootstrap.sh::synthetic-executable-path' );
		$mock_environment = <<<'SH'
		WSTM108_MOCK_ONLY=1 WSTM108_MOCK_ROOT="${WSTM108_MOCK_ROOT:?}" WSTM108_MOCK_CHECKOUT="${WSTM108_MOCK_CHECKOUT:?}" \
		WSTM108_MOCK_PHP="${WSTM108_MOCK_PHP:?}" WSTM108_MOCK_BASH="${WSTM108_MOCK_BASH:?}" \
		WSTM108_MOCK_MATRIX_PHP="${WSTM108_MOCK_MATRIX_PHP:?}" \
		WSTM108_MOCK_DIAGNOSTIC="${WSTM108_MOCK_DIAGNOSTIC:-0}" \
		WSTM108_MOCK_LIVE="${WSTM108_MOCK_LIVE:?}" TRACE="${TRACE:?}" \
		WSTM108_MOCK_FAULT="${WSTM108_MOCK_FAULT:-}" WSTM108_RELEASE_FAULT="${WSTM108_RELEASE_FAULT:-}" \
		FAIL_STAGE="${FAIL_STAGE:-}" FAIL_RESTORE="${FAIL_RESTORE:-}" FAIL_FINALIZE="${FAIL_FINALIZE:-}" \
		FAIL_PROOF="${FAIL_PROOF:-}" FAIL_OWNED_CLEANUP="${FAIL_OWNED_CLEANUP:-}" \
SH;
		$source = self::replace( $source, "\texec env -i \\\n", "\texec env -i \\\n" . $mock_environment . "\n", 'scripts/untrusted-host-bootstrap.sh::named-synthetic-boundary-inputs' );
		$updates['untrusted-host-bootstrap.sh'] = $source;
		foreach ( $updates as $name => $bytes ) { Wstm108_Files::update( $copy . '/scripts/' . $name, $files[ $name ], $bytes ); }
		foreach ( $core as $path => $record ) {
			$file = Wstm108_Files::file( $copy . '/' . $path );
			self::require( $record === array( 'sha256' => $file['sha256'], 'mode' => $file['identity']['mode'] ), 'core changed during boundary installation' );
		}
		Wstm108_Files::create( $root . '/synthetic-host-boundaries.private.json', json_encode( array(
			'claim' => 'synthetic endpoint/peer/filesystem-namespace/type/executable/interruption only; not real host, daemon or custody evidence',
			'before' => array_map( static fn( $file ) => $file['sha256'], $files ),
			'after' => array_map( static fn( $bytes ) => hash( 'sha256', $bytes ), $updates ),
			'exact_sites' => self::$substitutions,
			'unchanged_core' => $core,
		), JSON_THROW_ON_ERROR ) );
	}

	public static function mounts( string $project, string $checkout, string $docker_root ): array {
		require_once dirname( __DIR__, 3 ) . '/scripts/untrusted-authority.php';
		$endpoint = 'unix:///var/run/docker.sock';
		return array( 'project' => $project, 'daemon' => $endpoint, 'docker_root' => $docker_root, 'bind_sources' => array( $checkout ),
			'volumes' => array(), 'planned_oneoffs' => array(), 'docker' => Wstm108_HostAuthority::docker_binary(),
			'daemon_id' => 'synthetic-daemon', 'topology' => Wstm108_HostTopology::admit( $endpoint ) );
	}

	public static function capture( string $root, bool $failure ): void {
		self::require( '1' === getenv( 'WSTM108_BOUNDARY_OPT_IN' ) && 'Linux' === PHP_OS_FAMILY, 'explicit native filesystem/process control required' );
		$identity = Wstm108_Files::directory( $root );
		$controller = new Wstm108_HostController( $root, $identity, getenv() );
		$out = "before-handler-marker\0\xff\n";
		$err = "stderr-marker\0\xff\n";
		$status = $failure ? 73 : 0;
		$command = array( PHP_BINARY, '-r',
			'fwrite(STDOUT,base64_decode($argv[1]));fwrite(STDERR,base64_decode($argv[2]));fwrite(fopen("php://fd/9","w"),"forged-ready");exit((int)$argv[3]);',
			base64_encode( $out ), base64_encode( $err ), (string) $status );
		$result = $controller->capture( 'startup', $command, $root, array_merge( getenv(), array( 'GITHUB_OUTPUT' => $root . '/must-not-create' ) ) );
		self::require( $status === $result['child_exit'] && $out === $result['streams']['stdout']['bytes']
			&& $err === $result['streams']['stderr']['bytes'] && ! file_exists( $root . '/must-not-create' ), 'actual originals, exit and denied publication descriptor' );
		try { Wstm108_HostController::exact_frame( $result, 'WSTM108_HOST ' ); throw new LogicException( 'Unframed binary diagnostics accepted.' ); }
		catch ( RuntimeException $expected ) {
			self::require( $out === file_get_contents( $root . '/startup.stdout.private' ) && $err === file_get_contents( $root . '/startup.stderr.private' ), 'original bytes survive frame refusal' );
		}
		echo json_encode( array( 'child_exit' => $status, 'stdout_sha256' => hash( 'sha256', $out ), 'stderr_sha256' => hash( 'sha256', $err ),
			'capture_complete' => true, 'publication_denied' => true ), JSON_THROW_ON_ERROR ) . "\n";
	}

	public static function first_exits(): void {
		$path = getenv( 'GITHUB_OUTPUT' );
		self::require( is_string( $path ), 'owned mock workflow output required' );
		$output = Wstm108_Files::file( $path );
		preg_match_all( '/^untrusted_export_root=(.+)$/m', $output['bytes'], $matches );
		self::require( 1 === count( $matches[1] ), 'one safe export root required' );
		$failure = json_decode( file_get_contents( $matches[1][0] . '/untrusted-failure-witnesses.json' ), true, 512, JSON_THROW_ON_ERROR );
		self::require( array( 44, 47 ) === array_values( array_filter( array_column( $failure['failures'], 'child_exit' ), 'is_int' ) ), 'original runner and restoration exits retained' );
		echo "original_status=44 restoration_status=47\n";
	}

	public static function controller_custody( string $root, string $case ): void {
		Wstm108_Export::require_host();
		self::require( '1' === getenv( 'WSTM108_BOUNDARY_OPT_IN' ), 'explicit descriptor control required' );
		$class = Wstm108_HostController::class;
		if ( 'synthetic-sync-refusal' === $case ) {
			$source = file_get_contents( dirname( __DIR__, 3 ) . '/scripts/untrusted-host-controller.php' );
			$start = strpos( $source, 'final class Wstm108_HostController' );
			$end = strpos( $source, "\nif ( realpath( ", $start );
			self::require( false !== $start && false !== $end, 'bounded original controller class' );
			eval( 'namespace Wstm108DescriptorSyncFault; use \Wstm108_Files; use \Wstm108_HostTopology; use \Wstm108_Export; use \RuntimeException; use \Throwable;
function fsync($handle) {
	if ("php://fd/3" === (stream_get_meta_data($handle)["uri"] ?? "")) { return false; }
	return \fsync($handle);
}
' . substr( $source, $start, $end - $start ) );
			$class = 'Wstm108DescriptorSyncFault\\Wstm108_HostController';
		}
		$controller = new $class( $root, Wstm108_Files::directory( $root ), getenv() );
		$bind = new ReflectionMethod( $class, 'bind_controller_streams' );
		$verify = new ReflectionMethod( $class, 'verify_controller_streams' );
		$bind->setAccessible( true ); $verify->setAccessible( true );
		$stream_name = false !== strpos( $case, 'stderr' ) ? 'stderr' : 'stdout';
		$writer = 'stderr' === $stream_name ? STDERR : STDOUT;
		$path = $root . '/controller.' . $stream_name . '.private';
		$before = Wstm108_Files::file( $path );
		$position = null;
		$refused = false;
		$reason = '';
		$checking = false;
		try {
			if ( 0 === strpos( $case, 'initial-' ) ) { self::require( 5 === fwrite( $writer, 'noise' ), 'initial diagnostic injection' ); }
			$checking = true;
			$bind->invoke( $controller );
			$checking = false;
			if ( false !== strpos( $case, 'replacement' ) ) {
				self::require( rename( $path, $path . '.original' ), 'retain original descriptor inode' );
				$replacement = Wstm108_Files::create( $path, '' );
				self::require( $before['identity']['ino'] !== $replacement['identity']['ino'], 'actual new inode required' );
			} elseif ( false !== strpos( $case, 'unlink' ) ) {
				self::require( unlink( $path ), 'actual missing original path control' );
			} elseif ( false !== strpos( $case, 'truncate' ) ) {
				self::require( 5 === fwrite( $writer, 'noise' ) && fflush( $writer ), 'actual original writer append' );
				$position = ftell( $writer );
				self::require( 5 === $position && ftruncate( $writer, 0 ) && 5 === ftell( $writer ), 'truncate must preserve observed writer position' );
			} elseif ( false !== strpos( $case, 'noise' ) ) {
				self::require( 5 === fwrite( $writer, 'noise' ), 'actual original writer diagnostic' );
			} elseif ( 'active-buffer' === $case ) {
				self::require( ob_start(), 'new output buffer control' );
			}
			$checking = true;
			$verify->invoke( $controller );
		} catch ( RuntimeException $error ) {
			self::require( $checking, 'An injection failure is not a guard refusal.' );
			$refused = true;
			$reason = $error->getMessage();
		}
		// End only after the refusal observation, not to make a guard pass.
		if ( 'active-buffer' === $case && ob_get_level() > 0 ) { ob_end_flush(); }
		self::require( $refused === ( 'clean' !== $case ), 'actual descriptor guard outcome' );
		if ( false !== strpos( $case, 'replacement' ) ) {
			Wstm108_Files::assert_file( $path . '.original', $before );
			self::require( '' === file_get_contents( $path ), 'empty replacement bytes do not authorize adoption' );
		}
		if ( false !== strpos( $case, 'truncate' ) ) {
			self::require( '' === file_get_contents( $path ) && 5 === $position
				&& false !== strpos( $reason, 'identity-bytes-or-position' ), 'non-no-op append/truncate refusal' );
		}
		Wstm108_Files::create( $root . '/control-result.json', json_encode( array( 'case' => $case, 'refused' => $refused,
			'writer_position_after_append' => $position, 'original_path_present' => file_exists( $path ) ), JSON_THROW_ON_ERROR ) );
	}

	public static function native_suite( string $root ): void {
		Wstm108_Export::require_host();
		self::require( '1' === getenv( 'WSTM108_BOUNDARY_OPT_IN' ) && 'Linux' === PHP_OS_FAMILY
			&& function_exists( 'posix_geteuid' ), 'explicit native filesystem/process suite required' );
		$root_identity = Wstm108_Files::directory( $root );
		self::require( 0040700 === $root_identity['mode'] && posix_geteuid() === $root_identity['uid'], 'owned suite root' );
		$passed = array();
		$custody = array();
		$selectors = array();
		foreach ( array( 'empty' => '', 'missing' => $root . '/absent-php8.4', 'relative' => 'php',
			'non-php' => realpath( '/bin/true' ) ) as $case => $selected ) {
			self::require( is_string( $selected ), 'explicit invalid-selector fixture input' );
			$directory = $root . '/selector-' . $case;
			self::require( mkdir( $directory, 0700 ), 'exclusive interpreter refusal case' );
			$output = $directory . '/publication.private';
			Wstm108_Files::create( $output, '' );
			$environment = Wstm108_HostController::child_environment( getenv() );
			$environment['WSTM108_HOST_AUTHORITY_ROOT'] = $directory;
			$environment['WSTM108_HOST_PHP'] = $selected;
			$environment['COMPOSE_PROJECT_NAME'] = 'host-interpreter-refusal';
			$environment['GITHUB_OUTPUT'] = $output;
			$environment['E2E_SCRIPT_ROOT'] = dirname( __DIR__, 3 ) . '/scripts';
			$stdout = fopen( $directory . '/public.stdout.private', 'x+b' );
			$stderr = fopen( $directory . '/public.stderr.private', 'x+b' );
			self::require( is_resource( $stdout ) && is_resource( $stderr ), 'selector original streams' );
			$process = proc_open( array( '/bin/bash', '--noprofile', '--norc', '-c',
				'source "$1"; wstm108_host_bootstrap', 'host-selector-control', $environment['E2E_SCRIPT_ROOT'] . '/untrusted-host-bootstrap.sh' ),
				array( 0 => array( 'file', '/dev/null', 'r' ), 1 => $stdout, 2 => $stderr ), $pipes, $root, $environment );
			self::require( is_resource( $process ), 'selector child reservation' );
			$exit = proc_close( $process );
			fclose( $stdout ); fclose( $stderr );
			Wstm108_Files::create( $directory . '/child-exit.json', json_encode( array( 'exit' => $exit ), JSON_THROW_ON_ERROR ) );
			self::require( $exit > 0 && $exit <= 255 && '' === file_get_contents( $output ), 'invalid explicit target cannot fallback or publish' );
			$children = glob( $directory . '/wstm108-bootstrap-*', GLOB_ONLYDIR );
			self::require( is_array( $children ) && 1 === count( $children )
				&& array( '.', '..', 'controller.stderr.private', 'controller.stdout.private' ) === scandir( $children[0] ),
				'refusal before helper or export mutation; finite quarantine reservation only' );
			$selectors[] = $case;
		}
		foreach ( array( 'clean', 'initial-stdout-noise', 'initial-stderr-noise', 'stdout-replacement', 'stderr-replacement',
			'stdout-unlink', 'stderr-unlink', 'stdout-noise', 'stderr-noise', 'stdout-truncate', 'stderr-truncate',
			'active-buffer', 'synthetic-sync-refusal' ) as $case ) {
			$directory = $root . '/controller-' . $case;
			self::require( mkdir( $directory, 0700 ), 'exclusive controller descriptor case' );
			$mask = umask( 0077 );
			try {
				$stdout = fopen( $directory . '/controller.stdout.private', 'x+b' );
				$stderr = fopen( $directory . '/controller.stderr.private', 'x+b' );
			} finally { umask( $mask ); }
			self::require( is_resource( $stdout ) && is_resource( $stderr ), 'actual dual original descriptors' );
			$process = proc_open( array( PHP_BINARY, __FILE__, 'controller-custody', $directory, $case ),
				array( 0 => array( 'file', '/dev/null', 'r' ), 1 => $stdout, 2 => $stderr, 3 => $stdout, 4 => $stderr ),
				$pipes, $root, getenv() );
			self::require( is_resource( $process ), 'actual descriptor child' );
			$exit = proc_close( $process );
			fclose( $stdout ); fclose( $stderr );
			Wstm108_Files::create( $directory . '/child-exit.json', json_encode( array( 'exit' => $exit ), JSON_THROW_ON_ERROR ) );
			self::require( 0 === $exit, 'descriptor control failed; originals and actual exit retained by caller' );
			$result = json_decode( file_get_contents( $directory . '/control-result.json' ), true, 512, JSON_THROW_ON_ERROR );
			self::require( $case === $result['case'] && ( 'clean' !== $case ) === $result['refused'], 'exact actual descriptor result' );
			$custody[] = $case;
		}
		$command = static fn( string $directory ) => array( PHP_BINARY, '-r',
			'file_put_contents($argv[1]."/executed.marker","executed");fwrite(STDOUT,"out\0\xff");fwrite(STDERR,"err\0\xff");exit(73);', $directory );
		foreach ( array( 'stdout', 'stderr' ) as $stream ) {
			$directory = $root . '/collision-' . $stream;
			self::require( mkdir( $directory, 0700 ), 'exclusive collision case' );
			$foreign = Wstm108_Files::create( $directory . '/startup.' . $stream . '.private', "foreign-collision\0\xff" );
			$controller = new Wstm108_HostController( $directory, Wstm108_Files::directory( $directory ), getenv() );
			try { $controller->capture( 'startup', $command( $directory ), $directory, getenv() ); throw new LogicException( 'Capture collision accepted.' ); }
			catch ( RuntimeException $expected ) {
				Wstm108_Files::assert_file( $directory . '/startup.' . $stream . '.private', $foreign );
				self::require( ! file_exists( $directory . '/executed.marker' ), 'collision must precede child invocation' );
			}
			$passed[] = 'actual-' . $stream . '-collision-no-child';
		}
		$directory = $root . '/binary-nonzero';
		self::require( mkdir( $directory, 0700 ), 'exclusive original-stream case' );
		$controller = new Wstm108_HostController( $directory, Wstm108_Files::directory( $directory ), getenv() );
		$result = $controller->capture( 'startup', $command( $directory ), $directory, getenv() );
		self::require( 73 === $result['child_exit'] && "out\0\xff" === $result['streams']['stdout']['bytes']
			&& "err\0\xff" === $result['streams']['stderr']['bytes'], 'actual binary originals and native exit' );
		$passed[] = 'actual-binary-originals-native-exit';
		$directory = $root . '/php-startup-failure';
		self::require( mkdir( $directory, 0700 ), 'exclusive PHP startup failure case' );
		$controller = new Wstm108_HostController( $directory, Wstm108_Files::directory( $directory ), getenv() );
		$result = $controller->capture( 'startup', array( PHP_BINARY, '-d', 'display_errors=stderr', '-r', 'function {' ), $directory, getenv() );
		self::require( 255 === $result['child_exit'] && '' === $result['streams']['stdout']['bytes']
			&& '' !== $result['streams']['stderr']['bytes'] && true === $result['capture_complete'], 'actual pre-handler diagnostic and native exit retained' );
		Wstm108_Files::assert_file( $directory . '/startup.stderr.private', $result['streams']['stderr'] );
		$passed[] = 'actual-php-pre-handler-failure-original-stderr';

		$source = file_get_contents( dirname( __DIR__, 3 ) . '/scripts/untrusted-host-controller.php' );
		$start = strpos( $source, 'final class Wstm108_HostController' );
		$end = strpos( $source, "\nif ( realpath( ", $start );
		self::require( false !== $start && false !== $end, 'bounded controller class for single syscall injection' );
		eval( 'namespace Wstm108ControllerSyncFault; use \Wstm108_Files; use \Wstm108_HostTopology; use \Wstm108_Export; use \RuntimeException; use \Throwable;
function fsync($handle) {
	$uri = stream_get_meta_data($handle)["uri"] ?? "";
	if (substr($uri, -15) === ".stdout.private") { return false; }
	return \fsync($handle);
}
' . substr( $source, $start, $end - $start ) );
		$directory = $root . '/synthetic-sync-failure';
		self::require( mkdir( $directory, 0700 ), 'exclusive sync-failure case' );
		$controller = new \Wstm108ControllerSyncFault\Wstm108_HostController( $directory, Wstm108_Files::directory( $directory ), getenv() );
		try { $controller->capture( 'startup', $command( $directory ), $directory, getenv() ); throw new LogicException( 'Capture persistence failure accepted.' ); }
		catch ( RuntimeException $expected ) {
			self::require( 73 === $expected->getCode() && "out\0\xff" === file_get_contents( $directory . '/startup.stdout.private' )
				&& "err\0\xff" === file_get_contents( $directory . '/startup.stderr.private' ), 'actual first exit and original streams survive synthetic sync failure' );
			$failure = json_decode( file_get_contents( $directory . '/startup.failure.private.json' ), true, 512, JSON_THROW_ON_ERROR );
			self::require( 73 === $failure['child_exit'] && false === $failure['capture_complete'], 'failure is not a complete-success certificate' );
		}
		$passed[] = 'synthetic-sync-failure-real-exit-and-originals';
		$binding = array( 'owner' => str_repeat( 'a', 32 ), 'project' => 'boundary-export', 'source_sha' => str_repeat( 'b', 40 ),
			'tree_sha' => str_repeat( 'c', 40 ), 'package_sha256' => null );
		$run = array( 'id' => '123', 'attempt' => '1', 'job' => 'boundary' );
		$proof = array( 'version' => 1, 'binding' => $binding, 'run' => $run, 'status' => 'failed', 'context_sha256' => null, 'validated_actions' => array(),
			'release' => array( 'authorization' => 'not_authorized', 'commit_outcome' => 'not_observed', 'qa_outcome' => 'not_asserted' ) );
		$failures = array( 'version' => 1, 'binding' => $binding, 'failures' => array(
			array( 'action' => 'reserve', 'reason' => 'ownership-refused', 'child_exit' => 1, 'capture_complete' => true ),
		) );
		$directory = $root . '/export-collision';
		self::require( mkdir( $directory, 0700 ), 'exclusive export collision case' );
		$identity = Wstm108_Files::directory( $directory );
		$foreign = Wstm108_Files::create( $directory . '/untrusted-proof.json', "foreign-receipt-secret\0\xff" );
		try {
			new Wstm108_Export( $directory, $identity, $binding, $run, static function (): void {} );
			throw new LogicException( 'Foreign export collision adopted.' );
		} catch ( RuntimeException $expected ) {
			Wstm108_Files::assert_file( $directory . '/untrusted-proof.json', $foreign );
			self::require( ! file_exists( $directory . '/untrusted-export-manifest.json' ), 'collision cannot publish readiness' );
		}
		$passed[] = 'actual-export-collision-kept-private';
		$directory = $root . '/safe-failure-export';
		self::require( mkdir( $directory, 0700 ), 'exclusive safe failure export case' );
		$identity = Wstm108_Files::directory( $directory );
		$exporter = new Wstm108_Export( $directory, $identity, $binding, $run, static function () use ( $directory, $identity ): void {
			self::require( $identity === Wstm108_Files::directory( $directory ), 'original export guard' );
		} );
		$publication = $exporter->publish( $proof, $failures );
		$expected = array( 'source' => $binding['source_sha'], 'project' => $binding['project'], 'run' => $run );
		Wstm108_Export::verify_published( $directory, $publication['manifest_sha256'], $publication['custody'], $expected );
		self::require( 'failed' === $publication['proof_status'], 'safe publication cannot promote semantic failure' );
		foreach ( array( 'source', 'project', 'run' ) as $key ) {
			$foreign_expected = $expected;
			if ( 'run' === $key ) { $foreign_expected['run']['attempt'] = '2'; }
			else { $foreign_expected[ $key ] = 'foreign'; }
			try {
				Wstm108_Export::verify_published( $directory, $publication['manifest_sha256'], $publication['custody'], $foreign_expected );
				throw new LogicException( 'Foreign expected binding accepted.' );
			} catch ( RuntimeException $refusal ) { $passed[] = 'actual-export-refuses-foreign-' . $key; }
		}
		foreach ( array( 'directory', 'file' ) as $target ) {
			$forged = $publication['custody'];
			if ( 'directory' === $target ) { ++$forged['directory']['ino']; }
			else { ++$forged['files']['untrusted-proof.json']['identity']['ino']; }
			try {
				Wstm108_Export::verify_published( $directory, $publication['manifest_sha256'], $forged, $expected );
				throw new LogicException( 'Forged original custody accepted.' );
			} catch ( RuntimeException $refusal ) { $passed[] = 'actual-export-refuses-forged-' . $target; }
		}
		$alias = $root . '/export-alias';
		self::require( symlink( $directory, $alias ), 'actual export symlink control' );
		try {
			Wstm108_Export::verify_published( $alias, $publication['manifest_sha256'], $publication['custody'], $expected );
			throw new LogicException( 'Symlink export root accepted.' );
		} catch ( RuntimeException $refusal ) { $passed[] = 'actual-export-refuses-symlink'; }
		$path = $directory . '/untrusted-proof.json';
		$original = Wstm108_Files::file( $path );
		self::require( rename( $path, $root . '/retained-original-proof.json' ), 'retain original before replacement control' );
		$replacement = Wstm108_Files::create( $path, $original['bytes'] );
		self::require( chmod( $path, 0400 ), 'same-mode replacement control' );
		try {
			Wstm108_Export::verify_published( $directory, $publication['manifest_sha256'], $publication['custody'], $expected );
			throw new LogicException( 'Same-byte replacement export adopted.' );
		} catch ( RuntimeException $refusal ) {
			self::require( $original['bytes'] === file_get_contents( $path ) && $original['bytes'] === file_get_contents( $root . '/retained-original-proof.json' ),
				'replaced and original export bytes both retained' );
			$passed[] = 'actual-export-refuses-new-inode';
		}
		echo json_encode( array( 'passed' => $passed, 'controller_custody' => $custody, 'interpreter_refusals' => $selectors, 'root' => $root,
			'claim' => 'actual filesystem/process controls plus explicitly synthetic sync fault; no daemon, Docker or WordPress proof' ), JSON_THROW_ON_ERROR ) . "\n";
	}
}

if ( realpath( $_SERVER['SCRIPT_FILENAME'] ?? '' ) === __FILE__ ) {
	try {
		switch ( $argv[1] ?? '' ) {
			case 'install-mock': Wstm108_HostBoundaryFixture::install(); break;
			case 'capture': Wstm108_HostBoundaryFixture::capture( $argv[2], false ); break;
			case 'capture-failure': Wstm108_HostBoundaryFixture::capture( $argv[2], true ); break;
			case 'first-exits': Wstm108_HostBoundaryFixture::first_exits(); break;
			case 'native-suite': Wstm108_HostBoundaryFixture::native_suite( $argv[2] ); break;
			case 'controller-custody': Wstm108_HostBoundaryFixture::controller_custody( $argv[2], $argv[3] ); break;
			default: throw new RuntimeException( 'Explicit bounded fixture command required.' );
		}
	} catch ( Throwable $error ) {
		fwrite( STDERR, $error->getMessage() . "\n" );
		exit( $error->getCode() > 0 && $error->getCode() < 256 ? $error->getCode() : 1 );
	}
}
