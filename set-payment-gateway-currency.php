<?php
/*
Plugin Name: Site Specific- Set Payment Gateway Currency
Plugin URI: https://uriahsvictor.com
Description: Send the USD equivalent of an XCD amount to payment gateway for processing.
Version: 1.0.0
Author: Uriahs Victor
Author URI: https://uriahsvictor.com
Requires PHP: 7.2
License: GPLv2
*/

/***
 * 
 * This plugin fullfils a particular purpose: 
 * 
 * It displays a desired currency code on the front end and sends a desired currency conversion 
 * amount to the payment gateway.
 * 
 * The currency sent to the payment gateway will still be what is set in WooCommerce->General->Currency options->Currency
 *
 * This can be useful if you want users to browse your website in a local currency that isn't supported by a payment gateway example Paypal
 * but at checkout convert and send the amount to the gateway in a supported currency.
 * 
 * In the code below we're showing the site currency as XCD even though its actually in USD. At checkout we're making the conversion from XCD -> USD and sending that amount to Paypal for processing.
 * 
 * TODO - Handle Refunds - If a refund is performed the amount refunded shown on the admin dashboard would be incorrect.
 * We need to show the appropriate refunded amount.
 * 
 */

/**
 * Show our desired currency code.
 * 
 * @param string $format 
 * @param string $currency_pos 
 * @return string 
 */
function uv_add_price_prefix( string $format, string $currency_pos ) : string {
	switch ( $currency_pos ) {
		case 'left':
			$format = '%1$s%2$s&nbsp;' . "<span id='uv-ccode'>XCD</span>"; // Show XCD even though we're actually using USD
			break;
	}

	return $format;
}

add_action( 'woocommerce_price_format', 'uv_add_price_prefix', 1, 2 );

/**
 * Show the customer the amount they'd be paying in USD.
 * 
 * @return void 
 */
function uv_show_usd_total_at_checkout() : void { 

	$total  = WC()->cart->get_cart_contents_total();
	$total  = $total * 0.37; // Change conversion rate as necessary
	$total  = number_format( $total, 2, '.', ',' );
	$markup = <<<HTML
	
	<div style='text-align: center'>
	<p>Total in USD:  $$total <br><small>You will be billed in USD.</small><p>
	</div>
	
	HTML;

	echo $markup;
}
add_action( 'woocommerce_review_order_before_payment', 'uv_show_usd_total_at_checkout' );

/**
 * Set the total that should be passed to the gateway.
 * 
 * @param object $order 
 * @param array  $data 
 * @return void 
 */
function uv_set_total_for_gateway( object $order, array $order_data ) : void {

	$total = $order->get_total();

	// Set a WC session variable
	WC()->session->set( 'uv_original_total', $total );

	// Convert our total to the what we want to pass to the Payment gateway
	$converted_total = $total * 0.37; // Set your currency conversion rate here as well.
	$converted_total = round( $converted_total, 2 );

	// Also save the converted total to the session
	WC()->session->set( 'uv_converted_total', number_format( $converted_total, 2, '.', ',' ) );

	// Set the new calculated total for processing by the payment gateway
	$order->set_total( $converted_total );
}
add_action( 'woocommerce_checkout_create_order', 'uv_set_total_for_gateway', 9999, 2 );

/**
 * Save the order totals to the DB.
 * 
 * Both the original total and the converted total needs to be saved for later retrieval and processing.
 * 
 * @param int   $order_id 
 * @param array $order_data 
 * @return void 
 */
function uv_save_order_totals( int $order_id, array $order_data ) : void {

	// Get our original total session values
	$original_total  = WC()->session->get( 'uv_original_total' );
	$converted_total = WC()->session->get( 'uv_converted_total' );

	// We need to save the original total at this point so that we can grab it during sending of emails and on thank you page
	update_post_meta( $order_id, '_uv_original_total', sanitize_text_field( $original_total ) );

	// Save the converted total to the order details as well
	update_post_meta( $order_id, '_uv_converted_total', sanitize_text_field( $converted_total ) );

}
add_action( 'woocommerce_checkout_update_order_meta', 'uv_save_order_totals', 9999, 2 );

/**
 * Update the order total back to the desired currency amount.
 * 
 * We do this so that the total shown on the store will always be in our desired currency(XCD)
 * 
 * @param int $order_id 
 * @return void 
 */
function uv_update_order_total( int $order_id ) : void {

	// Get the original total 
	$original_total = get_post_meta( $order_id, '_uv_original_total', true );
	
	// Set the order total back to the original one
	update_post_meta( $order_id, '_order_total', $original_total );

}
add_action( 'woocommerce_thankyou', 'uv_update_order_total', 9999, 1 );

