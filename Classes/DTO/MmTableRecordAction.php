<?php

declare(strict_types=1);

namespace Toujou\DatabaseTransfer\DTO;

final class MmTableRecordAction
{
    public const CREATE = 'create';
    public const UPDATE = 'update';
    public const DELETE = 'delete';

    /**
     * @param mixed[] $data
     */
    public function __construct(
        private readonly array $data,
        private readonly string $tableName,
        private readonly string $actionType,
    ) {}

    public function getActionType(): string
    {
        return $this->actionType;
    }

    /**
     * @return mixed[]
     */
    public function getData(): array
    {
        return $this->data;
    }

    public function getTableName(): string
    {
        return $this->tableName;
    }

    /**
     * @param mixed[] $data
     */
    public static function createAction(array $data, string $tableName): self
    {
        return new self($data, $tableName, self::CREATE);
    }

    /**
     * @param mixed[] $data
     */
    public static function updateAction(array $data, string $tableName): self
    {
        return new self($data, $tableName, self::UPDATE);
    }

    /**
     * @param mixed[] $data
     */
    public static function deleteAction(array $data, string $tableName): self
    {
        return new self($data, $tableName, self::DELETE);
    }
}
