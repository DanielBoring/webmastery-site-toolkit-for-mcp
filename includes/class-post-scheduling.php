<?php

defined( 'ABSPATH' ) || exit;

class Webmastery_MCP_Post_Scheduling {
	/**
	 * Validate a requested schedule and build the post date arguments for it.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @param WP_Post|null         $post  Existing post when updating, or null when creating.
	 * @param int|null             $now   Current Unix timestamp, defaulting to time().
	 * @return array{}|array{post_date: string, post_date_gmt: string, edit_date?: true}|WP_Error
	 */
	public static function prepare( $input, $post = null, $now = null ) {
		$now    = $now ?? time();
		$status = isset( $input['status'] ) && in_array( $input['status'], [ 'draft', 'publish', 'pending', 'private', 'future' ], true )
			? $input['status']
			: ( $post->post_status ?? 'draft' );
		$future = 'future' === $status;
		$args   = [];

		if ( array_key_exists( 'scheduled_date', $input ) ) {
			if ( ! is_string( $input['scheduled_date'] ) ) {
				return self::invalid_date();
			}
			$date = sanitize_text_field( $input['scheduled_date'] );
			if ( '' === $date ) {
				return $future ? self::missing_date() : [];
			}

			$parsed = date_parse( $date );
			if ( $parsed['error_count'] || array_intersect( [ 'The parsed date was invalid', 'The parsed time was invalid' ], $parsed['warnings'] ) ) {
				return self::invalid_date();
			}

			// Keep strtotime's legacy default timezone and relative-date grammar.
			$timestamp = strtotime( $date, $now );
			if ( false === $timestamp ) {
				return self::invalid_date();
			}

			$args['post_date']     = wp_date( 'Y-m-d H:i:s', $timestamp );
			$args['post_date_gmt'] = gmdate( 'Y-m-d H:i:s', $timestamp );
			if ( ! self::valid_stored_date( $args['post_date'] ) || ! self::valid_stored_date( $args['post_date_gmt'] ) ) {
				return self::invalid_date();
			}
			if ( $future && null !== $post ) {
				// Core otherwise clears supplied dates on drafts with zero GMT.
				$args['edit_date'] = true;
			}
		} elseif ( $future ) {
			if ( null === $post || 'future' !== $post->post_status ) {
				return self::missing_date();
			}
			if ( ! self::valid_stored_date( $post->post_date ) || ! self::valid_stored_date( $post->post_date_gmt ) ) {
				return self::invalid_date();
			}
			// Stored GMT remains authoritative even after a site timezone change.
			$timestamp = strtotime( $post->post_date_gmt . ' GMT' );
			if ( false === $timestamp ) {
				return self::invalid_date();
			}
		} else {
			return [];
		}

		// Core publishes future posts at <60 seconds, not merely at <=0.
		if ( $future && $timestamp - $now < MINUTE_IN_SECONDS ) {
			return Webmastery_MCP_Response::local_error( 'scheduled_date_too_soon', 'The scheduled date must be at least 60 seconds in the future when validated.' );
		}

		return $args;
	}

	/**
	 * Check that a date is a real Y-m-d H:i:s calendar date and time.
	 *
	 * @param string|false $date Formatted or stored date.
	 * @return bool
	 */
	private static function valid_stored_date( $date ) {
		if ( ! is_string( $date ) || ! preg_match( '/^([0-9]{4})-([0-9]{2})-([0-9]{2}) ([0-9]{2}):([0-9]{2}):([0-9]{2})$/D', $date, $parts ) ) {
			return false;
		}

		return (int) $parts[1] >= 1000
			&& checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] )
			&& (int) $parts[4] < 24
			&& (int) $parts[5] < 60
			&& (int) $parts[6] < 60;
	}

	/**
	 * Error for an unparseable or impossible scheduled date.
	 *
	 * @return WP_Error
	 */
	private static function invalid_date() {
		return Webmastery_MCP_Response::local_error( 'invalid_scheduled_date', 'Provide a valid scheduled date without invalid calendar or time values.' );
	}

	/**
	 * Error for a future status without a usable scheduled date.
	 *
	 * @return WP_Error
	 */
	private static function missing_date() {
		return Webmastery_MCP_Response::local_error( 'missing_scheduled_date', 'Provide a nonempty scheduled date when newly scheduling content; omit it only to retain an existing valid future schedule.' );
	}
}
