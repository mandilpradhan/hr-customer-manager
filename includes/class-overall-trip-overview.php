<?php
/**
 * Data provider for the Overall Trip Overview admin screen.
 *
 * @package HR_Customer_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'HR_CM_Overall_Trip_Overview' ) ) {
	/**
	 * Prepares summary data for trip departures and bookings.
	 */
	class HR_CM_Overall_Trip_Overview {

		/**
		 * Return the Overall tab table payload for AJAX.
		 *
		 * @return array{columns:array<int,string>,rows:array<int,array<int,mixed>>}
		 */
		public static function get_overall_table_data() {
			$columns = array(
				__('Trip Code','hr-customer-manager'),
				__('Trip','hr-customer-manager'),
				__('Country','hr-customer-manager'),
				__('Departures','hr-customer-manager'),
				__('Next Departure','hr-customer-manager'),
				__('Days to Next','hr-customer-manager'),
				__('Total Pax','hr-customer-manager'),
				__('Pax on Next Departure','hr-customer-manager'),
			);

			// Build indexes we need.
			$tz              = wp_timezone();
			$today_dt        = new DateTimeImmutable( current_time('Y-m-d'), $tz );
			$depart_idx      = self::build_departure_index();
			$dates_by_trip   = $depart_idx['dates_by_trip'];
			$next_by_trip    = $depart_idx['next_by_trip'];
			$trip_index      = self::get_trip_index();
			$booking_ids     = self::get_booking_ids();
			$pax_aggregates  = self::aggregate_booking_pax( $booking_ids, $today_dt );

			$rows = array();

			foreach ( $trip_index as $trip_id => $trip ) {
				$dates            = isset( $dates_by_trip[ $trip_id ] ) ? $dates_by_trip[ $trip_id ] : array();
				$departures_count = count( $dates );

				$next_iso  = isset( $next_by_trip[ $trip_id ] ) ? $next_by_trip[ $trip_id ] : null;
				$next_disp = $next_iso ? date_i18n( 'F j, Y', ( new DateTimeImmutable( $next_iso, $tz ) )->getTimestamp() ) : '—';
				$days_to   = '—';
				if ( $next_iso ) {
					$next_dt = new DateTimeImmutable( $next_iso, $tz );
					$days_to = (string) $today_dt->diff( $next_dt )->days;
				}

				// Pax totals.
				$pax_tot   = isset( $pax_aggregates['by_trip'][ $trip_id ] ) ? (int) $pax_aggregates['by_trip'][ $trip_id ] : 0;
				$pax_dates = isset( $pax_aggregates['by_trip_date'][ $trip_id ] ) && is_array( $pax_aggregates['by_trip_date'][ $trip_id ] )
					? $pax_aggregates['by_trip_date'][ $trip_id ]
					: array();
				$pax_next  = ( $next_iso && isset( $pax_dates[ $next_iso ] ) ) ? (int) $pax_dates[ $next_iso ] : 0;

				$rows[] = array(
					(int) $trip_id,
					isset($trip['title']) ? $trip['title'] : '',
					isset($trip['country']) ? $trip['country'] : '',
					$departures_count,
					$next_disp,
					$days_to,
					$pax_tot,
					$pax_next,
				);
			}

			return array(
				'columns' => $columns,
				'rows'    => $rows,
			);
		}

		/**
		 * Build index of future departures by trip, using the global option
		 * wptravelengine_indexed_trips_by_dates (month-keyed: YYYY-MM => [ {trip_id,date,...}, ... ]).
		 * Availability (seats/capacity) is ignored by design.
		 *
		 * @return array{dates_by_trip:array<int,array<int,string>>, next_by_trip:array<int,string>}
		 */
		private static function build_departure_index() {
			$raw  = get_option( 'wptravelengine_indexed_trips_by_dates' );
			$data = self::parse_mixed_value( $raw );

			if ( ! is_array( $data ) ) {
				return array( 'dates_by_trip' => array(), 'next_by_trip' => array() );
			}

			$tz     = wp_timezone();
			$today  = new DateTimeImmutable( current_time( 'Y-m-d' ), $tz );
			$byTrip = array();

			// The option is keyed by YYYY-MM. Iterate each month bucket.
			foreach ( $data as $ym => $entries ) {
				if ( ! is_array( $entries ) ) {
					continue;
				}
				foreach ( $entries as $row ) {
					if ( ! is_array( $row ) ) {
						continue;
					}
					$tid  = isset( $row['trip_id'] ) ? (int) $row['trip_id'] : 0;
					$iso  = isset( $row['date'] ) ? (string) $row['date'] : '';
					if ( $tid <= 0 || $iso === '' ) {
						continue;
					}

					try {
						$d = new DateTimeImmutable( $iso, $tz );
					} catch ( \Throwable $e ) {
						continue;
					}
					if ( $d < $today ) {
						continue; // future only
					}

					$byTrip[ $tid ][] = $d->format( 'Y-m-d' );
				}
			}

			// Sort and pick "next" for each trip.
			$next = array();
			foreach ( $byTrip as $tid => $dates ) {
				$dates = array_values( array_unique( $dates ) );
				sort( $dates ); // ISO dates sort lexicographically
				$byTrip[ $tid ] = $dates;
				if ( ! empty( $dates ) ) {
					$next[ $tid ] = $dates[0];
				}
			}

			return array(
				'dates_by_trip' => $byTrip,
				'next_by_trip'  => $next,
			);
		}

		/**
		 * Get an index of all published trips.
		 *
		 * @return array<int,array{title:string,country:string}>
		 */
		private static function get_trip_index() {
			$index = array();

			$q = new WP_Query( array(
				'post_type'      => 'trip',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			) );

			if ( empty( $q->posts ) ) {
				return $index;
			}

			foreach ( $q->posts as $trip_id ) {
				$title   = get_the_title( $trip_id );
				$country = self::get_trip_country( $trip_id );
				$index[ (int) $trip_id ] = array(
					'title'   => is_string($title) ? $title : '',
					'country' => $country,
				);
			}

			return $index;
		}

		/**
		 * Best-effort country label for a trip.
		 */
		private static function get_trip_country( $trip_id ) {
			$tax_candidates = array( 'country', 'destination' );
			foreach ( $tax_candidates as $tax ) {
				if ( taxonomy_exists( $tax ) ) {
					$terms = get_the_terms( $trip_id, $tax );
					if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
						$term = array_shift( $terms );
						return is_object($term) ? $term->name : '';
					}
				}
			}
			// Fallback: try configured taxonomy name in options (if available).
			$opt_tax = get_option( 'wptravelengine_triptag_tax' );
			if ( is_string( $opt_tax ) && taxonomy_exists( $opt_tax ) ) {
				$terms = get_the_terms( $trip_id, $opt_tax );
				if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
					$term = array_shift( $terms );
					return is_object($term) ? $term->name : '';
				}
			}
			return '';
		}

		/**
		 * Return booking post IDs (for aggregation).
		 *
		 * @return int[]
		 */
		private static function get_booking_ids() {
			$q = new WP_Query( array(
				'post_type'      => 'booking',
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
			) );
			return $q->posts ? array_map( 'intval', $q->posts ) : array();
		}

		/**
		 * Aggregate pax per trip and per {trip,date} from booking metas (future only).
		 *
		 * @param int[]                $booking_ids
		 * @param DateTimeImmutable    $today_dt
		 * @return array{by_trip:array<int,int>, by_trip_date:array<int,array<string,int>>}
		 */
		private static function aggregate_booking_pax( $booking_ids, $today_dt ) {
			$by_trip      = array();
			$by_trip_date = array();

			foreach ( $booking_ids as $bid ) {
				$trip_id  = self::extract_trip_id( $bid );
				$trip_date= self::extract_trip_date( $bid );
				if ( ! $trip_id || ! $trip_date ) {
					continue;
				}

				try {
					$dt = new DateTimeImmutable( $trip_date, wp_timezone() );
				} catch ( \Throwable $e ) {
					continue;
				}
				if ( $dt < $today_dt ) {
					continue; // future only
				}

				$pax_map = self::extract_booking_pax( $bid );
				$pax_sum = self::sum_pax( $pax_map );

				if ( $pax_sum > 0 ) {
					$by_trip[ $trip_id ] = isset( $by_trip[ $trip_id ] ) ? $by_trip[ $trip_id ] + $pax_sum : $pax_sum;
					if ( ! isset( $by_trip_date[ $trip_id ] ) ) {
						$by_trip_date[ $trip_id ] = array();
					}
					$iso = $dt->format( 'Y-m-d' );
					$by_trip_date[ $trip_id ][ $iso ] = isset( $by_trip_date[ $trip_id ][ $iso ] )
						? $by_trip_date[ $trip_id ][ $iso ] + $pax_sum
						: $pax_sum;
				}
			}

			return array(
				'by_trip'      => $by_trip,
				'by_trip_date' => $by_trip_date,
			);
		}

		/**
		 * Extract the first order_trips item for a booking.
		 *
		 * @param int $booking_id
		 * @return array<string,mixed>|null
		 */
		private static function extract_booking_trip( $booking_id ) {
			$raw = get_post_meta( $booking_id, 'order_trips', true );
			$val = self::parse_mixed_value( $raw );
			if ( is_array( $val ) ) {
				$first = reset( $val );
				if ( is_array( $first ) ) {
					return $first;
				}
			}
			return null;
		}

		private static function extract_trip_id( $booking_id ) {
			$item = self::extract_booking_trip( $booking_id );
			if ( ! $item ) return 0;
			if ( isset( $item['ID'] ) ) return (int) $item['ID'];
			if ( isset( $item['_cart_item_object']['trip_id'] ) ) return (int) $item['_cart_item_object']['trip_id'];
			return 0;
		}

		private static function extract_trip_date( $booking_id ) {
			$item = self::extract_booking_trip( $booking_id );
			if ( ! $item ) return '';
			if ( isset( $item['datetime'] ) ) return self::normalize_date( $item['datetime'] );
			if ( isset( $item['_cart_item_object']['trip_date'] ) ) return self::normalize_date( $item['_cart_item_object']['trip_date'] );
			return '';
		}

		private static function extract_booking_pax( $booking_id ) {
			$item = self::extract_booking_trip( $booking_id );
			if ( ! $item ) return array();
			if ( isset( $item['pax'] ) && is_array( $item['pax'] ) ) return $item['pax'];
			if ( isset( $item['_cart_item_object']['pax'] ) && is_array( $item['_cart_item_object']['pax'] ) ) return $item['_cart_item_object']['pax'];
			return array();
		}

		private static function sum_pax( $pax_map ) {
			$total = 0;
			if ( is_array( $pax_map ) ) {
				foreach ( $pax_map as $v ) {
					$total += (int) $v;
				}
			}
			return $total;
		}

		/**
		 * Normalize date string to Y-m-d.
		 */
		private static function normalize_date( $date_string ) {
			try {
				$dt = new DateTimeImmutable( (string) $date_string, wp_timezone() );
				return $dt->format( 'Y-m-d' );
			} catch ( \Throwable $e ) {
				return '';
			}
		}

		/**
		 * Robustly parse a value that might be serialized PHP, JSON, or plain scalar.
		 * Returns arrays/objects as PHP arrays; scalars as-is.
		 *
		 * @param mixed $value
		 * @return mixed
		 */
		private static function parse_mixed_value( $value ) {
			// Already array.
			if ( is_array( $value ) ) {
				return $value;
			}
			// Objects -> array.
			if ( is_object( $value ) ) {
				return json_decode( wp_json_encode( $value ), true );
			}
			// Non-string scalars.
			if ( ! is_string( $value ) ) {
				return $value;
			}

			// Try unserialize first (WTE stores PHP serialized often).
			$maybe = @maybe_unserialize( $value );
			if ( $maybe !== $value ) {
				return self::parse_mixed_value( $maybe );
			}

			// Strip BOM and whitespace before attempting JSON decode.
			$clean = preg_replace( '/^\xEF\xBB\xBF/', '', $value );
			$clean = trim( (string) $clean );

			if ( $clean !== '' ) {
				$decoded = json_decode( $clean, true );
				if ( json_last_error() === JSON_ERROR_NONE && ( is_array( $decoded ) || is_object( $decoded ) ) ) {
					return (array) $decoded;
				}
			}

			// Fallback: raw string/scalar.
			return $value;
		}
	}
}
