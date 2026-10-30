<?php
require_once __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/db.php';

function car_booking_is_local_host(string $host): bool {
    $host = strtolower(trim($host, "[] \t\n\r\0\x0B"));
    return in_array($host, ['localhost', '127.0.0.1', '::1', '0.0.0.0'], true);
}

function car_booking_valid_lan_ipv4(string $ip): bool {
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return false;
    return !str_starts_with($ip, '127.') && !str_starts_with($ip, '169.254.');
}

function car_booking_detect_lan_ipv4(): string {
    $candidates = [];
    foreach ([$_SERVER['SERVER_ADDR'] ?? '', gethostbyname(gethostname() ?: '')] as $ip) {
        if (is_string($ip) && car_booking_valid_lan_ipv4($ip)) $candidates[] = $ip;
    }
    if (PHP_OS_FAMILY === 'Windows') {
        $out = (string) @shell_exec('ipconfig');
        if ($out && preg_match_all('/IPv4[^:\r\n]*:\s*([0-9]{1,3}(?:\.[0-9]{1,3}){3})/i', $out, $matches)) {
            foreach ($matches[1] as $ip) if (car_booking_valid_lan_ipv4($ip)) $candidates[] = $ip;
        }
    } else {
        $out = (string) @shell_exec('hostname -I 2>/dev/null');
        foreach (preg_split('/\s+/', trim($out)) as $ip) if (car_booking_valid_lan_ipv4($ip)) $candidates[] = $ip;
    }
    foreach ($candidates as $ip) {
        if (preg_match('/^(10\.|172\.(1[6-9]|2[0-9]|3[0-1])\.|192\.168\.)/', $ip)) return $ip;
    }
    return $candidates[0] ?? 'localhost';
}

function car_booking_quick_get(string $url, int $timeoutMs = 700): string {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT_MS => min($timeoutMs, 400),
            CURLOPT_TIMEOUT_MS => $timeoutMs,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $body = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($code >= 200 && $code < 300) ? $body : '';
    }
    $ctx = stream_context_create(['http' => ['timeout' => max(1, $timeoutMs / 1000), 'ignore_errors' => true]]);
    $body = @file_get_contents($url, false, $ctx);
    return is_string($body) ? $body : '';
}

function car_booking_detect_ngrok_url(): string {
    foreach (['http://127.0.0.1:4040/api/tunnels', 'http://localhost:4040/api/tunnels'] as $endpoint) {
        $raw = car_booking_quick_get($endpoint);
        if ($raw === '') continue;
        $data = json_decode($raw, true);
        if (!is_array($data) || empty($data['tunnels']) || !is_array($data['tunnels'])) continue;
        $fallback = '';
        foreach ($data['tunnels'] as $tunnel) {
            $public = (string) ($tunnel['public_url'] ?? '');
            if (!preg_match('~^https?://~i', $public)) continue;
            if (str_starts_with(strtolower($public), 'https://')) return rtrim($public, '/');
            if ($fallback === '') $fallback = rtrim($public, '/');
        }
        if ($fallback !== '') return $fallback;
    }
    return '';
}

function car_booking_public_link(): array {
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/backend/reservations/booking_link.php'));
    $bookingPath = rtrim($scriptDir, '/') . '/online_booking.php';
    if (!str_starts_with($bookingPath, '/')) $bookingPath = '/' . $bookingPath;

    $ngrok = car_booking_detect_ngrok_url();
    if ($ngrok !== '') {
        return ['url' => $ngrok . $bookingPath, 'source' => 'ngrok', 'ngrok' => $ngrok];
    }

    $scheme = stock_is_https() ? 'https' : 'http';
    $hostHeader = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $hostOnly = $hostHeader;
    $port = '';
    if (str_contains($hostHeader, ':') && !str_starts_with($hostHeader, '[')) {
        [$hostOnly, $portPart] = explode(':', $hostHeader, 2);
        $port = $portPart !== '' ? ':' . $portPart : '';
    }
    if (car_booking_is_local_host($hostOnly)) {
        $hostOnly = car_booking_detect_lan_ipv4();
        return ['url' => $scheme . '://' . $hostOnly . $port . $bookingPath, 'source' => 'local', 'ngrok' => ''];
    }
    return ['url' => $scheme . '://' . $hostHeader . $bookingPath, 'source' => 'hosted', 'ngrok' => ''];
}

