<?php
/**
 * Plugin Name: WooCommerce Oblio
 * Plugin URI: https://www.oblio.eu
 * Description: API implementation for oblio.eu
 * Version: 1.0.30
 * Author: Oblio Software
 * Author URI: https://www.oblio.eu
 * Text Domain: woocommerce-oblio
 *
 */

define( 'OBLIO_VERSION', '1.0.30' );
define( 'OBLIO_AUTO_UPDATE', true );

defined( 'ABSPATH' ) || exit;

/**
 * Load WooCommerce Oblio plugin.
 */
if (!defined('WP_OBLIO_DIR')) {
    define('WP_OBLIO_DIR', untrailingslashit(plugin_dir_path(__FILE__ )));
}

add_action('admin_menu', '_wp_oblio_load_plugin');
// add_action('wp_ajax_woocommerce_mark_order_status', '_wp_oblio_load_plugin');
add_action('oblio_sync_schedule', '_wp_oblio_sync');

add_action('init', '_oblio_init');
function _oblio_init() {
    add_action( 'woocommerce_order_status_changed', '_wp_oblio_status_complete', 99, 3 );
    add_action( 'woocommerce_payment_complete', '_wp_oblio_payment_complete' );
}

function _wp_oblio_payment_complete( $order_id ) {
    _wp_oblio_load_plugin();
    $oblio_invoice_autogen = get_option('oblio_invoice_autogen');
    $oblio_invoice_autogen_use_stock = (int) get_option('oblio_invoice_autogen_use_stock');
    if ( $oblio_invoice_autogen == '1' ) {
        _wp_oblio_generate_invoice( $order_id, ['use_stock' => $oblio_invoice_autogen_use_stock] );
    }
}
// ajax
add_action( 'wp_ajax_oblio', '_wp_oblio_ajax_handler' );

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
    // add_submenu_page('oblio-plugin', 'Ajutor', 'Ajutor', 'manage_options', 'oblio-help', '_wp_oblio_help');

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
            usleep(200000);
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
            $remote = wp_remote_get( 'https://www.oblio.eu/download/wp_info.json', array(
                'timeout' => 10,
                'headers' => array(
                    'Accept' => 'application/json'
                ) )
            );
     
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
            $remote = wp_remote_get( 'https://www.oblio.eu/download/wp_info.json', array(
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
?>
<div class="wrap woocommerce">
    <h1><?php esc_html_e('Conectare cu Oblio', 'woocommerce-oblio'); ?></h1>
    
    <p>Woocommerce se conecteaza cu Oblio folosind datele de conectare de mai jos:</p>

    <form method="post" action="options.php">
        <?php settings_fields('oblio-plugin-login-group'); ?>
        <?php do_settings_sections('oblio-plugin-login-group'); ?>
        <table class="form-table">
            <tr valign="top" class="form-field">
                <th scope="row"><?php esc_html_e('Email', 'woocommerce-oblio'); ?></th>
                <td>
                    <input type="text" name="oblio_email" value="<?php echo esc_attr(get_option('oblio_email')); ?>" />
                    <p class="description"><?php esc_html_e('Email-ul cu care te autentifici pe oblio.eu', 'woocommerce-oblio'); ?></p>
                </td>
            </tr>
             
            <tr valign="top" class="form-field">
                <th scope="row"><?php esc_html_e('API secret', 'woocommerce-oblio'); ?></th>
                <td>
                    <input type="text" name="oblio_api_secret" value="<?php echo esc_attr(get_option('oblio_api_secret')); ?>" />
                    <p class="description"><?php echo sprintf('API-ul secret poate fi gasit in <b>Oblio &gt; Contul meu &gt; Setari &gt; Date cont</b> sau %s', '<a href="https://www.oblio.eu/account/settings" target="_blank">direct de aici</a>'); ?></p>
                </td>
            </tr>
        </table>
        
        <?php submit_button(); ?>

    </form>
</div>
<?php
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
?>
<div class="wrap woocommerce">
    <h1><?php esc_html_e('Sincronizare Manuala', 'woocommerce-oblio'); ?></h1>
    <p>Sincronizarea manuala iti permite sa sincronizezi stocul imediat.</p>
    <p>Daca folosesti sincronizarea automata din <b>Oblio > Setari</b> stocul se actualizeaza automat la fiecare ora.</p>
    <?php echo $message; ?>
    <a class="button action" href="admin.php?page=oblio-import&amp;import=1">Sincronizare</a>
</div>
<?php
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
?>
<style type="text/css">
.form-field select {width:95%;}
</style>
<script type="text/javascript">
"use strict";
(function($) {
    $(document).ready(function() {
        var oblio_cui = $('#id_oblio_cui'),
            oblio_series_name = $('#id_oblio_series_name'),
            oblio_series_name_proforma = $('#id_oblio_series_name_proforma'),
            oblio_workstation = $('#id_oblio_workstation'),
            oblio_management = $('#id_oblio_management'),
            useStock = parseInt(oblio_cui.find('option:selected').data('use-stock')) === 1;
        
        showManagement(useStock);
        
        oblio_cui.change(function() {
            var self = $(this),
                data = {
                    action:'oblio',
                    type:'series_name',
                    cui:oblio_cui.val()
                },
                useStock = parseInt(oblio_cui.find('option:selected').data('use-stock')) === 1;

            // series name
            populateOptions(data, oblio_series_name);
            
            // series name proforma
            data.type = 'series_name_proforma';
            populateOptions(data, oblio_series_name_proforma);
            
            if (useStock) {
                data.type = 'workstation';
                populateOptions(data, oblio_workstation);
                populateOptionsRender(oblio_management, [])
            }
            showManagement(useStock);
        });
        oblio_workstation.change(function() {
            var self = $(this),
                data = {
                    action:'oblio',
                    type:'management',
                    name:self.val(),
                    cui:oblio_cui.val()
                };
            populateOptions(data, oblio_management);
        });
        
        function showManagement(useStock) {
            oblio_workstation.parent().parent().toggleClass('hidden', !useStock);
            oblio_management.parent().parent().toggleClass('hidden', !useStock);
        }
        
        function populateOptions(data, element, fn) {
            jQuery.ajax({
                type: 'post',
                dataType: 'json',
                url: ajaxurl,
                data: data,
                success: function(response) {
                    populateOptionsRender(element, response, fn);
                }
            });
        }
        
        function populateOptionsRender(element, data, fn) {
            var options = '<option value="">Selecteaza</option>';
            for (var index in data) {
                var value = data[index];
                options += '<option value="' + value.name + '">' + value.name + '</option>';
            }
            element.html(options);
            if (typeof fn === 'function') {
                fn(data);
            }
        }
    });
})(jQuery);
</script>
<div class="wrap woocommerce">
    <h1><?php esc_html_e('Setari Oblio', 'woocommerce-oblio'); ?></h1>

    <form method="post" action="options.php" id="oblio_configuration_form">
        <?php settings_fields('oblio-plugin-settings-group'); ?>
        <?php do_settings_sections('oblio-plugin-settings-group'); ?>
        <?php if ($error) echo sprintf('<div class="notice notice-error"><p>%s</p></div>', $error); ?>
        <table class="form-table">
        <?php foreach ($fields as $field) { ?>
            <tr valign="top" class="form-field">
                <th scope="row"><?php echo esc_attr($field['label']); ?></th>
                <td>
                    <select name="<?php echo esc_attr($field['name']); ?>" id="id_<?php echo esc_attr($field['name']); ?>">
                    <?php
                        $selectedOption = get_option($field['name']);
                        foreach ($field['options']['query'] as $option) {
                            $isSelected = $option[$field['options']['id']] === $selectedOption;
                            echo sprintf('<option value="%s"%s%s>%s</option>',
                                $option[$field['options']['id']], $isSelected ? ' selected' : '', $showData($option, $field['options']['data']), $option[$field['options']['name']]);
                        }
                    ?>
                    </select>
                </td>
            </tr>
        <?php } ?>
            <tr valign="top" class="form-field">
                <th scope="row"><?php esc_html_e('Genereaza factura automat', 'woocommerce-oblio'); ?></th>
                <td>
                    <?php 
                    $oblio_invoice_autogen = get_option('oblio_invoice_autogen');
                    ?>
                    <input type="checkbox" name="oblio_invoice_autogen"<?php echo $oblio_invoice_autogen == '1' ? ' checked' : ''; ?> value="1" />
                    <p class="description"><?php esc_html_e('Genereaza factura automat la schimbarea statusului comenzii in "Finalizat"', 'woocommerce-oblio'); ?></p>
                </td>
            </tr>
            <tr valign="top" class="form-field">
                <th scope="row"><?php esc_html_e('Descarcare de stoc la factura automata', 'woocommerce-oblio'); ?></th>
                <td>
                    <?php 
                    $oblio_invoice_autogen_use_stock = get_option('oblio_invoice_autogen_use_stock');
                    ?>
                    <input type="checkbox" name="oblio_invoice_autogen_use_stock"<?php echo $oblio_invoice_autogen_use_stock == '1' ? ' checked' : ''; ?> value="1" />
                    <p class="description"><?php esc_html_e('Cand se genereaza factura automat produsele sunt descarcate din stoc', 'woocommerce-oblio'); ?></p>
                </td>
            </tr>
            <tr valign="top" class="form-field">
                <th scope="row"><?php esc_html_e('Genereaza proforma automat', 'woocommerce-oblio'); ?></th>
                <td>
                    <?php 
                    $oblio_proforma_autogen = get_option('oblio_proforma_autogen');
                    ?>
                    <input type="checkbox" name="oblio_proforma_autogen"<?php echo $oblio_proforma_autogen == '1' ? ' checked' : ''; ?> value="1" />
                    <p class="description"><?php esc_html_e('Genereaza proforma automat la plasarea comenzii', 'woocommerce-oblio'); ?></p>
                </td>
            </tr>
            <tr valign="top" class="form-field">
                <th scope="row"><?php esc_html_e('Genereaza documente cu data', 'woocommerce-oblio'); ?></th>
                <td>
                    <?php 
                    $oblio_gen_date = get_option('oblio_gen_date');
                    ?>
                    <select name="oblio_gen_date" id="id_oblio_gen_date">
                      <option value="1">Emiterii</option>
                      <option value="2"<?php echo $oblio_gen_date == '2' ? ' selected' : ''; ?>>Comenzii</option>
                    </select>
                </td>
            </tr>
            <tr valign="top" class="form-field">
                <th scope="row"><?php esc_html_e('Incasare factura automata', 'woocommerce-oblio'); ?></th>
                <td>
                    <?php 
                    $oblio_auto_collect = (int) get_option('oblio_auto_collect');
                    ?>
                    <select name="oblio_auto_collect" id="id_oblio_auto_collect">
                      <option value="0">Nu</option>
                      <option value="1"<?php echo $oblio_auto_collect === 1 ? ' selected' : ''; ?>>Platile prin card</option>
                      <option value="2"<?php echo $oblio_auto_collect === 2 ? ' selected' : ''; ?>>Toate</option>
                    </select>
                </td>
            </tr>
            <tr valign="top" class="form-field">
                <th colspan="2"><h2>Email clienti</h2></th>
            </tr>
            <tr valign="top" class="form-field">
                <th scope="row"><?php esc_html_e('Trimite email la generare factura', 'woocommerce-oblio'); ?></th>
                <td>
                    <?php 
                    $oblio_invoice_gen_send_email = get_option('oblio_invoice_gen_send_email');
                    ?>
                    <input type="checkbox" name="oblio_invoice_gen_send_email"<?php echo $oblio_invoice_gen_send_email == '1' ? ' checked' : ''; ?> value="1" />
                </td>
            </tr>
            <tr valign="top" class="form-field">
                <th scope="row"><?php esc_html_e('De la (optional)', 'woocommerce-oblio'); ?></th>
                <td>
                    <?php 
                    $oblio_invoice_send_email_from = get_option('oblio_invoice_send_email_from');
                    ?>
                    <input type="text" name="oblio_invoice_send_email_from" value="<?php echo esc_attr($oblio_invoice_send_email_from); ?>" placeholder="nume@exemplu.ro" />
                </td>
            </tr>
            <tr valign="top" class="form-field">
                <th scope="row"><?php esc_html_e('Subiect email', 'woocommerce-oblio'); ?></th>
                <td>
                    <?php 
                    $oblio_invoice_send_email_subject = get_option('oblio_invoice_send_email_subject', __('S-a emis [type] [serie] [numar]', 'woocommerce-oblio'));
                    ?>
                    <input type="text" name="oblio_invoice_send_email_subject" value="<?php echo esc_attr($oblio_invoice_send_email_subject); ?>" />
                </td>
            </tr>
            <tr valign="top" class="form-field">
                <th scope="row"><?php esc_html_e('CC', 'woocommerce-oblio'); ?></th>
                <td>
                    <?php 
                    $oblio_invoice_send_email_cc = get_option('oblio_invoice_send_email_cc');
                    ?>
                    <input type="text" name="oblio_invoice_send_email_cc" value="<?php echo esc_attr($oblio_invoice_send_email_cc); ?>" />
                </td>
            </tr>
            <tr valign="top" class="form-field">
                <th scope="row">
                  <?php esc_html_e('Mesaj email', 'woocommerce-oblio'); ?><br>
                  <small>[type] = tip document</small><br>
                  <small>[serie] = serie document</small><br>
                  <small>[numar] = numar document</small><br>
                  <small>[link] = link document</small><br>
                  <small>[issue_date] = data emitere</small><br>
                  <small>[due_date] = data scadenta</small><br>
                  <small>[total] = total document</small><br>
                  <small>[contact_name] = nume de contact</small><br>
                  <small>[client_name] = nume de client</small><br>
                </th>
                <td>
                    <?php 
                    $oblio_invoice_send_email_message = get_option('oblio_invoice_send_email_message', __("Buna ziua,

Va informam ca am emis [type] [serie] [numar] .

Pentru mai multe detalii legate de [type], accesati linkul de mai jos:
[link]

Daca sunt intrebari sau neclaritati, nu ezitati sa ne contactati.

Va multumim.", 'woocommerce-oblio'));
                    ?>
                    <textarea rows="7" name="oblio_invoice_send_email_message"><?php echo esc_attr($oblio_invoice_send_email_message); ?></textarea>
                </td>
            </tr>
            <tr valign="top" class="form-field">
                <th colspan="2"><h2>Sincronizare</h2></th>
            </tr>
            <tr valign="top" class="form-field">
                <th scope="row">
                    <?php esc_html_e('Sincronizare automata cu stocul Oblio', 'woocommerce-oblio'); ?><br>
                    <small>Codul produsului din Oblio trebuie sa fie acelasi cu codul produsului din site-ul dvs.</small>
                </th>
                <td>
                    <?php 
                    $oblio_stock_sync = get_option('oblio_stock_sync');
                    ?>
                    <input type="checkbox" name="oblio_stock_sync"<?php echo $oblio_stock_sync == '1' ? ' checked' : ''; ?> value="1" />
                </td>
            </tr>
            <tr valign="top" class="form-field">
                <th colspan="2"><h2>Optiuni factura</h2></th>
            </tr>
            <tr valign="top" class="form-field">
                <th scope="row"><?php esc_html_e('Limba', 'woocommerce-oblio'); ?></th>
                <td>
                    <?php 
                    $oblio_invoice_language = get_option('oblio_invoice_language');
                    echo '<select name="oblio_invoice_language">';
                    $languages = _wp_oblio_get_languages();
                    foreach ($languages as $lang_code=>$language) {
                        echo sprintf('<option value="%1$s"%3$s>%2$s</option>', $lang_code, $language, $lang_code === $oblio_invoice_language ? ' selected' : '');
                    }
                    echo '</select>';
                    ?>
                </td>
            </tr>
            <tr valign="top" class="form-field">
                <th scope="row"><?php esc_html_e('Unitate de masura', 'woocommerce-oblio'); ?></th>
                <td>
                    <?php 
                    $oblio_invoice_measuring_unit = get_option('oblio_invoice_measuring_unit', 'buc');
                    ?>
                    <input type="text" name="oblio_invoice_measuring_unit" value="<?php echo esc_attr($oblio_invoice_measuring_unit); ?>" />
                </td>
            </tr>
            <tr valign="top" class="form-field">
                <th scope="row"><?php esc_html_e('Unitate de masura tradusa', 'woocommerce-oblio'); ?></th>
                <td>
                    <?php 
                    $oblio_invoice_measuring_unit_translation = get_option('oblio_invoice_measuring_unit_translation', '');
                    ?>
                    <input type="text" name="oblio_invoice_measuring_unit_translation" value="<?php echo esc_attr($oblio_invoice_measuring_unit_translation); ?>" />
                </td>
            </tr>
            <tr valign="top" class="form-field">
                <th scope="row"><?php esc_html_e('Tip produs', 'woocommerce-oblio'); ?></th>
                <td>
                    <?php 
                    $oblio_product_type = get_option('oblio_product_type');
                    echo '<select name="oblio_product_type">';
                    $product_types = _wp_oblio_get_products_type();
                    foreach ($product_types as $product_type) {
                        echo sprintf('<option%2$s>%1$s</option>', $product_type, $product_type === $oblio_product_type ? ' selected' : '');
                    }
                    echo '</select>';
                    ?>
                </td>
            </tr>
            <tr valign="top" class="form-field">
                <th scope="row"><?php esc_html_e('Scadenta', 'woocommerce-oblio'); ?></th>
                <td>
                    <?php 
                    $oblio_invoice_due = get_option('oblio_invoice_due');
                    ?>
                    <input type="text" placeholder="Introdu numarul de zile de scadenta" name="oblio_invoice_due" value="<?php echo esc_attr($oblio_invoice_due); ?>" />
                </td>
            </tr>
            <tr valign="top" class="form-field">
                <th scope="row"><?php esc_html_e('Intocmit de', 'woocommerce-oblio'); ?></th>
                <td>
                    <?php 
                    $oblio_invoice_issuer_name = get_option('oblio_invoice_issuer_name');
                    ?>
                    <input type="text" name="oblio_invoice_issuer_name" value="<?php echo esc_attr($oblio_invoice_issuer_name); ?>" />
                </td>
            </tr>
            <tr valign="top" class="form-field">
                <th scope="row"><?php esc_html_e('CNP', 'woocommerce-oblio'); ?></th>
                <td>
                    <?php 
                    $oblio_invoice_issuer_id = get_option('oblio_invoice_issuer_id');
                    ?>
                    <input type="text" name="oblio_invoice_issuer_id" value="<?php echo esc_attr($oblio_invoice_issuer_id); ?>" />
                </td>
            </tr>
            <tr valign="top" class="form-field">
                <th scope="row"><?php esc_html_e('Delegat', 'woocommerce-oblio'); ?></th>
                <td>
                    <?php 
                    $oblio_invoice_deputy_name = get_option('oblio_invoice_deputy_name');
                    ?>
                    <input type="text" name="oblio_invoice_deputy_name" value="<?php echo esc_attr($oblio_invoice_deputy_name); ?>" />
                </td>
            </tr>
            <tr valign="top" class="form-field">
                <th scope="row"><?php esc_html_e('Carte Identitate', 'woocommerce-oblio'); ?></th>
                <td>
                    <?php 
                    $oblio_invoice_deputy_identity_card = get_option('oblio_invoice_deputy_identity_card');
                    ?>
                    <input type="text" name="oblio_invoice_deputy_identity_card" value="<?php echo esc_attr($oblio_invoice_deputy_identity_card); ?>" />
                </td>
            </tr>
            <tr valign="top" class="form-field">
                <th scope="row"><?php esc_html_e('Auto', 'woocommerce-oblio'); ?></th>
                <td>
                    <?php 
                    $oblio_invoice_deputy_auto = get_option('oblio_invoice_deputy_auto');
                    ?>
                    <input type="text" name="oblio_invoice_deputy_auto" value="<?php echo esc_attr($oblio_invoice_deputy_auto); ?>" />
                </td>
            </tr>
            <tr valign="top" class="form-field">
                <th scope="row"><?php esc_html_e('Agent vanzari', 'woocommerce-oblio'); ?></th>
                <td>
                    <?php 
                    $oblio_invoice_seles_agent = get_option('oblio_invoice_seles_agent');
                    ?>
                    <input type="text" name="oblio_invoice_seles_agent" value="<?php echo esc_attr($oblio_invoice_seles_agent); ?>" />
                </td>
            </tr>
            <tr valign="top" class="form-field">
                <th scope="row">
                  <?php esc_html_e('Mentiuni', 'woocommerce-oblio'); ?><br>
                  <small>[order_id] = numar comanda</small><br>
                  <small>[date] = data comanda</small><br>
                  <small>[payment] = modalitate de plata</small><br>
                </th>
                <td>
                    <?php 
                    $oblio_invoice_mentions = get_option('oblio_invoice_mentions');
                    ?>
                    <textarea rows="7" name="oblio_invoice_mentions"><?php echo esc_attr($oblio_invoice_mentions); ?></textarea>
                </td>
            </tr>
            <tr valign="top" class="form-field">
                <th scope="row"><?php esc_html_e('Include discountul in pretul produsului', 'woocommerce-oblio'); ?></th>
                <td>
                    <?php 
                    $oblio_invoice_discount_in_product = get_option('oblio_invoice_discount_in_product');
                    ?>
                    <input type="checkbox" name="oblio_invoice_discount_in_product"<?php echo $oblio_invoice_discount_in_product == '1' ? ' checked' : ''; ?> value="1" />
                </td>
            </tr>
            
            
        </table>
        
        <?php submit_button(); ?>

    </form>
</div>
<?php
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
    
    echo '<div class="oblio-ajax-response"></div>';
    
    if ((int) get_option('oblio_gen_date') === 2) {
        $order       = new WC_Order($post->ID);
        $date        = $order->get_date_created();
        $invoiceDate = $date ? $date->format('Y-m-d') : date('Y-m-d');
    } else {
        $invoiceDate = date('Y-m-d');
    }
    $invoiceDateClass = '';
    if (get_post_meta($post->ID, 'oblio_invoice_link', true)) {
        $invoiceDateClass = 'hidden';
    }
?>
    <input type="date" id="oblio_invoice_date" class="<?php echo $invoiceDateClass; ?>" value="<?php echo $invoiceDate; ?>" />
<?php
    
    $displayDocument = function($post, $options = []) use ($wpdb) {
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
        
        $series_name = get_post_meta($post->ID, $series_name_key, true);
        $number      = get_post_meta($post->ID, $number_key, true);
        $link        = get_post_meta($post->ID, $link_key, true);
        
        $sql = "SELECT post_id FROM `{$wpdb->postmeta}` WHERE meta_key='{$number_key}' AND meta_value<>'' ORDER BY `meta_value` DESC LIMIT 1";
        $lastInvoice = $wpdb->get_var($sql);
        if ($link) {
            echo sprintf('<p><a class="button" href="%s" target="_blank">%s</a></p>',
                _wp_oblio_build_url('oblio-view-' . $options['docType'] . ''), sprintf(__('Vezi %s %s %d', 'woocommerce-oblio'), $options['name'], $series_name, $number));
            
            parse_str(parse_url($link, PHP_URL_QUERY), $output);
            $viewLink = 'https://www.oblio.eu/docs/preview/' . $options['docType'] . '/' . $output['id'];
            echo sprintf('<p><a class="button" href="%s" target="_blank">%s</a></p>',
                $viewLink, sprintf(__('Editeaza %s %s %d', 'woocommerce-oblio'), $options['name'], $series_name, $number));
        } else {
            echo sprintf('<p><a class="button oblio-generate-%s" href="%s" target="_blank">%s</a></p>', 
                $options['docType'], _wp_oblio_build_url('oblio-generate-' . $options['docType'] . '-stock'), __('Emite ' . $options['name'], 'woocommerce-oblio'));
            if ($options['docType'] === 'invoice' && get_option('oblio_use_stock') === '1') {
                echo sprintf('<p><a class="button oblio-generate-%s" href="%s" target="_blank">%s</a></p>', 
                    $options['docType'], _wp_oblio_build_url('oblio-generate-' . $options['docType'] . ''), __('Emite ' . $options['name'] . ' fara Descarcare', 'woocommerce-oblio'));
            }
        }
        if (!$link || $lastInvoice == $post->ID || $options['docType'] === 'proforma') {
            $hidden = $link ? '' : 'hidden';
            echo sprintf('<p><a class="button oblio-delete-' . $options['docType'] . ' %s" href="%s" target="_blank">%s</a></p>',
                $hidden, _wp_oblio_build_url('oblio-delete-' . $options['docType']), __('Sterge ' . $options['name'], 'woocommerce-oblio'));
        }
        if (isset($options['fn']) && is_callable($options['fn'])) {
            $options['fn']([
                'series_name' => $series_name,
                'number'      => $number,
                'link'        => $link,
            ]);
        }
?>

<script type="text/javascript">
"use strict";
(function($) {
    $(document).ready(function() {
        var buttons = $('.oblio-generate-<?php echo $options['docType']; ?>'),
            deleteButton = $('.oblio-delete-<?php echo $options['docType']; ?>'),
            responseContainer = $('#oblio_order_details_box .oblio-ajax-response');
        buttons.click(function(e) {
            var self = $(this);
            if (self.hasClass('disabled')) {
                return false;
            }
            if (!self.hasClass('oblio-generate-<?php echo $options['docType']; ?>')) {
                return true;
            }
            e.preventDefault();
            self.addClass('disabled');
            jQuery.ajax({
                dataType: 'json',
                url: self.attr('href') + '&date=' + $('#oblio_invoice_date').val(),
                data: {},
                success: function(response) {
                    var alert = '';
                    self.removeClass('disabled');
                    
                    if ('link' in response) {
                        buttons
                            .not(self)
                            .hide()
                        self
                            .attr('href', response.link)
                            .removeClass('oblio-generate-<?php echo $options['docType']; ?>')
                            .text(`Vezi <?php echo $options['docType']; ?> ${response.seriesName} ${response.number}`);
                        alert = '<ul class="order_notes"><li class="note system-note"><div class="note_content note_success"><?php echo strtoupper($options['name']); ?> a fost emisa</div></li></ul>';
                        deleteButton.removeClass('hidden');
                        
                        <?php if ($options['docType'] === 'invoice') { ?>
                        $('#oblio_invoice_date').addClass('hidden');
                        <?php } ?>
                    } else if ('error' in response) {
                        alert = '<ul class="order_notes"><li class="note system-note"><div class="note_content note_error">' + response.error + '</div></li></ul>';
                    }
                    responseContainer.html(alert);
                }
            });
        });
        deleteButton.click(function(e) {
            var self = $(this);
            if (self.hasClass('disabled')) {
                return false;
            }
            e.preventDefault();
            self.addClass('disabled');
            jQuery.ajax({
                dataType: 'json',
                url: self.attr('href'),
                data: {},
                success: function(response) {
                    if (response.type == 'success') {
                        location.reload();
                    } else {
                        var alert = '<ul class="order_notes"><li class="note system-note"><div class="note_content note_error">' + response.message + '</div></li></ul>';
                        responseContainer.html(alert);
                        self.removeClass('disabled');
                    }
                }
            });
        });
    });
})(jQuery);
</script>
<?php
    };
    
    $displayDocument($post, [
        'docType' => 'invoice',
        'name'    => 'factura',
        'fn'      => function($data) use ($displayDocument, $post) {
            if (!$data['link']) {
                $displayDocument($post, [
                    'docType' => 'proforma',
                    'name'    => 'proforma',
                ]);
            }
        }
    ]);
?>
<style type="text/css">
ul.order_notes li.system-note .note_content.note_error {background:#c9356e;color:#fff;}
ul.order_notes li .note_content.note_error::after {border-color: #c9356e transparent;}
ul.order_notes li.system-note .note_content.note_success {background:#46b450;color:#fff;}
ul.order_notes li .note_content.note_success::after {border-color: #46b450 transparent;}
</style>
    <?php
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
        try {
            require_once WP_OBLIO_DIR . '/includes/OblioApi.php';
            require_once WP_OBLIO_DIR . '/includes/AccessTokenHandler.php';
            
            $inv_number         = get_post_meta($order_id, $number_key, true);
            $inv_series_name    = get_post_meta($order_id, $series_name_key, true);
            $accessTokenHandler = new AccessTokenHandler();
            $api = new OblioApi($email, $secret, $accessTokenHandler);
            $api->setCif($cui);
            $response = $api->get($options['docType'], $inv_series_name, $inv_number);
            if (!empty($options['redirect'])) {
                wp_redirect($response['data']['link']);
                die;
            }
            return $response['data'];
        } catch (Exception $e) {
            update_post_meta($post->ID, $series_name_key, '');
            update_post_meta($post->ID, $number_key, '');
            update_post_meta($post->ID, $link_key, '');
            update_post_meta($post->ID, $date_key, '');
        }
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
    if ($auto_collect !== 0 && isset($orderMeta['_payment_method_title'])) {
        $isCard = preg_match('/card/i', $orderMeta['_payment_method_title'][0]) ||
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
            usleep(200000);
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
    header( 'Location: https://www.oblio.eu/info/integrari/woocommerce', true, 301 );
    exit;
}