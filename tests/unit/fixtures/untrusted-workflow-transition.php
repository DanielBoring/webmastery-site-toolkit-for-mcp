<?php

declare(strict_types=1);

final class Wstm108_Workflow_Transition {
	private const SEAL = 'f30822cfc6eb489c3993ffaacdc89862d87780335ff64e222e04cdf5c3a5e64e';

	public static function restore( string $path, string $source ): string {
		$json = file_get_contents( __DIR__ . '/untrusted-workflow-transition.json' );
		if ( self::SEAL !== hash( 'sha256', $json ) ) {
			throw new RuntimeException( 'Unapproved workflow composition transition.' );
		}
		$ledger = json_decode( $json, false, 512, JSON_THROW_ON_ERROR );
		foreach ( $ledger->files as $file ) {
			if ( $file->path !== $path ) { continue; }
			if ( $file->current_sha256 !== hash( 'sha256', $source )
				|| $file->before_sha256 !== hash( 'sha256', $file->before ) ) {
				throw new RuntimeException( 'Workflow composition exact source drift.' );
			}
			return $file->before;
		}
		throw new RuntimeException( 'Unknown workflow composition source.' );
	}
}
