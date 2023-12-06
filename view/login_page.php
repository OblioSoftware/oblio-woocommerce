<div class="wrap woocommerce">
    <h1><?php esc_html_e('Conectare cu Oblio', 'woocommerce-oblio'); ?></h1>
    
    <p><?php esc_html_e('Woocommerce se conecteaza cu Oblio folosind datele de conectare de mai jos:', 'woocommerce-oblio'); ?></p>

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
                    <p class="description"><?php echo sprintf(__('API-ul secret poate fi gasit in <b>Oblio &gt; Contul meu &gt; Setari &gt; Date cont</b> sau %s', 'woocommerce-oblio'), '<a href="https://www.oblio.eu/account/settings" target="_blank">direct de aici</a>'); ?></p>
                </td>
            </tr>
        </table>
        
        <?php submit_button(); ?>

    </form>
</div>