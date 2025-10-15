<?php
/**
 * Custom WooCommerce Customer Processing Order Email
 * Modified for WhatsApp confirmation flow (Scent n Skin)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

do_action( 'woocommerce_email_header', $email_heading, $email );

$order_id = $order->get_id();
$is_confirmed = $order->get_meta('_whatsapp_confirmed');
$customer_name = $order->get_billing_first_name();
?>

<p>
Hi <?php echo esc_html( $customer_name ?: 'Customer' ); ?>,
</p>

<?php if ( $is_confirmed === 'yes' ) : ?>
    <p>✅ Great news! Your order <strong>#<?php echo esc_html( $order_id ); ?></strong> has been <strong>confirmed via WhatsApp</strong> and is now being processed. We'll notify you once it's on the way.</p>
<?php else : ?>
    <p>🕓 We’ve received your order <strong>#<?php echo esc_html( $order_id ); ?></strong> but it’s currently <strong>pending WhatsApp confirmation</strong>. Once confirmed, we’ll begin processing your order.</p>
<?php endif; ?>

<hr style="border: 0; border-top: 1px solid #ddd; margin: 20px 0;">

<?php
// Display order details
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );

// Display customer details
do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );

// Additional custom note
if ( $is_confirmed === 'yes' ) {
    echo '<p style="margin-top:20px;">Thank you for confirming your order via WhatsApp. We truly appreciate your trust in <strong>Scent n Skin</strong>!</p>';
} else {
    echo '<p style="margin-top:20px;">You’ll receive a WhatsApp message shortly to confirm your order. Please reply to finalize it.</p>';
}

// Email footer
do_action( 'woocommerce_email_footer', $email );
?>






