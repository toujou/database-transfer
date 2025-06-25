<?php

declare(strict_types=1);

namespace Toujou\DatabaseTransfer\Export;

use Toujou\DatabaseTransfer\Service\SchemaService;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;

class ImportIndex
{
    private array $index = [];

    private string $importIndexTableName;

    private ?string $exportIndexTableName = null;

    public function __construct(
        private readonly Connection $connection,
        private readonly SchemaService $schemaService,
        string $transferName
    ) {
        $this->importIndexTableName = $this->schemaService->establishIndexTable($this->connection, 'import', $transferName);
        $this->exportIndexTableName = $this->schemaService->establishIndexTable($this->connection, 'export', $transferName);
    }

    public function getConnection(): Connection
    {
        return $this->connection;
    }

    public function compare(ExportIndex $exportIndex): array
    {
        $this->schemaService->emptyTable($this->connection, $this->exportIndexTableName);
        foreach ($exportIndex->getIndex() as $row) {
            $this->connection->insert($this->exportIndexTableName, $row);
        }

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
        );

        $queries = [
            (clone $query) // existing
                ->from($this->importIndexTableName, 'im')
                ->innerJoin('im', $this->exportIndexTableName, 'ex', 'ex.tablename = im.tablename AND ex.sourceuid = im.targetuid'),
            (clone $query) // unknown
                ->from($this->exportIndexTableName, 'ex')
                ->leftJoin('ex', $this->importIndexTableName, 'im', 'ex.tablename = im.tablename AND ex.sourceuid = im.targetuid')
                ->where('im.sourceuid IS NULL'),
            (clone $query) // missing
                ->from($this->importIndexTableName, 'im')
                ->leftJoin('im', $this->exportIndexTableName, 'ex', 'ex.tablename = im.tablename AND ex.sourceuid = im.targetuid')
                ->where('ex.sourceuid IS NULL'),
        ];
        $sql = \implode(' UNION ', \array_map(fn (QueryBuilder $query) => $query->getSQL(), $queries));
        $result = $this->connection->executeQuery($sql);

        $unknown = [];
        $existing = [];
        $missing = [];

        foreach ($result->iterateAssociative() as $row) {
            if (isset($row['im_sourceuid'], $row['ex_sourceuid'])) {
                $existing[$row['im_tablename'] . '_' . $row['im_sourceuid']] = [
                    'tablename' => $row['im_tablename'],
                    'sourceuid' => (int) $row['im_sourceuid'],
                    'type' => $row['im_type'],
                    'targetuid' => (int) $row['im_targetuid'],
                ];
            } elseif (isset($row['ex_sourceuid'])) {
                $unknown[$row['ex_tablename'] . '_' . $row['ex_sourceuid']] = [
                    'tablename' => $row['ex_tablename'],
                    'sourceuid' => (int) $row['ex_sourceuid'],
                    'type' => $row['ex_type'],
                    'targetuid' => (int) $row['ex_targetuid'],
                ];
            } elseif (isset($row['im_sourceuid'])) {
                $unknown[$row['im_tablename'] . '_' . $row['im_sourceuid']] = [
                    'tablename' => $row['im_tablename'],
                    'sourceuid' => (int) $row['im_sourceuid'],
                    'type' => $row['im_type'],
                    'targetuid' => (int) $row['im_targetuid'],
                ];
            } else {
                throw new \UnexpectedValueException('Export index to import index comparison returned an unexpected row.', 1741349615);
            }
        }
        $result->free();

