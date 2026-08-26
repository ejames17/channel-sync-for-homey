<?php
/**
 * Homey Theme Core Pluggable Hotpatches.
 *
 * Automatically overrides the parent theme's pluggable pricing functions
 * to correct the off-by-one date-shifting bug.
 *
 * @package HomeyChannelSync
 */

declare(strict_types=1);

// phpcs:disable

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'homey_get_prices' ) ) {
	function homey_get_prices( $check_in_date, $check_out_date, $listing_id, $guests, $extra_options = null ) {
		$prefix = 'homey_';

		$enable_services_fee        = homey_option( 'enable_services_fee' );
		$enable_taxes               = homey_option( 'enable_taxes' );
		$offsite_payment            = homey_option( 'off-site-payment' );
		$reservation_payment_type   = homey_option( 'reservation_payment' );
		$booking_percent            = homey_option( 'booking_percent' );
		$tax_type                   = homey_option( 'tax_type' );
		$apply_taxes_on_service_fee = homey_option( 'apply_taxes_on_service_fee' );
		$taxes_percent_global       = homey_option( 'taxes_percent' );
		$single_listing_tax         = get_post_meta( $listing_id, 'homey_tax_rate', true );

		$period_price = get_post_meta( $listing_id, 'homey_custom_period', true );
		/*
		echo '<pre> its period prices > ';
		print_r($period_price);*/

		if ( empty( $period_price ) ) {
			$period_price = array();
		}

		$total_extra_services          = 0;
		$extra_prices_html             = '';
		$taxes_final                   = 0;
		$taxes_percent                 = 0;
		$total_price                   = 0;
		$total_guests_price            = 0;
		$upfront_payment               = 0;
		$nights_total_price            = 0;
		$booking_has_weekend           = 0;
		$booking_has_custom_pricing    = 0;
		$balance                       = 0;
		$taxable_amount                = 0;
		$period_days                   = 0;
		$security_deposit              = '';
		$additional_guests             = '';
		$additional_guests_total_price = '';
		$services_fee_final            = '';
		$taxes_fee_final               = '';
		$prices_array                  = array();

		$listing_guests   = floatval( get_post_meta( $listing_id, $prefix . 'guests', true ) );
		$nightly_price    = floatval( get_post_meta( $listing_id, $prefix . 'night_price', true ) );
		$price_per_night  = $nightly_price;
		$weekends_price   = floatval( get_post_meta( $listing_id, $prefix . 'weekends_price', true ) );
		$weekends_days    = get_post_meta( $listing_id, $prefix . 'weekends_days', true );
		$priceWeek        = floatval( get_post_meta( $listing_id, $prefix . 'priceWeek', true ) ); // 7 Nights
		$priceMonthly     = floatval( get_post_meta( $listing_id, $prefix . 'priceMonthly', true ) );  // 30 Nights
		$security_deposit = floatval( get_post_meta( $listing_id, $prefix . 'security_deposit', true ) );

		$cleaning_fee      = floatval( get_post_meta( $listing_id, $prefix . 'cleaning_fee', true ) );
		$cleaning_fee_type = get_post_meta( $listing_id, $prefix . 'cleaning_fee_type', true );

		$city_fee      = floatval( get_post_meta( $listing_id, $prefix . 'city_fee', true ) );
		$city_fee_type = get_post_meta( $listing_id, $prefix . 'city_fee_type', true );

		$extra_guests_price      = floatval( get_post_meta( $listing_id, $prefix . 'additional_guests_price', true ) );
		$additional_guests_price = $extra_guests_price;

		$allow_additional_guests = get_post_meta( $listing_id, $prefix . 'allow_additional_guests', true );

		$check_in                = new DateTime( $check_in_date );
		$check_in_unix           = $check_in->getTimestamp();
		$check_in_unix_first_day = $check_in->getTimestamp();
		$check_out               = new DateTime( $check_out_date );
		$check_out_unix          = $check_out->getTimestamp();

		$time_difference = abs( strtotime( $check_in_date ) - strtotime( $check_out_date ) );
		$days_count      = $time_difference / 86400;
		$days_count      = intval( $days_count );
		$breakdown_price = '';
		// print_r($check_in_unix);

		if ( isset( $period_price[ $check_in_unix ] ) && isset( $period_price[ $check_in_unix ]['night_price'] ) && $period_price[ $check_in_unix ]['night_price'] != 0 ) {
			$price_per_night = $period_price[ $check_in_unix ]['night_price'];

			$booking_has_custom_pricing = 1;
			$period_days                = $period_days + 1;
		}

		if ( $days_count > 7 && $priceWeek != 0 ) {
			$price_per_night = $priceWeek;
		}

		if ( $days_count > 30 && $priceMonthly != 0 ) {
			$price_per_night = $priceMonthly;
		}

		// Check additional guests price
		if ( $allow_additional_guests == 'yes' && $guests > 0 && ! empty( $guests ) ) {
			if ( $guests > $listing_guests ) {
				$additional_guests = $guests - $listing_guests;

				$guests_price_return = homey_calculate_guests_price( $period_price, $check_in_unix, $additional_guests, $additional_guests_price );
				$breakdown_price    .= ', total_guests_price prev price=' . $total_guests_price . ' + weekend or reg price=' . $guests_price_return . '<br>';

				$total_guests_price = $total_guests_price + $guests_price_return;
			}
		}
		// echo $price_per_night.' only price ';

		// Check for weekend and add weekend price
		$breakdown_price .= ' * This first date * ' . date( 'd-m-Y', $check_in_unix_first_day ) . '<br>';

		$weekday = date( 'N', $check_in_unix_first_day );
		if ( homey_check_weekend( $weekday, $weekends_days, $weekends_price ) ) {
			$booking_has_weekend = 1;
		}

		if ( $booking_has_weekend != 1 && isset( $period_price[ $check_in_unix_first_day ] ) && isset( $period_price[ $check_in_unix_first_day ]['night_price'] ) && $period_price[ $check_in_unix_first_day ]['night_price'] != 0 ) {
			// echo ' iffff ';
			$returnPrice = $period_price[ $check_in_unix_first_day ]['night_price'];
		} else {
			// echo ' elseeee ';

			$returnPrice = homey_cal_weekend_price( $check_in_unix_first_day, $weekends_price, $price_per_night, $weekends_days, $period_price );
		}

		$check_in->modify( 'tomorrow' );
		$check_in_unix = $check_in->getTimestamp();
		// echo  ' first night price= '. $returnPrice.'<br>';
		$nights_total_price = $nights_total_price + $returnPrice;
		$total_price        = $total_price + $returnPrice;
		$current_index      = 0;
		while ( $check_in_unix < $check_out_unix ) {
			// echo ' * This date * '.date('d-m-Y',$check_in_unix).'<br>';
			$current_index++;
			$weekday = date( 'N', $check_in_unix );
			if ( homey_check_weekend( $weekday, $weekends_days, $weekends_price ) ) {
				$booking_has_weekend = 1;
			}

			if ( isset( $period_price[ $check_in_unix ] ) && isset( $period_price[ $check_in_unix ]['night_price'] ) && $period_price[ $check_in_unix ]['night_price'] != 0 ) {

				$price_per_night = $period_price[ $check_in_unix ]['night_price'];
				// echo 'cond> <pre>  if( isset('.$period_price[$check_in_unix].') && isset('. $period_price[$check_in_unix]['night_price'] .') && '. $period_price[$check_in_unix]['night_price'] .'!=0 ){';
				// print_r($period_price[$check_in_unix]);
				$breakdown_price .= date( 'd-m-Y', $check_in_unix ) . ' its custom pr ' . $price_per_night . ' custom price <br>';

				$booking_has_custom_pricing = 1;
				$period_days                = $period_days + 1;
			} else {
				if ( $days_count > 7 && $priceWeek != 0 ) {
					// do the logic
				} elseif ( $days_count > 30 && $priceMonthly != 0 ) {
					// do the logic
				} else {
					$price_per_night = $nightly_price; // this creates issue for 7+ and 30+ nights issue
				}
			}

			// To make this per night per additional guest, we added a condition > 1 night, because once it is added
			if ( $current_index > 1 && $allow_additional_guests == 'yes' && $guests > 0 && ! empty( $guests ) ) {
				if ( $guests > $listing_guests ) {
					$additional_guests = $guests - $listing_guests;

					$guests_price_return = homey_calculate_guests_price( $period_price, $check_in_unix, $additional_guests, $additional_guests_price );

					$breakdown_price .= ', prev price=' . $total_guests_price . ' + guest price=' . $guests_price_return . '<br>';

					$total_guests_price = $total_guests_price + $guests_price_return;
				}
			} // end To make this per night per additional guest, we added a condition > 1 night, because once it is added

			$returnPrice = homey_cal_weekend_price( $check_in_unix, $weekends_price, $price_per_night, $weekends_days, $period_price );

			// echo ' the day => price='. $returnPrice.'<br>';

			$nights_total_price = $nights_total_price + $returnPrice;
			$total_price        = $total_price + $returnPrice;
			$breakdown_price   .= date( 'd-m-Y', $check_in_unix ) . ' < date ' . $total_price . ' < total price <br>';

			$check_in->modify( 'tomorrow' );
			$check_in_unix = $check_in->getTimestamp();

		}

		if ( $cleaning_fee_type == 'daily' ) {
			$cleaning_fee = $cleaning_fee * $days_count;
			$total_price  = $total_price + $cleaning_fee;
		} else {
			$total_price = $total_price + $cleaning_fee;
		}

		// Extra prices =======================================
		if ( $extra_options != '' ) {

			$extra_prices_output = '';
			$is_first            = 0;
			foreach ( $extra_options as $extra_price ) {
				if ( $is_first == 0 ) {
					$extra_prices_output .= '<li class="sub-total">' . esc_html__( 'Extra Services', 'homey' ) . '</li>';
				} $is_first = 2;

				$ex_single_price = explode( '|', $extra_price );

				$ex_name  = $ex_single_price[0];
				$ex_price = floatval( $ex_single_price[1] );
				$ex_type  = $ex_single_price[2];

				if ( $ex_type == 'single_fee' ) {
					$ex_price = $ex_price;

				} elseif ( $ex_type == 'per_night' ) {
					$ex_price = $ex_price * $days_count;
				} elseif ( $ex_type == 'per_guest' ) {
					$ex_price = $ex_price * $guests;
				} elseif ( $ex_type == 'per_night_per_guest' ) {
					$ex_price = $ex_price * $days_count * $guests;
				}

				$total_extra_services = $total_extra_services + $ex_price;

				$extra_prices_output .= '<li>' . esc_attr( $ex_name ) . '<span>' . homey_formatted_price( $ex_price ) . '</span></li>';
			}

			$total_price       = $total_price + $total_extra_services;
			$extra_prices_html = $extra_prices_output;
		}

		// Calculate taxes based of original price (Excluding city, security deposit etc)
		if ( $enable_taxes == 1 ) {

			if ( $tax_type == 'global_tax' ) {
				$taxes_percent = $taxes_percent_global;
			} else {
				if ( ! empty( $single_listing_tax ) ) {
					$taxes_percent = $single_listing_tax;
				}
			}

			$taxable_amount = $total_price + $total_guests_price;
			$taxes_final    = homey_calculate_taxes( $taxes_percent, $taxable_amount );
			$total_price    = $total_price + $taxes_final;
		}

		// Calculate sevices fee based of original price (Excluding cleaning, city, sevices fee etc)
		if ( $enable_services_fee == 1 && $offsite_payment != 1 ) {
			$services_fee_type      = homey_option( 'services_fee_type' );
			$services_fee           = homey_option( 'services_fee' );
			$price_for_services_fee = $total_price + $total_guests_price;
			$services_fee_final     = homey_calculate_services_fee( $services_fee_type, $services_fee, $price_for_services_fee );
			$total_price            = $total_price + $services_fee_final;
		}

		if ( $city_fee_type == 'daily' ) {
			$city_fee    = $city_fee * $days_count;
			$total_price = $total_price + $city_fee;
		} else {
			$total_price = $total_price + $city_fee;
		}

		if ( ! empty( $security_deposit ) && $security_deposit != 0 ) {
			$total_price = $total_price + $security_deposit;
		}

		if ( $total_guests_price != 0 ) {
			$total_price = $total_price + $total_guests_price;
		}

		$listing_host_id               = get_post_field( 'post_author', $listing_id );
		$host_reservation_payment_type = get_user_meta( $listing_host_id, 'host_reservation_payment', true );
		$host_booking_percent          = get_user_meta( $listing_host_id, 'host_booking_percent', true );

		if ( $offsite_payment == 1 && ! empty( $host_reservation_payment_type ) ) {

			if ( $host_reservation_payment_type == 'percent' ) {
				if ( ! empty( $host_booking_percent ) && $host_booking_percent != 0 ) {
					$upfront_payment = round( $host_booking_percent * $total_price / 100, 2 );
				}
			} elseif ( $host_reservation_payment_type == 'full' ) {
				$upfront_payment = $total_price;

			} elseif ( $host_reservation_payment_type == 'only_security' ) {
				$upfront_payment = $security_deposit;

			} elseif ( $host_reservation_payment_type == 'only_services' ) {
				$upfront_payment = $services_fee_final;

			} elseif ( $host_reservation_payment_type == 'services_security' ) {
				$upfront_payment = $security_deposit + $services_fee_final;
			}
		} else {

			if ( $reservation_payment_type == 'percent' ) {
				if ( ! empty( $booking_percent ) && $booking_percent != 0 ) {
					$upfront_payment = round( $booking_percent * $total_price / 100, 2 );
				}
			} elseif ( $reservation_payment_type == 'full' ) {
				$upfront_payment = $total_price;

			} elseif ( $reservation_payment_type == 'only_security' ) {
				$upfront_payment = $security_deposit;

			} elseif ( $reservation_payment_type == 'only_services' ) {
				$upfront_payment = $services_fee_final;

			} elseif ( $reservation_payment_type == 'services_security' ) {
				$upfront_payment = (int) $security_deposit + (int) $services_fee_final;
			}
		}

		$balance = $total_price - $upfront_payment;

		$prices_array['breakdown_price']               = $breakdown_price;
		$prices_array['price_per_night']               = $price_per_night;
		$prices_array['nights_total_price']            = $nights_total_price;
		$prices_array['total_price']                   = $total_price;
		$prices_array['check_in_date']                 = $check_in_date;
		$prices_array['check_out_date']                = $check_out_date;
		$prices_array['cleaning_fee']                  = $cleaning_fee;
		$prices_array['city_fee']                      = $city_fee;
		$prices_array['services_fee']                  = $services_fee_final;
		$prices_array['days_count']                    = $days_count;
		$prices_array['period_days']                   = $period_days;
		$prices_array['taxes']                         = $taxes_final;
		$prices_array['taxes_percent']                 = $taxes_percent;
		$prices_array['security_deposit']              = $security_deposit;
		$prices_array['additional_guests']             = $additional_guests;
		$prices_array['additional_guests_price']       = $additional_guests_price;
		$prices_array['additional_guests_total_price'] = $total_guests_price;
		$prices_array['booking_has_weekend']           = $booking_has_weekend;
		$prices_array['booking_has_custom_pricing']    = $booking_has_custom_pricing;
		$prices_array['extra_prices_html']             = $extra_prices_html;
		$prices_array['balance']                       = $balance;
		$prices_array['upfront_payment']               = $upfront_payment;

		return $prices_array;

	}
}
