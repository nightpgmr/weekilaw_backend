<?php

namespace App\Console\Commands;

use App\Services\OTPService;
use Illuminate\Console\Command;

class TestOTP extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'otp:test {phone?} {--dev}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test OTP service with Kavenegar API';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $phone = $this->argument('phone') ?: '09123456789';
        $forceDev = $this->option('dev');

        $this->info('🧪 Testing OTP Service');
        $this->line('======================');
        $this->newLine();

        $otpService = app(OTPService::class);

        // Force development mode if --dev flag is used
        if ($forceDev) {
            config(['app.env' => 'local']);
        }

        $this->info("📱 Testing with phone: {$phone}");
        $this->info("🌍 Environment: " . config('app.env'));
        $this->newLine();

        try {
            $this->info('🔄 Generating OTP code...');
            $otpCode = $otpService->generateOTP();
            $this->info("✅ Generated OTP: {$otpCode}");
            $this->newLine();

            $this->info('📤 Sending OTP via SMS...');
            $result = $otpService->sendOTP($phone, $otpCode);

            $this->line('📊 Result:');
            $this->info("   Success: " . ($result['success'] ? '✅ Yes' : '❌ No'));
            $this->info("   Message: {$result['message']}");

            if (isset($result['error'])) {
                $this->error("   Error: {$result['error']}");
            }

            if (isset($result['data'])) {
                $this->line("   API Response:");
                $this->info(json_encode($result['data'], JSON_PRETTY_PRINT));
            }

            if (isset($result['dev_otp'])) {
                $this->warn("   Dev OTP (for testing): {$result['dev_otp']}");
            }

        } catch (\Exception $e) {
            $this->error("❌ Exception occurred: " . $e->getMessage());
            $this->error("   File: " . $e->getFile() . ":" . $e->getLine());
            return 1;
        }

        $this->newLine();
        $this->info('🏁 Test completed.');
        return 0;
    }
}
