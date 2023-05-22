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
define('WP_OBLIO_DIR', untrailingslashit(plugin_dir_path(__FILE__)));

defined('ABSPATH') || exit;

add_action('admin_menu', '_wp_oblio_load_plugin');
add_action('oblio_sync_schedule', '_wp_oblio_sync');

add_action('init', '_oblio_init');
function _oblio_init() {
    add_action( 'woocommerce_order_status_changed', '_wp_oblio_status_complete', 99, 3 );
    add_action( 'woocommerce_payment_complete', '_wp_oblio_payment_complete' );
}

function _wp_oblio_payment_complete($order_id) {
    _wp_oblio_load_plugin();
    $oblio_invoice_autogen = (int) get_option('oblio_invoice_autogen');
    $oblio_invoice_autogen_use_stock = (int) get_option('oblio_invoice_autogen_use_stock');
    if ($oblio_invoice_autogen === 1) {
        _wp_oblio_generate_invoice( $order_id, ['use_stock' => $oblio_invoice_autogen_use_stock] );
    }
}

// ajax
add_action('wp_ajax_oblio', '_wp_oblio_ajax_handler');

// add custom field
function _oblio_cfwc_create() {
    $args = array(
        'id'            => 'custom_package_number',
        'label'         => __( 'Bucati pe pachet', 'cfwc' ),
        'class'         => 'cfwc-custom-field',
        // 'desc_tip'      => false,
        // 'description'   => __( 'Enter the title of your custom text field.', 'ctwc' ),
    );
    woocommerce_wp_text_input($args);
    
    $args = array(
        'id'            => 'custom_product_type',
        'label'         => __( 'Tip produse', 'cfwc' ),
        'class'         => 'select short cfwc-custom-field',
        'options'       => ['' => 'Valoare implicita'] + _wp_oblio_get_products_type(),
        // 'desc_tip'      => false,
        // 'description'   => __( 'Enter the title of your custom text field.', 'ctwc' ),
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

register_deactivation_hook(__FILE__, '_wp_oblio_clear');

function _wp_oblio_clear() {
    wp_clear_scheduled_hook('oblio_sync_schedule');
}

function _wp_oblio_load_plugin() {
    if (!class_exists('WooCommerce')) {
        return;
    }
    
    //create new top-level menu
    add_menu_page('Oblio', 'Oblio', 'manage_options', 'oblio-plugin', null, plugins_url('/assets/images/icon.png', __FILE__) );
    add_submenu_page('oblio-plugin', 'Conectare', 'Conectare', 'manage_options', 'oblio-plugin', '_wp_oblio_login_page');
    add_submenu_page('oblio-plugin', 'Setari Oblio', 'Setari', 'manage_options', 'oblio-settings', '_wp_oblio_settings_page');
    add_submenu_page('oblio-plugin', 'Sincronizare Manuala', 'Sincronizare Manuala', 'manage_options', 'oblio-import', '_wp_oblio_import_page');
    add_submenu_page('oblio-plugin', 'Ajutor', 'Ajutor', 'manage_options', 'oblio-help', '_wp_oblio_help');

    //call register settings function
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
add_filter( 'bulk_actions-edit-shop_order', 'register_oblio_bulk_actions', 10 );

function register_oblio_bulk_actions($bulk_actions) {
    $bulk_actions['oblio_bulk_action'] = __( 'Genereaza factura in Oblio', 'oblio_bulk_action');
    return $bulk_actions;
}

add_filter( 'handle_bulk_actions-edit-shop_order', 'oblio_bulk_action_handler', 10, 3 );

function oblio_bulk_action_handler( $redirect_to, $doaction, $post_ids ) {
    if ( $doaction !== 'oblio_bulk_action' ) {
        return $redirect_to;
    }
    $oblio_invoice_autogen_use_stock = (int) get_option('oblio_invoice_autogen_use_stock');
    sort($post_ids, SORT_NUMERIC);
    foreach ( $post_ids as $post_id ) {
        $link = get_post_meta($post_id, 'oblio_invoice_link', true);
        if (empty($link)) {
            $result = _wp_oblio_generate_invoice( $post_id, ['use_stock' => $oblio_invoice_autogen_use_stock] );
        }
    }
    $redirect_to = add_query_arg( 'oblio_bulk_posts', count( $post_ids ), $redirect_to );
    return $redirect_to;
}

function _wp_register_oblio_plugin_settings() {
    //register our settings
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
}

// update
if ( OBLIO_AUTO_UPDATE ) {
    add_filter('plugins_api', '_oblio_plugin_info', 20, 3);

    function _oblio_plugin_info( $res, $action, $args ) {
        if( 'plugin_information' !== $action ) {
            return false;
        }
        if ( $args->slug !== 'woocommerce-oblio' ) {
            return $res;
        }
        
        // trying to get from cache first
        if( false == $remote = get_transient( 'oblio_update' ) ) {
            $remote = wp_remote_get( 'https://obliosoftware.github.io/builds/woocommerce/info.json', array(
                'timeout' => 10,
                'headers' => array(
                    'Accept' => 'application/json'
                )
            ));
     
            if ( ! is_wp_error( $remote ) && isset( $remote['response']['code'] ) && $remote['response']['code'] == 200 && ! empty( $remote['body'] ) ) {
                set_transient( 'oblio_update', $remote, 43200 ); // 12 hours cache
            }
        }
        
        if( ! is_wp_error( $remote ) && isset( $remote['response']['code'] ) && $remote['response']['code'] == 200 && ! empty( $remote['body'] ) ) {
     
            $remote = json_decode( $remote['body'] );
            $res = new stdClass();
     
            $res->name = $remote->name;
            $res->slug = 'woocommerce-oblio';
            $res->version = $remote->version;
            $res->tested = $remote->tested;
            $res->requires = $remote->requires;
            $res->author = '<a href="https://www.oblio.eu">Oblio Software</a>';
            $res->author_profile = 'https://www.oblio.eu';
            $res->download_link = $remote->download_url;
            $res->trunk = $remote->download_url;
            $res->requires_php = $remote->requires_php;
            $res->last_updated = $remote->last_updated;
            $res->sections = array(
                'description' => $remote->sections->description,
                'installation' => $remote->sections->installation,
                'changelog' => $remote->sections->changelog
                // you can add your custom sections (tabs) here
            );
            
            if( !empty( $remote->sections->screenshots ) ) {
                $res->sections['screenshots'] = $remote->sections->screenshots;
            }
     
            return $res;
        }
     
        return false;
    }

    add_filter('site_transient_update_plugins', '_oblio_push_update' );

    function _oblio_push_update( $transient ) {
        if ( empty($transient->checked ) ) {
            return $transient;
        }
        
        if( false == $remote = get_transient( 'oblio_update' ) ) {
     
            // info.json is the file with the actual plugin information on your server
            $remote = wp_remote_get( 'https://obliosoftware.github.io/builds/woocommerce/info.json', array(
                'timeout' => 10,
                'headers' => array(
                    'Accept' => 'application/json'
                ) )
            );
            
            if ( is_wp_error( $remote ) ) {
                return $transient;
            }
            
            if ( !is_wp_error( $remote ) && isset( $remote['response']['code'] ) && $remote['response']['code'] == 200 && !empty( $remote['body'] ) ) {
                set_transient( 'oblio_update', $remote, 43200 ); // 12 hours cache
            }
        }
        
        if( $remote ) {
            $remote = json_decode( $remote['body'] );
            // your installed plugin version should be on the line below! You can obtain it dynamically of course
            if( $remote && version_compare( OBLIO_VERSION, $remote->version, '<' ) && version_compare($remote->requires, get_bloginfo('version'), '<' ) ) {
                $res = new stdClass();
                $res->slug = 'woocommerce-oblio';
                $res->url = $remote->url;
                $res->plugin = 'woocommerce-oblio/woocommerce-oblio.php';
                $res->new_version = $remote->version;
                $res->tested = $remote->tested;
                $res->package = $remote->download_url;
                $res->icons = (array) $remote->icons;
                $res->banners = (array) $remote->banners;
                $transient->response[$res->plugin] = $res;
                $transient->checked[$res->plugin] = $remote->version;
            }
        }
        return $transient;
    }
}

function _wp_oblio_login_page() {
    include 'view/login_page.php';
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
    include 'view/import_page.php';
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
            require_once WP_OBLIO_DIR . '/includes/OblioApi.php';
            require_once WP_OBLIO_DIR . '/includes/AccessTokenHandler.php';
            
            $accessTokenHandler = new AccessTokenHandler();
            $api = new OblioApi($email, $secret, $accessTokenHandler);
            
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
                        //'lang' => true,
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
                    //'lang' => true,
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
                    //'lang' => true,
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
                    //'lang' => true,
                    //'required' => true
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
                    //'lang' => true,
                    //'required' => true
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
    include 'view/settings_page.php';
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

function _wp_oblio_order_details_invoice_box($post) {
    global $wpdb;
    include 'view/components/order_details_invoice_box.php';
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
        
        $getProductDescription = function($item) {
            $itemmeta = $item->get_meta_data();
            $description = '';
            foreach ($itemmeta as $value) {
                if ('_reduced_stock' === $value->key || substr($value->key, 0, 8) === 'pa_alege') {
                    continue;
                }
                if (is_array($value->value)) {
                    foreach ($value->value as $option) {
                        if (!isset($option['value'])) {
                            continue;
                        }
                        $description .= $option['name'] . ': ' . $option['value'] . "\n";
                    }
                } else {
                    $description .= $value->key . ': ' . $value->value . "\n";
                }
            }
            return $description;
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
                'description'               => '', // $getProductDescription($item),
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
            $data['products'][] = [
                'name'                      => 'Alte taxe',
                'code'                      => '',
                'description'               => '',
                'price'                     => number_format($order->get_total() - $total, 2, '.', ''),
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
        require_once WP_OBLIO_DIR . '/includes/OblioApi.php';
        require_once WP_OBLIO_DIR . '/includes/AccessTokenHandler.php';
        
        $accessTokenHandler = new AccessTokenHandler();
        $api = new OblioApi($email, $secret, $accessTokenHandler);
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

function _wp_oblio_help() {
    header( 'Location: https://www.oblio.eu/integrari/woocommerce', true, 301 );
    exit;
}