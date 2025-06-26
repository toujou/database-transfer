<?php

declare(strict_types=1);

namespace Toujou\DatabaseTransfer\Database;

use Doctrine\DBAL\ParameterType;
use Toujou\DatabaseTransfer\Export\ExportIndex;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

class RelationAnalyzer
{
    private array $relationsQueryCache = [];

    private array $foreignFields = [];

    public function __construct(
        private readonly ExportIndex $exportIndex
    ) {
        $this->foreignFields = $this->buildForeignFields();
    }

    private function buildForeignFields(): array
    {
        $foreignFields = [];
        $foreignFieldRelationsQuery = $this->exportIndex->getConnection()->createQueryBuilder();
        $foreignFieldRelationsExpr = $foreignFieldRelationsQuery->expr();
        $foreignFieldRelationsQuery->getRestrictions()->removeAll();
        $foreignFieldRelationsQuery->select('ri.tablename', 'ri.field', 'ri.flexpointer', 'ri.ref_table')->from('sys_refindex', 'ri')
            ->join('ri', $this->exportIndex->getIndexTableName(), 'exr', 'exr.tablename = ri.ref_table AND exr.sourceuid = ri.ref_uid')
            ->where($foreignFieldRelationsExpr->and(
                $foreignFieldRelationsExpr->eq('ri.softref_key', $foreignFieldRelationsQuery->quote(''))
            ))
            ->groupBy('ri.tablename', 'ri.field', 'ri.flexpointer', 'ri.ref_table');
        foreach ($foreignFieldRelationsQuery->executeQuery()->iterateAssociative() as $foreignFieldRelation) {
            if (!isset($GLOBALS['TCA'][$foreignFieldRelation['tablename']]['columns'][$foreignFieldRelation['field']]['config'])) {
                continue;
            }
            $columnConfig = $GLOBALS['TCA'][$foreignFieldRelation['tablename']]['columns'][$foreignFieldRelation['field']]['config'];
            // TODO resolve flexpointers here
            $foreignFields = $this->addForeignFieldForColumn($columnConfig, $foreignFieldRelation, $foreignFields);
        }

        return $foreignFields;
    }

    private function addForeignFieldForColumn(array $columnConfig, array $relation, array $foreignFields): array
    {
        if (
            !isset($columnConfig['type'], $columnConfig['foreign_table'], $columnConfig['foreign_field']) ||
            !\in_array($columnConfig['type'], ['select', 'inline', 'category', 'file'])
        ) {
            return $foreignFields;
        }

        if (!isset($foreignFields[$columnConfig['foreign_table']][$columnConfig['foreign_field']])) {
            $foreignFields[$columnConfig['foreign_table']][$columnConfig['foreign_field']] = [];
        }
        $foreignFields[$columnConfig['foreign_table']][$columnConfig['foreign_field']][] = [
            'tableName' => $relation['tablename'],
            'columnName' => $relation['field'],
        ];

        return $foreignFields;
    }

    public function getRelationsForRecord(string $tableName, int $uid): array
    {
        if (isset($this->relationsQueryCache[$tableName])) {
            $execQuery = $this->relationsQueryCache[$tableName];
        } else {
            $queries = [$this->buildForwardPointingRelationsQuery()];

            $hasForeignFieldConstraints = count($this->foreignFields[$tableName] ?? []) > 0;
            if ($hasForeignFieldConstraints) {
                $queries[] = $this->buildBackwardsPointingRelationsQuery($tableName);
            }

            $sql = \implode(' UNION ', \array_map(fn (QueryBuilder $query) => $query->getSQL(), $queries));
            $preparedQuery = $this->exportIndex->getConnection()->prepare($sql);

            $execQuery = $this->relationsQueryCache[$tableName] = function (string $tableName, int $uid) use ($preparedQuery, $hasForeignFieldConstraints) {
                $preparedQuery->bindValue(1, $tableName);
                $preparedQuery->bindValue(2, $uid, ParameterType::INTEGER);
                if ($hasForeignFieldConstraints) {
                    $preparedQuery->bindValue(3, $tableName);
                    $preparedQuery->bindValue(4, $uid, ParameterType::INTEGER);
                }

                return $preparedQuery->executeQuery();
            };
        }

        $result = $execQuery($tableName, $uid);
        $relations = $result->fetchAllAssociative();
        $result->free();

        return $relations;
    }

    private function buildForwardPointingRelationsQuery(): QueryBuilder
    {
        $query = $this->exportIndex->getConnection()->createQueryBuilder();
        $expr = $query->expr();

        $query
            ->select('ri.*')
            ->from('sys_refindex', 'ri')
            ->leftJoin(
                'ri',
                $this->exportIndex->getIndexTableName(),
                'exr',
                (string) $expr->and(
                    $expr->eq('ri.ref_table', 'exr.tablename'),
                    $expr->eq('ri.ref_uid', 'exr.sourceuid')
                )
            );

        $query->where($expr->and(
            $expr->and(
                $expr->eq('ri.tablename', '?'),
                $expr->eq('ri.recuid', '?'),
            )
        ));

        return $query;
    }

    private function buildBackwardsPointingRelationsQuery(string $tableName): QueryBuilder
    {
        $query = $this->exportIndex->getConnection()->createQueryBuilder();
        $expr = $query->expr();

        $foreignFieldsConstraints = [];
        foreach ($this->foreignFields[$tableName] ?? [] as $foreignFields) {
            foreach ($foreignFields as $foreignField) {
                $foreignFieldsConstraints[] = $expr->and(
                    $expr->eq('ri.tablename', $query->quote($foreignField['tableName'])),
                    $expr->eq('ri.field', $query->quote($foreignField['columnName'])),
                );
            }
        }

        $query
            ->select('ri.*')
            ->from('sys_refindex', 'ri')
            ->leftJoin(
                'ri',
                $this->exportIndex->getIndexTableName(),
                'exl',
                (string) $expr->and(
                    $expr->eq('ri.tablename', 'exl.tablename'),
                    $expr->eq('ri.recuid', 'exl.sourceuid'),
                )
            );

        $query->where($expr->and(
            $expr->neq('ri.ref_table', $query->quote('_STRING')),
            $expr->and(
                $expr->or(...$foreignFieldsConstraints),
                $expr->eq('ri.ref_table', '?'),
                $expr->eq('ri.ref_uid', '?'),
            )
        ));

        return $query;
    }
}
