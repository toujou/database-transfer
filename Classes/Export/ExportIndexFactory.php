<?php

declare(strict_types=1);

namespace Toujou\DatabaseTransfer\Export;

use Toujou\DatabaseTransfer\Service\SchemaService;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Schema\Capability\LanguageAwareSchemaCapability;
use TYPO3\CMS\Core\Schema\Capability\TcaSchemaCapability;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;

/**
 * This class encapsulates all the TCA specific logic
 */
class ExportIndexFactory
{
    public const TABLENAME_REFERENCE_INDEX = 'sys_refindex';

    public function __construct(
        private readonly SchemaService $schemaService,
        private readonly TcaSchemaFactory $tcaSchemaFactory,
    ) {}

    public function createExportIndex(Connection $connection, string $importSourceName, Selection $selection): ExportIndex
    {
        $exportIndex = new ExportIndex($connection, $this->schemaService, $importSourceName);

        $this->addDirectlySelectedRecords($selection, $exportIndex);

        // Related records and translation overlays are collected in one fixpoint: an overlay that only
        // the translation pass can reach may own related records, and those related records may own overlays.
        $depthLimiter = 0;
        do {
            $this->addRelatedRecords($selection, $exportIndex);
            $translationRecordsFound = $this->addTranslationRecordsWithDependencies($selection, $exportIndex);
            ++$depthLimiter;
        } while ($translationRecordsFound > 0 && $depthLimiter < 100);

        $this->addMMRelations($exportIndex);

        return $exportIndex;
    }

