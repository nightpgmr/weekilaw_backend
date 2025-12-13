<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\Process\Process;

class BackupController extends Controller
{
    private function getDatabaseType(): string
    {
        return config('database.default');
    }

    private function createBackup(string $filename): string
    {
        $dbType = $this->getDatabaseType();
        $backupPath = Storage::disk('local')->path($filename);

        // Ensure backups directory exists
        $backupDir = dirname($backupPath);
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        if ($dbType === 'mysql') {
            return $this->createMySQLBackup($backupPath);
        } elseif ($dbType === 'sqlite') {
            return $this->createSQLiteBackup($backupPath);
        }

        throw new \Exception("Unsupported database type: {$dbType}");
    }

    private function createMySQLBackup(string $backupPath): string
    {
        $config = config('database.connections.mysql');

        // Try Docker exec first if MySQL container is available
        if ($this->isMySQLContainerAvailable()) {
            $command = [
                'docker', 'exec', 'weekilaw_mysql',
                'mysqldump',
                '--user=' . $config['username'],
                '--password=' . $config['password'],
                $config['database']
            ];
        } else {
            // Fallback to direct mysqldump
            $command = [
                'mysqldump',
                '--user=' . $config['username'],
                '--password=' . $config['password'],
                '--host=' . $config['host'],
                '--port=' . $config['port'],
                $config['database']
            ];
        }

        $process = new Process($command);
        $process->setTimeout(300); // 5 minutes timeout
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \Exception('MySQL dump failed: ' . $process->getErrorOutput());
        }

        file_put_contents($backupPath, $process->getOutput());
        return $backupPath;
    }

    private function isRunningInDocker(): bool
    {
        return file_exists('/.dockerenv') || getenv('DOCKER_CONTAINER') === 'true';
    }

    private function isMySQLContainerAvailable(): bool
    {
        $process = new Process(['docker', 'ps', '--filter', 'name=weekilaw_mysql', '--format', '{{.Names}}']);
        $process->run();
        return trim($process->getOutput()) === 'weekilaw_mysql';
    }

    private function createSQLiteBackup(string $backupPath): string
    {
        $dbPath = database_path('database.sqlite');

        if (!file_exists($dbPath)) {
            throw new \Exception('SQLite database file not found');
        }

        if (!copy($dbPath, $backupPath)) {
            throw new \Exception('Failed to copy SQLite database');
        }

        return $backupPath;
    }

    public function create(Request $request): RedirectResponse
    {
        try {
            $dbType = $this->getDatabaseType();
            $extension = $dbType === 'mysql' ? 'sql' : 'sqlite';
            $timestamp = now()->format('Ymd_His');
            $filename = "backups/backup_{$timestamp}.{$extension}";

            $this->createBackup($filename);

            return back()->with('success', "Backup created: {$filename}");
        } catch (\Exception $e) {
            return back()->with('error', 'Failed to create backup: ' . $e->getMessage());
        }
    }

    public function download(): BinaryFileResponse
    {
        try {
            $dbType = $this->getDatabaseType();
            $extension = $dbType === 'mysql' ? 'sql' : 'sqlite';
            $timestamp = now()->format('Ymd_His');
            $filename = "backups/backup_{$timestamp}.{$extension}";

            $backupPath = $this->createBackup($filename);

            return response()->download($backupPath)->deleteFileAfterSend(true);
        } catch (\Exception $e) {
            abort(500, 'Failed to create backup: ' . $e->getMessage());
        }
    }

    public function restore(Request $request): RedirectResponse
    {
        try {
            $validated = $request->validate([
                'file' => ['required', 'file', 'max:102400'], // Allow larger files
            ]);

            $file = $validated['file'];
            $extension = strtolower($file->getClientOriginalExtension());
            $dbType = $this->getDatabaseType();

            // Validate file type based on current database
            if ($dbType === 'mysql' && $extension !== 'sql') {
                return back()->with('error', 'Only SQL dump files (.sql) are allowed for MySQL databases');
            } elseif ($dbType === 'sqlite' && $extension !== 'sqlite') {
                return back()->with('error', 'Only SQLite database files (.sqlite) are allowed for SQLite databases');
            }

            $storedPath = $file->storeAs('backups/restores', $file->getClientOriginalName());
            $fullRestorePath = Storage::disk('local')->path($storedPath);

            if ($dbType === 'mysql') {
                $this->restoreMySQLBackup($fullRestorePath);
            } elseif ($dbType === 'sqlite') {
                $this->restoreSQLiteBackup($fullRestorePath);
            }

            return back()->with('success', "Database restored successfully from: {$file->getClientOriginalName()}");
        } catch (\Exception $e) {
            return back()->with('error', 'Failed to restore database: ' . $e->getMessage());
        }
    }

    private function restoreMySQLBackup(string $backupPath): void
    {
        $config = config('database.connections.mysql');

        // Create backup of current database
        $currentBackupPath = Storage::disk('local')->path('backups/current_backup_' . now()->format('Ymd_His') . '.sql');
        $this->createMySQLBackup($currentBackupPath);

        // Try Docker exec first if MySQL container is available
        if ($this->isMySQLContainerAvailable()) {
            $command = [
                'docker', 'exec', '-i', 'weekilaw_mysql',
                'mysql',
                '--user=' . $config['username'],
                '--password=' . $config['password'],
                $config['database']
            ];
        } else {
            $command = [
                'mysql',
                '--user=' . $config['username'],
                '--password=' . $config['password'],
                '--host=' . $config['host'],
                '--port=' . $config['port'],
                $config['database']
            ];
        }

        $process = new Process($command);
        $process->setInput(file_get_contents($backupPath));
        $process->setTimeout(300);
        $process->run();

        if (!$process->isSuccessful()) {
            // Try to restore the current backup if restore failed
            if ($this->isMySQLContainerAvailable()) {
                $restoreCommand = [
                    'docker', 'exec', '-i', 'weekilaw_mysql',
                    'mysql',
                    '--user=' . $config['username'],
                    '--password=' . $config['password'],
                    $config['database']
                ];
            } else {
                $restoreCommand = array_merge($command, ['<', $currentBackupPath]);
            }
            $restoreProcess = new Process($restoreCommand);
            $restoreProcess->run();

            throw new \Exception('MySQL restore failed: ' . $process->getErrorOutput());
        }
    }

    private function restoreSQLiteBackup(string $backupPath): void
    {
        $dbPath = database_path('database.sqlite');
        $currentBackupPath = database_path('database.sqlite.backup.' . now()->format('Ymd_His'));

        // Create backup of current database
        if (file_exists($dbPath)) {
            copy($dbPath, $currentBackupPath);
        }

        // Perform the restore
        if (!copy($backupPath, $dbPath)) {
            // Restore the backup if copy failed
            if (file_exists($currentBackupPath)) {
                copy($currentBackupPath, $dbPath);
            }
            throw new \Exception('Failed to restore SQLite database');
        }
    }
}


