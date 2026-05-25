<?php

namespace Yakupeyisan\CodeIgniter4\EntityFramework\Migrations;

use CodeIgniter\Database\BaseConnection;

/**
 * MigrationManager - Manages migrations
 * Equivalent to migration commands in EF Core (Add-Migration, Update-Database, etc.)
 */
class MigrationManager
{
    /** CI4 `migrations` tablosundan ayrı; şema çakışmasını önler. */
    private const MIGRATIONS_TABLE = 'ef_migrations';

    private const SQL_OBJECTS_TABLE = 'ef_sql_objects';

    /** CI4 MigrationRunner ile çakışmaması için ayrı dizin (Forge yerine Connection bekler). */
    public const MIGRATIONS_NAMESPACE = 'App\\Database\\EfMigrations';

    private const MIGRATIONS_DIR = 'EfMigrations';

    private ?BaseConnection $connection;
    private string $migrationsPath;

    /** @var array{method: string, phpFormat: string, mssqlDateFormat?: string}|null */
    private ?array $resolvedDateFormat = null;

    public function __construct(?BaseConnection $connection = null, ?string $migrationsPath = null, bool $requireConnection = true)
    {
        $this->migrationsPath = $migrationsPath ?? APPPATH . 'Database/' . self::MIGRATIONS_DIR . '/';

        if ($connection !== null) {
            $this->connection = $connection;
        } elseif ($requireConnection) {
            $this->connection = \Config\Database::connect();
            $this->ensureDatabaseExists();
        } else {
            $this->connection = null;
        }
    }
    
    /**
     * Ensure database exists, create if not
     */
    private function ensureDatabaseExists(): void
    {
        try {
            // Try to connect to the database
            $this->connection->initialize();
        } catch (\Exception $e) {
            // If connection fails, try to create database
            $dbConfig = new \Config\Database();
            $defaultConfig = $dbConfig->default;
            $database = $defaultConfig['database'] ?? null;
            
            if (empty($database)) {
                throw new \RuntimeException('Database name not configured');
            }
            
            // Use database provider to create database
            $provider = \Yakupeyisan\CodeIgniter4\EntityFramework\Providers\DatabaseProviderFactory::getProvider($this->connection);
            
            try {
                // Check if database exists
                $checkSql = $provider->getCheckDatabaseExistsSql($database);
                if (!empty($checkSql)) {
                    $query = $this->connection->query($checkSql);
                    $exists = $query->getNumRows() > 0;
                    
                    if (!$exists) {
                        // Create database
                        $createSql = $provider->getCreateDatabaseSql($database);
                        if (!empty($createSql)) {
                            $this->connection->query($createSql);
                            echo "Database '{$database}' created successfully.\n";
                        }
                    }
                }
            } catch (\Exception $createException) {
                // If creation fails, re-throw original exception
                throw $e;
            }
            
            // Re-initialize connection after database creation
            $this->connection->initialize();
        }
    }
    

    /**
     * Add migration (equivalent to Add-Migration)
     */
    public function addMigration(string $migrationName, callable $up = null, callable $down = null): string
    {
        $timestamp = date('YmdHis');
        $className = 'Migration_' . $timestamp . '_' . $migrationName;
        $fileName = $timestamp . '_' . $migrationName . '.php';
        $filePath = $this->migrationsPath . $fileName;

        // Generate content
        if ($up !== null && $down !== null) {
            // Use provided callables
            $content = $this->generateMigrationContentFromCallables($className, $up, $down);
        } else {
            // Try to generate from ApplicationDbContext
            $generated = $this->generateMigrationFromContext();
            
            error_log('addMigration: generated is ' . ($generated !== null ? 'not null' : 'null'));
            
            // Use generated code if available, otherwise use template
            if ($generated !== null && is_array($generated) && isset($generated['up']) && isset($generated['down']) && !empty(trim($generated['up'])) && !empty(trim($generated['down']))) {
                error_log('addMigration: using generated code. Up length: ' . strlen($generated['up']) . ', Down length: ' . strlen($generated['down']));
                $content = $this->generateMigrationContentFromCode($className, $generated['up'], $generated['down']);
            } else {
                error_log('addMigration: falling back to template. Generated: ' . ($generated !== null ? 'not null but invalid' : 'null'));
                $content = $this->generateMigrationContentFromCode($className, $this->getDefaultUpCode(), $this->getDefaultDownCode());
            }
        }

        if (! is_dir($this->migrationsPath)) {
            mkdir($this->migrationsPath, 0755, true);
        }

        file_put_contents($filePath, $content);

        return $fileName;
    }

