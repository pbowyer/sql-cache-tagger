<?php


namespace pbowyer\SqlCacheTagger\QueryParser;


use PHPSQLParser\PHPSQLParser;

class GreenlionQueryParser implements QueryParserInterface
{
    /**
     * @var PHPSQLParser
     */
    private $parser;
    private $tables = [];

    public function __construct(PHPSQLParser $parser)
    {
        $this->parser = $parser;
    }

    public function getTables(string $sql)
    {
        $t = microtime(true);
        $o = $this->parser->parse($sql);
        $this->recursiveSearch($o, 'table');
        #fwrite(STDERR, var_export(microtime(true) - $t, TRUE) . "\n");
        $tables = $this->tables;
        // @TODO Code round this horible hack. A new instance of this class every time?
        $this->tables = [];
        return $tables;
    }

    private function recursiveSearch(array $toSearch, string $key)
    {
        foreach ($toSearch as $k => $v) {
            if (is_array($v)) {
                $this->recursiveSearch($v, $key);
            }
            if ($k === $key) {
                $this->tables[] = $v;
            }
        }
    }
}