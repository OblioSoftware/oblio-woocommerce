<table cellspacing="0" class="display_meta">
    <tr>
        <th>Comanda</th>
        <th>Factura</th>
    </tr>
    <?php if (count($invoices) === 0): ?>
        <tr>
            <td colspan="2">Nu exista nici o factura</td>
        </tr>
    <?php else: ?>
    <?php foreach ($invoices as $invoice): ?>
        <tr>
            <td>#<?php echo $invoice->ID; ?></td>
            <td><?php echo sprintf('<a href="%1$s" target="_blank" title="%2$s %3$s">%2$s %3$s</a>', $invoice->link, $invoice->series_name, $invoice->number); ?></td>
        </tr>
    <?php endforeach; ?>
    <?php endif; ?>
</table>