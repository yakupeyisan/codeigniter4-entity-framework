<?php

namespace Yakupeyisan\CodeIgniter4\EntityFramework\Query;

use ReflectionFunction;
use ReflectionClass;
use ReflectionProperty;

/**
 * ExpressionParser - Advanced expression tree parsing for WHERE clauses
 * Parses lambda expressions and converts them to SQL WHERE conditions
 * Supports complex expressions: AND, OR, NOT, comparisons, arithmetic, method calls
 */
class ExpressionParser
{
    private string $entityType;
    private string $tableAlias;
    private array $parameterMap = [];
    private int $parameterIndex = 0;

    public function __construct(string $entityType, string $tableAlias = 't0')
    {
        $this->entityType = $entityType;
        $this->tableAlias = $tableAlias;
    }

    /**
     * Parse lambda expression to SQL WHERE condition
     */
    public function parse(callable $predicate): string
    {
        $reflection = new ReflectionFunction($predicate);
        $code = $this->getFunctionCode($reflection);
        
        if (empty($code)) {
            return '';
        }

        // Extract the expression part (between => and ; or end)
        $expression = $this->extractExpression($code);
        
        // Parse the expression
        $sql = $this->parseExpression($expression);
        
        return $sql;
    }

    /**
     * Get function source code
     */
    private function getFunctionCode(ReflectionFunction $reflection): string
    {
        $file = $reflection->getFileName();
        $start = $reflection->getStartLine();
        $end = $reflection->getEndLine();
        
        if (!$file || !$start || !$end || !file_exists($file)) {
            return '';
        }
        
        $lines = file($file);
        $code = implode('', array_slice($lines, $start - 1, $end - $start + 1));
        
        return $code;
    }

    /**
     * Extract expression from function code
     */
    private function extractExpression(string $code): string
    {
        // Remove function declaration and get expression
        // Pattern: fn($x) => $x->Property === value
        if (preg_match('/=>\s*(.+?)(?:;|$)/s', $code, $matches)) {
            return trim($matches[1]);
        }
        
        // Pattern: function($x) { return $x->Property === value; }
        if (preg_match('/return\s+(.+?);/s', $code, $matches)) {
            return trim($matches[1]);
        }
        
        return trim($code);
    }

    /**
     * Parse expression to SQL
     */
    private function parseExpression(string $expression): string
    {
        $expression = trim($expression);
        
        // Handle parentheses
        if (preg_match('/^\((.*)\)$/', $expression, $matches)) {
            return '(' . $this->parseExpression($matches[1]) . ')';
        }
        
        // Handle logical operators (AND, OR)
        $sql = $this->parseLogicalOperators($expression);
        if ($sql !== null) {
            return $sql;
        }
        
        // Handle arithmetic operations (+, -, *, /, %)
        $sql = $this->parseArithmetic($expression);
        if ($sql !== null) {
            return $sql;
        }
        
        // Handle comparison operators (===, ==, !==, !=, <, >, <=, >=)
        $sql = $this->parseComparison($expression);
        if ($sql !== null) {
            return $sql;
        }
        
        // Handle NOT operator
        $sql = $this->parseNot($expression);
        if ($sql !== null) {
            return $sql;
        }
        
        // Handle method calls (Contains, StartsWith, EndsWith, etc.)
        $sql = $this->parseMethodCall($expression);
        if ($sql !== null) {
            return $sql;
        }
        
        // Handle IN operator
        $sql = $this->parseIn($expression);
        if ($sql !== null) {
            return $sql;
        }
        
        // Handle property access
        return $this->parsePropertyAccess($expression);
    }

