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

defined('ABSPATH') || exit;

include WP_OBLIO_DIR . '/includes/Hooks.php';

if (OBLIO_AUTO_UPDATE) {
    include WP_OBLIO_DIR . '/includes/Update.php';
}

function _wp_oblio_sync(&$error = '') {
    $email        = get_option('oblio_email');
    $secret       = get_option('oblio_api_secret');
    $cui          = get_option('oblio_cui');
    $use_stock    = get_option('oblio_use_stock');
    $series_name  = get_option('oblio_series_name');
    $workstation  = get_option('oblio_workstation');
    $management   = get_option('oblio_management');
    $product_type = get_option('oblio_product_type');
    
    if (!$email || !$secret || !$cui || !$use_stock) {
        return 0;
    }
    
    $getProductType = function($post) use ($product_type) {
        if (!$post) {
            return '';
        }
        $custom_product_type = trim(get_post_meta($post->ID, 'custom_product_type', true));
        if ($custom_product_type) {
            return $custom_product_type;
        }
        if ($product_type) {
            return $product_type;
        }
        return 'Marfa';
    };
    
    $total = 0;
    try {
        require_once WP_OBLIO_DIR . '/includes/Products.php';
        require_once WP_OBLIO_DIR . '/includes/OblioApi.php';
        require_once WP_OBLIO_DIR . '/includes/AccessTokenHandler.php';
        $accessTokenHandler = new AccessTokenHandler();
        $api = new OblioApi($email, $secret, $accessTokenHandler);
        $api->setCif($cui);
        
        $offset = 0;
        $limitPerPage = 250;
        $model = new Oblio_Products();
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
                $post = $model->find($product);
                if ($post && $getProductType($post) !== $product['productType']) {
                    continue;
                }
                if ($post) {
                    $model->update($post->ID, $product);
                } else {
                    // $model->insert($product);
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

function _wp_oblio_delete_invoice($order_id, $options = []) {
    global $wpdb;
    
    $order_id = (int) $order_id;
    if (!$order_id) {
        return array();
    }
    
    $email       = get_option('oblio_email');
    $secret      = get_option('oblio_api_secret');
    $cui         = get_option('oblio_cui');
    $management  = get_option('oblio_management');
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
    
    $series_name = get_post_meta($order_id, $series_name_key, true);
    $number      = get_post_meta($order_id, $number_key, true);
    $link        = get_post_meta($order_id, $link_key, true);
    if ($link) {
        try {
            require_once WP_OBLIO_DIR . '/includes/OblioApi.php';
            require_once WP_OBLIO_DIR . '/includes/AccessTokenHandler.php';
            
            $accessTokenHandler = new AccessTokenHandler();
            $api = new OblioApi($email, $secret, $accessTokenHandler);
            $api->setCif($cui);
            $response = $api->delete($options['docType'], $series_name, $number);
            if ($response['status'] === 200) {
                update_post_meta($order_id, $series_name_key, '');
                update_post_meta($order_id, $number_key, '');
                update_post_meta($order_id, $link_key, '');
                $result['type'] = 'success';
                $result['message'] = 'Factura a fost stearsa';
            }
        } catch (Exception $e) {
            $result['message'] = $e->getMessage();
        }
    }
    $sql = "SELECT post_id FROM `{$wpdb->postmeta}` WHERE meta_key='{$number_key}' AND meta_value<>'' ORDER BY `meta_value` DESC LIMIT 1";
    $result['lastInvoice'] = $wpdb->get_var($sql);
    return $result;
}

function _wp_oblio_build_url($action) {
    $action_url = add_query_arg('a', $action);
    return esc_url($action_url);
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
    
    $discount_in_product = (int) get_option('oblio_invoice_discount_in_product');
    
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
    
    $link = get_post_meta($order_id, $link_key, true);
    if ($link) {
        if (!empty($options['redirect'])) {
            wp_redirect($link);
            die;
        }
        return [
            'seriesName' => get_post_meta($order_id, $number_key, true),
            'number'     => get_post_meta($order_id, $series_name_key, true),
            'link'       => $link,
        ];
    }
    
    $order       = new WC_Order($order_id);
    $orderMeta   = get_post_meta($order_id);
    $contact     = sprintf('%s %s', $orderMeta['_billing_first_name'][0]??'', $orderMeta['_billing_last_name'][0]??'');
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
        sprintf('#%d', isset($orderMeta['_order_number'][0]) ? $orderMeta['_order_number'][0] : $order_id),
        date('d.m.Y', $order->get_date_created()->format('U')),
        $orderMeta['_payment_method_title'][0],
    );
    $collect = [];
    if ($auto_collect !== 0) {
        $isCard = preg_match('/card/i', $orderMeta['_payment_method_title'][0] ?? '') ||
            in_array($orderMeta['_payment_method'][0], ['stripe_cc', 'paylike', 'ipay', 'netopiapayments']);
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
            'cif'           => _wp_oblio_find_client_meta('cif', $orderMeta),
            'name'          => empty($orderMeta['_billing_company'][0]) ? $contact : $orderMeta['_billing_company'][0],
            'rc'            => _wp_oblio_find_client_meta('rc', $orderMeta),
            'code'          => '',
            'address'       => trim(($orderMeta['_billing_address_1'][0]??'') . ', ' . ($orderMeta['_billing_address_2'][0]??''), ', '),
            'state'         => _wp_oblio_find_client_meta('billing_state', $orderMeta),
            'city'          => $orderMeta['_billing_city'][0],
            'country'       => _wp_oblio_find_client_meta('billing_country', $orderMeta),
            'iban'          => '',
            'bank'          => '',
            'email'         => $orderMeta['_billing_email'][0],
            'phone'         => $orderMeta['_billing_phone'][0],
            'contact'       => $contact,
            'save'          => true,
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
        
        $getProductType = function($item, $product) use ($product_type) {
            $custom_product_type = trim(get_post_meta($item['product_id'], 'custom_product_type', true));
            if ($custom_product_type) {
                return $custom_product_type;
            }
            if ($product_type) {
                return $product_type;
            }
            return 'Marfa';
        };
        
        $normalRate = 19;
        $vatIncluded = $orderMeta['_prices_include_tax'][0] === 'yes';
        
        $measuringUnit = get_option('oblio_invoice_measuring_unit') ? get_option('oblio_invoice_measuring_unit') : 'buc';
        $measuringUnitTranslation = $data['language'] == 'RO' ? '' : get_option('oblio_invoice_measuring_unit_translation', '');
        $total = 0;
        $hasDiscounts = $discount_in_product;
        foreach ($order_items as $item) {
            $product = wc_get_product($item['product_id']);
            $package_number = $getProductPackageNumber($item, $product);
            
            $vatName = '';
            $isTaxable = $item['total_tax'] > 0; // $item->get_tax_status() === 'taxable';
            
            if ($isTaxable) {
                $vatPercentage = round($item['total_tax'] / $item['total'] * 100);
                $factor = $vatIncluded ? 1 : (1 + $vatPercentage / 100);
            } else {
                $vatName = 'SDD';
                $vatPercentage = 0;
                $factor = 1;
            }
            $regular_price = $factor * ((float) $product->get_regular_price());
            if ($item->get_variation_id() > 0) {
                $product_variatons = new WC_Product_Variation($item->get_variation_id());
                if ($product_variatons->exists()) {
                    $regular_price = $factor * ((float) $product_variatons->get_regular_price());
                }
            }
            if ($regular_price == 0) {
                $regular_price = $factor * ((float) $product->get_price());
            }
            
            $price = number_format(round($item['total'] + $item['total_tax'], 2) / $item['quantity'], 4, '.', '');
            $productPrice = empty($discount_in_product) ? $regular_price : $price;
            $total += round($price * $item['quantity'], $data['precision'] + 2);
            
            $data['products'][] = [
                'name'                      => $getProductName($item, $product),
                'code'                      => $getProductSku($item, $product),
                'description'               => _wp_oblio_get_product_description($item),
                'price'                     => round($productPrice / $package_number, $data['precision'] + 2),
                'measuringUnit'             => $measuringUnit,
                'measuringUnitTranslation'  => $measuringUnitTranslation,
                'currency'                  => $currency,
                'vatName'                   => $vatName,
                'vatPercentage'             => $vatPercentage,
                'vatIncluded'               => true,
                'quantity'                  => round($item['quantity'] * $package_number, $data['precision']),
                'productType'               => $getProductType($item, $product),
                'management'                => $management,
                'save'                      => true
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
                }
                $hasDiscounts = true;
            }
        }
        if ($order->get_shipping_total() > 0) {
            $vatName = '';
            if ($isTaxable) {
                $vatPercentage = $order->get_shipping_tax() > 0 ? round($order->get_shipping_tax() / $order->get_shipping_total() * 100) : $normalRate;
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
                'vatName'                   => $vatName,
                'vatPercentage'             => $vatPercentage,
                'vatIncluded'               => true,
                'quantity'                  => 1,
                'productType'               => 'Serviciu',
            ];
            $total += $shipping;
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
                'vatName'                   => $vatName,
                'vatPercentage'             => $vatPercentage,
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
        require_once WP_OBLIO_DIR . '/includes/OblioApi.php';
        require_once WP_OBLIO_DIR . '/includes/AccessTokenHandler.php';
        
        $accessTokenHandler = new AccessTokenHandler();
        $api = new OblioApi($email, $secret, $accessTokenHandler);
        switch ($options['docType']) {
            case 'proforma': $result = $api->createProforma($data); break;
            default: $result = $api->createInvoice($data);
        }
        // _wp_oblio_add_to_log($order_id, $result);
        if ($result['status'] == 200) {
            update_post_meta($order_id, $series_name_key, $result['data']['seriesName']);
            update_post_meta($order_id, $number_key, $result['data']['number']);
            update_post_meta($order_id, $link_key, $result['data']['link']);
            update_post_meta($order_id, $link_key, $result['data']['link']);
            update_post_meta($order_id, $date_key, date('Y-m-d'));
            
            $oblio_invoice_gen_send_email = get_option('oblio_invoice_gen_send_email');
            if ($oblio_invoice_gen_send_email == '1') {
                _wp_oblio_send_email_invoice($order_id, array(
                    'docType' => $options['docType']
                ));
            }
            if (!empty($options['redirect'])) {
                wp_redirect($response['data']['link']);
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

function _wp_oblio_get_product_description($item) {
    if (!method_exists($item, 'get_all_formatted_meta_data')) {
        return '';
    }
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
    foreach ($meta_data as $meta_id => $meta) {
        if (in_array($meta->key, $hidden_order_itemmeta, true)) {
            continue;
        }

        $description .= wp_kses_post($meta->display_key) . ': ' . wp_kses_post(force_balance_tags($meta->display_value));
    }
    return $description;
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

function _wp_oblio_find_client_meta($type, $orderMeta) {
    $av_facturare = isset($orderMeta['av_facturare']) ? unserialize($orderMeta['av_facturare'][0]) : [];
    $curiero = isset($orderMeta['curiero_pf_pj_option']) ? unserialize($orderMeta['curiero_pf_pj_option'][0]) : [];
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
            $expression = '/(cif|cui|company\_details)$/';
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
            $countryCode = $orderMeta['_billing_country'][0];
            $stateCode = $orderMeta['_billing_state'][0];
            $states = WC()->countries->get_states($countryCode);
            return isset($states[$stateCode]) ? $states[$stateCode] : $stateCode;
            break;
        case 'billing_country':
            $countryCode = $orderMeta['_billing_country'][0];
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
            $docType = 'proforma';
            $series_name  = get_post_meta($order_id, 'oblio_proforma_series_name', true);
            $number       = get_post_meta($order_id, 'oblio_proforma_number', true);
            $link         = get_post_meta($order_id, 'oblio_proforma_link', true);
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
    
    $order                              = new WC_Order($order_id);
    $orderMeta                          = get_post_meta($order_id);
    $oblio_invoice_send_email_from      = get_option('oblio_invoice_send_email_from');
    $oblio_invoice_send_email_subject   = get_option('oblio_invoice_send_email_subject');
    $oblio_invoice_send_email_cc        = get_option('oblio_invoice_send_email_cc');
    $oblio_invoice_send_email_message   = nl2br(get_option('oblio_invoice_send_email_message'));
    $series_name                        = get_post_meta($order_id, 'oblio_' . $options['docType'] . '_series_name', true);
    $number                             = get_post_meta($order_id, 'oblio_' . $options['docType'] . '_number', true);
    $link                               = get_post_meta($order_id, 'oblio_' . $options['docType'] . '_link', true);
    if (!$series_name || !$number || !$link) {
        return;
    }
    
    $issueDate    = (int) $order->get_date_created()->format('U');
    $dueDays      = (int) get_option('oblio_invoice_due');
    $contact      = sprintf('%s %s', $orderMeta['_billing_first_name'][0], $orderMeta['_billing_last_name'][0]);
    $clientName   = empty($orderMeta['_billing_company'][0]) ? $contact : $orderMeta['_billing_company'][0];
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
    
    $to = $orderMeta['_billing_email'][0];
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