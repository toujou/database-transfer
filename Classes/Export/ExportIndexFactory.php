<?php

declare(strict_types=1);

namespace Toujou\DatabaseTransfer\Export;

use Toujou\DatabaseTransfer\Service\SchemaService;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;

/**
 * This class encapsulates all the TCA specific logic
 */
class ExportIndexFactory
{
    public const TABLENAME_REFERENCE_INDEX = 'sys_refindex';

    public function __construct(
        private readonly ConnectionPool $connectionPool
    ) {
    }

    public function createExportIndex(Selection $selection, string $transferName): ExportIndex
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLENAME_REFERENCE_INDEX);
        $exportIndex = new ExportIndex($connection, new SchemaService(), $transferName);

        $this->addDirectlySelectedRecords($selection, $exportIndex);
        $this->addRelatedRecords($selection, $exportIndex);
        $this->addMMRelations($exportIndex);

        return $exportIndex;
    }

    private function generateRecordQueriesForSelection(array $tableNames, array $selectedPageIds, array $staticTableNames = [], array $excludedRecords = []): \Generator
    {
        foreach ($tableNames as $tableName) {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($tableName);
            $expr = $queryBuilder->expr();

            $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());
            $queryBuilder->select('uid', '*')->from($tableName);

            if (!\in_array($tableName, $staticTableNames, true)) {
                $queryBuilder->select('uid', '*');
                $queryBuilder->where(match (true) {
                    'pages' === $tableName => $expr->in('uid', $selectedPageIds),
                    'sys_file' === $tableName => $expr->in('pid', 0),
                    'sys_file_metadata' === $tableName => $expr->in('pid', 0),
                    default => $expr->in('pid', $selectedPageIds)
                });
            }

            if (!empty($excludedRecords[$tableName])) {
                $queryBuilder->andWhere($expr->notIn('uid', $excludedRecords[$tableName]));
            }

            if (isset($GLOBALS['TCA'][$tableName]['ctrl']['languageField'])) {
                $queryBuilder->andWhere($expr->eq($GLOBALS['TCA'][$tableName]['ctrl']['languageField'], 0));
            }

            yield $tableName => $queryBuilder;
        }
    }

    private function addRelatedRecords(Selection $selection, ExportIndex $exportIndex): void
    {
        $relatedTables = \array_unique(\array_merge($selection->getRelatedTables(), $selection->getStaticTables()));
        $depthLimiter = 0;
        do {
            $recordsFound = 0;
            foreach ($this->generateRecordQueriesForSelection(
                $relatedTables,
                $selection->getSelectedPageIds(),
                $selection->getStaticTables(),
                $selection->getExcludedRecords()
            ) as $tableName => $query) {
                $expr = $query->expr();
                $query->selectLiteral($query->quote($tableName) . ' AS tablename', 'uid AS sourceuid', (\in_array($tableName, $selection->getStaticTables()) ? $query->quote('static') : $query->quote('related')) . ' AS type');

                // TODO replace this with RelationAnalyzer as this doesn't cater for backwards pointing relations like sys_category_record_mm
                $refindexSubquery = $exportIndex->getConnection()->createQueryBuilder();
                $refindexSubquery->getRestrictions()->removeAll()->add(new DeletedRestriction());
                $refindexSubquery->select('ref_uid')->from(self::TABLENAME_REFERENCE_INDEX, 'ri');
                $refindexSubquery->join(
                    'ri',
                    $exportIndex->getIndexTableName(),
                    'exi',
                    (string) $expr->and(
                        $expr->eq('ri.recuid', 'exi.sourceuid'),
                        $expr->eq('ri.tablename', 'exi.tablename'),
                    )
                );
                $refindexSubquery->where($expr->in('ref_table', $query->quote($tableName)));

                $exportSelectionSubquery = $exportIndex->getConnection()->createQueryBuilder();
                $exportSelectionSubquery->getRestrictions()->removeAll();
                $exportSelectionSubquery->select('sourceuid')->from($exportIndex->getIndexTableName(), 'exe')->where(
                    $expr->eq('exe.tablename', $query->quote($tableName)),
                );

                $query->andWhere(
                    $expr->in('uid', $refindexSubquery->getSQL()),
                    $expr->notIn('uid', $exportSelectionSubquery->getSQL()),
                );
                $recordsFound += $exportIndex->addRecordsToIndexFromQuery($query);
            }
            ++$depthLimiter;
        } while ($recordsFound > 0 && $depthLimiter < 100);
    }

    private function addDirectlySelectedRecords(Selection $selection, ExportIndex $exportIndex): void
    {
        foreach ($this->generateRecordQueriesForSelection(
            $selection->getSelectedTables(),
            $selection->getSelectedPageIds(),
            [],
            $selection->getExcludedRecords()
        ) as $tableName => $query) {
            $query->selectLiteral($query->quote($tableName) . ' AS tablename', 'uid AS sourceuid', $query->quote('included') . ' AS type');
            $exportIndex->addRecordsToIndexFromQuery($query);
        }
    }

    private function addMMRelations(ExportIndex $exportIndex): void
    {
        $mmRelationsQuery = $exportIndex->getConnection()->createQueryBuilder();
        $mmRelationsExpr = $mmRelationsQuery->expr();
        $mmRelationsQuery->getRestrictions()->removeAll();
        $mmRelationsQuery->select('ri.tablename', 'ri.field', 'ri.flexpointer', 'ri.ref_table')->from(self::TABLENAME_REFERENCE_INDEX, 'ri')
            ->join('ri', $exportIndex->getIndexTableName(), 'exl', 'exl.tablename = ri.tablename AND exl.sourceuid = ri.recuid')
            ->join('ri', $exportIndex->getIndexTableName(), 'exr', 'exr.tablename = ri.ref_table AND exr.sourceuid = ri.ref_uid')
            ->where($mmRelationsExpr->eq('ri.softref_key', $mmRelationsQuery->quote('')))
            ->groupBy('ri.tablename', 'ri.field', 'ri.flexpointer', 'ri.ref_table');
        foreach ($mmRelationsQuery->executeQuery()->iterateAssociative() as $mmRelation) {
            if (!isset($GLOBALS['TCA'][$mmRelation['tablename']]['columns'][$mmRelation['field']]['config'])) {
                continue;
            }
            $columnConfig = $GLOBALS['TCA'][$mmRelation['tablename']]['columns'][$mmRelation['field']]['config'];
            $this->addMmRelationsForColumn($exportIndex, $columnConfig, $mmRelation);
        }
    }

    private function addMmRelationsForColumn(ExportIndex $exportIndex, array $columnConfig, array $relation): void
    {
        if (!isset($columnConfig['MM'])) {
            return;
        }
        // Technically this shouldn't be necessary, as the reference index only includes all relations from the owning side.
        if (isset($columnConfig['MM_opposite_field'])) {
            return;
        }

        if (isset($columnConfig['MM_oppositeUsage']) && !isset($columnConfig['MM_oppositeUsage'][$relation['ref_table']])) {
            return;
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
            $exportIndex->addMMRelation($mmTableName, $relation['tablename'], $relation['ref_table'], $mmMatchFields);
        }
    }
}
