
<div class="oblio-ajax-response"></div>
<?php
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