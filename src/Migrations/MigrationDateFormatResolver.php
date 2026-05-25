<?php

namespace Yakupeyisan\CodeIgniter4\EntityFramework\Migrations;

use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * SQL Server ef_migrations insert: DATEFORMAT uyumsuzluğunda alternatif formatları dener,
 * çalışan formatı database.bootstrap.php (ve runtime config) içine yazar.
 */
final class MigrationDateFormatResolver
{
    /** @var list<string> */
    private const ALLOWED_SET_DATEFORMAT = ['ymd', 'dmy', 'mdy', 'ydm', 'myd', 'dym'];

    /**
     * @return list<array{method: string, phpFormat: string, mssqlDateFormat?: string}>
     */
    public static function candidates(BaseConnection $connection): array
    {
        $fromDbConfig = 'Y-m-d H:i:s';
        try {
            $df = $connection->dateFormat ?? null;
            if (is_array($df) && ! empty($df['datetime'])) {
                $fromDbConfig = (string) $df['datetime'];
            }
        } catch (\Throwable) {
        }

        $appMssql = 'ymd';
        try {
            if (function_exists('config') && config('App') !== null) {
                $appMssql = strtolower((string) (config('App')->mssqlDateFormat ?? 'ymd'));
            }
        } catch (\Throwable) {
        }
        if (! in_array($appMssql, self::ALLOWED_SET_DATEFORMAT, true)) {
            $appMssql = 'ymd';
        }

        $list = [
            ['method' => 'convert120', 'phpFormat' => 'Y-m-d H:i:s'],
            ['method' => 'setDateFormat', 'phpFormat' => 'Ymd H:i:s', 'mssqlDateFormat' => 'ymd'],
            ['method' => 'setDateFormat', 'phpFormat' => 'Y-m-d H:i:s', 'mssqlDateFormat' => 'ymd'],
            ['method' => 'setDateFormat', 'phpFormat' => 'd/m/Y H:i:s', 'mssqlDateFormat' => 'dmy'],
            ['method' => 'setDateFormat', 'phpFormat' => 'm/d/Y H:i:s', 'mssqlDateFormat' => 'mdy'],
        ];

        if ($fromDbConfig !== 'Y-m-d H:i:s') {
            $list[] = ['method' => 'convert120', 'phpFormat' => $fromDbConfig];
            $list[] = ['method' => 'setDateFormat', 'phpFormat' => $fromDbConfig, 'mssqlDateFormat' => $appMssql];
        }

        return $list;
    }

    /**
     * @return array{method: string, phpFormat: string, mssqlDateFormat?: string}
     */
    public static function insertAppliedAt(
        BaseConnection $connection,
        string $table,
        string $timestamp,
        string $name,
        ?DateTimeInterface $appliedAt = null
    ): array {
        $appliedAt ??= new DateTimeImmutable();
        $lastError = null;

        foreach (self::candidates($connection) as $candidate) {
            try {
                self::tryInsert($connection, $table, $timestamp, $name, $appliedAt, $candidate);
                self::persistResolvedFormat($candidate);
                self::applyToConnection($connection, $candidate);
                log_message(
                    'info',
                    'ef_migrations: applied_at yazıldı (method=' . $candidate['method']
                    . ', phpFormat=' . $candidate['phpFormat']
                    . (isset($candidate['mssqlDateFormat']) ? ', mssqlDateFormat=' . $candidate['mssqlDateFormat'] : '')
                    . ')'
                );

                return $candidate;
            } catch (\Throwable $e) {
                $lastError = $e;
                if (! self::isDateConversionError($e)) {
                    throw $e;
                }
                log_message(
                    'debug',
                    'ef_migrations datetime format denemesi başarısız: '
                    . json_encode($candidate, JSON_UNESCAPED_UNICODE)
                    . ' — ' . $e->getMessage()
                );
            }
        }

        throw new \RuntimeException(
            'ef_migrations tablosuna kayıt yazılamadı: uyumlu datetime formatı bulunamadı. '
            . ($lastError !== null ? $lastError->getMessage() : ''),
            0,
            $lastError
        );
    }

    /**
     * @param array{method: string, phpFormat: string, mssqlDateFormat?: string} $candidate
     */
    private static function tryInsert(
        BaseConnection $connection,
        string $table,
        string $timestamp,
        string $name,
        DateTimeInterface $appliedAt,
        array $candidate
    ): void {
        if ($candidate['method'] === 'convert120') {
            $iso = $appliedAt->format('Y-m-d H:i:s');
            $sql = 'INSERT INTO [' . $table . '] ([timestamp], [name], [applied_at]) '
                . 'VALUES (?, ?, CONVERT(DATETIME, ?, 120))';
            $connection->query($sql, [$timestamp, $name, $iso]);

            return;
        }

        $mssqlDf = strtolower((string) ($candidate['mssqlDateFormat'] ?? 'ymd'));
        if (! in_array($mssqlDf, self::ALLOWED_SET_DATEFORMAT, true)) {
            $mssqlDf = 'ymd';
        }

        $connection->query('SET DATEFORMAT ' . $mssqlDf);
        $literal = $appliedAt->format($candidate['phpFormat']);
        $sql = 'INSERT INTO [' . $table . '] ([timestamp], [name], [applied_at]) VALUES (?, ?, ?)';
        $connection->query($sql, [$timestamp, $name, $literal]);
    }

