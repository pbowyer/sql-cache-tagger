<?php


namespace pbowyer\SqlCacheTagger;

use drupol\phptree\Node\ValueNode;
use pbowyer\SqlCacheTagger\QueryParser\QueryParserInterface;

class QueryRecorder
{
    private $stack;
    /** @var ValueNode[] */
    private $tree;
    private $currentNode;
    private $rootName = 'root';
    /**
     * @var QueryParserInterface
     */
    private $queryParser;

    public function __construct(QueryParserInterface $queryParser)
    {
        $this->stack[$this->rootName] = new ValueNode('root');
        $this->tree = $this->stack[$this->rootName];
        $this->currentNode = $this->stack[$this->rootName];
        $this->queryParser = $queryParser;
    }

    public function begin(string $name)
    {
        $node = new ValueNode($name);
        $this->stack[$name] = $node;
        $this->currentNode->add($node);
        $this->currentNode = $this->stack[$name];
    }

    /**
     * @TODO Handle invalidly nested blocks
     * End a block.
     * If a name is supplied, and that name isn't the current level, recurse up until we find it.
     * If it doesn't exist, throw an error
     * If we're at the root level, close nothing
     *
     * @param string|null $name
     */
    public function end(?string $name = null)
    {
//        if (count($this->stack) === 1) {
//            return;
//        }
        if ($name === null) {
//            array_pop($this->stack);
//            $this->currentNode = $this->stack[array_key_last($this->stack)];
            $this->currentNode = $this->currentNode->getParent();
        } else {
            throw new \Exception('TODO: Handle named block ends');
        }
    }

    public function addQuery($query)
    {
//        /**
//         * As many of our pages do repeated queries with different parameters, we gather
//         * all the SQL first. This means we don't analyse duplicate queries
//         */
//        $hash = crc32($query);
//        if (!isset($this->queries[$hash])) {
//            $this->queries[$hash] = $query;
//            // Only dispatch new queries
//        }
        $this->currentNode->add(new ValueNode($query));
    }

    public function getQueriesInBlock($name)
    {
        if ( ! isset($this->stack[$name])) {
            return;
        }
        $out = [];
        foreach ($this->stack[$name]->all() as $node) {
            if ($node->isLeaf()) {
                $out[] = $node->getValue();
            }
        }

        return $out;
    }

    public function getQueriesFromRoot()
    {
        return $this->getQueriesInBlock($this->rootName);
    }

    /**
     * As a cop out / to save time, I take a basic approach to identifying the query type.
     *
     * @TODO Only parse queries once. If the same query is run multiple times, don't parse again
     * @param null $blockName
     *
     * @return array
     */
    public function getWriteTables($blockName = null)
    {
        $blockName = $blockName ?? $this->rootName;
        $queries = $this->getQueriesInBlock($blockName);
        $tables = [];
        foreach ($queries as $query) {
            if (stripos($query, 'INSERT') === 0 || stripos($query, 'UPDATE') === 0 || stripos($query, 'DELETE') === 0) {
                $tables[] = $this->queryParser->getTables($query);
            }
        }
        return array_values(array_unique(array_merge([], ...$tables)));
    }

    /**
     * As a cop out / to save time, I take a basic approach to identifying the query type.
     *
     * @TODO Handle read tables in subqueries OR in nonsubqueries e.g. INSERT INTO foo SELECT * FROM foo_copy
     * @TODO Only parse queries once. If the same query is run multiple times, don't parse again
     * @param null $blockName
     *
     * @return array
     */
    public function getReadTables($blockName = null)
    {
        $blockName = $blockName ?? $this->rootName;
        $queries = $this->getQueriesInBlock($blockName);
        $tables = [];
        foreach ($queries as $query) {
            if (stripos($query, 'SELECT') === 0) {
                $tables += $this->queryParser->getTables($query);
            }
        }
        return array_values(array_unique($tables));
    }
}