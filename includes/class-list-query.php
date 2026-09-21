<?php

defined( 'ABSPATH' ) || exit;

final class Webmastery_MCP_List_Query {

	public static function window( array $args, int $page, int $per_page ): array {
		$page     = max( 1, $page );
		$per_page = min( 100, max( 1, $per_page ) );
		$order    = $args['order'] ?? 'DESC';
		$orderby  = $args['orderby'] ?? 'date';
		$orderby  = 'id' === $orderby ? 'ID' : $orderby;

		$args['orderby']             = is_array( $orderby ) ? $orderby : [ $orderby => $order ];
		$args['orderby']['ID']       = $order;
		$args['fields']              = 'ids';
		$args['posts_per_page']      = $per_page + 1;
		$args['offset']              = ( $page - 1 ) * $per_page;
		$args['paged']               = 1;
		$args['no_found_rows']       = true;
		$args['ignore_sticky_posts'] = true;

		$query = new WP_Query( $args );
		$ids   = array_values( array_unique( array_map( 'intval', $query->posts ) ) );
		$more  = count( $ids ) > $per_page;
		$ids   = array_slice( $ids, 0, $per_page );

		// Prime only this window, never the lookahead or the rest of the library.
		_prime_post_caches( $ids, true, false );

		return [
			'ids'       => $ids,
			'page'      => $page,
			'per_page'  => $per_page,
			'next_page' => $more ? $page + 1 : null,
		];
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
			if ( isset( $item['untrusted_fields'] ) ) {
				$item['untrusted_fields'] = array_values( array_diff( $item['untrusted_fields'], [ 'content' ] ) );
			}
		}
		return $item;
	}
}
