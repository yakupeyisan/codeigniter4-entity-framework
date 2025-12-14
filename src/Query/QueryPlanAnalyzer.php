<?php

namespace Yakupeyisan\CodeIgniter4\EntityFramework\Query;

use CodeIgniter\Database\BaseConnection;
use Yakupeyisan\CodeIgniter4\EntityFramework\Providers\DatabaseProvider;
use Yakupeyisan\CodeIgniter4\EntityFramework\Providers\DatabaseProviderFactory;

/**
 * QueryPlanAnalyzer - Analyzes and optimizes SQL query execution plans
 * Provides query plan analysis, index recommendations, and optimization suggestions
 */
class QueryPlanAnalyzer
{
    private BaseConnection $connection;
    private DatabaseProvider $provider;

    public function __construct(BaseConnection $connection)
    {
        $this->connection = $connection;
        $this->provider = DatabaseProviderFactory::getProvider($connection);
    }

    /**
     * Analyze query execution plan
     * 
     * @param string $sql SQL query to analyze
     * @return array Analysis results with recommendations
     */
    public function analyzePlan(string $sql): array
    {
        $explainResult = $this->explainQuery($sql);
        
        $analysis = [
            'sql' => $sql,
            'explain_plan' => $explainResult,
            'recommendations' => [],
            'warnings' => [],
            'index_suggestions' => [],
            'performance_score' => 100,
        ];

        // Analyze explain plan
        $this->analyzeExplainPlan($explainResult, $analysis);
        
        // Check for missing indexes
        $this->checkMissingIndexes($sql, $analysis);
        
        // Check for inefficient operations
        $this->checkInefficientOperations($sql, $analysis);
        
        // Calculate performance score
        $this->calculatePerformanceScore($analysis);

        return $analysis;
    }

