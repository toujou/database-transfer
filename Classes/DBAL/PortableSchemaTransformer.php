<?php

declare(strict_types=1);


namespace Toujou\DatabaseTransfer\DBAL;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Table;

class PortableSchemaTransformer
{

    /**
     * Helper function to build a table object that has the _quoted attribute set so that the SchemaManager
     * will use quoted identifiers when creating the final SQL statements. This is needed as Doctrine doesn't
     * provide a method to set the flag after the object has been instantiated and there's no possibility to
     * hook into the createSchema() method early enough to influence the original table object.
     */
    public static function transformTableSchema(Table $tableSchema, AbstractPlatform $databasePlatform)
    {
        return new $tableSchema(
            $databasePlatform->quoteIdentifier($tableSchema->getName()),
            \array_map(fn(Column $column) => self::buildQuotedColumn($column->setPlatformOptions([]), $databasePlatform), $tableSchema->getColumns()),
            \array_map(fn(Index $index) => self::buildQuotedIndex($index, $databasePlatform), $tableSchema->getIndexes()),
            [], // unique constraints are already part of indexes
            \array_map(fn(ForeignKeyConstraint $foreignKeyConstraint) => self::buildQuotedForeignKey($foreignKeyConstraint, $databasePlatform), $tableSchema->getForeignKeys()),
            // no table options here for portability
        );
    }

    /**
     * Helper function to build a column object that has the _quoted attribute set so that the SchemaManager
     * will use quoted identifiers when creating the final SQL statements. This is needed as Doctrine doesn't
     * provide a method to set the flag after the object has been instantiated and there's no possibility to
     * hook into the createSchema() method early enough to influence the original column object.
     */
    protected static function buildQuotedColumn(Column $column, AbstractPlatform $databasePlatform): Column
    {
        return new Column(
            $databasePlatform->quoteIdentifier($column->getName()),
            $column->getType(),
            $column->getPlatformOptions()
        );
    }

    /**
     * Helper function to build an index object that has the _quoted attribute set so that the SchemaManager
     * will use quoted identifiers when creating the final SQL statements. This is needed as Doctrine doesn't
     * provide a method to set the flag after the object has been instantiated and there's no possibility to
     * hook into the createSchema() method early enough to influence the original column object.
     */
    protected static function buildQuotedIndex(Index $index, AbstractPlatform $databasePlatform): Index
    {
        return new Index(
            $databasePlatform->quoteIdentifier($index->getName()),
            \array_map([$databasePlatform, 'quoteIdentifier'], $index->getColumns()),
            $index->isUnique(),
            $index->isPrimary(),
            $index->getFlags(),
            $index->getOptions()
        );
    }

    /**
     * Helper function to build a foreign key constraint object that has the _quoted attribute set so that the
     * SchemaManager will use quoted identifiers when creating the final SQL statements. This is needed as Doctrine
     * doesn't provide a method to set the flag after the object has been instantiated and there's no possibility to
     * hook into the createSchema() method early enough to influence the original column object.
     */
    protected static function buildQuotedForeignKey(ForeignKeyConstraint $index, AbstractPlatform $databasePlatform): ForeignKeyConstraint
    {
        return new ForeignKeyConstraint(
            \array_map([$databasePlatform, 'quoteIdentifier'], $index->getLocalColumns()),
            $databasePlatform->quoteIdentifier($index->getForeignTableName()),
            \array_map([$databasePlatform, 'quoteIdentifier'], $index->getForeignColumns()),
            $databasePlatform->quoteIdentifier($index->getName()),
            $index->getOptions()
        );
    }
}
