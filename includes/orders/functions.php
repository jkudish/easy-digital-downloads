<?php
/**
 * Order Functions
 *
 * @package     EDD
 * @subpackage  Orders
 * @copyright   Copyright (c) 2018, Easy Digital Downloads, LLC
 * @license     http://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       3.0.0
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

/**
 * Add an order.
 *
 * @since 3.0
 *
 * @param array $data
 * @return int|false ID of newly created order, false on error.
 */
function edd_add_order( $data = array() ) {
	$orders = new EDD\Database\Queries\Order();

	return $orders->add_item( $data );
}

/**
 * Delete an order.
 *
 * @since 3.0
 *
 * @param int $order_id Order ID.
 * @return int
 */
function edd_delete_order( $order_id = 0 ) {
	$orders = new EDD\Database\Queries\Order();

	return $orders->delete_item( $order_id );
}

/**
 * Destroy an order.
 *
 * Completely deletes an order, and the items and adjustments with it.
 *
 * @todo switch to _destroy_ for items & adjustments
 *
 * @since 3.0
 *
 * @param int $order_id Order ID.
 * @return int
 */
function edd_destroy_order( $order_id = 0 ) {

	// Get items.
	$items = edd_get_order_items( array(
		'order_id'      => $order_id,
		'no_found_rows' => true
	) );

	// Destroy items (and their adjustments).
	if ( ! empty( $items ) ) {
		foreach ( $items as $item ) {
			edd_delete_order_item( $item->id );
		}
	}

	// Get adjustments.
	$adjustments = edd_get_order_adjustments( array(
		'object_id'     => $order_id,
		'object_type'   => 'order',
		'no_found_rows' => true
	) );

	// Destroy adjustments.
	if ( ! empty( $adjustments ) ) {
		foreach ( $adjustments as $adjustment ) {
			edd_delete_order_adjustment( $adjustment->id );
		}
	}

	// Get address.
	$address = edd_get_order_address_by( 'order_id', $order_id );

	// Destroy address.
	if ( $address ) {
		edd_delete_order_address( $address->id );
	}

	// Delete the order
	return edd_delete_order( $order_id );
}

/**
 * Update an order.
 *
 * @since 3.0
 *
 * @param int   $order_id Order ID.
 * @param array $data   Updated order data.
 * @return bool Whether or not the order was updated.
 */
function edd_update_order( $order_id = 0, $data = array() ) {
	$orders = new EDD\Database\Queries\Order();

	return $orders->update_item( $order_id, $data );
}

/**
 * Get an order by ID.
 *
 * @since 3.0
 *
 * @param int $order_id Order ID.
 * @return EDD\Orders\Order Order object.
 */
function edd_get_order( $order_id = 0 ) {
	return edd_get_order_by( 'id', $order_id );
}

/**
 * Get an order by a specific field value.
 *
 * @since 3.0
 *
 * @param string $field Database table field.
 * @param string $value Value of the row.
 *
 * @return \EDD\Orders\Order Order object.
 */
function edd_get_order_by( $field = '', $value = '' ) {

	// Instantiate a query object
	$orders = new EDD\Database\Queries\Order();

	// Get an item
	return $orders->get_item_by( $field, $value );
}

/**
 * Query for orders.
 *
 * @since 3.0
 *
 * @param array $args
 * @return EDD\Orders\Order[]
 */
function edd_get_orders( $args = array() ) {

	// Parse args
	$r = wp_parse_args( $args, array(
		'number' => 30
	) );

	// Instantiate a query object
	$orders = new EDD\Database\Queries\Order();

	// Return items
	return $orders->query( $r );
}

/**
 * Count orders.
 *
 * @since 3.0
 *
 * @param array $args
 * @return int
 */
function edd_count_orders( $args = array() ) {

	// Parse args
	$r = wp_parse_args( $args, array(
		'count' => true
	) );

	// Query for count(s)
	$orders = new EDD\Database\Queries\Order( $r );

	// Return count(s)
	return absint( $orders->found_items );
}

/**
 * Query for and return array of order counts, keyed by status.
 *
 * @since 3.0
 *
 * @return array
 */
function edd_get_order_counts() {

	// Default statuses
	$defaults = array_fill_keys( array_keys( edd_get_payment_statuses() ), 0 );

	// Query for count
	$counts = edd_get_orders( array(
		'count'   => true,
		'groupby' => 'status'
	) );

	// Default array
	$o = array(
		'total' => 0
	);

	// Loop through counts and shape return value
	if ( ! empty( $counts ) ) {

		// Loop through statuses
		foreach ( $counts as $item ) {
			$o[ $item['status'] ] = absint( $item['count'] );
		}

		// Total
		$o['total'] = array_sum( $o );
	}

	// Return counts
	return array_merge( $defaults, $o );
}

/**
 * Check if an order can be recovered.
 *
 * @since 3.0
 *
 * @param int $order_id Order ID.
 * @return bool Whether or not the order can be recovered.
 */
function edd_is_order_recoverable( $order_id = 0 ) {
	$order = edd_get_order( $order_id );

	if ( ! $order ) {
		return false;
	}

	$recoverable_statuses = apply_filters( 'edd_recoverable_payment_statuses', array( 'pending', 'abandoned', 'failed' ) );

	$transaction_id = $order->get_transaction_id();

	if ( in_array( $order->status, $recoverable_statuses, true ) && empty( $transaction_id ) ) {
		return true;
	}

	return false;
}

/**
 * Generate the correct parameters required to insert a new order into the database
 * based on the order details passed by the gateway.
 *
 * @since 3.0
 *
 * @param array $order_data Order data.
 *
 * @return int|bool Integer of order ID if successful, false otherwise.
 */
