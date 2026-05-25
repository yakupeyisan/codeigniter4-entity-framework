<?php

namespace Yakupeyisan\CodeIgniter4\EntityFramework\Migrations;

use ReflectionClass;
use Yakupeyisan\CodeIgniter4\EntityFramework\Attributes\GenerateQuery;
use Yakupeyisan\CodeIgniter4\EntityFramework\Attributes\NotMapped;
use Yakupeyisan\CodeIgniter4\EntityFramework\Attributes\Table;

/**
 * Discovers #[GenerateQuery] attributes on entity / SqlObjects classes.
 */
final class ProgrammabilityDiscovery
{
    /**
     * @return list<array{objectName: string, objectType: string, schema: string, sql: string, deployOrder: int, sqlHash: string, sourceClass: string}>
     */
    public static function discover(): array
    {
        $objects = [];
        $paths = [];

        if (defined('APPPATH')) {
            $paths[] = APPPATH . 'Entities';
            $paths[] = APPPATH . 'Database' . DIRECTORY_SEPARATOR . 'SqlObjects';
        }

        foreach ($paths as $root) {
            if (! is_dir($root)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $class = self::classFromFile($file->getPathname());
                if ($class === null || ! class_exists($class)) {
                    continue;
                }
                try {
                    $reflection = new ReflectionClass($class);
                } catch (\Throwable) {
                    continue;
                }
                foreach ($reflection->getAttributes(GenerateQuery::class) as $attr) {
                    $instance = $attr->newInstance();
                    $sql = self::resolveSql($instance, $file->getPathname());
                    if ($sql === null || trim($sql) === '') {
                        continue;
                    }
                    $objects[] = [
                        'objectName'  => $instance->objectName,
                        'objectType'  => strtolower($instance->objectType),
                        'schema'      => $instance->schema,
                        'sql'         => trim($sql),
                        'deployOrder' => $instance->deployOrder,
                        'sqlHash'     => SqlProgrammabilityNormalizer::hash($sql),
                        'sourceClass' => $class,
                    ];
                }
            }
        }

        usort($objects, static fn ($a, $b) => $a['deployOrder'] <=> $b['deployOrder']
            ?: strcmp($a['objectName'], $b['objectName']));

        $deduped = [];
        foreach ($objects as $object) {
            $key = $object['objectType'] . ':' . $object['objectName'];
            if (! isset($deduped[$key])) {
                $deduped[$key] = $object;
            }
        }

        return array_values($deduped);
    }

    /**
     * Skip migration table creation for TVF/view result types; keep real tables (e.g. Card + sequence).
     */
    public static function shouldSkipTableMigration(string $class): bool
    {
        if (! self::classHasGenerateQuery($class)) {
            return false;
        }

        try {
            $reflection = new ReflectionClass($class);
        } catch (\Throwable) {
            return false;
        }

        if ($reflection->getAttributes(NotMapped::class) !== []) {
            return true;
        }

        foreach ($reflection->getAttributes(Table::class) as $tableAttr) {
            $table = $tableAttr->newInstance();
            $name = $table->name ?? '';
            if ($name !== '' && preg_match('/^fn/i', $name)) {
                return true;
            }
        }

        $tableName = null;
        foreach ($reflection->getAttributes(Table::class) as $tableAttr) {
            $table = $tableAttr->newInstance();
            $tableName = $table->name ?? null;
            break;
        }

        foreach ($reflection->getAttributes(GenerateQuery::class) as $attr) {
            $gq = $attr->newInstance();
            if (strtolower($gq->objectType) !== 'view' || $tableName === null) {
                continue;
            }
            if (strcasecmp($tableName, $gq->objectName) === 0) {
                return true;
            }
        }

        return false;
    }

    public static function classHasGenerateQuery(string $class): bool
    {
        if (! class_exists($class)) {
            return false;
        }
        try {
            $reflection = new ReflectionClass($class);

            return $reflection->getAttributes(GenerateQuery::class) !== [];
        } catch (\Throwable) {
            return false;
        }
    }

    private static function resolveSql(GenerateQuery $instance, string $filePath): ?string
    {
        if ($instance->sql !== '') {
            return $instance->sql;
        }

        return null;
    }

    private static function classFromFile(string $path): ?string
    {
        $content = @file_get_contents($path);
        if ($content === false) {
            return null;
        }
        if (! preg_match('/namespace\s+([^;]+);/', $content, $ns)) {
            return null;
        }
        if (! preg_match('/\bclass\s+(\w+)/', $content, $cls)) {
            return null;
        }

        return trim($ns[1]) . '\\' . trim($cls[1]);
    }
}
