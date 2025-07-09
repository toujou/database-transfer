<?php

declare(strict_types=1);

namespace Toujou\DatabaseTransfer\Export;

use Toujou\DatabaseTransfer\Service\SchemaService;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;

class ExportIndex
{
    private string $indexTableName;

    private array $mmRelations = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly SchemaService $schemaService,
        string $transferName,
    ) {
        $this->indexTableName = $this->schemaService->establishIndexTable($this->connection, 'export', $transferName);
        $this->schemaService->emptyTable($this->connection, $this->indexTableName);
    }

    public function getConnection(): Connection
    {
        return $this->connection;
    }

    public function getIndexTableName(): string
    {
        return $this->indexTableName;
    }

    public function addRecordsToIndexFromQuery(QueryBuilder $query): int
    {
        if ($query->getConnection() !== $this->connection) {
            throw new \InvalidArgumentException('The database connection of the given query doesn\'t match the expected database connection', 1750924837);
        }

        return (int)$this->connection->executeStatement(
            \sprintf(
                'INSERT INTO %s (tablename,sourceuid,type) %s',
                $query->quoteIdentifier($this->indexTableName),
                $query->getSQL(),
            ),
        );
    }

    public function updateIndex(array $indexRows): void
    {
        $this->connection->transactional(function () use ($indexRows) {
            foreach ($indexRows as $row) {
                $this->connection->update(
                    $this->indexTableName,
                    ['targetuid' => (int)$row['targetuid']],
                    ['tablename' => $row['tablename'], 'sourceuid' => $row['sourceuid']],
                );
            }
        });
    }

    public function addMMRelation(string $mmTableName, string $localTableName, string $foreignTableName, array $mmMatchFields): void
    {
        \asort($mmMatchFields); // Normalize
        $mmQuery = [
            'localTable' => $localTableName,
            'matchFields' => $mmMatchFields,
            'foreignTable' => $foreignTableName,
        ];
        $this->mmRelations[$mmTableName][crc32(serialize($mmQuery))] = $mmQuery;
    }

    public function getMmRelations(): array
    {
        return $this->mmRelations;
    }

    public function getRecordTableNames(): array
    {
        $tableNamesQuery = $this->connection->createQueryBuilder();
        $tableNamesQuery->getRestrictions()->removeAll();
        $tableNamesQuery->select('tablename')->from($this->indexTableName)->groupBy('tablename');

        return \array_unique(\array_column($tableNamesQuery->executeQuery()->fetchAllAssociative(), 'tablename'));
    }

    public function getAllTableNames(): array
    {
        return \array_merge(
            ['sys_refindex'],
            \array_keys($this->mmRelations),
            $this->getRecordTableNames(),
        );
    }

    public function getRecordCount(): int
    {
        return $this->connection->count('sourceuid', $this->indexTableName, []);
    }

    public function getRecords(): \Generator
    {
        foreach ($this->getRecordTableNames() as $recordTableName) {
            $query = $this->connection->createQueryBuilder();
            $expr = $query->expr();
            $query->getRestrictions()->removeAll()->add(new DeletedRestriction());
            $query->select('rt.*')->from($recordTableName, 'rt')
                ->join(
                    'rt',
                    $this->indexTableName,
                    'ex',
                    (string)$expr->and(
                        $expr->eq('ex.sourceuid', 'rt.uid'),
                        $expr->eq('ex.tablename', $query->quote($recordTableName)),
                        $expr->neq('ex.type', $query->quote('static')),
                    ),
                );
            $result = $query->executeQuery();
            foreach ($result->iterateAssociative() as $row) {
                yield $recordTableName => $row;
            }
            $result->free();
        }
    }

    public function getMMRecords(): \Generator
    {
        foreach ($this->mmRelations as $mmTableName => $mmQueryParameters) {
            $query = $this->connection->createQueryBuilder();
            $expr = $query->expr();
            $query->getRestrictions()->removeAll()->add(new DeletedRestriction());
            $query->select('mm.*')->from($mmTableName, 'mm');
            $query->join(
                'mm',
                $this->indexTableName,
                'exl',
                (string)$expr->eq('exl.sourceuid', 'mm.uid_local'),
            );
            $query->join(
                'mm',
                $this->indexTableName,
                'exr',
                (string)$expr->eq('exr.sourceuid', 'mm.uid_foreign'),
            );

            $queries = \array_map(function (array $queryParameters) use ($query, $expr) {
                return (clone $query)
                    ->addSelectLiteral(
                        $query->quote($queryParameters['localTable']) . ' AS _localTable',
                        $query->quote($queryParameters['foreignTable']) . ' AS _foreignTable',
                    )
                    ->where(
                        $expr->and(
                            $expr->gt('exl.targetuid', 0),
                            $expr->gt('exr.targetuid', 0),
                            $expr->eq('exl.tablename', $query->quote($queryParameters['localTable'])),
                            $expr->eq('exr.tablename', $query->quote($queryParameters['foreignTable'])),
                            ...\array_map(
                                fn(string $columnName, string $matchValue) => $expr->eq('mm.' . $columnName, $query->quote($matchValue)),
                                \array_keys($queryParameters['matchFields']),
                                $queryParameters['matchFields'],
                            ),
                        ),
                    );
            }, $mmQueryParameters);

            $sql = \implode(' UNION ', \array_map(fn(QueryBuilder $query) => $query->getSQL(), $queries));

            $result = $this->connection->executeQuery($sql);
            foreach ($result->iterateAssociative() as $row) {
                yield $mmTableName => $row;
            }
            $result->free();
        }
    }

    public function getIndex(): \Generator
    {
        $query = $this->connection->createQueryBuilder();
        $query->getRestrictions()->removeAll()->add(new DeletedRestriction());
        $query->select('ex.*')->from($this->indexTableName, 'ex');

        $result = $query->executeQuery();
        foreach ($result->iterateAssociative() as $row) {
            yield $this->indexTableName => $row;
        }
        $result->free();
    }

    public function __destruct()
    {
        try {
            $this->schemaService->dropTable($this->connection, $this->indexTableName);
        } catch (\Exception $th) {
        }
    }
}