    /**
     * Parse logical operators (AND, OR)
     */
    private function parseLogicalOperators(string $expression): ?string
    {
        // Handle AND (&& or and)
        if (preg_match('/^(.+?)\s*(?:&&|and)\s*(.+)$/i', $expression, $matches)) {
            $left = $this->parseExpression(trim($matches[1]));
            $right = $this->parseExpression(trim($matches[2]));
            return "({$left} AND {$right})";
        }
        
        // Handle OR (|| or or)
        if (preg_match('/^(.+?)\s*(?:\|\||or)\s*(.+)$/i', $expression, $matches)) {
            $left = $this->parseExpression(trim($matches[1]));
            $right = $this->parseExpression(trim($matches[2]));
            return "({$left} OR {$right})";
        }
        
        return null;
    }

    /**
     * Parse comparison operators
     */
    private function parseComparison(string $expression): ?string
    {
        // Match: $x->Property === value, $x->Property == value, etc.
        $patterns = [
            '/^(.+?)\s*===\s*(.+)$/' => '=',
            '/^(.+?)\s*==\s*(.+)$/' => '=',
            '/^(.+?)\s*!==\s*(.+)$/' => '!=',
            '/^(.+?)\s*!=\s*(.+)$/' => '!=',
            '/^(.+?)\s*<=\s*(.+)$/' => '<=',
            '/^(.+?)\s*>=\s*(.+)$/' => '>=',
            '/^(.+?)\s*<\s*(.+)$/' => '<',
            '/^(.+?)\s*>\s*(.+)$/' => '>',
        ];
        
        foreach ($patterns as $pattern => $operator) {
            if (preg_match($pattern, $expression, $matches)) {
                $left = trim($matches[1]);
                $right = trim($matches[2]);
                
                $leftSql = $this->parseExpression($left);
                $rightSql = $this->parseValue($right);
                
                return "{$leftSql} {$operator} {$rightSql}";
            }
        }
        
        return null;
    }

    /**
     * Parse NOT operator
     */
    private function parseNot(string $expression): ?string
    {
        if (preg_match('/^!\s*(.+)$/', $expression, $matches)) {
            $inner = $this->parseExpression(trim($matches[1]));
            return "NOT ({$inner})";
        }
        
        return null;
    }

    /**
     * Parse arithmetic operations (+, -, *, /, %)
     */
    private function parseArithmetic(string $expression): ?string
    {
        // Handle multiplication and division first (higher precedence)
        $patterns = [
            '/^(.+?)\s*\*\s*(.+)$/' => '*',
            '/^(.+?)\s*\/\s*(.+)$/' => '/',
            '/^(.+?)\s*%\s*(.+)$/' => '%',
        ];
        
        foreach ($patterns as $pattern => $operator) {
            if (preg_match($pattern, $expression, $matches)) {
                $left = trim($matches[1]);
                $right = trim($matches[2]);
                
                $leftSql = $this->parseExpression($left);
                $rightSql = $this->parseExpression($right);
                
                return "({$leftSql} {$operator} {$rightSql})";
            }
        }
        
        // Handle addition and subtraction (lower precedence)
        $patterns = [
            '/^(.+?)\s*\+\s*(.+)$/' => '+',
            '/^(.+?)\s*-\s*(.+)$/' => '-',
        ];
        
        foreach ($patterns as $pattern => $operator) {
            if (preg_match($pattern, $expression, $matches)) {
                $left = trim($matches[1]);
                $right = trim($matches[2]);
                
                $leftSql = $this->parseExpression($left);
                $rightSql = $this->parseExpression($right);
                
                return "({$leftSql} {$operator} {$rightSql})";
            }
        }
        
        return null;
    }