function edd_build_order( $order_data = array() ) {

	// Bail if no order data
	if ( empty( $order_data ) ) {
		return false;
	}

	/* Order recovery ********************************************************/

	$resume_order   = false;
	$existing_order = EDD()->session->get( 'edd_resume_payment' );

	if ( ! empty( $existing_order ) ) {
		$order = edd_get_order( $existing_order );

		if ( $order ) {
			$recoverable_statuses = apply_filters( 'edd_recoverable_payment_statuses', array( 'pending', 'abandoned', 'failed' ) );

			$transaction_id = $order->get_transaction_id();
			
			if ( in_array( $order->status, $recoverable_statuses, true ) && empty( $transaction_id ) ) {
				$payment      = edd_get_payment( $existing_order );
				$resume_order = true;
			}
		}
	}

	if ( $resume_order ) {
		$payment->date = date( 'Y-m-d G:i:s', current_time( 'timestamp' ) );

		$payment->add_note( __( 'Payment recovery processed', 'easy-digital-downloads' ) );

		// Since things could have been added/removed since we first crated this...rebuild the cart details.
		foreach ( $payment->fees as $fee_index => $fee ) {
			$payment->remove_fee_by( 'index', $fee_index, true );
		}

		foreach ( $payment->downloads as $cart_index => $download ) {
			$item_args = array(
				'quantity'   => isset( $download['quantity'] ) ? $download['quantity'] : 1,
				'cart_index' => $cart_index,
			);
			$payment->remove_download( $download['id'], $item_args );
		}

		if ( strtolower( $payment->email ) !== strtolower( $order_data['user_info']['email'] ) ) {

			// Remove the payment from the previous customer.
			$previous_customer = new EDD_Customer( $payment->customer_id );
			$previous_customer->remove_payment( $payment->ID, false );

			// Redefine the email first and last names.
			$payment->email      = $order_data['user_info']['email'];
			$payment->first_name = $order_data['user_info']['first_name'];
			$payment->last_name  = $order_data['user_info']['last_name'];
		}

		// Remove any remainders of possible fees from items.
		$payment->save();
	}

	/** Setup order information ***********************************************/

	$gateway = ! empty( $order_data['gateway'] ) ? $order_data['gateway'] : '';
	$gateway = empty( $gateway ) && isset( $_POST['edd-gateway'] )
		? sanitize_key( $_POST['edd-gateway'] )
		: $gateway;

	if ( ! $resume_order ) {

		// Allow for post_date to be passed in.
		if ( isset( $order_data['post_date'] ) ) {
			$order_data['date_created'] = $order_data['post_date'];
			unset( $order_data['post_date'] );
		}
	}

	// Build order information based on data passed from the gateway.
	$order_args = array(
		'parent'       => ! empty( $order_data['parent'] ) ? absint( $order_data['parent'] ) : '',
		'order_number' => '',
		'status'       => ! empty( $order_data['status'] ) ? $order_data['status'] : 'pending',
		'user_id'      => $order_data['user_info']['id'],
		'email'        => $order_data['user_info']['email'],
		'ip'           => edd_get_ip(),
		'gateway'      => $gateway,
		'mode'         => edd_is_test_mode() ? 'test' : 'live',
		'currency'     => ! empty( $order_data['currency'] ) ? $order_data['currency'] : edd_get_currency(),
		'payment_key'  => $order_data['purchase_key'],
		'date_created' => ! empty( $order_data['date_created'] ) ? $order_data['date_created'] : '',
	);

	/** Setup customer ********************************************************/

	$customer = new stdClass();

	if ( did_action( 'edd_pre_process_purchase' ) && is_user_logged_in() ) {
		$customer = new EDD_Customer( get_current_user_id(), true );

		// Customer is logged in but used a different email to purchase so we need to assign that email address to their customer record.
		if ( ! empty( $customer->id ) && ( $order_args['email'] !== $customer->email ) ) {
			$customer->add_email( $order_args['email'] );
		}
	}

	if ( empty( $customer->id ) ) {
		$customer = new EDD_Customer( $order_args['email'] );

		if ( empty( $order_data['user_info']['first_name'] ) && empty( $order_data['user_info']['last_name'] ) ) {
			$name = $order_args['email'];
		} else {
			$name = $order_data['user_info']['first_name'] . ' ' . $order_data['user_info']['last_name'];
		}

		$customer->create( array(
			'name'    => $name,
			'email'   => $order_args['email'],
			'user_id' => $order_args['user_id']
		) );
	}

	// If the customer name was initially empty, update the record to store the name used at checkout.
	if ( empty( $customer->name ) ) {
		$customer->update( array(
			'name' => $order_data['user_info']['first_name'] . ' ' . $order_data['user_info']['last_name']
		) );
	}

	$order_args['customer_id'] = $customer->id;

	/** Insert order **********************************************************/

	// Add order into the edd_orders table.
	$order_id = true === $resume_order
		? $payment->ID
		: edd_add_order( $order_args );

	// Attach order to the customer record.
	$customer->attach_payment( $order_id, false );

	// Declare variables to store amounts for the order.
	$subtotal       = 0.00;
	$total_tax      = 0.00;
	$total_discount = 0.00;
	$total_fees     = 0.00;
	$order_total    = 0.00;

	/** Insert order address *************************************************/

	$order_data['user_info']['address'] = isset( $order_data['user_info']['address'] )
		? $order_data['user_info']['address']
		: array();

	$order_data['user_info']['address'] = wp_parse_args( $order_data['user_info']['address'], array(
		'line1'   => '',
		'line2'   => '',
		'city'    => '',
		'zip'     => '',
		'country' => '',
		'state'   => '',
	) );

	$order_address_data = array(
		'order_id'    => $order_id,
		'first_name'  => $order_data['user_info']['first_name'],
		'last_name'   => $order_data['user_info']['last_name'],
		'address'     => $order_data['user_info']['address']['line1'],
		'address2'    => $order_data['user_info']['address']['line2'],
		'city'        => $order_data['user_info']['address']['city'],
		'region'      => $order_data['user_info']['address']['state'],
		'country'     => $order_data['user_info']['address']['country'],
		'postal_code' => $order_data['user_info']['address']['zip'],
	);

	// Remove empty data.
	$order_address_data = array_filter( $order_address_data );

	// Add to edd_order_addresses table.
	edd_add_order_address( $order_address_data );

	// Maybe add the address to the edd_customer_addresses.
	$customer_address_data = $order_address_data;

	// We don't need to pass this data to edd_maybe_add_customer_address().
	unset( $customer_address_data['order_id'] );
	unset( $customer_address_data['first_name'] );
	unset( $customer_address_data['last_name'] );

	edd_maybe_add_customer_address( $customer->id, $customer_address_data );

	/** Insert order items ****************************************************/

	if ( is_array( $order_data['cart_details'] ) && ! empty( $order_data['cart_details'] ) ) {
		foreach ( $order_data['cart_details'] as $key => $item ) {

			// First, we need to check that what is being added is a valid download.
			$download = edd_get_download( $item['id'] );

			// Skip if download is missing or not actually a download.
			if ( empty( $download ) || ( 'download' !== $download->post_type ) ) {
				continue;
			}

			// Get price ID.
			$price_id = isset( $item['item_number']['options']['price_id'] )
				? absint( $item['item_number']['options']['price_id'] )
				: 0;

			// Build a base array of information for each order item.
			$item['discount'] = isset( $item['discount'] )
				? $item['discount']
				: 0.00;

			$item['subtotal'] = isset( $item['subtotal'] )
				? $item['subtotal']
				: (float) $item['quantity'] * $item['item_price'];

			$order_item_args = array(
				'order_id'     => $order_id,
				'product_id'   => $item['id'],
				'product_name' => $item['name'],
				'price_id'     => $price_id,
				'cart_index'   => $key,
				'quantity'     => $item['quantity'],
				'amount'       => $item['item_price'],
				'subtotal'     => $item['subtotal'],
				'discount'     => $item['discount'],
				'tax'          => $item['tax'],
				'total'        => $item['price'],
				'item_price'   => $item['item_price'] // Added for backwards compatibility
			);

			/**
			 * Allow the order item arguments to be filtered.
			 *
			 * This is here for backwards compatibility purposes.
			 *
			 * @since 3.0
			 *
			 * @param array $order_item_args Order item arguments.
			 * @param int   $download->ID    Download ID.
			 */
			$order_item_args = apply_filters( 'edd_payment_add_download_args', $order_item_args, $download->ID );
			$order_item_args = wp_parse_args( $order_item_args, array(
				'quantity'   => 1,
				'price_id'   => false,
				'amount'     => false,
				'item_price' => false,
				'discount'   => 0.00,
				'tax'        => 0.00,
			) );

			// The item_price key could have been changed by a filter.
			// This exists for backwards compatibility purposes.
			$order_item_args['amount'] = $order_item_args['item_price'];
			unset( $order_item_args['item_price'] );

			// Try to use what's passed in via the args.
			if ( false !== $order_item_args['amount'] ) {
				$item_price = $order_item_args['amount'];

				// Deal with variable pricing.
			} elseif ( $download->has_variable_prices() ) {
				$prices = $download->get_prices();

				if ( $order_item_args['price_id'] && array_key_exists( $order_item_args['price_id'], (array) $prices ) ) {
					$item_price = $prices[ $order_item_args['price_id'] ]['amount'];
				} else {
					$item_price                  = edd_get_lowest_price_option( $download->ID );
					$order_item_args['price_id'] = edd_get_lowest_price_id( $download->ID );
				}

				// Fallback to getting it directly.
			} else {
				$item_price = edd_get_download_price( $download->ID );
			}

			// Sanitize price & quantity.
			$item_price = edd_sanitize_amount( $item_price );
			$quantity   = edd_item_quantities_enabled()
				? absint( $order_item_args['quantity'] )
				: 1;

			// Subtotal needs to be updated with the sanitized amount.
			$order_item_args['subtotal'] = round( $item_price * $quantity, edd_currency_decimal_filter() );

			if ( edd_prices_include_tax() ) {
				$order_item_args['subtotal'] -= round( $order_item_args['tax'], edd_currency_decimal_filter() );
			}

			$total = $order_item_args['subtotal'] - $order_item_args['discount'] + $order_item_args['tax'];

			// Do not allow totals to go negative
			// TODO: probably remove for handling returns
			if ( $total < 0 ) {
				$total = 0;
			}

			// Sanitize all the amounts.
			$order_item_args['amount']   = round( $item_price,                  edd_currency_decimal_filter() );
			$order_item_args['subtotal'] = round( $order_item_args['subtotal'], edd_currency_decimal_filter() );
			$order_item_args['tax']      = round( $order_item_args['tax'],      edd_currency_decimal_filter() );
			$order_item_args['total']    = round( $total,                       edd_currency_decimal_filter() );

			$order_item_id = edd_add_order_item( $order_item_args );

			// Store order item fees as adjustments.
			if ( isset( $item['fees'] ) && ! empty( $item['fees'] ) ) {
				foreach ( $item['fees'] as $fee_id => $fee ) {

					// Add the adjustment.
					$adjustment_id = edd_add_order_adjustment( array(
						'object_id'   => $order_item_id,
						'object_type' => 'order_item',
						'type_id'     => '',
						'type'        => 'fee',
						'description' => $fee['label'],
						'amount'      => $fee['amount']
					) );

					edd_add_order_adjustment_meta( $adjustment_id, 'fee_id', $fee_id );
					edd_add_order_adjustment_meta( $adjustment_id, 'download_id', $fee['download_id'] );

					if ( isset( $fee['no_tax'] ) && ( true === $fee['no_tax'] ) ) {
						edd_add_order_adjustment_meta( $adjustment_id, 'no_tax', $fee['no_tax'] );
					}

					if ( isset( $fee['price_id'] ) && ! is_null( $fee['price_id'] ) ) {
						edd_add_order_adjustment_meta( $adjustment_id, 'price_id', $fee['price_id'] );
					}
				}
			}

			// Maybe store order tax.
			if ( edd_use_taxes() ) {
				$country = ! empty( $order_data['user_info']['address']['country'] )
					? $order_data['user_info']['address']['country']
					: false;

				$state = ! empty( $order_data['user_info']['address']['state'] )
					? $order_data['user_info']['address']['state']
					: false;

				$zip = ! empty( $order_data['user_info']['address']['zip'] )
					? $order_data['user_info']['address']['zip']
					: false;

				$tax_rate = isset( $item['tax_rate'] )
					? (float) $item['tax_rate']
					: edd_get_cart_tax_rate( $country, $state, $zip );

				// Always store order tax, even if empty.
				edd_add_order_adjustment( array(
					'object_id'   => $order_item_id,
					'object_type' => 'order_item',
					'type_id'     => 0,
					'type'        => 'tax_rate',
					'total'       => $tax_rate,
				) );
			}

			$subtotal       += (float) $order_item_args['subtotal'];
			$total_tax      += (float) $order_item_args['tax'];
			$total_discount += (float) $order_item_args['discount'];
		}
	}

	/** Insert order adjustments **********************************************/

	// Insert fees.
	$fees = edd_get_cart_fees();

	// Process fees.
	if ( ! empty( $fees ) ) {
		foreach ( $fees as $key => $fee ) {

			// Skip adding fee if it was specific to a download in the cart.
			if ( isset( $fee['download_id'] ) && ! empty( $fee['download_id'] ) ) {
				continue;
			}

			add_filter( 'edd_prices_include_tax', '__return_false' );

			$tax = ( isset( $fee['no_tax'] ) && false === $fee['no_tax'] ) || $fee['amount'] < 0
				? floatval( edd_calculate_tax( $fee['amount'] ) )
				: 0.00;

			remove_filter( 'edd_prices_include_tax', '__return_false' );

			$args = array(
				'object_id'   => $order_id,
				'object_type' => 'order',
				'type_id'     => '',
				'type'        => 'fee',
				'description' => $fee['label'],
				'subtotal'    => floatval( $fee['amount'] ),
				'tax'         => $tax,
				'total'       => floatval( $fee['amount'] ) + $tax,
			);

			// Add the adjustment.
			$adjustment_id = edd_add_order_adjustment( $args );

			edd_add_order_adjustment_meta( $adjustment_id, 'fee_id', $key );

			if ( isset( $fee['price_id'] ) && ! is_null( $fee['price_id'] ) ) {
				edd_add_order_adjustment_meta( $adjustment_id, 'price_id', $fee['price_id'] );
			}

			$total_fees += (float) $fee['amount'];
			$total_tax  += $tax;
		}
	}

	// Insert discounts.
	$discounts = ! empty( $order_data['user_info']['discount'] )
		? $order_data['user_info']['discount']
		: array();

	if ( ! is_array( $discounts ) ) {
		$discounts = explode( ',', $discounts );
	}

	if ( ! empty( $discounts ) && ( 'none' !== $discounts[0] ) ) {
		foreach ( $discounts as $discount ) {

			/** @var EDD_Discount $discount */
			$discount = edd_get_discount_by( 'code', $discount );

			if ( $discount instanceof EDD_Discount ) {
				edd_add_order_adjustment( array(
					'object_id'   => $order_id,
					'object_type' => 'order',
					'type_id'     => $discount->id,
					'type'        => 'discount',
					'description' => $discount,
					'amount'      => $subtotal - $discount->get_discounted_amount( $subtotal ),
				) );
			}
		}
	}

	// Calculate order total.
	$order_total = $subtotal - $total_discount + $total_tax + $total_fees;

	// Setup order number.
	if ( edd_get_option( 'enable_sequential' ) ) {
		$number = edd_get_next_payment_number();

		$order_args['order_number'] = edd_format_payment_number( $number );

		update_option( 'edd_last_payment_number', $number );
	}

	// Update the order with all of the newly computed values
	edd_update_order( $order_id, array(
		'order_number' => $order_args['order_number'],
		'subtotal'     => $subtotal,
		'tax'          => $total_tax,
		'discount'     => $total_discount,
		'total'        => $order_total
	) );

	/**
	 * Executes after an order has been fully built from the sum of its parts.
	 *
	 * @since 3.0
	 *
	 * @param int   $order_id   ID of the new order
	 * @param array $order_data Array of original order data
	 */
	do_action( 'edd_built_order', $order_id, $order_data );

	// Return order ID, or false
	return ! empty( $order_id )
		? $order_id
		: false;
}

