<?php

namespace Yaseen\PackGen\Services;

use Yaseen\PackGen\Protocols\DataHolder;
use function Laravel\Prompts\warning;
use function Laravel\Prompts\error;
use function Laravel\Prompts\text;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Yaseen\PackGen\Protocols\DataGenerator;

 class DataGeneratorImp implements DataGenerator
{

    public function __construct(
        protected DataHolder $dataHolder,
        protected Filesystem $fs,
        public ?string $schemaFile = null,
        public bool $tableExists = false,
    ){}
    
    
    /**
     * Check if table exist or ask for schema file.
     */
    public function validate($tableName) : bool {
        $this->tableExists = Schema::hasTable($tableName);
        if (! $this->tableExists) {
            warning("`{$tableName}` table doesn't exist");
        }

        $schemaFile = $this->schemaFolder().$tableName.'.json';
        if($this->fs->exists($schemaFile)){
            info("Using fields from schema: $schemaFile");
            $this->schemaFile = $schemaFile;
        } elseif(!$this->tableExists){
            $this->schemaFile = text('Enter schema file path', '/');
            if ($this->schemaFile == null || !$this->fs->exists($this->schemaFile)) {
                error("File not found, Can't proceed without a Database Table or a Schema File");
                return false;
            }
        }

        return true;
    }
    
    
    protected function schemaFolder() : string {
        return config('packgen.schemas_folder', resource_path('packgen_schemas/'));
    }
    
 
    /**
     * Generate Model fields.
     */
    public function generateFields($tableName)
    {
        $this->dataHolder->tableName = $tableName;
        if($this->schemaFile){
            $this->generateFieldsFromSchema();
        }
        if($this->tableExists){
            $this->generateFieldsFromDb();
        }
    }

    /**
     * Generate Model fields from schema file.
     */
    protected function generateFieldsFromSchema()
    {
        info("Using fields from schema: {$this->schemaFile}");
        $fileContents = $this->fs->get($this->schemaFile); //file_get_contents($this->schemaFile);
        $jsonData = json_decode($fileContents, true);
        $fields = $jsonData['fields']; //array_merge_recursive($this->dataHolder->fields, $jsonData['fields']);
        
        foreach($fields as &$field) {
            if(!isset($field['name'])){
                throw new \Exception("Some fields doesn't have a name, File: {$this->schemaFile}");
            }
            $unfillable = in_array($field['name'], $this->dataHolder->unfillable);
            $hidden = in_array($field['name'], $this->dataHolder->hidden);
            $field['searchable'] ??= $field['searchable'] ?? !$hidden;
            $field['editable'] ??= $field['editable'] ?? !$unfillable;
            $field['inView'] ??= $field['inView'] ?? !$hidden;
            $field['inGrid'] ??= $field['inGrid'] ?? !$hidden;
        }
        $this->dataHolder->fields = collect($fields);
    }
    
    /**
     * Generate Model fields from db table.
     */
    protected function generateFieldsFromDb()
    {
        info("Using fields from table: {$this->dataHolder->tableName}");
        $tableColumns = Schema::getColumns($this->dataHolder->tableName);
        $this->dataHolder->fields = $this->mergeColumnsToFields( $tableColumns );
        return $this;
    }


    /**
     * merge local schema with DB table columns
     */
    protected function mergeColumnsToFields(array $tableColumns) : Collection {
        $fields = collect([]);
        $dbToHtml = fn($type) => match($type){
            'int', 'tinyint', 'bigint'                  => 'number',
            'char', 'varchar'                           => 'text',
            'text',                                     => 'textarea',
            'bool'                                      => 'checkbox',
            'date', 'datetime', 'timestamps'            => 'date',
            default => 'text'
        };
        $dbToCast = fn($type) => match($type){
            'json'                                      => 'array',
            'bool'                                      => 'boolean',
            'date', 'datetime', 'timestamps'            => 'date',
            default => null
        };

        foreach ($tableColumns as $column) {
            $field = ($this->dataHolder->fields ?? collect([]))->firstWhere('name', $column['name'])  ?? [];

            $unfillable = in_array($column['name'], $this->dataHolder->unfillable);
            $hidden = in_array($column['name'], $this->dataHolder->hidden);
            
            // fill absent keys
            $field['name'] ??= $column['name'];
            $field['dbType'] ??= $column['type_name'];
            $field['inputType'] ??= $dbToHtml($field['dbType']);
            $field['runtimeType'] ??= $dbToCast($field['dbType']);
            $field['searchable'] ??= $field['searchable'] ?? !$hidden;
            $field['editable'] ??= $field['editable'] ?? !$unfillable;
            $field['inView'] ??= $field['inView'] ?? !$hidden;
            $field['inGrid'] ??= $field['inView'] ?? !$hidden;
            
            // anticipating rules from column info
            $rules = $field['rules'] ?? [];

            if (!isset($rules['required']) && !$column['nullable']) {
                $rules[] = 'required';
            }
            if (!isset($rules['boolean']) && $column['type_name'] == 'bool') {
                $rules[] = 'boolean';
            }
            if (!isset($rules['uuid']) && $column['type_name'] == 'uuid') {
                $rules[] = 'uuid';
            }
            if (!isset($rules['uuid']) && ($column['type_name'] == 'text' || $column['type_name'] == 'varchar')) {
                $rules[] = 'string';
            }
            $field['rules'] = $rules;
            
            
            $fields->push($field);
        }
        return $fields;
    }

}