    /**
     * Execute EXPLAIN query
     */
    private function explainQuery(string $sql): array
    {
        $explainSql = $this->provider->getExplainSql($sql);
        
        if (empty($explainSql)) {
            return [];
        }

        try {
            $query = $this->connection->query($explainSql);
            return $query->getResultArray();
        } catch (\Exception $e) {
            //log_message('error', 'EXPLAIN query failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Analyze EXPLAIN plan results
     */
    private function analyzeExplainPlan(array $explainResult, array &$analysis): void
    {
        if (empty($explainResult)) {
            $analysis['warnings'][] = 'Could not analyze query plan - EXPLAIN not supported or failed';
            return;
        }

        $providerName = $this->provider->getName();

        foreach ($explainResult as $row) {
            if ($providerName === 'MySQL') {
                $this->analyzeMySqlPlan($row, $analysis);
            } elseif ($providerName === 'SQL Server') {
                $this->analyzeSqlServerPlan($row, $analysis);
            } elseif ($providerName === 'PostgreSQL') {
                $this->analyzePostgrePlan($row, $analysis);
            } elseif ($providerName === 'SQLite') {
                $this->analyzeSqlitePlan($row, $analysis);
            }
        }
    }

    /**
     * Analyze SQLite EXPLAIN plan
     */
    private function analyzeSqlitePlan(array $row, array &$analysis): void
    {
        $detail = $row['detail'] ?? '';
        
        // Check for sequential scan
        if (stripos($detail, 'SCAN TABLE') !== false) {
            $analysis['warnings'][] = 'Table scan detected - consider adding an index';
            $analysis['performance_score'] -= 30;
        }

        // Check for index usage
        if (stripos($detail, 'SEARCH TABLE') !== false || stripos($detail, 'USING INDEX') !== false) {
            $analysis['recommendations'][] = 'Index scan detected - good';
        }
    }

    /**
     * Analyze MySQL EXPLAIN plan
     */
    private function analyzeMySqlPlan(array $row, array &$analysis): void
    {
        $type = strtoupper($row['type'] ?? '');
        $key = $row['key'] ?? null;
        $rows = (int)($row['rows'] ?? 0);
        $extra = strtoupper($row['Extra'] ?? '');

        // Check for full table scan
        if ($type === 'ALL' && $key === null) {
            $analysis['warnings'][] = 'Full table scan detected - consider adding an index';
            $analysis['performance_score'] -= 30;
        }

        // Check for filesort
        if (strpos($extra, 'USING FILESORT') !== false) {
            $analysis['warnings'][] = 'File sort detected - consider adding index for ORDER BY columns';
            $analysis['performance_score'] -= 10;
        }

        // Check for temporary table
        if (strpos($extra, 'USING TEMPORARY') !== false) {
            $analysis['warnings'][] = 'Temporary table detected - query may be inefficient';
            $analysis['performance_score'] -= 15;
        }

        // Check for high row count
        if ($rows > 10000 && $type !== 'const' && $type !== 'eq_ref') {
            $analysis['warnings'][] = "High row count ({$rows}) - consider optimizing query or adding indexes";
            $analysis['performance_score'] -= 5;
        }

        // Check for index usage
        if ($key !== null && $type !== 'ALL') {
            $analysis['recommendations'][] = "Index '{$key}' is being used - good";
        }
    }

    /**
     * Analyze SQL Server execution plan
     */
    private function analyzeSqlServerPlan(array $row, array &$analysis): void
    {
        // SQL Server plan analysis would go here
        // This is a simplified version
        $analysis['recommendations'][] = 'SQL Server plan analysis - check execution plan in SSMS for details';
    }

    /**
     * Analyze PostgreSQL EXPLAIN plan
     */
    private function analyzePostgrePlan(array $row, array &$analysis): void
    {
        $plan = $row['QUERY PLAN'] ?? '';
        
        // Check for sequential scan
        if (stripos($plan, 'Seq Scan') !== false) {
            $analysis['warnings'][] = 'Sequential scan detected - consider adding an index';
            $analysis['performance_score'] -= 30;
        }

        // Check for index scan
        if (stripos($plan, 'Index Scan') !== false || stripos($plan, 'Index Only Scan') !== false) {
            $analysis['recommendations'][] = 'Index scan detected - good';
        }

        // Check for sort
        if (stripos($plan, 'Sort') !== false) {
            $analysis['warnings'][] = 'Sort operation detected - consider adding index for ORDER BY';
            $analysis['performance_score'] -= 10;
        }
    }

    /**
     * Check for missing indexes
     */
    private function checkMissingIndexes(string $sql, array &$analysis): void
    {
        // Extract WHERE conditions
        if (preg_match('/WHERE\s+(.+?)(?:\s+ORDER|\s+GROUP|\s+LIMIT|$)/i', $sql, $matches)) {
            $whereClause = $matches[1];
            $this->suggestIndexesForWhere($whereClause, $analysis);
        }

        // Extract ORDER BY columns
        if (preg_match('/ORDER\s+BY\s+(.+?)(?:\s+LIMIT|$)/i', $sql, $matches)) {
            $orderByClause = $matches[1];
            $this->suggestIndexesForOrderBy($orderByClause, $analysis);
        }

        // Extract JOIN conditions
        if (preg_match_all('/JOIN\s+\w+\s+ON\s+(.+?)(?:\s+WHERE|\s+ORDER|\s+GROUP|$)/i', $sql, $matches)) {
            foreach ($matches[1] as $joinCondition) {
                $this->suggestIndexesForJoin($joinCondition, $analysis);
            }
        }
    }

    /**
     * Suggest indexes for WHERE clause
     */
    private function suggestIndexesForWhere(string $whereClause, array &$analysis): void
    {
        // Extract column names from WHERE clause
        if (preg_match_all('/(\w+)\.(\w+)\s*[=<>!]/', $whereClause, $matches)) {
            foreach ($matches[2] as $column) {
                $analysis['index_suggestions'][] = "Consider adding index on column: {$column}";
            }
        }
    }

    /**
     * Suggest indexes for ORDER BY
     */
    private function suggestIndexesForOrderBy(string $orderByClause, array &$analysis): void
    {
        if (preg_match_all('/(\w+)\.(\w+)/', $orderByClause, $matches)) {
            foreach ($matches[2] as $column) {
                $analysis['index_suggestions'][] = "Consider adding index on ORDER BY column: {$column}";
            }
        }
    }

    /**
     * Suggest indexes for JOIN
     */
    private function suggestIndexesForJoin(string $joinCondition, array &$analysis): void
    {
        if (preg_match_all('/(\w+)\.(\w+)\s*=\s*(\w+)\.(\w+)/', $joinCondition, $matches)) {
            foreach ($matches[2] as $column) {
                $analysis['index_suggestions'][] = "Consider adding index on JOIN column: {$column}";
            }
            foreach ($matches[4] as $column) {
                $analysis['index_suggestions'][] = "Consider adding index on JOIN column: {$column}";
            }
        }
    }

    /**
     * Check for inefficient operations
     */
    private function checkInefficientOperations(string $sql, array &$analysis): void
    {
        // Check for SELECT *
        if (preg_match('/SELECT\s+\*\s+FROM/i', $sql)) {
            $analysis['warnings'][] = 'SELECT * detected - consider selecting only needed columns';
            $analysis['performance_score'] -= 5;
        }

        // Check for LIKE with leading wildcard
        if (preg_match('/LIKE\s+[\'"]%/', $sql)) {
            $analysis['warnings'][] = 'LIKE with leading wildcard detected - cannot use index efficiently';
            $analysis['performance_score'] -= 10;
        }

        // Check for functions in WHERE clause
        if (preg_match('/WHERE.*\b(LOWER|UPPER|YEAR|MONTH|DAY|SUBSTRING)\s*\(/i', $sql)) {
            $analysis['warnings'][] = 'Functions in WHERE clause detected - may prevent index usage';
            $analysis['performance_score'] -= 10;
        }

        // Check for OR conditions
        if (preg_match('/WHERE.*\bOR\b/i', $sql)) {
            $analysis['warnings'][] = 'OR conditions detected - may prevent index usage, consider UNION';
            $analysis['performance_score'] -= 5;
        }

        // Check for subqueries
        if (preg_match('/\(SELECT\s+/i', $sql)) {
            $analysis['recommendations'][] = 'Subquery detected - consider using JOIN if possible';
        }
    }

    /**
     * Calculate performance score
     */
    private function calculatePerformanceScore(array &$analysis): void
    {
        // Ensure score is between 0 and 100
        $analysis['performance_score'] = max(0, min(100, $analysis['performance_score']));
        
        // Add performance rating
        if ($analysis['performance_score'] >= 80) {
            $analysis['performance_rating'] = 'Excellent';
        } elseif ($analysis['performance_score'] >= 60) {
            $analysis['performance_rating'] = 'Good';
        } elseif ($analysis['performance_score'] >= 40) {
            $analysis['performance_rating'] = 'Fair';
        } else {
            $analysis['performance_rating'] = 'Poor';
        }
    }

    /**
     * Get query statistics
     */
    public function getQueryStats(string $sql): array
    {
        $stats = [
            'sql' => $sql,
            'execution_time' => null,
            'rows_affected' => null,
            'rows_returned' => null,
        ];

        try {
            $startTime = microtime(true);
            $query = $this->connection->query($sql);
            $endTime = microtime(true);
            
            $stats['execution_time'] = ($endTime - $startTime) * 1000; // Convert to milliseconds
            $stats['rows_returned'] = $query->getNumRows();
            
            // Try to get affected rows (for UPDATE, DELETE, INSERT)
            if (method_exists($query, 'affectedRows')) {
                $stats['rows_affected'] = $this->connection->affectedRows();
            }
        } catch (\Exception $e) {
            //log_message('error', 'Query stats failed: ' . $e->getMessage());
        }

        return $stats;
    }

    /**
     * Compare two query plans
     */
    public function comparePlans(string $sql1, string $sql2): array
    {
        $plan1 = $this->analyzePlan($sql1);
        $plan2 = $this->analyzePlan($sql2);
        
        $stats1 = $this->getQueryStats($sql1);
        $stats2 = $this->getQueryStats($sql2);

        return [
            'query1' => [
                'sql' => $sql1,
                'plan' => $plan1,
                'stats' => $stats1,
            ],
            'query2' => [
                'sql' => $sql2,
                'plan' => $plan2,
                'stats' => $stats2,
            ],
            'comparison' => [
                'performance_score_diff' => $plan2['performance_score'] - $plan1['performance_score'],
                'execution_time_diff' => ($stats2['execution_time'] ?? 0) - ($stats1['execution_time'] ?? 0),
                'better_query' => $plan2['performance_score'] > $plan1['performance_score'] ? 'query2' : 'query1',
            ],
        ];
    }
}

