<?php

declare(strict_types=1);


namespace Toujou\DatabaseTransfer\Service;

use Toujou\DatabaseTransfer\Database\DatabaseContext;
use Toujou\DatabaseTransfer\Export\ExportIndexFactory;
use Toujou\DatabaseTransfer\Export\Selection;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\ReferenceIndex;

class Exporter
{


    public function __construct(
        private readonly ConnectionPool     $connectionPool,
        private readonly ExportIndexFactory $queryGenerator,
        private readonly SchemaService      $schemaService,
        private readonly ReferenceIndex     $referenceIndex
    )
    {
    }

    public function export(Selection $selection, string $targetConnectionName): void
    {
        $targetDatabase = $this->connectionPool->getConnectionByName($targetConnectionName);

        $exportIndex = $this->queryGenerator->createExportIndex($selection, $targetConnectionName);
        $allTableNames = $exportIndex->getAllTableNames();
        $allTableNames[] = $exportIndexTableName = $this->queryGenerator->establishExportIndexTable($targetDatabase, 'export');
        $this->schemaService->establishSchemaOfTables($targetDatabase, $allTableNames);
        $tableColumnNames = $this->schemaService->getTableColumnTypes($targetDatabase, $allTableNames);

        // This transaction leads to roughly 100x performance improvement on sqlite
        $targetDatabase->transactional(function(Connection $targetDatabase) use($exportIndex, $tableColumnNames, $exportIndexTableName) {
            foreach ($exportIndex->getExportIndex($exportIndexTableName) as $row) {
                $this->insertRow($targetDatabase, $row, $tableColumnNames);
            }
            foreach ($exportIndex->getReferenceIndex() as $row) {
                $this->insertRow($targetDatabase, $row, $tableColumnNames);
            }
        });

        $targetDatabase->transactional(function(Connection $targetDatabase) use($exportIndex, $tableColumnNames, $exportIndexTableName) {
            foreach ($exportIndex->getRecords() as $row) {
                $row = $this->filterLostRelations($targetDatabase, $row, $exportIndexTableName);
                $this->insertRow($targetDatabase, $row, $tableColumnNames);
            }
            foreach ($exportIndex->getMMRelations() as $row) {
                $this->insertRow($targetDatabase, $row, $tableColumnNames);
            }
        });

        $exportContext = new DatabaseContext($targetDatabase, $targetConnectionName, $allTableNames);
        $exportContext->runWithinConnection(function(Connection $connection) use ($exportIndexTableName) {
            $this->referenceIndex->updateIndex(false);
        });

        var_dump($targetDatabase->count('hash', 'sys_refindex', []));

        var_dump(memory_get_peak_usage(true) / 1024 / 1024);
        die();
    }

    private function filterLostRelations(Connection $connection, array $row, string $exportIndexTableName): array
    {
        $tableName = $row['_tablename'];
        $uid = $row['uid'];

        $query = $connection->createQueryBuilder();
        $expr = $query->expr();
        $query->select('ri.*')->from('sys_refindex', 'ri');
        $query->leftJoin(
            'ri',
            $exportIndexTableName,
            'ex',
            (string) $expr->and(
                $expr->eq('ri.ref_table', 'ex.tablename'),
                $expr->eq('ri.ref_uid', 'ex.recuid')
            )
        );
        $query->where($expr->and(
            $expr->eq('ri.tablename', $query->quote($tableName)),
            $expr->eq('ri.recuid', $query->quote($uid)),
            $expr->neq('ri.ref_table', $query->quote('_STRING')),
            $expr->isNull('ex.recuid'),
        ));
        $lostRelations = $query->executeQuery()->fetchAllAssociative();

        // TODO
        // ignore MM
        // ! pay attention to foreign_field
        // reimplement $this->referenceIndex->setReferenceValue

        return $row;
    }

    private function insertRow(Connection $targetDatabase, array $row, array $columnTypes): void
    {
        $tableName = \array_shift($row);
        $types = $columnTypes[$tableName];
        $row = \array_intersect_key($row, $types);
        $targetDatabase->insert($tableName, $row, $types);
    }

}
