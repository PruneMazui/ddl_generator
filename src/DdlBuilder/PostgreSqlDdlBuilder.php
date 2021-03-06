<?php
namespace PruneMazui\DdlGenerator\DdlBuilder;

use PruneMazui\DdlGenerator\Definition\Definition;
use PruneMazui\DdlGenerator\DdlGeneratorException;
use PruneMazui\DdlGenerator\Definition\Rules\Table;
use PruneMazui\DdlGenerator\Definition\Rules\Schema;
use PruneMazui\DdlGenerator\Definition\Rules\ForeignKey;
use PruneMazui\DdlGenerator\Definition\Rules\Index;

/**
 * DDL for PostgreSQL
 *
 * @author ko_tanaka
 */
class PostgreSqlDdlBuilder extends AbstractDdlBuilder
{
    protected static $defaultConfig = [
        'add_empty_string'  => true, // if colmn's data type is (var)char and required, empty string is added to the default value
        'end_of_line'       => "\n",
        'indent'            => "    ",
        'format'            => "UTF-8",
    ];

    protected static $numericTypeMap = [
        'INT', // INTEGER INT SMALLINT TINYINT MEDIUMINT BIGINT
        'DEC', 'FIXED', 'NUMERIC', 'FIXED', // DECIMAL alias
        'BIT', 'BOOL', // TINYINT(1)
        'FLOAT', 'DOUBLE', 'REAL',
    ];

    /**
     * Quote identifier for PgSQL
     * @param string $field_name
     * @return string
     */
    private function quoteIdentifier($field_name)
    {
        $field_name = str_replace('"', '""', $field_name);

        return '"' . $field_name . '"';
    }

    private function createIdentifier($schema, $table)
    {
        $ret = (String)$schema;
        if(strlen($ret)) {
            $ret = $this->quoteIdentifier($ret) . '.';
        }

        return $ret . $this->quoteIdentifier((String) $table);
    }

    private function buildColumnsComment(Schema $schema, Table $table)
    {
        $eol = $this->getConfig('end_of_line');
        $sql = '';

        foreach($table->getColumns() as $column) {
            $comment = $column->getComment();
            if(! strlen($comment)) {
                continue;
            }

            $sql .= 'COMMENT ON COLUMN '
                . $this->createIdentifier($schema, $table)
                . '.' . $this->quoteIdentifier($column->getColumnName())
                . ' IS ' . $this->quoteString($comment) . ';' . $eol;
        }

        return $sql;
    }

    private function buildTableColumns(Table $table)
    {
        $addEmptyString = $this->getConfig('add_empty_string');
        $eol = $this->getConfig('end_of_line');
        $indent = $this->getConfig('indent');

        $lines = [];
        foreach($table->getColumns() as $column) {
            $sql = '';
            $sql .= $indent . $this->quoteIdentifier($column->getColumnName()) . ' ';

            $data_type = $column->getDataType();

            if($column->isAutoIncrement()) {
                if(strpos("SERIAL", strtoupper($data_type)) !== false) {
                    // noop
                }
                else if(! $this->isNumericType($data_type)) {
                    throw new DdlGeneratorException("datatype `{$data_type}` is not support auto_increment");
                }
                else {
                    // @todo convert data type
                    $data_type = "SERIAL";
                }
            }


            $sql .= $data_type;

            $length = $column->getLength();
            if(strlen($length)) {
                $sql .= '(' . $length . ')';
            }

            if($column->isRequired()) {
                $sql .= ' NOT NULL';
            }

            $default = $column->getDefault();
            if(strlen($default)) {
                if($this->isNumericType($data_type)) {
                    $sql .= ' DEFAULT ' . $default;
                } else {
                    $sql .= ' DEFAULT ' . $this->quoteString($default);
                }
            } else if($addEmptyString && strpos(strtoupper($data_type), 'CHAR') !== false) {
                $sql .= " DEFAULT ''";
            }

            $lines[] = $sql;
        }

        return implode(',' . $eol, $lines);
    }

    /**
     *
     * @param string $data_type
     * @return bool
     */
    public function isNumericType($data_type)
    {
        $data_type = strtoupper($data_type);

        foreach(self::$numericTypeMap as $word) {
            if(strpos($data_type, $word) !== false) {
                return true;
            }
        }

        return false;
    }


