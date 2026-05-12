<?php

declare(strict_types=1);

namespace Toujou\DatabaseTransfer\Database\ForwardRelationTranslator;

use Toujou\DatabaseTransfer\DTO\RelationTranslation;
use TYPO3\CMS\Core\Utility\GeneralUtility;

readonly class ForeignTableRelationTranslator implements RelationTranslationStrategy
{
    public function supports(array $fieldConfig): bool
    {
        if (!\in_array($fieldConfig['type'] ?? null, ['select', 'inline', 'category', 'file'], true)) {
            return false;
        }

        if (!isset($fieldConfig['foreign_table'])) {
            return false;
        }

        if (isset($fieldConfig['foreign_field'])) {
            return false;
        }

        return true;
    }

    public function translate(array $relationTranslations, mixed $value, array $fieldConfig): mixed
    {
        $translationMap = \array_combine(
            \array_map(fn(RelationTranslation $relationTranslation) => $relationTranslation->original->getRefUid(), $relationTranslations),
            \array_map(fn(RelationTranslation $relationTranslation) => $relationTranslation->translated?->getRefUid(), $relationTranslations),
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
