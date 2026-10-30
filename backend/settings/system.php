<?php
/*
 * System check: shows whether this installation is ready to be used live
 * (server, configuration, web protection, database, scheduled task, backups).
 */
require_once __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/send_email.php';
require_once __DIR__ . '/../includes/system_check.php';

// The live web-protection test calls this server; don't hold the session lock meanwhile.
session_write_close();

$checks = cr_system_checks($conn);
$overall = cr_system_overall($checks);
$counts = array_count_values(array_column($checks, 'status')) + ['ok' => 0, 'warn' => 0, 'fail' => 0];
$tone = ['ok' => 'success', 'warn' => 'warning', 'fail' => 'danger'];
$word = ['ok' => 'OK', 'warn' => 'Check', 'fail' => 'Fix'];
$groups = [];
foreach ($checks as $c) $groups[$c['group']][] = $c;
?>
<?php cr_layout_start('System check'); ?>
<?= cr_page_header('System check', 'Is this installation ready to use live? Fix every red item and review the yellow ones.',
    '<a class="btn btn-secondary" href="system.php">Check again</a>'); ?>

<?php if ($overall === 'ok'): ?>
    <?= cr_alert('success', 'Everything checks out. The system is ready to use.'); ?>
<?php elseif ($overall === 'warn'): ?>
    <?= cr_alert('warning', $counts['warn'] . ' item(s) need a look. The system works, but review them before going live.'); ?>
<?php else: ?>
    <?= cr_alert('error', $counts['fail'] . ' item(s) must be fixed before the system is used live.'); ?>
<?php endif; ?>

<?php foreach ($groups as $group => $items): ?>
<div class="card card-flush">
    <div class="card-head"><h2><?= h($group); ?></h2></div>
    <div class="table-wrap">
        <table>
            <tbody>
            <?php foreach ($items as $c): ?>
                <tr>
                    <td style="width:120px"><span class="badge badge-<?= $tone[$c['status']]; ?>"><?= $word[$c['status']]; ?></span></td>
                    <td style="width:220px"><strong><?= h($c['label']); ?></strong></td>
                    <td><?= h($c['detail']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endforeach; ?>

<p class="muted">The same check can be run on the server with: <code>php cron/check.php</code></p>
<?php cr_layout_end(); ?>
