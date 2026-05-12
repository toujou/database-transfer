<?php

declare(strict_types=1);

namespace Toujou\DatabaseTransfer\DTO;

final readonly class RelationTranslation
{
    private function __construct(
        public Relation $original,
        public ?Relation $translated,
    ) {}

    public static function create(
        Relation $original,
        ?Relation $translated,
    ): self {
        return new self($original, $translated);
    }
}
