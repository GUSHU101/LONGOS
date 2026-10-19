<?php

namespace App\Saas\Database;

use Illuminate\Database\Schema\Blueprint;

class TenantBlueprint extends Blueprint
{
    /**
     * PostgreSQL and SQLite require index names to be unique across the
     * schema/database. Qualify every generated or explicit schema object name
     * with the active tenant prefix, not only the physical table name.
     */
    protected function indexCommand($type, $columns, $index, $algorithm = null, $operatorClass = null)
    {
        $columns = (array) $columns;
        $index   = $index ?: $this->createIndexName($type, $columns);
        $prefix  = $this->connection->getTablePrefix();

        if ($prefix !== '' && ! str_starts_with($index, $prefix)) {
            $index = $this->qualifiedName($prefix . $index);
        }

        return $this->addCommand(
            $type,
            compact('index', 'columns', 'algorithm', 'operatorClass'),
        );
    }

    private function qualifiedName(string $name): string
    {
        // PostgreSQL's identifier limit is 63 bytes. Keep a deterministic
        // suffix when a legacy explicit index name would exceed that limit.
        if (strlen($name) <= 63) {
            return $name;
        }

        return substr($name, 0, 50) . '_' . substr(hash('sha256', $name), 0, 12);
    }
}
