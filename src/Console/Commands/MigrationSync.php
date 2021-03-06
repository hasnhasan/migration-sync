<?php

namespace HasnHasan\MigrationSync\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class MigrationSync extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'migrate:sync';
    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migration Database SYNC';
    protected $debug = false;
    protected $sqlDump = [];
    protected $excludeTables = [];
    protected $excludeFields = [];
    protected $onlyIncludeTables = [];
    protected $outputFolder = '';
    private $tableOrders = []; // TODO:: Order

    /**
     * MigrationSync constructor.
     */
    public function __construct()
    {
        parent::__construct();
        // Set Config
        $this->excludeTables     = config('migration-sync.excludeTables', []);
        $this->excludeFields     = config('migration-sync.excludeFields', []);
        $this->onlyIncludeTables = config('migration-sync.onlyIncludeTables', []);
        $this->outputFolder      = config('migration-sync.outputFolder', 'database/migrations/');

    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        if (!config('migration-sync.connection.database')) {
            $this->error('In ENV, "DB_DATABASE_SYNC" is attached. You must create a database and specify it in the env file.');

            return;
        }
        // Set Sync Database Connection
        $defaultConnection       = config('database.default');
        $defaultConnectionConfig = config('database.connections.'.$defaultConnection);
        $connectionConfig        = array_merge($defaultConnectionConfig, config('migration-sync.connection'));
        $syncDb                  = 'dbSync';
        Config::set('database.connections.'.$syncDb, $connectionConfig);

        // Telescope migration fix
        if (config('telescope.storage.database.connection', false)) {
            config(['telescope.storage.database.connection' => $syncDb]);
        }

        // Sync Db Clear and migrate
        Artisan::call('migrate:fresh', ['--force' => true, '--database' => $syncDb]);

        // Get Sync DB
        $migrationNowDatas = $this->generateMigration($syncDb);

        // Get Default DB
        $migrationLiveDatas = $this->generateMigration($defaultConnection);

        $diffDatas    = [];
        $foreingDatas = [];
        if (!$migrationLiveDatas) {
            foreach ($migrationNowDatas as $table => $datas) {
                if ($table == '#foreigners#') {
                    $foreingDatas = $datas;
                    continue;
                }
                [$createData, $dropData] = $migrationNowDatas[$table];
                $create = collect($createData)->toArray();
                $drop   = collect($dropData)->toArray();

                $diffData                    = [$create, $drop];
                $diffDatas[$table]['create'] = $diffData;
            }
            $tableOrder = isset($this->tableOrders[$syncDb]) ? $this->tableOrders[$syncDb] : [];
            $order = $this->tableCreateSort(array_keys($migrationNowDatas), $tableOrder);

        } else {

            foreach ($migrationLiveDatas as $table => $datas) {

                if ($table == '#foreigners#') {
                    $foreingDatas = $datas;
                    continue;
                }

                [$create, $drop] = $datas;

                $type = 'create'; // New Table

                $diffData = $datas;
                // existing table
                if (isset($migrationNowDatas[$table])) {
                    $type = 'table';
                    [$createData, $dropData] = $migrationNowDatas[$table];
                    $create = collect($create)->diff(collect($createData))->toArray();
                    $drop   = collect($drop)->diff(collect($dropData))->toArray();

                    $diffData = [$create, $drop];
                }

                // If Table Diff
                if ($diffData[0] || $diffData[1]) {
                    $diffDatas[$table][$type] = $diffData;
                }

            }
            $tableOrder = isset($this->tableOrders[$defaultConnection]) ? $this->tableOrders[$defaultConnection] : [];
            $order = $this->tableCreateSort(array_keys($migrationLiveDatas), $tableOrder);
        }

        $diffDatas = collect($diffDatas)->sortBy(function ($datas, $table) use ($order) {
            return array_search($table, $order);
        })->toArray();

        // Table Diff  process
        $i = 100000;
        foreach ($diffDatas as $tableName => $diffData) {
            $type = current(array_keys($diffData));
            [$up, $down] = current(array_values($diffData));
            $tableSchemaCode = [
                'up'   => $up,
                'down' => $down,
            ];

            $foreingSchema = [
                'up'   => '',
                'down' => '',
            ];

            if (isset($foreingDatas['up'][$tableName])) {
                $foreingSchema = [
                    'up'   => $foreingDatas['up'][$tableName],
                    'down' => $foreingDatas['down'][$tableName],
                ];
            }

            $this->createMigrationFile($type, $tableName, $tableSchemaCode, $foreingSchema, $i);
            $i++;
        }

    }

    /**
     * @param $type
     * @param $tableName
     * @param $tableSchemaCode
     * @param $foreingSchema
     * @param  int  $i
     */
    private function createMigrationFile($type, $tableName, $tableSchemaCode, $foreingSchema, $i = 10000)
    {
        // Table
        $upSchema     = implode("\n        ", $tableSchemaCode['up']);
        $upSchemaCode = "  Schema::$type('$tableName', function (Blueprint \$table) {
            $upSchema
          });";

        $downSchema = implode("\n        ", $tableSchemaCode['down']);
        if ($type == 'create') {
            $downSchemaCode = "  Schema::dropIfExists('$tableName');";
        } else {
            $downSchemaCode = "  Schema::$type('$tableName', function (Blueprint \$table) {
            $downSchema
          });";
        }

        // Foreing
        $upForeingSchema   = '';
        $downForeingSchema = '';

        /*if (isset($foreingSchema['up']) && $foreingSchema['up']) {
            $upForeingCode   = implode("\n        ", $foreingSchema['up']);
            $upForeingSchema = "  Schema::table('$tableName', function (Blueprint \$table) {
            $upForeingCode
          });";
        }


        if (isset($foreingSchema['down']) && $foreingSchema['down']) {
            $downForeingCode   = implode("\n        ", $foreingSchema['down']);
            $downForeingSchema = "  Schema::table('$tableName', function (Blueprint \$table) {
            $downForeingCode
          });";
        }*/

        //Class Name
        $name      = str_replace(' ', '', str_replace('_', ' ', Str::title($tableName)));
        $className = sprintf("%s%sTable", ucfirst($type), $name);

        $template = [
            '{{className}}'         => $className,
            '{{upSchema}}'          => $upSchemaCode,
            '{{upForeingSchema}}'   => $upForeingSchema,
            '{{downSchema}}'        => $downSchemaCode,
            '{{downForeingSchema}}' => $downForeingSchema,
        ];

        $outputFileName = $this->setStub($tableName, $type, $template, $i);
        $this->info("File: $outputFileName");
    }

    /**
     * @param $connectionName
     * @return array
     */
    private function generateMigration($connectionName)
    {
        $database = config('database.connections.'.$connectionName.'.database');

        /* check connection */
        try {
            DB::connection($connectionName)->getPdo();
        } catch (\Exception $e) {
            $this->error('Connect failed:'.$e->getMessage());

            return [];
        }

        $tables = DB::connection($connectionName)->table('information_schema.tables')
            ->select(['TABLE_SCHEMA', 'TABLE_NAME', 'TABLE_TYPE', 'TABLE_COMMENT'])
            ->where('TABLE_SCHEMA', $database)
            ->orderBy('CREATE_TIME')
            ->get()
            ->toArray();

        $tableNames = array_map(function ($table) {
            return $table->TABLE_NAME;
        }, $tables);

        if (count($this->onlyIncludeTables) > 0) {
            $tableNames = array_filter($tableNames, function ($tableName) use ($tableNames) {
                return in_array($tableName, $this->onlyIncludeTables);
            });
        } else {
            $tableNames = array_filter($tableNames, function ($tableName) use ($tableNames) {
                return !in_array($tableName, $this->excludeTables);
            });
        }

        $fieldTypeNameMappings = config('migration-sync.fieldTypeNameMappings', []);
        $nullableFieldTypes    = config('migration-sync.nullableFieldTypes', []);
        $filterFieldTypeParams = config('migration-sync.filterFieldTypeParams', []);
        $filterFieldTypeParams = config('migration-sync.filterFieldTypeParams', []);
        $arrayFieldsTypes      = config('migration-sync.arrayFieldsTypes', []);

        $response            = [];
        $sql                 = [];
        $foreignersUpCodes   = [];
        $foreignersDownCodes = [];
        foreach ($tableNames as $tableName) {

            $fullColumns          = DB::connection($connectionName)->select("SHOW FULL COLUMNS FROM `$tableName`");
            $tableDropSchemaCodes = [];
            $tableSchemaCodes     = [];
            $fields               = [];
            if ($fullColumns) {
                // Get Fields
                foreach ($fullColumns as $column) {
                    $field      = $column->Field;
                    $field_type = $column->Type;
                    $collation  = $column->Collation;
                    $null       = $column->Null;
                    $key        = $column->Key;
                    $default    = $column->Default;
                    $extra      = $column->Extra;
                    $comment    = $column->Comment;
                    $fields[]   = $field;
                    if (in_array($field, $this->excludeFields)) {
                        continue;
                    }

                    $fieldTypeSplit = explode('(', $field_type);
                    $fieldTypeName  = $fieldTypeSplit[0];
                    $fieldTypeName  = strtolower($fieldTypeName);
                    $fieldTypeName  = array_key_exists($fieldTypeName,
                        $fieldTypeNameMappings) ? $fieldTypeNameMappings[$fieldTypeName] : $fieldTypeName;

                    $fieldTypeSettings     = count($fieldTypeSplit) > 1 ? explode(' ', $fieldTypeSplit[1]) : [];
                    $fieldTypeParamsString = count($fieldTypeSplit) > 1 ? explode(')', $fieldTypeSplit[1])[0] : '';
                    $fieldTypeParams       = $fieldTypeParamsString != '' ? explode(',', $fieldTypeParamsString) : [];

                    $fieldTypeParams = array_key_exists($fieldTypeName,
                        $filterFieldTypeParams) ? $filterFieldTypeParams[$fieldTypeName]($fieldTypeParams) : $fieldTypeParams;

                    if ($extra == 'auto_increment') {
                        if ($fieldTypeName == 'bigInteger') {
                            $fieldTypeName = 'bigIncrements';
                        } else {
                            $fieldTypeName = 'increments';
                        }
                    }

                    $appends = [];
                    if ($null == 'YES' /*&& in_array($fieldTypeName, $nullableFieldTypes)*/) {
                        $appends [] = '->nullable()';
                    }
                    if (in_array('unsigned', $fieldTypeSettings) && $fieldTypeName != 'increments') {
                        $appends [] = '->unsigned()';
                    }
                    if (!is_null($default)) {
                        if ($default == 'CURRENT_TIMESTAMP') {
                            $appends [] = sprintf("->default(\DB::raw('%s'))", $default);
                        } else {
                            $appends [] = sprintf("->default('%s')", $default);
                        }
                    }
                    if ($comment) {
                        $appends [] = "->comment('{$comment}')";
                    }

                    if (in_array($fieldTypeName, $arrayFieldsTypes)) {
                        $fieldTypeParams = ['['.implode(',', $fieldTypeParams).']'];
                    }
                    $migrationParams = array_merge([sprintf("'%s'", $field)], $fieldTypeParams);
                    $migrationParams = array_filter($migrationParams, function ($param) {
                        return trim($param) != "";
                    });
                    $migrationParams = implode(", ", $migrationParams);

                    $appends         = implode("", $appends);
                    $tableSchemaCode = sprintf("    \$table->%s(%s)%s;", $fieldTypeName, $migrationParams, $appends);
                    $this->debug and $tableSchemaCodes[] = "// ".json_encode($column);
                    $tableSchemaCodes[]     = $tableSchemaCode;
                    $tableDropSchemaCodes[] = sprintf("    \$table->dropColumn('%s');", $field);
                };
            }

            // timestamps control
            if (in_array('created_at', $fields) && in_array('updated_at', $fields)) {
                $tableSchemaCodes[] = '    $table->timestamps();';
            } else {
                if (in_array('created_at', $fields)) {
                    $tableSchemaCodes[] = '    $table->timestamp("created_at");';
                }
                if (in_array('updated_at', $fields)) {
                    $tableSchemaCodes[] = '    $table->timestamp("updated_at");';
                }
            }
            if (in_array('deleted_at', $fields)) {
                $tableSchemaCodes[] = '    $table->softDeletes();';
            }

            // Get indexes
            $indexes   = [];
            $indexData = DB::connection($connectionName)->select("SHOW INDEX FROM `$tableName`");
            foreach ($indexData as $row) {
                if ($row->Key_name != 'PRIMARY') {
                    $indexes[$row->Key_name]['is_unique'] = $row->Non_unique ? false : true;
                    $indexes[$row->Key_name]['keys'][]    = $row->Column_name;
                }
            }
            if (!empty($indexes)) {
                $tableSchemaCodes[] = PHP_EOL;
                foreach ($indexes as $indexName => $index) {
                    $tableSchemaCodes[] = '    $table->'.($index['is_unique'] ? 'unique' : 'index').'(["'.implode('", "',
                            $index['keys']).'"],"'.$indexName.'");';
                }
                $tableSchemaCodes[] = PHP_EOL;
            }

            // Get foreign
            $foreigners = DB::connection($connectionName)->select("SELECT tb1.CONSTRAINT_NAME,tb1.COLUMN_NAME,tb1.REFERENCED_TABLE_NAME,tb1.REFERENCED_COLUMN_NAME,tb2.UPDATE_RULE,tb2.DELETE_RULE FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE as tb1 INNER JOIN information_schema.REFERENTIAL_CONSTRAINTS AS tb2 ON tb1.CONSTRAINT_NAME = tb2.CONSTRAINT_NAME WHERE `tb1`.`TABLE_SCHEMA` = '$database'  AND `tb1`.`TABLE_NAME` = '$tableName'  AND `tb1`.`REFERENCED_TABLE_NAME` IS NOT NULL");

            foreach ($foreigners as $row) {

                $tableSchemaCodes[$row->CONSTRAINT_NAME] = '    $table->foreign("'.$row->COLUMN_NAME.'","'.$row->CONSTRAINT_NAME.'")
                ->references("'.$row->REFERENCED_COLUMN_NAME.'")
                ->on("'.$row->REFERENCED_TABLE_NAME.'")
                ->onDelete("'.$row->DELETE_RULE.'")
                ->onUpdate("'.$row->UPDATE_RULE.'");
                
                ';
                $tableDropSchemaCodes[]                  = '    $table->dropForeign("'.$row->CONSTRAINT_NAME.'");';
                if (!isset($this->tableOrders[$connectionName][$row->REFERENCED_TABLE_NAME])) {
                    $this->tableOrders[$connectionName][$row->REFERENCED_TABLE_NAME] = [];
                }
                $this->tableOrders[$connectionName][$row->REFERENCED_TABLE_NAME][$tableName] = 1;

                /*$foreignersUpCodes[$tableName][$row->CONSTRAINT_NAME] = '$table->foreign("'.$row->COLUMN_NAME.'","'.$row->CONSTRAINT_NAME.'")
                ->references("'.$row->REFERENCED_COLUMN_NAME.'")->on("'.$row->REFERENCED_TABLE_NAME.'")
                ->onDelete("'.$row->DELETE_RULE.'")
                ->onUpdate("'.$row->UPDATE_RULE.'");
                ';

                $foreignersDownCodes[$tableName][$row->CONSTRAINT_NAME] = '    $table->dropForeign("'.$row->CONSTRAINT_NAME.'");';*/

            }

            // Debug Create Sql
            if ($this->debug) {
                $results = DB::connection($connectionName)->select("SHOW CREATE TABLE `$tableName`");
                $sqlTmp  = [];
                foreach ($results as $row) {
                    $sqlTmp[] = $row->{'Create Table'};
                }
                $sql[$tableName] = implode('\n', $sqlTmp);
            }

            $response[$tableName] = [$tableSchemaCodes, $tableDropSchemaCodes];
        }

        $this->sqlDump = $sql;

        $response['#foreigners#'] = [
            'up'   => $foreignersUpCodes,
            'down' => $foreignersDownCodes,
        ];

        return $response;
    }

    /**
     * @param $table
     * @param $foreingList
     * @return array
     */
    function xWhatForTable($table, $foreingList)
    {

        if (isset($foreingList[$table])) {
            return $foreingList[$table];
        }

        return [];

    }

    /**
     * @param $allTable
     * @param  array  $foreingList
     * @return array
     */
    private function tableCreateSort($allTable, $foreingList = [])
    {
        $order = [];
        foreach ($allTable as $table) {
            $order[$table] = 0;
        }

        foreach ($allTable as $table) {
            $tables = $this->xWhatForTable($table, $foreingList);
            if ($tables) {
                foreach ($tables as $x => $a) {
                    $order[$x]--;
                }
            } else {
                $order[$table]++;
            }

        }

        arsort($order);

        return array_keys($order);
    }

    /**
     * @param $type
     * @return false|string
     */
    protected function getStub($type = 'Migration')
    {

        $path = __DIR__."/../../Stubs/$type.stub";

        return File::get($path);
    }

    /**
     * @param $tableName
     * @param $type
     * @param $template
     * @param $i
     */
    protected function setStub($tableName, $type, $template, $i)
    {
        $code = str_replace(array_keys($template), array_values($template), $this->getStub());

        $fileName = sprintf("%s/%s_".$type."_%s_table.php", $this->outputFolder, date('Y_m_d_'.$i), $tableName);
        File::isDirectory($this->outputFolder) or File::makeDirectory($this->outputFolder);
        File::put($fileName, $code);

        return $fileName;
    }
}
