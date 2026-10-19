<?php

namespace Beike\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class Sequence extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'postgreSQL:sequence';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fix the last value of the sequence';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        if (DB::getDriverName() !== 'pgsql') {
            return self::SUCCESS;
        }

        $prefix  = DB::connection()->getTablePrefix();
        $results = DB::select(
            'SELECT schemaname, sequencename FROM pg_sequences'
            . ' WHERE schemaname = current_schema()'
            . ' AND LEFT(sequencename, LENGTH(?)) = ?',
            [$prefix, $prefix],
        );
        foreach ($results as $row) {
            if (! str_ends_with($row->sequencename, '_id_seq')) {
                continue;
            }

            $tableName = substr($row->sequencename, 0, -strlen('_id_seq'));
            $maxId     = $this->getTableMax($tableName);
            $sequence  = $this->quotedIdentifier($row->schemaname) . '.' . $this->quotedIdentifier($row->sequencename);
            DB::statement('SELECT setval(?::regclass, ?, ?)', [
                $sequence,
                max(1, (int) $maxId),
                (int) $maxId > 0,
            ]);
        }

        return self::SUCCESS;
    }

    private function getTableMax(string $table): int
    {
        $results = DB::selectOne('SELECT MAX(id) as id FROM ' . $this->quotedIdentifier($table));

        return (int) ($results->id ?? 0);
    }

    private function quotedIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }
}
