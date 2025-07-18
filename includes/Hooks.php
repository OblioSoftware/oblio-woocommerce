<?php

use Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore;

add_action('admin_menu', '_wp_oblio_load_plugin');
add_action('oblio_sync_schedule', '_wp_oblio_sync');

add_action('init', '_oblio_init');
function _oblio_init() {
    // Load plugin textdomain.
    load_plugin_textdomain('woocommerce-oblio', false, WP_OBLIO_DIR . '/languages');

    add_action('woocommerce_order_status_changed', '_wp_oblio_status_complete', 99, 3);
    add_action('woocommerce_payment_complete', '_wp_oblio_payment_complete');

    // ajax
    add_action('wp_ajax_oblio', '_wp_oblio_ajax_handler');
    add_action('wp_ajax_oblio_invoice', '_wp_oblio_invoice_ajax_handler');

    // rest api
    add_action('rest_api_init', function () {
        register_rest_route('oblio/v1', '/card/confirm', [
            'methods' => 'POST',
            'callback' => '_wp_oblio_card_confirm',
            'permission_callback' => '__return_true'
        ]);
    });
}

function _wp_oblio_payment_complete($order_id) {
    _wp_oblio_load_plugin();
    $oblio_invoice_autogen = (int) get_option('oblio_invoice_autogen');
    $oblio_invoice_autogen_use_stock = (int) get_option('oblio_invoice_autogen_use_stock');
    if ($oblio_invoice_autogen === 1) {
        _wp_oblio_generate_invoice($order_id, ['use_stock' => $oblio_invoice_autogen_use_stock]);
    }
}

function _wp_oblio_get_hash() {
    return sha1(get_option('oblio_email'));
}

function _wp_oblio_card_confirm(WP_REST_Request $request) {
    $hash = _wp_oblio_get_hash();
    $code = 400;
    $body = 'Bad request';

    $query = $request->get_query_params();

    if (($query['hash'] ?? '') === $hash && $hash !== '') {
        $code   = 200;
        $body   = base64_encode($request->get_header('x_oblio_request_id'));
        $params = $request->get_json_params();
        $data   = $params['data'] ?? [];

        $order = OblioSoftware\Order::get_order_by_proforma($data['seriesName'] ?? '', $data['number'] ?? '');
        if ($order !== null) {
            $invoiceSeriesName = esc_sql($data['invoicedBy']['seriesName'] ?? '');
            $invoiceNumber     = esc_sql($data['invoicedBy']['number'] ?? '');
            $invoiceLink       = esc_sql($data['invoicedBy']['link'] ?? '');

            try {
                wc_transaction_query('start');

                if ($invoiceSeriesName !== '' && $invoiceNumber !== '') {
                    $order->set_data_info('oblio_invoice_series_name', $invoiceSeriesName);
                    $order->set_data_info('oblio_invoice_number', $invoiceNumber);
                    $order->set_data_info('oblio_invoice_link', $invoiceLink);
                    $order->set_data_info('oblio_invoice_date', date('Y-m-d'));
                }

                $order->set_status('completed');
                $order->save();

                wc_transaction_query('commit');
            } catch (Exception $e) {
                wc_transaction_query('rollback');
            }
        }
    }

    http_response_code($code);
    header('Content-Type: text/html');
    exit($body);
}

function _wp_oblio_ajax_handler() {
    $type       = isset($_POST['type']) ? $_POST['type'] : '';
    $cui        = isset($_POST['cui']) ? $_POST['cui'] : '';
    $name       = isset($_POST['name']) ? $_POST['name'] : '';
    $result     = array();
    
    $email       = get_option('oblio_email');
    $secret      = get_option('oblio_api_secret');
    
    if (!$email || !$secret) {
        die('[]');
    }
    
    try {
        $accessTokenHandler = new OblioSoftware\Api\AccessTokenHandler();
        $api = new OblioSoftware\Api($email, $secret, $accessTokenHandler);
        $api->setCif($cui);
        
        switch ($type) {
            case 'series_name':
            case 'series_name_proforma':
                $options = ['type' => 'Factura'];
                if ($type == 'series_name_proforma') {
                    $options['type'] = 'Proforma';
                }
                $response = $api->nomenclature('series', '', $options);
                $result = $response['data'];
                break;
            case 'workstation':
            case 'management':
                $response = $api->nomenclature('management', '');
                $workStations = array();
                $management = array();
                foreach ($response['data'] as $item) {
                    if ($name === $item['workStation']) {
                        $management[] = ['name' => $item['management']];
                    }
                    $workStations[$item['workStation']] = ['name' => $item['workStation']];
                }
                switch ($type) {
                    case 'workstation': $result = $workStations; break;
                    case 'management': $result = $management; break;
                }
                break;
        }
    } catch (Exception $e) {
        // do nothing
    }
    die(json_encode($result));
}