/**
 * Change the total shown in the order email to be our desired currency(XCD).
 * 
 * @param array  $total_rows 
 * @param object $class_instance 
 * @param string $tax_display 
 * @return array 
 */
function uv_change_order_email_total( array $total_rows, object $class_instance, string $tax_display ) : array {

	$order_id = $class_instance->get_id();
	// Grab the original order total that we started with
	$original_order_total = get_post_meta( $order_id, '_uv_original_total', true );
	// Show it in the email instead of the converted value.
	$total_rows['order_total']['value'] = wc_price( $original_order_total );

	return $total_rows;
}
add_filter( 'woocommerce_get_order_item_totals', 'uv_change_order_email_total', 9999, 3 );

/**
 * Change the "Total" shown on the order received page to our desired currency (XCD).
 * 
 * The _order_total meta gets set on the woocommerce_thankyou hook which runs AFTER the page is loaded. 
 * In this function we're making sure that the correct value shows on page load.
 * 
 * @param string $formatted_total 
 * @param object $class_instance 
 * @param string $tax_display 
 * @param bool   $display_refunded 
 * @return string 
 */
function uv_change_order_received_total( string $formatted_total, object $class_instance, string $tax_display, bool $display_refunded ) {

	// We only want this code to run on Thank You page.
	if ( ! is_checkout() && empty( is_wc_endpoint_url( 'order-received' ) ) ) {
		return;
	}

	$order_id = $class_instance->get_id();

	// Get our original total we saved during the order
	$original_total = get_post_meta( $order_id, '_uv_original_total', true );

	// Find the converted total inside the formatted total string
	preg_match( '/<\/span>(.*?)<span id/', $formatted_total, $matches );
	
	$cleaned = '';
	if ( $matches ) {
		$match   = $matches[1];
		$cleaned = preg_replace( '/[^0-9.,]/', '', $match );
	}

	// Replace the converted total with the original total
	$replaced = str_replace( $cleaned, $original_total, $formatted_total );

	return $replaced;
}
add_filter( 'woocommerce_get_formatted_order_total', 'uv_change_order_received_total', 9999, 4 );

/**
 * 
 * Add extra field to display the converted total to the admin in dashboard.
 * 
 * @param int $order_id 
 * @return void 
 */
function uv_show_admin_converted_total_order_details( int $order_id ) {

	$converted_amount           = get_post_meta( $order_id, '_uv_converted_total', true );
	$converted_amount_formatted = wc_price( $converted_amount );
	// Since we're filtering wc_price via the uv_add_price_prefix function we need to change our currency code back.
	$converted_amount_formatted = str_replace( 'XCD', 'USD', $converted_amount_formatted );
	
	$markup = <<<HTML
    <tr>
    <td class="label">Amount Paid in USD:</td>
    <td width="1%"></td>
    <td class="total">
        $converted_amount_formatted
    </td>
    </tr>
HTML;

	echo $markup;

}
add_action( 'woocommerce_admin_order_totals_after_total', 'uv_show_admin_converted_total_order_details' );

/**
 * 
 * Add extra field to display the converted total to customer when they view a past order.
 * 
 * @param object $order 
 * @return void 
 */
function uv_show_customer_converted_total_order_details( object $order ): void {

	$order_id                   = $order->get_id();
	$converted_amount           = get_post_meta( $order_id, '_uv_converted_total', true );
	$converted_amount_formatted = wc_price( $converted_amount );
	// Since we're filtering wc_price via the uv_add_price_prefix function we need to change our currency code back.
	$converted_amount_formatted = str_replace( 'XCD', 'USD', $converted_amount_formatted );
	
	$markup = <<<HTML
    <p style="text-align: right"><strong>Amount Paid in USD:</strong> $converted_amount_formatted</p>
HTML;

	echo $markup;
}
add_action( 'woocommerce_order_details_after_order_table', 'uv_show_customer_converted_total_order_details' );

/**
 * 
 * Add the converted total to the order email.
 * 
 * @param object $order 
 * @param bool   $sent_to_admin 
 * @param bool   $plain_text 
 * @param object $email 
 * @return void 
 */
function uv_add_converted_total_to_email( object $order, bool $sent_to_admin, bool $plain_text, object $email ): void {

	$order_id                   = $order->get_id();
	$converted_amount           = get_post_meta( $order_id, '_uv_converted_total', true );
	$converted_amount_formatted = wc_price( $converted_amount );
	$converted_amount_formatted = str_replace( 'XCD', 'USD', $converted_amount_formatted );
	
	$markup = <<<HTML
    <p><strong>Amount Paid in USD:</strong> $converted_amount_formatted</p>
HTML;

	echo $markup;

}
add_action( 'woocommerce_email_after_order_table', 'uv_add_converted_total_to_email', 9999, 4 );