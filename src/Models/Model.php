<?php

namespace IsrarMinhas\FilamentAiVisibility\Models;

use Illuminate\Database\Eloquent\Model as EloquentModel;

/**
 * Base model that applies the configurable table prefix.
 */
abstract class Model extends EloquentModel
{
    /**
     * Table name without the prefix.
     */
    protected string $baseTable;

    protected $guarded = ['id'];

    public function getTable(): string
    {
        return static::prefixedTable($this->baseTable);
    }

    public static function prefixedTable(string $table): string
    {
        return config('ai-visibility.table_prefix', 'ai_visibility_') . $table;
    }
}