function _wp_oblio_invoice_ajax_handler() {
    header('Content-Type: application/json');

    $result = array();
    $date = isset($_GET['date']) ? $_GET['date'] : '';

    $order_id = $_GET['id'] ?? 0;

    switch ($_GET['a']) {
        case 'oblio-generate-invoice': $result = _wp_oblio_generate_invoice($order_id, ['date' => $date]); break; 
        case 'oblio-generate-invoice-stock': $result = _wp_oblio_generate_invoice($order_id, ['use_stock' => true, 'date' => $date]); break;
        case 'oblio-generate-proforma-stock': $result = _wp_oblio_generate_invoice($order_id, ['docType' => 'proforma', 'date' => $date]); break;
        case 'oblio-view-invoice': die(_wp_oblio_generate_invoice($order_id, ['redirect' => true, 'date' => $date])); break; 
        case 'oblio-view-proforma': die(_wp_oblio_generate_invoice($order_id, ['redirect' => true, 'docType' => 'proforma', 'date' => $date])); break; 
        case 'oblio-delete-invoice': $result = _wp_oblio_delete_invoice($order_id); break;
        case 'oblio-delete-proforma': $result = _wp_oblio_delete_invoice($order_id, ['docType' => 'proforma']); break;
    }
    die(json_encode($result));
}

// add custom field
function _oblio_cfwc_create() {
    $args = array(
        'id'            => 'custom_package_number',
        'label'         => __('Bucati pe pachet', 'woocommerce-oblio'),
        'class'         => 'cfwc-custom-field',
    );
    woocommerce_wp_text_input($args);
    
    $args = array(
        'id'            => 'custom_product_type',
        'label'         => __('Tip produse', 'woocommerce-oblio'),
        'class'         => 'select short cfwc-custom-field',
        'options'       => ['' => 'Valoare implicita'] + _wp_oblio_get_products_type(),
    );
    woocommerce_wp_select($args);
}
add_action('woocommerce_product_options_general_product_data', '_oblio_cfwc_create');

function _oblio_cfwc_save($post_id) {
    $product    = wc_get_product($post_id);
    $title      = isset($_POST['custom_package_number']) ? $_POST['custom_package_number'] : '';
    $product->update_meta_data('custom_package_number', sanitize_text_field($title));
    
    $title      = isset($_POST['custom_product_type']) ? $_POST['custom_product_type'] : '';
    $product->update_meta_data('custom_product_type', sanitize_text_field($title));
    
    $product->save();
}
add_action('woocommerce_process_product_meta', '_oblio_cfwc_save');
    
// add custom field variations
function _oblio_cfwc_create_variations($loop, $variation_data, $variation) {
    $args = array(
        'id'            => 'cfwc_package_number[' . $loop . ']',
        'class'         => 'short',
        'wrapper_class' => 'form-row',
        'label'         => __('Bucati pe pachet', 'woocommerce-oblio'),
        'value'         => get_post_meta($variation->ID, 'cfwc_package_number', true)
    );
    woocommerce_wp_text_input($args);
}
add_action('woocommerce_variation_options_pricing', '_oblio_cfwc_create_variations', 100, 3);

