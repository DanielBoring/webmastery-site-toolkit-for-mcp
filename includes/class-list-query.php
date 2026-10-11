<?php

defined( 'ABSPATH' ) || exit;

final class Webmastery_MCP_List_Query {

	public static function window( array $args, int $page, int $per_page ): array|WP_Error {
		global $wpdb;

		$page     = max( 1, $page );
		$per_page = min( 100, max( 1, $per_page ) );
		if ( PHP_INT_MAX === $page || $page - 1 > intdiv( PHP_INT_MAX, $per_page ) ) {
			return Webmastery_MCP_Response::local_error( 'invalid_input', 'Pagination exceeds the supported integer range.' );
		}
		$order   = $args['order'] ?? 'DESC';
		$orderby = $args['orderby'] ?? 'date';
		$orderby = 'id' === $orderby ? 'ID' : $orderby;

		$args['orderby']             = is_array( $orderby ) ? $orderby : [ $orderby => $order ];
		$args['orderby']['ID']       = $order;
		$args['fields']              = 'ids';
		$args['posts_per_page']      = $per_page + 1;
		$args['offset']              = ( $page - 1 ) * $per_page;
		$args['paged']               = 1;
		$args['no_found_rows']       = true;
		$args['ignore_sticky_posts'] = true;

		$query = self::database_read( static fn() => new WP_Query( $args ) );
		if ( is_wp_error( $query ) ) {
			return $query;
		}
		$ids  = array_values( array_unique( array_map( 'intval', $query->posts ) ) );
		$more = count( $ids ) > $per_page;
		$ids  = array_slice( $ids, 0, $per_page );

		// Prime only this window, never the lookahead or the rest of the library.
		$error = self::database_read( static fn() => _prime_post_caches( $ids, true, false ) );
		if ( is_wp_error( $error ) ) {
			return $error;
		}

		return [
			'ids'       => $ids,
			'page'      => $page,
			'per_page'  => $per_page,
			'next_page' => $more ? $page + 1 : null,
		];
	}

	private static function database_read( callable $read ) {
		global $wpdb;

		$query_count = $wpdb->num_queries;
		$failed      = false;
		$observe     = static function ( $sql ) use ( $wpdb, $query_count, &$failed ) {
			// The query filter runs before wpdb flushes the previous read's error.
			if ( $wpdb->num_queries > $query_count && '' !== $wpdb->last_error ) {
				$failed = true;
			}
			return $sql;
		};
		add_filter( 'query', $observe, PHP_INT_MIN );
		try {
			$result = $read();
			$observe( '' );
		} finally {
			remove_filter( 'query', $observe, PHP_INT_MIN );
		}
		// Cached reads can leave a stale error without executing any SQL.
		if ( $failed ) {
			// Core may already have cached a failed ID query as an empty result.
			wp_cache_set_posts_last_changed();
			return Webmastery_MCP_Response::local_error( 'upstream_failed', 'The list query could not be completed.' );
		}
		return $result;
	}

	public static function result( array $window, array $items ): array {
		return [
			'items'     => array_values( $items ),
			'page'      => $window['page'],
			'per_page'  => $window['per_page'],
			'next_page' => $window['next_page'],
		];
	}

	public static function fields_schema(): array {
		return [
			'type'        => 'string',
			'enum'        => [ 'summary', 'full' ],
			'default'     => 'summary',
			'description' => 'Summary omits content only; full returns unchanged stored content.',
		];
	}

	public static function project( array $item, string $fields ): array {
		if ( 'summary' === $fields ) {
			unset( $item['content'] );
		}
		if ( isset( $item['untrusted_fields'] ) ) {
			$item = Webmastery_MCP_Untrusted::mark( $item, $item['untrusted_fields'] );
		}
		return $item;
	}
}