/**
 * Update the status of an entire order.
 *
 * @since 3.0
 *
 * @param int    $order_id   Order ID.
 * @param string $new_status New order status.
 *
 * @return bool True if the status was updated successfully, false otherwise.
 */
function edd_update_order_status( $order_id = 0, $new_status = '' ) {

	// Bail if order and status are empty
	if ( empty( $order_id ) || empty( $new_status ) ) {
		return false;
	}

	// Get the order
	$order = edd_get_order( $order_id );

	// Bail if order not found
	if ( empty( $order ) ) {
		return false;
	}

	/**
	 * For backwards compatibility purposes, we need an instance of EDD_Payment so that the correct actions
	 * are invoked.
	 */
	$payment = edd_get_payment( $order_id );

	// Override to `publish`
	if ( in_array( $new_status, array( 'completed', 'complete' ), true ) ) {
		$new_status = 'publish';
	}

	// Get the old (current) status
	$old_status = $order->status;

	// We do not allow status changes if the status is the same to that stored in the database.
	// This prevents the `edd_update_payment_status` action from being triggered unnecessarily.
	if ( $old_status === $new_status ) {
		return false;
	}

	// Backwards compatibility
	$do_change = apply_filters( 'edd_should_update_payment_status', true,       $order_id, $new_status, $old_status );
	$do_change = apply_filters( 'edd_should_update_order_status',   $do_change, $order_id, $new_status, $old_status );

	$updated = false;

	if ( ! empty( $do_change ) ) {
		/**
		 * Action triggered before updating order status.
		 *
		 * @since 3.0
		 *
		 * @param int    $order_id   Order ID.
		 * @param string $new_status New order status.
		 * @param string $old_status Old order status.
		 */
		do_action( 'edd_before_order_status_change', $order_id, $new_status, $old_status );

		/**
		 * We need to update the status on the EDD_Payment instance so that the correct actions are invoked if the status
		 * is changing to something that requires interception by the payment gateway (e.g. refunds).
		 */
		$payment->status = $new_status;
		$updated = $payment->save();

		/**
		 * Action triggered when updating order status.
		 *
		 * @since 3.0
		 *
		 * @param int    $order_id   Order ID.
		 * @param string $new_status New order status.
		 * @param string $old_status Old order status.
		 */
		do_action( 'edd_transition_order_status', $order_id, $new_status, $old_status );
	}

	return $updated;
}

