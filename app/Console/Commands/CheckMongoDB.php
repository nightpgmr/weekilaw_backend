<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class CheckMongoDB extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mongodb:check';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check MongoDB PHP extension status';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🔍 MongoDB PHP Extension Status');
        $this->line('==============================');
        $this->newLine();

        // Check extension loaded
        $loaded = extension_loaded('mongodb');
        $this->info('Extension Status:');
        if ($loaded) {
            $this->info('  ✅ MongoDB extension is LOADED');
            $version = phpversion('mongodb');
            if ($version) {
                $this->line("  Version: {$version}");
            }
        } else {
            $this->error('  ❌ MongoDB extension is NOT loaded');
        }
        $this->newLine();

        // Check class exists
        $this->info('Class Availability:');
        if (class_exists('MongoDB\Client')) {
            $this->info('  ✅ MongoDB\Client class EXISTS');
        } else {
            $this->error('  ❌ MongoDB\Client class NOT FOUND');
        }
        $this->newLine();

        // Check DLL file
        $phpExtDir = ini_get('extension_dir');
        $dllPath = $phpExtDir . DIRECTORY_SEPARATOR . 'php_mongodb.dll';
        
        $this->info('DLL File:');
        if (file_exists($dllPath)) {
            $this->info("  ✅ DLL found: {$dllPath}");
            $size = filesize($dllPath);
            $this->line("  Size: " . number_format($size / 1024, 2) . " KB");
        } else {
            $this->error("  ❌ DLL not found: {$dllPath}");
        }
        $this->newLine();

        // Check php.ini
        $phpIni = php_ini_loaded_file();
        $this->info('php.ini Configuration:');
        $this->line("  File: {$phpIni}");
        
        $iniContent = file_get_contents($phpIni);
        if (preg_match('/^extension\s*=\s*mongodb/m', $iniContent)) {
            $this->info('  ✅ extension=mongodb is enabled');
        } else {
            $this->error('  ❌ extension=mongodb not found');
        }
        $this->newLine();

        // Check MongoDB connection (if extension loaded)
        if ($loaded && class_exists('MongoDB\Client')) {
            $this->info('MongoDB Connection Test:');
            try {
                $uri = config('mongodb.uri');
                if ($uri) {
                    $this->line("  URI: " . substr($uri, 0, 30) . "...");
                    
                    $client = new \MongoDB\Client($uri);
                    $database = config('mongodb.database', 'weekilaw');
                    $client->selectDatabase($database)->command(['ping' => 1]);
                    
                    $this->info('  ✅ Connection successful!');
                } else {
                    $this->warn('  ⚠️  MONGODB_URI not configured in .env');
                }
            } catch (\Exception $e) {
                $this->error('  ❌ Connection failed: ' . $e->getMessage());
            }
        }

        $this->newLine();
        
        if (!$loaded) {
            $this->warn('💡 To install MongoDB extension, run:');
            $this->line('   composer mongodb:install');
            $this->line('   or');
            $this->line('   php artisan mongodb:install');
        }

        return $loaded ? 0 : 1;
    }
}
