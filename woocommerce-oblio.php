<?php
/**
 * Plugin Name: WooCommerce Oblio
 * Plugin URI: https://www.oblio.eu
 * Description: API implementation for oblio.eu
 * Version: [PLUGIN_VERSION]
 * Author: Oblio Software
 * Author URI: https://www.oblio.eu
 * Text Domain: woocommerce-oblio
 *
 */

define('OBLIO_VERSION', '[PLUGIN_VERSION]');
define('OBLIO_AUTO_UPDATE', true);
define('WP_OBLIO_FILE', __FILE__);
define('WP_OBLIO_DIR', untrailingslashit(plugin_dir_path(__FILE__)));
define('WP_OBLIO_SSL_VERIFYPEER', WP_OBLIO_DIR . '/certs/cacert.pem');

defined('ABSPATH') || exit;

include WP_OBLIO_DIR . '/includes/Hooks.php';
include WP_OBLIO_DIR . '/src/Autoloader.php';

OblioSoftware\Autoloader::init(WP_OBLIO_DIR . '/src');

if (OBLIO_AUTO_UPDATE) {
    include WP_OBLIO_DIR . '/includes/Update.php';
}

if (!function_exists('_wp_oblio_sync')) {
    function _wp_oblio_sync(&$error = '') {
        $email        = get_option('oblio_email');
        $secret       = get_option('oblio_api_secret');
        $cui          = get_option('oblio_cui');
        $use_stock    = get_option('oblio_use_stock');
        $workstation  = get_option('oblio_workstation');
        $management   = get_option('oblio_management');
        $product_type = get_option('oblio_product_type');
        $oblio_stock_adjusments = (int) get_option('oblio_stock_adjusments');
        
        if (!$email || !$secret || !$cui || !$use_stock) {
            return 0;
        }
    
        $total = 0;
        try {
            $accessTokenHandler = new OblioSoftware\Api\AccessTokenHandler();
            $api = new OblioSoftware\Api($email, $secret, $accessTokenHandler);
            $api->setCif($cui);
            
            $offset = 0;
            $limitPerPage = 250;
            $service = new OblioSoftware\Products();
            $ordersQty = [];
            if ($oblio_stock_adjusments === 1) {
                $ordersQty = $service->getOrdersQty([
                    'where' => [
                        "p.`post_status` IN('wc-on-hold', 'wc-processing', 'wc-pending')",
                        sprintf("p.post_date > '%s'", date('Y-m-d', time() - (3600 * 24 * 30))),
                    ]
                ]);
            }
    
            do {
                if ($offset > 0) {
                    usleep(500000);
                }
                $products = $api->nomenclature('products', null, [
                    'workStation' => $workstation,
                    'management'  => $management,
                    'offset'      => $offset,
                ]);
                $index = 0;
                foreach ($products['data'] as $product) {
                    $index++;
                    $post = $service->find($product);
                    if ($post && _wp_oblio_get_product_type($post->ID, $product_type) !== $product['productType']) {
                        continue;
                    }
                    if ($post) {
                        $service->update($post->ID, $product, $ordersQty);
                    } else {
                        // $service->insert($product, $ordersQty);
                    }
                }
                $offset += $limitPerPage; // next page
            } while ($index === $limitPerPage);
            $total = $offset - $limitPerPage + $index;
            wc_update_product_lookup_tables_column('stock_quantity');
            wc_update_product_lookup_tables_column('stock_status');
        } catch (Exception $e) {
            $error = $e->getMessage();
            $accessTokenHandler->clear();
        }
        return $total;
    }
}

