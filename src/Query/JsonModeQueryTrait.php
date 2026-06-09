<?php

namespace Yakupeyisan\CodeIgniter4\EntityFramework\Query;

use ReflectionClass;

/**
 * FOR JSON PATH execution path for SQL Server (AdvancedQueryBuilder).
 *
 * @mixin AdvancedQueryBuilder
 */
trait JsonModeQueryTrait
{
    public function toJson(): string
    {
        return $this->executeJsonModeQuery();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function toJsonArray(): array
    {
        $raw = $this->toJson();
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        return $decoded;
    }

    public function firstJson(): ?string
    {
        $raw = $this->toJson();
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || $decoded === []) {
            return null;
        }

        return json_encode($decoded[0], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public function singleJson(): string
    {
        $this->assertJsonModeCompatibleJson();
        $this->validateGroupBalance();

        $probe = clone $this;
        $probe->takeCount = 2;
        $probeJson = $probe->executeJsonModeQuery();
        $rows = json_decode($probeJson, true);
        if (!is_array($rows)) {
            $rows = [];
        }
        $exceptionClass = class_exists('\Yakupeyisan\CodeIgniter4\EntityFramework\Exceptions\InvalidOperationException')
            ? '\Yakupeyisan\CodeIgniter4\EntityFramework\Exceptions\InvalidOperationException'
            : \RuntimeException::class;
        if (count($rows) === 0) {
            throw new $exceptionClass('Sequence contains no elements');
        }
        if (count($rows) > 1) {
            throw new $exceptionClass('Sequence contains more than one element');
        }

        $final = clone $this;
        $final->takeCount = 1;
        $finalJson = $final->executeJsonModeQuery();
        $finalRows = json_decode($finalJson, true);
        if (!is_array($finalRows) || $finalRows === []) {
            throw new $exceptionClass('Sequence contains no elements');
        }

        return json_encode($finalRows[0], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * Compiled JSON-mode SQL (not cached with classic toSql() keys).
     */
    public function toJsonSql(): string
    {
        $this->assertJsonModeCompatibleJson();
        $this->validateGroupBalance();

        return $this->buildJsonModeSql();
    }

    private function assertJsonModeCompatibleJson(): void
    {
        $driver = strtolower($this->connection->getPlatform() ?? '');
        if (!in_array($driver, ['sqlsrv', 'sqlserver'], true)) {
            throw new \InvalidArgumentException('JSON mode requires SQL Server (sqlsrv/sqlserver).');
        }
        if ($this->useRawSql) {
            throw new \InvalidArgumentException('JSON mode does not support fromSqlRaw / useRawSql in V1.');
        }
        if ($this->groupBy !== null) {
            throw new \InvalidArgumentException('JSON mode does not support groupBy in V1.');
        }
        if (!empty($this->joins) || !empty($this->rawJoins)) {
            throw new \InvalidArgumentException('JSON mode does not support join / joinRaw in V1.');
        }
        if (!empty($this->selectRaw)) {
            throw new \InvalidArgumentException('JSON mode does not support selectRaw in V1.');
        }
        if (!empty($this->whereRaw)) {
            throw new \InvalidArgumentException('JSON mode does not support whereRaw in V1.');
        }
        if ($this->select !== null) {
            throw new \InvalidArgumentException('JSON mode does not support select(projection) in V1; use full entity shape.');
        }
        $this->jsonAssertIncludeOptionsSupported();
    }

    private function jsonAssertIncludeOptionsSupported(): void
    {
        // For reference navigations we now honor custom joinCondition and INNER joinType
        // (mirroring AdvancedQueryBuilder's classic pipeline). For collection navigations
        // these options are ignored by the correlated FOR JSON PATH subquery; refuse the
        // combination explicitly so callers don't silently get a different shape.
        $walker = function (array $node, string $parentDotPrefix, string $parentEntityType) use (&$walker): void {
            $navName = $node['path'] ?? $node['navigation'] ?? null;
            if ($navName === null || $navName === '') {
                return;
            }
            $fullPath = $parentDotPrefix === '' ? $navName : $parentDotPrefix . '.' . $navName;
            $navInfo = $parentDotPrefix === ''
                ? $this->getNavigationInfo($navName)
                : $this->getNavigationInfo($fullPath);

            $hasJoinCondition = isset($node['joinCondition']) && trim((string) $node['joinCondition']) !== '';
            $joinType = strtoupper((string) ($node['joinType'] ?? 'LEFT'));

            if ($navInfo !== null && $navInfo['isCollection']) {
                if ($hasJoinCondition) {
                    throw new \InvalidArgumentException(
                        'JSON mode V1 does not support include(..., joinCondition) on collection navigation "' . $fullPath . '".'
                    );
                }
                if ($joinType !== 'LEFT') {
                    throw new \InvalidArgumentException(
                        'JSON mode V1 does not support non-LEFT include joinType on collection navigation "' . $fullPath . '".'
                    );
                }
            } elseif ($navInfo === null) {
                // Unknown navigation: still reject joinCondition with an unrecognized navigation to avoid silently dropping it.
                if ($hasJoinCondition) {
                    throw new \InvalidArgumentException(
                        'JSON mode V1: include() joinCondition specified for unknown navigation "' . $fullPath . '".'
                    );
                }
            }

            if (isset($node['thenIncludes']) && is_array($node['thenIncludes'])) {
                $childParentEntity = $navInfo['entityType'] ?? $parentEntityType;
                foreach ($node['thenIncludes'] as $child) {
                    if (is_array($child)) {
                        $walker($child, $fullPath, $childParentEntity);
                    }
                }
            }
        };
        foreach ($this->includes as $inc) {
            if (is_array($inc)) {
                $walker($inc, '', $this->entityType);
            }
        }
    }

    private function executeJsonModeQuery(): string
    {
        $this->assertJsonModeCompatibleJson();
        $this->validateGroupBalance();
        $this->currentQueryStats = [
            'entityType' => $this->entityType,
            'startTime' => microtime(true),
            'includes' => count($this->includes),
            'whereCount' => count($this->wheres),
            'orderByCount' => count($this->orderBys),
            'queryType' => 'JSON_MODE',
        ];
        $this->connection->query('SET DATEFORMAT ' . env('app.mssqlDateFormat', 'Ymd'));

        $sql = $this->buildJsonModeSql();
        if ($this->queryHints !== null) {
            $driver = strtolower($this->connection->getPlatform() ?? '');
            $tableName = $this->context->getTableName($this->entityType);
            $sql = $this->queryHints->applyToSql($sql, $driver, $tableName);
        }
        //log_message('error', 'executeJsonModeQuery: SQL: ' . $sql);
        $sqlExecStart = microtime(true);
        try {
            $query = $this->connection->query($sql);
        } catch (\Throwable $e) {
            @file_put_contents(
                WRITEPATH . 'logs/jsonmode-debug.log',
                '[' . date('Y-m-d H:i:s') . '] ' . $e->getMessage() . "\nSQL:\n" . $sql . "\n\n",
                FILE_APPEND
            );
            throw $e;
        }
        $resultRows = $query->getResultArray();
        $this->currentQueryStats['sqlExecutionTime'] = microtime(true) - $sqlExecStart;
        $this->currentQueryStats['sql'] = substr($sql, 0, 500);
        $this->currentQueryStats['sqlFull'] = $sql;
        $this->currentQueryStats['parsingTime'] = 0.0;
        $this->currentQueryStats['mappingTime'] = 0.0;
        $this->currentQueryStats['trackingTime'] = 0.0;
        $this->currentQueryStats['lazyLoadingTime'] = 0.0;

        $buffer = '';
        foreach ($resultRows as $row) {
            foreach ($row as $fragment) {
                if ($fragment !== null && $fragment !== '') {
                    $buffer .= (string) $fragment;
                }
            }
        }

        $payload = $buffer !== '' ? $buffer : '[]';
        $decoded = json_decode($payload, true);
        if (is_array($decoded) && $decoded !== [] && !empty($this->includes)) {
            $decoded = $this->jsonDedupeRootRecordsByPrimaryKey($decoded);
            $payload = json_encode($decoded, JSON_UNESCAPED_UNICODE);
            if ($payload === false) {
                $payload = '[]';
            }
        }
        $this->currentQueryStats['rowCount'] = is_array($decoded) ? count($decoded) : 0;
        $this->finalizeQueryStats();

        return $payload;
    }

    /**
     * Reference-navigation JOINs in JSON mode can multiply root rows (same PK repeated).
     * Classic EF-style materialization dedupes via entitiesMap; mirror that here.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function jsonDedupeRootRecordsByPrimaryKey(array $rows): array
    {
        $entityReflection = self::getCachedReflection($this->entityType);
        $primaryKeyProperty = null;
        foreach ($entityReflection->getProperties() as $property) {
            if ($property->isStatic()) {
                continue;
            }
            $keyAttributes = $property->getAttributes(\Yakupeyisan\CodeIgniter4\EntityFramework\Attributes\Key::class);
            if ($keyAttributes !== []) {
                $primaryKeyProperty = $property->getName();
                break;
            }
        }
        if ($primaryKeyProperty === null) {
            $commonNames = ['Id', $entityReflection->getShortName() . 'Id', $entityReflection->getShortName() . 'ID'];
            foreach ($commonNames as $name) {
                if ($entityReflection->hasProperty($name)) {
                    $primaryKeyProperty = $name;
                    break;
                }
            }
        }
        if ($primaryKeyProperty === null) {
            return $rows;
        }

        $seen = [];
        $deduped = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                $deduped[] = $row;
                continue;
            }
            $id = $row[$primaryKeyProperty] ?? null;
            if ($id === null) {
                $deduped[] = $row;
                continue;
            }
            $key = is_object($id) ? spl_object_hash($id) : (string) $id;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $deduped[] = $row;
        }

        return $deduped;
    }

    private function buildJsonModeSql(): string
    {
        $provider = \Yakupeyisan\CodeIgniter4\EntityFramework\Providers\DatabaseProviderFactory::getProvider($this->connection);
        $mainAlias = 'e0';
        $entityReflection = self::getCachedReflection($this->entityType);
        $mainTable = $this->context->getTableName($this->entityType);
        $quotedMainTable = $provider->escapeIdentifier($mainTable);
        $quotedMainAlias = $provider->escapeIdentifier($mainAlias);

        $selectParts = [];
        $joinParts = [];
        $refAliasByPath = [];

        $this->jsonAppendScalarSelects($selectParts, $entityReflection, $mainAlias, '');

        $refIndex = 0;
        $this->jsonEnsureWhereReferenceJoins($selectParts, $joinParts, $refAliasByPath, $refIndex);

        foreach ($this->includes as $include) {
            if (!is_array($include)) {
                continue;
            }
            $this->jsonProcessIncludeTree(
                $include,
                $this->entityType,
                $mainAlias,
                '',
                $selectParts,
                $joinParts,
                $refAliasByPath,
                $refIndex
            );
        }

        // WHERE-driven reference JOINs can add Employee.* before sibling includes (CardType, …)
        // while deferred thenIncludes append Employee.CustomField.* later. SQL Server FOR JSON PATH
        // requires all columns sharing the same dot-path prefix to be consecutive (error 13601).
        $selectParts = $this->jsonSortSelectPartsForJsonPath($selectParts);

        $whereSql = $this->jsonBuildWhereClause($mainAlias, $refAliasByPath);
        $orderSql = $this->jsonBuildOrderByClause($mainAlias, $refAliasByPath);
        $offsetFetch = $this->jsonBuildOffsetFetchClause($provider);

        if ($offsetFetch !== '' && $orderSql === '') {
            $pkCol = $this->getPrimaryKeyColumnName($entityReflection);
            $orderSql = $provider->escapeIdentifier($mainAlias) . '.' . $provider->escapeIdentifier($pkCol);
        }

        $sql = 'SELECT ' . implode(', ', $selectParts) . "\n"
            . "FROM {$quotedMainTable} AS {$quotedMainAlias}";
        if ($joinParts !== []) {
            $sql .= "\n" . implode("\n", $joinParts);
        }
        if ($whereSql !== '') {
            $sql .= "\nWHERE " . $whereSql;
        }
        if ($orderSql !== '') {
            $sql .= "\nORDER BY " . $orderSql;
        }
        if ($offsetFetch !== '') {
            $sql .= "\n" . $offsetFetch;
        }
        $sql .= "\nFOR JSON PATH, INCLUDE_NULL_VALUES";

        return $sql;
    }

    /**
     * LEFT JOIN reference navigations required by WHERE (detectNavigationPaths) if not already included.
     *
     * @param list<string> $selectParts
     * @param list<string> $joinParts
     * @param array<string, string> $refAliasByPath
     */
    private function jsonEnsureWhereReferenceJoins(array &$selectParts, array &$joinParts, array &$refAliasByPath, int &$refIndex): void
    {
        $pathsToEnsure = [];
        foreach ($this->wheres as $whereItem) {
            $pred = is_array($whereItem) ? ($whereItem['predicate'] ?? null) : $whereItem;
            if (is_callable($pred)) {
                foreach ($this->detectNavigationPaths($pred) as $navPath) {
                    $pathsToEnsure[] = $navPath;
                }
            }

            $rawSql = is_array($whereItem) ? ($whereItem['rawSql'] ?? null) : null;
            if (is_string($rawSql) && str_contains($rawSql, '.')) {
                if (preg_match_all('/\b([A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)+)\b/', $rawSql, $matches)) {
                    foreach (array_unique($matches[1]) as $dotPath) {
                        foreach ($this->collectReferenceNavigationPathsFromDotPath($dotPath) as $navPath) {
                            $pathsToEnsure[] = $navPath;
                        }
                    }
                }
            }
        }

        foreach (array_unique($pathsToEnsure) as $navPath) {
            if ($navPath === '' || isset($refAliasByPath[$navPath])) {
                continue;
            }
            $this->jsonEnsureReferenceJoinForPath(
                $navPath,
                $selectParts,
                $joinParts,
                $refAliasByPath,
                $refIndex
            );
        }
    }

    /**
     * @param list<string> $selectParts
     * @param list<string> $joinParts
     * @param array<string, string> $refAliasByPath
     */
    private function jsonEnsureReferenceJoinForPath(
        string $navPath,
        array &$selectParts,
        array &$joinParts,
        array &$refAliasByPath,
        int &$refIndex
    ): void {
        $parts = explode('.', $navPath);
        if ($parts === []) {
            return;
        }

        $fake = ['path' => array_shift($parts), 'thenIncludes' => []];
        $current = &$fake;
        foreach ($parts as $part) {
            $child = ['path' => $part, 'thenIncludes' => []];
            $current['thenIncludes'][] = $child;
            $current = $child;
        }

        $this->jsonProcessIncludeTree(
            $fake,
            $this->entityType,
            'e0',
            '',
            $selectParts,
            $joinParts,
            $refAliasByPath,
            $refIndex
        );
    }

    /**
     * @param list<string> $selectParts
     */
    private function jsonAppendScalarSelects(array &$selectParts, ReflectionClass $entityReflection, string $tableAlias, string $dotPrefix): void
    {
        $provider = \Yakupeyisan\CodeIgniter4\EntityFramework\Providers\DatabaseProviderFactory::getProvider($this->connection);
        $quotedAlias = $provider->escapeIdentifier($tableAlias);
        $columnsWithProperties = $this->getEntityColumnsWithProperties($entityReflection);

        foreach ($columnsWithProperties as $colInfo) {
            $property = $entityReflection->getProperty($colInfo['property']);
            $injectAttributes = $property->getAttributes(\Yakupeyisan\CodeIgniter4\EntityFramework\Attributes\InjectQuery::class);
            $sensitiveAttributes = $property->getAttributes(\Yakupeyisan\CodeIgniter4\EntityFramework\Attributes\SensitiveValue::class);
            $quotedCol = $provider->escapeIdentifier($colInfo['column']);
            $jsonKey = $dotPrefix === '' ? $colInfo['property'] : $dotPrefix . $colInfo['property'];
            $quotedJsonKey = $provider->escapeIdentifier($jsonKey);

            if (!empty($injectAttributes)) {
                // Build the bare SQL expression directly to avoid getColumnSelectExpression()'s
                // embedded `AS [Column]` which would collide with our JSON-key alias and produce
                // invalid SQL like `... AS [Day] AS [Day]`.
                $injectAttr = $injectAttributes[0]->newInstance();
                $expression = trim($injectAttr->expression ?? '');
                if ($expression !== '') {
                    $expression = str_replace('{alias}', $quotedAlias, $expression);
                    $selectParts[] = "{$expression} AS {$quotedJsonKey}";
                }
                continue;
            }

            $columnRef = "{$quotedAlias}.{$quotedCol}";
            if (!empty($sensitiveAttributes) && !$this->isSensitive) {
                $sensitiveAttr = $sensitiveAttributes[0]->newInstance();
                $maskedExpression = $provider->getMaskingSql(
                    $columnRef,
                    $sensitiveAttr->maskChar,
                    $sensitiveAttr->visibleStart,
                    $sensitiveAttr->visibleEnd,
                    $sensitiveAttr->customMask
                );
                $selectParts[] = "({$maskedExpression}) AS {$quotedJsonKey}";
            } else {
                $selectParts[] = "{$columnRef} AS {$quotedJsonKey}";
            }
        }
    }

    /**
     * @param list<string> $selectParts
     * @param list<string> $joinParts
     * @param array<string, string> $refAliasByPath
     */
    private function jsonProcessIncludeTree(
        array $includeNode,
        string $parentEntityType,
        string $parentSqlAlias,
        string $parentDotPrefix,
        array &$selectParts,
        array &$joinParts,
        array &$refAliasByPath,
        int &$refIndex
    ): void {
        $provider = \Yakupeyisan\CodeIgniter4\EntityFramework\Providers\DatabaseProviderFactory::getProvider($this->connection);
        $navName = $includeNode['path'] ?? $includeNode['navigation'] ?? null;
        if ($navName === null || $navName === '') {
            return;
        }

        $fullPath = $parentDotPrefix === '' ? $navName : $parentDotPrefix . '.' . $navName;
        // Resolve relative to the parent entity rather than always walking from the root entity,
        // so nested thenIncludes inside collection subqueries (parentDotPrefix='') still find their
        // navigation on the collection's entity type.
        $navInfo = $this->getNavigationInfoForEntity($navName, $parentEntityType);

        if ($navInfo === null) {
            log_message('warning', "jsonProcessIncludeTree: Unknown navigation '{$fullPath}' on entity '{$parentEntityType}'");

            return;
        }

        if (!$navInfo['isCollection']) {
            if (isset($refAliasByPath[$fullPath])) {
                $refAlias = $refAliasByPath[$fullPath];
                if (!empty($includeNode['thenIncludes'])) {
                    foreach ($includeNode['thenIncludes'] as $child) {
                        if (!is_array($child)) {
                            continue;
                        }
                        $childNav = $child['navigation'] ?? $child['path'] ?? null;
                        if ($childNav === null) {
                            continue;
                        }
                        $childNode = $child;
                        if (!isset($childNode['path'])) {
                            $childNode['path'] = $childNav;
                        }
                        $this->jsonProcessIncludeTree(
                            $childNode,
                            $navInfo['entityType'],
                            $refAlias,
                            $fullPath,
                            $selectParts,
                            $joinParts,
                            $refAliasByPath,
                            $refIndex
                        );
                    }
                }

                return;
            }
        }

        if ($navInfo['isCollection']) {
            $thenIncludes = $includeNode['thenIncludes'] ?? [];
            $whereClause = $includeNode['whereClause'] ?? null;
            // Use the FULL dotted path as the JSON column alias so SQL Server's FOR JSON PATH
            // places this correlated subquery under its correct nested object (e.g.
            // [PaymentOfVirtualPos.Employee.EmployeeDepartments]). Using only the bare nav name
            // can collide with another collection of the same name on a different branch and
            // triggers "JSON çıkışında 'X' özelliği oluşturulamıyor" errors.
            $jsonColAlias = $provider->escapeIdentifier($fullPath);
            $expr = $this->jsonBuildCollectionJsonQuery(
                $fullPath,
                $navInfo,
                $thenIncludes,
                $whereClause,
                $parentSqlAlias,
                $parentEntityType
            );
            $selectParts[] = "{$expr} AS {$jsonColAlias}";

            return;
        }

        $refAlias = $this->getTableAlias(str_replace('.', '_', $fullPath), $refIndex);
        $refIndex++;
        $refAliasByPath[$fullPath] = $refAlias;

        $relatedEntityType = $navInfo['entityType'];
        $relatedTable = $this->context->getTableName($relatedEntityType);
        $quotedRelatedTable = $provider->escapeIdentifier($relatedTable);
        $quotedRefAlias = $provider->escapeIdentifier($refAlias);
        $quotedParentAlias = $provider->escapeIdentifier($parentSqlAlias);

        $parentReflection = new ReflectionClass($parentEntityType);

        $customJoinCondition = isset($includeNode['joinCondition']) ? trim((string) $includeNode['joinCondition']) : '';
        if ($customJoinCondition !== '') {
            // Mirror AdvancedQueryBuilder placeholder substitution: {alias} = parent (e.g. AccessEvent),
            // {relatedAlias} = navigation target (e.g. Card / Terminal).
            $joinOn = str_replace(
                ['{alias}', '{relatedAlias}'],
                [$quotedParentAlias, $quotedRefAlias],
                $customJoinCondition
            );
        } else {
            $joinOn = $this->buildJoinCondition($parentSqlAlias, $refAlias, $navName, $navInfo, $parentEntityType);
        }

        $joinType = strtoupper((string) ($includeNode['joinType'] ?? 'LEFT'));
        if ($joinType !== 'INNER' && $joinType !== 'LEFT' && $joinType !== 'RIGHT') {
            $joinType = 'LEFT';
        }
        $joinParts[] = $joinType . ' JOIN ' . $quotedRelatedTable . ' AS ' . $quotedRefAlias . ' ON ' . $joinOn;

        $newDot = $fullPath . '.';
        $relatedReflection = new ReflectionClass($relatedEntityType);
        $this->jsonAppendScalarSelects($selectParts, $relatedReflection, $refAlias, $newDot);

        if (!empty($includeNode['thenIncludes'])) {
            foreach ($includeNode['thenIncludes'] as $child) {
                if (!is_array($child)) {
                    continue;
                }
                $childNav = $child['navigation'] ?? $child['path'] ?? null;
                if ($childNav === null) {
                    continue;
                }
                $childNode = $child;
                if (!isset($childNode['path'])) {
                    $childNode['path'] = $childNav;
                }
                $this->jsonProcessIncludeTree(
                    $childNode,
                    $relatedEntityType,
                    $refAlias,
                    $fullPath,
                    $selectParts,
                    $joinParts,
                    $refAliasByPath,
                    $refIndex
                );
            }
        }
    }

    /**
     * Correlated JSON_QUERY((SELECT ... FOR JSON PATH)) for a collection include.
     *
     * @param list<array<string, mixed>> $thenIncludes
     */
    private function jsonBuildCollectionJsonQuery(
        string $fullNavPath,
        array $navInfo,
        array $thenIncludes,
        ?string $includeWhereClause,
        string $parentSqlAlias,
        string $parentEntityType
    ): string {
        $provider = \Yakupeyisan\CodeIgniter4\EntityFramework\Providers\DatabaseProviderFactory::getProvider($this->connection);
        $quotedParentAlias = $provider->escapeIdentifier($parentSqlAlias);
        $parentReflection = new ReflectionClass($parentEntityType);
        $parentPkCol = $this->getPrimaryKeyColumnName($parentReflection);
        $quotedParentPk = $provider->escapeIdentifier($parentPkCol);

        $joinEntityType = $navInfo['joinEntityType'] ?? null;
        $relatedEntityTypeFromNav = $navInfo['entityType'];
        $resolvedRelated = $joinEntityType
            ? ($this->findRelatedEntityFromJoinEntityForParent($joinEntityType, $parentEntityType) ?? $relatedEntityTypeFromNav)
            : $relatedEntityTypeFromNav;
        $relatedReflection = new ReflectionClass($resolvedRelated);

        // `getJoinEntityType()` koleksiyonun tipini her zaman join entity olarak işaret ediyor;
        // gerçekten ayrı bir pivot varsa `findRelatedEntityFromJoinEntityForParent` farklı bir entity döner.
        // Aynı entity'ye çözüldüyse bu klasik one-to-many'dir (pivot yok) → düz one-to-many dalına düş.
        $isTrueJoinPivot = $joinEntityType !== null && $joinEntityType !== $resolvedRelated;

        if ($isTrueJoinPivot) {
            $joinTable = $this->context->getTableName($joinEntityType);
            $relatedTable = $this->context->getTableName($resolvedRelated);
            $joinAlias = 'j' . substr(hash('sha256', $fullNavPath), 0, 4);
            $relAlias = 'r' . substr(hash('sha256', $fullNavPath . 'r'), 0, 4);
            $quotedJoinTable = $provider->escapeIdentifier($joinTable);
            $quotedRelatedTable = $provider->escapeIdentifier($relatedTable);
            $quotedJoinAlias = $provider->escapeIdentifier($joinAlias);
            $quotedRelAlias = $provider->escapeIdentifier($relAlias);

            $joinEntityReflection = new ReflectionClass($joinEntityType);
            $relatedShort = $relatedReflection->getShortName();
            $expectedFk = $relatedShort . 'Id';
            $joinFkToRelated = $joinEntityReflection->hasProperty($expectedFk) ? $expectedFk : null;
            if ($joinFkToRelated === null) {
                foreach ($joinEntityReflection->getProperties() as $p) {
                    $attrs = $p->getAttributes(\Yakupeyisan\CodeIgniter4\EntityFramework\Attributes\ForeignKey::class);
                    if ($attrs !== []) {
                        $fkAttr = $attrs[0]->newInstance();
                        if (strcasecmp((string) $fkAttr->navigationProperty, $relatedShort) === 0) {
                            $joinFkToRelated = $p->getName();
                            break;
                        }
                    }
                }
            }
            if ($joinFkToRelated === null) {
                throw new \RuntimeException("JSON mode: could not resolve join FK to related entity for collection '{$fullNavPath}'.");
            }
            $joinFkToRelatedCol = $this->getColumnNameFromProperty($joinEntityReflection, $joinFkToRelated);
            $quotedJoinFkToRelatedCol = $provider->escapeIdentifier($joinFkToRelatedCol);
            $relatedPk = $this->getPrimaryKeyColumnName($relatedReflection);
            $quotedRelatedPk = $provider->escapeIdentifier($relatedPk);

            $fkToParentProp = $navInfo['foreignKey'] ?? null;
            if ($fkToParentProp === null) {
                throw new \RuntimeException("JSON mode: missing foreignKey on collection nav '{$fullNavPath}'.");
            }
            $fkToParentCol = $this->getColumnNameFromProperty($joinEntityReflection, $fkToParentProp);
            $quotedFkToParentCol = $provider->escapeIdentifier($fkToParentCol);

            $innerSelect = [];
            $this->jsonAppendScalarSelects($innerSelect, $relatedReflection, $relAlias, '');
            $innerJoins = [];
            $innerRefMap = [];
            $innerRefIdx = 0;
            foreach ($thenIncludes as $child) {
                if (!is_array($child)) {
                    continue;
                }
                $cn = $child['navigation'] ?? $child['path'] ?? null;
                if ($cn === null) {
                    continue;
                }
                $childNode = $child;
                $childNode['path'] = $cn;
                $this->jsonProcessIncludeTree(
                    $childNode,
                    $resolvedRelated,
                    $relAlias,
                    '',
                    $innerSelect,
                    $innerJoins,
                    $innerRefMap,
                    $innerRefIdx
                );
            }

            $sqlInner = 'SELECT ' . implode(', ', $innerSelect)
                . "\nFROM {$quotedJoinTable} AS {$quotedJoinAlias}"
                . "\nINNER JOIN {$quotedRelatedTable} AS {$quotedRelAlias} ON {$quotedJoinAlias}.{$quotedJoinFkToRelatedCol} = {$quotedRelAlias}.{$quotedRelatedPk}";
            if ($innerJoins !== []) {
                $sqlInner .= "\n" . implode("\n", $innerJoins);
            }
            $sqlInner .= "\nWHERE {$quotedJoinAlias}.{$quotedFkToParentCol} = {$quotedParentAlias}.{$quotedParentPk}";
            if ($includeWhereClause !== null && trim($includeWhereClause) !== '') {
                $wc = str_replace('{alias}', $quotedRelAlias, $includeWhereClause);
                $sqlInner .= ' AND (' . $wc . ')';
            }
            $sqlInner .= "\nFOR JSON PATH, INCLUDE_NULL_VALUES";

            return 'JSON_QUERY((' . "\n" . $sqlInner . "\n))";
        }

        $relatedTable = $this->context->getTableName($resolvedRelated);
        $relAlias = 'r' . substr(hash('sha256', $fullNavPath), 0, 5);
        $quotedRelatedTable = $provider->escapeIdentifier($relatedTable);
        $quotedRelAlias = $provider->escapeIdentifier($relAlias);

        $fkProp = $navInfo['foreignKey'] ?? null;
        if ($fkProp === null) {
            throw new \RuntimeException("JSON mode: missing foreignKey for collection '{$fullNavPath}'.");
        }
        $fkCol = $this->getColumnNameFromProperty($relatedReflection, $fkProp);
        $quotedFkCol = $provider->escapeIdentifier($fkCol);

        $innerSelect = [];
        $this->jsonAppendScalarSelects($innerSelect, $relatedReflection, $relAlias, '');
        $innerJoins = [];
        $innerRefMap = [];
        $innerRefIdx = 0;
        foreach ($thenIncludes as $child) {
            if (!is_array($child)) {
                continue;
            }
            $cn = $child['navigation'] ?? $child['path'] ?? null;
            if ($cn === null) {
                continue;
            }
            $childNode = $child;
            $childNode['path'] = $cn;
            $this->jsonProcessIncludeTree(
                $childNode,
                $resolvedRelated,
                $relAlias,
                '',
                $innerSelect,
                $innerJoins,
                $innerRefMap,
                $innerRefIdx
            );
        }

        $sqlInner = 'SELECT ' . implode(', ', $innerSelect)
            . "\nFROM {$quotedRelatedTable} AS {$quotedRelAlias}";
        if ($innerJoins !== []) {
            $sqlInner .= "\n" . implode("\n", $innerJoins);
        }
        $sqlInner .= "\nWHERE {$quotedRelAlias}.{$quotedFkCol} = {$quotedParentAlias}.{$quotedParentPk}";
        if ($includeWhereClause !== null && trim($includeWhereClause) !== '') {
            $wc = str_replace('{alias}', $quotedRelAlias, $includeWhereClause);
            $sqlInner .= ' AND (' . $wc . ')';
        }
        $sqlInner .= "\nFOR JSON PATH, INCLUDE_NULL_VALUES";

        return 'JSON_QUERY((' . "\n" . $sqlInner . "\n))";
    }

    /**
     * Raw WHERE SQL'inde `{alias}`, `[MainTable]` ve `MainTable.` referanslarını JSON ana takma adına çevirir.
     * Bu sayede `where('[PaymentsOfVirtualPos].[PaymentName] IS NOT NULL')` gibi non-JSON modda çalışan
     * ham SQL parçaları, JSON modda da ana tablo `[e0]` olarak aliaslanmış olsa bile bağlanabilir.
     */
    private function jsonRewriteRawSqlMainTableRefs(string $rawSql, string $mainAlias): string
    {
        $provider = \Yakupeyisan\CodeIgniter4\EntityFramework\Providers\DatabaseProviderFactory::getProvider($this->connection);
        $mainTable = $this->context->getTableName($this->entityType);
        $quotedAlias = $provider->escapeIdentifier($mainAlias);

        $sql = str_replace('{alias}', $quotedAlias, $rawSql);

        if ($mainTable !== '' && $mainTable !== null) {
            $quotedTable = $provider->escapeIdentifier($mainTable);
            $sql = str_replace($quotedTable . '.', $quotedAlias . '.', $sql);
            $sql = preg_replace(
                '/(?<![\w\[])' . preg_quote($mainTable, '/') . '\./',
                $quotedAlias . '.',
                $sql
            ) ?? $sql;
        }

        return $sql;
    }

    private function jsonBuildWhereClause(string $mainAlias, array $refAliasByPath): string
    {
        $conditions = [];
        $inGroup = false;
        $group = [];

        foreach ($this->wheres as $whereItem) {
            $groupStart = is_array($whereItem) && !empty($whereItem['groupStart']);
            $groupEnd = is_array($whereItem) && !empty($whereItem['groupEnd']);
            if ($groupStart) {
                if ($inGroup && $group !== []) {
                    $conditions[] = '(' . $this->jsonFlattenWhereConditions($group) . ')';
                    $group = [];
                }
                $inGroup = true;
                continue;
            }
            if ($groupEnd) {
                if ($inGroup && $group !== []) {
                    $conditions[] = '(' . $this->jsonFlattenWhereConditions($group) . ')';
                    $group = [];
                }
                $inGroup = false;
                continue;
            }

            $predicate = is_array($whereItem) ? ($whereItem['predicate'] ?? null) : $whereItem;
            $rawSql = is_array($whereItem) && isset($whereItem['rawSql']) ? $whereItem['rawSql'] : null;
            $isOr = is_array($whereItem) && !empty($whereItem['isOr']);

            if ($predicate === null && $rawSql === null) {
                continue;
            }

            if (is_string($rawSql)) {
                $rewritten = $this->jsonRewriteRawSqlMainTableRefs($rawSql, $mainAlias);
                $rewritten = $this->rewriteNavigationEmptyNotEmptyConditions($rewritten, $mainAlias, $refAliasByPath);
                $rewritten = $this->jsonRewriteRawSqlNavigationRefs($rewritten, $mainAlias, $refAliasByPath);
                $piece = '(' . trim($rewritten) . ')';
            } elseif (is_callable($predicate)) {
                $piece = $this->jsonParseCallableWhere($predicate, $mainAlias, $refAliasByPath);
                if ($piece === null || $piece === '') {
                    continue;
                }
            } else {
                continue;
            }

            if ($inGroup) {
                // Grup içi koşullar AND/OR konnektörlerini `isOr` bayrağına göre korur.
                // Ham parça `OR ` ön ekiyle saklanır ve gruplar düzleştirilirken çözülür.
                if ($isOr && $group !== []) {
                    $group[] = 'OR ' . $piece;
                } else {
                    $group[] = $piece;
                }
            } else {
                if ($isOr && $conditions !== []) {
                    $conditions[] = 'OR ' . $piece;
                } else {
                    $conditions[] = $piece;
                }
            }
        }

        if ($inGroup && $group !== []) {
            $conditions[] = '(' . $this->jsonFlattenWhereConditions($group) . ')';
        }

        return $this->jsonFlattenWhereConditions($conditions);
    }

    /**
     * @param list<string> $conditions
     */
    private function jsonFlattenWhereConditions(array $conditions): string
    {
        if ($conditions === []) {
            return '';
        }
        $out = '';
        foreach ($conditions as $i => $c) {
            if ($i > 0 && strtoupper(substr(ltrim($c), 0, 3)) === 'OR ') {
                $out .= ' OR ' . substr(ltrim($c), 3);
            } elseif ($i > 0) {
                $out .= ' AND ' . $c;
            } else {
                $out .= $c;
            }
        }

        return $out;
    }

    private function jsonParseCallableWhere(callable $predicate, string $mainAlias, array $refAliasByPath): ?string
    {
        try {
            $parser = new ExpressionParser($this->entityType, $mainAlias, $this->context);
            $reflection = new \ReflectionFunction($predicate);
            $staticVariables = $reflection->getStaticVariables();
            $variableValues = [];
            foreach ($staticVariables as $varName => $varValue) {
                if (!is_object($varValue) || !($varValue instanceof \Yakupeyisan\CodeIgniter4\EntityFramework\Core\Entity)) {
                    $variableValues[$varName] = $varValue;
                }
            }
            $parser->setVariableValues($variableValues);
            $code = $this->getFunctionCode($reflection);
            $lambdaCode = $parser->extractExpression($code);
            $sql = $parser->parseExpression($lambdaCode);
            if ($sql === null || $sql === '') {
                return null;
            }
            $sql = str_replace('[u].', '[' . $mainAlias . '].', $sql);
            $sql = str_replace('[U].', '[' . $mainAlias . '].', $sql);

            // Resolve NAVIGATION_IN tokens against the JSON pipeline's already-built JOIN aliases first
            // (refAliasByPath). This handles deep reference-only chains (e.g.
            // PaymentOfVirtualPos.Employee.Kadro.ID) that the generic EXISTS-subquery expander cannot
            // walk reliably because it always re-resolves the chain from the root entity.
            if (strpos($sql, 'NAVIGATION_IN:') !== false) {
                $sql = $this->jsonResolveNavigationInTokensViaAliases($sql, $refAliasByPath);
            }
            // Any NAVIGATION_IN tokens that did not match a known reference alias (typically collection
            // paths or unmapped navigations) are still expanded by AdvancedQueryBuilder's EXISTS form.
            if (strpos($sql, 'NAVIGATION_IN:') !== false) {
                $expanded = $this->expandNavigationInTokensForRawSql($sql, $mainAlias);
                if (is_string($expanded) && $expanded !== '') {
                    $sql = $expanded;
                }
            }
            // Recursive NAVIGATION_IN expansion may emit [u]. from getTableAliasForParser(); map to JSON main alias.
            $sql = str_replace('[u].', '[' . $mainAlias . '].', $sql);
            $sql = str_replace('[U].', '[' . $mainAlias . '].', $sql);

            // `InjectQuery` ile türetilen sanal kolonlar (Day/Time/DayName gibi) WHERE'de
            // alias adıyla geçemez — SQL Server kolon takma adlarını WHERE'de çözümlemez.
            // Bu yüzden parser'ın ürettiği `e0.Day` / `[e0].[Day]` referanslarını
            // gerçek ifadeyle (`CONVERT(DATE, [e0].[EventTime])`) değiştiriyoruz.
            $sql = $this->jsonRewriteInjectQueryReferences($sql, $mainAlias);

            return $this->jsonResolveNavigationTokens($sql, $refAliasByPath);
        } catch (\Throwable $e) {
            log_message('error', 'jsonParseCallableWhere: ' . $e->getMessage());

            throw $e;
        }
    }

    /**
     * Ana entity'nin `#[InjectQuery]` property'lerine yapılan WHERE referanslarını
     * gerçek SQL ifadesine çevirir. `e0.Day` / `[e0].[Day]` → `(CONVERT(DATE, [e0].[EventTime]))`.
     *
     * SELECT katmanı (`jsonAppendScalarSelects`) `... AS [Day]` üretir; ancak SQL Server
     * column-alias'ları WHERE/JOIN klozlarında çözümleyemediği için filtreler
     * (örn. AccessEvent.Day arasında BETWEEN) sanal kolonu doğrudan kullanırsa
     * "Geçersiz sütun adı 'Day'" hatasını verir.
     */
    private function jsonRewriteInjectQueryReferences(string $sql, string $mainAlias): string
    {
        if ($sql === '') {
            return $sql;
        }
        $provider = \Yakupeyisan\CodeIgniter4\EntityFramework\Providers\DatabaseProviderFactory::getProvider($this->connection);
        $quotedAlias = $provider->escapeIdentifier($mainAlias);
        $entityReflection = self::getCachedReflection($this->entityType);

        foreach ($entityReflection->getProperties() as $property) {
            $attrs = $property->getAttributes(\Yakupeyisan\CodeIgniter4\EntityFramework\Attributes\InjectQuery::class);
            if ($attrs === []) {
                continue;
            }
            /** @var \Yakupeyisan\CodeIgniter4\EntityFramework\Attributes\InjectQuery $injectAttr */
            $injectAttr = $attrs[0]->newInstance();
            $expression = trim((string) $injectAttr->expression);
            if ($expression === '') {
                continue;
            }
            $expression = str_replace('{alias}', $quotedAlias, $expression);
            $propName = $property->getName();
            $quotedProp = $provider->escapeIdentifier($propName);

            // [e0].[Day] gibi escaped formu
            $sql = str_replace($quotedAlias . '.' . $quotedProp, '(' . $expression . ')', $sql);

            // e0.Day gibi unescaped formu (parser çıktısı tipik bu şekilde)
            $unescaped = $mainAlias . '.' . $propName;
            $sql = preg_replace(
                '/(?<![\w\[])' . preg_quote($unescaped, '/') . '(?![\w\]])/',
                '(' . $expression . ')',
                $sql
            ) ?? $sql;
        }

        return $sql;
    }

    /**
     * Resolve NAVIGATION_IN:Path.Column:values tokens by matching the navigation path against the
     * JSON pipeline's reference JOIN map (refAliasByPath). When the path resolves to an already-
     * joined reference navigation, emit a direct "[alias].[column] IN (values)" condition instead
     * of an EXISTS subquery. This handles deep reference chains (3+ levels) reliably whereas the
     * generic EXISTS expander only handles up to 3 parts and assumes specific FK directions.
     *
     * Tokens that do not map to a known reference alias (e.g. collection-bearing paths) are left
     * intact so the caller can fall back to the classic EXISTS expander.
     *
     * @param array<string, string> $refAliasByPath
     */
    private function jsonResolveNavigationInTokensViaAliases(string $sql, array $refAliasByPath): string
    {
        $provider = \Yakupeyisan\CodeIgniter4\EntityFramework\Providers\DatabaseProviderFactory::getProvider($this->connection);

        return preg_replace_callback(
            '/NAVIGATION_IN:([A-Za-z0-9_.]+):([^)\s]+)/',
            function (array $m) use ($refAliasByPath, $provider): string {
                $fullPath = $m[1];
                $values = $m[2];
                $pathParts = explode('.', $fullPath);
                if (count($pathParts) < 2) {
                    return $m[0];
                }
                $columnName = array_pop($pathParts);
                $navParts = $pathParts;

                while (count($navParts) >= 1) {
                    $navigationProperty = implode('.', $navParts);
                    if (!isset($refAliasByPath[$navigationProperty])) {
                        array_pop($navParts);
                        continue;
                    }
                    if ($this->navigationPathContainsCollection($navParts)) {
                        return $m[0];
                    }

                    $entityType = $this->jsonResolveEntityTypeForNavPath($navParts);
                    if ($entityType === null) {
                        array_pop($navParts);
                        continue;
                    }

                    $refReflection = new ReflectionClass($entityType);
                    if (!$refReflection->hasProperty($columnName)) {
                        array_pop($navParts);
                        continue;
                    }

                    $column = $this->getColumnNameFromProperty($refReflection, $columnName);
                    $quotedAlias = $provider->escapeIdentifier($refAliasByPath[$navigationProperty]);
                    $quotedColumn = $provider->escapeIdentifier($column);

                    if ($values === '' || $values === '?') {
                        return $quotedAlias . '.' . $quotedColumn . ' IN (' . ($values === '' ? '?' : $values) . ')';
                    }

                    return $quotedAlias . '.' . $quotedColumn . ' IN (' . $values . ')';
                }

                return $m[0];
            },
            $sql
        ) ?? $sql;
    }

    private function jsonResolveNavigationTokens(string $sql, array $refAliasByPath): string
    {
        while (preg_match('/NAVIGATION:([^\s]+)/', $sql, $m, PREG_OFFSET_CAPTURE)) {
            $start = $m[0][1];
            $navTokenLen = strlen($m[0][0]);
            $navPathFull = $m[1][0];
            $after = substr($sql, $start + $navTokenLen);

            if (!preg_match(
                '/^\s*((?:IS\s+NOT\s+NULL|IS\s+NULL|NOT\s+LIKE|LIKE|<>|!=|>=|<=|=|>|<))\s*(.*)$/is',
                $after,
                $opm
            )) {
                throw new \InvalidArgumentException('JSON mode: could not parse SQL after NAVIGATION:' . $navPathFull);
            }
            $op = trim($opm[1]);
            $rhsTail = $opm[2];
            $isNullary = stripos($op, 'IS ') === 0;
            [$rhs, $remainder] = $this->jsonSplitRhsAtTopLevelAndOr(ltrim($rhsTail));

            $pathParts = explode('.', $navPathFull);
            if (count($pathParts) < 2) {
                throw new \InvalidArgumentException('JSON mode: invalid NAVIGATION path ' . $navPathFull);
            }
            $columnName = array_pop($pathParts);
            $navigationProperty = implode('.', $pathParts);

            $navInfo = $this->getNavigationInfo($navigationProperty);
            if ($navInfo === null) {
                throw new \InvalidArgumentException('JSON mode: unknown navigation ' . $navigationProperty);
            }

            $provider = \Yakupeyisan\CodeIgniter4\EntityFramework\Providers\DatabaseProviderFactory::getProvider($this->connection);
            $quotedE0 = $provider->escapeIdentifier('e0');
            $mainReflection = self::getCachedReflection($this->entityType);
            $mainPk = $this->getPrimaryKeyColumnName($mainReflection);
            $quotedMainPk = $provider->escapeIdentifier($mainPk);

            if (!$navInfo['isCollection']) {
                $alias = $refAliasByPath[$navigationProperty] ?? null;
                if ($alias === null) {
                    throw new \InvalidArgumentException('JSON mode: WHERE references "' . $navigationProperty . '" but it is not included; add include().');
                }
                $ref = new ReflectionClass($navInfo['entityType']);
                $col = $this->getColumnNameFromProperty($ref, $columnName);
                $qa = $provider->escapeIdentifier($alias);
                $qc = $provider->escapeIdentifier($col);
                $replacement = $qa . '.' . $qc . ' ' . $op . ($isNullary || $rhs === '' ? '' : ' ' . $rhs);
            } else {
                if (($navInfo['joinEntityType'] ?? null) !== null) {
                    throw new \InvalidArgumentException('JSON mode V1: WHERE on many-to-many collection "' . $navigationProperty . '" is not supported; use include(whereClause).');
                }
                $relatedReflection = new ReflectionClass($navInfo['entityType']);
                $relatedTable = $this->context->getTableName($navInfo['entityType']);
                $quotedTable = $provider->escapeIdentifier($relatedTable);
                $cx = 'w' . substr(hash('sha256', $navigationProperty . $columnName . $start), 0, 6);
                $quotedCx = $provider->escapeIdentifier($cx);
                $fkProp = $navInfo['foreignKey'] ?? null;
                if ($fkProp === null) {
                    throw new \InvalidArgumentException('JSON mode: collection navigation missing foreignKey: ' . $navigationProperty);
                }
                $fkCol = $this->getColumnNameFromProperty($relatedReflection, $fkProp);
                $quotedFk = $provider->escapeIdentifier($fkCol);
                $filterCol = $this->getColumnNameFromProperty($relatedReflection, $columnName);
                $quotedFilterCol = $provider->escapeIdentifier($filterCol);
                $replacement = 'EXISTS (SELECT 1 FROM ' . $quotedTable . ' AS ' . $quotedCx
                    . ' WHERE ' . $quotedCx . '.' . $quotedFk . ' = ' . $quotedE0 . '.' . $quotedMainPk
                    . ' AND ' . $quotedCx . '.' . $quotedFilterCol . ' ' . $op . ($isNullary || $rhs === '' ? '' : ' ' . $rhs) . ')';
            }

            $consumedAfterLen = strlen($after) - strlen($remainder);
            $oldLen = $navTokenLen + $consumedAfterLen;
            $sql = substr($sql, 0, $start) . $replacement . substr($sql, $start + $oldLen);
        }

        return $sql;
    }

    /**
     * @return array{0: string, 1: string} [rhs, remainder starting with AND/OR or empty]
     */
    private function jsonSplitRhsAtTopLevelAndOr(string $rhsTail): array
    {
        $depth = 0;
        $inStr = false;
        $strCh = '';
        $len = strlen($rhsTail);
        for ($i = 0; $i < $len; $i++) {
            $ch = $rhsTail[$i];
            if (($ch === "'" || $ch === '"') && ($i === 0 || $rhsTail[$i - 1] !== '\\')) {
                if (!$inStr) {
                    $inStr = true;
                    $strCh = $ch;
                } elseif ($ch === $strCh) {
                    $inStr = false;
                }
                continue;
            }
            if ($inStr) {
                continue;
            }
            if ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                $depth--;
            } elseif ($depth === 0 && $i + 5 <= $len && strtoupper(substr($rhsTail, $i, 5)) === ' AND ') {
                return [trim(substr($rhsTail, 0, $i)), substr($rhsTail, $i)];
            } elseif ($depth === 0 && $i + 4 <= $len && strtoupper(substr($rhsTail, $i, 4)) === ' OR ') {
                return [trim(substr($rhsTail, 0, $i)), substr($rhsTail, $i)];
            }
        }

        return [trim($rhsTail), ''];
    }

    private function jsonBuildOrderByClause(string $mainAlias, array $refAliasByPath): string
    {
        if ($this->orderBys === []) {
            return '';
        }

        $savedReq = $this->requiredJoins;
        $savedIdx = $this->referenceNavIndexes;
        $this->requiredJoins = [];
        $this->referenceNavIndexes = [];
        $idx = 0;
        foreach ($refAliasByPath as $path => $alias) {
            $navInfo = $this->getNavigationInfo($path);
            if ($navInfo === null || $navInfo['isCollection']) {
                continue;
            }
            $tableName = $this->context->getTableName($navInfo['entityType']);
            $this->requiredJoins[$path] = [
                'table' => $tableName,
                'alias' => $alias,
                'entityType' => $navInfo['entityType'],
            ];
            $this->referenceNavIndexes[$path] = $idx++;
        }

        $parts = [];
        foreach ($this->orderBys as $orderBy) {
            $sql = $this->convertOrderByToSql($orderBy['selector'], $orderBy['direction'], $mainAlias);
            if ($sql === null || $sql === '') {
                $this->requiredJoins = $savedReq;
                $this->referenceNavIndexes = $savedIdx;
                throw new \InvalidArgumentException('JSON mode V1: this orderBy expression is not supported (navigation/collection order or unresolved join).');
            }
            $sql = str_replace('[u].', '[' . $mainAlias . '].', $sql);
            $parts[] = $sql;
        }
        $this->requiredJoins = $savedReq;
        $this->referenceNavIndexes = $savedIdx;

        return implode(', ', $parts);
    }

    private function jsonBuildOffsetFetchClause($provider): string
    {
        $offset = $this->skipCount ?? 0;
        $take = $this->takeCount;
        if ($take !== null && $take > 0) {
            return 'OFFSET ' . (int) $offset . ' ROWS FETCH NEXT ' . (int) $take . ' ROWS ONLY';
        }
        if ($offset > 0) {
            throw new \InvalidArgumentException('JSON mode: skip without take is not supported on SQL Server; set take().');
        }

        return '';
    }

    /**
     * Group SELECT columns by their FOR JSON PATH root prefix so nested paths are not interrupted.
     *
     * @param list<string> $selectParts
     * @return list<string>
     */
    private function jsonSortSelectPartsForJsonPath(array $selectParts): array
    {
        if (count($selectParts) < 2) {
            return $selectParts;
        }

        $decorated = [];
        foreach ($selectParts as $index => $part) {
            $alias = $this->jsonExtractSelectJsonAlias($part);
            $groupKey = $alias === '' || !str_contains($alias, '.')
                ? ''
                : explode('.', $alias, 2)[0];
            $decorated[] = [
                'part' => $part,
                'groupKey' => $groupKey,
                'alias' => $alias,
                'index' => $index,
            ];
        }

        usort($decorated, static function (array $a, array $b): int {
            if ($a['groupKey'] === '' && $b['groupKey'] !== '') {
                return -1;
            }
            if ($a['groupKey'] !== '' && $b['groupKey'] === '') {
                return 1;
            }
            if ($a['groupKey'] !== $b['groupKey']) {
                return strcmp($a['groupKey'], $b['groupKey']);
            }
            $aliasCmp = strcmp($a['alias'], $b['alias']);
            if ($aliasCmp !== 0) {
                return $aliasCmp;
            }

            return $a['index'] <=> $b['index'];
        });

        return array_map(static fn(array $row): string => $row['part'], $decorated);
    }

    private function jsonExtractSelectJsonAlias(string $selectPart): string
    {
        if (preg_match('/\sAS\s+\[([^\]]+)\]\s*$/i', $selectPart, $matches)) {
            return $matches[1];
        }

        return '';
    }

    /**
     * isEmpty / isNotEmpty ham SQL koşullarında collection veya derin navigation path'lerini
     * EXISTS / NOT EXISTS alt sorgularına çevirir; referans path'leri join alias'ına bırakır.
     *
     * @param array<string, string> $refAliasByPath
     */
    private function rewriteNavigationEmptyNotEmptyConditions(string $sql, string $mainAlias, array $refAliasByPath): string
    {
        if ($sql === '' || !str_contains($sql, '.')) {
            return $sql;
        }

        $sql = preg_replace_callback(
            '/\(\s*([A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)+)\s+IS\s+NULL(?:\s+OR\s+\1\s*=\s*\'\')?\s*\)/i',
            function (array $m) use ($mainAlias, $refAliasByPath): string {
                return $this->rewriteSingleEmptyNotEmptyNavCondition($m[1], 'isEmpty', $mainAlias, $refAliasByPath);
            },
            $sql
        ) ?? $sql;

        return preg_replace_callback(
            '/\(\s*([A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)+)\s+IS\s+NOT\s+NULL(?:\s+AND\s+\1\s*<>\s*\'\')?\s*\)/i',
            function (array $m) use ($mainAlias, $refAliasByPath): string {
                return $this->rewriteSingleEmptyNotEmptyNavCondition($m[1], 'isNotEmpty', $mainAlias, $refAliasByPath);
            },
            $sql
        ) ?? $sql;
    }

    /**
     * @param array<string, string> $refAliasByPath
     */
    private function rewriteSingleEmptyNotEmptyNavCondition(
        string $dotPath,
        string $mode,
        string $mainAlias,
        array $refAliasByPath
    ): string {
        $allParts = explode('.', $dotPath);
        if (count($allParts) < 2) {
            return $mode === 'isEmpty' ? "({$dotPath} IS NULL)" : "({$dotPath} IS NOT NULL)";
        }

        $columnName = array_pop($allParts);
        $navParts = $allParts;

        while (count($navParts) >= 1) {
            $navPath = implode('.', $navParts);
            if (isset($refAliasByPath[$navPath]) && !$this->navigationPathContainsCollection($navParts)) {
                $entityType = $this->jsonResolveEntityTypeForNavPath($navParts);
                if ($entityType !== null) {
                    $ref = new ReflectionClass($entityType);
                    if ($ref->hasProperty($columnName)) {
                        $provider = \Yakupeyisan\CodeIgniter4\EntityFramework\Providers\DatabaseProviderFactory::getProvider($this->connection);
                        $colRef = $provider->escapeIdentifier($refAliasByPath[$navPath]) . '.'
                            . $provider->escapeIdentifier($this->getColumnNameFromProperty($ref, $columnName));
                        if ($mode === 'isEmpty') {
                            return "({$colRef} IS NULL OR {$colRef} = '')";
                        }

                        return "({$colRef} IS NOT NULL AND {$colRef} <> '')";
                    }
                }
            }
            array_pop($navParts);
        }

        if (!$this->navigationPathNeedsExistsSemantics($dotPath, $refAliasByPath)) {
            if ($mode === 'isEmpty') {
                return "({$dotPath} IS NULL)";
            }

            return "({$dotPath} IS NOT NULL)";
        }

        $existsSql = $this->buildNavigationExistsForEmptyFilter(explode('.', $dotPath), $mainAlias);
        if ($existsSql === null) {
            if ($mode === 'isEmpty') {
                return "({$dotPath} IS NULL)";
            }

            return "({$dotPath} IS NOT NULL)";
        }

        return $mode === 'isEmpty' ? "NOT ({$existsSql})" : "({$existsSql})";
    }

    /**
     * @param string[] $pathParts
     */
    private function buildNavigationExistsForEmptyFilter(array $pathParts, string $mainAlias): ?string
    {
        if (count($pathParts) < 2) {
            return null;
        }

        $existsWithFilter = $this->buildNavigationPathConditionRecursive($pathParts, '1', null, $mainAlias);
        if ($existsWithFilter !== null) {
            $stripped = preg_replace('/\s+AND\s+.+\s+IN\s*\(\s*1\s*\)\s*\)\s*$/i', ')', $existsWithFilter);
            if ($stripped !== null && $stripped !== '') {
                return trim($stripped);
            }
        }

        $dotPath = implode('.', $pathParts);
        $expanded = $this->expandNavigationTokenForRawSql("NAVIGATION:{$dotPath} IS NOT NULL", $mainAlias);
        if ($expanded !== null) {
            $trimmed = trim($expanded);
            if (preg_match('/^NOT\s*\((EXISTS\s*\(.+\))\)\s*$/is', $trimmed, $m)) {
                return trim($m[1]);
            }
            if (preg_match('/^(EXISTS\s*\(.+\))\s*$/is', $trimmed, $m)) {
                return trim($m[1]);
            }
        }

        return null;
    }

    /**
     * @param string[] $navParts
     */
    private function navigationPathContainsCollection(array $navParts): bool
    {
        $current = $this->entityType;
        foreach ($navParts as $part) {
            $navInfo = $this->getNavigationInfoForEntity($part, $current);
            if ($navInfo === null) {
                return true;
            }
            if ($navInfo['isCollection']) {
                return true;
            }
            $current = $navInfo['entityType'];
        }

        return false;
    }

    /**
     * @param array<string, string> $refAliasByPath
     */
    private function navigationPathNeedsExistsSemantics(string $dotPath, array $refAliasByPath): bool
    {
        $allParts = explode('.', $dotPath);
        if (count($allParts) < 2) {
            return false;
        }
        array_pop($allParts);

        if ($this->navigationPathContainsCollection($allParts)) {
            return true;
        }

        $navParts = $allParts;
        while (count($navParts) >= 1) {
            if (isset($refAliasByPath[implode('.', $navParts)])) {
                return false;
            }
            array_pop($navParts);
        }

        return true;
    }

    /**
     * Raw WHERE (isEmpty/isNotEmpty) içindeki navigation path'lerini JSON join alias'larına çevirir.
     * Örn. (Employee.Name IS NULL) → ([e1].[Name] IS NULL)
     */
    private function jsonRewriteRawSqlNavigationRefs(string $sql, string $mainAlias, array $refAliasByPath): string
    {
        if ($sql === '' || !str_contains($sql, '.')) {
            return $sql;
        }

        return preg_replace_callback(
            '/\b([A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)+)\b/',
            function (array $m) use ($mainAlias, $refAliasByPath): string {
                $path = $m[1];
                if (substr_count($path, '.') < 1) {
                    return $path;
                }
                $resolved = $this->jsonResolveQualifiedColumnRef($path, $mainAlias, $refAliasByPath);

                return $resolved ?? $path;
            },
            $sql
        ) ?? $sql;
    }

    /**
     * @param array<string, string> $refAliasByPath
     */
    private function jsonResolveQualifiedColumnRef(string $dotPath, string $mainAlias, array $refAliasByPath): ?string
    {
        $allParts = explode('.', $dotPath);
        if (count($allParts) < 2) {
            return null;
        }

        $columnName = array_pop($allParts);
        $navParts = $allParts;

        while (count($navParts) >= 1) {
            $navPath = implode('.', $navParts);
            if (isset($refAliasByPath[$navPath]) && !$this->navigationPathContainsCollection($navParts)) {
                $entityType = $this->jsonResolveEntityTypeForNavPath($navParts);
                if ($entityType !== null) {
                    $ref = new ReflectionClass($entityType);
                    if ($ref->hasProperty($columnName)) {
                        $provider = \Yakupeyisan\CodeIgniter4\EntityFramework\Providers\DatabaseProviderFactory::getProvider($this->connection);
                        $col = $this->getColumnNameFromProperty($ref, $columnName);

                        return $provider->escapeIdentifier($refAliasByPath[$navPath]) . '.' . $provider->escapeIdentifier($col);
                    }
                }
            }
            array_pop($navParts);
        }

        return null;
    }

    /**
     * @param string[] $pathParts
     */
    private function jsonResolveEntityTypeForNavPath(array $pathParts): ?string
    {
        $current = $this->entityType;
        foreach ($pathParts as $part) {
            $navInfo = $this->getNavigationInfoForEntity($part, $current);
            if ($navInfo === null) {
                return null;
            }
            $current = $navInfo['entityType'];
        }

        return $current;
    }
}