function _oblio_cfwc_save_variations($variation_id, $i) {
    $cfwc_package_number = isset($_POST['cfwc_package_number'][$i]) ? $_POST['cfwc_package_number'][$i] : '';
    update_post_meta($variation_id, 'cfwc_package_number', esc_attr($cfwc_package_number));
}
add_action('woocommerce_save_product_variation', '_oblio_cfwc_save_variations', 100, 2);

register_deactivation_hook(WP_OBLIO_FILE, '_wp_oblio_clear');

function _wp_oblio_clear() {
    wp_clear_scheduled_hook('oblio_sync_schedule');
}

function _wp_oblio_load_plugin() {
    if (!class_exists('WooCommerce')) {
        return;
    }
    
    // create new top-level menu
    add_menu_page('Oblio', 'Oblio', 'manage_options', 'oblio-plugin', null, plugins_url('/assets/images/icon.png', WP_OBLIO_FILE) );
    add_submenu_page('oblio-plugin', __('Conectare', 'woocommerce-oblio'), __('Conectare', 'woocommerce-oblio'), 'manage_options', 'oblio-plugin', '_wp_oblio_login_page');
    add_submenu_page('oblio-plugin', __('Setari Oblio', 'woocommerce-oblio'), __('Setari', 'woocommerce-oblio'), 'manage_options', 'oblio-settings', '_wp_oblio_settings_page');
    add_submenu_page('oblio-plugin', __('Sincronizare Manuala', 'woocommerce-oblio'), __('Sincronizare Manuala', 'woocommerce-oblio'), 'manage_options', 'oblio-import', '_wp_oblio_import_page');
    add_submenu_page('oblio-plugin', __('Ajutor', 'woocommerce-oblio'), __('Ajutor', 'woocommerce-oblio'), 'manage_options', 'oblio-help', '_wp_oblio_help');

    // call register settings function
    add_action('admin_init', '_wp_register_oblio_plugin_settings');
    
    $email   = get_option('oblio_email');
    $secret  = get_option('oblio_api_secret');
    
    if (!$email || !$secret) {
        return;
    }
    
    add_filter('manage_woocommerce_page_wc-orders_columns', '_wp_oblio_add_invoice_column', 10); // WC 7.1+
    add_filter('manage_edit-shop_order_columns', '_wp_oblio_add_invoice_column', 10);
    add_action('manage_woocommerce_page_wc-orders_custom_column', '_wp_oblio_add_invoice_column_content', 10, 2); // WC 7.1+
    add_action('manage_shop_order_posts_custom_column', '_wp_oblio_add_invoice_column_content', 10, 2);
    
    add_action('add_meta_boxes', '_wp_oblio_order_details_box');

    add_action('update_option', '_wp_oblio_update_options', 10, 3);
    
    // cron sync
    $oblio_stock_sync = get_option('oblio_stock_sync');
    if ($oblio_stock_sync == '1') {
        if (!wp_next_scheduled('oblio_sync_schedule')) {
            wp_schedule_event(time(), 'hourly', 'oblio_sync_schedule');
        }
    } else {
        wp_clear_scheduled_hook('oblio_sync_schedule');
    }
}

function _wp_oblio_update_options($option_name, $old_value, $value) {
    if ($option_name === 'oblio_webhook_card_complete') {
        $email       = get_option('oblio_email');
        $secret      = get_option('oblio_api_secret');
        $cui         = get_option('oblio_cui');

        try {
            $accessTokenHandler = new OblioSoftware\Api\AccessTokenHandler();
            $api = new OblioSoftware\Api($email, $secret, $accessTokenHandler);

            if (empty($value)) {
                $response = $api->createRequest(
                    new OblioSoftware\Api\Request\WebhookRead(null, [
                        'cif'       => $cui,
                        'topic'     => 'Card/Confirmed',
                    ])
                );
                $id = $response['data'][0]['id'] ?? null;
                if ($id !== null) {
                    $api->createRequest(
                        new OblioSoftware\Api\Request\WebhookDelete($id)
                    );
                }
            } else {
                $api->createRequest(
                    new OblioSoftware\Api\Request\WebhookCreate([
                        'cif'       => $cui,
                        'topic'     => 'Card/Confirmed',
                        'endpoint'  => get_site_url(null, 'wp-json/oblio/v1/card/confirm?hash=' . _wp_oblio_get_hash()),
                    ])
                );
            }
        } catch (Exception $e) {}
    }
}

