<?php

namespace Yakupeyisan\CodeIgniter4\EntityFramework\Migrations;

/**
 * Normalizes SQL programmability scripts for stable hashing and deployment.
 */
final class SqlProgrammabilityNormalizer
{
    public static function normalize(string $sql): string
    {
        $sql = str_replace(["\r\n", "\r"], "\n", $sql);
        $sql = str_replace("\t", '    ', $sql);
        $lines = explode("\n", $sql);
        $out = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || strcasecmp($trimmed, 'GO') === 0) {
                continue;
            }
            if (preg_match('/^USE\s+\[/i', $trimmed)) {
                continue;
            }
            if (preg_match('/^SET\s+(ANSI_NULLS|QUOTED_IDENTIFIER|NOCOUNT)/i', $trimmed)) {
                continue;
            }
            $out[] = $trimmed;
        }

        $joined = implode("\n", $out);
        $joined = preg_replace('/\s+/', ' ', $joined) ?? $joined;

        return trim($joined);
    }

    public static function hash(string $sql): string
    {
        return hash('sha256', self::normalize($sql));
    }

    /**
     * Prepare SQL for deployment (CREATE OR ALTER where supported).
     */
    public static function prepareForDeploy(string $sql, string $objectType): string
    {
        $objectType = strtolower($objectType);

        if ($objectType === 'sequence') {
            return self::prepareSequence($sql);
        }

        if ($objectType === 'view') {
            return self::toCreateOrAlter($sql, 'VIEW');
        }

        if ($objectType === 'function' || $objectType === 'procedure') {
            return self::toCreateOrAlter($sql, $objectType === 'procedure' ? 'PROCEDURE' : 'FUNCTION');
        }

        return $sql;
    }

    private static function toCreateOrAlter(string $sql, string $keyword): string
    {
        $pattern = '/^\s*CREATE\s+' . $keyword . '\b/i';

        return (string) preg_replace($pattern, 'CREATE OR ALTER ' . $keyword, $sql, 1);
    }

    private static function prepareSequence(string $sql): string
    {
        if (preg_match('/CREATE\s+SEQUENCE\s+(\[[^\]]+\]\.\[[^\]]+\]|\[[^\]]+\])/i', $sql, $m)) {
            $seq = $m[1];
            if (! preg_match('/IF\s+OBJECT_ID/i', $sql)) {
                $create = trim($sql);
                $sql = "IF OBJECT_ID(N'dbo." . self::bracketName($seq) . "', N'SO') IS NULL\nBEGIN\n    {$create}\nEND";
            }
        }

        return $sql;
    }

    private static function bracketName(string $qualified): string
    {
        $qualified = trim($qualified, '[]');

        if (str_contains($qualified, '].[')) {
            $parts = explode('].[', $qualified);

            return end($parts);
        }

        return $qualified;
    }
}
