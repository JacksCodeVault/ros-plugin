<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="ros-csrf" content="<?= htmlspecialchars($csrfToken ?? '', ENT_QUOTES, 'UTF-8') ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light dark">
    <meta name="description" content="RouterOS provisioning and service management for UISP">
    <title>ROS Plugin</title>
    <link rel="icon" type="image/png" href="public/panel/MT_Symbol_Black.png">
    <style><?php include __DIR__ . '/assets/app.css'; ?></style>
</head>
<body>
    <a class="skip-link" href="#main-content">Skip to content</a>
    <div class="app-shell">
        <nav class="tab-bar" aria-label="Plugin sections">
            <button type="button" data-tab="devices" aria-controls="page-devices">Routers</button>
            <button type="button" data-tab="services" aria-controls="page-services">Services</button>
            <button type="button" data-tab="plans" aria-controls="page-plans">Plans</button>
            <button type="button" data-tab="attributes" aria-controls="page-attributes">Attributes</button>
            <button type="button" data-tab="settings" aria-controls="page-settings">Settings</button>
            <button type="button" data-tab="system" aria-controls="page-system">System</button>
        </nav>
        <main id="main-content" tabindex="-1">
            <?php
            include __DIR__ . '/pages/devices.php';
            include __DIR__ . '/pages/services.php';
            include __DIR__ . '/pages/plans.php';
            include __DIR__ . '/pages/attributes.php';
            include __DIR__ . '/pages/settings.php';
            include __DIR__ . '/pages/system.php';
            ?>
        </main>
    </div>
    <div id="confirm-dialog" class="dialog-backdrop is-hidden" role="presentation">
        <section class="dialog dialog--small" role="alertdialog" aria-modal="true" aria-labelledby="confirm-title" aria-describedby="confirm-message">
            <header class="dialog__header"><div><h2 id="confirm-title">Confirm action</h2><p id="confirm-message"></p></div></header>
            <footer class="dialog__footer">
                <button type="button" class="button button--secondary" data-confirm-cancel>Cancel</button>
                <button type="button" class="button button--danger" data-confirm-accept>Confirm</button>
            </footer>
        </section>
    </div>
    <div id="toast-container" class="toast-region" aria-live="polite" aria-atomic="true"></div>
    <script><?php include __DIR__ . '/assets/app.js'; ?></script>
</body>
</html>