    /**
     * Generate migration content from callables
     */
    private function generateMigrationContentFromCallables(string $className, callable $up, callable $down): string
    {
        // For callables, we'll use the template approach
        // In a full implementation, we'd need to serialize the builder operations
        return $this->generateMigrationContentFromCode($className, $this->getDefaultUpCode(), $this->getDefaultDownCode());
    }

    /**
     * Generate migration content from code strings
     */
    private function generateMigrationContentFromCode(string $className, string $upCode, string $downCode): string
    {
        // Indent the code
        $upCode = $this->indentCode($upCode, 8);
        $downCode = $this->indentCode($downCode, 8);
        
        return <<<PHP
<?php

namespace App\Database\EfMigrations;

use Yakupeyisan\CodeIgniter4\EntityFramework\Migrations\Migration;
use Yakupeyisan\CodeIgniter4\EntityFramework\Migrations\MigrationBuilder;
use Yakupeyisan\CodeIgniter4\EntityFramework\Migrations\ColumnBuilder;

class {$className} extends Migration
{
    public function up(): void
    {
        \$builder = new MigrationBuilder(\$this->connection);
        
{$upCode}
        
        \$builder->execute();
    }

    public function down(): void
    {
        \$builder = new MigrationBuilder(\$this->connection);
        
{$downCode}
        
        \$builder->execute();
    }
}
PHP;
    }

    /**
     * Indent code with specified number of spaces
     */
    private function indentCode(string $code, int $spaces): string
    {
        if (empty(trim($code))) {
            return $code;
        }
        
        $code = str_replace("\t", '    ', $code);
        $indent = str_repeat(' ', $spaces);
        $lines = explode("\n", $code);
        $indented = array_map(function($line) use ($indent) {
            // Don't indent empty lines
            if (trim($line) === '') {
                return $line;
            }
            return $indent . ltrim($line);
        }, $lines);
        return implode("\n", $indented);
    }

