<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "═══════════════════════════════════════════════════════════\n";
echo "  Configuration Verification\n";
echo "═══════════════════════════════════════════════════════════\n\n";

echo "MongoDB Configuration:\n";
echo "  URI: " . config('mongodb.uri') . "\n";
echo "  Database: " . config('mongodb.database') . "\n";
echo "  Collection: " . config('mongodb.collection') . "\n\n";

echo "OTP Configuration:\n";
echo "  Dev Code: " . config('services.kavenegar.dev_code') . "\n";
echo "  API Key Set: " . (config('services.kavenegar.api_key') ? 'Yes' : 'No') . "\n";
echo "  Template: " . config('services.kavenegar.template') . "\n\n";

echo "Environment:\n";
echo "  APP_ENV: " . config('app.env') . "\n";
echo "  APP_DEBUG: " . (config('app.debug') ? 'true' : 'false') . "\n\n";

echo "═══════════════════════════════════════════════════════════\n";
echo "  Expected Values:\n";
echo "═══════════════════════════════════════════════════════════\n";
echo "  MongoDB Database: test\n";
echo "  MongoDB Collection: users\n";
echo "  OTP Dev Code: 12345\n";
echo "  Environment: local (for dev)\n\n";
