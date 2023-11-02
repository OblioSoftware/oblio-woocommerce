<?php

namespace OblioSoftware;

use WC_Order;
use Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore;

class Order extends WC_Order {
    public function get_data_info($key_name) {
        $data = get_post_meta($this->id, $key_name, true);

        if (empty($data) && method_exists($this, 'get_meta')) {
            return $this->get_meta($key_name);
        }

        return $data;
    }

    public function set_data_info($key_name, $value) {
        if (method_exists($this, 'update_meta_data')) {
            $this->add_meta_data($key_name, $value, true);
            return;
        }

        update_post_meta($this->id, $key_name, $value);
    }

    public function get_data_info_array() {
        $data = [];
        $temp = $this->get_meta_data();
        foreach ($temp as $tmp) {
            if (!isset($data[$tmp->key])) {
                $data[$tmp->key] = [];
            }
            $data[$tmp->key][] = $tmp->value;
        }

        $data = array_merge($data, get_post_meta($this->id));

        return $data;
    }

    public static function get_order_by_proforma($seriesName, $number) {
        global $wpdb;
        if ($seriesName === '' || $number === '') {
            return null;
        }

        $field_name = 'post_id';
        $order_meta_table = OrdersTableDataStore::get_meta_table_name();
        $performance_tables_active = get_option('woocommerce_custom_orders_table_enabled');
        if ($performance_tables_active === 'yes') {
            $field_name = 'order_id';
        }

        $seriesName = esc_sql($seriesName);
        $number     = esc_sql($number);
        $sql = "SELECT pma.{$field_name} FROM `{$order_meta_table}` pma " .
            "JOIN `{$order_meta_table}` pmb ON(pma.{$field_name}=pmb.{$field_name} AND pma.meta_key='oblio_proforma_series_name' AND pmb.meta_key='oblio_proforma_number') " .
            "WHERE pma.meta_value='{$seriesName}' AND pmb.meta_value='{$number}' " .
            "LIMIT 1";
        $result = $wpdb->get_row($sql);
        if ($result === null) {
            return null;
        }
        return new self($result->post_id);
    }

    public static function get_last_invoiced_order_id($type) {
        global $wpdb;

        $order_meta_table = self::get_meta_table_name();
        $order_meta_field = self::get_meta_table_field_name();
        $number_key = 'oblio_' . $type . '_number';

        $sql = "SELECT {$order_meta_field} FROM `{$order_meta_table}` WHERE meta_key='{$number_key}' AND meta_value<>'' ORDER BY `meta_value` DESC LIMIT 1";
        return $wpdb->get_var($sql);
    }

    public static function get_meta_table_name() {
        global $wpdb;
        return self::custom_tables_enabled() ? OrdersTableDataStore::get_meta_table_name() : $wpdb->postmeta;
    }

    public static function get_meta_table_field_name() {
        return self::custom_tables_enabled() ? 'order_id' : 'post_id';
    }

    public static function custom_tables_enabled() {
        return get_option('woocommerce_custom_orders_table_enabled') === 'yes';
    }
}