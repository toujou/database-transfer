<?php

declare(strict_types=1);

namespace Toujou\DatabaseTransfer\Database;

use TYPO3\CMS\Core\Configuration\FlexForm\FlexFormTools;
use TYPO3\CMS\Core\DataHandling\SoftReference\SoftReferenceParserFactory;
use TYPO3\CMS\Core\Utility\ArrayUtility;
use TYPO3\CMS\Core\Utility\Exception\MissingArrayPathException;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class RelationEditor
{
    public function __construct(
        private SoftReferenceParserFactory $softReferenceParserFactory
    ) {
    }

    public function editRelationsInRecord(string $tableName, int $uid, array $record, array $relationMap): array
    {
        if (empty($relationMap)) {
            return $record;
        }

        $forwardPointingRelations = [];
        $backwardsPointingRelations = [];
        foreach ($relationMap as $relationTranslation) {
            $relation = $relationTranslation['original'];
            $relationTableName = $relation['tablename'];
            $referencedTableName = $relation['ref_table'];
            $columnName = $relation['field'];

            if (!isset($GLOBALS['TCA'][$relationTableName]['columns'][$columnName]['config'])) {
                continue;
            }
            $columnConfig = $GLOBALS['TCA'][$relationTableName]['columns'][$columnName]['config'];

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

            if ('flex' === $column['config']['type']) {
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

    private function translateRelationsInFlexColumn(array $relations, string $tableName, string $columnName, array $record): mixed
    {
        $flexFormTools = GeneralUtility::makeInstance(FlexFormTools::class);
        $dataStructureIdentifier = $flexFormTools->getDataStructureIdentifier($GLOBALS['TCA'][$tableName]['columns'][$columnName], $tableName, $columnName, $record);
        $dataStructureArray = $flexFormTools->parseDataStructureByIdentifier($dataStructureIdentifier);
        $flexFormData = GeneralUtility::xml2array($record[$columnName]);
        $flexFormDataChanged = false;

        $relationsByFlexpointer = [];
        foreach ($relations as $relation) {
            if (empty($relation['original']['flexpointer'])) {
                continue;
            }
            $relationsByFlexpointer[$relation['original']['flexpointer']][] = $relation;
        }

        foreach ($relationsByFlexpointer as $flexpointer => $relationsOfFlexpointer) {
            $flexPointer = trim($flexpointer, '/');

            try {
                $fieldConfig = ArrayUtility::getValueByPath($dataStructureArray, 'sheets/' . str_replace(['/lDEF/', '/vDEF'], ['/ROOT/el/', '/config'], $flexPointer));
                $dataValue = ArrayUtility::getValueByPath($flexFormData, 'data/' . $flexPointer);
                $translatedValue = $this->translateForwardPointingRelationsInColumn($relationsOfFlexpointer, $dataValue, $fieldConfig);
                $flexFormData = ArrayUtility::setValueByPath($flexFormData, 'data/' . $flexPointer, $translatedValue);
                $flexFormDataChanged = true;
            } catch (MissingArrayPathException $arrayPathException) {
            }
        }

        if ($flexFormDataChanged) {
            return $flexFormTools->flexArray2Xml($flexFormData, true);
        }

        return $record[$columnName];
    }

    /**
     * @internal This method needs to be public, so the callback object in ->removeRelationsFromFlexColumn can call it.
     */
    public function translateForwardPointingRelationsInColumn(array $relations, mixed $value, array $fieldConfig)
    {
        if (empty($value)) {
            return $value;
        }

        if ('group' === $fieldConfig['type'] && isset($fieldConfig['allowed'])) {
            $foreignTables = GeneralUtility::trimExplode(',', $fieldConfig['allowed'], true);
            $prependTableName = $fieldConfig['prepend_tname'] ?? count($foreignTables) > 1;

            $translationMap = \array_combine(
                \array_map(fn (array $relation) => ($prependTableName ? $relation['ref_table'] . '_' : '') . ($relation['original']['ref_uid'] ?? 0), $relations),
                \array_map(fn (array $relation) => empty($relation['translated']['ref_uid']) ? null : ($prependTableName ? $relation['ref_table'] . '_' : '') . ($relation['translated']['ref_uid']), $relations),
            );

            return $this->translateList($value, $translationMap);
        }

        if (\in_array($fieldConfig['type'], ['select', 'inline', 'category', 'file']) &&
            isset($fieldConfig['foreign_table']) &&
            !isset($fieldConfig['foreign_field'])) {
            $translationMap = \array_combine(
                \array_map(fn (array $relation) => $relation['original']['ref_uid'] ?? 0, $relations),
                \array_map(fn (array $relation) => $relation['translated']['ref_uid'] ?? null, $relations),
            );

            return $this->translateList($value, $translationMap);
        }

        // Add a softref definition for link fields if the TCA does not specify one already
        if ('link' === $fieldConfig['type'] && empty($fieldConfig['softref'])) {
            $fieldConfig['softref'] = 'typolink';
        }
        if (isset($fieldConfig['softref']) && count(\array_filter(\array_column(\array_column($relations, 'original'), 'softref_key'))) > 0) {
            return $this->translateRelationsFromSoftReferences($relations, $value, $fieldConfig['softref']);
        }

        return $value;
    }

    private function translateList(mixed $list, array $translationMap): mixed
    {
        $existingElements = GeneralUtility::trimExplode(',', (string) $list, true);
        $translatedElements = \array_map(fn ($source) => $translationMap[$source], $existingElements);
        $translatedElements = \array_filter($translatedElements);

        if ($existingElements !== $translatedElements) {
            $originalType = gettype($list);
            $list = implode(',', $translatedElements);
            settype($list, $originalType);
        }

        return $list;
    }

    private function translateRelationsFromSoftReferences(array $relations, mixed $value, $softref): mixed
    {
        $representativeRelation = \reset($relations)['original'];
        $parsedValue = $value;
        $matchedElements = [];
        foreach ($this->softReferenceParserFactory->getParsersBySoftRefParserList($softref) as $softReferenceParser) {
            $parserResult = $softReferenceParser->parse(
                $representativeRelation['tablename'],
                $representativeRelation['field'],
                $representativeRelation['recuid'],
                $parsedValue,
                $representativeRelation['flexpointer']
            );
            if ($parserResult->hasMatched()) {
                $matchedElements[$softReferenceParser->getParserKey()] = $parserResult->getMatchedElements();
                if ($parserResult->hasContent()) {
                    $parsedValue = $parserResult->getContent();
                }
            }
        }
        if (empty($matchedElements) || (string) $value === (string) $parsedValue || !str_contains($parsedValue, '{softref:')) {
            return $value;
        }

        // This is ugly and complicated. As there is no way to stringify the found softreferences back to their original string,
        // we need to split the parsed values by the softreference tokens and then split the original value by text around the tokens.
        // Only this way we can build a list of the softreference tokens with its original string.
        $splitParsedValue = \preg_split('/({softref:[a-z0-9]+})/', $parsedValue, -1, \PREG_SPLIT_DELIM_CAPTURE);
        $unparsedValue = $value;
        $softrefValues = [];
        foreach ($splitParsedValue as $index => $split) {
            if (!($index % 2)) {
                $start = \strpos($unparsedValue, $split);
                if ($start > 0) {
                    $softrefValues[$splitParsedValue[$index - 1]] = \substr($unparsedValue, 0, $start);
                }
                $unparsedValue = \substr($unparsedValue, $start + \strlen($split));
            }
        }

        // As the reference index stores the key of the softreference parser and the id, we need to map this to the
        // softreference tokenId.
        foreach ($relations as $relation) {
            $softrefElement = $matchedElements[$relation['original']['softref_key']][$relation['original']['softref_id']] ?? null;
            if (!isset($softrefElement['subst']['tokenID'])) {
                continue;
            }
            $tokenId = $softrefElement['subst']['tokenID'];
            if (null === $relation['translated']) {
                $softrefValues['{softref:' . $tokenId . '}'] = '';
            } elseif ('db' === $softrefElement['subst']['type'] &&
                \str_contains($softrefElement['matchString'], ':') &&
                null !== $relation['translated']['ref_uid']) {
                [$tokenKey] = explode(':', $softrefElement['matchString']);
                $softrefValues['{softref:' . $tokenId . '}'] = $tokenKey . ':' . $relation['translated']['ref_uid'];
            }
            // TODO figure out if its a problem if none of the conditions apply
        }

        // TODO relation softref_id has to be recalculated as the hash includes the uid of the record

        return \str_replace(\array_keys($softrefValues), $softrefValues, $parsedValue);
    }

    public function translateBackwardsPointingRelationInColumn(array $column, string $relationTableName, mixed $tableName, int $uid, array $record): array
    {
        $foreignFieldColumnName = $column['config']['foreign_field'];
        $matchFields = $column['config']['foreign_match_fields'] ?? [];
        if (isset($column['config']['foreign_table_field'])) {
            $matchFields[$column['config']['foreign_table_field']] = $relationTableName;
        }
        foreach ($column['relations'] as $relationTranslation) {
            $relation = $relationTranslation['translated'];
            if (null !== $relation &&
                $tableName !== $relation['ref_table'] &&
                $uid !== ((int) $relation['ref_uid']) &&
                ((int) $record[$foreignFieldColumnName]) !== ((int) $relation['recuid']) &&
                count(\array_diff_assoc($matchFields, $record)) > 0
            ) {
                continue;
            }
            if (null !== $relation) {
                $record[$foreignFieldColumnName] = $relation['recuid'];
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
