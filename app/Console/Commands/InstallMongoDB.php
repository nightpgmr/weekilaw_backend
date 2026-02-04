<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class InstallMongoDB extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mongodb:install {--force : Force reinstall even if already installed}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Install MongoDB PHP extension (Windows)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🔧 MongoDB PHP Extension Installer');
        $this->line('===================================');
        $this->newLine();

        // Check if already installed
        if (extension_loaded('mongodb')) {
            $this->info('✅ MongoDB extension is already loaded!');
            
            if (!$this->option('force')) {
                $this->line('Use --force to reinstall.');
                return 0;
            }
            
            $this->warn('Reinstalling...');
        }

        // Get PHP configuration
        $phpIni = php_ini_loaded_file();
        $phpExtDir = ini_get('extension_dir');
        $phpVersion = PHP_VERSION;
        $phpTS = ZEND_THREAD_SAFE ? 'TS' : 'NTS';
        $phpArch = (PHP_INT_SIZE * 8) . '-bit';

        $this->info("PHP Configuration:");
        $this->line("  Version: {$phpVersion}");
        $this->line("  Thread Safety: {$phpTS}");
        $this->line("  Architecture: {$phpArch}");
        $this->line("  php.ini: {$phpIni}");
        $this->line("  Extension dir: {$phpExtDir}");
        $this->newLine();

        // Check OS
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->error('This command is for Windows only.');
            $this->line('On Linux/Mac, use: pecl install mongodb');
            return 1;
        }

        $dllPath = $phpExtDir . DIRECTORY_SEPARATOR . 'php_mongodb.dll';

        // Check if DLL exists
        if (File::exists($dllPath) && !$this->option('force')) {
            $this->warn("DLL already exists at: {$dllPath}");
            if (!$this->confirm('Do you want to reinstall?', false)) {
                return 0;
            }
        }

        $this->info('Step 1: Download MongoDB DLL');
        $this->line('Please download the correct DLL:');
        $this->line('  URL: https://pecl.php.net/package/mongodb/1.21.1/windows');
        
        // Determine correct DLL version
        $dllVersion = '8.4'; // Use 8.4 for PHP 8.5+
        if (version_compare($phpVersion, '8.4', '<')) {
            $dllVersion = '8.1';
        }
        
        $dllFile = "php_mongodb-1.21.1-{$dllVersion}-ts-vs17-x64.zip";
        $this->line("  File: {$dllFile} (Thread Safe x64)");
        $this->newLine();

        $zipPath = $this->ask('Enter path to downloaded zip file', '');
        
        if (empty($zipPath) || !File::exists($zipPath)) {
            $this->error('File not found!');
            $this->line('Please download the DLL first and provide the path.');
            return 1;
        }

        $this->info('Step 2: Extracting and installing DLL...');
        
        $tempExtract = storage_path('app/temp_mongodb_extract');
        
        try {
            // Cleanup old extraction
            if (File::exists($tempExtract)) {
                File::deleteDirectory($tempExtract);
            }
            
            // Extract zip
            $zip = new \ZipArchive();
            if ($zip->open($zipPath) !== true) {
                $this->error('Failed to open zip file!');
                return 1;
            }
            
            File::makeDirectory($tempExtract, 0755, true);
            $zip->extractTo($tempExtract);
            $zip->close();
            
            // Find DLL
            $dllFile = $this->findFile($tempExtract, 'php_mongodb.dll');
            if (!$dllFile) {
                $this->error('php_mongodb.dll not found in archive!');
                File::deleteDirectory($tempExtract);
                return 1;
            }
            
            // Copy DLL
            File::copy($dllFile, $dllPath);
            $this->info("✅ DLL installed: {$dllPath}");
            
            // Find and copy libsasl.dll
            $libsaslFile = $this->findFile($tempExtract, 'libsasl.dll');
            if ($libsaslFile) {
                $phpRoot = dirname($phpIni);
                $libsaslDest = $phpRoot . DIRECTORY_SEPARATOR . 'libsasl.dll';
                File::copy($libsaslFile, $libsaslDest);
                $this->info("✅ libsasl.dll installed: {$libsaslDest}");
            }
            
            // Cleanup
            File::deleteDirectory($tempExtract);
            
        } catch (\Exception $e) {
            $this->error('Installation failed: ' . $e->getMessage());
            if (File::exists($tempExtract)) {
                File::deleteDirectory($tempExtract);
            }
            return 1;
        }

        $this->info('Step 3: Enabling in php.ini...');
        
        try {
            $iniContent = File::get($phpIni);
            
            // Check if already enabled
            if (preg_match('/^extension\s*=\s*mongodb/m', $iniContent)) {
                $this->info('✅ Extension already enabled in php.ini');
            } else {
                // Find insertion point (after other extensions)
                $lines = explode("\n", $iniContent);
                $insertIndex = -1;
                
                for ($i = 0; $i < count($lines); $i++) {
                    if (preg_match('/^extension\s*=\s*openssl/', $lines[$i])) {
                        $insertIndex = $i + 1;
                        break;
                    }
                }
                
                // Backup
                $backupPath = $phpIni . '.backup.' . date('Ymd_His');
                File::copy($phpIni, $backupPath);
                $this->line("✅ Backup created: {$backupPath}");
                
                // Insert extension line
                if ($insertIndex > 0) {
                    array_splice($lines, $insertIndex, 0, 'extension=mongodb');
                } else {
                    $lines[] = 'extension=mongodb';
                }
                
                File::put($phpIni, implode("\n", $lines));
                $this->info('✅ Added extension=mongodb to php.ini');
            }
            
        } catch (\Exception $e) {
            $this->error('Failed to edit php.ini: ' . $e->getMessage());
            $this->line('Please add manually: extension=mongodb');
            return 1;
        }

        $this->newLine();
        $this->info('✅ Installation complete!');
        $this->newLine();
        $this->line('Next steps:');
        $this->line('  1. Close and reopen your terminal');
        $this->line('  2. Run: php artisan mongodb:check');
        $this->line('  3. Start server: php artisan serve');
        
        return 0;
    }

    /**
     * Find a file recursively in directory
     */
    private function findFile($directory, $filename)
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory)
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getFilename() === $filename) {
                return $file->getPathname();
            }
        }
        
        return null;
    }
}
