<?php

declare(strict_types=1);


namespace Toujou\DatabaseTransfer\Export;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\UniqueConstraint;
use Doctrine\DBAL\Types\IntegerType;
use Doctrine\DBAL\Types\StringType;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;

class ExportIndexFactory
{

    public const TABLENAME_REFERENCE_INDEX = 'sys_refindex';

    public function __construct(
        private readonly ConnectionPool $connectionPool
    )
    {
    }

    public function createExportIndex(Selection $selection, string $targetConnectionName): ExportIndex
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLENAME_REFERENCE_INDEX);
        $exportIndexTableName = $this->establishExportIndexTable($connection, $targetConnectionName);

        $this->addDirectlySelectedRecords($selection, $connection, $exportIndexTableName);
        $this->addRelatedRecords($selection, $connection, $exportIndexTableName);

        $tableNamesQuery = $connection->createQueryBuilder();
        $tableNamesQuery->getRestrictions()->removeAll();
        $tableNamesQuery->select('tablename')->from($exportIndexTableName)->groupBy('tablename');
        $tableNames = \array_column($tableNamesQuery->executeQuery()->fetchAllAssociative(), 'tablename');

        $relationsQuery = $connection->createQueryBuilder();
        $relationsExpr = $relationsQuery->expr();
        $relationsQuery->getRestrictions()->removeAll();
        $relationsQuery->select('ri.tablename', 'ri.field', 'ri.flexpointer', 'ri.ref_table')->from(self::TABLENAME_REFERENCE_INDEX, 'ri')
            ->join('ri', $exportIndexTableName, 'exl', 'exl.tablename = ri.tablename AND exl.recuid = ri.recuid')
            ->join('ri', $exportIndexTableName, 'exr', 'exr.tablename = ri.ref_table AND exr.recuid = ri.ref_uid')
            ->where($relationsExpr->eq('ri.softref_key', $relationsQuery->quote('')))
            ->groupBy('ri.tablename', 'ri.field', 'ri.flexpointer', 'ri.ref_table');
        $relations = $relationsQuery->executeQuery()->fetchAllAssociative();

        $mmQueries = [];
        foreach($relations as $relation) {
            // TODO handle flexpoint here, eg BlogCard.xml
            // Allowedtable with opposite usage?
            $columnConfig = $GLOBALS['TCA'][$relation['tablename']]['columns'][$relation['field']]['config'] ?? null;

            if (!isset($columnConfig['MM'])) {
                continue;
            }
            // Technically this shouldn't be necessary, as the reference index only includes all relations from the owning side.
            if (isset($columnConfig['MM_opposite_field'])) {
                continue;
            }

            if (isset($columnConfig['MM_oppositeUsage']) && !isset($columnConfig['MM_oppositeUsage'][$relation['ref_table']])) {
                continue;
            }

            $relationConfigs = !isset($columnConfig['MM_oppositeUsage'])
                ? [$columnConfig]
                : \array_column(
                    \array_intersect_key(
                        $GLOBALS['TCA'][$relation['ref_table']]['columns'],
                        \array_flip($columnConfig['MM_oppositeUsage'][$relation['ref_table']])
                    ),
                    'config'
                );
            foreach ($relationConfigs as $relationConfig) {
                $mmTableName = $relationConfig['MM'];
                $mmMatchFields = $relationConfig['MM_match_fields'] ?? [];
                \asort($mmMatchFields); // Normalize
                $mmQuery = [
                    'localTable' => $relation['tablename'],
                    'matchFields' => $mmMatchFields,
                    'foreignTable' => $relation['ref_table'],
                ];
                $mmQueries[$mmTableName][md5(serialize($mmQuery))] = $mmQuery;
            }
        }

        return new ExportIndex(
            $connection,
            $exportIndexTableName,
            \array_unique($tableNames),
            $mmQueries
        );
    }

    private function generateRecordQueriesForSelection(array $tableNames, array $selectedPageIds, array $staticTableNames = []): \Generator
    {
        foreach ($tableNames as $tableName) {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($tableName);
            $expr = $queryBuilder->expr();

            $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction);
            $queryBuilder->select('uid','*')->from($tableName);


            if (!\in_array($tableName, $staticTableNames, true)) {
                $queryBuilder->select('uid','*');
                $queryBuilder->where(match (true) {
                    'pages' === $tableName => $expr->in('uid', $selectedPageIds),
                    'sys_file' === $tableName => $expr->in('pid', 0),
                    'sys_file_metadata' === $tableName => $expr->in('pid', 0),
                    default => $expr->in('pid', $selectedPageIds)
                });
            }

            if (isset($GLOBALS['TCA'][$tableName]['ctrl']['languageField'])) {
                $queryBuilder->andWhere($expr->eq($GLOBALS['TCA'][$tableName]['ctrl']['languageField'], 0));
            }

            yield $tableName => $queryBuilder;
        }
    }

    public function establishExportIndexTable(Connection $connection, string $targetConnectionName): string
    {
        $exportIndexTableName = "{$targetConnectionName}_index";
        $exportSelectionTable = new Table(
            $exportIndexTableName,
            [
                new Column('tablename', new StringType()),
                new Column('recuid', new IntegerType()),
                new Column('type', new StringType())
            ],
            [],
            [new UniqueConstraint('tablename_recuid', ['tablename', 'recuid'])],
            []
        );
        $schemaManager = $connection->createSchemaManager();
        if ($schemaManager->tablesExist($exportIndexTableName)) {
            $schemaManager->dropTable($exportIndexTableName);
        }
        $schemaManager->createTable($exportSelectionTable);
        return $exportIndexTableName;
    }

    private function addRelatedRecords(Selection $selection, Connection $connection, string $exportIndexTableName): void
    {
        $relatedTables = \array_unique(\array_merge($selection->getRelatedTables(), $selection->getStaticTables()));
        $depthLimiter = 0;
        do {
            $recordsFound = 0;
            foreach ($this->generateRecordQueriesForSelection($relatedTables, $selection->getSelectedPageIds(), $selection->getStaticTables()) as $tableName => $query) {
                $expr = $query->expr();
                $query->selectLiteral($query->quote($tableName) . ' AS tablename', 'uid AS recuid', (\in_array($tableName, $selection->getStaticTables()) ? $query->quote('static') : $query->quote('related')) . ' AS type');

                $refindexSubquery = $connection->createQueryBuilder();
                $refindexSubquery->getRestrictions()->removeAll()->add(new DeletedRestriction);
                $refindexSubquery->select('ref_uid')->from(self::TABLENAME_REFERENCE_INDEX, 'ri');
                $refindexSubquery->join(
                    'ri',
                    $exportIndexTableName,
                    'exi',
                    (string) $expr->and(
                        $expr->eq('ri.recuid', 'exi.recuid'),
                        $expr->eq('ri.tablename', 'exi.tablename'),
                    )
                );
                $refindexSubquery->where($expr->in('ref_table', $query->quote($tableName)));

                $exportSelectionSubquery = $connection->createQueryBuilder();
                $exportSelectionSubquery->getRestrictions()->removeAll();
                $exportSelectionSubquery->select('recuid')->from($exportIndexTableName, 'exe')->where(
                    $expr->eq('exe.tablename', $query->quote($tableName)),
                );

                $query->andWhere(
                    $expr->in('uid', $refindexSubquery->getSQL()),
                    $expr->notIn('uid', $exportSelectionSubquery->getSQL()),
                );
                $recordsFound += $connection->executeStatement(\sprintf('INSERT INTO %s %s', $query->quoteIdentifier($exportIndexTableName), $query->getSQL()));
            }
            $depthLimiter++;
        } while ($recordsFound > 0 && $depthLimiter < 100);
    }
    private function addDirectlySelectedRecords(Selection $selection, Connection $connection, string $exportIndexTableName): void
    {
        foreach ($this->generateRecordQueriesForSelection($selection->getSelectedTables(), $selection->getSelectedPageIds()) as $tableName => $query) {
            $query->selectLiteral($query->quote($tableName) . ' AS tablename', 'uid AS recuid', $query->quote('included') . ' AS type');
            $connection->executeStatement(\sprintf('INSERT INTO %s %s', $query->quoteIdentifier($exportIndexTableName), $query->getSQL()));
        }
    }

}
