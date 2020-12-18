<?php

/**
 *
 * This file is part of the Apix Project.
 *
 * (c) Franck Cassedanne <franck at ouarz.net>
 *
 * @license     http://opensource.org/licenses/BSD-3-Clause  New BSD License
 *
 */

namespace Apix\Cache;

/**
 * PDO cache wrapper with tag support.
 *
 * @author Franck Cassedanne <franck at ouarz.net>
 */
abstract class AbstractPdo extends AbstractCache
{

    /**
     * Holds the SQL definitions.
     * @var array
     */
    protected $sql_definitions;

    /**
     * Holds the array of TTLs.
     * @var array
     */
    protected $ttls = array();

    /**
     * Constructor.
     *
     * @param \PDO  $pdo     An instance of a PDO class.
     * @param array $options Array of options.
     */
    public function __construct(\PDO $pdo, array $options=null)
    {
        // default options
        $this->options['db_table']   = 'cache'; // table to hold the cache
        $this->options['serializer'] = 'php';   // null, php, igBinary, json
                                                // and msgpack
        $this->options['preflight']  = true;    // wether to preflight the DB
        $this->options['timestamp']  = 'Y-m-d H:i:s'; // timestamp db format

        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        parent::__construct($pdo, $options);
        $this->setSerializer($this->options['serializer']);

        if ($this->options['preflight']) {
            $this->initDb();
        }
    }

    /**
     * Initialises the database and its indexes (if required, non-destructive).
     *
     * @return self Provides a fluent interface
     */
    public function initDb()
    {
        $this->adapter->exec( $this->getSql('init') );

        $this->createIndexTable('key_idx');
        $this->createIndexTable('exp_idx');
        $this->createIndexTable('tag_idx');

        return $this;
    }

    /**
     * Creates the specified indexe table (if missing).
     *
     * @param  string  $index
     * @return boolean
     */
    public function createIndexTable($index)
    {
        if (!isset($this->sql_definitions[$index])) {
            return false;
        }

        try {
            $this->adapter->exec($this->getSql($index, $this->options['db_table']));
        } catch (\PDOException $ex) {
            if (1061 !== $ex->errorInfo[1]) {
                // if not "Index already exists"
            }
        }

        return $this->adapter->errorCode() == '00000';
    }

    /**
     * {@inheritdoc}
     */
    public function loadKey($key)
    {
        $result = null;

        $key = $this->mapKey($key);
        $sql = $this->getSql('loadKey');
        $values = array('key' => $key, 'now' => time());

        $cached = $this->exec($sql, $values)->fetch();

        if (false !== $cached) {
            $this->ttls[$key] = $cached['expire'];

            if (null !== $cached['data'] && null !== $this->serializer) {
                $result = $this->serializer->unserialize($cached['data']);
            }
            else {
                $result = $cached['data'];
            }
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function loadTag($tag)
    {
        $sql = $this->getSql('loadTag');
        // $tag = $this->mapTag($tag);
        $values = array('tag' => "%$tag%", 'now' => time());

        $items = $this->exec($sql, $values)->fetchAll();

        $keys = array();
        foreach ($items as $item) {
            $keys[] = $item['key'];
        }

        return empty($keys) ? null : $keys;
    }

    /**
     * {@inheritdoc}
     */
    public function save($data, $key, array $tags=null, $ttl=null)
    {
        $key = $this->mapKey($key);
        $values = array(
            'key'   => $key,
            'data'  => null !== $this->serializer
                        ? $this->serializer->serialize($data)
                        : $data,
            'exp'   => null !== $ttl && 0 !== $ttl ? time()+$ttl : null,
            'dated' => $this->getTimestamp()
        );

        $this->ttls[$key] = $values['exp'];

        $values['tags'] = $this->options['tag_enable'] && null !== $tags
                            ? implode(', ', $tags)
                            : null;

        // upsert
        $sql = $this->getSql('update');
        $nb = $this->exec($sql, $values)->rowCount();
        if ($nb == 0) {
            $sql = $this->getSql('insert');
            $nb = $this->exec($sql, $values)->rowCount();
        }

        return (boolean) $nb;
    }

    /**
     * {@inheritdoc}
     */
    public function delete($key)
    {
        $sql = $this->getSql('delete');
        $values = array($this->mapKey($key));

        return (boolean) $this->exec($sql, $values)->rowCount();
    }

    /**
     * {@inheritdoc}
     */
    public function clean(array $tags)
    {
        $values = array();
        foreach ($tags as $tag) {
            // $tag = $this->mapTag($tag);
            $values[] = '%' . $tag . '%';
        }

        $sql = $this->getSql(
            'clean', implode(' OR ', array_fill(
                    0, count($tags), $this->getSql('clean_like')))
        );

        return (boolean) $this->exec($sql, $values)->rowCount();
    }

    /**
     * {@inheritdoc}
     */
    public function flush($all=false)
    {
        if (true === $all) {
            return false !== $this->adapter->exec($this->getSql('flush_all'));
        }

        $value = array(
            $this->options['prefix_key'].'%',
            $this->options['prefix_tag'].'%'
        );

        return (boolean) $this->exec($this->getSql('flush'), $value)->rowCount();
    }

    /**
     * Purges expired items.
     *
     * @param  integer|null $add Extra time in second to add.
     * @return boolean      Returns True on success or False on failure.
     */
    public function purge($add=null)
    {
        $time = null == $add ? time() : time()+$add;

        return (boolean) $this->adapter->exec($this->getSql('purge', $time));
    }

    /**
     * Gets the named SQL definition.
     *
     * @param  string         $key
     * @param  string|integer $value An additional value.
     * @return string
     */
    protected function getSql($key, $value=null)
    {
        return sprintf(
            $this->sql_definitions[$key],
            $this->options['db_table'],
            $value
        );
    }

    /**
     * Prepares and executes a SQL query.
     *
     * @param  string        $sql    The SQL to prepare.
     * @param  array         $values The values to execute.
     * @return \PDOStatement Provides a fluent interface
     */
    protected function exec($sql, array $values)
    {
        $prep = $this->adapter->prepare($sql);
        $prep->execute($values);

        return $prep;
    }

    /**
     * Returns the current driver's name.
     *
     * @param \PDO  $pdo     An instance of a PDO class.
     * @return string        Either 'Mysql', 'Pgsql', 'Sqlite' or 'Sql1999'.
     */
    public static function getDriverName(\PDO $pdo)
    {
        $name = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if (!in_array($name, array('sqlite', 'mysql', 'pgsql'))) {
        // @codeCoverageIgnoreStart
            // sql1999 specific tests are run on Sqlite.
            $name = 'sql1999';
        }
        // @codeCoverageIgnoreEnd

        return ucfirst($name);
    }

    /**
     * Returns a formated timestamp.
     *
     * @param integer|null $time If null, use the current time.
     */
    public function getTimestamp($time=null)
    {
        return date(
            $this->options['timestamp'],
            null != $time ? $time : time()
        );
    }

    /**
     * {@inheritdoc}
     */
    public function getTtl($key)
    {
        $mKey = $this->mapKey($key);

        return isset($this->ttls[$mKey])
                ? $this->ttls[$mKey]-time()
                : false;
    }

}
