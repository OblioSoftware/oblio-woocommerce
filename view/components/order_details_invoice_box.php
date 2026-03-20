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
<div class="row g-2 oblio-issuer-deputy-fields" style="margin-bottom:10px;">
	<div class="col-lg-6">
		<div class="" style="position:relative;">
			<label class="form-label" for="intocmit_de"><?php echo esc_html__('Intocmit de', 'woocommerce-oblio'); ?></label>
			<input type="text" class="form-control form-control-sm" name="intocmit_de" id="intocmit_de" value="" autocomplete="off" />
			<input type="hidden" name="intocmit_de_id" id="intocmit_de_id" value="" />
			<div id="oblio_autocomplete_intocmit_de" class="oblio-autocomplete-list" style="display:none;"></div>
		</div>
	</div>
	<div class="col-lg-6">
		<div class="">
			<label class="form-label" for="intocmit_cnp"><?php echo esc_html__('CNP', 'woocommerce-oblio'); ?></label>
			<input type="text" class="form-control form-control-sm" name="intocmit_cnp" id="intocmit_cnp" value="" autocomplete="off" />
		</div>
	</div>
	<div class="col-lg-6">
		<div class="" style="position:relative;">
			<label class="form-label" for="delegat"><?php echo esc_html__('Delegat', 'woocommerce-oblio'); ?></label>
			<input type="text" class="form-control form-control-sm" name="delegat" id="delegat" value="" autocomplete="off" />
			<input type="hidden" name="delegat_id" id="delegat_id" value="" />
			<div id="oblio_autocomplete_delegat" class="oblio-autocomplete-list" style="display:none;"></div>
		</div>
	</div>
	<div class="col-lg-6">
		<div class="">
			<label class="form-label" for="delegat_buletin"><?php echo esc_html__('Carte Identitate', 'woocommerce-oblio'); ?></label>
			<input type="text" class="form-control form-control-sm" name="delegat_buletin" id="delegat_buletin" value="" autocomplete="off" />
		</div>
	</div>
	<div class="col-lg-6">
		<div class="">
			<label class="form-label" for="delegat_auto"><?php echo esc_html__('Auto', 'woocommerce-oblio'); ?></label>
			<input type="text" class="form-control form-control-sm" name="delegat_auto" id="delegat_auto" value="" autocomplete="off" />
		</div>
	</div>
