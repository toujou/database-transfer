<?php

declare(strict_types=1);

namespace Toujou\DatabaseTransfer\Database;

use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Toujou\DatabaseTransfer\Database\ForwardRelationTranslator\RelationTranslationStrategy;
use Toujou\DatabaseTransfer\DTO\RelationTranslation;
use TYPO3\CMS\Core\Configuration\FlexForm\FlexFormTools;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Utility\ArrayUtility;
use TYPO3\CMS\Core\Utility\Exception\MissingArrayPathException;
use TYPO3\CMS\Core\Utility\GeneralUtility;

readonly class RelationEditor
{
    /**
     * @param ServiceLocator<RelationTranslationStrategy> $relationTranslationStrategies
     */
    public function __construct(
        #[AutowireLocator(
            services: 'database-transfer.relation-translator',
        )]
        private ServiceLocator $relationTranslationStrategies,
        private TcaSchemaFactory $tcaSchemaFactory,
    ) {}

    /**
     * @param mixed[] $record
     * @param RelationTranslation[] $relationTranslations
     *
     * @return mixed[]
     */
    public function editRelationsInRecord(string $tableName, int $uid, array $record, array $relationTranslations): array
    {
        if (empty($relationTranslations)) {
            return $record;
        }

        $forwardPointingRelations = [];
        $backwardsPointingRelations = [];
        foreach ($relationTranslations as $relationTranslation) {
            $originalRelation = $relationTranslation->original;

            $relationTableName = $originalRelation->getTableName();
            $referencedTableName = $originalRelation->getRefTable();
            $columnName = $originalRelation->getField();

            $schema = $this->tcaSchemaFactory->get($relationTableName);

            if (!$schema->hasField($columnName)) {
                continue;
            }

            $columnConfig = $schema->getField($columnName)->getConfiguration();

            if ($tableName === $relationTableName && isset($record[$columnName]) && !isset($columnConfig['foreign_field'])) {
                if (!isset($forwardPointingRelations[$columnName])) {
                    $forwardPointingRelations[$columnName]['config'] = $columnConfig;
                    $forwardPointingRelations[$columnName]['relations'] = [];
                }
                $forwardPointingRelations[$columnName]['relations'][] = $relationTranslation;
            } elseif ($tableName === $referencedTableName && isset($columnConfig['foreign_field'], $record[$columnConfig['foreign_field']])) {
                $columnName = $columnConfig['foreign_field'];
                if (!isset($backwardsPointingRelations[$columnName][$columnName])) {
                    $backwardsPointingRelations[$relationTableName][$columnName]['config'] = $columnConfig;
                    $backwardsPointingRelations[$relationTableName][$columnName]['relations'] = [];
                }
                $backwardsPointingRelations[$relationTableName][$columnName]['relations'][] = $relationTranslation;
            }
        }

        foreach ($forwardPointingRelations as $columnName => $column) {
            if (!isset($column['config']['type']) || isset($column['config']['MM'])) {
                // We skip MM relations as those aren't local to the record
                continue;
            }

            if ($column['config']['type'] === 'flex') {
                $record[$columnName] = $this->translateRelationsInFlexColumn($column['relations'], $tableName, $columnName, $record);

                continue;
            }

            $record[$columnName] = $this->translateForwardPointingRelationsInColumn($column['relations'], $record[$columnName], $column['config']);
        }

        foreach ($backwardsPointingRelations as $relationTableName => $relationTableColumns) {
            foreach ($relationTableColumns as $column) {
                if (isset($forwardPointingRelations[$column['config']['foreign_field']])) {
                    // Skip backwards pointing relation when there is already a forward pointing relation.
                    continue;
                }
                $record = $this->translateBackwardsPointingRelationInColumn($column, $relationTableName, $tableName, $uid, $record);
            }
        }

        return $record;
    }

    /**
     * @param RelationTranslation[] $relationTranslations
     * @param mixed[] $record
     */
    private function translateRelationsInFlexColumn(array $relationTranslations, string $tableName, string $columnName, array $record): mixed
    {
        $flexFormTools = GeneralUtility::makeInstance(FlexFormTools::class);
        $tableTcaSchema = $this->tcaSchemaFactory->get($tableName);
        $fieldConfig['config'] = $tableTcaSchema->getField($columnName)->getConfiguration();
        $dataStructureIdentifier = $flexFormTools->getDataStructureIdentifier($fieldConfig, $tableName, $columnName, $record);

        $dataStructureArray = $flexFormTools->parseDataStructureByIdentifier($dataStructureIdentifier);
        $flexFormData = GeneralUtility::xml2array($record[$columnName]);
        $flexFormDataChanged = false;

        /** @var RelationTranslation[] $relationsByFlexPointer */
        $relationsByFlexPointer = [];
        foreach ($relationTranslations as $relation) {
            if (empty($relation->original->getFlexPointer())) {
                continue;
            }
            $relationsByFlexPointer[$relation->original->getFlexPointer()][] = $relation;
        }

        foreach ($relationsByFlexPointer as $flexPointer => $relationsOfFlexPointer) {
            $flexPointer = trim($flexPointer, '/');

            try {
                $fieldConfig = ArrayUtility::getValueByPath($dataStructureArray, 'sheets/' . str_replace(['/lDEF/', '/vDEF'], ['/ROOT/el/', '/config'], $flexPointer));
                $dataValue = ArrayUtility::getValueByPath($flexFormData, 'data/' . $flexPointer);
                $translatedValue = $this->translateForwardPointingRelationsInColumn($relationsOfFlexPointer, $dataValue, $fieldConfig);
                $flexFormData = ArrayUtility::setValueByPath($flexFormData, 'data/' . $flexPointer, $translatedValue);
                $flexFormDataChanged = true;
            } catch (MissingArrayPathException) {
            }
        }

        if ($flexFormDataChanged) {
            return $flexFormTools->flexArray2Xml($flexFormData);
        }

        return $record[$columnName];
    }

    /**
     * @param RelationTranslation[] $relationTranslations
     * @param mixed[] $fieldConfig
     *@internal This method needs to be public, so the callback object in ->removeRelationsFromFlexColumn can call it.
     */
    public function translateForwardPointingRelationsInColumn(array $relationTranslations, mixed $value, array $fieldConfig): mixed
    {
        if (empty($value)) {
            return $value;
        }

        // Add a softref definition for link fields if the TCA does not specify one already
        if ($fieldConfig['type'] === 'link' && empty($fieldConfig['softref'])) {
            $fieldConfig['softref'] = 'typolink';
        }

        foreach ($this->relationTranslationStrategies->getProvidedServices() as $id => $service) {
            $strategy = $this->relationTranslationStrategies->get($id);
            if ($strategy->supports($fieldConfig)) {
                return $strategy->translate($relationTranslations, $value, $fieldConfig);
            }
        }

        return $value;
    }

    /**
     * @param mixed[] $column
     * @param mixed[] $record
     *
     * @return mixed[]
     */
    public function translateBackwardsPointingRelationInColumn(array $column, string $relationTableName, mixed $tableName, int $uid, array $record): array
    {
        $foreignFieldColumnName = $column['config']['foreign_field'];
        $matchFields = $column['config']['foreign_match_fields'] ?? [];
        if (isset($column['config']['foreign_table_field'])) {
            $matchFields[$column['config']['foreign_table_field']] = $relationTableName;
        }
        /** @var RelationTranslation $relationTranslation */
        foreach ($column['relations'] as $relationTranslation) {
            $relation = $relationTranslation->translated;

            if ($relation !== null &&
                $tableName !== $relation->getRefTable() &&
                $uid !== ($relation->getRefUid()) &&
                ((int)$record[$foreignFieldColumnName]) !== ((int)$relation->getRefUid()) &&
                count(\array_diff_assoc($matchFields, $record)) > 0
            ) {
                continue;
            }
            if ($relation !== null) {
                $record[$foreignFieldColumnName] = $relation->getRecordUid();
            } else {
                // Lost relation
                $record[$foreignFieldColumnName] = 0;
                // Not sure about unsetting match fields.
                $record = \array_replace($record, \array_fill_keys(\array_keys($matchFields), ''));
            }
        }

        return $record;
    }
}
