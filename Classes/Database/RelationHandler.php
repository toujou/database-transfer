<?php

declare(strict_types=1);

namespace Toujou\DatabaseTransfer\Database;

use TYPO3\CMS\Core\Configuration\FlexForm\FlexFormTools;
use TYPO3\CMS\Core\DataHandling\SoftReference\SoftReferenceParserFactory;
use TYPO3\CMS\Core\Utility\ArrayUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class RelationHandler
{
    public function __construct(
        private SoftReferenceParserFactory $softReferenceParserFactory
    ) {
    }

    public function removeRelationsFromRecord(array $relations, array $record): array
    {
        if (empty($relations)) {
            return $record;
        }

        $tableName = $record['_tablename'];
        $uid = (int) $record['uid'];

        $forwardPointingRelations = [];
        $backwardsPointingRelations = [];
        foreach ($relations as $relation) {
            if (!isset($relation['tablename'], $relation['field'], $relation['flexpointer'], $relation['recuid'], $relation['ref_table'], $relation['ref_uid'])) {
                continue;
            }

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
                $forwardPointingRelations[$columnName]['relations'][] = $relation;
            } elseif ($tableName === $referencedTableName && isset($columnConfig['foreign_field'], $record[$columnConfig['foreign_field']])) {
                $columnName = $columnConfig['foreign_field'];
                if (!isset($backwardsPointingRelations[$columnName][$columnName])) {
                    $backwardsPointingRelations[$relationTableName][$columnName]['config'] = $columnConfig;
                    $backwardsPointingRelations[$relationTableName][$columnName]['relations'] = [];
                }
                $backwardsPointingRelations[$relationTableName][$columnName]['relations'][] = $relation;
            }
        }

        foreach ($forwardPointingRelations as $columnName => $column) {
            if (!isset($column['config']['type']) || isset($column['config']['MM'])) {
                // We skip MM relations as those aren't local to the record
                continue;
            }

            if ('flex' === $column['config']['type']) {
                $record[$columnName] = $this->removeRelationsFromFlexColumn($column['relations'], $tableName, $columnName, $record);

                continue;
            }

            $record[$columnName] = $this->removeForwardPointingRelationsFromColumn($column['relations'], $record[$columnName], $column['config']);
        }

        foreach ($backwardsPointingRelations as $relationTableName => $relationTableColumns) {
            foreach ($relationTableColumns as $column) {
                $foreignFieldColumnName = $column['config']['foreign_field'];
                $matchFields = $column['config']['foreign_match_fields'] ?? [];
                if (isset($column['config']['foreign_table_field'])) {
                    $matchFields[$column['config']['foreign_table_field']] = $relationTableName;
                }
                foreach ($relations as $relation) {
                    if ($tableName !== $relation['ref_table'] &&
                        $uid !== ((int) $relation['ref_uid']) &&
                        ((int) $record[$foreignFieldColumnName]) !== ((int) $relation['recuid']) &&
                        count(\array_diff_assoc($matchFields, $record)) > 0
                    ) {
                        continue;
                    }
                    $record[$foreignFieldColumnName] = 0;
                    // Not sure about unsetting match fields.
                    $record = \array_replace($record, \array_fill_keys(\array_keys($matchFields), ''));
                }
            }
        }

        return $record;
    }

    private function removeRelationsFromFlexColumn(mixed $relations, mixed $tableName, string $columnName, array $record): mixed
    {
        // FlexFormTools->traverseFlexFormXMLData expects a weird callback object, so we're building it on the fly here
        $callBackObj = new class($this, $relations) {
            private array $relationsByFlexpointer = [];

            public array $changes = [];

            public function __construct(
                private RelationHandler $relationHandler,
                array $relations
            ) {
                foreach ($relations as $relation) {
                    if (empty($relation['flexpointer'])) {
                        continue;
                    }

                    // ReferenceIndex stores flexpointers in a slightly different format than the one FlexFormTools uses
                    $flexpointer = 'data/' . trim($relation['flexpointer'], '/');
                    if (!isset($this->relationsByFlexpointer[$flexpointer])) {
                        $this->relationsByFlexpointer[$flexpointer] = [];
                    }
                    $this->relationsByFlexpointer[$flexpointer][] = $relation;
                }
            }

            public function flexTraverseCalback(array $dsArr, mixed $dataValue, array $PA, string $flexpointer): void
            {
                if (!isset($this->relationsByFlexpointer[$flexpointer])) {
                    return;
                }
                $processedValue = $this->relationHandler->removeForwardPointingRelationsFromColumn($this->relationsByFlexpointer[$flexpointer], $dataValue, $dsArr['config']);
                if ($processedValue !== $dataValue) {
                    $this->changes[$flexpointer] = $processedValue;
                }
            }
        };

        $flexFormTools = GeneralUtility::makeInstance(FlexFormTools::class);
        $flexFormTools->traverseFlexFormXMLData($tableName, $columnName, $record, $callBackObj, 'flexTraverseCalback');

        if (count($callBackObj->changes) > 0) {
            $flexData = GeneralUtility::xml2array($record[$columnName]);
            foreach ($callBackObj->changes as $flexpointer => $changedValue) {
                $flexData = ArrayUtility::setValueByPath($flexData, $flexpointer, $changedValue);
            }

            return $flexFormTools->flexArray2Xml($flexData, true);
        }

        return $record[$columnName];
    }

    /**
     * @internal This method needs to be public, so the callback object in ->removeRelationsFromFlexColumn can call it.
     */
    public function removeForwardPointingRelationsFromColumn(array $relations, mixed $value, array $columnConfig)
    {
        if (empty($value)) {
            return $value;
        }

        if ('group' === $columnConfig['type'] && isset($columnConfig['allowed'])) {
            $foreignTables = GeneralUtility::trimExplode(',', $columnConfig['allowed'], true);
            $prependTableName = $columnConfig['prepend_tname'] ?? count($foreignTables) > 1;
            $removeElements = \array_map(fn ($relation) => ($prependTableName ? $relation['ref_table'] . '_' : '') . $relation['ref_uid'], $relations);

            return $this->removeFromList($value, $removeElements);
        }

        if (\in_array($columnConfig['type'], ['select', 'inline', 'categories', 'file']) &&
            isset($columnConfig['foreign_table']) &&
            !isset($columnConfig['foreign_field'])) {
            $removeElements = \array_map(fn ($relation) => $relation['ref_uid'], $relations);

            return $this->removeFromList($value, $removeElements);
        }

        // Add a softref definition for link fields if the TCA does not specify one already
        if ('link' === $columnConfig['type'] && empty($columnConfig['softref'])) {
            $columnConfig['softref'] = 'typolink';
        }
        if (isset($columnConfig['softref']) && count(\array_filter(\array_column($relations, 'softref_key'))) > 0) {
            return $this->removeRelationsFromSoftReferences($relations, $value, $columnConfig['softref']);
        }

        return $value;
    }

    private function removeFromList(mixed $list, array $removeElements): mixed
    {
        $existingElements = GeneralUtility::trimExplode(',', $list, true);
        if (!empty($removeElements)) {
            $originalType = gettype($list);
            $list = implode(',', array_diff($existingElements, $removeElements));
            settype($list, $originalType);
        }

        return $list;
    }

    private function removeRelationsFromSoftReferences(array $relations, mixed $value, $softref): mixed
    {
        $representativeRelation = \reset($relations);
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
            if (!isset($matchedElements[$relation['softref_key']][$relation['softref_id']]['subst']['tokenID'])) {
                continue;
            }
            $tokenId = $matchedElements[$relation['softref_key']][$relation['softref_id']]['subst']['tokenID'];
            $softrefValues['{softref:' . $tokenId . '}'] = '';
        }

        $parsedValue = \str_replace(\array_keys($softrefValues), $softrefValues, $parsedValue);

        return $parsedValue;
    }
}
