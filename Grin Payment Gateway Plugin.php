<?php
/*
Plugin Name: GRIN Payment Gateway
Description: Accept GRIN payments using Slatepack protocol
Version: 0.1
Author: Az0te
*/

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Add the Gateway to WooCommerce
add_filter('woocommerce_payment_gateways', 'add_grin_payment_gateway');
function add_grin_payment_gateway($gateways) {
    $gateways[] = 'WC_GRIN_Gateway';
    return $gateways;
}

// Initialize the Gateway
add_action('plugins_loaded', 'init_grin_gateway');
function init_grin_gateway() {
    class WC_GRIN_Gateway extends WC_Payment_Gateway {
        public function __construct() {
            $this->id = 'grin';
            $this->icon = ''; // Add GRIN icon URL
            $this->has_fields = true;
            $this->method_title = 'GRIN Payments';
            $this->method_description = 'Accept GRIN cryptocurrency payments using Slatepack';

            // Load settings
            $this->init_form_fields();
            $this->init_settings();

            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->slatepack_address = $this->get_option('slatepack_address');

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            add_action('woocommerce_thankyou_' . $this->id, array($this, 'thank_you_page'));

            // Add action for AJAX exchange rate update
            add_action('wp_ajax_update_grin_exchange_rate', array($this, 'update_grin_exchange_rate'));
            add_action('wp_ajax_nopriv_update_grin_exchange_rate', array($this, 'update_grin_exchange_rate'));
        }

        public function init_form_fields() {
            $this->form_fields = array(
                'enabled' => array(
                    'title' => 'Enable/Disable',
                    'type' => 'checkbox',
                    'label' => 'Enable GRIN Payments',
                    'default' => 'no'
                ),
                'title' => array(
                    'title' => 'Title',
                    'type' => 'text',
                    'description' => 'Payment method title that customers see at checkout',
                    'default' => 'GRIN Payment',
                    'desc_tip' => true,
                ),
                'description' => array(
                    'title' => 'Description',
                    'type' => 'textarea',
                    'description' => 'Payment method description that customers see at checkout',
                    'default' => 'Pay with GRIN cryptocurrency using Slatepack',
                ),
                'slatepack_address' => array(
                    'title' => 'Slatepack Address',
                    'type' => 'text',
                    'description' => 'Your GRIN Slatepack address',
                ),
                'api_key' => array(
                    'title' => 'API Key',
                    'type' => 'text',
                    'description' => 'API key for GRIN node (if applicable)',
                ),
                'exchange_rate_source' => array(
                    'title' => 'Exchange Rate Source',
                    'type' => 'select',
                    'options' => array(
                        'coingecko' => 'CoinGecko',
                        'manual' => 'Manual Input',
                    ),
                    'default' => 'coingecko',
                ),
                'manual_exchange_rate' => array(
                    'title' => 'Manual Exchange Rate',
                    'type' => 'number',
                    'description' => 'GRIN to USD exchange rate (if using manual input)',
                    'default' => '1',
                ),
            );
        }

        public function process_payment($order_id) {
            global $woocommerce;
            $order = wc_get_order($order_id);

            // Generate unique payment reference
            $payment_reference = 'GRIN-' . $order_id . '-' . time();
            
            // Store payment reference
            $order->update_meta_data('_grin_payment_reference', $payment_reference);

            // Get current exchange rate and calculate GRIN amount
            $exchange_rate = $this->get_exchange_rate();
            $grin_amount = $order->get_total() / $exchange_rate;

            // Store GRIN amount
            $order->update_meta_data('_grin_amount', $grin_amount);
            $order->save();

            // Mark as pending payment
            $order->update_status('pending', __('Awaiting GRIN payment', 'woocommerce'));

            // Empty cart
            $woocommerce->cart->empty_cart();

            // Log the payment process initiation
            $this->log('Payment process initiated for order ' . $order_id . '. GRIN amount: ' . $grin_amount);

            // Redirect to thank you page
            return array(
                'result' => 'success',
                'redirect' => $this->get_return_url($order)
            );
        }

        public function thank_you_page($order_id) {
            $order = wc_get_order($order_id);
            $payment_reference = $order->get_meta('_grin_payment_reference');
            $grin_amount = $order->get_meta('_grin_amount');
            
            echo '<h2>GRIN Payment Instructions</h2>';
            echo '<p>Please send your GRIN payment using the following Slatepack address:</p>';
            echo '<code>' . esc_html($this->slatepack_address) . '</code>';
            echo '<p>Payment Reference: ' . esc_html($payment_reference) . '</p>';
            echo '<p>Amount: <span class="grin-amount">' . esc_html(number_format($grin_amount, 8)) . '</span> GRIN</p>';
            
            // Add instructions for using Slatepack
            echo '<h3>How to Pay:</h3>';
            echo '<ol>';
            echo '<li>Open your GRIN wallet</li>';
            echo '<li>Create a new transaction using the Slatepack address above</li>';
            echo '<li>Enter the exact amount shown</li>';
            echo '<li>Include the payment reference in the transaction message</li>';
            echo '<li>Complete the transaction</li>';
            echo '</ol>';

            // Add JavaScript for real-time exchange rate updates
            ?>
            <script type="text/javascript">
            jQuery(document).ready(function($) {
                function updateExchangeRate() {
                    $.ajax({
                        url: '<?php echo admin_url('admin-ajax.php'); ?>',
                        type: 'POST',
                        data: {
                            action: 'update_grin_exchange_rate',
                            order_id: <?php echo $order_id; ?>
                        },
                        success: function(response) {
                            if (response.success) {
                                $('.grin-amount').text(response.grin_amount);
                            }
                        }
                    });
                }
                setInterval(updateExchangeRate, 60000); // Update every minute
            });
            </script>
            <?php
        }

        public function check_pending_payments() {
            $orders = wc_get_orders(array(
                'status' => 'pending',
                'payment_method' => 'grin',
                'date_created' => '>' . (time() - 24 * 60 * 60) // Last 24 hours
            ));

            foreach ($orders as $order) {
                $payment_reference = $order->get_meta('_grin_payment_reference');
                $grin_amount = $order->get_meta('_grin_amount');

                // Check payment status (implement your own logic here)
                $payment_received = $this->verify_payment($payment_reference, $grin_amount);

                if ($payment_received) {
                    $order->payment_complete();
                    $order->add_order_note('GRIN payment verified and completed.');
                    $this->log('Payment completed for order ' . $order->get_id());
                }
            }
        }

        private function verify_payment($payment_reference, $expected_amount) {
            // Implement your payment verification logic here
            // This could involve checking your GRIN node or a third-party API
            // Return true if payment is verified, false otherwise
            
            // Placeholder implementation
            $this->log('Verifying payment: ' . $payment_reference . ', Expected amount: ' . $expected_amount);
            return false;
        }

        private function get_exchange_rate() {
            $exchange_rate_source = $this->get_option('exchange_rate_source');

            if ($exchange_rate_source === 'manual') {
                return floatval($this->get_option('manual_exchange_rate'));
            } else {
                // Fetch from CoinGecko API
                $response = wp_remote_get('https://api.coingecko.com/api/v3/simple/price?ids=grin&vs_currencies=usd');
                if (is_wp_error($response)) {
                    $this->log('Error fetching exchange rate: ' . $response->get_error_message());
                    return floatval($this->get_option('manual_exchange_rate')); // Fallback to manual rate
                }
                $data = json_decode(wp_remote_retrieve_body($response), true);
                return isset($data['grin']['usd']) ? $data['grin']['usd'] : floatval($this->get_option('manual_exchange_rate'));
            }
        }

        public function update_grin_exchange_rate() {
            $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
            $order = wc_get_order($order_id);

            if ($order) {
                $exchange_rate = $this->get_exchange_rate();
                $grin_amount = $order->get_total() / $exchange_rate;
                $order->update_meta_data('_grin_amount', $grin_amount);
                $order->save();

                wp_send_json_success(array('grin_amount' => number_format($grin_amount, 8)));
            } else {
                wp_send_json_error();
            }
        }

        private function log($message) {
            if (is_admin()) {
                error_log('GRIN Payment: ' . $message);
            }
        }
    }
}

