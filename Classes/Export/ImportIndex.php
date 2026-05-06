<?php

declare(strict_types=1);

namespace Toujou\DatabaseTransfer\Export;

use Toujou\DatabaseTransfer\DTO\RecordAction;
use Toujou\DatabaseTransfer\DTO\RecordChangeSet;
use Toujou\DatabaseTransfer\Service\SchemaService;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;

class ImportIndex
{
    /** @var array<string, array<int, int|null>> */
    private array $index = [];

    private string $importIndexTableName;

    private ?string $exportIndexTableName;

    public function __construct(
        private readonly Connection $connection,
        private readonly SchemaService $schemaService,
        string $importSourceName,
    ) {
        $this->importIndexTableName = $this->schemaService->establishIndexTable($this->connection, 'import', $importSourceName);
        $this->exportIndexTableName = $this->schemaService->establishIndexTable($this->connection, 'export', $importSourceName);
    }

    public function getConnection(): Connection
    {
        return $this->connection;
    }

    public function compare(ExportIndex $exportIndex, bool $isDeltaUpdate = false): RecordChangeSet
    {
        $this->schemaService->emptyTable($this->connection, $this->exportIndexTableName);
        $this->copySourceExportTableData($exportIndex);

        $query = $this->connection->createQueryBuilder();
        $query->select(
            'im.tablename AS im_tablename',
            'im.sourceuid AS im_sourceuid',
            'im.type AS im_type',
            'im.targetuid AS im_targetuid',
            'ex.tablename AS ex_tablename',
            'ex.sourceuid AS ex_sourceuid',
            'ex.type AS ex_type',
            'ex.targetuid AS ex_targetuid',
            'ex.updated_at AS updated_at',
        );
        $queries = [
            (clone $query) // recordsToCreate
                ->from($this->importIndexTableName, 'im')
                ->innerJoin('im', $this->exportIndexTableName, 'ex', 'ex.tablename = im.tablename AND ex.sourceuid = im.sourceuid')
                ->where($isDeltaUpdate ? 'ex.updated_at IS NULL OR (ex.updated_at != im.updated_at)' : '1'),

            (clone $query) // recordsToUpdate
                ->from($this->exportIndexTableName, 'ex')
                ->leftJoin('ex', $this->importIndexTableName, 'im', 'ex.tablename = im.tablename AND ex.sourceuid = im.sourceuid')
                ->where('im.sourceuid IS NULL'),
            (clone $query) // recordsToDelete
                ->from($this->importIndexTableName, 'im')
                ->leftJoin('im', $this->exportIndexTableName, 'ex', 'ex.tablename = im.tablename AND ex.sourceuid = im.sourceuid')
                ->where('ex.sourceuid IS NULL'),
        ];
        $sql = \implode(' UNION ', \array_map(fn(QueryBuilder $query) => $query->getSQL(), $queries));
        $result = $this->connection->executeQuery($sql);

        $rows = $result->fetchAllAssociative();

        $items = array_map(fn(array $row) => RecordAction::fromArray(
            [
                'tablename' => $row['im_tablename'] ?: $row['ex_tablename'],
                'sourceuid' => $row['im_sourceuid'] ?: $row['ex_sourceuid'],
                'type' => $row['im_type'] ?: $row['ex_type'],
                'targetuid' => $row['im_targetuid'] ?? 0,
                'updated_at' => $row['updated_at'],
            ],
        ), $rows);

        $comparisonResult = RecordChangeSet::create($items);

        $result->free();

        foreach ($comparisonResult->getRecordsToUpdate() as $existingEntry) {
            $this->index[$existingEntry->tableName][$existingEntry->sourceUid] = $existingEntry->targetUid;
        }

        return $comparisonResult;
    }

    public function translateUid(string $tableName, int $sourceUid): ?int
    {
        return $this->index[$tableName][$sourceUid] ?? null;
    }

    /**
     * @param mixed[] $relation
     *
     * @return mixed[]
     */
    public function translateRelation(array $relation): array
    {
        $original = $translated = $relation;
        $translated['recuid'] = $this->translateUid($translated['tablename'], $translated['recuid']);

        if ($relation['ref_table'] !== '_STRING') {
            $translated['ref_uid'] = $this->translateUid($translated['ref_table'], $translated['ref_uid']);
        }

        if ($translated['recuid'] === null || ($translated['ref_table'] !== '_STRING' && $translated['ref_uid'] === null)) {
            $translated = null;
        } else {
            $translated['hash'] = $this->calculateReferenceIndexRelationHash($translated);
        }

        return ['original' => $original, 'translated' => $translated];
    }