function _wp_oblio_delete_invoice($order_id, $options = []) {
    $order_id = (int) $order_id;
    if (!$order_id) {
        return array();
    }
    
    $email       = get_option('oblio_email');
    $secret      = get_option('oblio_api_secret');
    $cui         = get_option('oblio_cui');
    $result      = array(
        'type'    => 'error',
        'message' => '',
    );
    
    if (empty($options['docType'])) {
        $options['docType'] = 'invoice';
    }
    $series_name_key = 'oblio_' . $options['docType'] . '_series_name';
    $number_key      = 'oblio_' . $options['docType'] . '_number';
    $link_key        = 'oblio_' . $options['docType'] . '_link';
    
    $order       = new OblioSoftware\Order($order_id);
    $series_name = $order->get_data_info($series_name_key);
    $number      = $order->get_data_info($number_key);
    $link        = $order->get_data_info($link_key);
    if ($link) {
        try {
            $accessTokenHandler = new OblioSoftware\Api\AccessTokenHandler();
            $api = new OblioSoftware\Api($email, $secret, $accessTokenHandler);
            $api->setCif($cui);
            $response = $api->delete($options['docType'], $series_name, $number);
            if ($response['status'] === 200) {
                $order->set_data_info($series_name_key, '');
                $order->set_data_info($number_key, '');
                $order->set_data_info($link_key, '');
                $order->save();
                $result['type'] = 'success';
                $result['message'] = 'Factura a fost stearsa';
            }
        } catch (Exception $e) {
            $result['message'] = $e->getMessage();
        }
    }
    $result['lastInvoice'] = OblioSoftware\Order::get_last_invoiced_order_id($options['docType']);
    return $result;
}

function _wp_oblio_build_url($action, $post) {
    return get_site_url(null, 'wp-admin/admin-ajax.php?action=oblio_invoice&a=' . $action . '&id=' .
        (method_exists($post, 'get_id') ? $post->get_id() : $post->ID));
}

