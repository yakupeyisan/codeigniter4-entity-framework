<?php

namespace Yakupeyisan\CodeIgniter4\EntityFramework\Providers;

use CodeIgniter\Database\BaseConnection;

/**
 * DatabaseProviderFactory - Factory for creating database providers
 */
class DatabaseProviderFactory
{
    private static array $providers = [];

    /**
     * Register a custom database provider
     */
    public static function register(DatabaseProvider $provider): void
    {
        self::$providers[] = $provider;
    }

    /**
     * Get database provider for connection
     */
    public static function getProvider(BaseConnection $connection): DatabaseProvider
    {
        // Check registered providers first
        foreach (self::$providers as $provider) {
            if ($provider->supports($connection)) {
                return $provider;
            }
        }

        // Check built-in providers
        $builtInProviders = [
            new MySqlProvider(),
            new SqlServerProvider(),
            new PostgreSqlProvider(),
            new SqliteProvider(),
        ];

        foreach ($builtInProviders as $provider) {
            if ($provider->supports($connection)) {
                return $provider;
            }
        }

        // Default to MySQL if no provider found
        return new MySqlProvider();
    }

    /**
     * Get all registered providers
     */
    public static function getRegisteredProviders(): array
    {
        return self::$providers;
    }

    /**
     * Clear all registered providers
     */
    public static function clear(): void
    {
        self::$providers = [];
    }
}

