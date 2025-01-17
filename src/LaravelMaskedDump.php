<?php

namespace FenixDumper\LaravelMaskedDumper;

use Doctrine\DBAL\Schema\Schema;
use FenixDumper\LaravelMaskedDumper\TableDefinitions\TableDefinition;
use Illuminate\Console\OutputStyle;

class LaravelMaskedDump
{
    protected DumpSchema $definition;

    protected OutputStyle $output;

    protected array $tablesWithDisableConstrain = [];

    public function __construct(DumpSchema $definition, OutputStyle $output)
    {
        $this->definition = $definition;
        $this->output = $output;
    }

    public function dump(): string
    {
        $tables = $this->definition->getDumpTables();

        $query = '';

        $overallTableProgress = $this->output->createProgressBar(count($tables));

        if ($this->definition->isDisableAllConstrains()) {
            $query .= $this->disableAllConstraints();
        }

        foreach ($tables as $tableName => $table) {
            $query .= "DROP TABLE IF EXISTS `$tableName`;".PHP_EOL;

            $query .= $this->dumpSchema($table);

            if ($table->shouldDumpData()) {
                $query .= $this->lockTable($tableName);

                if (! $table->isConstrain()) {
                    $query .= $this->disableConstraintsTable($tableName);
                }

                $query .= $this->dumpTableData($table);

                $query .= $this->unlockTable($tableName);
            }

            $overallTableProgress->advance();
        }

        if ($this->tablesWithDisableConstrain) {
            foreach ($this->tablesWithDisableConstrain as $tableName) {
                $query .= $this->enableConstraintsTable($tableName);
            }
        }

        if ($this->definition->isDisableAllConstrains()) {
            $query .= $this->enableAllConstraints();
        }

        if ($this->tablesWithDisableConstrain || $this->definition->isDisableAllConstrains()) {
            $overallTableProgress->advance();
        }

        return $query;
    }

    protected function transformResultForInsert($row, TableDefinition $table): array
    {
        $connection = $this->definition->getConnection()->getDoctrineConnection();

        return collect($row)->map(function ($value, $column) use ($row, $connection, $table) {
            if (($columnDefinition = $table->findColumn($column)) && ! $table->isIgnored($row)) {
                $value = $columnDefinition->modifyValue($value, $row);
            }

            return is_null($value) ? 'NULL' : ($value === '' ? '""' : $connection->quote($value));
        })->toArray();
    }

    protected function dumpSchema(TableDefinition $table): string
    {
        $platform = $this->definition->getConnection()->getDoctrineSchemaManager()->getDatabasePlatform();

        $schema = new Schema([$table->getDoctrineTable()]);

        return implode(";", $schema->toSql($platform)).";".PHP_EOL;
    }

    protected function disableAllConstraints(): string
    {
        return "SET FOREIGN_KEY_CHECKS=0;".PHP_EOL;
    }

    protected function enableAllConstraints(): string
    {
        return "SET FOREIGN_KEY_CHECKS=1;".PHP_EOL;
    }

    protected function disableConstraintsTable(string $tableName): string
    {
        $this->tablesWithDisableConstrain[] = $tableName;

        return "ALTER TABLE `$tableName` NOCHECK CONSTRAINT ALL;".PHP_EOL;
    }

    protected function enableConstraintsTable(string $tableName): string
    {
        return "ALTER TABLE `$tableName` WITH CHECK CHECK CONSTRAINT ALL;".PHP_EOL;
    }

    protected function lockTable(string $tableName): string
    {
        return "LOCK TABLES `$tableName` WRITE;".PHP_EOL."ALTER TABLE `$tableName` DISABLE KEYS;".PHP_EOL;
    }

    protected function unlockTable(string $tableName): string
    {
        return "ALTER TABLE `$tableName` ENABLE KEYS;".PHP_EOL."UNLOCK TABLES;".PHP_EOL;
    }

    protected function dumpTableData(TableDefinition $table)
    {
        $query = '';

        $queryBuilder = $this->definition->getConnection()
            ->table($table->getDoctrineTable()->getName());

        $table->modifyQuery($queryBuilder);

        $queryBuilder->get()
            ->each(function ($row, $index) use ($queryBuilder, $table, &$query) {
                $row = $this->transformResultForInsert((array) $row, $table);
                $tableName = $table->getDoctrineTable()->getName();

                $query .= "INSERT INTO `${tableName}` (`".implode('`, `', array_keys($row)).'`) VALUES ';
                $query .= "(";

                $firstColumn = true;

                foreach ($row as $value) {
                    if (! $firstColumn) {
                        $query .= ", ";
                    }

                    $query .= $value;
                    $firstColumn = false;
                }

                $query .= ");".PHP_EOL;
            });

        return $query;
    }
}