    /**
     * Parse method calls (Contains, StartsWith, EndsWith, etc.)
     */
    private function parseMethodCall(string $expression): ?string
    {
        // Contains: $x->Property->contains('value')
        if (preg_match('/^(.+?)->contains\((.+?)\)$/i', $expression, $matches)) {
            $property = trim($matches[1]);
            $value = trim($matches[2]);
            $propertySql = $this->parseExpression($property);
            $valueSql = $this->parseValue($value);
            return "{$propertySql} LIKE CONCAT('%', {$valueSql}, '%')";
        }
        
        // StartsWith: $x->Property->startsWith('value')
        if (preg_match('/^(.+?)->startsWith\((.+?)\)$/i', $expression, $matches)) {
            $property = trim($matches[1]);
            $value = trim($matches[2]);
            $propertySql = $this->parseExpression($property);
            $valueSql = $this->parseValue($value);
            return "{$propertySql} LIKE CONCAT({$valueSql}, '%')";
        }
        
        // EndsWith: $x->Property->endsWith('value')
        if (preg_match('/^(.+?)->endsWith\((.+?)\)$/i', $expression, $matches)) {
            $property = trim($matches[1]);
            $value = trim($matches[2]);
            $propertySql = $this->parseExpression($property);
            $valueSql = $this->parseValue($value);
            return "{$propertySql} LIKE CONCAT('%', {$valueSql})";
        }
        
        // ToLower: $x->Property->toLower()
        if (preg_match('/^(.+?)->toLower\(\)$/i', $expression, $matches)) {
            $property = trim($matches[1]);
            $propertySql = $this->parseExpression($property);
            return "LOWER({$propertySql})";
        }
        
        // ToUpper: $x->Property->toUpper()
        if (preg_match('/^(.+?)->toUpper\(\)$/i', $expression, $matches)) {
            $property = trim($matches[1]);
            $propertySql = $this->parseExpression($property);
            return "UPPER({$propertySql})";
        }
        
        // Length: $x->Property->length()
        if (preg_match('/^(.+?)->length\(\)$/i', $expression, $matches)) {
            $property = trim($matches[1]);
            $propertySql = $this->parseExpression($property);
            return "LENGTH({$propertySql})";
        }
        
        // Substring: $x->Property->substring(start, length)
        if (preg_match('/^(.+?)->substring\((.+?)\)$/i', $expression, $matches)) {
            $property = trim($matches[1]);
            $params = trim($matches[2]);
            $propertySql = $this->parseExpression($property);
            
            // Parse parameters (start, length)
            $paramParts = explode(',', $params);
            $start = $this->parseValue(trim($paramParts[0]));
            $length = isset($paramParts[1]) ? $this->parseValue(trim($paramParts[1])) : null;
            
            if ($length !== null) {
                return "SUBSTRING({$propertySql}, {$start}, {$length})";
            } else {
                return "SUBSTRING({$propertySql}, {$start})";
            }
        }
        
        // Trim: $x->Property->trim()
        if (preg_match('/^(.+?)->trim\(\)$/i', $expression, $matches)) {
            $property = trim($matches[1]);
            $propertySql = $this->parseExpression($property);
            return "TRIM({$propertySql})";
        }
        
        // LTrim: $x->Property->lTrim()
        if (preg_match('/^(.+?)->lTrim\(\)$/i', $expression, $matches)) {
            $property = trim($matches[1]);
            $propertySql = $this->parseExpression($property);
            return "LTRIM({$propertySql})";
        }
        
        // RTrim: $x->Property->rTrim()
        if (preg_match('/^(.+?)->rTrim\(\)$/i', $expression, $matches)) {
            $property = trim($matches[1]);
            $propertySql = $this->parseExpression($property);
            return "RTRIM({$propertySql})";
        }
        
        // Replace: $x->Property->replace('old', 'new')
        if (preg_match('/^(.+?)->replace\((.+?)\)$/i', $expression, $matches)) {
            $property = trim($matches[1]);
            $params = trim($matches[2]);
            $propertySql = $this->parseExpression($property);
            
            // Parse parameters (old, new)
            $paramParts = explode(',', $params);
            $old = $this->parseValue(trim($paramParts[0]));
            $new = isset($paramParts[1]) ? $this->parseValue(trim($paramParts[1])) : "''";
            
            return "REPLACE({$propertySql}, {$old}, {$new})";
        }
        
        // Date/Time methods
        // Year: $x->Property->year()
        if (preg_match('/^(.+?)->year\(\)$/i', $expression, $matches)) {
            $property = trim($matches[1]);
            $propertySql = $this->parseExpression($property);
            return "YEAR({$propertySql})";
        }
        
        // Month: $x->Property->month()
        if (preg_match('/^(.+?)->month\(\)$/i', $expression, $matches)) {
            $property = trim($matches[1]);
            $propertySql = $this->parseExpression($property);
            return "MONTH({$propertySql})";
        }
        
        // Day: $x->Property->day()
        if (preg_match('/^(.+?)->day\(\)$/i', $expression, $matches)) {
            $property = trim($matches[1]);
            $propertySql = $this->parseExpression($property);
            return "DAY({$propertySql})";
        }
        
        // Hour: $x->Property->hour()
        if (preg_match('/^(.+?)->hour\(\)$/i', $expression, $matches)) {
            $property = trim($matches[1]);
            $propertySql = $this->parseExpression($property);
            return "HOUR({$propertySql})";
        }
        
        // Minute: $x->Property->minute()
        if (preg_match('/^(.+?)->minute\(\)$/i', $expression, $matches)) {
            $property = trim($matches[1]);
            $propertySql = $this->parseExpression($property);
            return "MINUTE({$propertySql})";
        }
        
        // Second: $x->Property->second()
        if (preg_match('/^(.+?)->second\(\)$/i', $expression, $matches)) {
            $property = trim($matches[1]);
            $propertySql = $this->parseExpression($property);
            return "SECOND({$propertySql})";
        }
        
        // Math methods
        // Abs: $x->Property->abs()
        if (preg_match('/^(.+?)->abs\(\)$/i', $expression, $matches)) {
            $property = trim($matches[1]);
            $propertySql = $this->parseExpression($property);
            return "ABS({$propertySql})";
        }
        
        // Round: $x->Property->round() or $x->Property->round(decimals)
        if (preg_match('/^(.+?)->round\((.+?)?\)$/i', $expression, $matches)) {
            $property = trim($matches[1]);
            $propertySql = $this->parseExpression($property);
            $decimals = isset($matches[2]) && trim($matches[2]) !== '' ? $this->parseValue(trim($matches[2])) : '0';
            return "ROUND({$propertySql}, {$decimals})";
        }
        
        // Ceiling: $x->Property->ceiling()
        if (preg_match('/^(.+?)->ceiling\(\)$/i', $expression, $matches)) {
            $property = trim($matches[1]);
            $propertySql = $this->parseExpression($property);
            return "CEILING({$propertySql})";
        }
        
        // Floor: $x->Property->floor()
        if (preg_match('/^(.+?)->floor\(\)$/i', $expression, $matches)) {
            $property = trim($matches[1]);
            $propertySql = $this->parseExpression($property);
            return "FLOOR({$propertySql})";
        }
        
        // IsNull: $x->Property === null
        if (preg_match('/^(.+?)\s*===\s*null$/i', $expression, $matches)) {
            $property = trim($matches[1]);
            $propertySql = $this->parseExpression($property);
            return "{$propertySql} IS NULL";
        }
        
        // IsNotNull: $x->Property !== null
        if (preg_match('/^(.+?)\s*!==\s*null$/i', $expression, $matches)) {
            $property = trim($matches[1]);
            $propertySql = $this->parseExpression($property);
            return "{$propertySql} IS NOT NULL";
        }
        
        return null;
    }

