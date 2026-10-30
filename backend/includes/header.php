<?php
require_once __DIR__ . '/app.php';
$baseUrl = app_base_url();
$username = $_SESSION['username'] ?? 'Guest';
?>
<link rel="stylesheet" href="<?= h(app_url('includes/css/header.css')); ?>?v=customer-ready-1">
<link rel="stylesheet" href="<?= h(app_url('includes/css/dashboard.css')); ?>?v=customer-ready-1">
<link rel="stylesheet" href="<?= h(app_url('includes/css/common.css')); ?>?v=customer-ready-1">
<link rel="stylesheet" href="<?= h(app_url('includes/css/all_cars.css')); ?>?v=customer-ready-1">
<link rel="stylesheet" href="<?= h(app_url('includes/css/customer-polish.css')); ?>?v=customer-polish-1">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@mdi/font@7.4.47/css/materialdesignicons.min.css">
<link rel="icon" type="image/png" href="<?= h(app_url('includes/images/logo.png')); ?>?v=customer-ready-1">

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<header class="topbar">
    <button type="button" id="mobileMenuToggle" class="mobile-menu-toggle" aria-label="Open menu" aria-expanded="false">
        <span class="hamburger-symbol">☰</span>
    </button>

    <div class="topbar-left">
        <a href="<?= h(app_url('dashboard.php')); ?>" class="logo">
            <span class="logo-mark">CR</span>
            <span class="logo-text">Car Rental</span>
        </a>
    </div>

    <div class="topbar-right">
        <div class="profile-dropdown" id="profileDropdown">
            <button type="button" class="profile-button" id="profileButton" aria-label="Open profile menu">
                <span class="profile-avatar">👤</span>
                <span class="profile-name"><?= h($username); ?></span>
                <span class="profile-caret">▾</span>
            </button>

            <div class="dropdown-menu" id="profileMenu">
                <a href="<?= h(app_url('profile.php')); ?>">Profile</a>
                <a href="<?= h(app_url('change_password.php')); ?>">Change Password</a>
                <a href="<?= h(app_url('authentication/logout.php')); ?>">Logout</a>
            </div>
        </div>
    </div>
</header>

<script src="<?= h(app_url('includes/js/dashboard.js')); ?>?v=customer-ready-1" defer></script>