        return [$unknown, $existing, $missing];
    }

    public function translateUid(string $tableName, int $sourceUid): ?int
    {
        return $this->index[$tableName][$sourceUid]['targetuid'] ?? null;
    }

    public function translateRelation(array $relation): array
    {
        $original = $translated = $relation;
        $translated['recuid'] = $this->translateUid($translated['tablename'], $translated['recuid']);

        if ('_STRING' !== $relation['ref_table']) {
            $translated['ref_uid'] = $this->translateUid($translated['ref_table'], $translated['ref_uid']);
        }

        if (null === $translated['recuid'] || ('_STRING' !== $translated['ref_table'] && null === $translated['ref_uid'])) {
            $translated = null;
        } else {
            $translated['hash'] = $this->calulateReferenceIndexRelationHash($translated);
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
                (string) $expr->eq('exl.targetuid', 'mm.uid_local')
            );
            $query->join(
                'mm',
                $this->importIndexTableName,
                'exr',
                (string) $expr->eq('exr.targetuid', 'mm.uid_foreign')
            );

            $query->where($expr->or(...\array_map(function (array $queryParameters) use ($query, $expr) {
                return $expr->and(
                    $expr->eq('exl.tablename', $query->quote($queryParameters['localTable'])),
                    $expr->eq('exr.tablename', $query->quote($queryParameters['foreignTable'])),
                    ...\array_map(
                        fn (string $columnName, string $matchValue) => $expr->eq('mm.' . $columnName, $query->quote($matchValue)),
                        \array_keys($queryParameters['matchFields']),
                        $queryParameters['matchFields']
                    )
                );
            }, $mmQueryParameters)));

            $result = $query->executeQuery();
            foreach ($result->iterateAssociative() as $row) {
                yield $mmTableName => $row;
            }
            $result->free();
        }
    }

    public function getImportIndexTableName(): string
    {
        return $this->importIndexTableName;
    }

    public function getIndex(): \Generator
    {
        $query = $this->connection->createQueryBuilder();
        $query->getRestrictions()->removeAll()->add(new DeletedRestriction());
        $query->select('ex.*')->from($this->importIndexTableName, 'ex');

        $result = $query->executeQuery();
        foreach ($result->iterateAssociative() as $row) {
            yield $this->importIndexTableName => $row;
        }
        $result->free();
    }

    public function getFromIndex(string $tableName, int $sourceUid): ?array
    {
        return $this->index[$tableName][$sourceUid] ?? null;
    }

    public function removeFromIndex(string $tableName, int $sourceUid): void
    {
        unset($this->index[$tableName][$sourceUid]);
        $this->connection->delete($this->importIndexTableName, [
            'tablename' => $tableName,
            'sourceuid' => $sourceUid,
        ]);
    }

    public function addToIndex(string $tableName, int $sourceUid, string $type, int $targetUid = null): array
    {
        $this->index[$tableName][$sourceUid] = [
            'type' => $type,
            'targetuid' => $targetUid,
        ];
        $record = [
            'tablename' => $tableName,
            'sourceuid' => $sourceUid,
            'type' => $type,
            'targetuid' => $targetUid,
        ];
        $this->connection->insert($this->importIndexTableName, $record);

        return $record;
    }

    public function getRecordsWithIndex(): \Generator
    {
        foreach ($this->index as $recordTableName => $records) {
            $query = $this->connection->createQueryBuilder();
            $expr = $query->expr();
            $query->getRestrictions()->removeAll()->add(new DeletedRestriction());
            $query
                ->select('rt.*')
                ->addSelectLiteral('ex.sourceuid AS _sourceUid')
                ->from($recordTableName, 'rt')
                ->join(
                    'rt',
                    $this->importIndexTableName,
                    'ex',
                    (string) $expr->and(
                        $expr->eq('ex.targetuid', 'rt.uid'),
                        $expr->eq('ex.tablename', $query->quote($recordTableName)),
                        $expr->neq('ex.type', $query->quote('static'))
                    )
                );
            $result = $query->executeQuery();
            foreach ($result->iterateAssociative() as $row) {
                $sourceUid = (int) $row['_sourceUid'];
                unset($row['_sourceUid']);
                $index = $this->getFromIndex($recordTableName, $sourceUid);
                if (null === $index) {
                    throw new \UnexpectedValueException('Record ' . $row['uid'] . ' in table ' . $recordTableName . ' does not exist in import index', 1741853372);
                }
                $index['sourceuid'] = $sourceUid;
                yield $recordTableName => ['record' => $row, 'index' => $index];
            }
            $result->free();
        }
    }

    private function calulateReferenceIndexRelationHash(array $relation): string
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

    public function __destruct()
    {
        try {
            $this->schemaService->dropTable($this->connection, $this->exportIndexTableName);
        } catch (\Exception $th) {
        }
    }
}
