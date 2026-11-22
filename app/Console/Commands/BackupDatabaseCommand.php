<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

#[Signature('finance:backup {--keep= : How many backups to keep (default config finance.backup_keep)}')]
#[Description('Back up the database into storage/backups (pg_dump for PostgreSQL, file copy for SQLite)')]
class BackupDatabaseCommand extends Command
{
    public function handle(): int
    {
        $connection = config('database.default');
        $config = config("database.connections.{$connection}");
        $directory = config('finance.backup_path');
        $timestamp = now()->format('Y-m-d_His');

        File::ensureDirectoryExists($directory);

        $path = match ($config['driver']) {
            'pgsql' => $this->dumpPostgres($config, "{$directory}/{$config['database']}_{$timestamp}.dump"),
            'sqlite' => $this->copySqlite($config, "{$directory}/database_{$timestamp}.sqlite"),
            default => null,
        };

        if ($path === null) {
            $this->components->error("Backups are not supported for the [{$config['driver']}] driver.");

            return self::FAILURE;
        }

        $this->prune($directory, (int) ($this->option('keep') ?? config('finance.backup_keep')));

        $this->components->info("Backup created: {$path}");

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function dumpPostgres(array $config, string $path): ?string
    {
        $result = Process::env(['PGPASSWORD' => (string) ($config['password'] ?? '')])
            ->timeout(600)
            ->run([
                'pg_dump',
                '--format=custom',
                '--no-owner',
                '--host='.$config['host'],
                '--port='.$config['port'],
                '--username='.$config['username'],
                '--file='.$path,
                $config['database'],
            ]);

        if ($result->failed()) {
            $this->components->error(trim($result->errorOutput()) ?: 'pg_dump failed.');

            return null;
        }

        return $path;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function copySqlite(array $config, string $path): ?string
    {
        return File::copy($config['database'], $path) ? $path : null;
    }

    private function prune(string $directory, int $keep): void
    {
        if ($keep < 1) {
            return;
        }

        collect(File::files($directory))
            ->sortByDesc(fn ($file) => $file->getMTime())
            ->slice($keep)
            ->each(fn ($file) => File::delete($file->getPathname()));
    }
}
