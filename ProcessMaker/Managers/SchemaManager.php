<?php

namespace ProcessMaker\Managers;

use Doctrine\DBAL\Platforms\MySqlPlatform;
use Doctrine\DBAL\Types\Type;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ProcessMaker\Model\PmTable;
use ProcessMaker\Model\ProcessVariable;
use stdClass;

class SchemaManager
{
    /**
     * Returns the Doctrine/Eloquent type that corresponds to a MySql type
     *
     * @param string $databaseType
     *
     * @return string
     */
    public function schemaType($databaseType)
    {
        $dbalPlatform = new MySqlPlatform();
        return $dbalPlatform->getDoctrineTypeMapping($databaseType);
    }

    /**
     * Determines if a MySql type has a size in the doctrine\dbal definition
     *
     * @param string $databaseType
     *
     * @return bool
     */
    public function typeHasSizeAsParameter($databaseType)
    {
        $dbalPlatform = new MySqlPlatform();
        $dbalType = $this->schemaType($databaseType);
        $type = Type::getType($dbalType);
        return !is_null($type->getDefaultLength($dbalPlatform));
    }

    /**
     * Updates a column or creates it if it does not exist
     *
     * @param PmTable $pmTable
     * @param array $field
     *
     */
    public function updateOrCreateColumn(PmTable $pmTable, array $field)
    {
        $tableName = $pmTable->physicalTableName();
        $columnType = $this->schemaType($field['FLD_TYPE']);

        $existsColumn = Schema::hasColumn($tableName, $field['FLD_NAME']);
        $existsTable = Schema::hasTable($tableName);

        if ($existsTable && $existsColumn) {
            $this->changePhysicalColumn($pmTable, $field);
        }

        if ($existsTable && !$existsColumn) {
            $this->createPhysicalColumn($pmTable, $field);
        }

        if (!$existsTable && !$existsColumn) {
            $this->createPhysicalTableAndColumn($pmTable, $field);
        }

        // we add the nullable column property
        $canBeNull = isset($field['FLD_NULL']) && $field['FLD_NULL'] === 1;

        if (empty($field['FLD_SIZE']) || !$this->typeHasSizeAsParameter($field['FLD_TYPE'])) {
            Schema::table($tableName, function ($table) use ($columnType, $field, $canBeNull) {
                $table->{$columnType}($field['FLD_NAME'])
                    ->nullable($canBeNull)
                    ->change();
            });
        } else {
            Schema::table($tableName, function ($table) use ($columnType, $field, $canBeNull) {
                $table->{$columnType}($field['FLD_NAME'], $field['FLD_SIZE'])
                    ->nullable($canBeNull)
                    ->change();
            });
        }

        // we set the field as primary key
        if (isset($field['FLD_KEY']) && $field['FLD_KEY'] === 1) {
            Schema::table($tableName, function ($table) use ($tableName, $field) {
                if ($this->existsPrimaryKey($tableName)) {
                    $table->dropPrimary();
                }
                $table->primary($field['FLD_NAME']);
            });
        }

        // we add the autoincrement property
        if (isset($field['FLD_AUTO_INCREMENT']) && $field['FLD_AUTO_INCREMENT'] === 1) {
            Schema::table($tableName, function ($table) use ($tableName, $field) {
                if ($this->existsPrimaryKey($tableName)) {
                    $table->dropPrimary();
                }
            });

            Schema::table($tableName, function ($table) use ($field) {
                $table->dropColumn($field['FLD_NAME']);
            });

            Schema::table($tableName, function ($table) use ($field) {
                $table->increments($field['FLD_NAME']);
            });
        }
    }

    /**
     * Removes a column from a physical table
     *
     * @param PmTable $pmTable
     * @param string $columnName
     */
    public function dropColumn(PmTable $pmTable, $columnName)
    {
        Schema::table($pmTable->physicalTableName(), function ($table) use ($columnName) {
            $table->dropColumn($columnName);
        });
    }

    /**
     * Removes a physical table
     *
     * @param string $tableName
     */
    public function dropPhysicalTable($tableName)
    {
        Schema::dropIfExists($tableName);
    }

    /**
     * Sets the default values for fields of a report table
     *
     * @param array $field
     * @param ProcessVariable $variable
     * @return array
     */
    public function setDefaultsForReportTablesFields(array $field, ProcessVariable $variable)
    {
        $result = $field;

        if (!array_key_exists('FLD_TYPE', $result)) {
            $result['FLD_TYPE'] = $this->variableType2Mysql($variable->VAR_FIELD_TYPE);
        }

        if (!array_key_exists('FLD_SIZE', $result)) {
            $result['FLD_SIZE'] = $this->variableSize($variable->VAR_FIELD_TYPE);
        }

        //by default report tables allows nulls
        if (!array_key_exists('FLD_NULL', $result)) {
            $result['FLD_NULL'] = 1;
        }

        return $result;
    }

    /**
     * Returns the mysql type equivalente to a ProcessMaker process variable name
     *
     * @param string $varType
     * @return string
     */
    private function variableType2Mysql($varType)
    {
        switch ($varType) {
            case 'integer':
                $result = 'INTEGER';
                break;
            case 'float':
                $result = 'FLOAT';
                break;
            case 'boolean':
                $result = 'INTEGER';
                break;
            case 'datetime':
                $result = 'DATETIME';
                break;
            default:
                $result = 'VARCHAR';
        }
        return $result;
    }

