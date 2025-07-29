<?php

declare(strict_types=1);

namespace Toujou\DatabaseTransfer\Database;

use Doctrine\DBAL\ParameterType;
use Toujou\DatabaseTransfer\Export\ExportIndex;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Schema\Field\FieldTypeInterface;
use TYPO3\CMS\Core\Schema\Field\RelationalFieldTypeInterface;
use TYPO3\CMS\Core\Schema\PassiveRelation;
use TYPO3\CMS\Core\Schema\RelationshipType;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class RelationAnalyzer
{
    private array $relationsQueryCache = [];

    private readonly TcaSchemaFactory $schemaFactory;

    private readonly array $backwardsPointingRelations;

    public function __construct(
        private readonly ExportIndex $exportIndex,
        TcaSchemaFactory $schemaFactory = null,
    ) {
        $this->schemaFactory = $schemaFactory ?? GeneralUtility::makeInstance(TcaSchemaFactory::class);
        $this->backwardsPointingRelations = $this->buildBackwardsPointingRelations();
    }

    private function buildBackwardsPointingRelations(): array
    {
        $backwardsPointingRelations = [];
        $recordTableNames = $this->exportIndex->getRecordTableNames();
        foreach ($recordTableNames as $tableName) {
            $schema = $this->schemaFactory->get($tableName);
            // We only take care of foreign fields, as all other relations are already covered by the forward pointing relations
            $foreignFieldFields = $schema->getFields(fn(FieldTypeInterface $field) => $field instanceof RelationalFieldTypeInterface && $field->getRelationshipType() === RelationshipType::OneToMany);
            foreach ($foreignFieldFields as $foreignFieldField) {
                foreach ($foreignFieldField->getRelations() as $relation) {
                    $backwardsPointingRelations[$relation->toTable()][] = new PassiveRelation($tableName, $foreignFieldField->getName(), null);
                }
            }
        }
        return $backwardsPointingRelations;
    }

    public function getRelationsForRecord(string $tableName, int $uid): array
    {
        if (isset($this->relationsQueryCache[$tableName])) {
            $execQuery = $this->relationsQueryCache[$tableName];
        } else {
            $queries = [$this->buildForwardPointingRelationsQuery()];

            $backwardsPointingQuery = $this->buildBackwardsPointingRelationsQuery($tableName);
            if ($backwardsPointingQuery) {
                $queries[] = $backwardsPointingQuery;
            }

            $sql = \implode(' UNION ', \array_map(fn(QueryBuilder $query) => $query->getSQL(), $queries));
            $preparedQuery = $this->exportIndex->getConnection()->prepare($sql);

            $execQuery = $this->relationsQueryCache[$tableName] = function (string $tableName, int $uid) use ($preparedQuery, $backwardsPointingQuery) {
                $preparedQuery->bindValue(1, $tableName);
                $preparedQuery->bindValue(2, $uid, ParameterType::INTEGER);
                if ($backwardsPointingQuery) {
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
                (string)$expr->and(
                    $expr->eq('ri.ref_table', 'exr.tablename'),
                    $expr->eq('ri.ref_uid', 'exr.sourceuid'),
                ),
            );

        $query->where($expr->and(
            $expr->and(
                $expr->eq('ri.tablename', '?'),
                $expr->eq('ri.recuid', '?'),
            ),
        ));

        return $query;
    }

    private function buildBackwardsPointingRelationsQuery(string $tableName): ?QueryBuilder
    {
        $passiveRelations = $this->backwardsPointingRelations[$tableName] ?? [];
        if (empty($passiveRelations)) {
            return null;
        }

        $query = $this->exportIndex->getConnection()->createQueryBuilder();
        $expr = $query->expr();

        $foreignFieldsConstraints = [];
        foreach ($passiveRelations as $passiveRelation) {
            $foreignFieldsConstraints[] = $expr->and(
                $expr->eq('ri.tablename', $query->quote($passiveRelation->fromTable())),
                $expr->eq('ri.field', $query->quote($passiveRelation->fromField())),
            );
        }

        $query
            ->select('ri.*')
            ->from('sys_refindex', 'ri')
            ->leftJoin(
                'ri',
                $this->exportIndex->getIndexTableName(),
                'exl',
                (string)$expr->and(
                    $expr->eq('ri.tablename', 'exl.tablename'),
                    $expr->eq('ri.recuid', 'exl.sourceuid'),
                ),
            );

        $query->where($expr->and(
            $expr->neq('ri.ref_table', $query->quote('_STRING')),
            $expr->and(
                $expr->or(...$foreignFieldsConstraints),
                $expr->eq('ri.ref_table', '?'),
                $expr->eq('ri.ref_uid', '?'),
            ),
        ));

        return $query;
    }
}
