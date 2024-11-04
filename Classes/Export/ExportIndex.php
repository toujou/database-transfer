<?php

declare(strict_types=1);


namespace Toujou\DatabaseTransfer\Export;

use Doctrine\DBAL\Schema\AbstractSchemaManager;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;

class ExportIndex
{
    private AbstractSchemaManager $schemaManager;

    public function __construct(
        private readonly Connection $connection,
        private readonly string     $exportIndexTableName,
        private readonly array      $recordTableNames,
        private readonly array      $mmQueries
    )
    {
        $this->schemaManager = $this->connection->createSchemaManager();
    }

    public function getConnection(): Connection
    {
        return $this->connection;
    }

    public function getSchemaManager(): AbstractSchemaManager
    {
        return $this->schemaManager;
    }

    public function getAllTableNames(): array
    {
        return \array_merge($this->recordTableNames, \array_keys($this->mmQueries), ['sys_refindex']);
    }

    public function getRecordCount(): int
    {
        return $this->connection->count('recuid', $this->exportIndexTableName, []);
    }

    public function getRecords(): \Generator
    {
        foreach ($this->recordTableNames as $recordTableName) {
            $query = $this->connection->createQueryBuilder();
            $expr = $query->expr();
            $query->getRestrictions()->removeAll()->add(new DeletedRestriction());
            $query->selectLiteral($query->quote($recordTableName) . ' AS _tablename')->addSelect('rt.*')->from($recordTableName, 'rt')
                ->join(
                    'rt',
                    $this->exportIndexTableName,
                    'ex',
                    (string)$expr->and(
                        $expr->eq('ex.recuid', 'rt.uid'),
                        $expr->eq('ex.tablename', $query->quote($recordTableName)),
                        $expr->neq('ex.type', $query->quote('static'))
                    )
                );
            $result = $query->executeQuery();
            yield from $result->iterateAssociative();
            $result->free();
        }
    }

    public function getMMRelations(): \Generator
    {
        foreach ($this->mmQueries as $mmTableName => $mmQueryParameters) {
            $query = $this->connection->createQueryBuilder();
            $expr = $query->expr();
            $query->getRestrictions()->removeAll()->add(new DeletedRestriction());
            $query->selectLiteral($query->quote($mmTableName) . ' AS _tablename')->addSelect('mm.*')->from($mmTableName, 'mm');
            $query->join(
                'mm',
                $this->exportIndexTableName,
                'exl',
                (string)$expr->eq('exl.recuid', 'mm.uid_local')
            );
            $query->join(
                'mm',
                $this->exportIndexTableName,
                'exr',
                (string)$expr->eq('exr.recuid', 'mm.uid_foreign')
            );

            $query->where($expr->or(...\array_map(function(array $queryParameters) use ($query, $expr) {
                return $expr->and(
                  $expr->eq('exl.tablename', $query->quote($queryParameters['localTable'])),
                  $expr->eq('exr.tablename', $query->quote($queryParameters['foreignTable'])),
                  ...\array_map(
                      fn(string $columnName, string $matchValue) => $expr->eq('mm.' . $columnName, $query->quote($matchValue)),
                      \array_keys($queryParameters['matchFields']),
                      $queryParameters['matchFields']
                  )
                );
            }, $mmQueryParameters)));

            $result = $query->executeQuery();
            yield from $result->iterateAssociative();
            $result->free();
        }
    }

    public function getReferenceIndex(): \Generator
    {
        $query = $this->connection->createQueryBuilder();
        $expr = $query->expr();
        $query->getRestrictions()->removeAll()->add(new DeletedRestriction());
        $query->selectLiteral($query->quote('sys_refindex') . ' AS _tablename')->addSelect('ri.*')->from('sys_refindex', 'ri');
        $query->join(
            'ri',
            $this->exportIndexTableName,
            'ex',
            (string)$expr->and(
                $expr->eq('ex.recuid', 'ri.recuid'),
                $expr->eq('ex.tablename', 'ri.tablename'),
            )
        );

        $result = $query->executeQuery();
        yield from $result->iterateAssociative();
        $result->free();
    }

    public function getExportIndex(string $targetExportIndexTableName): \Generator
    {
        $query = $this->connection->createQueryBuilder();
        $query->getRestrictions()->removeAll()->add(new DeletedRestriction());
        $query->selectLiteral($query->quote($targetExportIndexTableName) . ' AS _tablename')->addSelect('ex.*')->from($this->exportIndexTableName, 'ex');

        $result = $query->executeQuery();
        yield from $result->iterateAssociative();
        $result->free();
    }

    public function __destruct()
    {
        $schemaManager = $this->connection->createSchemaManager();
        try {
            $schemaManager->dropTable($this->exportIndexTableName);
        } catch (\Exception $th) {
        }
    }

}