// Add cron job for checking payment status
add_action('wp', 'schedule_grin_payment_check');
function schedule_grin_payment_check() {
    if (!wp_next_scheduled('check_grin_payments')) {
        wp_schedule_event(time(), 'hourly', 'check_grin_payments');
    }
}

add_action('check_grin_payments', 'do_grin_payment_check');
function do_grin_payment_check() {
    $gateway = new WC_GRIN_Gateway();
    $gateway->check_pending_payments();
}

// Add GRIN amount custom column to admin order list
add_filter('manage_edit-shop_order_columns', 'add_grin_amount_column');
function add_grin_amount_column($columns) {
    $new_columns = array();
    foreach ($columns as $column_name => $column_info) {
        $new_columns[$column_name] = $column_info;
        if ($column_name === 'order_total') {
            $new_columns['grin_amount'] = __('GRIN Amount', 'woocommerce');
        }
    }
    return $new_columns;
}

add_action('manage_shop_order_posts_custom_column', 'add_grin_amount_column_content');
function add_grin_amount_column_content($column) {
    global $post;
    if ($column === 'grin_amount') {
        $order = wc_get_order($post->ID);
        if ($order->get_payment_method() === 'grin') {
            $grin_amount = $order->get_meta('_grin_amount');
            echo $grin_amount ? number_format($grin_amount, 8) . ' GRIN' : '-';
        }
    }
}