/**
 * Retrieve order ID based on the transaction ID.
 *
 * @since 3.0
 *
 * @param string $transaction_id Transaction ID.
 * @return int $order_id Order ID.
 */
function edd_get_order_id_from_transaction_id( $transaction_id = '' ) {
	global $wpdb;

	// Bail if no transaction ID passed.
	if ( empty( $transaction_id ) ) {
		return 0;
	}

	$order_id = $wpdb->get_var( $wpdb->prepare(
		"SELECT object_id FROM {$wpdb->edd_order_transactions} WHERE transaction_id = %s",
		$transaction_id
	) );

	return empty( $order_id )
		? 0
		: $order_id;
}

/**
 * Manually add an order.
 *
 * @since 3.0
 *
 * @param array $data Order form data.
 * @return int|bool Order ID if successful, false otherwise.
 */
function edd_add_manual_order( $data ) {

	// Bail if user cannot manage shop settings or no data was passed.
	if ( ! current_user_can( 'manage_shop_settings' ) || empty( $data ) ) {
		return false;
	}

	// Set up parameters.
	$nonce = isset( $_POST['edd_add_order_nonce'] )
		? sanitize_text_field( $_POST['edd_add_order_nonce'] )
		: '';

	// Bail if nonce fails.
	if ( empty( $nonce ) || ! wp_verify_nonce( $nonce, 'edd_add_order_nonce' ) ) {
		return false;
	}

	// Set defaults.
	$defaults = array(
		'downloads'          => array(),
		'edd-payment-status' => '',
		'payment_key'        => '',
		'gateway'            => '',
		'transaction_id'     => '',
		'receipt'            => '',
	);

	// Parse args.
	$data = wp_parse_args( $data, $defaults );

	/** Customer data ********************************************************/

	$customer_id = 0;
	$user_id     = 0;
	$email       = '';

	// Create a new customer record.
	if ( isset( $data['edd-new-customer'] ) && 1 === (int) absint( $data['edd-new-customer'] ) ) {
		$name = isset( $data['edd-new-customer-name'] )
			? sanitize_text_field( $data['edd-new-customer-name'] )
			: '';

		$email = isset( $data['edd-new-customer-email'] )
			? sanitize_email( $data['edd-new-customer-email'] )
			: '';

		// Save to database.
		$customer_id = edd_add_customer( array(
			'name'  => $name,
			'email' => $email,
		) );

		$customer = edd_get_customer( $customer_id );

	// Existing customer.
	} elseif ( isset( $data['edd-new-customer'] ) && 0 === (int) absint( $data['edd-new-customer'] ) && isset( $data['customer-id'] ) ) {
		$customer_id = absint( $data['customer-id'] );

		$customer = edd_get_customer( $customer_id );

		if ( $customer ) {
			$email   = $customer->email;
			$user_id = $customer->user_id;
		}
	} else {
		// @todo
		// Error: Please select customer or create one.
	}

	/** Insert order *********************************************************/

	// Parse order status.
	$status = sanitize_text_field( $data['edd-payment-status'] );

	if ( empty( $status ) || ! in_array( $status, array_keys( edd_get_payment_statuses() ), true ) ) {
		$status = 'publish';
	}

	$order_data = array(
		'status'      => $status,
		'user_id'     => $user_id,
		'customer_id' => $customer_id,
		'email'       => $email,
		'ip'          => sanitize_text_field( $data['ip'] ),
		'gateway'     => sanitize_text_field( $data['gateway'] ),
		'mode'        => edd_is_test_mode() ? 'test' : 'live',
		'currency'    => edd_get_currency(),
		'payment_key' => sanitize_text_field( $data['payment_key'] ),
	);

	$order_id = edd_add_order( $order_data );

	// Attach order to the customer record.
	$customer->attach_payment( $order_id, false );

	// Declare variables to store amounts for the order.
	$order_subtotal = 0.00;
	$total_tax      = 0.00;
	$total_discount = 0.00;
	$total_fees     = 0.00;
	$order_total    = 0.00;

	/** Insert order address *************************************************/
	if ( isset( $data['edd_order_address'] ) ) {
		$address = array(
			'address'     => '',
			'address2'    => '',
			'city'        => '',
			'postal_code' => '',
			'country'     => '',
			'region'      => '',
		);

		$address = wp_parse_args( $data['edd_order_address'], $address );

		$order_address_data             = $address;
		$order_address_data['order_id'] = $order_id;

		// Remove empty data.
		$order_address_data = array_filter( $order_address_data );

		// Add to edd_order_addresses table.
		edd_add_order_address( $order_address_data );

		// Maybe add the address to the edd_customer_addresses.
		$customer_address_data = $order_address_data;

		// We don't need to pass this data to edd_maybe_add_customer_address().
		unset( $customer_address_data['order_id'] );

		edd_maybe_add_customer_address( $customer->id, $customer_address_data );
	} elseif ( edd_use_taxes() && ! isset( $data['edd_order_address'] ) ) {
		// @TODO: Error... enter address.
	}

	/** Insert order items ***************************************************/

	if ( ! empty( $data['downloads'] ) ) {

		// Re-index downloads.
		$data['downloads'] = array_values( $data['downloads'] );

		foreach ( $data['downloads'] as $cart_key => $download ) {
			$d = edd_get_download( absint( $download['id'] ) );

			if ( $d ) {
				$discount = 0.00;
				$tax      = 0.00;

				// Quantity.
				$quantity = isset( $download['quantity'] )
					? absint( $download['quantity'] )
					: 1;

				// Price ID.
				$price_id = isset( $download['price_id'] )
					? absint( $download['price_id'] )
					: false;

				// Fetch variable price.
				if ( $d->has_variable_prices() && false !== $price_id ) {
					$prices = $d->get_prices();

					if ( isset( $prices[ $price_id ] ) ) {
						$amount = $prices[ $price_id ]['amount'];
					} else {
						$amount   = edd_get_lowest_price_option( $d->ID );
						$price_id = edd_get_lowest_price_id( $d->ID );
					}

				// Fetch flat price.
				} else {
					$amount = $d->get_price();
				}

				$quantity = absint( $quantity );
				$amount   = floatval( $amount );
				$subtotal = floatval( $amount * $quantity );

				// Apply percent discounts.
				if ( isset( $data['adjustments']['discount'] ) ) {
					$discounts = wp_filter_object_list( $data['adjustments']['discount'], array( 'type' => 'percent' ) );

					if ( $discounts ) {
						foreach ( $discounts as $discount ) {
							$d = edd_get_discount( absint( $discount['id'] ) );

							// Skip if discount not found.
							if ( ! $d ) {
								continue;
							}

							$discount = $subtotal * ( $d->amount / 100 );
						}
					}
				}

				if ( edd_use_taxes() ) {
					$tax = edd_prices_include_tax()
						? 0.00
						: edd_calculate_tax( $subtotal - $discount, $address['country'], $address['region'] );
				}

				// Calculate total.
				$total = floatval( $subtotal - $discount + $tax );

				// Add to edd_order_items table.
				edd_add_order_item( array(
					'order_id'     => $order_id,
					'product_id'   => absint( $download['id'] ),
					'product_name' => $d->post_title,
					'price_id'     => absint( $price_id ),
					'cart_index'   => $cart_key,
					'type'         => 'download',
					'status'       => 'complete',
					'quantity'     => $quantity,
					'amount'       => $amount,
					'subtotal'     => $subtotal,
					'discount'     => $discount,
					'tax'          => $tax,
					'total'        => $total,
				) );

				// Update running totals.
				$order_subtotal += $subtotal;
				$total_tax      += $tax;
				$total_discount += $discount;
				$order_total    += $total;
			}
		}
	}

	/** Insert adjustments ***************************************************/

	// Credit needs to be applied first.
	if ( ! empty( $data['adjustments']['credit'] ) ) {
		foreach ( $data['adjustments']['credit'] as $adjustment ) {
			edd_add_order_adjustment( array(
				'object_id'   => $order_id,
				'object_type' => 'order',
				'type'        => 'credit',
				'subtotal'    => floatval( $adjustment['amount'] ),
				'total'       => floatval( $adjustment['amount'] ),
			) );

			// Subtract from order total.
			$order_total -= floatval( $adjustment['amount'] );
		}
	}

	// Discounts are applied last.
	if ( ! empty( $data['adjustments']['discount'] ) ) {
		foreach ( $data['adjustments']['discount'] as $adjustment ) {
			$discount = edd_get_discount( absint( $adjustment['id'] ) );

			if ( $discount ) {

				// Only add flat discounts to $total_discount.
				if ( 'flat' === $discount->amount_type ) {
					$amount          = floatval( $discount->amount );
					$total_discount += $amount;
				} else {
					$amount = floatval( $order_subtotal * ( $discount->amount / 100 ) );
				}

				// Store discount.
				edd_add_order_adjustment( array(
					'object_id'   => $order_id,
					'object_type' => 'order',
					'type_id'     => $discount->id,
					'type'        => 'discount',
					'description' => $discount->code,
					'subtotal'    => $amount,
					'total'       => $amount,
				) );

				// Subtract from order total.
				$order_total -= $amount;
			}
		}
	}

	// Insert transaction ID.
	if ( ! empty( $data['transaction_id'] ) ) {
		edd_add_order_transaction( array(
			'object_id'      => $order_id,
			'object_type'    => 'order',
			'transaction_id' => sanitize_text_field( $data['transaction_id'] ),
			'gateway'        => sanitize_text_field( $data['gateway'] ),
			'status'         => 'complete',
			'total'          => $order_total,
		) );
	}

	// Update totals.
	edd_update_order( $order_id, array(
		'subtotal' => $order_subtotal,
		'tax'      => $total_tax,
		'discount' => $total_discount,
		'total'    => $order_total
	) );
}
add_action( 'edd_add_order', 'edd_add_manual_order' );