function _wp_oblio_generate_invoice($order_id, $options = array()) {
    $order_id = (int) $order_id;
    if (!$order_id) {
        return array();
    }
    
    $email        = get_option('oblio_email');
    $secret       = get_option('oblio_api_secret');
    $cui          = get_option('oblio_cui');
    $use_stock    = get_option('oblio_use_stock');
    
    $workstation  = get_option('oblio_workstation');
    $management   = get_option('oblio_management');
    $product_type = get_option('oblio_product_type');
    
    $gen_date     = (int) get_option('oblio_gen_date');
    $auto_collect = (int) get_option('oblio_auto_collect');
    
    $discount_in_product    = (int) get_option('oblio_invoice_discount_in_product');
    $woocommerce_calc_taxes = get_option('woocommerce_calc_taxes') === 'yes';
    $hide_description       = (bool) get_option('oblio_hide_description');

    if (empty($options['docType'])) {
        $options['docType'] = 'invoice';
    }
    switch ($options['docType']) {
        case 'proforma': $series_name = get_option('oblio_series_name_proforma'); break;
        default: $series_name = get_option('oblio_series_name');
    }
    $series_name_key = 'oblio_' . $options['docType'] . '_series_name';
    $number_key      = 'oblio_' . $options['docType'] . '_number';
    $link_key        = 'oblio_' . $options['docType'] . '_link';
    $date_key        = 'oblio_' . $options['docType'] . '_date';

    $lock = OblioSoftware\Lock::open();

    $order = new OblioSoftware\Order($order_id);
    $link = $order->get_data_info($link_key);
    if ($link) {
        if (!empty($options['redirect'])) {
            wp_redirect($link);
            die;
        }
        return [
            'seriesName' => $order->get_data_info($number_key),
            'number'     => $order->get_data_info($series_name_key),
            'link'       => $link,
        ];
    }

    $contact     = sprintf('%s %s', $order->get_billing_first_name(), $order->get_billing_last_name());
    if (!$email || !$secret || !$cui || !$series_name) {
        return array(
            'error' => 'Eroare configurare, intra la Oblio &gt; Setari'
        );
    }
    
    $currency = substr($order->get_currency(), 0, 3);
    if (strtolower($currency) === 'lei') {
        $currency = 'RON';
    }
    
    if (empty($use_stock)) {
        $options['use_stock'] = 0;
    }
    if (!empty($options['date'])) {
        $issueDate = $options['date'];
    } else {
        $issueDate = $gen_date === 2 ? $order->get_date_created()->format('Y-m-d') : date('Y-m-d');
    }
    $dueDays = (int) get_option('oblio_invoice_due');
    $dueDate = $dueDays > 0 ? date('Y-m-d', strtotime($issueDate) + $dueDays * 3600 * 24) : '';
    
    $needle = array(
        '[order_id]',
        '[date]',
        '[payment]',
    );
    $haystack = array(
        sprintf('#%d', $order->get_order_number()),
        date('d.m.Y', $order->get_date_created()->format('U')),
        $order->get_payment_method_title(),
    );
    $collect = [];
    if ($auto_collect !== 0) {
        $isCard = preg_match('/card/i', $order->get_payment_method_title()) ||
            in_array($order->get_payment_method(), ['stripe_cc', 'stripe', 'paylike', 'ipay', 'netopiapayments']);
        if (($auto_collect === 1 && $isCard) || $auto_collect === 2) {
            $collect = [
                'type'            => $isCard ? 'Card' : 'Ordin de plata',
                'documentNumber'  => '#' . $order_id,
            ];
        }
    }
    
    $oblio_invoice_mentions = get_option('oblio_invoice_mentions');
    $oblio_invoice_mentions = str_replace($needle, $haystack, $oblio_invoice_mentions);
    $data = array(
        'cif'                => $cui,
        'client'             => [
            'cif'           => _wp_oblio_find_client_data('cif', $order),
            'name'          => trim($order->get_billing_company()) === '' ? $contact : trim($order->get_billing_company()),
            'rc'            => _wp_oblio_find_client_data('rc', $order),
            'address'       => trim($order->get_billing_address_1() . ', ' . $order->get_billing_address_2(), ', '),
            'state'         => _wp_oblio_find_client_data('billing_state', $order),
            'city'          => $order->get_billing_city(),
            'country'       => _wp_oblio_find_client_data('billing_country', $order),
            // 'iban'          => '',
            // 'bank'          => '',
            'email'         => $order->get_billing_email(),
            'phone'         => $order->get_billing_phone(),
            'contact'       => $contact,
            'save'          => true,
            'autocomplete'  => get_option('oblio_autocomplete_company', 0),
        ],
        'issueDate'          => $issueDate,
        'dueDate'            => $dueDate,
        'deliveryDate'       => '',
        'collectDate'        => '',
        'seriesName'         => $series_name,
        'collect'            => $collect,
        'referenceDocument'  => _wp_oblio_get_reference_document($order_id, $options),
        'language'           => get_option('oblio_invoice_language') ? get_option('oblio_invoice_language') : 'RO',
        'precision'          => get_option('woocommerce_price_num_decimals', 2),
        'currency'           => $currency,
        'products'           => [],
        'issuerName'         => get_option('oblio_invoice_issuer_name'),
        'issuerId'           => get_option('oblio_invoice_issuer_id'),
        'noticeNumber'       => '',
        'internalNote'       => '',
        'deputyName'         => get_option('oblio_invoice_deputy_name'),
        'deputyIdentityCard' => get_option('oblio_invoice_deputy_identity_card'),
        'deputyAuto'         => get_option('oblio_invoice_deputy_auto'),
        'selesAgent'         => get_option('oblio_invoice_seles_agent'),
        'mentions'           => $oblio_invoice_mentions,
        'value'              => 0,
        'workStation'        => $workstation,
        'useStock'           => empty($options['use_stock']) ? 0 : 1,
    );
    
    if (empty($data['referenceDocument'])) {
        /** @var \WC_Order_Item_Product[] */
        $order_items = $order->get_items();
        
        $getProductName = function($item, $product) {
            return $item['name'];
        };
        $getProductSku = function($item, $product) {
            if ($item['variation_id'] > 0) {
                $variations = get_post_meta($item['variation_id']);
                if (!empty($variations['_sku'][0])) {
                    return $variations['_sku'][0];
                }
            }
            if (!$product) {
                return '';
            }
            return $product->get_sku();
        };
        $getProductPackageNumber = function($item, $product) {
            $package_number = (int) get_post_meta($item['product_id'], 'custom_package_number', true);
            if ($item['variation_id'] > 0) {
                $variations = get_post_meta($item['variation_id']);
                if (!empty($variations['cfwc_package_number'][0])) {
                    $package_number = (int) $variations['cfwc_package_number'][0];
                }
            }
            if ($package_number === 0) { // not set
                $package_number = 1;
            }
            return $package_number;
        };

        $normalRate = 19;
        $vatIncluded = $order->get_prices_include_tax();

        $price_decimals = wc_get_price_decimals();
        
        $measuringUnit = get_option('oblio_invoice_measuring_unit') ? get_option('oblio_invoice_measuring_unit') : 'buc';
        $measuringUnitTranslation = $data['language'] == 'RO' ? '' : get_option('oblio_invoice_measuring_unit_translation', '');
        $total = 0;
        foreach ($order_items as $item) {
            $product = $item->get_product();
            $package_number = $getProductPackageNumber($item, $product);
            
            $vatName = '';
            $isTaxable = $item['total_tax'] > 0; // $item->get_tax_status() === 'taxable';
            
            if ($isTaxable) {
                $vatPercentage = round($item['total_tax'] / $item['total'] * 100);
            } else {
                $vatName = 'SDD';
                $vatPercentage = 0;
            }

            $subtotal   = number_format(round($item->get_subtotal() + $item->get_subtotal_tax(), $price_decimals) / $item['quantity'], 4, '.', '');
            $price      = number_format(round($item->get_total() + $item->get_total_tax(), $price_decimals) / $item['quantity'], 4, '.', '');
            if ($subtotal === $price && !empty($product)) {
                $regular_price = $product->get_regular_price();
                if ($item->get_variation_id() > 0) {
                    $product_variatons = new WC_Product_Variation($item->get_variation_id());
                    if ($product_variatons->exists()) {
                        $regular_price = $product_variatons->get_regular_price();
                    }
                }
                if (number_format((float) $regular_price, 2) === '0.00') {
                    $regular_price = $product->get_price();
                }
            } else {
                $regular_price = $subtotal;
            }

            $productPrice = empty($discount_in_product) ? $regular_price : $price;
            $total += round(floatval($price) * $item['quantity'], $data['precision'] + 2);
            
            $data['products'][] = [
                'name'                      => $getProductName($item, $product),
                'code'                      => $getProductSku($item, $product),
                'description'               => $hide_description ? '&nbsp;' : _wp_oblio_get_product_description($item),
                'price'                     => round(floatval($productPrice) / $package_number, $data['precision'] + 2),
                'measuringUnit'             => $measuringUnit,
                'measuringUnitTranslation'  => $measuringUnitTranslation,
                'currency'                  => $currency,
                'vatName'                   => $woocommerce_calc_taxes ? $vatName : '',
                'vatPercentage'             => $woocommerce_calc_taxes ? $vatPercentage : null,
                'vatIncluded'               => true,
                'quantity'                  => round($item['quantity'] * $package_number, $data['precision']),
                'productType'               => _wp_oblio_get_product_type($item['product_id'], $product_type),
                'management'                => $management,
                'save'                      => intval(get_option('oblio_notsave_price', 0)) === 0
            ];
            if (empty($discount_in_product) && $price !== number_format($regular_price, 4, '.', '')) {
                $discount = ($regular_price * $item['quantity']) - ($item['total'] + $item['total_tax']);
                $discount = round($discount, $data['precision'], PHP_ROUND_HALF_DOWN);
                if ($discount > 0) {
                    $data['products'][] = [
                        'name'          => sprintf('Discount "%s"', $getProductName($item, $product)),
                        'discount'      => $discount,
                        'discountType'  => 'valoric',
                    ];
                } else { // in case of stupid extensions
                    $lastKey = array_key_last($data['products']);
                    $data['products'][$lastKey]['price'] = round($price / $package_number, $data['precision'] + 2);
                }
            }
        }
        if ($order->get_shipping_total() > 0) {
            $vatName = '';
            $isTaxable = $order->get_shipping_tax() > 0;

            if ($isTaxable) {
                $vatPercentage = round($order->get_shipping_tax() / $order->get_shipping_total() * 100);
            } else {
                $vatName = 'SDD';
                $vatPercentage = 0;
            }
            $shipping = $order->get_shipping_total() + $order->get_shipping_tax();
            $data['products'][] = [
                'name'                      => 'Transport',
                'code'                      => '',
                'description'               => '',
                'price'                     => $shipping,
                'measuringUnit'             => $measuringUnit,
                'measuringUnitTranslation'  => $measuringUnitTranslation,
                'currency'                  => $currency,
                'vatName'                   => $woocommerce_calc_taxes ? $vatName : '',
                'vatPercentage'             => $woocommerce_calc_taxes ? $vatPercentage : null,
                'vatIncluded'               => true,
                'quantity'                  => 1,
                'productType'               => 'Serviciu',
            ];
            $total += $shipping;
        }


	    if ($order->get_fees()) {
		    foreach ($order->get_fees() as $fee) {
			    $fee_total = $fee->get_total() + $fee->get_total_tax();
			    if ($fee_total != 0) {
				    $vatName = '';
				    $isTaxable = $fee->get_total_tax() > 0;

				    if ($isTaxable) {
					    $vatPercentage = round($fee->get_total_tax() / $fee->get_total() * 100);
				    } else {
					    $vatName = 'SDD';
					    $vatPercentage = 0;
				    }

				    $data['products'][] = [
					    'name'                      => $fee->get_name(),
					    'code'                      => '',
					    'description'               => '',
					    'price'                     => $fee_total,
					    'measuringUnit'             => $measuringUnit,
					    'measuringUnitTranslation'  => $measuringUnitTranslation,
					    'currency'                  => $currency,
					    'vatName'                   => $woocommerce_calc_taxes ? $vatName : '',
					    'vatPercentage'             => $woocommerce_calc_taxes ? $vatPercentage : null,
					    'vatIncluded'               => true,
					    'quantity'                  => 1,
					    'productType'               => 'Serviciu',
				    ];
				    $total += $fee_total;
			    }
		    }
	    }
	    
        if (number_format($total, 2, '.', '') !== number_format($order->get_total(), 2, '.', '')) {
            $difference = $order->get_total() - $total;
            $data['products'][] = [
                'name'                      => $difference > 0 ? 'Alte taxe' : 'Discount',
                'code'                      => '',
                'description'               => '',
                'price'                     => number_format($difference, 2, '.', ''),
                'measuringUnit'             => $measuringUnit,
                'measuringUnitTranslation'  => $measuringUnitTranslation,
                'currency'                  => $currency,
                'vatName'                   => $woocommerce_calc_taxes ? $vatName : '',
                'vatPercentage'             => $woocommerce_calc_taxes ? $vatPercentage : null,
                'vatIncluded'               => true,
                'quantity'                  => 1,
                'productType'               => 'Serviciu',
            ];
        }
        
        if (number_format($total, 2, '.', '') === '0.00') {
            return [
                'error' => 'Comanda are valoare 0.00'
            ];
        }
    }
    
    $data = apply_filters( 'woocommerce_oblio_invoice_data', $data, $order_id );
    
    try {
        $accessTokenHandler = new OblioSoftware\Api\AccessTokenHandler();
        $api = new OblioSoftware\Api($email, $secret, $accessTokenHandler);
        switch ($options['docType']) {
            case 'proforma': $result = $api->createProforma($data); break;
            default: $result = $api->createInvoice($data);
        }

        do_action('woocommerce_oblio_invoice_result', $result, $order_id, $options);

        if ($result['status'] == 200) {
            try {
                wc_transaction_query('start');

                $order->set_data_info($series_name_key, $result['data']['seriesName']);
                $order->set_data_info($number_key, $result['data']['number']);
                $order->set_data_info($link_key, $result['data']['link']);
                $order->set_data_info($date_key, date('Y-m-d'));
                $order->save();

                wc_transaction_query('commit');
            } catch (Exception $e) {
                wc_transaction_query('rollback');
            }

            $oblio_invoice_gen_send_email = (int) get_option('oblio_invoice_gen_send_email');
            if ($oblio_invoice_gen_send_email === 1) {
                _wp_oblio_send_email_invoice($order_id, array(
                    'docType' => $options['docType']
                ));
            }
            if (!empty($options['redirect'])) {
                wp_redirect($result['data']['link']);
                die;
            }
            return $result['data'];
        }
    } catch (Exception $e) {
        // error handle
        $message = $e->getMessage();
        if ($message === 'The access token provided is invalid') {
            $accessTokenHandler->clear();
        }
        if (!empty($options['redirect'])) {
            die(nl2br($message));
        }
        return array(
            'error' => nl2br($message)
        );
    }
}