add_action('woocommerce_thankyou', '_wp_oblio_new_order', 1000);
function _wp_oblio_new_order($order_id) {
    $order = new OblioSoftware\Order($order_id);
    $series_name  = $order->get_data_info('oblio_invoice_series_name');
    $number       = $order->get_data_info('oblio_invoice_number');
    $link         = $order->get_data_info('oblio_invoice_link');
    if ($series_name || $number || $link) {
        return;
    }
    
    $oblio_proforma_autogen = get_option('oblio_proforma_autogen');
    if ($oblio_proforma_autogen == '1') {
        _wp_oblio_generate_invoice($order_id, ['docType' => 'proforma']);
    }
}

// bulk options
add_filter('bulk_actions-edit-shop_order', 'register_oblio_bulk_actions', 10);
add_filter('bulk_actions-woocommerce_page_wc-orders', 'register_oblio_bulk_actions', 10); // WC 7.1+

function register_oblio_bulk_actions($bulk_actions) {
    $bulk_actions['oblio_bulk_action'] = __('Genereaza factura in Oblio', 'woocommerce-oblio');
    return $bulk_actions;
}

add_filter('handle_bulk_actions-edit-shop_order', 'oblio_bulk_action_handler', 10, 3);
add_filter('handle_bulk_actions-woocommerce_page_wc-orders', 'oblio_bulk_action_handler', 10, 3); // WC 7.1+

function oblio_bulk_action_handler($redirect_to, $doaction, $post_ids) {
    if ($doaction !== 'oblio_bulk_action') {
        return $redirect_to;
    }
    $oblio_invoice_autogen_use_stock = (int) get_option('oblio_invoice_autogen_use_stock');
    sort($post_ids, SORT_NUMERIC);
    foreach ($post_ids as $post_id) {
        $order = new OblioSoftware\Order($post_id);
        $link = $order->get_data_info('oblio_invoice_link');
        if (empty($link)) {
            $result = _wp_oblio_generate_invoice($post_id, ['use_stock' => $oblio_invoice_autogen_use_stock]);
        }
    }
    $redirect_to = add_query_arg('oblio_bulk_posts', count($post_ids), $redirect_to);
    return $redirect_to;
}