</div>
<input type="date" id="oblio_invoice_date" class="<?php echo $invoiceDateClass; ?>" value="<?php echo $invoiceDate; ?>" />
<?php
$displayDocument = function ($post, $options = []) use ($wpdb, $order) {
	if (empty($options['docType'])) {
		$options['docType'] = 'invoice';
	}

	$series_name_key = 'oblio_' . $options['docType'] . '_series_name';
	$number_key      = 'oblio_' . $options['docType'] . '_number';
	$link_key        = 'oblio_' . $options['docType'] . '_link';

	$series_name = $order->get_data_info($series_name_key);
	$number      = $order->get_data_info($number_key);
	$link        = $order->get_data_info($link_key);

	$order_meta_table = OblioSoftware\Order::get_meta_table_name();
	$order_id_field   = OblioSoftware\Order::get_meta_table_field_name();
	$meta_id_field    = $order_id_field === 'post_id' ? 'meta_id' : 'id';

	$sql = $wpdb->prepare("
        SELECT {$order_id_field}  FROM (
            SELECT pmn.{$order_id_field}, pmn.meta_value
            FROM `{$order_meta_table}` pmn
            JOIN `{$order_meta_table}` pms
                ON pmn.{$order_id_field} = pms.{$order_id_field} 
                AND pmn.meta_key = %s AND pms.meta_key = %s
            WHERE pmn.meta_value <> '' AND pms.meta_value = %s
            ORDER BY pmn.{$meta_id_field} DESC
            LIMIT 100
        ) AS tab
        ORDER BY CAST(meta_value AS UNSIGNED) DESC
        LIMIT 1
    ", $number_key, $series_name_key, $series_name);

	$lastInvoice = $wpdb->get_var($sql);

	if ($link) {
		echo sprintf(
			'<p><a class="button" href="%s" target="_blank">%s</a></p>',
			_wp_oblio_build_url('oblio-view-' . $options['docType'], $post),
			sprintf(__('Vezi %s %s %d', 'woocommerce-oblio'), $options['name'], $series_name, $number)
		);

		parse_str(parse_url($link, PHP_URL_QUERY), $output);
		$viewLink = 'https://www.oblio.eu/docs/preview/' . $options['docType'] . '/' . $output['id'];

		echo sprintf(
			'<p><a class="button" href="%s" target="_blank">%s</a></p>',
			$viewLink,
			sprintf(__('Editeaza %s %s %d', 'woocommerce-oblio'), $options['name'], $series_name, $number)
		);
	} else {
		echo sprintf(
			'<p><a class="button oblio-generate-%s" href="%s" target="_blank">%s</a></p>',
			$options['docType'],
			_wp_oblio_build_url('oblio-generate-' . $options['docType'] . '-stock', $post),
			__('Emite ' . $options['name'], 'woocommerce-oblio')
		);

		if (in_array($options['docType'], ['invoice', 'notice'], true) && intval(get_option('oblio_use_stock')) === 1) {
			echo sprintf(
				'<p><a class="button oblio-generate-%s" href="%s" target="_blank">%s</a></p>',
				$options['docType'],
				_wp_oblio_build_url('oblio-generate-' . $options['docType'], $post),
				__('Emite ' . $options['name'] . ' fara Descarcare', 'woocommerce-oblio')
			);
		}
	}

	if (! $link || $lastInvoice == $post->ID || in_array($options['docType'], ['proforma', 'notice'], true)) {
		$hidden = $link ? '' : 'hidden';
		echo sprintf(
			'<p><a class="button oblio-delete-%s %s" href="%s" target="_blank">%s</a></p>',
			$options['docType'],
			$hidden,
			_wp_oblio_build_url('oblio-delete-' . $options['docType'], $post),
			__('Sterge ' . $options['name'], 'woocommerce-oblio')
		);
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
				// Autocomplete local cu <datalist>, bazat pe oblioAutocomplete (wp_localize_script)
				if (typeof window.oblioAutocomplete === 'object' && window.oblioAutocomplete !== null) {
					var lastSet = (typeof window.oblioAutocomplete.lastSet === 'object' && window.oblioAutocomplete.lastSet !== null)
						? window.oblioAutocomplete.lastSet
						: {};

					var map = {
						issuerName: '#intocmit_de',
						issuerId: '#intocmit_cnp',
						deputyName: '#delegat',
						deputyIdentityCard: '#delegat_buletin',
						deputyAuto: '#delegat_auto'
					};

					Object.keys(map).forEach(function(field) {
						var selector = map[field];
						var $input = $(selector);
						if ($input.length === 0) {
							return;
						}

						var values = window.oblioAutocomplete[field] || [];
						if (!Array.isArray(values) || values.length === 0) {
							return;
						}

						var listId = 'oblio_datalist_' + field;
						var $datalist = $('#' + listId);
						if ($datalist.length === 0) {
							$datalist = $('<datalist></datalist>').attr('id', listId);
							$input.attr('list', listId);
							$input.after($datalist);
						} else {
							$datalist.empty();
						}

						values.forEach(function(val) {
							if (!val) {
								return;
							}
							$('<option></option>').attr('value', val).appendTo($datalist);
						});

						// Prefill with last used value (most recently saved).
						// Only override if input is currently empty (avoid clobbering user edits).
						if (($input.val() === '' || $input.val() === null) && values.length > 0) {
							var lastValue = values[values.length - 1];
							var prefillValue = (lastSet[field] !== undefined && lastSet[field] !== '') ? lastSet[field] : lastValue;
							if (prefillValue) {
								$input.val(prefillValue);
							}
						}
					});
				}

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

					var requestUrl = self.attr('href') + '&date=' + encodeURIComponent($('#oblio_invoice_date').val());
					<?php if (in_array($options['docType'], ['invoice', 'notice'], true)) { ?>
						var issuerName = $.trim($('#intocmit_de').val());
						var issuerId = $.trim($('#intocmit_cnp').val());
						var deputyName = $.trim($('#delegat').val());
						var deputyIdentityCard = $.trim($('#delegat_buletin').val());
						var deputyAuto = $.trim($('#delegat_auto').val());

						if (issuerName !== '') {
							requestUrl += '&issuerName=' + encodeURIComponent(issuerName);
						}
						if (issuerId !== '') {
							requestUrl += '&issuerId=' + encodeURIComponent(issuerId);
						}
						if (deputyName !== '') {
							requestUrl += '&deputyName=' + encodeURIComponent(deputyName);
						}
						if (deputyIdentityCard !== '') {
							requestUrl += '&deputyIdentityCard=' + encodeURIComponent(deputyIdentityCard);
						}
						if (deputyAuto !== '') {
							requestUrl += '&deputyAuto=' + encodeURIComponent(deputyAuto);
						}
					<?php } ?>

					jQuery.ajax({
						dataType: 'json',
						url: requestUrl,
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
	'fn'      => function ($data) use ($displayDocument, $post) {
		if (! $data['link']) {
			$displayDocument($post, [
				'docType' => 'proforma',
				'name'    => 'proforma',
			]);
		}
	}
]);

// Shipping notice (Aviz) – emitted similarly to proforma, but with its own series and payload rules.
$displayDocument($post, [
	'docType' => 'notice',
	'name'    => 'aviz',
]);
?>
<style type="text/css">
	ul.order_notes li.system-note .note_content.note_error {
		background: #c9356e;
		color: #fff;
	}

	ul.order_notes li .note_content.note_error::after {
		border-color: #c9356e transparent;
	}

	ul.order_notes li.system-note .note_content.note_success {
		background: #46b450;
		color: #fff;
	}

	ul.order_notes li .note_content.note_success::after {
		border-color: #46b450 transparent;
	}
</style>