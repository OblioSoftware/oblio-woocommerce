<?php


add_action('admin_menu', '_wp_oblio_load_plugin');
add_action('oblio_sync_schedule', '_wp_oblio_sync');

add_action('init', '_oblio_init');
function _oblio_init() {
    add_action('woocommerce_order_status_changed', '_wp_oblio_status_complete', 99, 3);
    add_action('woocommerce_payment_complete', '_wp_oblio_payment_complete');
}

function _wp_oblio_payment_complete($order_id) {
    _wp_oblio_load_plugin();
    $oblio_invoice_autogen = (int) get_option('oblio_invoice_autogen');
    $oblio_invoice_autogen_use_stock = (int) get_option('oblio_invoice_autogen_use_stock');
    if ($oblio_invoice_autogen === 1) {
        _wp_oblio_generate_invoice($order_id, ['use_stock' => $oblio_invoice_autogen_use_stock]);
    }
}

// ajax
add_action('wp_ajax_oblio', '_wp_oblio_ajax_handler');

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

// add custom field
function _oblio_cfwc_create() {
    $args = array(
        'id'            => 'custom_package_number',
        'label'         => __('Bucati pe pachet', 'cfwc'),
        'class'         => 'cfwc-custom-field',
        // 'desc_tip'      => false,
        // 'description'   => __('Enter the title of your custom text field.', 'ctwc'),
    );
    woocommerce_wp_text_input($args);
    
    $args = array(
        'id'            => 'custom_product_type',
        'label'         => __('Tip produse', 'cfwc'),
        'class'         => 'select short cfwc-custom-field',
        'options'       => ['' => 'Valoare implicita'] + _wp_oblio_get_products_type(),
        // 'desc_tip'      => false,
        // 'description'   => __('Enter the title of your custom text field.', 'ctwc'),
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
        'label'         => __('Bucati pe pachet', 'woocommerce'),
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
    add_submenu_page('oblio-plugin', 'Conectare', 'Conectare', 'manage_options', 'oblio-plugin', '_wp_oblio_login_page');
    add_submenu_page('oblio-plugin', 'Setari Oblio', 'Setari', 'manage_options', 'oblio-settings', '_wp_oblio_settings_page');
    add_submenu_page('oblio-plugin', 'Sincronizare Manuala', 'Sincronizare Manuala', 'manage_options', 'oblio-import', '_wp_oblio_import_page');
    add_submenu_page('oblio-plugin', 'Ajutor', 'Ajutor', 'manage_options', 'oblio-help', '_wp_oblio_help');

    // call register settings function
    add_action('admin_init', '_wp_register_oblio_plugin_settings');
    
    $email   = get_option('oblio_email');
    $secret  = get_option('oblio_api_secret');
    
    if (!$email || !$secret) {
        return;
    }
    
    add_filter('manage_edit-shop_order_columns', '_wp_oblio_add_invoice_column', 10);
    add_action('manage_shop_order_posts_custom_column', '_wp_oblio_add_invoice_column_content', 10);
    
    add_action('add_meta_boxes', '_wp_oblio_order_details_box');
    
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

add_action('woocommerce_thankyou', '_wp_oblio_new_order', 1000);
function _wp_oblio_new_order($order_id) {
    $series_name  = get_post_meta($order_id, 'oblio_invoice_series_name', true);
    $number       = get_post_meta($order_id, 'oblio_invoice_number', true);
    $link         = get_post_meta($order_id, 'oblio_invoice_link', true);
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

function register_oblio_bulk_actions($bulk_actions) {
    $bulk_actions['oblio_bulk_action'] = __('Genereaza factura in Oblio', 'oblio_bulk_action');
    return $bulk_actions;
}

add_filter('handle_bulk_actions-edit-shop_order', 'oblio_bulk_action_handler', 10, 3);

function oblio_bulk_action_handler($redirect_to, $doaction, $post_ids) {
    if ($doaction !== 'oblio_bulk_action') {
        return $redirect_to;
    }
    $oblio_invoice_autogen_use_stock = (int) get_option('oblio_invoice_autogen_use_stock');
    sort($post_ids, SORT_NUMERIC);
    foreach ($post_ids as $post_id) {
        $link = get_post_meta($post_id, 'oblio_invoice_link', true);
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
}

function _wp_oblio_status_complete($order_id, $old_status, $new_status) {
    if (!$order_id) {
        return;
    }
    if ($old_status != 'completed' && $new_status == 'completed') {
        $oblio_invoice_autogen = get_option('oblio_invoice_autogen');
        $oblio_invoice_autogen_use_stock = (int) get_option('oblio_invoice_autogen_use_stock');
        if ($oblio_invoice_autogen == '1') {
            _wp_oblio_generate_invoice($order_id, ['use_stock' => $oblio_invoice_autogen_use_stock]);
        }
    }
}

function _wp_oblio_add_invoice_column($columns) {
    $columns['oblio_invoice'] = 'Oblio';
    return $columns;
}

function _wp_oblio_add_invoice_column_content($column) {
    global $post;
    switch ($column) {
        case 'oblio_invoice':
            $series_name = get_post_meta($post->ID, 'oblio_invoice_series_name', true);
            $number      = get_post_meta($post->ID, 'oblio_invoice_number', true);
            $link        = get_post_meta($post->ID, 'oblio_invoice_link', true);

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
        echo '<h1>Introdu Email si API Secret in sectiunea "Oblio"</h1>';
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
        echo '<h1>Introdu Email si API Secret in sectiunea "Oblio"</h1>';
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
                        'label' => 'Companie',
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
                    'label' => 'Serie factura',
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
                    'label' => 'Serie proforma',
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
                    'label' => 'Punct de lucru',
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
                    'label' => 'Gestiune',
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
        'Facturare Oblio',
        '_wp_oblio_order_details_invoice_box',
        'shop_order',
        'side',
        'high'
    );
    
    if (isset($_GET['a'])) {
        $result = array();
        $date = isset($_GET['date']) ? $_GET['date'] : '';
        switch ($_GET['a']) {
            case 'oblio-generate-invoice': $result = _wp_oblio_generate_invoice($_GET['post'], ['date' => $date]); break; 
            case 'oblio-generate-invoice-stock': $result = _wp_oblio_generate_invoice($_GET['post'], ['use_stock' => true, 'date' => $date]); break;
            case 'oblio-generate-proforma-stock': $result = _wp_oblio_generate_invoice($_GET['post'], ['docType' => 'proforma', 'date' => $date]); break;
            case 'oblio-view-invoice': die(_wp_oblio_generate_invoice($_GET['post'], ['redirect' => true, 'date' => $date])); break; 
            case 'oblio-view-proforma': die(_wp_oblio_generate_invoice($_GET['post'], ['redirect' => true, 'docType' => 'proforma', 'date' => $date])); break; 
            case 'oblio-delete-invoice': $result = _wp_oblio_delete_invoice($_GET['post']); break;
            case 'oblio-delete-proforma': $result = _wp_oblio_delete_invoice($_GET['post'], ['docType' => 'proforma']); break;
        }
        die(json_encode($result));
    }
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

function _wp_oblio_add_tab($items) {
	$items['oblio-invoices'] = 'Facturi';
	return $items;
}

add_action('woocommerce_account_oblio-invoices_endpoint', '_wp_oblio_add_content');

function _wp_oblio_add_content() {
    global $wpdb;

    $auth_id = get_current_user_id();

    $sql = "SELECT ID, pmol.meta_value AS link, pmos.meta_value AS series_name, pmon.meta_value AS number " .
        "FROM {$wpdb->posts} p " .
        "JOIN {$wpdb->postmeta} pm ON(pm.post_id=p.ID AND pm.meta_key='_customer_user' AND pm.meta_value={$auth_id}) " .
        "JOIN {$wpdb->postmeta} pmol ON(pmol.post_id=p.ID AND pmol.meta_key='oblio_invoice_link' AND pmol.meta_value<>'') " .
        "JOIN {$wpdb->postmeta} pmon ON(pmon.post_id=p.ID AND pmon.meta_key='oblio_invoice_number') " .
        "JOIN {$wpdb->postmeta} pmos ON(pmos.post_id=p.ID AND pmos.meta_key='oblio_invoice_series_name') ";
    $invoices = $wpdb->get_results($sql);
    include WP_OBLIO_DIR . '/view/account_invoices_page.php';
}