function _wp_register_oblio_plugin_settings() {
    // register our settings
    register_setting('oblio-plugin-login-group', 'oblio_email');
    register_setting('oblio-plugin-login-group', 'oblio_api_secret');
    register_setting('oblio-plugin-settings-group', 'oblio_cui');
    register_setting('oblio-plugin-settings-group', 'oblio_use_stock');
    register_setting('oblio-plugin-settings-group', 'oblio_series_name');
    register_setting('oblio-plugin-settings-group', 'oblio_series_name_proforma');
    register_setting('oblio-plugin-settings-group', 'oblio_workstation');
    register_setting('oblio-plugin-settings-group', 'oblio_management');
    register_setting('oblio-plugin-settings-group', 'oblio_invoice_autogen');
    register_setting('oblio-plugin-settings-group', 'oblio_invoice_autogen_use_stock');
    register_setting('oblio-plugin-settings-group', 'oblio_proforma_autogen');
    register_setting('oblio-plugin-settings-group', 'oblio_gen_date');
    register_setting('oblio-plugin-settings-group', 'oblio_auto_collect');
    register_setting('oblio-plugin-settings-group', 'oblio_webhook_card_complete');
    register_setting('oblio-plugin-settings-group', 'oblio_invoice_gen_send_email');
    register_setting('oblio-plugin-settings-group', 'oblio_invoice_send_email_from');
    register_setting('oblio-plugin-settings-group', 'oblio_invoice_send_email_subject');
    register_setting('oblio-plugin-settings-group', 'oblio_invoice_send_email_cc');
    register_setting('oblio-plugin-settings-group', 'oblio_invoice_send_email_message');
    register_setting('oblio-plugin-settings-group', 'oblio_stock_sync');
    register_setting('oblio-plugin-settings-group', 'oblio_stock_adjusments');
    register_setting('oblio-plugin-settings-group', 'oblio_invoice_language');
    register_setting('oblio-plugin-settings-group', 'oblio_invoice_measuring_unit');
    register_setting('oblio-plugin-settings-group', 'oblio_invoice_measuring_unit_translation');
    register_setting('oblio-plugin-settings-group', 'oblio_product_type');
    register_setting('oblio-plugin-settings-group', 'oblio_invoice_due');
    register_setting('oblio-plugin-settings-group', 'oblio_invoice_issuer_name');
    register_setting('oblio-plugin-settings-group', 'oblio_invoice_issuer_id');
    register_setting('oblio-plugin-settings-group', 'oblio_invoice_deputy_name');
    register_setting('oblio-plugin-settings-group', 'oblio_invoice_deputy_identity_card');
    register_setting('oblio-plugin-settings-group', 'oblio_invoice_deputy_auto');
    register_setting('oblio-plugin-settings-group', 'oblio_invoice_seles_agent');
    register_setting('oblio-plugin-settings-group', 'oblio_invoice_mentions');
    register_setting('oblio-plugin-settings-group', 'oblio_invoice_discount_in_product');
    register_setting('oblio-plugin-settings-group', 'oblio_invoice_vat_from_woocommerce');
    register_setting('oblio-plugin-settings-group', 'oblio_hide_description');
    register_setting('oblio-plugin-settings-group', 'oblio_autocomplete_company');
    register_setting('oblio-plugin-settings-group', 'oblio_notsave_price');
    register_setting('oblio-plugin-settings-group', 'oblio_update_price');
}

function _wp_oblio_status_complete($order_id, $old_status, $new_status) {
    if (!$order_id) {
        return;
    }
    if ($old_status != 'completed' && $new_status == 'completed') {
        $oblio_invoice_autogen = (int) get_option('oblio_invoice_autogen');
        $oblio_invoice_autogen_use_stock = (int) get_option('oblio_invoice_autogen_use_stock');
        if ($oblio_invoice_autogen === 1) {
            _wp_oblio_generate_invoice($order_id, ['use_stock' => $oblio_invoice_autogen_use_stock]);
        }
    }
}

function _wp_oblio_add_invoice_column($columns) {
    $columns['oblio_invoice'] = 'Oblio';
    return $columns;
}

function _wp_oblio_add_invoice_column_content($column, $item = null) {
    switch ($column) {
        case 'oblio_invoice':
            $order       = new OblioSoftware\Order(is_int($item) ? $item : $item->get_id());
            $series_name = $order->get_data_info('oblio_invoice_series_name');
            $number      = $order->get_data_info('oblio_invoice_number');
            $link        = $order->get_data_info('oblio_invoice_link');

            if ($series_name && $number && $link) {
                echo sprintf('<a href="%s" target="_blank">%s %s</a>', $link, $series_name, $number);
            }
            break;
    }
}

/**
 * Pages
 */

function _wp_oblio_login_page() {
    if (function_exists('flush_rewrite_rules')) {
        flush_rewrite_rules();
    }
    include WP_OBLIO_DIR . '/view/login_page.php';
}

function _wp_oblio_import_page() {
    $email       = get_option('oblio_email');
    $secret      = get_option('oblio_api_secret');
    
    if (!$email || !$secret) {
        echo '<h1>'.__('Introdu Email si API Secret in sectiunea "Oblio"', 'woocommerce-oblio').'</h1>';
        return;
    }
    $message = '';
    if (isset($_GET['import'])) {
        $total = _wp_oblio_sync($error);
        if ($error) {
            $message = sprintf('<div class="notice notice-error"><p>%s</p></div>', $error);
        } else {
            $message = sprintf('<div class="notice notice-success"><p>Importul a fost facut cu succes. %d produse importate</p></div>', $total);
        }
    }
    include WP_OBLIO_DIR . '/view/import_page.php';
}