    /**
     * {@inheritDoc}
     * @see \PruneMazui\DdlGenerator\DdlBuilder\DdlBuilderInterface::buildCreateTable()
     */
    public function buildCreateTable(Schema $schema, Table $table)
    {
        $eol = $this->getConfig('end_of_line');
        $indent = $this->getConfig('indent');

        $sql  = 'CREATE TABLE ' . $this->createIdentifier($schema, $table) . ' (' . $eol;
        $sql .= $this->buildTableColumns($table);

        $primary_key = $table->getPrimaryKey();
        if(count($primary_key)) {

            foreach($primary_key as $key => $value) {
                $primary_key[$key] = $this->quoteIdentifier($value);
            }

            $sql .= ',' . $eol . $eol . $indent . "PRIMARY KEY (" . implode(', ', $primary_key) . ')' . $eol;
        }

        $sql .= ');' . $eol;


        // comment
        $comment = $table->getComment();
        if(strlen($comment)) {
            $sql .= 'COMMENT ON TABLE ' . $this->createIdentifier($schema, $table) . ' IS ' . $this->quoteString($comment) . ';' . $eol;
        }
        $sql .= $this->buildColumnsComment($schema, $table);

        return $this->encode($sql);
    }

    /**
     * {@inheritDoc}
     * @see \PruneMazui\DdlGenerator\DdlBuilder\DdlBuilderInterface::buildAllDropTable()
     */
    public function buildAllDropTable(Definition $definition)
    {
        if($definition->countSchemas() == 0) {
            return '';
        }

        $eol = $this->getConfig('end_of_line');

        $sql =
        '/** DROP TABLE **/' . $eol;

        foreach($definition->getSchemas() as $schema) {
            foreach($schema->getTables() as $table) {
                $sql .= $this->buildDropTable($schema, $table) . $eol;
            }
        }

        return $sql . $eol;
    }

    /**
     * {@inheritDoc}
     * @see \PruneMazui\DdlGenerator\DdlBuilder\DdlBuilderInterface::buildDropTable()
     */
    public function buildDropTable(Schema $schema, Table $table)
    {
        return $this->encode('DROP TABLE IF EXISTS ' . $this->createIdentifier($schema, $table) . ' CASCADE;');
    }

    /**
     * {@inheritDoc}
     * @see \PruneMazui\DdlGenerator\DdlBuilder\DdlBuilderInterface::buildCreateIndex()
     */
    public function buildCreateIndex(Index $index)
    {
        $sql = "CREATE ";
        if($index->isUniqueIndex()) {
            $sql .= "UNIQUE ";
        }

        // ignore schema
        $sql .= "INDEX " . $this->quoteIdentifier($index->getKeyName())
            . " ON " . $this->createIdentifier($index->getSchemaName(), $index->getTableName());

        $columns = array_map([$this, 'quoteIdentifier'], $index->getColumnNameList());

        $sql .= " (" . implode(", ", $columns) . ");";

        return $this->encode($sql);
    }

    /**
     * {@inheritDoc}
     * @see \PruneMazui\DdlGenerator\DdlBuilder\DdlBuilderInterface::buildCreateForeignKey()
     */
    public function buildCreateForeignKey(ForeignKey $foreign_key)
    {
        $columns = array_map([$this, 'quoteIdentifier'], $foreign_key->getColumnNameList());
        $lookup_columns = array_map([$this, 'quoteIdentifier'], $foreign_key->getLookupColumnNameList());

        // ignore schema
        $sql = "ALTER TABLE " . $this->createIdentifier($foreign_key->getSchemaName(), $foreign_key->getTableName())
            . " ADD CONSTRAINT " . $this->quoteIdentifier($foreign_key->getKeyName())
            . " FOREIGN KEY (" . implode(", ", $columns) . ")"
            . " REFERENCES " . $this->createIdentifier($foreign_key->getLookupSchemaName(), $foreign_key->getLookupTableName())
            . " (" . implode(", ", $lookup_columns) . ")"
            . " ON UPDATE " . $foreign_key->getOnUpdate()
            . " ON DELETE " . $foreign_key->getOnDelete() . ";";

        return $this->encode($sql);
    }
}