    /**
     * Parse IN operator
     */
    private function parseIn(string $expression): ?string
    {
        // Pattern: in_array($x->Property, [1, 2, 3])
        if (preg_match('/^in_array\((.+?),\s*\[(.+?)\]\)$/i', $expression, $matches)) {
            $property = trim($matches[1]);
            $values = trim($matches[2]);
            $propertySql = $this->parsePropertyAccess($property);
            $valuesArray = $this->parseArray($values);
            return "{$propertySql} IN (" . implode(', ', $valuesArray) . ")";
        }
        
        return null;
    }

    /**
     * Parse property access (e.g., $x->Property)
     */
    private function parsePropertyAccess(string $expression): string
    {
        // Remove variable prefix ($x->, $u->, etc.)
        $expression = preg_replace('/^\$[a-zA-Z_][a-zA-Z0-9_]*->/', '', $expression);
        
        // Handle nested properties (e.g., Company->Name)
        if (strpos($expression, '->') !== false) {
            // This is a navigation property - would need JOIN handling
            // For now, return as is (will be handled by navigation property logic)
            return $this->handleNavigationProperty($expression);
        }
        
        // Get column name from property
        $columnName = $this->getColumnName($expression);
        
        return "{$this->tableAlias}.{$columnName}";
    }

    /**
     * Handle navigation property access
     */
    private function handleNavigationProperty(string $expression): string
    {
        // Split by ->
        $parts = explode('->', $expression);
        $property = end($parts);
        
        // For navigation properties, we'll need JOINs
        // This is a simplified version - full implementation would track JOINs
        $columnName = $this->getColumnName($property);
        
        // Use first part as table alias (would be set by JOIN logic)
        $tableAlias = 't' . (count($parts) - 1);
        
        return "{$tableAlias}.{$columnName}";
    }

