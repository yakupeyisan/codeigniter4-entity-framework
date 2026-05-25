<?php

namespace Yakupeyisan\CodeIgniter4\EntityFramework\Migrations;

use CodeIgniter\Database\BaseConnection;

/**
 * Executes T-SQL batches (GO-separated) for migration programmability scripts.
 */
final class SqlBatchExecutor
{
    public function __construct(private readonly BaseConnection $connection)
    {
    }

    public function execute(string $sql, string $objectType, string $objectName, bool $useCreateOrAlter = true): void
    {
        $sql = $useCreateOrAlter
            ? SqlProgrammabilityNormalizer::prepareForDeploy($sql, $objectType)
            : $sql;

        foreach ($this->splitBatches($sql) as $batch) {
            $batch = trim($batch);
            if ($batch === '') {
                continue;
            }
            $this->connection->query($batch);
        }

        log_message('debug', "SqlBatchExecutor: deployed [{$objectType}] [{$objectName}]");
    }

    /**
     * @return list<string>
     */
    public function splitBatches(string $sql): array
    {
        $sql = str_replace(["\r\n", "\r"], "\n", $sql);
        $parts = preg_split('/^\s*GO\s*$/mi', $sql) ?: [];

        return array_values(array_filter(array_map('trim', $parts), static fn ($p) => $p !== ''));
    }

    public static function dropStatement(string $objectType, string $schema, string $objectName): string
    {
        $full = '[' . $schema . '].[' . $objectName . ']';
        $objectType = strtolower($objectType);

        return match ($objectType) {
            'view' => "IF OBJECT_ID(N'{$schema}.{$objectName}', N'V') IS NOT NULL DROP VIEW {$full}",
            'sequence' => "IF OBJECT_ID(N'{$schema}.{$objectName}', N'SO') IS NOT NULL DROP SEQUENCE {$full}",
            'constraint' => "IF OBJECT_ID(N'[{$schema}].[{$objectName}]', N'D') IS NOT NULL "
                . "ALTER TABLE [{$schema}].[Card] DROP CONSTRAINT [{$objectName}]",
            'procedure' => self::dropFunctionFamily($schema, $objectName, 'P'),
            default => self::dropFunctionFamily($schema, $objectName, 'FN')
                . ';' . self::dropFunctionFamily($schema, $objectName, 'IF')
                . ';' . self::dropFunctionFamily($schema, $objectName, 'TF'),
        };
    }

    private static function dropFunctionFamily(string $schema, string $objectName, string $type): string
    {
        $typeChar = $type === 'FN' ? 'FN' : ($type === 'IF' ? 'IF' : ($type === 'TF' ? 'TF' : 'P'));

        return "IF OBJECT_ID(N'{$schema}.{$objectName}', N'{$typeChar}') IS NOT NULL DROP FUNCTION [{$schema}].[{$objectName}]";
    }
}
