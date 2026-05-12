<?php

declare(strict_types=1);

namespace Toujou\DatabaseTransfer\DTO;

final readonly class Relation
{
    /**
     * @param mixed[] $relation
     */
    public function __construct(
        private array $relation,
    ) {}

    public function getTableName(): string
    {
        return $this->relation['tablename'];
    }

    public function getRecordUid(): int
    {
        return $this->relation['recuid'];
    }

    public function getField(): ?string
    {
        return $this->relation['field'] ?? null;
    }

    public function getFlexPointer(): string
    {
        return $this->relation['flexpointer'] ?? '';
    }

    public function getSoftRefKey(): ?string
    {
        return $this->relation['softref_key'] ?? null;
    }

    public function getSoftRefId(): ?string
    {
        return $this->relation['softref_id'] ?? null;
    }

    public function getRefTable(): ?string
    {
        return $this->relation['ref_table'] ?? null;
    }

    public function getRefUid(): ?int
    {
        return isset($this->relation['ref_uid']) ? (int)$this->relation['ref_uid'] : null;
    }

    /**
     * @param mixed[] $relation
     * @return self
     */
    public static function fromArray(array $relation): self
    {
        return new self($relation);
    }

    /**
     * @return mixed[]
     */
    public function toArray(): array
    {
        return $this->relation;
    }
}