/** Order Items ***************************************************************/

/**
 * Add an order item.
 *
 * @since 3.0
 *
 * @param array $data
 * @return int|false ID of newly created order, false on error.
 */
function edd_add_order_item( $data = array() ) {

	// An order ID and product ID must be supplied for every order that is
	// inserted into the database.
	if ( empty( $data['order_id'] ) || empty( $data['product_id'] ) ) {
		return false;
	}

	// Instantiate a query object
	$orders = new EDD\Database\Queries\Order_Item();

	return $orders->add_item( $data );
}

/**
 * Delete an order item.
 *
 * @since 3.0
 *
 * @param int $order_item_id Order item ID.
 * @return int
 */
function edd_delete_order_item( $order_item_id = 0 ) {
	$orders = new EDD\Database\Queries\Order_Item();

	return $orders->delete_item( $order_item_id );
}

/**
 * Update an order item.
 *
 * @since 3.0
 *
 * @param int   $order_item_id Order item ID.
 * @param array $data          Updated file download order data.
 * @return bool Whether or not the file download order was updated.
 */
function edd_update_order_item( $order_item_id = 0, $data = array() ) {
	$orders = new EDD\Database\Queries\Order_Item();

	return $orders->update_item( $order_item_id, $data );
}

/**
 * Get an order item by ID.
 *
 * @since 3.0
 *
 * @param int $order_item_id Order item ID.
 * @return EDD\Orders\Order_Item
 */
