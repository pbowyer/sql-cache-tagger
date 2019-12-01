
This package starts from a simple premise:

1. Cache items can be tagged with the names of the database tables they read from
2. Cache items can be invalidated based on the database tables that were written to


# :cop: Why?????

4 years ago I worked on a site running a particularly bad CMS, which used Varnish to improve the site's performance. As a CMS with a large plugin library the plugins couldn't be modified to clear the cache when they modified data. So *any* change meant the *entire Varnish cache* had to be emptied.

I wanted to improve that by tracking which items might have changed when a given database table was modified. Clearing a subset of the cache would result in lower load and better load times.

Alas other tasks took priority, but I didn't forget it.

## Time to revisit the idea
Fast forward to the end of 2019 and I'm migrating a legacy system to Symfony. With new code written and some features migrated the rewrite is halted as not cost-effective. I have 2 mostly-separate codebases, with part of the public facing side in Symfony and the internal admin-system in the legacy codebase. The legacy system needs caching for performance reasons (some screens use 2000+ queries); Symfony would benefit too.

The systems share some code and the legacy system is wrapped in Symfony. Both use different database access layers - and PHP database extensions. The best way to know when a legacy screen has affected data displayed somewhere else? By intercepting the database queries.

Worst of all is a data translation layer, put in place between the legacy system's database structure and part of the new, meant-to-be-replacement system. Eventually when everything was rewritten, the database structure would have been altered and the translation layer discarded. Now that's not goign to happen, and the translation layer is a query-hungry monstrosity.

## A deliberate choice since Reads >> Writes
The system gets an order of magnitude (or 3) more database reads than writes. Because of this, slowing down the workings of the system by tracking all SQL queries is an acceptable tradeoff for a faster user experience and less infrastructure to run the application (and reduce load on the database).
  
# :thinking: Should I use this?
**In general, no**. If you're building a new system, you should design for cache invalidation from the start.

If your system's a pile of spaghetti code and you're modernising bits... well what harm can it do?

# Why didn't you use...

## Doctrine's Second Level Cache?
* ORM only
* Is not aware of changes made to the persistent store by another application, which is a requirement
* Works at a database-query level. I'm trying to cache the results of data manipulations that rely on the database for underlying data, not the queries themselves


# Limitations

* Tagging is by database table, not by table row. If you modify a single column in one row of the table, every cache item that references that table will be invalidated, not just the items that use the changed data.
* It's a proof of concept
* Detecting read and write queries is crude (see above)
    * Any queries beginning `SELECT` are read queries
    * Any queries beginning `INSERT`, `UPDATE` or `DELETE` are write queries
        *  `SELECT` subqueries are ignored, and not treated as read queries

# Install

:rotating_light: WARNING: experimental code not for production :rotating_light: 

## Add the package

As this package isn't (yet) available on packagist.org, add the following to your `composer.json` file first:

```json
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/pbowyer/sql-cache-tagger.git"
        }
    ]
```

Then install the package:
```
composer require pbowyer/sql-cache-tagger
```

## Configure your Symfony project to use the code

Eventually these will be in a bundle. For now, add the following to your `config/services.yaml` file:

```yaml
    tablecachetagslogger:
        class: pbowyer\SqlCacheTagger\Recorder\DoctrineCacheTagsLogger
        arguments: ~
    pbowyer\SqlCacheTagger\QueryRecorder: ~
    pbowyer\SqlCacheTagger\QueryParser\QueryParserInterface:
        class: pbowyer\SqlCacheTagger\QueryParser\GreenlionQueryParser
    PHPSQLParser\PHPSQLParser: ~
```

## Configure DoctrineBundle to give this bundle the queries

This bundle hooks into Doctrine's logger. To protect people from doing silly things, DoctrineBundle makes configuring this particularly tricky in the production environment. Instructions below subject to change - especially if I find a better way.

### In development
In Symfony's dev environment `APP_ENV=dev` it's easy, because the Doctrine logger is active. I add the following to my `config/services.yaml`:
```yaml
    doctrine.dbal.logger.chain:
        class: '%doctrine.dbal.logger.chain.class%'
        public: false
        abstract: true
        calls:
            # I've tried commenting out this call in production - it doesn't work :(
            - method: addLogger
              arguments:
                  - '@doctrine.dbal.logger'
            - method: addLogger
              arguments:
                  - '@tablecachetagslogger'
```

### In production
In production environment (`APP_ENV=prod`)... I have yet to find the best way. A way that works is:

1. Edit `config/packages/prod/doctrine.yaml` and add:

    ```yaml
    dbal:
        profiling: true
    ```
1. Edit `config/services.yaml` and add:

    ```yaml
    doctrine.dbal.logger.profiling.default:
        class: pbowyer\SqlCacheTagger\Recorder\DoctrineCacheTagsLogger
    ```
    
## Create a listener to invalidate the cache based on write queries
I created the following very simple listener:
```php
<?php


namespace App\EventListener;


use App\Lib\AccountManager;
use pbowyer\SqlCacheTagger\QueryRecorder;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

class TaggedCacheSqlListener
{
    /**
     * @var AccountManager
     */
    private $accountManager;
    /**
     * @var QueryRecorder
     */
    private $queryRecorder;
    /**
     * @var TagAwareCacheInterface
     */
    private $cache;
    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(
        AccountManager $accountManager,
        QueryRecorder $queryRecorder,
        TagAwareCacheInterface $cache,
        LoggerInterface $logger
    )
    {
        $this->accountManager = $accountManager;
        $this->queryRecorder = $queryRecorder;
        $this->cache = $cache;
        $this->logger = $logger;
    }

    public function onKernelTerminate($event)
    {
        $accountId = $this->accountManager->getAccount()->getId();
        $tags = $this->queryRecorder->getWriteTables();
        // @TODO Provide a proper way to specifiy tables to exclude (e.g. session tables)
        $tags = array_diff($tags, ['session']);
        if (count($tags)) {
            array_walk(
                $tags,
                function (&$value, $key) use ($accountId) {
                    $value = $accountId.$value;
                }
            );
            $this->logger->info("Invalidating tags: ".implode(', ', $tags), ['tags' => $tags]);
            $this->cache->invalidateTags($tags);
        }
    }
}
```

Register it in `config/services.yaml`:
```yaml
    App\EventListener\TaggedCacheSqlListener:
        tags:
            - { name: kernel.event_listener, event: kernel.terminate }
```


# Future work

* Write a Symfony bundle, so this repository is less 'experimental'
* Consider if there's any room to do this at a more granular level (e.g. per-row). Whether by hashing the data, or recognising the fetch data's primary key