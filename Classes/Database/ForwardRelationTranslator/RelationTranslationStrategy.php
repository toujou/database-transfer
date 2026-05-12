<?php

namespace Toujou\DatabaseTransfer\Database\ForwardRelationTranslator;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Toujou\DatabaseTransfer\DTO\RelationTranslation;

#[AutoconfigureTag('database-transfer.relation-translator')]
interface RelationTranslationStrategy
{
    /**
     * @param mixed[] $fieldConfig
     */
    public function supports(array $fieldConfig): bool;

    /**
     * @param RelationTranslation[] $relationTranslations
     * @param mixed $value
     * @param mixed[] $fieldConfig
     * @return mixed
     */
    public function translate(
        array $relationTranslations,
        mixed $value,
        array $fieldConfig,
    ): mixed;

}
