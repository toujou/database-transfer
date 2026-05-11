<?php

declare(strict_types=1);

namespace Toujou\DatabaseTransfer\Database\ForwardRelationTranslator;

use Toujou\DatabaseTransfer\DTO\RelationTranslation;
use TYPO3\CMS\Core\Utility\GeneralUtility;

readonly class GroupRelationTranslator implements RelationTranslationStrategy
{
    public function supports(array $fieldConfig): bool
    {
        return $fieldConfig['type'] === 'group' && isset($fieldConfig['allowed']);
    }

    public function translate(array $relationTranslations, mixed $value, array $fieldConfig): mixed
    {
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

    /**
     * @param mixed[] $translationMap
     */
    private function translateList(mixed $list, array $translationMap): mixed
    {
        $existingElements = GeneralUtility::trimExplode(',', (string)$list, true);

        $translatedElements = array_values(array_filter(
            array_map(
                static fn($source) => $translationMap[$source] ?? null,
                $existingElements,
            ),
        ));

        if ($existingElements !== $translatedElements) {
            $originalType = gettype($list);
            $list = implode(',', $translatedElements);
            settype($list, $originalType);
        }

        return $list;
    }
}
