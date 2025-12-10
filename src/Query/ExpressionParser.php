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
    private array $variableValues = [];
    private array $parameterValues = []; // Store actual parameter values
    private ?\Yakupeyisan\CodeIgniter4\EntityFramework\Core\DbContext $context = null; // For resolving dynamic properties

    public function __construct(string $entityType, string $tableAlias = 't0', ?\Yakupeyisan\CodeIgniter4\EntityFramework\Core\DbContext $context = null)
    {
        $this->entityType = $entityType;
        $this->tableAlias = $tableAlias;
        $this->context = $context;
    }

    /**
     * Set variable values from closure's static variables
     */
    public function setVariableValues(array $values): void
    {
        $this->variableValues = $values;
    }

    /**
     * Parse lambda expression to SQL WHERE condition
     */
    public function parse(callable $predicate): string
    {
        $reflection = new ReflectionFunction($predicate);
        $code = $this->getFunctionCode($reflection);
        
        //log_message('debug', 'ExpressionParser - Raw code: ' . substr($code, 0, 200));
        
        if (empty($code)) {
            //log_message('debug', 'ExpressionParser - Code is empty');
            return '';
        }

        // Extract the expression part (between => and ; or end)
        $expression = $this->extractExpression($code);
        //log_message('debug', 'ExpressionParser - Extracted expression: ' . $expression);
        
        // Parse the expression
        $sql = $this->parseExpression($expression);
        //log_message('debug', 'ExpressionParser - Parsed SQL: ' . $sql);
        
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
        // Get more lines to handle multi-line expressions
        $code = implode('', array_slice($lines, max(0, $start - 1), min($end - $start + 5, count($lines) - $start + 1)));
        
        // If the code contains ->where, ->and, ->or, etc., extract just the lambda part
        // Pattern: ->where(fn($e) => $e->Id === $id) or ->where(function($e) { return $e->Id === $id; })
        // Also handle multi-line expressions like: ->where(fn($e) => $e->{$this->primaryKey} === (int)$id)
        // Handle nested parentheses correctly: ->where(fn($e) => in_array($e->Id, $ids))
        if (preg_match('/->(where|and|or|not)\s*\(\s*(?:fn|function)\s*\([^)]+\)\s*=>\s*(.+)/s', $code, $matches)) {
            // Return just the lambda expression part, wrapped in fn() => format
            $lambdaBody = trim($matches[2]);
            
            // Find the correct closing parenthesis by counting parentheses
            // We need to find the closing parenthesis that matches the opening parenthesis of ->where(...)
            // Start with parenCount = 1 because we're inside ->where( which opened a parenthesis
            $parenCount = 1;
            $lambdaEndPos = 0;
            $inString = false;
            $stringChar = '';
            
            for ($i = 0; $i < strlen($lambdaBody); $i++) {
                $char = $lambdaBody[$i];
                
                // Handle string literals
                if (($char === '"' || $char === "'") && ($i === 0 || $lambdaBody[$i - 1] !== '\\')) {
                    if (!$inString) {
                        $inString = true;
                        $stringChar = $char;
                    } elseif ($char === $stringChar) {
                        $inString = false;
                        $stringChar = '';
                    }
                    continue;
                }
                
                if ($inString) {
                    continue;
                }
                
                // Count parentheses
                if ($char === '(') {
                    $parenCount++;
                } elseif ($char === ')') {
                    $parenCount--;
                    // If we've closed all parentheses, this is the closing parenthesis of ->where(...)
                    if ($parenCount === 0) {
                        $lambdaEndPos = $i;
                        break;
                    }
                } elseif ($char === ';' && $parenCount === 1) {
                    // End of statement (but only if we're at the top level, not inside nested parentheses)
                    $lambdaEndPos = $i;
                    break;
                }
            }
            
            // If we found a valid end position, truncate the lambda body
            if ($lambdaEndPos > 0) {
                $lambdaBody = substr($lambdaBody, 0, $lambdaEndPos);
            } else {
                // If no valid end found, try to find it by looking for the pattern
                // Remove trailing closing parenthesis if it's the last character (from method chaining)
                // But only if we have balanced parentheses
                if (substr_count($lambdaBody, '(') > substr_count($lambdaBody, ')')) {
                    // We're missing a closing parenthesis, don't remove anything
                } else {
                    $lambdaBody = rtrim($lambdaBody, ')');
                }
            }
            
            // If the expression seems incomplete (ends with incomplete type cast or variable), try to get more lines
            if (preg_match('/\(int\s*$|\(string\s*$|\(float\s*$|\(bool\s*$|\$\s*$/', $lambdaBody)) {
                // Get a few more lines to complete the expression
                $moreLines = array_slice($lines, $end - $start, 5);
                $moreCode = implode('', $moreLines);
                // Try to find the complete expression
                if (preg_match('/(\(int\)\s*\$[a-zA-Z_][a-zA-Z0-9_]*)/', $moreCode, $completeMatches)) {
                    $lambdaBody = str_replace('(int', $completeMatches[1], $lambdaBody);
                } elseif (preg_match('/(\(string\)\s*\$[a-zA-Z_][a-zA-Z0-9_]*)/', $moreCode, $completeMatches)) {
                    $lambdaBody = str_replace('(string', $completeMatches[1], $lambdaBody);
                } elseif (preg_match('/(\(float\)\s*\$[a-zA-Z_][a-zA-Z0-9_]*)/', $moreCode, $completeMatches)) {
                    $lambdaBody = str_replace('(float', $completeMatches[1], $lambdaBody);
                } elseif (preg_match('/(\(bool\)\s*\$[a-zA-Z_][a-zA-Z0-9_]*)/', $moreCode, $completeMatches)) {
                    $lambdaBody = str_replace('(bool', $completeMatches[1], $lambdaBody);
                } elseif (preg_match('/(\$[a-zA-Z_][a-zA-Z0-9_]*)/', $moreCode, $completeMatches)) {
                    $lambdaBody .= $completeMatches[1];
                }
            }
            
            // Remove any trailing -> operators
            $lambdaBody = preg_replace('/\s*->\s*$/', '', $lambdaBody);
            
            //log_message('debug', "getFunctionCode - extracted lambda body: {$lambdaBody}");
            return 'fn($x) => ' . trim($lambdaBody);
        }
        
        return $code;
    }

    /**
     * Extract expression from function code
     */
    private function extractExpression(string $code): string
    {
        // Remove function declaration and get expression
        // Pattern: fn($x) => $x->Property === value
        // Also handle incomplete expressions like: fn($x) => $x->Property === (int
        // Handle nested function calls like: fn($x) => in_array($x->Id, $ids)
        if (preg_match('/=>\s*(.+)/s', $code, $matches)) {
            $expression = trim($matches[1]);
            
            // Find the correct end of the expression by counting parentheses
            // We need to find where the expression actually ends (not just the first ; or newline)
            $parenCount = 0;
            $expressionEndPos = strlen($expression);
            $inString = false;
            $stringChar = '';
            
            for ($i = 0; $i < strlen($expression); $i++) {
                $char = $expression[$i];
                
                // Handle string literals
                if (($char === '"' || $char === "'") && ($i === 0 || $expression[$i - 1] !== '\\')) {
                    if (!$inString) {
                        $inString = true;
                        $stringChar = $char;
                    } elseif ($char === $stringChar) {
                        $inString = false;
                        $stringChar = '';
                    }
                    continue;
                }
                
                if ($inString) {
                    continue;
                }
                
                // Count parentheses
                if ($char === '(') {
                    $parenCount++;
                } elseif ($char === ')') {
                    $parenCount--;
                } elseif ($char === ';' && $parenCount === 0) {
                    // End of statement
                    $expressionEndPos = $i;
                    break;
                } elseif (preg_match('/\s*->(where|and|or|not)\s*\(/i', substr($expression, $i), $methodMatch) && $parenCount === 0) {
                    // Method chaining detected
                    $expressionEndPos = $i;
                    break;
                } elseif (($char === "\n" || $char === "\r") && $parenCount === 0 && $i > 0) {
                    // End of line, but only if we're not inside parentheses
                    // Check if next non-whitespace is method chaining or end of code
                    $remaining = trim(substr($expression, $i));
                    if (empty($remaining) || preg_match('/^->(where|and|or|not)\s*\(/i', $remaining)) {
                        $expressionEndPos = $i;
                        break;
                    }
                }
            }
            
            // Truncate expression to the correct end position
            if ($expressionEndPos < strlen($expression)) {
                $expression = substr($expression, 0, $expressionEndPos);
            }
            
            // If expression ends with incomplete type cast, try to complete it
            if (preg_match('/\(int\s*$|\(string\s*$|\(float\s*$|\(bool\s*$/', $expression)) {
                // Look for the rest in the code
                if (preg_match('/\(int\)\s*\$([a-zA-Z_][a-zA-Z0-9_]*)/', $code, $completeMatches)) {
                    $expression = str_replace('(int', '(int)$' . $completeMatches[1], $expression);
                } elseif (preg_match('/\(string\)\s*\$([a-zA-Z_][a-zA-Z0-9_]*)/', $code, $completeMatches)) {
                    $expression = str_replace('(string', '(string)$' . $completeMatches[1], $expression);
                } elseif (preg_match('/\(float\)\s*\$([a-zA-Z_][a-zA-Z0-9_]*)/', $code, $completeMatches)) {
                    $expression = str_replace('(float', '(float)$' . $completeMatches[1], $expression);
                } elseif (preg_match('/\(bool\)\s*\$([a-zA-Z_][a-zA-Z0-9_]*)/', $code, $completeMatches)) {
                    $expression = str_replace('(bool', '(bool)$' . $completeMatches[1], $expression);
                }
            }
            
            // Remove any trailing ->where, ->and, ->or, etc. that might be part of method chaining
            $expression = preg_replace('/\s*->(where|and|or|not)\s*\(.*$/i', '', $expression);
            // Remove any trailing closing parentheses that might be from method chaining (but keep function call parentheses)
            // Only remove if it's at the very end and we have balanced parentheses
            if (preg_match('/\)\s*$/', $expression) && substr_count($expression, '(') === substr_count($expression, ')') - 1) {
                $expression = rtrim($expression, ')');
            }
            // Remove any trailing -> operators
            $expression = preg_replace('/\s*->\s*$/', '', $expression);
            return trim($expression);
        }
        
        // Pattern: function($x) { return $x->Property === value; }
        if (preg_match('/return\s+(.+?);/s', $code, $matches)) {
            $expression = trim($matches[1]);
            // Remove any trailing ->where, ->and, ->or, etc.
            $expression = preg_replace('/\s*->(where|and|or|not)\s*\(.*$/i', '', $expression);
            return trim($expression);
        }
        
        return trim($code);
    }

    /**
     * Parse expression to SQL
     */
    private function parseExpression(string $expression): string
    {
        $expression = trim($expression);
        
        //log_message('debug', "parseExpression - input: {$expression}");
        
        // Handle parentheses FIRST
        if (preg_match('/^\((.*)\)$/', $expression, $matches)) {
            return '(' . $this->parseExpression($matches[1]) . ')';
        }
        
        // Handle type casting first: (int)$id, (string)$value, etc.
        // Match: (int)$id, (string)$value, (float)$num, (bool)$flag
        if (preg_match('/^\((\w+)\)\s*\$?([a-zA-Z_][a-zA-Z0-9_]*)/', $expression, $castMatches)) {
            $type = $castMatches[1];
            $value = '$' . $castMatches[2];
            //log_message('debug', "parseExpression - type casting detected: ({$type}){$value}");
            // Parse the value (ignore the cast, SQL will handle it)
            return $this->parseExpression($value);
        }
        
        // Handle incomplete type casting: (int (without closing parenthesis)
        if (preg_match('/^\((\w+)\s*$/', $expression, $castMatches)) {
            //log_message('debug', "parseExpression - incomplete type casting detected: ({$castMatches[1]}");
            // This is an incomplete expression, return as is and let comparison handle it
        }
        
        // Handle NOT in_array BEFORE everything else (special case: !in_array(...))
        // Pattern: !in_array(...) should be handled by parseIn first, then wrapped with NOT
        if (preg_match('/^!\s*in_array\(/i', $expression)) {
            // Remove the ! and parse in_array, then wrap with NOT
            $innerExpression = preg_replace('/^!\s*/', '', $expression);
            $innerSql = $this->parseIn($innerExpression);
            if ($innerSql !== null) {
                $result = "NOT ({$innerSql})";
                //log_message('debug', "parseExpression - not in_array result: {$result}");
                return $result;
            }
        }
        
        // Handle IN operator BEFORE comparison (in_array is a function call, not a comparison)
        $sql = $this->parseIn($expression);
        if ($sql !== null) {
            //log_message('debug', "parseExpression - in result: {$sql}");
            return $sql;
        }
        
        // Handle method calls (Contains, StartsWith, EndsWith, etc.) BEFORE comparison
        // Method calls like ->startsWith() should be parsed before comparison operators
        $sql = $this->parseMethodCall($expression);
        if ($sql !== null) {
            //log_message('debug', "parseExpression - method call result: {$sql}");
            return $sql;
        }
        
        // Handle comparison operators (before arithmetic, because === has higher precedence than -)
        $sql = $this->parseComparison($expression);
        if ($sql !== null) {
            //log_message('debug', "parseExpression - comparison result: {$sql}");
            return $sql;
        }
        
        // If it's a SIMPLE property access pattern (NOT a comparison), handle it directly
        // Pattern: $x->Property (but NOT $x->Property === value)
        if (preg_match('/^\$[a-zA-Z_][a-zA-Z0-9_]*->[A-Za-z_][A-Za-z0-9_]*$/', $expression)) {
            $result = $this->parsePropertyAccess($expression);
            //log_message('debug', "parseExpression - property access result: {$result}");
            return $result;
        }
        
        // Handle logical operators (AND, OR)
        $sql = $this->parseLogicalOperators($expression);
        if ($sql !== null) {
            //log_message('debug', "parseExpression - logical result: {$sql}");
            return $sql;
        }
        
        // Handle arithmetic operations (+, -, *, /, %) - but only if not part of comparison
        $sql = $this->parseArithmetic($expression);
        if ($sql !== null) {
            //log_message('debug', "parseExpression - arithmetic result: {$sql}");
            return $sql;
        }
        
        // Handle NOT operator
        $sql = $this->parseNot($expression);
        if ($sql !== null) {
            //log_message('debug', "parseExpression - not result: {$sql}");
            return $sql;
        }
        
        // Handle property access (fallback)
        $result = $this->parsePropertyAccess($expression);
        //log_message('debug', "parseExpression - property access fallback result: {$result}");
        return $result;
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
        // If expression contains a method call (startsWith, contains, endsWith, etc.), 
        // don't treat it as a comparison - let parseMethodCall handle it
        if (preg_match('/->(startsWith|contains|endsWith|toLower|toUpper|length|substring|trim|lTrim|rTrim|replace)\(/i', $expression)) {
            return null;
        }
        
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
                
                //log_message('debug', "parseComparison - left: {$left}, right: {$right}");
                
                // Parse left side (property access)
                // Check if it's a property access pattern ($x->Property)
                if (preg_match('/^\$[a-zA-Z_][a-zA-Z0-9_]*->/', $left)) {
                    // It's a property access, use parsePropertyAccess directly
                    $leftSql = $this->parsePropertyAccess($left);
                } else {
                    // Otherwise use parseExpression
                    $leftSql = $this->parseExpression($left);
                }
                //log_message('debug', "parseComparison - leftSql: {$leftSql}");
                
                // Parse right side (value)
                // Handle type casting first: (int)$id, (string)$value, etc.
                if (preg_match('/^\((\w+)\)\s*\$?([a-zA-Z_][a-zA-Z0-9_]*)/', $right, $castMatches)) {
                    $type = $castMatches[1];
                    $varName = '$' . $castMatches[2];
                    //log_message('debug', "parseComparison - type casting detected on right side: ({$type}){$varName}");
                    // Parse the value (ignore the cast, SQL will handle it)
                    $rightSql = $this->parseValue($varName);
                } elseif (preg_match('/^\$[a-zA-Z_][a-zA-Z0-9_]*->/', $right)) {
                    // Check if right side is a property access too (for comparisons like $e->Id === $e->OtherId)
                    $rightSql = $this->parsePropertyAccess($right);
                } else {
                    $rightSql = $this->parseValue($right);
                }
                //log_message('debug', "parseComparison - rightSql: {$rightSql}");
                
                $result = "{$leftSql} {$operator} {$rightSql}";
                //log_message('debug', "parseComparison - result: {$result}");
                return $result;
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
            // Use parsePropertyAccess if it's a property access pattern, otherwise use parseExpression
            if (preg_match('/^\$[a-zA-Z_][a-zA-Z0-9_]*(->\$?[a-zA-Z_][a-zA-Z0-9_]*)*$/', $property)) {
                $propertySql = $this->parsePropertyAccess($property);
            } else {
                $propertySql = $this->parseExpression($property);
            }
            $valueSql = $this->parseValue($value);
            return "{$propertySql} LIKE CONCAT('%', {$valueSql}, '%')";
        }
        
        // StartsWith: $x->Property->startsWith('value')
        if (preg_match('/^(.+?)->startsWith\((.+?)\)$/i', $expression, $matches)) {
            $property = trim($matches[1]);
            $value = trim($matches[2]);
            // Use parsePropertyAccess if it's a property access pattern, otherwise use parseExpression
            if (preg_match('/^\$[a-zA-Z_][a-zA-Z0-9_]*(->\$?[a-zA-Z_][a-zA-Z0-9_]*)*$/', $property)) {
                $propertySql = $this->parsePropertyAccess($property);
            } else {
                $propertySql = $this->parseExpression($property);
            }
            $valueSql = $this->parseValue($value);
            return "{$propertySql} LIKE CONCAT({$valueSql}, '%')";
        }
        
        // EndsWith: $x->Property->endsWith('value')
        if (preg_match('/^(.+?)->endsWith\((.+?)\)$/i', $expression, $matches)) {
            $property = trim($matches[1]);
            $value = trim($matches[2]);
            // Use parsePropertyAccess if it's a property access pattern, otherwise use parseExpression
            if (preg_match('/^\$[a-zA-Z_][a-zA-Z0-9_]*(->\$?[a-zA-Z_][a-zA-Z0-9_]*)*$/', $property)) {
                $propertySql = $this->parsePropertyAccess($property);
            } else {
                $propertySql = $this->parseExpression($property);
            }
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
        // Pattern 1: in_array($x->Property, [1, 2, 3]) - literal array
        if (preg_match('/^in_array\((.+?),\s*\[(.+?)\]\)$/i', $expression, $matches)) {
            $property = trim($matches[1]);
            $values = trim($matches[2]);
            $propertySql = $this->parsePropertyAccess($property);
            $valuesArray = $this->parseArray($values);
            return "{$propertySql} IN (" . implode(', ', $valuesArray) . ")";
        }
        
        // Pattern 2: in_array($x->Property, $variable) - variable array
        // Match: in_array($s->Id, $selectedShowIds) or in_array($s->Id, $selectedShowIds)
        if (preg_match('/^in_array\((.+?),\s*\$([a-zA-Z_][a-zA-Z0-9_]*)\)$/i', $expression, $matches)) {
            $property = trim($matches[1]);
            $varName = trim($matches[2]);
            $propertySql = $this->parsePropertyAccess($property);
            
            // Check if propertySql is a navigation property path (starts with "NAVIGATION:")
            if (strpos($propertySql, 'NAVIGATION:') === 0) {
                // Extract navigation property path
                $navPath = substr($propertySql, 11); // Remove "NAVIGATION:" prefix
                //log_message('debug', "parseIn - navigation property path detected: {$navPath}");
                
                // Check if we have the array value in variableValues
                if (isset($this->variableValues[$varName]) && is_array($this->variableValues[$varName])) {
                    $valuesArray = $this->variableValues[$varName];
                    $valuesSql = [];
                    foreach ($valuesArray as $value) {
                        if (is_string($value)) {
                            $value = str_replace("'", "''", $value);
                            $valuesSql[] = "'{$value}'";
                        } elseif (is_numeric($value)) {
                            $valuesSql[] = (string)$value;
                        } elseif (is_bool($value)) {
                            $valuesSql[] = $value ? '1' : '0';
                        } elseif (is_null($value)) {
                            $valuesSql[] = 'NULL';
                        } else {
                            $value = str_replace("'", "''", (string)$value);
                            $valuesSql[] = "'{$value}'";
                        }
                    }
                    // Return navigation property path with values for AdvancedQueryBuilder to handle
                    return "NAVIGATION_IN:{$navPath}:" . implode(',', $valuesSql);
                } else {
                    // Variable not found or not an array - return navigation path for AdvancedQueryBuilder to handle
                    //log_message('warning', "parseIn - variable \${$varName} not found in variableValues or not an array, returning navigation path");
                    return "NAVIGATION_IN:{$navPath}:?";
                }
            }
            
            // Check if we have the array value in variableValues
            if (isset($this->variableValues[$varName]) && is_array($this->variableValues[$varName])) {
                $valuesArray = $this->variableValues[$varName];
                $valuesSql = [];
                foreach ($valuesArray as $value) {
                    if (is_string($value)) {
                        $value = str_replace("'", "''", $value);
                        $valuesSql[] = "'{$value}'";
                    } elseif (is_numeric($value)) {
                        $valuesSql[] = (string)$value;
                    } elseif (is_bool($value)) {
                        $valuesSql[] = $value ? '1' : '0';
                    } elseif (is_null($value)) {
                        $valuesSql[] = 'NULL';
                    } else {
                        $value = str_replace("'", "''", (string)$value);
                        $valuesSql[] = "'{$value}'";
                    }
                }
                return "{$propertySql} IN (" . implode(', ', $valuesSql) . ")";
            } else {
                // Variable not found or not an array - use parameter binding
                //log_message('warning', "parseIn - variable \${$varName} not found in variableValues or not an array, using parameter binding");
                $paramIndex = $this->parameterIndex++;
                $paramName = 'param_' . $paramIndex;
                $this->parameterMap[$paramName] = '$' . $varName;
                // We'll need to handle this differently - for now, return a placeholder
                // The actual binding will be done in AdvancedQueryBuilder
                return "{$propertySql} IN (?)";
            }
        }
        
        return null;
    }

    /**
     * Parse property access (e.g., $x->Property or $x->{$variable})
     */
    private function parsePropertyAccess(string $expression): string
    {
        //log_message('debug', "parsePropertyAccess - input: {$expression}");
        
        // Handle dynamic property access: $e->{$this->primaryKey}
        if (preg_match('/^\$[a-zA-Z_][a-zA-Z0-9_]*->\{([^}]+)\}/', $expression, $dynamicMatches)) {
            $dynamicProperty = trim($dynamicMatches[1]);
            //log_message('debug', "parsePropertyAccess - dynamic property detected: {$dynamicProperty}");
            
            // Try to extract property name from dynamic expression
            // Pattern: $this->primaryKey or $variable->property
            if (preg_match('/\$this->([a-zA-Z_][a-zA-Z0-9_]*)/', $dynamicProperty, $thisMatches)) {
                $propertyName = $thisMatches[1];
                //log_message('debug', "parsePropertyAccess - extracted property from \$this->: {$propertyName}");
                
                // If it's a common property name like 'primaryKey', try to resolve it
                // For 'primaryKey', we need to find the actual primary key property from the entity
                if ($propertyName === 'primaryKey' && $this->context !== null) {
                    // Try to find primary key property from entity
                    $reflection = new ReflectionClass($this->entityType);
                    foreach ($reflection->getProperties() as $prop) {
                        $keyAttributes = $prop->getAttributes(\Yakupeyisan\CodeIgniter4\EntityFramework\Attributes\Key::class);
                        if (!empty($keyAttributes)) {
                            $propertyName = $prop->getName();
                            //log_message('debug', "parsePropertyAccess - found primary key property: {$propertyName}");
                            break;
                        }
                    }
                    // If no primary key found, default to 'Id'
                    if ($propertyName === 'primaryKey') {
                        $propertyName = 'Id';
                        //log_message('debug', "parsePropertyAccess - using default primary key: Id");
                    }
                }
            } elseif (preg_match('/\$([a-zA-Z_][a-zA-Z0-9_]*)/', $dynamicProperty, $varMatches)) {
                // It's a variable - try to resolve it from variableValues
                $varName = $varMatches[1];
                if (isset($this->variableValues[$varName])) {
                    $propertyName = $this->variableValues[$varName];
                    //log_message('debug', "parsePropertyAccess - resolved dynamic property variable \${$varName} to: {$propertyName}");
                } else {
                    // Variable not found - use a default property name (Id) or throw an error
                    //log_message('warning', "parsePropertyAccess - cannot resolve dynamic property variable: \${$varName}, using default 'Id'");
                    $propertyName = 'Id'; // Fallback
                }
            } else {
                // It's a literal property name
                $propertyName = trim($dynamicProperty);
                // Remove any remaining -> operators
                $propertyName = preg_replace('/->/', '', $propertyName);
                $propertyName = preg_replace('/[^A-Za-z0-9_]/', '', $propertyName);
            }
            
            $columnName = $this->getColumnName($propertyName);
            $result = "{$this->tableAlias}.{$columnName}";
            //log_message('debug', "parsePropertyAccess - dynamic property result: {$result}");
            return $result;
        }
        
        // Remove variable prefix ($x->, $u->, $e->, etc.)
        // Match: $variable->Property or $variable->Property->NestedProperty
        $originalExpression = $expression;
        $expression = preg_replace('/^\$[a-zA-Z_][a-zA-Z0-9_]*->/', '', $expression);
        
        //log_message('debug', "parsePropertyAccess - after removing variable prefix: {$expression}");
        
        // If there's still a $ sign, it means we have a dynamic property like $e->$field
        // Try to resolve the variable from variableValues first
        if (preg_match('/\$([a-zA-Z_][a-zA-Z0-9_]*)/', $expression, $varMatches)) {
            $varName = $varMatches[1];
            // Check if this variable has a value in variableValues (e.g., $field = "EmployeeID")
            if (isset($this->variableValues[$varName])) {
                $varValue = $this->variableValues[$varName];
                //log_message('debug', "parsePropertyAccess - resolving variable \${$varName} to: {$varValue}");
                // Replace the variable with its value
                $expression = preg_replace('/\$' . preg_quote($varName, '/') . '/', $varValue, $expression);
                //log_message('debug', "parsePropertyAccess - after resolving variable: {$expression}");
            } else {
                // Variable not found in variableValues - remove it (fallback behavior)
                //log_message('warning', "parsePropertyAccess - variable \${$varName} not found in variableValues, removing it");
                $expression = preg_replace('/\$[a-zA-Z_][a-zA-Z0-9_]*/', '', $expression);
                // Clean up any leftover -> operators
                $expression = preg_replace('/^->+/', '', $expression);
                $expression = ltrim($expression);
                //log_message('debug', "parsePropertyAccess - after removing unresolved variable: {$expression}");
            }
        }
        
        // Remove ALL -> operators from the expression (they shouldn't be in SQL)
        $expression = preg_replace('/->/', '', $expression);
        $expression = trim($expression);
        
        //log_message('debug', "parsePropertyAccess - after removing -> operators: {$expression}");
        
        // If expression is empty or contains only spaces/dashes, something went wrong
        if (empty($expression) || preg_match('/^[\s\-\.]+$/', $expression)) {
            //log_message('error', "parsePropertyAccess - expression became empty or invalid: '{$expression}' from '{$originalExpression}'");
            // Try to extract property name from original expression
            if (preg_match('/->([A-Za-z_][A-Za-z0-9_]*)/', $originalExpression, $propMatches)) {
                $expression = $propMatches[1];
                //log_message('debug', "parsePropertyAccess - extracted property name: {$expression}");
            } else {
                // Fallback: use a default
                return "{$this->tableAlias}.Id";
            }
        }
        
        // Handle nested properties (e.g., Company->Name) - but -> is already removed
        // If we had navigation properties, they would be handled differently
        // For now, just get the last property name
        if (strpos($expression, ' ') !== false) {
            // If there are spaces, take the last word (property name)
            $parts = explode(' ', $expression);
            $expression = end($parts);
            //log_message('debug', "parsePropertyAccess - after extracting last word: {$expression}");
        }
        
        // Check if expression contains navigation property path (e.g., "EmployeeDepartments.Department.DepartmentID")
        // Navigation property paths contain dots and represent nested navigation properties
        if (strpos($expression, '.') !== false && preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)+$/', $expression)) {
            // This is a navigation property path - return it as-is for AdvancedQueryBuilder to handle
            // Format: NAVIGATION:CollectionProperty.ReferenceProperty.Column
            //log_message('debug', "parsePropertyAccess - navigation property path detected: {$expression}");
            return "NAVIGATION:{$expression}";
        }
        
        // Remove any remaining invalid characters (dots, dashes, etc. that shouldn't be in property name)
        // But keep curly braces for dynamic properties
        $expression = preg_replace('/[^A-Za-z0-9_]/', '', $expression);
        
        // Get column name from property
        $columnName = $this->getColumnName($expression);
        
        $result = "{$this->tableAlias}.{$columnName}";
        //log_message('debug', "parsePropertyAccess - result: {$result}");
        
        return $result;
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
        
        // Handle variables - try to get value from variableValues map
        if (preg_match('/^\$([a-zA-Z_][a-zA-Z0-9_]*)$/', $value, $varMatches)) {
            $varName = $varMatches[1];
            
            //log_message('debug', "parseValue - variable: \${$varName}, variableValues: " . json_encode(array_keys($this->variableValues)));
            
            // Check if we have the value in variableValues
            if (isset($this->variableValues[$varName])) {
                $varValue = $this->variableValues[$varName];
                //log_message('debug', "parseValue - found value for \${$varName}: " . (is_scalar($varValue) ? $varValue : gettype($varValue)));
                
                // Parse the actual value
                if (is_string($varValue)) {
                    $varValue = str_replace("'", "''", $varValue);
                    return "'{$varValue}'";
                } elseif (is_numeric($varValue)) {
                    return (string)$varValue;
                } elseif (is_bool($varValue)) {
                    return $varValue ? '1' : '0';
                } elseif (is_null($varValue)) {
                    return 'NULL';
                } else {
                    $varValue = str_replace("'", "''", (string)$varValue);
                    return "'{$varValue}'";
                }
            }
            
            //log_message('debug', "parseValue - value not found for \${$varName}, using parameter binding");
            
            // If value not found, use parameter binding with ? placeholder (CodeIgniter style)
            $paramIndex = $this->parameterIndex++;
            $paramName = 'param_' . $paramIndex;
            $this->parameterMap[$paramName] = $value; // Store variable name for reference
            $this->parameterValues[$paramIndex] = null; // Will be filled later if value is found
            return '?'; // Use ? placeholder instead of :param_0 for CodeIgniter compatibility
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

    /**
     * Get parameter values array (for CodeIgniter binding)
     */
    public function getParameterValues(): array
    {
        // Fill parameter values from variableValues
        $values = [];
        foreach ($this->parameterMap as $paramName => $varName) {
            $varName = ltrim($varName, '$');
            if (isset($this->variableValues[$varName])) {
                $values[] = $this->variableValues[$varName];
            } else {
                // If value not found, we can't bind it - this will cause an error
                // But we'll add null as placeholder
                $values[] = null;
            }
        }
        return $values;
    }
}

