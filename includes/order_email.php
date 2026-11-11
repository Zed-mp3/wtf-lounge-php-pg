<?php
/**
 * Emails the order details to the customer after checkout.
 * Uses sendMail() from mailer.php (Gmail API over HTTPS on Render, so it works on the free plan).
 * Never throws: if the email fails, the order is still placed and the reason goes to the server log.
 */
require_once __DIR__ . '/mailer.php';

function sendOrderEmail(PDO $pdo, string $orderCode, int $customerId): bool
{
    try {
        if (!mailConfigured()) {
            return false;
        }
        $customer = getCustomerById($pdo, $customerId);
        $to = trim((string) ($customer['email'] ?? ''));
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false; // older accounts may have no email on file
        }
        $order = getOrderByCode($pdo, $orderCode);
        if (!$order) {
            return false;
        }

        $name   = (string) ($customer['fullname'] ?? 'Customer');
        $isDel  = $order['order_mode'] === 'Delivery';
        $eta    = $isDel ? '20-30 mins' : '15-20 mins';
        $e      = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $money  = fn($n) => '₱' . number_format((float) $n, 2);

        $rowsHtml = '';
        $rowsText = '';
        foreach ($order['items'] as $item) {
            $qty   = (int) $item['quantity'];
            $price = (float) $item['price'];
            $meta  = trim((string) ($item['meta_snapshot'] ?? ''));
            $line  = $price * $qty;
            $rowsHtml .= '<tr>'
                . '<td style="padding:8px 0;border-bottom:1px solid #eee;">' . $e($item['productname_snapshot'])
                . ($meta !== '' ? '<br><small style="color:#777;">' . $e($meta) . '</small>' : '') . '</td>'
                . '<td style="padding:8px 0;border-bottom:1px solid #eee;text-align:center;">' . $qty . '</td>'
                . '<td style="padding:8px 0;border-bottom:1px solid #eee;text-align:right;">' . $money($line) . '</td>'
                . '</tr>';
            $rowsText .= '- ' . $item['productname_snapshot'] . ' x' . $qty . '  ' . $money($line)
                . ($meta !== '' ? ' (' . $meta . ')' : '') . "\n";
        }

        $address = trim((string) ($customer['address'] ?? ''));
        $html = '<div style="font-family:Arial,sans-serif;max-width:520px;margin:auto;color:#222;">'
            . '<h2 style="margin:0 0 4px;">Thanks for your order, ' . $e($name) . '!</h2>'
            . '<p style="margin:0 0 16px;color:#555;">We\'re preparing your treats. Estimated time: <b>' . $eta . '</b>.</p>'
            . '<p style="margin:0 0 4px;"><b>Order number:</b> #' . $e($order['order_code']) . '</p>'
            . '<p style="margin:0 0 4px;"><b>Mode:</b> ' . $e($order['order_mode']) . '</p>'
            . ($isDel && $address !== '' ? '<p style="margin:0 0 4px;"><b>Deliver to:</b> ' . $e($address) . '</p>' : '')
            . '<p style="margin:0 0 16px;"><b>Payment:</b> ' . $e($order['payment_method']) . '</p>'
            . '<table style="width:100%;border-collapse:collapse;font-size:14px;">'
            . '<tr><th style="text-align:left;border-bottom:2px solid #ddd;padding-bottom:6px;">Item</th>'
            . '<th style="border-bottom:2px solid #ddd;padding-bottom:6px;">Qty</th>'
            . '<th style="text-align:right;border-bottom:2px solid #ddd;padding-bottom:6px;">Price</th></tr>'
            . $rowsHtml . '</table>'
            . '<p style="text-align:right;margin:12px 0 2px;">Subtotal: ' . $money($order['subtotal']) . '</p>'
            . '<p style="text-align:right;margin:0 0 2px;">' . ($isDel ? 'Delivery fee: ' . $money($order['delivery_fee']) : 'Pickup: Free') . '</p>'
            . '<p style="text-align:right;margin:0;font-size:18px;"><b>Total: ' . $money($order['total']) . '</b></p>'
            . '<p style="margin-top:20px;color:#777;font-size:12px;">WTF Lounge</p>'
            . '</div>';

        $text = "Thanks for your order, $name!\n"
            . "Order number: #{$order['order_code']}\n"
            . "Mode: {$order['order_mode']}\n"
            . ($isDel && $address !== '' ? "Deliver to: $address\n" : '')
            . "Payment: {$order['payment_method']}\n"
            . "Estimated time: $eta\n\n"
            . $rowsText . "\n"
            . 'Subtotal: ' . $money($order['subtotal']) . "\n"
            . ($isDel ? 'Delivery fee: ' . $money($order['delivery_fee']) : 'Pickup: Free') . "\n"
            . 'Total: ' . $money($order['total']) . "\n";

        return sendMail($to, $name, 'Your WTF Lounge order #' . $order['order_code'], $html, $text);
    } catch (Throwable $ex) {
        error_log('Order email failed: ' . $ex->getMessage());
        return false;
    }
}