function edd_get_order_item( $order_item_id = 0 ) {
	return edd_get_order_item_by( 'id', $order_item_id );
}

/**
 * Get an order item by field and value.
 *
 * @since 3.0
 *
 * @param string $field Database table field.
 * @param string $value Value of the row.
 * @return object
 */
function edd_get_order_item_by( $field = '', $value = '' ) {

	// Instantiate a query object
	$orders = new EDD\Database\Queries\Order_Item();

	// Return item
	return $orders->get_item_by( $field, $value );
}

/**
 * Query for order items.
 *
 * @since 3.0
 *
 * @param array $args
 * @return \EDD\Orders\Order_Item[]
 */
function edd_get_order_items( $args = array() ) {

	// Parse args
	$r = wp_parse_args( $args, array(
		'number' => 30
	) );

	// Instantiate a query object
	$orders = new EDD\Database\Queries\Order_Item();

	// Return items
	return $orders->query( $r );
}

/**
 * Count order items.
 *
 * @since 3.0
 *
 * @param array $args
 * @return int
 */
function edd_count_order_items( $args = array() ) {

	// Parse args
	$r = wp_parse_args( $args, array(
		'count' => true
	) );

	// Query for count(s)
	$orders = new EDD\Database\Queries\Order_Item( $r );

	// Return count(s)
	return absint( $orders->found_items );
}

