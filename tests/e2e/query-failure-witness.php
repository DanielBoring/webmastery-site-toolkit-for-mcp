<?php

final class Wstm121QueryFailureWitness {
	private ?array $pending = null;
	private ?array $witness = null;

	public function injected( string $replacement, int $query_count, int $sql_index ): void {
		wstm121_require( null === $this->pending && null === $this->witness, 'Conflicting injected-query witness.' );
		$this->pending = array( 'replacement' => $replacement, 'query_before' => $query_count, 'sql_index' => $sql_index );
	}

	public function observe( $database, string $boundary ): void {
		if ( null === $this->pending || $database->num_queries === $this->pending['query_before'] ) {
			return;
		}
		wstm121_require( $database->num_queries === $this->pending['query_before'] + 1
			&& $database->last_query === $this->pending['replacement'], 'Injected-query execution association changed.' );
		wstm121_require( in_array( $boundary, array( 'before-next-query', 'final' ), true ), 'Unknown query-witness boundary.' );
		$this->witness = array(
			'sql_index' => $this->pending['sql_index'],
			'query_before' => $this->pending['query_before'],
			'query_completed' => $database->num_queries,
			'query_sha256' => hash( 'sha256', $database->last_query ),
			'result_failed' => false === $database->result,
			'error_present' => is_string( $database->last_error ) && '' !== $database->last_error,
			'boundary' => $boundary,
		);
		$this->pending = null;
	}

	public function retained(): ?array {
		return $this->witness;
	}
}
