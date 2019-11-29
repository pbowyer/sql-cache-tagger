<?php

declare(strict_types=1);

namespace pbowyer\SqlCacheTagger\Tests;

use pbowyer\SqlCacheTagger\QueryParser\GreenlionQueryParser;
use pbowyer\SqlCacheTagger\QueryParser\LightQueryParser;
use pbowyer\SqlCacheTagger\QueryRecorder;
use PHPSQLParser\PHPSQLParser;
use PHPUnit\Framework\TestCase;

class QueryRecorderTest extends TestCase
{
    /**
     * @var QueryRecorder
     */
    protected $qr;

    protected function setUp() : void
    {
        $this->qr = new QueryRecorder(new GreenlionQueryParser(new PHPSQLParser()));
    }

    public function testIsInstanceOfQueryRecorder() : void
    {
        $actual = $this->qr;
        $this->assertInstanceOf(QueryRecorder::class, $actual);
    }

    public function testRecordSingleQuery() : void
    {
        $this->qr->addQuery('Query 1');
        $this->assertEquals(['Query 1'], $this->qr->getQueriesFromRoot());
    }

    public function testRecordTwoQueries() : void
    {
        $this->qr->addQuery('Query 1');
        $this->qr->addQuery('Query 2');
        $this->assertEquals(['Query 1', 'Query 2'], $this->qr->getQueriesFromRoot());
    }

    public function testOneBlockDeep() : void
    {
        $this->qr->addQuery('Query 1');
        $this->qr->begin('block');
        $this->qr->addQuery('Query 2');
        $this->qr->end();
        $this->assertEquals(['Query 1', 'Query 2'], $this->qr->getQueriesFromRoot());
        $this->assertEquals(['Query 2'], $this->qr->getQueriesInBlock('block'));
    }

    public function testMultipleBlocksDeep() : void
    {
        $this->qr->addQuery('Query 1');
        $this->qr->begin('block2');
        $this->qr->addQuery('Query 2');

        $this->qr->begin('block3');
        $this->qr->addQuery('Query 3');
        $this->qr->addQuery('Query 3a');
        $this->qr->end();

        $this->qr->addQuery('Query 2a');
        $this->qr->end();

        $this->assertEquals(['Query 1', 'Query 2', 'Query 3', 'Query 3a', 'Query 2a'], $this->qr->getQueriesFromRoot());
        $this->assertEquals(['Query 2', 'Query 3', 'Query 3a', 'Query 2a'], $this->qr->getQueriesInBlock('block2'));
        $this->assertEquals(['Query 3', 'Query 3a'], $this->qr->getQueriesInBlock('block3'));
    }

    public function testWriteTables()
    {
        $this->qr->addQuery("UPDATE tbl1 SET foo = 'bar'");
        $this->qr->addQuery("DELETE tbl1 FROM tbl1 INNER JOIN tbl2 ON tbl1.x = tbl2.y WHERE foo = 'bar'");

        $this->assertEquals(['tbl1', 'tbl2'], $this->qr->getWriteTables());
    }

    public function testOnlyWriteTables()
    {
        $this->qr->addQuery("UPDATE tbl1 SET foo = 'bar'");
        $this->qr->begin('notable');
        $this->qr->addQuery("SELECT * FROM selectbl");
        $this->qr->end();
        $this->qr->begin('ablock');
        $this->qr->addQuery("INSERT INTO tbl3(x, y) VALUES ('foo', 'bar')");
        $this->qr->addQuery("DELETE tbl1 FROM tbl1 INNER JOIN tbl2 ON tbl1.x = tbl2.y WHERE foo = 'bar'");
        $this->qr->end();

        // Write tests
        $this->assertEquals(['tbl1', 'tbl3', 'tbl2'], $this->qr->getWriteTables());
        $this->assertEquals([], $this->qr->getWriteTables('notable'));
        $this->assertEquals(['tbl3', 'tbl1', 'tbl2'], $this->qr->getWriteTables('ablock'));
    }

    public function testOnlyReadTables()
    {
        $this->qr->addQuery("UPDATE tbl1 SET foo = 'bar'");
        $this->qr->begin('notable');
        $this->qr->addQuery("SELECT * FROM selectbl");
        $this->qr->end();
        $this->qr->begin('ablock');
        $this->qr->addQuery("INSERT INTO tbl3(x, y) VALUES ('foo', 'bar')");
        $this->qr->addQuery("DELETE tbl1 FROM tbl1 INNER JOIN tbl2 ON tbl1.x = tbl2.y WHERE foo = 'bar'");
        $this->qr->end();

        // Read tests
        $this->assertEquals(['selectbl'], $this->qr->getReadTables());
        $this->assertEquals(['selectbl'], $this->qr->getReadTables('notable'));
        $this->assertEquals([], $this->qr->getReadTables('ablock'));
    }

    /**
     * For now, the SQL parser ignores read queries where SELECT is not the first word
     * @throws \Exception
     */
    public function testReadTablesThatAreMissedInsertSelect()
    {
        $this->qr->addQuery("INSERT INTO tbl_temp2 (fld_id)
  SELECT tbl_temp1.fld_order_id FROM tbl_temp1 WHERE tbl_temp1.fld_order_id > 100;");

        $this->qr->addQuery("INSERT INTO tbl_temp4 (fld_id)
  (SELECT tbl_temp3.fld_order_id FROM tbl_temp3 WHERE tbl_temp3.fld_order_id > 100)");

        $this->assertEquals([], $this->qr->getReadTables());
    }

    /**
     * When a SELECT with a subSELECT, both tables should be read
     */
    public function testReadTablesSelectSubselect()
    {
        $this->qr->addQuery("SELECT /*+ materialize*/ strategy_id
                            FROM
                             ( SELECT  strat.cf_strategy_id 
                               FROM strategy strt,
                                    doc_sect_ver prodGrp
                              WHERE  strat.src_id               = prodGrp.struct_doc_sect_id
                                       AND strat.module_type   IN ('sdfdsf','assdf')
                            )");

        $this->assertEquals(['strategy', 'doc_sect_ver'], $this->qr->getReadTables());
    }
}
