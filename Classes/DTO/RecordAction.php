<?php

declare(strict_types=1);

namespace Toujou\DatabaseTransfer\DTO;

final readonly class RecordAction
{
    private function __construct(
        public string $tableName,
        public string $type,
        public ?int $sourceUid,
        public ?int $targetUid,
        public ?int $updatedAt,
    ) {}

    public function isCreateAction(): bool
    {
        return $this->targetUid === null;
    }

    public function isUpdateAction(): bool
    {
        return $this->targetUid !== null && $this->sourceUid !== null;
    }

    public function isDeleteAction(): bool
    {
        return $this->targetUid !== null && $this->sourceUid === null;
    }

    /**
     * @param mixed[] $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            tableName: $data['tablename'],
            type: $data['type'],
            sourceUid: $data['sourceuid'] ?: null,
            targetUid: $data['targetuid'] ?: null,
            updatedAt: isset($data['updated_at']) ? (int)$data['updated_at'] : null,
        );
    }

    /**
     * @return mixed[]
     */
    public function toArray(): array
    {
        return [
            'tablename' => $this->tableName,
            'type' => $this->type,
            'sourceuid' => $this->sourceUid,
            'targetuid' => $this->targetUid,
            'updated_at' => $this->updatedAt,
        ];
    }
}
