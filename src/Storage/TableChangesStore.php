<?php

declare(strict_types=1);

namespace Keboola\OutputMapping\Storage;

use Keboola\OutputMapping\Mapping\MappingFromConfigurationSchemaColumn;
use Keboola\OutputMapping\Mapping\MappingFromConfigurationSchemaPrimaryKey;

class TableChangesStore
{
    /** @var MappingFromConfigurationSchemaColumn[] $missingColumns */
    private array $missingColumns = [];

    private ?MappingFromConfigurationSchemaPrimaryKey $primaryKey = null;

    public function hasMissingColumns(): bool
    {
        return count($this->missingColumns) > 0;
    }

    public function getMissingColumns(): array
    {
        return $this->missingColumns;
    }

    public function addMissingColumn(MappingFromConfigurationSchemaColumn $missingColumn): void
    {
        $this->missingColumns[] = $missingColumn;
    }

    public function getPrimaryKey(): ?MappingFromConfigurationSchemaPrimaryKey
    {
        return $this->primaryKey;
    }

    public function setPrimaryKey(?MappingFromConfigurationSchemaPrimaryKey $primaryKey): void
    {
        $this->primaryKey = $primaryKey;
    }
}
