<?php


namespace pbowyer\SqlCacheTagger\QueryParser;


interface QueryParserInterface
{
    public function getTables(string $sql);
}