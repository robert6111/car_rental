<?php
require_once __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/db.php';

/**
 * Public address of the online booking form. Uses the System address from Settings when set;
 * otherwise falls back to this server's own network address (works inside the office network only).
 */
function car_booking_public_link(): array {
    $configured = cr_absolute_url('reservations/online_booking.php', true);
    if ($configured !== null) return ['url' => $configured, 'source' => 'configured'];

    $url = cr_absolute_url('reservations/online_booking.php');
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    if (in_array($host, ['localhost', '127.0.0.1', '[::1]', '::1'], true)) {
        $ip = (string) ($_SERVER['SERVER_ADDR'] ?? '');
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || str_starts_with($ip, '127.')) {
            $ip = gethostbyname(gethostname() ?: 'localhost');
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && !str_starts_with($ip, '127.')) {
            $url = preg_replace('#//(localhost|127\.0\.0\.1|\[::1\])#i', '//' . $ip, $url, 1);
        }
    }
    return ['url' => $url, 'source' => 'local'];
}

function car_booking_whatsapp_number(string $phone): string {
    return cr_phone_international($phone);
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
<?php cr_layout_start('Send booking link'); ?>
<a class="back-link" href="manage.php"><?= cr_icon('arrow-left', 'icon-sm'); ?> All reservations</a>
<?= cr_page_header('Send booking link', 'Send customers the online booking page on WhatsApp so they can request a car.'); ?>
<?php if (!cr_setting_bool('public_booking_enabled')): ?>
    <?= cr_alert('warning', 'Online booking is switched off, so customers who open this link will see a "closed" message.' . (stock_has_permission('settings_manage') ? ' <a href="../settings/index.php">Turn it on in Settings</a>.' : ''), true); ?>
<?php endif; ?>
<?= cr_alerts('', $error); ?>

<div class="card">
    <div class="card-head"><h2>Send to a customer on WhatsApp</h2></div>
    <p class="muted">Select an existing customer or enter a WhatsApp number. The button opens WhatsApp with the customer number and booking message already filled.</p>
    <?php if ($linkInfo['source'] === 'local'): ?>
        <?= cr_alert('warning', '<strong>Local network link.</strong> This link only works for people on the same network as this computer. To send links to customers anywhere, put the system online and enter its address under <a href="' . h(app_url('settings/index.php')) . '">Settings &rsaquo; System address</a>.', true); ?>
    <?php endif; ?>

    <form method="POST" target="_blank" id="whatsappSendForm">
        <input type="hidden" name="send_whatsapp" value="1">
        <div class="form-grid">
            <div class="field">
                <label for="customerPicker">1. Select an existing customer</label>
                <select id="customerPicker" name="customer_id">
                    <option value="" data-name="" data-phone="">Choose a customer or type a number below</option>
                    <?php if ($customers): while ($customer = $customers->fetch_assoc()): ?>
                        <option value="<?= h($customer['id']); ?>" data-name="<?= h($customer['full_name']); ?>" data-phone="<?= h($customer['phone_number']); ?>"><?= h($customer['full_name'] . ' — ' . $customer['phone_number']); ?></option>
                    <?php endwhile; endif; ?>
                </select>
            </div>
            <div class="field">
                <label for="customerName">2. Customer name <span class="opt">(optional)</span></label>
                <input type="text" name="customer_name" id="customerName" placeholder="Example: Mary" value="<?= h($prefillName); ?>">
            </div>
            <div class="field">
                <label for="customerPhone">3. Customer WhatsApp number <span class="req">*</span></label>
                <input type="tel" name="phone" id="customerPhone" placeholder="Local number or with country code" value="<?= h($prefillPhone); ?>">
            </div>
            <div class="field span-all">
                <label for="whatsappMessage">4. Message preview</label>
                <textarea name="message" id="whatsappMessage" rows="4"></textarea>
            </div>
        </div>
        <div class="form-actions">
            <button class="btn btn-success" type="submit"><?= cr_icon('whatsapp'); ?> Open WhatsApp and send link</button>
            <button class="btn btn-secondary" id="copyBookingLink" type="button"><?= cr_icon('link'); ?> Copy booking link</button>
            <a class="btn btn-secondary" target="_blank" rel="noopener" href="<?= h($link); ?>"><?= cr_icon('external'); ?> Open booking page</a>
            <a class="btn btn-ghost" href="booking_link.php"><?= cr_icon('refresh'); ?> Refresh detected link</a>
        </div>
    </form>
</div>

<div class="card">
    <div class="card-head"><h2>Customer booking link</h2></div>
    <p class="muted">This is the exact public booking page link that will be sent to customers.</p>
    <div class="field">
        <label for="bookingLink">Booking link</label>
        <input id="bookingLink" type="text" value="<?= h($link); ?>" readonly onclick="this.select()">
    </div>
    <div class="row-flex mt-4">
        <a class="btn btn-success" target="_blank" rel="noopener" href="https://wa.me/?text=<?= h(rawurlencode($defaultMessage)); ?>"><?= cr_icon('whatsapp'); ?> Share on WhatsApp without a number</a>
        <a class="btn btn-secondary" target="_blank" rel="noopener" href="<?= h($link); ?>"><?= cr_icon('external'); ?> Open booking page</a>
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
            const original = copyButton.innerHTML;
            copyButton.textContent = 'Copied';
            setTimeout(function () { copyButton.innerHTML = original; }, 1200);
        } catch (e) {
            bookingLinkInput.focus();
            bookingLinkInput.select();
            document.execCommand('copy');
        }
    });
    updateMessage();
})();
</script>
<?php cr_layout_end(); ?>
