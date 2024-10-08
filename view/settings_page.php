
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
                                $option[$field['options']['id']], $isSelected ? ' selected' : '',
                                $showData($option, $field['options']['data'] ?? []),
                                $option[$field['options']['name']]);
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
                      <option value="1"><?php esc_html_e('Emiterii', 'woocommerce-oblio'); ?></option>
                      <option value="2"<?php echo $oblio_gen_date == '2' ? ' selected' : ''; ?>><?php esc_html_e('Comenzii', 'woocommerce-oblio'); ?></option>
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
                      <option value="0"><?php esc_html_e('Nu', 'woocommerce-oblio'); ?></option>
                      <option value="1"<?php echo $oblio_auto_collect === 1 ? ' selected' : ''; ?>><?php esc_html_e('Platile prin card', 'woocommerce-oblio'); ?></option>
                      <option value="2"<?php echo $oblio_auto_collect === 2 ? ' selected' : ''; ?>><?php esc_html_e('Toate', 'woocommerce-oblio'); ?></option>
                    </select>
                </td>
            </tr>
            <tr valign="top" class="form-field">
                <th scope="row"><?php esc_html_e('Seteaza ca finalizata comanda platita prin Card', 'woocommerce-oblio'); ?></th>
                <td>
                    <?php 
                    $oblio_webhook_card_complete = get_option('oblio_webhook_card_complete');
                    ?>
                    <input type="checkbox" name="oblio_webhook_card_complete"<?php echo intval($oblio_webhook_card_complete) === 1 ? ' checked' : ''; ?> value="1" />
                    <p class="description"><?php esc_html_e('Seteaza ca finalizata comanda platita prin integrarea cu cardul din Oblio (Link pe factura/proforma)', 'woocommerce-oblio'); ?></p>
                </td>
            </tr>
            <tr valign="top" class="form-field">
                <th colspan="2"><h2><?php esc_html_e('Email clienti', 'woocommerce-oblio'); ?></h2></th>
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
                  <small>[type] = <?php esc_html_e('tip document', 'woocommerce-oblio'); ?></small><br>
                  <small>[serie] = <?php esc_html_e('serie document', 'woocommerce-oblio'); ?></small><br>
                  <small>[numar] = <?php esc_html_e('numar document', 'woocommerce-oblio'); ?></small><br>
                  <small>[link] = <?php esc_html_e('link document', 'woocommerce-oblio'); ?></small><br>
                  <small>[issue_date] = <?php esc_html_e('data emitere', 'woocommerce-oblio'); ?></small><br>
                  <small>[due_date] = <?php esc_html_e('data scadenta', 'woocommerce-oblio'); ?></small><br>
                  <small>[total] = <?php esc_html_e('total document', 'woocommerce-oblio'); ?></small><br>
                  <small>[contact_name] = <?php esc_html_e('nume de contact', 'woocommerce-oblio'); ?></small><br>
                  <small>[client_name] = <?php esc_html_e('nume de client', 'woocommerce-oblio'); ?></small><br>
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
                <th colspan="2"><h2><?php esc_html_e('Sincronizare', 'woocommerce-oblio'); ?></h2></th>
            </tr>
            <tr valign="top" class="form-field">
                <th scope="row"><?php esc_html_e('Sincronizare automata cu stocul Oblio', 'woocommerce-oblio'); ?></th>
                <td>
                    <?php 
                    $oblio_stock_sync = (int) get_option('oblio_stock_sync');
                    ?>
                    <input type="checkbox" name="oblio_stock_sync"<?php echo $oblio_stock_sync === 1 ? ' checked' : ''; ?> value="1" />
                    <p class="description"><?php esc_html_e('Codul produsului din Oblio trebuie sa fie acelasi cu codul produsului din site-ul dvs.', 'woocommerce-oblio'); ?></p>
                </td>
            </tr>
            <tr valign="top" class="form-field">
                <th scope="row"><?php esc_html_e('Rezerva stoc comenzi', 'woocommerce-oblio'); ?></th>
                <td>
                    <?php 
                    $oblio_stock_adjusments = (int) get_option('oblio_stock_adjusments');
                    ?>
                    <input type="checkbox" name="oblio_stock_adjusments"<?php echo $oblio_stock_adjusments === 1 ? ' checked' : ''; ?> value="1" />
                    <p class="description">
                        <?php _e('Stocul din magazin va fi stocul din Oblio minus comenzile din ultimele 30 de zile cu status "Plata in asteptare", "In asteptare" sau "In procesare". <br>
                        Practic fara comenzile Nefacturate', 'woocommerce-oblio'); ?>
                    </p>
                </td>
            </tr>
            <tr valign="top" class="form-field">
                <th colspan="2"><h2><?php esc_html_e('Optiuni factura', 'woocommerce-oblio'); ?></h2></th>
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
                  <small>[order_id] = <?php esc_html_e('numar comanda', 'woocommerce-oblio'); ?></small><br>
                  <small>[date] = <?php esc_html_e('data comanda', 'woocommerce-oblio'); ?></small><br>
                  <small>[payment] = <?php esc_html_e('modalitate de plata', 'woocommerce-oblio'); ?></small><br>
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
            <tr valign="top" class="form-field">
                <th scope="row"><?php esc_html_e('Ascunde detalii produs', 'woocommerce-oblio'); ?></th>
                <td>
                    <?php 
                    $oblio_hide_description = get_option('oblio_hide_description');
                    ?>
                     <input type="checkbox" name="oblio_hide_description"<?php echo $oblio_hide_description == '1' ? ' checked' : ''; ?> value="1" />
                    <p class="description"><?php esc_html_e('Optiune in cazul in care doriti sa ascundeti detaliile', 'woocommerce-oblio'); ?></p>
                </td>
            </tr>
            <tr valign="top" class="form-field">
                <th scope="row"><?php esc_html_e('Completeaza automat datele pentru companii', 'woocommerce-oblio'); ?></th>
                <td>
                    <?php 
                    $oblio_autocomplete_company = get_option('oblio_autocomplete_company');
                    ?>
                     <input type="checkbox" name="oblio_autocomplete_company"<?php echo $oblio_autocomplete_company == '1' ? ' checked' : ''; ?> value="1" />
                    <p class="description"><?php esc_html_e('Completeaza automat datele pentru companiile din Romania pe baza CIF-ului', 'woocommerce-oblio'); ?></p>
                </td>
            </tr>
            <tr valign="top" class="form-field">
                <th scope="row"><?php esc_html_e('Dezactiveaza modifcarea pretul produsului in Oblio', 'woocommerce-oblio'); ?></th>
                <td>
                    <?php 
                    $oblio_notsave_price = get_option('oblio_notsave_price');
                    ?>
                     <input type="checkbox" name="oblio_notsave_price"<?php echo $oblio_notsave_price == '1' ? ' checked' : ''; ?> value="1" />
                    <p class="description"><?php esc_html_e('Dezactiveaza modifcarea pretul produsului in Oblio la generearea facturii', 'woocommerce-oblio'); ?></p>
                </td>
            </tr>
        </table>
        
        <?php submit_button(); ?>

    </form>
</div>