    /**
     * Generate migration from ApplicationDbContext
     * 
     * @param string|null $contextClass The fully qualified class name of the DbContext (e.g., 'App\EntityFramework\ApplicationDbContext')
     * @return array|null Returns array with 'up' and 'down' keys, or null if generation fails
     */
    public function generateMigrationFromContext(?string $contextClass = null): ?array
    {
        // Try to auto-detect ApplicationDbContext if not provided
        if ($contextClass === null) {
            // Common locations for ApplicationDbContext
            $possibleClasses = [
                'App\EntityFramework\ApplicationDbContext',
                'App\ApplicationDbContext',
                'ApplicationDbContext'
            ];
            
            foreach ($possibleClasses as $class) {
                if (class_exists($class)) {
                    $contextClass = $class;
                    break;
                }
            }
        }
        
        if ($contextClass === null || !class_exists($contextClass)) {
            error_log("Context class not found. Please provide the fully qualified class name of your DbContext.");
            return null;
        }
        
        try {
            $generator = new MigrationGenerator($contextClass, $this->connection);
            $result = $generator->generateMigrationCode();
            
            if (trim($result['up'] ?? '') === '' && trim($result['down'] ?? '') === '') {
                return null;
            }
            
            return $result;
        } catch (\Exception $e) {
            error_log("Error generating migration from context: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            return null;
        }
    }

    /**
     * Şema zaten uygulanmış (CLI / önceki kurulum) ama ef_migrations kaydı yoksa yalnızca kayıt yazar.
     *
     * @return int Damgalanan migration sayısı
     */
    public function reconcileAppliedMigrationsIfSchemaPresent(): int
    {
        if (! $this->schemaLooksInitialized()) {
            return 0;
        }

        $pending = $this->getPendingMigrations();
        if ($pending === []) {
            return 0;
        }

        $stamped = 0;
        foreach ($pending as $migration) {
            $this->stampMigrationAsApplied($migration);
            $stamped++;
        }

        $this->refreshConnection();

        return $stamped;
    }

    /**
     * Migration kaydı + SQL nesne registry (up() çalıştırmadan).
     */
    public function stampMigrationAsApplied(array $migration): void
    {
        $this->recordMigration($migration);
        $this->syncSqlObjectsRegistry($migration);
    }

    private function schemaLooksInitialized(): bool
    {
        $driver = strtolower($this->connection->getPlatform() ?? '');

        if ($driver === 'sqlsrv' || $driver === 'sqlserver') {
            return $this->sqlServerTableExists('settings')
                && $this->sqlServerTableExists('Users');
        }

        return $this->connection->tableExists('settings')
            && $this->connection->tableExists('Users');
    }

    /**
     * Update database (equivalent to Update-Database)
     */
    public function updateDatabase(?string $targetMigration = null): void
    {
        $this->reconcileAppliedMigrationsIfSchemaPresent();

        $migrations = $this->getPendingMigrations();
        
        if ($targetMigration !== null) {
            $migrations = array_filter($migrations, fn($m) => $m['name'] <= $targetMigration);
        }

        foreach ($migrations as $migration) {
            $this->runMigration($migration, 'up');
        }

        $this->refreshConnection();
    }

    /**
     * Remove migration (equivalent to Remove-Migration)
     */
    public function removeMigration(string $migrationName): bool
    {
        $files = glob($this->migrationsPath . '*_' . $migrationName . '.php');
        if (empty($files)) {
            return false;
        }

        foreach ($files as $file) {
            unlink($file);
        }

        return true;
    }

    /**
     * Rollback migration
     */
    public function rollbackMigration(int $steps = 1): void
    {
        $appliedMigrations = $this->getAppliedMigrations();
        $allMigrations = $this->getAllMigrations();
        
        // Create a map of applied migrations by timestamp+name
        $appliedMap = [];
        foreach ($appliedMigrations as $applied) {
            $key = $applied['timestamp'] . '_' . $applied['name'];
            $appliedMap[$key] = $applied;
        }
        
        // Match applied migrations with file paths from all migrations
        $migrationsToRollback = [];
        foreach ($allMigrations as $migration) {
            $key = $migration['timestamp'] . '_' . $migration['name'];
            if (isset($appliedMap[$key])) {
                $migrationsToRollback[] = $migration; // Use migration with 'file' key
            }
        }
        
        // Get last N migrations
        $migrationsToRollback = array_slice($migrationsToRollback, -$steps);

        foreach ($migrationsToRollback as $migration) {
            $this->runMigration($migration, 'down');
        }
    }

    /**
     * Get pending migrations
     */
    public function getPendingMigrations(): array
    {
        $allMigrations = $this->getAllMigrations();
        $appliedMigrations = $this->getAppliedMigrations();
        $appliedKeys = array_map(
            static fn (array $m): string => ($m['timestamp'] ?? '') . '_' . ($m['name'] ?? ''),
            $appliedMigrations
        );

        return array_filter(
            $allMigrations,
            static fn (array $m): bool => ! in_array($m['timestamp'] . '_' . $m['name'], $appliedKeys, true)
        );
    }

    /**
     * Get all migrations
     */
    public function getAllMigrations(): array
    {
        $files = glob($this->migrationsPath . '*.php');
        $migrations = [];

        foreach ($files as $file) {
            $name = basename($file, '.php');
            $parts = explode('_', $name, 2);
            if (count($parts) === 2) {
                $migrations[] = [
                    'timestamp' => $parts[0],
                    'name' => $parts[1],
                    'file' => $file
                ];
            }
        }

        usort($migrations, fn($a, $b) => $a['timestamp'] <=> $b['timestamp']);
        return $migrations;
    }

    /**
     * Get applied migrations
     */
    public function getAppliedMigrations(): array
    {
        if (! $this->connection->tableExists(self::MIGRATIONS_TABLE)) {
            $this->createMigrationsTable();

            return [];
        }

        $query = $this->connection->query(
            'SELECT [timestamp], [name] FROM [' . self::MIGRATIONS_TABLE . '] ORDER BY [timestamp] DESC'
        );
        $results = $query->getResultArray();

        return array_map(static function (array $r): array {
            return [
                'timestamp' => $r['timestamp'] ?? $r['Timestamp'] ?? '',
                'name'      => $r['name'] ?? $r['Name'] ?? '',
            ];
        }, $results);
    }

    /**
     * Run migration
     */
    private function runMigration(array $migration, string $direction): void
    {
        require_once $migration['file'];
        $className = self::MIGRATIONS_NAMESPACE . '\\Migration_' . $migration['timestamp'] . '_' . $migration['name'];
        
        if (class_exists($className)) {
            $migrationInstance = new $className($this->connection);
            if ($direction === 'up') {
                $migrationInstance->up();
                // Uzun migration / ODBC hata sonrası ölü oturumu at; ef_migrations + ef_sql_objects yazımı için
                $this->refreshConnection();
                $this->stampMigrationAsApplied($migration);
            } else {
                $migrationInstance->down();
                $this->removeMigrationRecord($migration);
            }
        }
    }

    /**
     * Record migration
     */
    private function recordMigration(array $migration): void
    {
        if (! $this->connection->tableExists(self::MIGRATIONS_TABLE)) {
            $this->createMigrationsTable();
        }

        $driver = strtolower($this->connection->getPlatform() ?? '');
        if ($driver === 'sqlsrv' || $driver === 'sqlserver') {
            $resolved = MigrationDateFormatResolver::insertAppliedAt(
                $this->connection,
                self::MIGRATIONS_TABLE,
                (string) $migration['timestamp'],
                (string) $migration['name']
            );
            // insertAppliedAt içinde persistResolvedFormat + applyToConnection çağrılır
            $this->resolvedDateFormat = $resolved;

            return;
        }

        $this->connection->table(self::MIGRATIONS_TABLE)->insert([
            'timestamp'  => $migration['timestamp'],
            'name'       => $migration['name'],
            'applied_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Remove migration record
     */
    private function removeMigrationRecord(array $migration): void
    {
        $this->connection->table(self::MIGRATIONS_TABLE)
            ->where('timestamp', $migration['timestamp'])
            ->where('name', $migration['name'])
            ->delete();
    }

    /**
     * Create migrations table
     */
    private function createMigrationsTable(): void
    {
        $driver = strtolower($this->connection->getPlatform() ?? '');
        
        // SQL Server uses IDENTITY instead of AUTO_INCREMENT
        if ($driver === 'sqlsrv' || $driver === 'sqlserver') {
            $this->createMigrationsTableSqlServer();
        } else {
            // MySQL and other databases
            $forge = new \CodeIgniter\Database\Forge($this->connection);
            $forge->addField([
                'id' => ['type' => 'INT', 'auto_increment' => true],
                'timestamp' => ['type' => 'VARCHAR(14)'],
                'name' => ['type' => 'VARCHAR(255)'],
                'applied_at' => ['type' => 'DATETIME']
            ]);
            $forge->addKey('id', true);
            $forge->createTable(self::MIGRATIONS_TABLE);
        }
    }
    
    /**
     * Create migrations table for SQL Server
     */
    private function createMigrationsTableSqlServer(): void
    {
        $table = self::MIGRATIONS_TABLE;
        if (! $this->sqlServerTableExists($table)) {
            $this->connection->query(
                "CREATE TABLE [{$table}] (
                [id] INT IDENTITY(1,1) PRIMARY KEY,
                [timestamp] VARCHAR(14) NOT NULL,
                [name] VARCHAR(255) NOT NULL,
                [applied_at] DATETIME NOT NULL
            )"
            );
        }

        $this->ensureSqlObjectsTable();
    }

    private function ensureSqlObjectsTable(): void
    {
        $driver = strtolower($this->connection->getPlatform() ?? '');
        if ($driver === 'sqlsrv' || $driver === 'sqlserver') {
            if ($this->sqlServerTableExists(self::SQL_OBJECTS_TABLE)) {
                return;
            }

            $table = self::SQL_OBJECTS_TABLE;
            $this->connection->query(
                "CREATE TABLE [{$table}] (
                [id] INT IDENTITY(1,1) PRIMARY KEY,
                [object_name] NVARCHAR(255) NOT NULL,
                [object_type] NVARCHAR(32) NOT NULL,
                [sql_hash] CHAR(64) NOT NULL,
                [migration_timestamp] VARCHAR(14) NULL,
                [migration_name] NVARCHAR(255) NULL,
                [updated_at] DATETIME NOT NULL CONSTRAINT [DF_ef_sql_objects_updated_at] DEFAULT (GETDATE()),
                CONSTRAINT [UQ_ef_sql_objects_name_type] UNIQUE ([object_name], [object_type])
            )"
            );

            return;
        }

        if ($this->connection->tableExists(self::SQL_OBJECTS_TABLE)) {
            return;
        }

        $forge = new \CodeIgniter\Database\Forge($this->connection);
            $forge->addField([
                'id'                  => ['type' => 'INT', 'auto_increment' => true],
                'object_name'         => ['type' => 'VARCHAR', 'constraint' => 255],
                'object_type'         => ['type' => 'VARCHAR', 'constraint' => 32],
                'sql_hash'            => ['type' => 'CHAR', 'constraint' => 64],
                'migration_timestamp' => ['type' => 'VARCHAR', 'constraint' => 14, 'null' => true],
                'migration_name'      => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
                'updated_at'          => ['type' => 'DATETIME'],
            ]);
            $forge->addKey('id', true);
            $forge->addUniqueKey(['object_name', 'object_type']);
            $forge->createTable(self::SQL_OBJECTS_TABLE);
    }

    private function sqlServerTableExists(string $tableName): bool
    {
        $row = $this->connection->query(
            'SELECT OBJECT_ID(?, \'U\') AS oid',
            [$tableName]
        )->getRowArray();

        return ! empty($row['oid']);
    }

    /**
     * SQL Server ODBC "connection is broken" sonrası yeni oturum açar; CI4 paylaşımlı bağlantıyı günceller.
     */
    private function refreshConnection(): void
    {
        if ($this->connection === null) {
            $this->connection = \Config\Database::connect();

            return;
        }

        $group = $this->connection->DBGroup ?? 'default';

        try {
            if (method_exists($this->connection, 'reconnect')) {
                $this->connection->reconnect();
            } else {
                $this->connection->close();
                $this->connection->initialize();
            }
        } catch (\Throwable) {
            try {
                $this->connection->close();
            } catch (\Throwable) {
            }

            $this->connection = \Config\Database::connect($group, false);
            $this->connection->initialize();
        }

        $this->replaceSharedConnection($group, $this->connection);
    }

    private function replaceSharedConnection(string $group, BaseConnection $connection): void
    {
        if (! class_exists(\CodeIgniter\Database\Database::class)) {
            return;
        }

        try {
            $ref = new \ReflectionClass(\CodeIgniter\Database\Database::class);
            if (! $ref->hasProperty('instances')) {
                return;
            }

            $instances = $ref->getStaticPropertyValue('instances');
            if (! is_array($instances)) {
                $instances = [];
            }
            $instances[$group] = $connection;
            $ref->setStaticPropertyValue('instances', $instances);
        } catch (\Throwable $e) {
            log_message('debug', 'MigrationManager::replaceSharedConnection: ' . $e->getMessage());
        }
    }

    /**
     * Upsert discovered #[GenerateQuery] hashes after a successful migration up.
     */
    private function syncSqlObjectsRegistry(array $migration): void
    {
        $this->ensureSqlObjectsTable();

        $objects = ProgrammabilityDiscovery::discover();
        $timestamp = (string) ($migration['timestamp'] ?? '');
        $name = (string) ($migration['name'] ?? '');
        $driver = strtolower($this->connection->getPlatform() ?? '');

        $useSafeDatetime = ($driver === 'sqlsrv' || $driver === 'sqlserver');

        foreach ($objects as $object) {
            $existing = $this->connection->table(self::SQL_OBJECTS_TABLE)
                ->where('object_name', $object['objectName'])
                ->where('object_type', $object['objectType'])
                ->get()
                ->getRowArray();

            if ($useSafeDatetime) {
                MigrationDateFormatResolver::upsertSqlObjectRow(
                    $this->connection,
                    self::SQL_OBJECTS_TABLE,
                    $object,
                    $timestamp,
                    $name,
                    $existing !== null && $existing !== []
                );

                continue;
            }

            $row = [
                'object_name'         => $object['objectName'],
                'object_type'         => $object['objectType'],
                'sql_hash'            => $object['sqlHash'],
                'migration_timestamp' => $timestamp,
                'migration_name'      => $name,
                'updated_at'          => date('Y-m-d H:i:s'),
            ];

            if ($existing) {
                $this->connection->table(self::SQL_OBJECTS_TABLE)
                    ->where('object_name', $object['objectName'])
                    ->where('object_type', $object['objectType'])
                    ->update($row);
            } else {
                $this->connection->table(self::SQL_OBJECTS_TABLE)->insert($row);
            }
        }
    }

    /**
     * Generate migration content
     */

    /**
     * Get default up code template
     */
    private function getDefaultUpCode(): string
    {
        return <<<'CODE'
        // Örnek: Tablo oluşturma
        // $builder->createTable('TableName', function(ColumnBuilder $columns) {
        //     $columns->integer('Id')->primaryKey()->autoIncrement();
        //     $columns->string('Name', 255)->notNull();
        //     $columns->datetime('CreatedAt')->nullable();
        //     $columns->datetime('UpdatedAt')->nullable();
        // });
        
        // Örnek: Index oluşturma
        // $builder->createIndex('TableName', 'IX_TableName_Name', ['Name'], true);
        
        // Örnek: Foreign key oluşturma
        // $builder->addForeignKey(
        //     'TableName',
        //     'FK_TableName_OtherTable',
        //     ['OtherTableId'],
        //     'OtherTable',
        //     ['Id'],
        //     'CASCADE'
        // );
CODE;
    }

    /**
     * Get default down code template
     */
    private function getDefaultDownCode(): string
    {
        return <<<'CODE'
        // Rollback işlemleri (up metodundaki işlemlerin tersi)
        // Örnek: Tablo silme
        // $builder->dropTable('TableName');
CODE;
    }
}