    /**
     * Returns the default size to use for a certain mysql type
     *
     * @param string $varType
     * @return int|null
     */
    private function variableSize($varType)
    {
        return ($this->variableType2Mysql($varType) === 'VARCHAR')
            ? 250
            : null;
    }

    /**
     * Changes a column with the metadata specified in the $field array to the PmTable
     *
     * @param PmTable $pmTable
     * @param array $field
     */
    private function changePhysicalColumn(PmTable $pmTable, array $field)
    {
        $tableName = $pmTable->physicalTableName();
        $columnType = $this->schemaType($field['FLD_TYPE']);
        if (empty($field['FLD_SIZE']) || !$this->typeHasSizeAsParameter($field['FLD_TYPE'])) {
            Schema::table($tableName, function ($table) use ($columnType, $field) {
                $table->{$columnType}($field['FLD_NAME'])->change();
            });
        } else {
            Schema::table($tableName, function ($table) use ($columnType, $field) {
                $table->{$columnType}($field['FLD_NAME'], $field['FLD_SIZE'])->change();
            });
        }
    }

    /**
     * Adds a column with the metadata specified in the $field array to the PmTable
     *
     * @param PmTable $pmTable
     * @param array $field
     */
    private function createPhysicalColumn(PmTable $pmTable, array $field)
    {
        $tableName = $pmTable->physicalTableName();
        $columnType = $this->schemaType($field['FLD_TYPE']);
        if (empty($field['FLD_SIZE']) || !$this->typeHasSizeAsParameter($field['FLD_TYPE'])) {
            Schema::table($tableName, function ($table) use ($columnType, $field) {
                $table->{$columnType}($field['FLD_NAME']);
            });
        } else {
            Schema::table($tableName, function ($table) use ($columnType, $field) {
                $table->{$columnType}($field['FLD_NAME'], $field['FLD_SIZE']);
            });
        }
    }

    /**
     * Creates the physical table with a column specified in the $field array
     *
     * @param PmTable $pmTable
     * @param array $field
     */
    private function createPhysicalTableAndColumn(PmTable $pmTable, array $field)
    {
        $tableName = $pmTable->physicalTableName();
        $columnType = $this->schemaType($field['FLD_TYPE']);

        Schema::create($tableName, function ($table) use ($tableName, $columnType, $field) {
            if (empty($field['FLD_SIZE']) || !$this->typeHasSizeAsParameter($field['FLD_TYPE'])) {
                $table->{$columnType}($field['FLD_NAME']);
            } else {
                $table->{$columnType}($field['FLD_NAME'], $field['FLD_SIZE']);
            }
        });
    }

    /**
     *  Returns an array with the metadata of the columns of the physical table of a PmTable
     *
     * @param PmTable $pmTable
     *
     * @return stdClass
     */
    public function getMetadataFromSchema(PmTable $pmTable)
    {
        $tableMetadata = new stdClass();
        $tableMetadata->columns = [];
        $tableMetadata->primaryKeyColumns = [];
        $tableMetadata->hasPrimaryKey = false;

        $conn = DB::connection()->getDoctrineConnection();
        $sm = $conn->getSchemaManager();
        $columns = $sm->listTableColumns($pmTable->physicalTableName());
        $tableDetail = $sm->listTableDetails($pmTable->physicalTableName());

        // we get the metadata for each column of the table and map their values to PM formats
        foreach ($columns as $column) {
            $columnMeta = new stdClass();
            $columnMeta->FLD_NAME = $column->getName();
            $columnMeta->FLD_DESCRIPTION = $column->getComment();
            $columnMeta->FLD_TYPE = $column->getType()->getName();
            $columnMeta->FLD_SIZE = $column->getLength();
            $columnMeta->FLD_NULL = $column->getNotnull() ? 0 : 1;
            $columnMeta->FLD_AUTO_INCREMENT = $column->getAutoincrement() ? 1 : 0;
            $columnMeta->FLD_TABLE_INDEX = $this->columnHasIndex($column->getName(), $tableDetail->getIndexes()) ? 1 : 0;
            $columnMeta->FLD_KEY = false;
            if ($tableDetail->hasPrimaryKey()) {
                $columnMeta->FLD_KEY = in_array($column->getName(), $tableDetail->getPrimaryKeyColumns());
            }
            $tableMetadata->columns[] = $columnMeta;

            $tableMetadata->hasPrimaryKey = $tableDetail->hasPrimaryKey();
        }
        if ($tableDetail->hasPrimaryKey()) {
            $tableMetadata->primaryKeyColumns[] = $tableDetail->getPrimaryKeyColumns()[0];
        }
        return $tableMetadata;
    }

    /**
     * Determines if the table has a primary key
     *
     * @param string $tableName
     *
     * @return bool
     */
    private function existsPrimaryKey($tableName)
    {
        $sm = Schema::getConnection()->getDoctrineSchemaManager();
        $indexesFound = $sm->listTableIndexes($tableName);
        return array_key_exists('primary', $indexesFound);
    }

    /**
     * Determines if the column has an index in the $indexes array
     *
     * @param string $columnName
     * @param array $indexes
     *
     * @return bool
     */
    private function columnHasIndex($columnName, array $indexes)
    {
        foreach ($indexes as $index) {
            if (in_array($columnName, $index->getColumns())) {
                return true;
            }
        }
        return false;
    }
}
