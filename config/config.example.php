<?php
/*
 * Car Rental - server configuration.
 *
 * Copy this file to config.php and fill in the values for this installation.
 * config.php holds passwords: never share it or commit it to version control.
 * Business settings (company name, currency, timezone, rates) are managed by the
 * admin inside the application, not here.
 */
return [
    'app' => [
        // Full public address of the backend folder, without trailing slash.
        // Examples: 'https://rent.example.com/backend' or 'http://192.168.1.20/car_rental/backend'
        // Required for password-reset emails and booking links. Leave empty to auto-detect (LAN use only).
        'url' => '',

        // true only while developing: shows PHP errors on screen. Keep false on live systems.
        'debug' => false,

        // Server timezone used until the admin sets one in Settings.
        'timezone' => 'UTC',

        // Log out inactive users after this many seconds.
        'idle_timeout' => 1800,

        // Set true only if the app sits behind a trusted reverse proxy / load balancer
        // so the visitor IP is read from X-Forwarded-For.
        'trust_proxy' => false,
    ],

    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'car_rental',
        'user' => 'car_rental_admin',
        'pass' => 'CHANGE_ME',
    ],

    // Outgoing email (password resets, notifications). Leave host empty to disable email.
    'mail' => [
        'host'       => '',
        'port'       => 587,
        'encryption' => 'tls',   // 'tls', 'ssl' or '' for none
        'username'   => '',
        'password'   => '',
        'from_email' => '',
        'from_name'  => 'Car Rental',
    ],

    // Folder for uploads, backups and logs. Leave empty to use ../storage next to the backend.
    // On hosting, a folder outside the public web root is best, e.g. '/home/user/car_rental_storage'.
    'storage_path' => '',
];