function car_booking_whatsapp_number(string $phone): string {
    $digits = preg_replace('/\D+/', '', $phone);
    if ($digits === '') return '';
    if (str_starts_with($digits, '00')) $digits = substr($digits, 2);
    if (str_starts_with($digits, '0')) $digits = '255' . substr($digits, 1);
    if (strlen($digits) === 9) $digits = '255' . $digits;
    return $digits;
}

function car_booking_message(string $customerName, string $link): string {
    $greeting = trim($customerName) !== '' ? 'Hello ' . trim($customerName) . ',' : 'Hello,';
    return $greeting . ' you can submit your car booking request using this link: ' . $link;
}

$linkInfo = car_booking_public_link();
$link = $linkInfo['url'];
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_whatsapp'])) {
    $customerId = (int) ($_POST['customer_id'] ?? 0);
    $customer = null;
    if ($customerId > 0) {
        $stmt = $conn->prepare('SELECT id, full_name, phone_number FROM customers WHERE id=? LIMIT 1');
        $stmt->bind_param('i', $customerId);
        $stmt->execute();
        $customer = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
    }

    $customerName = trim((string) ($_POST['customer_name'] ?? ($customer['full_name'] ?? '')));
    $phone = trim((string) ($_POST['phone'] ?? ($customer['phone_number'] ?? '')));
    $message = trim((string) ($_POST['message'] ?? ''));
    if ($message === '') $message = car_booking_message($customerName, $link);
    $waNumber = car_booking_whatsapp_number($phone);

    if ($waNumber === '') {
        $error = 'Enter or select a customer WhatsApp number first.';
    } else {
        $waUrl = 'https://wa.me/' . rawurlencode($waNumber) . '?text=' . rawurlencode($message);
        header('Location: ' . $waUrl, true, 303);
        exit;
    }
}