function _wp_oblio_get_product_description(WC_Order_Item_Product $item) {
    if (!method_exists($item, 'get_all_formatted_meta_data')) {
        return '';
    }
    $item_id = $item->get_id();
    $product = $item->get_product();
    $hidden_order_itemmeta = apply_filters(
        'woocommerce_hidden_order_itemmeta',
        array(
            '_qty',
            '_tax_class',
            '_product_id',
            '_variation_id',
            '_line_subtotal',
            '_line_subtotal_tax',
            '_line_total',
            '_line_tax',
            'method_id',
            'cost',
            '_reduced_stock',
            '_restock_refunded_items',
        )
    );
	$meta_data = $item->get_all_formatted_meta_data('');
    $description = '';

    // the dumb way it's done in wordpress 
    ob_start();
    do_action('woocommerce_before_order_itemmeta', $item_id, $item, $product);
    $description .= strip_tags(ob_get_clean());

    foreach ($meta_data as $meta_id => $meta) {
        if (in_array($meta->key, $hidden_order_itemmeta, true)) {
            continue;
        }

        $description .= wp_kses_post($meta->display_key) . ': ' . wp_kses_post(force_balance_tags($meta->display_value));
    }

    ob_start();
    do_action('woocommerce_after_order_itemmeta', $item_id, $item, $product);
    $description .= strip_tags(ob_get_clean());

    return $description;
}

