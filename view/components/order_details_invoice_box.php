
<div class="oblio-ajax-response"></div>
<?php
use Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore;

$order = new OblioSoftware\Order($post->ID);
if ((int) get_option('oblio_gen_date') === 2) {
    $date        = $order->get_date_created();
    $invoiceDate = $date ? $date->format('Y-m-d') : date('Y-m-d');
} else {
    $invoiceDate = date('Y-m-d');
}
$invoiceDateClass = '';
if ($order->get_data_info('oblio_invoice_link')) {
    $invoiceDateClass = 'hidden';
}
?>
<input type="date" id="oblio_invoice_date" class="<?php echo $invoiceDateClass; ?>" value="<?php echo $invoiceDate; ?>" />
<?php
$displayDocument = function($post, $options = []) use ($wpdb, $order) {
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
    
    $series_name = $order->get_data_info($series_name_key);
    $number      = $order->get_data_info($number_key);
    $link        = $order->get_data_info($link_key);
    
    $order_meta_table = OblioSoftware\Order::get_meta_table_name();
    $field_name = OblioSoftware\Order::get_meta_table_field_name();
    $sql = "SELECT {$field_name} FROM `{$order_meta_table}` WHERE meta_key='{$number_key}' AND meta_value<>'' ORDER BY `meta_value` DESC LIMIT 1";
    $lastInvoice = $wpdb->get_var($sql);
    if ($link) {
        echo sprintf('<p><a class="button" href="%s" target="_blank">%s</a></p>',
            _wp_oblio_build_url('oblio-view-' . $options['docType'], $post), sprintf(__('Vezi %s %s %d', 'woocommerce-oblio'), $options['name'], $series_name, $number));
        
        parse_str(parse_url($link, PHP_URL_QUERY), $output);
        $viewLink = 'https://www.oblio.eu/docs/preview/' . $options['docType'] . '/' . $output['id'];
        echo sprintf('<p><a class="button" href="%s" target="_blank">%s</a></p>',
            $viewLink, sprintf(__('Editeaza %s %s %d', 'woocommerce-oblio'), $options['name'], $series_name, $number));
    } else {
        echo sprintf('<p><a class="button oblio-generate-%s" href="%s" target="_blank">%s</a></p>', 
            $options['docType'], _wp_oblio_build_url('oblio-generate-' . $options['docType'] . '-stock', $post), __('Emite ' . $options['name'], 'woocommerce-oblio'));
        if ($options['docType'] === 'invoice' && get_option('oblio_use_stock') === '1') {
            echo sprintf('<p><a class="button oblio-generate-%s" href="%s" target="_blank">%s</a></p>', 
                $options['docType'], _wp_oblio_build_url('oblio-generate-' . $options['docType'], $post), __('Emite ' . $options['name'] . ' fara Descarcare', 'woocommerce-oblio'));
        }
    }
    if (!$link || $lastInvoice == $post->ID || $options['docType'] === 'proforma') {
        $hidden = $link ? '' : 'hidden';
        echo sprintf('<p><a class="button oblio-delete-' . $options['docType'] . ' %s" href="%s" target="_blank">%s</a></p>',
            $hidden, _wp_oblio_build_url('oblio-delete-' . $options['docType'], $post), __('Sterge ' . $options['name'], 'woocommerce-oblio'));
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
        buttons.on('click', function(e) {
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
                            .hide();
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
        deleteButton.on('click', function(e) {
            e.preventDefault();
            var self = $(this);
            if (self.hasClass('disabled')) {
                return false;
            }
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