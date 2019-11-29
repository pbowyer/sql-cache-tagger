<?php

namespace pbowyer\SqlCacheTagger\Recorder;

use Doctrine\DBAL\Logging\SQLLogger;
use pbowyer\SqlCacheTagger\QueryRecorder;

class DoctrineCacheTagsLogger implements SQLLogger
{
    /**
     * @var QueryRecorder
     */
    private $cacheTags;

    public function __construct(QueryRecorder $cacheTags)
    {
        $this->cacheTags = $cacheTags;
    }

    /**
     * {@inheritdoc}
     */
    public function startQuery($sql, array $params = null, array $types = null)
    {
        $this->cacheTags->addQuery($sql);
    }

    /**
     * {@inheritdoc}
     */
    public function stopQuery()
    {
    }

    private function isRead($sql)
    {
        return stripos($sql, 'select ') === 0;
    }
}
