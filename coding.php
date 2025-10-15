
/* ===========================================================
   ✅ WhatsApp COD Order Flow – Final Stable Version
   Compatible with your working server.js
   =========================================================== */

// 🧠 Helper: detect WooCommerce email preview (to avoid admin preview error)
function scentnskin_is_email_preview() {
    return (is_admin() && isset($_GET['preview_email'])) || defined('WC_DOING_EMAIL_TEST');
}

/* 1️⃣ Keep COD orders as Pending until WhatsApp confirmation */
add_action('woocommerce_checkout_order_processed', function($order_id, $posted_data, $order) {
    if (scentnskin_is_email_preview()) return;
    if (!$order instanceof WC_Order) return;

    if ($order->get_payment_method() === 'cod' && !$order->get_meta('_whatsapp_confirmed')) {
        $order->update_status('pending', 'Awaiting WhatsApp confirmation.');
    }
}, 10, 3);

/* 2️⃣ Prevent COD order from auto-changing until confirmed */
add_action('woocommerce_order_status_changed', function($order_id, $old_status, $new_status, $order) {
    if (scentnskin_is_email_preview()) return;
    if (!$order instanceof WC_Order) return;

    $confirmed = $order->get_meta('_whatsapp_confirmed');
    if ($confirmed === 'yes') return; // confirmed via WhatsApp — allow
    if (is_admin() && !wp_doing_ajax()) return; // manual admin change — allow

    // ✅ Revert only if NOT cancelled
    if (
        $order->get_payment_method() === 'cod' &&
        $old_status === 'pending' &&
        $new_status !== 'pending' &&
        $new_status !== 'cancelled'
    ) {
        $order->update_status('pending', 'Auto reverted: waiting for WhatsApp confirmation.');
    }
}, 10, 4);


/* 3️⃣ After WhatsApp confirmation, send both emails manually */
add_action('updated_post_meta', function($meta_id, $order_id, $meta_key, $meta_value) {
    if (scentnskin_is_email_preview()) return;
    if ($meta_key !== '_whatsapp_confirmed' || $meta_value !== 'yes') return;

    $order = wc_get_order($order_id);
    if (!$order) return;

    $mailer = WC()->mailer();
    if (!empty($mailer->emails['WC_Email_New_Order'])) {
        $mailer->emails['WC_Email_New_Order']->trigger($order_id);
    }
    if (!empty($mailer->emails['WC_Email_Customer_Processing_Order'])) {
        $mailer->emails['WC_Email_Customer_Processing_Order']->trigger($order_id);
    }

    $order->add_order_note('✅ WhatsApp confirmed — confirmation emails triggered.');
}, 10, 4);

/* 4️⃣ Custom email subject lines for COD orders */
add_filter('woocommerce_email_subject_customer_processing_order', function($subject, $order) {
    if (!is_a($order, 'WC_Order')) return $subject;

    if ($order->get_payment_method() === 'cod') {
        if ($order->get_meta('_whatsapp_confirmed') === 'yes') {
            return '✅ Your order has been confirmed via WhatsApp';
        } else {
            return 'We’ve received your order pending WhatsApp confirmation';
        }
    }
    return $subject; // for online orders
}, 10, 2);

/* 5️⃣ Add custom message in order email body for pending COD */
add_action('woocommerce_email_before_order_table', function($order, $sent_to_admin, $plain_text, $email) {
    if (!is_a($order, 'WC_Order')) return;

    if ($order->get_payment_method() === 'cod' && $order->get_meta('_whatsapp_confirmed') !== 'yes' && !$sent_to_admin) {
        echo '<p style="color:#444;">
           <strong> Make Every Day Better, With Our Fragrance & Give Your Skin The Love It Deserves.</strong>
        </p>';
    }
}, 10, 4);


/* ===========================================================
   🚫 WhatsApp Order Cancel – Auto Email Trigger (FINAL FIX – WORKING)
   =========================================================== */
