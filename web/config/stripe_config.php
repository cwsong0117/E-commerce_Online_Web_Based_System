<?php
// Stripe API Configuration

// Load environment variables from .env file
$envFile = __DIR__ . '/../../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($key, $value) = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

// Stripe API Keys from environment
define('STRIPE_PUBLISHABLE_KEY', $_ENV['STRIPE_PUBLISHABLE_KEY'] ?? '');
define('STRIPE_SECRET_KEY', $_ENV['STRIPE_SECRET_KEY'] ?? '');
define('STRIPE_CURRENCY', $_ENV['STRIPE_CURRENCY'] ?? 'myr');

// Online banking / e-wallet are SIMULATED (no real gateway verifies them), so anyone
// who can reach the endpoint can mark their own order paid. Keep this on only for demos;
// set ALLOW_SIMULATED_PAYMENTS=false in .env to disable them.
define('ALLOW_SIMULATED_PAYMENTS', filter_var($_ENV['ALLOW_SIMULATED_PAYMENTS'] ?? 'true', FILTER_VALIDATE_BOOLEAN));

// For production, use live keys:
// define('STRIPE_PUBLISHABLE_KEY', 'pk_live_...');
// define('STRIPE_SECRET_KEY', 'sk_live_...');
