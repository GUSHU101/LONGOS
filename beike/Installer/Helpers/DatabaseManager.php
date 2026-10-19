<?php

namespace Beike\Installer\Helpers;

use App\Saas\Support\DatabaseReadiness;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Output\BufferedOutput;

class DatabaseManager
{
    /**
     * Migrate and seed the database.
     *
     * @return array
     */
    public function migrateAndSeed()
    {
        $migration = $this->migrate();
        if (($migration['status'] ?? '') !== 'success') {
            return $migration;
        }

        return $this->seed();
    }

    /** Run schema migrations as an independently resumable web phase. */
    public function migrate(): array
    {
        $outputLog = new BufferedOutput;

        $this->sqlite($outputLog);

        try {
            // The environment step rejects non-empty databases, so normal
            // migrations are sufficient here and no existing database can be
            // dropped as a side effect of an installation request.
            $exitCode = Artisan::call('migrate', [
                '--force'          => true,
                '--no-interaction' => true,
            ], $outputLog);
            if ($exitCode !== 0) {
                return $this->response('Database migration failed.', 'error', $outputLog);
            }
            DatabaseReadiness::assertMigrated((string) config('database.default'));
            gc_collect_cycles();
        } catch (\Throwable $e) {
            return $this->response($e->getMessage(), 'error', $outputLog);
        }

        return $this->response(
            trans('installer::installer_messages.installing.schema_complete'),
            'success',
            $outputLog,
        );
    }

    /** Seed base commerce data as an independently resumable web phase. */
    public function seed(): array
    {
        $outputLog = new BufferedOutput;

        try {
            // Seeders are idempotent and intentionally run without one outer
            // transaction. MySQL migrations and compatible plugin seeders may
            // execute DDL that implicitly commits, which would otherwise end
            // with "no active transaction" after successful work.
            $exitCode = Artisan::call('db:seed', [
                '--force'          => true,
                '--no-interaction' => true,
            ], $outputLog);
            if ($exitCode !== 0) {
                return $this->response('Database seeding failed.', 'error', $outputLog);
            }
            DatabaseReadiness::assertSeeded((string) config('database.default'));
            gc_collect_cycles();
        } catch (\Throwable $e) {
            return $this->response($e->getMessage(), 'error', $outputLog);
        }

        return $this->response(trans('installer::installer_messages.final.finished'), 'success', $outputLog);
    }

    /**
     * Return a formatted error messages.
     *
     * @param string                                           $message
     * @param string                                           $status
     * @param \Symfony\Component\Console\Output\BufferedOutput $outputLog
     * @return array
     */
    private function response($message, $status, BufferedOutput $outputLog)
    {
        return [
            'status'      => $status,
            'message'     => $message,
            'dbOutputLog' => $outputLog->fetch(),
        ];
    }

    /**
     * Check database type. If SQLite, then create the database file.
     *
     * @param \Symfony\Component\Console\Output\BufferedOutput $outputLog
     */
    private function sqlite(BufferedOutput $outputLog)
    {
        if (DB::connection() instanceof SQLiteConnection) {
            $database = DB::connection()->getDatabaseName();
            if (! file_exists($database)) {
                touch($database);
                DB::reconnect(Config::get('database.default'));
            }
            $outputLog->write('Using SqlLite database: ' . $database, 1);
        }
    }
}
