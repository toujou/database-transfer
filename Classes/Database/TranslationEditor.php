<?php

declare(strict_types=1);

namespace Toujou\DatabaseTransfer\Database;

use Toujou\DatabaseTransfer\Export\ImportIndex;
use Toujou\DatabaseTransfer\Export\Selection;
use TYPO3\CMS\Core\Schema\Capability\LanguageAwareSchemaCapability;
use TYPO3\CMS\Core\Schema\Capability\TcaSchemaCapability;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;

readonly class TranslationEditor
{
    public function __construct(
        private TcaSchemaFactory $tcaSchemaFactory,
    ) {}

    /**
     * Remaps the TCA translation fields of a record to the target instance.
     *
     * Language pointers are not reliably tracked via sys_refindex, so they are
     * remapped from the language map and the import index. The returned record
     * is authoritative over values set by the relation editor.
     *
     * @param mixed[] $record
     *
     * @return mixed[]
     */
    public function editTranslationsInRecord(string $tableName, array $record, Selection $selection, ImportIndex $importIndex): array
    {
        $schema = $this->tcaSchemaFactory->get($tableName);
        if (!$schema->isLanguageAware()) {
            return $record;
        }

        /** @var LanguageAwareSchemaCapability $languageCapability */
        $languageCapability = $schema->getCapability(TcaSchemaCapability::Language);
        $overrides = [];

        $languageField = $languageCapability->getLanguageField()->getName();
        if (isset($record[$languageField])) {
            $sourceLanguageId = (int)$record[$languageField];
            if ($sourceLanguageId !== 0 && $sourceLanguageId !== -1) {
                $overrides[$languageField] = $selection->getLanguageMap()[$sourceLanguageId] ?? $sourceLanguageId;
            }
        }

        $originPointerField = $languageCapability->getTranslationOriginPointerField()->getName();
        if (isset($record[$originPointerField])) {
            $overrides[$originPointerField] = $importIndex->translateUid($tableName, (int)$record[$originPointerField]) ?? 0;
        }

        $translationSourceField = $languageCapability->getTranslationSourceField();
        if ($translationSourceField !== null) {
            $translationSourceFieldName = $translationSourceField->getName();
            if (isset($record[$translationSourceFieldName])) {
                $overrides[$translationSourceFieldName] = $importIndex->translateUid($tableName, (int)$record[$translationSourceFieldName]) ?? 0;
            }
        }

        return \array_replace($record, $overrides);
    }
}