function _wp_oblio_settings_page() {
    $email       = get_option('oblio_email');
    $secret      = get_option('oblio_api_secret');
    $cui         = get_option('oblio_cui');
    $series_name = get_option('oblio_series_name');
    $workstation = get_option('oblio_workstation');
    $error = '';
    $fields = [];
    
    if (!$email || !$secret) {
        echo '<h1>'.__('Introdu Email si API Secret in sectiunea "Oblio"', 'woocommerce-oblio').'</h1>';
        return;
    }
    if (!$error) {
        try {
            $accessTokenHandler = new OblioSoftware\Api\AccessTokenHandler();
            $api = new OblioSoftware\Api($email, $secret, $accessTokenHandler);
            
            // get companies
            $companies = $api->nomenclature('companies');
            $series = [];
            $series_proforma = [];
            $workStations = [];
            $management = [];
            
            if ((int) $companies['status'] === 200 && count($companies['data']) > 0) {
                $fields = array(
                    array(
                        'type' => 'select',
                        'label' => __('Companie', 'woocommerce-oblio'),
                        'name' => 'oblio_cui',
                        'options' => [
                            'query' => array_merge([['cif' => '', 'company' => 'Selecteaza']], $companies['data']),
                            'id'    => 'cif',
                            'name'  => 'company',
                            'data'  => array('use-stock' => 'useStock'),
                        ],
                        'class' => 'chosen',
                        // 'lang' => true,
                        'required' => true
                    ),
                );
                
                if ($cui) {
                    $api->setCif($cui);
                    $useStock = false;
                    foreach ($companies['data'] as $company) {
                        if ($company['cif'] === $cui) {
                            $useStock = $company['useStock'];
                            break;
                        }
                    }
                    update_option('oblio_use_stock', (int) $useStock);
                    
                    // series
                    usleep(500000); // 0.5s sleep
                    $response = $api->nomenclature('series', '', ['type' => 'Factura']);
                    $series = $response['data'];
                    
                    // series proformas
                    usleep(500000); // 0.5s sleep
                    $response = $api->nomenclature('series', '', ['type' => 'Proforma']);
                    $series_proforma = $response['data'];
                    
                    // management
                    if ($useStock) {
                        usleep(500000); // 0.5s sleep
                        $response = $api->nomenclature('management', '');
                        foreach ($response['data'] as $item) {
                            if ($workstation === $item['workStation']) {
                                $management[] = ['name' => $item['management']];
                            }
                            $workStations[$item['workStation']] = ['name' => $item['workStation']];
                        }
                    }
                }
                
                $fields[] = array(
                    'type' => 'select',
                    'label' => __('Serie factura', 'woocommerce-oblio'),
                    'name' => 'oblio_series_name',
                    'options' => [
                        'query' => array_merge([['name' => 'Selecteaza']], $series),
                        'id'    => 'name',
                        'name'  => 'name',
                    ],
                    'class' => 'chosen',
                    // 'lang' => true,
                    'required' => true
                );
                $fields[] = array(
                    'type' => 'select',
                    'label' => __('Serie proforma', 'woocommerce-oblio'),
                    'name' => 'oblio_series_name_proforma',
                    'options' => [
                        'query' => array_merge([['name' => 'Selecteaza']], $series_proforma),
                        'id'    => 'name',
                        'name'  => 'name',
                    ],
                    'class' => 'chosen',
                    // 'lang' => true,
                    'required' => true
                );
                $fields[] = array(
                    'type' => 'select',
                    'label' => __('Punct de lucru', 'woocommerce-oblio'),
                    'name' => 'oblio_workstation',
                    'options' => [
                        'query' => array_merge([['name' => 'Selecteaza']], $workStations),
                        'id'    => 'name',
                        'name'  => 'name',
                    ],
                    'class' => 'chosen',
                    // 'lang' => true,
                    // 'required' => true
                );
                $fields[] = array(
                    'type' => 'select',
                    'label' => __('Gestiune', 'woocommerce-oblio'),
                    'name' => 'oblio_management',
                    'options' => [
                        'query' => array_merge([['name' => 'Selecteaza']], $management),
                        'id'    => 'name',
                        'name'  => 'name',
                    ],
                    'class' => 'chosen',
                    // 'lang' => true,
                    // 'required' => true
                );
            }
        } catch (Exception $e) {
            $error = $e->getMessage();
            $accessTokenHandler->clear();
        }
    }
    
    $showData = function($option, $data = array()) {
        $result = '';
        if (isset($data) && is_array($data)) {
            foreach ($data as $key=>$value) {
                if (isset($option[$value])) {
                    $result .= sprintf(' data-%s="%s"', $key, esc_attr($option[$value]));
                }
            }
        }
        return $result;
    };
    include WP_OBLIO_DIR . '/view/settings_page.php';
}