    /**
     * Parse value (literal, variable, etc.)
     */
    private function parseValue(string $value): string
    {
        $value = trim($value);
        
        // Remove quotes if present
        if (preg_match('/^["\'](.+?)["\']$/', $value, $matches)) {
            $value = $matches[1];
            // Escape single quotes for SQL
            $value = str_replace("'", "''", $value);
            return "'{$value}'";
        }
        
        // Handle numbers
        if (is_numeric($value)) {
            return $value;
        }
        
        // Handle boolean
        if (strtolower($value) === 'true') {
            return '1';
        }
        if (strtolower($value) === 'false') {
            return '0';
        }
        
        // Handle null
        if (strtolower($value) === 'null') {
            return 'NULL';
        }
        
        // Handle variables (would need parameter binding in real implementation)
        if (preg_match('/^\$[a-zA-Z_][a-zA-Z0-9_]*$/', $value)) {
            $paramName = 'param_' . $this->parameterIndex++;
            $this->parameterMap[$paramName] = $value;
            return ':' . $paramName;
        }
        
        // Default: treat as string
        $value = str_replace("'", "''", $value);
        return "'{$value}'";
    }

    /**
     * Parse array literal
     */
    private function parseArray(string $arrayString): array
    {
        // Remove brackets
        $arrayString = trim($arrayString, '[]');
        
        // Split by comma
        $items = explode(',', $arrayString);
        $result = [];
        
        foreach ($items as $item) {
            $result[] = $this->parseValue(trim($item));
        }
        
        return $result;
    }

    /**
     * Get column name from property name
     */
    private function getColumnName(string $propertyName): string
    {
        // Check if property has Column attribute
        $reflection = new ReflectionClass($this->entityType);
        
        if ($reflection->hasProperty($propertyName)) {
            $property = $reflection->getProperty($propertyName);
            $attributes = $property->getAttributes(\Yakupeyisan\CodeIgniter4\EntityFramework\Attributes\Column::class);
            
            if (!empty($attributes)) {
                $columnAttr = $attributes[0]->newInstance();
                if ($columnAttr->name !== null) {
                    return $columnAttr->name;
                }
            }
        }
        
        // Default: use property name as-is (or convert to snake_case if needed)
        return $propertyName;
    }

    /**
     * Get parameter map (for parameterized queries)
     */
    public function getParameterMap(): array
    {
        return $this->parameterMap;
    }
}

