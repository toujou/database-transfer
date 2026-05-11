<?php

declare(strict_types=1);

namespace Toujou\DatabaseTransfer\Database;

use Toujou\DatabaseTransfer\DTO\Relation;
use Toujou\DatabaseTransfer\DTO\RelationTranslation;
use TYPO3\CMS\Core\Configuration\FlexForm\FlexFormTools;
use TYPO3\CMS\Core\DataHandling\SoftReference\SoftReferenceParserFactory;
use TYPO3\CMS\Core\LinkHandling\LinkService;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Utility\ArrayUtility;
use TYPO3\CMS\Core\Utility\Exception\MissingArrayPathException;
use TYPO3\CMS\Core\Utility\GeneralUtility;

readonly class RelationEditor
{
    public function __construct(
        private SoftReferenceParserFactory $softReferenceParserFactory,
        private LinkService $linkService,
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

        if ($fieldConfig['type'] === 'group' && isset($fieldConfig['allowed'])) {
            $foreignTables = GeneralUtility::trimExplode(',', $fieldConfig['allowed'], true);
            $prependTableName = $fieldConfig['prepend_tname'] ?? count($foreignTables) > 1;

            $translationMap = \array_combine(
                \array_map(
                    fn(RelationTranslation $relationTranslation) => (
                        $prependTableName ? $relationTranslation->original->getRefTable() . '_' : ''
                    ) . ($relationTranslation->original->getRefUid()),
                    $relationTranslations,
                ),
                \array_map(
                    fn(RelationTranslation $relationTranslation) =>
                    empty($relationTranslation->translated) ? null : ($prependTableName ? $relationTranslation->translated->getRefTable() . '_' : '') . ($relationTranslation->translated->getRefUid()),
                    $relationTranslations,
                ),
            );

            return $this->translateList($value, $translationMap);
        }

        if (\in_array($fieldConfig['type'], ['select', 'inline', 'category', 'file']) &&
            isset($fieldConfig['foreign_table']) &&
            !isset($fieldConfig['foreign_field'])) {
            $translationMap = \array_combine(
                \array_map(fn(RelationTranslation $relationTranslation) => $relationTranslation->original->getRefUid(), $relationTranslations),
                \array_map(fn(RelationTranslation $relationTranslation) => $relationTranslation->translated?->getRefUid(), $relationTranslations),
            );

            return $this->translateList($value, $translationMap);
        }

        // Add a softref definition for link fields if the TCA does not specify one already
        if ($fieldConfig['type'] === 'link' && empty($fieldConfig['softref'])) {
            $fieldConfig['softref'] = 'typolink';
        }

        $hasSoftRefs = false;
        if (isset($fieldConfig['softref'])) {
            foreach ($relationTranslations as $relationTranslation) {
                if ($relationTranslation->original->getSoftrefKey() !== null) {
                    $hasSoftRefs = true;
                    break;
                }
            }
        }

        if ($hasSoftRefs) {
            return $this->translateRelationsFromSoftReferences($relationTranslations, $value, $fieldConfig['softref']);
        }

        return $value;
    }

    /**
     * @param mixed[] $translationMap
     */
    private function translateList(mixed $list, array $translationMap): mixed
    {
        $existingElements = GeneralUtility::trimExplode(',', (string)$list, true);
        $translatedElements = \array_map(fn($source) => $translationMap[$source], $existingElements);
        $translatedElements = \array_filter($translatedElements);

        if ($existingElements !== $translatedElements) {
            $originalType = gettype($list);
            $list = implode(',', $translatedElements);
            settype($list, $originalType);
        }

        return $list;
    }

    /**
     * @param RelationTranslation[] $relationTranslations
     */
    private function translateRelationsFromSoftReferences(array $relationTranslations, mixed $value, string $softref): mixed
    {
        /** @var Relation $representativeRelation */
        $representativeRelation = \reset($relationTranslations)->original;
        $parsedValue = $value;
        $matchedElements = [];
        foreach ($this->softReferenceParserFactory->getParsersBySoftRefParserList($softref) as $softReferenceParser) {
            $parserResult = $softReferenceParser->parse(
                $representativeRelation->getTableName(),
                $representativeRelation->getField(),
                $representativeRelation->getRecordUid(),
                $parsedValue,
                $representativeRelation->getFlexPointer(),
            );
            if ($parserResult->hasMatched()) {
                $matchedElements[$softReferenceParser->getParserKey()] = $parserResult->getMatchedElements();
                if ($parserResult->hasContent()) {
                    $parsedValue = $parserResult->getContent();
                }
            }
        }
        if (empty($matchedElements) || (string)$value === (string)$parsedValue || !str_contains($parsedValue, '{softref:')) {
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
        foreach ($relationTranslations as $relationTranslation) {
            $originalRelation = $relationTranslation->original;
            $translatedRelation = $relationTranslation->translated;

            $softrefKey = $originalRelation->getSoftrefKey();
            $softrefId = $originalRelation->getSoftrefId();
            $softrefElement = $matchedElements[$softrefKey][$softrefId] ?? null;
            $tokenId = $softrefElement['subst']['tokenID'] ?? null;

            if ($tokenId === null) {
                continue;
            }

            if (($translatedRelation?->getRefUid()) === null) {
                $softrefValues['{softref:' . $tokenId . '}'] = '';

                continue;
            }

            switch ($softrefElement['subst']['type'] ?? null) {
                case 'file':
                    // @todo add handling for files

                case 'db':
                    $insertValue = $translatedRelation->getRefUid();
                    $tokenValue = (string)$softrefElement['subst']['tokenValue'];
                    if (str_contains($tokenValue, ':')) {
                        [$tokenKey] = explode(':', $tokenValue);
                        $insertValue = $tokenKey . ':' . $insertValue;
                    }
                    $matchString = $softrefElement['matchString'] ?? '';

                    // Handling for typo3_links
                    if (str_contains($matchString, 't3://')) {
                        $link = $matchString;
                        if (preg_match('/href="([^"]+)"/', $link, $linkMatches)) {
                            $link = $linkMatches[1];
                        }

                        $parts = $this->linkService->resolve($link);

                        if ($translatedRelation->getTableName() === 'pages') {
                            $parts['pageuid'] = $translatedRelation->getRefUid();
                        }
                        // content element anchors will be replaced via own softref
                        if ((int)($parts['fragment'] ?? 0) > 0) {
                            unset($parts['fragment']);
                        }

                        $insertValue = $this->linkService->asString($parts);
                    }
                    $softrefValues['{softref:' . $tokenId . '}'] = $insertValue;
            }

        }

        // TODO relation softref_id has to be recalculated as the hash includes the uid of the record

        return \str_replace(\array_keys($softrefValues), $softrefValues, $parsedValue);
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