function _wp_oblio_order_details_box() {
    add_meta_box(
        'oblio_order_details_box',
        __('Facturare Oblio', 'woocommerce-oblio'),
        '_wp_oblio_order_details_invoice_box',
        ['shop_order', 'woocommerce_page_wc-orders'],
        'side',
        'high'
    );
}

function _wp_oblio_order_details_invoice_box($post) {
    global $wpdb;
    include WP_OBLIO_DIR . '/view/components/order_details_invoice_box.php';
}

function _wp_oblio_help() {
    header('Location: https://www.oblio.eu/integrari/woocommerce', true, 301);
    exit;
}

/**
 * Add account tab section
 */

add_action('init', '_wp_oblio_register_invoices_endpoint');

function _wp_oblio_register_invoices_endpoint() {
	add_rewrite_endpoint('oblio-invoices', EP_ROOT | EP_PAGES);
}

add_filter('query_vars', '_wp_oblio_invoices_query_vars');

function _wp_oblio_invoices_query_vars($vars) {
	$vars[] = 'oblio-invoices';
	return $vars;
}

add_filter('woocommerce_account_menu_items', '_wp_oblio_add_tab');

/**
 *  Add this stylesheet:
 *  .woocommerce-MyAccount-navigation ul li.woocommerce-MyAccount-navigation-link--oblio-invoices a::before {
 *     content: "\f570";
 *  }
 */
function _wp_oblio_add_tab($items) {
    $result = array_splice($items, count($items) - 1);
	$items['oblio-invoices'] = __('Facturi', 'woocommerce-oblio');
    $items = array_merge($items, $result);
	return $items;
}

add_action('woocommerce_account_oblio-invoices_endpoint', '_wp_oblio_add_content');

function _wp_oblio_add_content() {
    global $wpdb;

    $auth_id = get_current_user_id();

    $field_name = 'post_id';
    $order_table = $wpdb->posts;
    $order_meta_table = $wpdb->postmeta;
    $clientCondition = "JOIN {$order_meta_table} pm ON(pm.{$field_name}=p.ID AND pm.meta_key='_customer_user' AND pm.meta_value={$auth_id}) ";

    $performance_tables_active = get_option('woocommerce_custom_orders_table_enabled');
    if ($performance_tables_active === 'yes') {
        $field_name = 'order_id';
        $order_table = OrdersTableDataStore::get_orders_table_name();
        $order_meta_table = OrdersTableDataStore::get_meta_table_name();
        $clientCondition = "WHERE p.customer_id={$auth_id} ";
    }

    $sql = "SELECT p.ID, pmol.meta_value AS link, pmos.meta_value AS series_name, pmon.meta_value AS number " .
        "FROM {$order_table} p " .
        "JOIN {$order_meta_table} pmol ON(pmol.{$field_name}=p.ID AND pmol.meta_key='oblio_invoice_link') " .
        "JOIN {$order_meta_table} pmon ON(pmon.{$field_name}=p.ID AND pmon.meta_key='oblio_invoice_number') " .
        "JOIN {$order_meta_table} pmos ON(pmos.{$field_name}=p.ID AND pmos.meta_key='oblio_invoice_series_name' AND pmol.meta_value<>'') " .
        $clientCondition;
    $invoices = $wpdb->get_results($sql);
    include WP_OBLIO_DIR . '/view/account_invoices_page.php';
} 