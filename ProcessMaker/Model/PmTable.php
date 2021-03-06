<?php

namespace ProcessMaker\Model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use PDOException;
use ProcessMaker\Facades\SchemaManager;
use Watson\Validating\ValidatingTrait;

/**
 * Represents an Eloquent model of pmTable
 * @package ProcessMaker\Model
 */
class PmTable extends Model
{
    use ValidatingTrait;

    // all tables will have this prefix
    const TABLE_PREFIX = 'PMT_';

    public static $attributesList = [
        'ADD_TAB_UID',
        'ADD_TAB_NAME',
        'ADD_TAB_DESCRIPTION',
        'ADD_TAB_PLG_UID',
        'DBS_UID',
        'PRO_UID',
        'ADD_TAB_TYPE',
        'ADD_TAB_GRID',
        'ADD_TAB_TAG'
    ];

    public $timestamps = false;
    public $incrementing = false;

    protected $table = 'ADDITIONAL_TABLES';
    protected $primaryKey = 'ADD_TAB_UID';

    //validation rules
    protected $rules = [
        'ADD_TAB_NAME' => 'required',
        'DBS_UID' => 'required',
    ];

    //stores the metadata of the columns and keys of the physical table
    private $tableMetadata = null;

    /**
     * Returns the name of the physical table
     *
     * @return string
     */
    public function physicalTableName()
    {
        return PmTable::TABLE_PREFIX . strtoupper($this->ADD_TAB_NAME);
    }

    /**
     * Returns all the data rows of the physical table
     *
     * @return array
     */
    public function allDataRows()
    {
        $data = DB::table($this->physicalTableName())->get();

        $result = [];
        foreach ($data->all() as $element) {
            $result[] = (array)$element;
        }

        return $result;
    }

    /**
     * Add a data row to the physical table
     *
     * @param array $dataRow
     *
     * @return mixed
     */
    public function addDataRow(array $dataRow)
    {
        return DB::table($this->physicalTableName())->insert($dataRow);
    }

    /**
     * Updates a row in the physical table. The table should have a primary key
     *
     * @param array $dataRow
     *
     * @return array
     */
    public function updateDataRow(array $dataRow)
    {
        $pkColumn = $this->getTableMetadata()->primaryKeyColumns;

        if (empty($pkColumn)) {
            throw new PDOException('The table should have a primary key to update its data');
        }

        foreach ($dataRow as $column => $value) {
            if ($column === $pkColumn[0]) {
                DB::table($this->physicalTableName())
                    ->where($column, $value)
                    ->update($dataRow);
            }
        }

        return (array)DB::table($this->physicalTableName())
            ->where($pkColumn[0], $dataRow[$pkColumn[0]])
            ->first();
    }

    /**
     * Deletes a row in the physical table
     *
     * @param array $keys
     *
     * @return null
     */
    public function deleteDataRow(array $keys)
    {
        $qry = null;
        foreach ($keys as $column => $value) {
            if ($qry === null) {
                $qry = DB::table($this->physicalTableName())
                    ->where($column, $value);
            } else {
                $qry = $qry->where($column, $value);
            }
        }
        $qry->delete();
    }

    /**
     * Getter that reads the metadata of the physical table
     *
     * @return null|\stdClass
     */
    public function getTableMetadata()
    {
        return SchemaManager::getMetadataFromSchema($this);
    }
}