    /**
     * Çözülen formatı oturum ve bağlantı üzerine uygular (ef_sql_objects vb. sonraki yazımlar için).
     *
     * @param array{method: string, phpFormat: string, mssqlDateFormat?: string} $candidate
     */
    public static function applyToConnection(BaseConnection $connection, array $candidate): void
    {
        $driver = strtolower($connection->getPlatform() ?? '');
        if ($driver !== 'sqlsrv' && $driver !== 'sqlserver') {
            return;
        }

        if ($candidate['method'] === 'setDateFormat' && ! empty($candidate['mssqlDateFormat'])) {
            $mssqlDf = strtolower((string) $candidate['mssqlDateFormat']);
            if (in_array($mssqlDf, self::ALLOWED_SET_DATEFORMAT, true)) {
                $connection->query('SET DATEFORMAT ' . $mssqlDf);
            }
        }

        try {
            $ref = new \ReflectionClass($connection);
            if (! $ref->hasProperty('dateFormat')) {
                return;
            }

            $prop = $ref->getProperty('dateFormat');
            $df = $prop->getValue($connection);
            if (! is_array($df)) {
                $df = [
                    'date'     => 'Y-m-d',
                    'datetime' => 'Y-m-d H:i:s',
                    'time'     => 'H:i:s',
                ];
            }
            $df['datetime'] = $candidate['phpFormat'];
            $prop->setValue($connection, $df);
        } catch (\Throwable) {
            log_message('debug', 'applyToConnection dateFormat: ' . $e->getMessage());
        }
    }

    /**
     * ef_sql_objects kaydı — datetime için ODBC/SET DATEFORMAT bağımsız CONVERT(…, 120).
     *
     * @param array{objectName: string, objectType: string, sqlHash: string} $object
     */
    public static function upsertSqlObjectRow(
        BaseConnection $connection,
        string $table,
        array $object,
        string $migrationTimestamp,
        string $migrationName,
        bool $exists
    ): void {
        $iso = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        if ($exists) {
            $sql = 'UPDATE [' . $table . '] SET [sql_hash] = ?, [migration_timestamp] = ?, [migration_name] = ?, '
                . '[updated_at] = CONVERT(DATETIME, ?, 120) WHERE [object_name] = ? AND [object_type] = ?';
            $connection->query($sql, [
                $object['sqlHash'],
                $migrationTimestamp,
                $migrationName,
                $iso,
                $object['objectName'],
                $object['objectType'],
            ]);

            return;
        }

        $sql = 'INSERT INTO [' . $table . '] ([object_name], [object_type], [sql_hash], [migration_timestamp], [migration_name], [updated_at]) '
            . 'VALUES (?, ?, ?, ?, ?, CONVERT(DATETIME, ?, 120))';
        $connection->query($sql, [
            $object['objectName'],
            $object['objectType'],
            $object['sqlHash'],
            $migrationTimestamp,
            $migrationName,
            $iso,
        ]);
    }

    private static function isDateConversionError(\Throwable $e): bool
    {
        $msg = strtolower($e->getMessage());

        return str_contains($msg, 'datetime')
            || str_contains($msg, 'dateformat')
            || str_contains($msg, '22007')
            || str_contains($msg, '241')
            || str_contains($msg, 'conversion')
            || str_contains($msg, 'arık dışı')
            || str_contains($msg, 'out-of-range')
            || str_contains($msg, 'dönüştürme');
    }

    /**
     * @param array{method: string, phpFormat: string, mssqlDateFormat?: string} $candidate
     */
    private static function persistResolvedFormat(array $candidate): void
    {
        if (! class_exists(\App\Services\DatabaseBootstrapService::class)) {
            return;
        }

        if (! \App\Services\DatabaseBootstrapService::hasBootstrap()) {
            self::persistRuntimeConfigOnly($candidate);

            return;
        }

        $cfg = \App\Services\DatabaseBootstrapService::load();
        if ($cfg === null) {
            return;
        }

        if (! isset($cfg['dateFormat']) || ! is_array($cfg['dateFormat'])) {
            $cfg['dateFormat'] = [
                'date'     => 'Y-m-d',
                'datetime' => 'Y-m-d H:i:s',
                'time'     => 'H:i:s',
            ];
        }

        $cfg['dateFormat']['datetime'] = $candidate['phpFormat'];

        if ($candidate['method'] === 'setDateFormat' && ! empty($candidate['mssqlDateFormat'])) {
            $cfg['mssqlDateFormat'] = strtolower((string) $candidate['mssqlDateFormat']);
        } elseif ($candidate['method'] === 'convert120') {
            $cfg['mssqlDateFormat'] = $cfg['mssqlDateFormat'] ?? 'ymd';
        }

        \App\Services\DatabaseBootstrapService::save($cfg);
        self::persistRuntimeConfigOnly($candidate, $cfg['dateFormat'], $cfg['mssqlDateFormat'] ?? null);

        log_message('info', 'database.bootstrap.php dateFormat güncellendi: ' . json_encode($cfg['dateFormat']));
    }

    /**
     * @param array{date?: string, datetime?: string, time?: string}|null $dateFormat
     */
    private static function persistRuntimeConfigOnly(
        array $candidate,
        ?array $dateFormat = null,
        ?string $mssqlDateFormat = null
    ): void {
        if (! function_exists('config')) {
            return;
        }

        try {
            $db = config('Database');
            if ($db !== null) {
                if ($dateFormat !== null) {
                    $db->default['dateFormat'] = array_merge(
                        $db->default['dateFormat'] ?? [],
                        $dateFormat
                    );
                } else {
                    $db->default['dateFormat']['datetime'] = $candidate['phpFormat'];
                }
            }

            $mssql = $mssqlDateFormat ?? ($candidate['mssqlDateFormat'] ?? null);
            if ($mssql !== null && config('App') !== null) {
                config('App')->mssqlDateFormat = $mssql;
            }
        } catch (\Throwable $e) {
            log_message('debug', 'Runtime config dateFormat güncellenemedi: ' . $e->getMessage());
        }
    }
}