function _wp_oblio_get_product_type($id, $product_type = 'Marfa') {
    $custom_product_type = trim(get_post_meta($id, 'custom_product_type', true));
    if ($custom_product_type) {
        return $custom_product_type;
    }
    return $product_type;
}

function _wp_oblio_add_to_log(int $order_id, array $data) {
    $logName = '/response_log.json';
    $line = date('Y-m-d h:i:s') . '-#' . $order_id . '-' . json_encode($data) . PHP_EOL;
    file_put_contents(WP_OBLIO_DIR . $logName, $line, FILE_APPEND);
}

function _wp_oblio_find_meta($expression, $orderMeta) {
    foreach ($orderMeta as $key=>$meta) {
        if (preg_match($expression, $key)) {
            return $meta[0];
        }
    }
    return '';
}

function _wp_oblio_find_client_data($type, OblioSoftware\Order $order) {
    $orderMeta = $order->get_data_info_array();

    $av_facturare = isset($orderMeta['av_facturare'])
        ? (is_string($orderMeta['av_facturare'][0]) ? unserialize($orderMeta['av_facturare'][0]) : $orderMeta['av_facturare'][0])
        : [];

    $curiero = [];
    if (isset($orderMeta['curiero_pf_pj_option'])) {
        if (isset($orderMeta['curiero_pf_pj_option'][0])) {
            $curiero = is_string($orderMeta['curiero_pf_pj_option'][0])
                ? unserialize($orderMeta['curiero_pf_pj_option'][0])
                : $orderMeta['curiero_pf_pj_option'][0];
        } else {
            $curiero = $orderMeta['curiero_pf_pj_option'];
        }
    }
    switch ($type) {
        case 'cif':
            if (isset($av_facturare['cui'])) {
                return $av_facturare['cui'];
            }
            if (isset($av_facturare['cnp'])) {
                return $av_facturare['cnp'];
            }
            if (isset($curiero['cui'])) {
                return $curiero['cui'];
            }
            $expression = '/(cif|cui|nif|company\_details)$/';
            break;
        case 'rc':
            if (isset($av_facturare['nr_reg_com'])) {
                return $av_facturare['nr_reg_com'];
            }
            if (isset($curiero['nr_reg_com'])) {
                return $curiero['nr_reg_com'];
            }
            $expression = '/(regcom|reg\_com|rc)$/';
            break;
        case 'billing_state':
            $countryCode = $order->get_billing_country();
            $stateCode = $order->get_billing_state();
            $states = WC()->countries->get_states($countryCode);
            return isset($states[$stateCode]) ? $states[$stateCode] : $stateCode;
            break;
        case 'billing_country':
            $countryCode = $order->get_billing_country();
            $countries = WC()->countries->get_countries();
            return isset($countries[$countryCode]) ? $countries[$countryCode] : $countryCode;
            break;
        default: return '';
    }
	
    if (!empty($av_facturare)) {
        return '';
    }
    return _wp_oblio_find_meta($expression, $orderMeta);
}