    /**
     * @param string[] $tableNames
     * @param int[] $selectedPageIds
     * @param string[] $staticTableNames
     * @param array<string, int[]> $excludedRecords
     * @param int[] $includedSourceLanguageIds
     */
    private function generateRecordQueriesForSelection(ExportIndex $exportIndex, array $tableNames, array $selectedPageIds, array $staticTableNames, array $excludedRecords, array $includedSourceLanguageIds): \Generator
    {
        foreach ($tableNames as $tableName) {
            $schema = $this->tcaSchemaFactory->get($tableName);
            $queryBuilder = $exportIndex->getConnection()->createQueryBuilder();
            $expr = $queryBuilder->expr();

            $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());
            $queryBuilder->select('uid', '*')->from($tableName);

            if (!\in_array($tableName, $staticTableNames, true)) {
                $queryBuilder->select('uid', '*');
                $queryBuilder->where(match (true) {
                    $tableName === 'pages' => $expr->in('uid', $selectedPageIds),
                    $tableName === 'sys_file' => $expr->in('pid', [0]),
                    $tableName === 'sys_file_metadata' => $expr->in('pid', [0]),
                    default => $expr->in('pid', $selectedPageIds),
                });
            }

            if (!empty($excludedRecords[$tableName])) {
                $queryBuilder->andWhere($expr->notIn('uid', $excludedRecords[$tableName]));
            }
            if ($schema->isLanguageAware()) {
                /** @var LanguageAwareSchemaCapability $languageCapability */
                $languageCapability = $schema->getCapability(TcaSchemaCapability::Language);
                $languageField = $languageCapability->getLanguageField()->getName();
                $queryBuilder->andWhere($expr->in($languageField, $includedSourceLanguageIds));
            }

            yield $tableName => $queryBuilder;
        }
    }

    private function addRelatedRecords(Selection $selection, ExportIndex $exportIndex): void
    {
        $relatedTables = \array_unique(\array_merge($selection->getRelatedTables(), $selection->getStaticTables()));
        // TODO recurse until no records are inserted anymore. Throw implementation error exception when depth limiter is hit.
        $depthLimiter = 0;
        do {
            $recordsFound = 0;
            foreach ($this->generateRecordQueriesForSelection(
                $exportIndex,
                $relatedTables,
                $selection->getSelectedPageIds(),
                $selection->getStaticTables(),
                $selection->getExcludedRecords(),
                $selection->getIncludedSourceLanguageIds(),
            ) as $tableName => $query) {
                $expr = $query->expr();

                $query->selectLiteral(...$this->buildIndexSelectLiterals(
                    $query,
                    $tableName,
                    \in_array($tableName, $selection->getStaticTables(), true) ? 'static' : 'related',
                ));

                // TODO replace this with RelationAnalyzer as
                // this doesn't cater for backwards pointing relations like sys_category_record_mm
                $refindexSubquery = $exportIndex->getConnection()->createQueryBuilder();
                $refindexSubquery->getRestrictions()->removeAll()->add(new DeletedRestriction());
                $refindexSubquery->select('ref_uid')->from(self::TABLENAME_REFERENCE_INDEX, 'ri');
                $refindexSubquery->join(
                    'ri',
                    $exportIndex->getIndexTableName(),
                    'exi',
                    (string)$expr->and(
                        $expr->eq('ri.recuid', 'exi.sourceuid'),
                        $expr->eq('ri.tablename', 'exi.tablename'),
                    ),
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

    /**
     * Adds overlays of already indexed records until no further overlay is found.
     *
     * Overlays can own other overlays (translation chains), so tables that received records are scanned again.
     *
     * @return int number of added overlay records
     */
    private function addTranslationRecordsWithDependencies(Selection $selection, ExportIndex $exportIndex): int
    {
        $recordsFound = 0;
        $tableNames = null;
        $depthLimiter = 0;
        do {
            $addedRecords = $this->addTranslationRecords($selection, $exportIndex, $tableNames);
            $tableNames = \array_keys($addedRecords);
            $recordsFound += \array_sum($addedRecords);
            ++$depthLimiter;
        } while ($tableNames !== [] && $depthLimiter < 100);

        return $recordsFound;
    }

    /**
     * Adds overlays of records that are already present in the export index.
     *
     * Translated records are not guaranteed to be reachable via sys_refindex, so they are collected
     * by following the origin pointer field of language-aware tables. Only runs when a language map is set.
     *
     * @param string[]|null $tableNames tables to scan, null scans every language-aware table in the export index
     *
     * @return array<string, int> number of added records per table
     */
    private function addTranslationRecords(Selection $selection, ExportIndex $exportIndex, ?array $tableNames = null): array
    {
        $sourceLanguageIds = \array_keys($selection->getLanguageMap());
        if ($sourceLanguageIds === []) {
            return [];
        }

        $excludedRecords = $selection->getExcludedRecords();
        $addedRecords = [];

        foreach ($tableNames ?? $exportIndex->getRecordTableNames() as $tableName) {
            $schema = $this->tcaSchemaFactory->get($tableName);
            if (!$schema->isLanguageAware()) {
                continue;
            }

            /** @var LanguageAwareSchemaCapability $languageCapability */
            $languageCapability = $schema->getCapability(TcaSchemaCapability::Language);
            $languageField = $languageCapability->getLanguageField()->getName();
            $originPointerField = $languageCapability->getTranslationOriginPointerField()->getName();

            $query = $exportIndex->getConnection()->createQueryBuilder();
            $expr = $query->expr();
            $query->getRestrictions()->removeAll()->add(new DeletedRestriction());
            $query->selectLiteral(...$this->buildIndexSelectLiterals(
                $query,
                $tableName,
                \in_array($tableName, $selection->getStaticTables(), true) ? 'static' : 'related',
                't',
            ));

            $query->from($tableName, 't');
            $query->join(
                't',
                $exportIndex->getIndexTableName(),
                'ex',
                (string)$expr->and(
                    $expr->eq('ex.tablename', $query->quote($tableName)),
                    $expr->eq('ex.sourceuid', 't.' . $originPointerField),
                ),
            );
            $query->where($expr->in('t.' . $languageField, $sourceLanguageIds));

            if (!empty($excludedRecords[$tableName])) {
                $query->andWhere($expr->notIn('t.uid', $excludedRecords[$tableName]));
            }

            $exportSelectionSubquery = $exportIndex->getConnection()->createQueryBuilder();
            $exportSelectionSubquery->getRestrictions()->removeAll();
            $exportSelectionSubquery->select('sourceuid')->from($exportIndex->getIndexTableName(), 'exe')->where(
                $expr->eq('exe.tablename', $query->quote($tableName)),
            );

            $query->andWhere($expr->notIn('t.uid', $exportSelectionSubquery->getSQL()));

            $recordsAdded = $exportIndex->addRecordsToIndexFromQuery($query);
            if ($recordsAdded > 0) {
                $addedRecords[$tableName] = $recordsAdded;
            }
        }

        return $addedRecords;
    }

    /**
     * @return string[]
     */
    private function buildIndexSelectLiterals(QueryBuilder $query, string $tableName, string $type, string $tableAlias = ''): array
    {
        $schema = $this->tcaSchemaFactory->get($tableName);
        $columnPrefix = $tableAlias === '' ? '' : $tableAlias . '.';

        $selectLiterals = [
            $query->quote($tableName) . ' AS tablename',
            $columnPrefix . 'uid AS sourceuid',
            $query->quote($type) . ' AS type',
        ];

        if ($schema->hasCapability(TcaSchemaCapability::UpdatedAt)) {
            $selectLiterals[] = $columnPrefix . $schema->getCapability(TcaSchemaCapability::UpdatedAt)->getFieldName() . ' AS updated_at';
        } else {
            $selectLiterals[] = 'NULL AS updated_at';
        }

        if ($tableName === 'sys_file') {
            $selectLiterals[] = $columnPrefix . 'identifier';
        } else {
            $selectLiterals[] = 'NULL AS identifier';
        }

        return $selectLiterals;
    }

    private function addDirectlySelectedRecords(Selection $selection, ExportIndex $exportIndex): void
    {
        foreach ($this->generateRecordQueriesForSelection(
            $exportIndex,
            $selection->getSelectedTables(),
            $selection->getSelectedPageIds(),
            [],
            $selection->getExcludedRecords(),
            $selection->getIncludedSourceLanguageIds(),
        ) as $tableName => $query) {
            $query->selectLiteral(...$this->buildIndexSelectLiterals($query, $tableName, 'included'));

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

    /**
     * @param mixed[] $columnConfig
     * @param mixed[] $relation
     */
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
                    \array_flip($columnConfig['MM_oppositeUsage'][$relation['ref_table']]),
                ),
                'config',
            );
        foreach ($relationConfigs as $relationConfig) {
            $mmTableName = $relationConfig['MM'];
            $mmMatchFields = $relationConfig['MM_match_fields'] ?? [];
            $exportIndex->addMMRelation($mmTableName, $relation['tablename'], $relation['ref_table'], $mmMatchFields);
        }
    }
}
