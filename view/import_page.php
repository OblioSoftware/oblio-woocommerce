<div class="wrap woocommerce">
    <h1><?php esc_html_e('Sincronizare Manuala', 'woocommerce-oblio'); ?></h1>
    <p>Sincronizarea manuala iti permite sa sincronizezi stocul imediat.</p>
    <p>Daca folosesti sincronizarea automata din <b>Oblio > Setari</b> stocul se actualizeaza automat la fiecare ora.</p>
    <?php echo $message; ?>
    <a class="button action" href="admin.php?page=oblio-import&amp;import=1">Sincronizare</a>
</div>