<div class="wrap woocommerce">
    <h1><?php esc_html_e('Sincronizare Manuala', 'woocommerce-oblio'); ?></h1>
    <p><?php esc_html_e('Sincronizarea manuala iti permite sa sincronizezi stocul imediat.', 'woocommerce-oblio'); ?></p>
    <p><?php esc_html_e('Daca folosesti sincronizarea automata din', 'woocommerce-oblio'); ?> <b><?php _e('Oblio > Setari', 'woocommerce-oblio'); ?></b> <?php esc_html_e('stocul se actualizeaza automat la fiecare ora.', 'woocommerce-oblio'); ?></p>
    <?php echo $message; ?>
    <a class="button action" href="admin.php?page=oblio-import&amp;import=1"><?php esc_html_e('Sincronizare', 'woocommerce-oblio'); ?></a>
</div>