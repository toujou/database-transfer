<?php

declare(strict_types=1);

namespace Toujou\DatabaseTransfer\Database;

use TYPO3\CMS\Core\Configuration\FlexForm\FlexFormTools;
use TYPO3\CMS\Core\Schema\Field\FlexFormFieldType;
use TYPO3\CMS\Core\Schema\PassiveRelation;
use TYPO3\CMS\Core\Utility\GeneralUtility;

readonly class FlexRelationHelper
{
    public function __construct() {}

    /**
     * @return array<string, array<int, PassiveRelation>>
     */
    public function getRelationsForFlexField(FlexFormFieldType $flexFormField, string $tableName): array
    {
        $relations = [];

        foreach ($this->getRelationalFlexFormConfigurations($flexFormField, $tableName) as $config) {
            $foreignTable = $config['foreign_table'] ?? $config['allowed'] ?? null;

            $targetTables = GeneralUtility::trimExplode(',', $foreignTable, true);

            foreach ($targetTables as $targetTable) {
                $relations[$targetTable][] = new PassiveRelation(
                    $tableName,
                    $flexFormField->getName(),
                    null,
                );
            }
        }

        return $relations;
    }

    /**
     * @return mixed[]
     */
    public function getRelationalFlexFormConfigurations(FlexFormFieldType $foreignFieldField, string $tableName): array
    {
        $configurations = [];

        /** @var FlexFormTools $flexFormTools */
        $flexFormTools = GeneralUtility::makeInstance(FlexFormTools::class);

        // Get the core field configuration
        $fieldConfig = $foreignFieldField->getConfiguration();

        if (isset($fieldConfig['ds']) && is_array($fieldConfig['ds'])) {
            foreach ($fieldConfig['ds'] as $key => $value) {
                $identifier = json_encode([
                    'type' => 'tca',
                    'tableName' => $tableName,
                    'fieldName' => $foreignFieldField->getName(),
                    'dataStructureKey' => $key,
                ]);

                try {
                    $dataStructureArray = $flexFormTools->parseDataStructureByIdentifier($identifier);

                    if (isset($dataStructureArray['sheets']) && is_array($dataStructureArray['sheets'])) {
                        foreach ($dataStructureArray['sheets'] as $sheet) {
                            if (!isset($sheet['ROOT']['el']) || !is_array($sheet['ROOT']['el'])) {
                                continue;
                            }

                            foreach ($sheet['ROOT']['el'] as $nestedFieldName => $nestedFieldConfig) {
                                $config = $nestedFieldConfig['config'] ?? [];

                                // 4. Look for foreign table indicators (inline, select, group, category)
                                $foreignTable = $config['foreign_table'] ?? $config['allowed'] ?? null;

                                if ($foreignTable && is_string($foreignTable)) {
                                    $configurations[] = $config;
                                }
                            }
                        }
                    }
                } catch (\Exception $e) {
                    continue;
                }
            }
        }

        return $configurations;

    }

}
