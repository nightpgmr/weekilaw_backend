<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class TestKavenegar extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kavenegar:test {--template=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test Kavenegar API connectivity and template configuration';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $apiKey = config('services.kavenegar.api_key');
        $template = $this->option('template') ?: config('services.kavenegar.template', 'liantemp');

        $this->info('🔍 Testing Kavenegar API Connection');
        $this->line('====================================');
        $this->newLine();

        if (!$apiKey) {
            $this->error('❌ KAVENEGAR_API_KEY not configured in .env');
            $this->line('Add to your .env file:');
            $this->info('KAVENEGAR_API_KEY=your_api_key_here');
            return 1;
        }

        $this->info("API Key: " . substr($apiKey, 0, 10) . "...");
        $this->info("Template: {$template}");
        $this->newLine();

        try {
            // Test account info
            $this->info('🔗 Testing API connectivity...');
            $response = Http::timeout(10)->get("https://api.kavenegar.com/v1/{$apiKey}/account/info.json");

            if ($response->successful()) {
                $data = $response->json();
                $this->info('✅ API connection successful!');
                $this->line('Account Info:');
                $this->info(json_encode($data, JSON_PRETTY_PRINT));
                $this->newLine();
            } else {
                $this->error("❌ API connection failed: Status {$response->status()}");
                $this->error("Response: {$response->body()}");
                return 1;
            }

            // Test template
            $this->info("🔍 Testing template '{$template}'...");
            $response = Http::timeout(10)->get("https://api.kavenegar.com/v1/{$apiKey}/verify/lookup.json", [
                'receptor' => '+989121111111',
                'token' => '1234',
                'template' => $template,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $this->info('✅ Template exists and is approved!');
                $this->line('Template Test Result:');
                $this->info(json_encode($data, JSON_PRETTY_PRINT));
            } else {
                $this->error("❌ Template issue: Status {$response->status()}");
                $this->error("Response: {$response->body()}");
                $this->newLine();

                $this->warn('💡 Solutions:');
                $this->line('1. Go to https://panel.kavenegar.com/');
                $this->line('2. Navigate to SMS → Templates');
                $this->line('3. Create a new verification template');
                $this->line('4. Use a template name like: otp_verify, verify_code, etc.');
                $this->line('5. Template content should include {token} placeholder');
                $this->line('6. Wait for template approval (usually 1-24 hours)');
                $this->line('7. Update KAVENEGAR_TEMPLATE in .env with approved template name');
                $this->newLine();

                $this->info('Example template content:');
                $this->info('"کد تایید شما: {token}"');
            }

        } catch (\Exception $e) {
            $this->error("❌ Exception: " . $e->getMessage());
            return 1;
        }

        $this->newLine();
        $this->info('🏁 Test completed.');
        return 0;
    }
}