/**
 * Query for and return array of order item counts, keyed by status.
 *
 * @since 3.0
 *
 * @return array
 */
function edd_get_order_item_counts( $order_id = 0 ) {

	// Default statuses
	$defaults = array_fill_keys( array_keys( edd_get_payment_statuses() ), 0 );

	// Query for count
	$counts = edd_get_order_items( array(
		'order_id' => $order_id,
		'count'    => true,
		'groupby'  => 'status'
	) );

	// Default array
	$o = array(
		'total' => 0
	);

	// Loop through counts and shape return value
	if ( ! empty( $counts ) ) {

		// Loop through statuses
		foreach ( $counts as $item ) {
			$o[ $item['status'] ] = absint( $item['count'] );
		}

		// Total
		$o['total'] = array_sum( $o );
	}

	// Return counts
	return array_merge( $defaults, $o );
}

/** Order Adjustments *********************************************************/

/**
 * Add an order adjustment.
 *
 * @since 3.0
 *
 * @param array $data
 * @return int|false ID of newly created order adjustment, false on error.
 */
function edd_add_order_adjustment( $data ) {

	// An object ID and object ID must be supplied for every order that is
	// inserted into the database.
	if ( empty( $data['object_id'] ) || empty( $data['object_type'] ) ) {
		return false;
	}

	// Instantiate a query object
	$orders = new EDD\Database\Queries\Order_Adjustment();

	return $orders->add_item( $data );
}

/**
 * Delete an API request order.
 *
 * @since 3.0
 *
 * @param int $adjustment_id Order adjustment ID.
 * @return int
 */
function edd_delete_order_adjustment( $adjustment_id = 0 ) {
	$orders = new EDD\Database\Queries\Order_Adjustment();

	return $orders->delete_item( $adjustment_id );
}

/**
 * Update an API request order.
 *
 * @since 3.0
 *
 * @param int   $adjustment_id Order adjustment ID.
 * @param array $data          Updated API request order data.
 * @return bool Whether or not the API request order was updated.
 */
function edd_update_order_adjustment( $adjustment_id = 0, $data = array() ) {
	$orders = new EDD\Database\Queries\Order_Adjustment();

	return $orders->update_item( $adjustment_id, $data );
}

/**
 * Get an API request order by ID.
 *
 * @since 3.0
 *
 * @param int $adjustment_id Order adjustment ID.
 * @return object
 */
function edd_get_order_adjustment( $adjustment_id = 0 ) {
	return edd_get_order_adjustment_by( 'id', $adjustment_id );
}

/**
 * Get an API request order by a specific field value.
 *
 * @since 3.0
 *
 * @param string $field Database table field.
 * @param string $value Value of the row.
 * @return object
 */
function edd_get_order_adjustment_by( $field = '', $value = '' ) {
	$orders = new EDD\Database\Queries\Order_Adjustment();

	// Return note
	return $orders->get_item_by( $field, $value );
}

/**
 * Query for API request orders.
 *
 * @since 3.0
 *
 * @param array $args
 * @return array
 */
function edd_get_order_adjustments( $args = array() ) {

	// Parse args
	$r = wp_parse_args( $args, array(
		'number' => 30
	) );

	// Instantiate a query object
	$orders = new EDD\Database\Queries\Order_Adjustment();

	// Return orders
	return $orders->query( $r );
}

/**
 * Count API request orders.
 *
 * @since 3.0
 *
 * @param array $args
 * @return int
 */
function edd_count_order_adjustments( $args = array() ) {

	// Parse args
	$r = wp_parse_args( $args, array(
		'count' => true
	) );

	// Query for count(s)
	$orders = new EDD\Database\Queries\Order_Adjustment( $r );

	// Return count(s)
	return absint( $orders->found_items );
}

/**
 * Query for and return array of order item counts, keyed by status.
 *
 * @since 3.0
 *
 * @param int    $object_id   ID of the object
 * @param string $object_type Type of object. Default `order`.
 *
 * @return array
 */
function edd_get_order_adjustment_counts( $object_id = 0, $object_type = 'order' ) {

	// Default statuses
	$defaults = array_fill_keys( array( 'tax_rate', 'fee', 'discount' ), 0 );

	// Query for count
	$counts = edd_get_order_adjustments( array(
		'object_id'   => $object_id,
		'object_type' => $object_type,
		'count'       => true,
		'groupby'     => 'type'
	) );

	// Default array
	$o = array(
		'total' => 0
	);

	// Loop through counts and shape return value
	if ( ! empty( $counts ) ) {

		// Loop through statuses
		foreach ( $counts as $item ) {
			$o[ $item['type'] ] = absint( $item['count'] );
		}

		// Total
		$o['total'] = array_sum( $o );
	}

	// Return counts
	return array_merge( $defaults, $o );
}