add_action('updated_post_meta', function($meta_id, $order_id, $meta_key, $meta_value) {

    // ✅ Only trigger on WhatsApp cancel
    if ($meta_key !== '_whatsapp_confirmed' || $meta_value !== 'no') return;

    $order = wc_get_order($order_id);
    if (! $order instanceof WC_Order) return;

    // ✅ Ensure order can be cancelled even if pending or on-hold
    $valid_statuses = array('pending', 'on-hold', 'processing');
    if (in_array($order->get_status(), $valid_statuses)) {

        // ✅ Force cancel and add reason
        $order->update_status('cancelled', '❌ Cancelled via WhatsApp by customer.');

        // ✅ Send both Admin and Customer "Order Cancelled" emails
        $mailer = WC()->mailer();

        // 🔔 Admin email (New Cancelled Order)
        if (isset($mailer->emails['WC_Email_Cancelled_Order'])) {
            $mailer->emails['WC_Email_Cancelled_Order']->trigger($order_id);
        }

        // 📩 Customer email (Your Order Has Been Cancelled)
        if (isset($mailer->emails['WC_Email_Customer_Cancelled_Order'])) {
            $mailer->emails['WC_Email_Customer_Cancelled_Order']->trigger($order_id);
        }

        // 🪪 Internal note for logs
        $order->add_order_note('❌ WhatsApp Cancelled — Order status updated and both emails sent.');
    } else {
        // If already cancelled or completed, log skip
        $order->add_order_note('ℹ️ WhatsApp cancel ignored — order not in cancellable state.');
    }

}, 20, 4);



/* ===========================================================
   ✅ Allow Pending COD Orders to Move to Cancelled (for WhatsApp bot)
   =========================================================== */
add_filter('woocommerce_valid_order_statuses_for_cancel', function($statuses, $order) {
    if ($order && $order->get_payment_method() === 'cod') {
        // Permit cancel from pending or on-hold, etc.
        $statuses[] = 'pending';
        $statuses[] = 'on-hold';
    }
    return array_unique($statuses);
}, 10, 2);


/* ===========================================================
   📨 Force Send Cancelled Emails on Status Change (100% Fix)
   =========================================================== */
add_action('woocommerce_order_status_cancelled', function($order_id) {
    $order = wc_get_order($order_id);
    if (! $order) return;

    $mailer = WC()->mailer();

    // 🔔 Admin cancelled email
    if (! empty($mailer->emails['WC_Email_Cancelled_Order'])) {
        $mailer->emails['WC_Email_Cancelled_Order']->trigger($order_id);
    }

    // 📩 Customer cancelled email
    if (! empty($mailer->emails['WC_Email_Customer_Cancelled_Order'])) {
        $mailer->emails['WC_Email_Customer_Cancelled_Order']->trigger($order_id);
    }

    $order->add_order_note('📨 Forced cancelled emails triggered programmatically.');
}, 20);


/* ===========================================================
   📩 Force Email When COD Order Moves to Processing
   =========================================================== */
add_action('woocommerce_order_status_processing', function($order_id) {
    $order = wc_get_order($order_id);
    if (!$order instanceof WC_Order) return;

    // Only for COD orders confirmed via WhatsApp
    if ($order->get_payment_method() !== 'cod') return;
    if ($order->get_meta('_whatsapp_confirmed') !== 'yes') return;

    $mailer = WC()->mailer();

    // Send both admin + customer confirmation emails
    if (!empty($mailer->emails['WC_Email_New_Order'])) {
        $mailer->emails['WC_Email_New_Order']->trigger($order_id);
    }
    if (!empty($mailer->emails['WC_Email_Customer_Processing_Order'])) {
        $mailer->emails['WC_Email_Customer_Processing_Order']->trigger($order_id);
    }

    $order->add_order_note('📧 Forced processing emails sent after WhatsApp confirmation.');
}, 10, 1);