    public function getMMRecords(ExportIndex $exportIndex): \Generator
    {
        foreach ($exportIndex->getMmRelations() as $mmTableName => $mmQueryParameters) {
            $query = $this->connection->createQueryBuilder();
            $expr = $query->expr();
            $query->getRestrictions()->removeAll()->add(new DeletedRestriction());
            $query->select('mm.*')->from($mmTableName, 'mm');
            $query->join(
                'mm',
                $this->importIndexTableName,
                'exl',
                (string)$expr->eq('exl.targetuid', 'mm.uid_local'),
            );
            $query->join(
                'mm',
                $this->importIndexTableName,
                'exr',
                (string)$expr->eq('exr.targetuid', 'mm.uid_foreign'),
            );

            $query->where($expr->or(...\array_map(fn(array $queryParameters) => $expr->and(
                $expr->eq('exl.tablename', $query->quote($queryParameters['localTable'])),
                $expr->eq('exr.tablename', $query->quote($queryParameters['foreignTable'])),
                ...\array_map(
                    fn(string $columnName, string $matchValue) => $expr->eq('mm.' . $columnName, $query->quote($matchValue)),
                    \array_keys($queryParameters['matchFields']),
                    $queryParameters['matchFields'],
                ),
            ), $mmQueryParameters)));

            $result = $query->executeQuery();
            foreach ($result->iterateAssociative() as $row) {
                yield $mmTableName => $row;
            }
            $result->free();
        }
    }

    public function removeFromIndex(string $tableName, int $sourceUid): void
    {
        unset($this->index[$tableName][$sourceUid]);
        $this->connection->delete($this->importIndexTableName, [
            'tablename' => $tableName,
            'sourceuid' => $sourceUid,
        ]);
    }

    public function addToIndex(RecordAction $record, int $targetUid): void
    {
        $this->index[$record->tableName][$record->sourceUid] = $targetUid;

        $this->connection->insert($this->importIndexTableName, [
            ...$record->toArray(),
            'targetuid' => $targetUid,
        ]);
    }

    /**
     * @param mixed[] $relation
     */
    private function calculateReferenceIndexRelationHash(array $relation): string
    {
        // @see \TYPO3\CMS\Core\Database\ReferenceIndex::createEntryDataUsingRecord
        $hashMap = [
            'tablename' => $relation['tablename'] ?? '',
            'recuid' => $relation['recuid'] ?? '',
            'field' => $relation['field'] ?? '',
            'flexpointer' => $relation['flexpointer'] ?? '',
            'softref_key' => $relation['softref_key'] ?? '',
            'softref_id' => $relation['softref_id'] ?? '',
            'sorting' => $relation['sorting'] ?? '',
            'workspace' => $relation['workspace'] ?? '',
            'ref_table' => $relation['ref_table'] ?? '',
            'ref_uid' => $relation['ref_uid'] ?? '',
            'ref_string' => $relation['ref_string'] ?? '',
        ];

        // @see \TYPO3\CMS\Core\Database\ReferenceIndex::updateRefIndexTable:221
        return md5(implode('///', $hashMap) . '///1');
    }

    private function copySourceExportTableData(ExportIndex $exportIndex, int $chunkSize = 500): void
    {
        $buffer = [];

        foreach ($exportIndex->getIndex() as $row) {
            $buffer[] = $row;
            if (count($buffer) === $chunkSize) {
                $this->connection->bulkInsert($this->exportIndexTableName, $buffer, array_keys($buffer[0]));
                $buffer = [];
            }
        }
        if ($buffer) {
            $this->connection->bulkInsert($this->exportIndexTableName, $buffer, array_keys($buffer[0]));
        }
    }

    public function updateUpdatedAtTimestamp(RecordAction $row): void
    {
        $this->connection->update(
            $this->importIndexTableName,
            [
                'updated_at' => $row->updatedAt,
            ],
            [
                'tablename' => $row->tableName,
                'sourceuid' => $row->sourceUid,
            ],
        );
    }

    public function __destruct()
    {
        try {
            $this->schemaService->dropTable($this->connection, $this->exportIndexTableName);
        } catch (\Exception) {
        }
    }
}