/** Order Addresses **********************************************************/

/**
 * Add an order address.
 *
 * @since 3.0
 *
 * @param array $data
 * @return int|false ID of newly created order address, false on error.
 */
function edd_add_order_address( $data ) {

	// An order ID must be supplied for every address inserted.
	if ( empty( $data['order_id'] ) ) {
		return false;
	}

	// Instantiate a query object
	$order_addresses = new EDD\Database\Queries\Order_Address();

	return $order_addresses->add_item( $data );
}

/**
 * Delete an order address.
 *
 * @since 3.0
 *
 * @param int $order_address_id Order address ID.
 * @return int
 */
function edd_delete_order_address( $order_address_id = 0 ) {
	$order_addresses = new EDD\Database\Queries\Order_Address();

	return $order_addresses->delete_item( $order_address_id );
}

/**
 * Update an order address.
 *
 * @since 3.0
 *
 * @param int   $order_address_id Order address ID.
 * @param array $data             Updated order address data.
 * @return bool Whether or not the API request order was updated.
 */
function edd_update_order_address( $order_address_id = 0, $data = array() ) {
	$order_addresses = new EDD\Database\Queries\Order_Address();

	return $order_addresses->update_item( $order_address_id, $data );
}

/**
 * Get an order address by ID.
 *
 * @since 3.0
 *
 * @param int $order_address_id Order adjustment ID.
 * @return object
 */
function edd_get_order_address( $order_address_id = 0 ) {
	return edd_get_order_address_by( 'id', $order_address_id );
}

/**
 * Get an order address by a specific field value.
 *
 * @since 3.0
 *
 * @param string $field Database table field.
 * @param string $value Value of the row.
 *
 * @return \EDD\Orders\Order_Address|false Object if successful, false otherwise.
 */
function edd_get_order_address_by( $field = '', $value = '' ) {
	$order_addresses = new EDD\Database\Queries\Order_Address();

	// Return order address
	return $order_addresses->get_item_by( $field, $value );
}

/**
 * Query for order addresses.
 *
 * @since 3.0
 *
 * @param array $args
 * @return \EDD\Orders\Order_Address[]
 */
function edd_get_order_addresses( $args = array() ) {

	// Parse args
	$r = wp_parse_args( $args, array(
		'number' => 30
	) );

	// Instantiate a query object
	$order_addresses = new EDD\Database\Queries\Order_Address();

	// Return orders
	return $order_addresses->query( $r );
}

/**
 * Count order addresses.
 *
 * @since 3.0
 *
 * @param array $args
 * @return int
 */
function edd_count_order_addresses( $args = array() ) {

	// Parse args
	$r = wp_parse_args( $args, array(
		'count' => true
	) );

	// Query for count(s)
	$order_addresses = new EDD\Database\Queries\Order_Address( $r );

	// Return count(s)
	return absint( $order_addresses->found_items );
}

/** Order Transactions *******************************************************/

/**
 * Add an order transaction.
 *
 * @since 3.0
 *
 * @param array $data
 * @return int|false ID of newly created order transaction, false on error.
 */
function edd_add_order_transaction( $data ) {

	// An object ID and object type must be supplied for every transaction inserted.
	if ( empty( $data['object_id'] ) || empty( $data['object_type'] ) ) {
		return false;
	}

	// Instantiate a query object.
	$transactions = new EDD\Database\Queries\Order_Transaction();

	return $transactions->add_item( $data );
}

/**
 * Delete an order transaction.
 *
 * @since 3.0
 *
 * @param int $order_transaction_id Order transaction ID.
 * @return int
 */
function edd_delete_order_transaction( $order_transaction_id = 0 ) {
	$transactions = new EDD\Database\Queries\Order_Transaction();

	return $transactions->delete_item( $order_transaction_id );
}

/**
 * Update an order address.
 *
 * @since 3.0
 *
 * @param int   $order_transaction_id Order transaction ID.
 * @param array $data                 Updated order transaction data.
 *
 * @return bool Whether or not the API request order was updated.
 */
function edd_update_order_transaction( $order_transaction_id = 0, $data = array() ) {
	$transactions = new EDD\Database\Queries\Order_Transaction();

	return $transactions->update_item( $order_transaction_id, $data );
}

/**
 * Get an order address by ID.
 *
 * @since 3.0
 *
 * @param int $order_transaction_id Order transaction ID.
 * @return object
 */
function edd_get_order_transaction( $order_transaction_id = 0 ) {
	return edd_get_order_transaction_by( 'id', $order_transaction_id );
}

/**
 * Get an order transaction by a specific field value.
 *
 * @since 3.0
 *
 * @param string $field Database table field.
 * @param string $value Value of the row.
 *
 * @return \EDD\Orders\Order_Transaction|false Object if successful, false otherwise.
 */
function edd_get_order_transaction_by( $field = '', $value = '' ) {
	$transactions = new EDD\Database\Queries\Order_Transaction();

	// Return order transaction.
	return $transactions->get_item_by( $field, $value );
}

/**
 * Query for order addresses.
 *
 * @since 3.0
 *
 * @param array $args
 * @return \EDD\Orders\Order_Transaction[]
 */
function edd_get_order_transactions( $args = array() ) {

	// Parse args.
	$r = wp_parse_args( $args, array(
		'number' => 30
	) );

	// Instantiate a query object.
	$transactions = new EDD\Database\Queries\Order_Transaction();

	// Return order transactions.
	return $transactions->query( $r );
}

/**
 * Count order transactions.
 *
 * @since 3.0
 *
 * @param array $args
 * @return int
 */
function edd_count_order_transactions( $args = array() ) {

	// Parse args.
	$r = wp_parse_args( $args, array(
		'count' => true
	) );

	// Query for count(s).
	$transactions = new EDD\Database\Queries\Order_Transaction( $r );

	// Return count(s).
	return absint( $transactions->found_items );
}