function _wp_oblio_get_reference_document($order_id, $options = []) {
    if (empty($options['docType'])) {
        $options['docType'] = 'invoice';
    }
    switch ($options['docType']) {
        case 'invoice':
            $order        = new OblioSoftware\Order($order_id);
            $series_name  = $order->get_data_info('oblio_proforma_series_name');
            $number       = $order->get_data_info('oblio_proforma_number');
            $link         = $order->get_data_info('oblio_proforma_link');
            if ($series_name && $number && $link) {
                return [
                    'type'       => 'Proforma',
                    'seriesName' => $series_name,
                    'number'     => $number,
                ];
            }
            break;
    }
    return [];
}

function _wp_oblio_send_email_invoice($order_id, $options = []) {
    if (!$order_id) {
        return;
    }
    if (empty($options['docType'])) {
        $options['docType'] = 'invoice';
    }
    switch ($options['docType']) {
        case 'proforma': $type = 'Proforma'; break;
        default: $type = 'Factura';
    }
    
    $order                              = new OblioSoftware\Order($order_id);
    $oblio_invoice_send_email_from      = get_option('oblio_invoice_send_email_from');
    $oblio_invoice_send_email_subject   = get_option('oblio_invoice_send_email_subject');
    $oblio_invoice_send_email_cc        = get_option('oblio_invoice_send_email_cc');
    $oblio_invoice_send_email_message   = nl2br(get_option('oblio_invoice_send_email_message'));
    $series_name                        = $order->get_data_info('oblio_' . $options['docType'] . '_series_name');
    $number                             = $order->get_data_info('oblio_' . $options['docType'] . '_number');
    $link                               = $order->get_data_info('oblio_' . $options['docType'] . '_link');
    if (!$series_name || !$number || !$link) {
        return;
    }
    
    $issueDate    = (int) $order->get_date_created()->format('U');
    $dueDays      = (int) get_option('oblio_invoice_due');
    $contact      = sprintf('%s %s', $order->get_billing_first_name(), $order->get_billing_last_name());
    $clientName   = trim($order->get_billing_company()) === '' ? $contact : trim($order->get_billing_company());
    $needle = array(
        '[serie]',
        '[numar]',
        '[link]',
        '[type]',
        '[issue_date]',
        '[due_date]',
        '[total]',
        '[contact_name]',
        '[client_name]',
    );
    $haystack = array(
        $series_name,
        $number,
        sprintf('<a href="%s">%s %s</a>', $link, $series_name, $number),
        $type,
        date('d.m.Y', $issueDate),
        date('d.m.Y', $issueDate + $dueDays * 3600 * 24),
        $order->get_formatted_order_total(),
        $contact,
        $clientName,
    );
    $subject = str_replace($needle, $haystack, $oblio_invoice_send_email_subject);
    $message = str_replace($needle, $haystack, $oblio_invoice_send_email_message);
    
    $to = $order->get_billing_email();
    $headers = array('Content-Type: text/html; charset=UTF-8');
    if ($oblio_invoice_send_email_cc) {
        $headers[] = sprintf('CC: %1$s <%1$s>', $oblio_invoice_send_email_cc);
    }
    $from = sprintf('%s <%s>', get_option('blogname'), get_option('admin_email'));
    if (is_email($oblio_invoice_send_email_from)) {
        $from = sprintf('%s <%s>', get_option('blogname'), $oblio_invoice_send_email_from);
    }
    $headers[] = 'From: ' . $from;
    
    wp_mail($to, $subject, $message, $headers);
}

function _wp_oblio_get_products_type() {
    return [
        'Marfa'             => 'Marfa',
        'Semifabricate'     => 'Semifabricate',
        'Produs finit'      => 'Produs finit',
        'Produs rezidual'   => 'Produs rezidual',
        'Produse agricole'  => 'Produse agricole',
        'Animale si pasari' => 'Animale si pasari',
        'Ambalaje'          => 'Ambalaje',
        'Serviciu'          => 'Serviciu',
    ];
}

function _wp_oblio_get_languages() {
    return [
        'RO' => 'Romana',
        'EN' => 'Engleza',
        'FR' => 'Franceza',
        'IT' => 'Italiana',
        'SP' => 'Spaniola',
        'HU' => 'Maghiara',
        'DE' => 'Germana',                   
    ];
}