$customers = $conn->query("SELECT id, full_name, phone_number FROM customers WHERE phone_number IS NOT NULL AND phone_number<>'' ORDER BY full_name LIMIT 500");
$defaultMessage = car_booking_message('', $link);
$prefillName = trim((string) ($_POST['customer_name'] ?? ''));
$prefillPhone = trim((string) ($_POST['phone'] ?? ''));
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Send WhatsApp Booking Link</title>
</head>
<body>
<?php include __DIR__ . '/../includes/header.php'; include __DIR__ . '/../includes/leftsidebar.php'; ?>
<div class="content-page">
    <h2>Send WhatsApp Booking Link</h2>
    <?php if ($error): ?><p class="error-msg"><?= h($error); ?></p><?php endif; ?>

    <div class="card-box" style="border-left:7px solid #16a34a;">
        <h3>Send Booking Page to Customer WhatsApp</h3>
        <p>Select an existing customer or enter a WhatsApp number. The button opens WhatsApp with the customer number and booking message already filled.</p>

        <?php if ($linkInfo['source'] === 'ngrok'): ?>
            <p class="success-msg"><strong>Ngrok detected automatically:</strong> <?= h($linkInfo['ngrok']); ?>. The WhatsApp message will use this public booking link.</p>
        <?php elseif ($linkInfo['source'] === 'local'): ?>
            <p style="padding:10px 12px;border-radius:8px;background:#fff7ed;color:#9a3412;"><strong>Ngrok not detected.</strong> The link below uses the local network address. For customers outside this network, start Ngrok with <code>ngrok http 80</code> and refresh this page.</p>
        <?php else: ?>
            <p class="success-msg"><strong>Hosted booking link detected.</strong> The WhatsApp message will use the current website address.</p>
        <?php endif; ?>

        <form method="POST" target="_blank" id="whatsappSendForm">
            <input type="hidden" name="send_whatsapp" value="1">
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;align-items:start;">
                <div>
                    <label>1. Select Existing Customer</label>
                    <select id="customerPicker" name="customer_id">
                        <option value="" data-name="" data-phone="">Choose customer or type number below</option>
                        <?php if ($customers): while ($customer = $customers->fetch_assoc()): ?>
                            <option value="<?= h($customer['id']); ?>" data-name="<?= h($customer['full_name']); ?>" data-phone="<?= h($customer['phone_number']); ?>">
                                <?= h($customer['full_name'] . ' — ' . $customer['phone_number']); ?>
                            </option>
                        <?php endwhile; endif; ?>
                    </select>
                </div>
                <div>
                    <label>2. Customer Name Optional</label>
                    <input type="text" name="customer_name" id="customerName" placeholder="Example: Mary" value="<?= h($prefillName); ?>">
                </div>
                <div>
                    <label>3. Customer WhatsApp Number</label>
                    <input type="text" name="phone" id="customerPhone" placeholder="Example: 0712 345 678 or 255712345678" value="<?= h($prefillPhone); ?>">
                </div>
                <div style="grid-column:1/-1;">
                    <label>4. Message Preview</label>
                    <textarea name="message" id="whatsappMessage" rows="4"></textarea>
                </div>
            </div>
            <div style="display:flex;flex-wrap:wrap;gap:10px;margin-top:14px;">
                <button class="btn btn-success" type="submit">Open WhatsApp and Send Booking Link</button>
                <button class="btn" id="copyBookingLink" type="button">Copy Booking Link</button>
                <a class="btn btn-primary" target="_blank" href="<?= h($link); ?>">Open Booking Page</a>
                <a class="btn" href="booking_link.php">Refresh Detected Link</a>
            </div>
        </form>
    </div>

    <div class="card-box">
        <h3>Customer Booking Link</h3>
        <p>This is the exact public car booking page link that will be sent to customers.</p>
        <label>Booking Link</label>
        <input id="bookingLink" type="text" value="<?= h($link); ?>" readonly onclick="this.select()">
        <div style="display:flex;flex-wrap:wrap;gap:10px;margin-top:14px;">
            <a class="btn btn-success" target="_blank" href="https://wa.me/?text=<?= h(rawurlencode($defaultMessage)); ?>">Share Link by WhatsApp Without Customer Number</a>
            <a class="btn btn-primary" target="_blank" href="<?= h($link); ?>">Open Booking Page</a>
        </div>
    </div>
</div>
<script>
(function () {
    const picker = document.getElementById('customerPicker');
    const nameInput = document.getElementById('customerName');
    const phoneInput = document.getElementById('customerPhone');
    const messageInput = document.getElementById('whatsappMessage');
    const form = document.getElementById('whatsappSendForm');
    const bookingLinkInput = document.getElementById('bookingLink');
    const copyButton = document.getElementById('copyBookingLink');
    const bookingLink = <?= json_encode($link); ?>;

    function updateMessage() {
        const name = (nameInput.value || '').trim();
        const greeting = name ? 'Hello ' + name + ',' : 'Hello,';
        messageInput.value = greeting + ' you can submit your car booking request using this link: ' + bookingLink;
    }

    picker.addEventListener('change', function () {
        const option = picker.options[picker.selectedIndex];
        const selectedName = option.getAttribute('data-name') || '';
        const selectedPhone = option.getAttribute('data-phone') || '';
        if (selectedName) nameInput.value = selectedName;
        if (selectedPhone) phoneInput.value = selectedPhone;
        updateMessage();
    });
    nameInput.addEventListener('input', updateMessage);
    form.addEventListener('submit', function (event) {
        if (!(phoneInput.value || '').trim()) {
            event.preventDefault();
            alert('Enter the customer WhatsApp number first.');
            phoneInput.focus();
        }
    });
    copyButton.addEventListener('click', async function () {
        try {
            await navigator.clipboard.writeText(bookingLinkInput.value);
            const original = copyButton.textContent;
            copyButton.textContent = 'Copied';
            setTimeout(function () { copyButton.textContent = original; }, 1200);
        } catch (e) {
            bookingLinkInput.focus();
            bookingLinkInput.select();
            document.execCommand('copy');
        }
    });
    updateMessage();
})();
</script>
</body>
</html>
