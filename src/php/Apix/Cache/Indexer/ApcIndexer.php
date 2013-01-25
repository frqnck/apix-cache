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

namespace Apix\Cache\Indexer;

use Apix\Cache\Apc;

/**
 * Apc indexer.
 *
 * @author Franck Cassedanne <franck at ouarz.net>
 *
 * @TODO: namespacing?!
 * @see http://code.google.com/p/memcached/wiki/NewProgrammingTricks
 * @TODO: tag set?!
 * @see http://dustin.github.com/2011/02/17/memcached-set.html
 *
 */
class ApcIndexer extends AbstractIndexer
{

    const DIRTINESS_THRESHOLD = 100;

    /**
     * Holds the name of the index.
     * @var array
     */
    protected $index;

    /**
     * Holds the index items.
     * @var array
     */
    protected $items = null;

    /**
     * Holds this index dirtiness.
     * @var integer
     */
    protected $dirtiness;

    /**
     * Constructor.
     *
     * @param array                $options   Array of options.
     * @param Apix\Cache\Apc $Memcached An instance of .
     */
    public function __construct(Apc $engine, $index)
    {
        $this->engine = $engine;
        $this->index = $index;
    }

    /**
     * Gets the adapter.
     *
     * @return \Memcached
     */
    public function getAdapter()
    {
        return $this->engine->getAdapter();
    }

    /**
     * {@inheritdoc}
     */
    public function add($elements)
    {
        foreach ((array) $elements as $element) {
            $tag = $this->mapTag($tag);
            $keys = apc_fetch($tag, $success);
            
            if (false === $success) {
                $store[$tag] = array($key);
            } else {
                $keys[] = $key;
                $store[$tag] = array_unique($keys);
            }
        }

        return (boolean) $success;


        $success = $this->getAdapter()->append($this->index, $str);
        if (!$success) {
            $success = $this->getAdapter()->add($this->index, $str);
        }

    }

    /**
     * {@inheritdoc}
     */
    public function remove($elements)
    {
        $str = $this->serialize((array) $elements, '-');

        return (boolean) $this->getAdapter()->append($this->index, $str);
    }

    /**
     * Returns the indexed items.
     *
     * @param  array   $context The elements to remove from the index.
     * @return Returns True on success or False on failure.
     */
    public function load()
    {
        return $this->engine->get($this->index);
    }

    /**
     * Purge the index.
     *
     * @return [type] [description]
     */
    public function purge()
    {
        $str = $this->serialize($this->items, '+');

        return $this->getAdapter()->cas($this->token, $this->index, $str);
    }

    /**
     * Serialises the given string.
     *
     * e.g. '+a +b +c -b -x' => ['a','c'];
     * Sets the dirtiness level (count negative).
     *
     * @param  array  $keys
     * @return string $operator
     */
    public function serialize(array $keys, $op='+')
    {
        $str = '';
        foreach ($keys as $key) {
            $str .= $op . $key . ' ';
        }

        return $str;
    }

    /**
     * Unserialises the given string.
     *
     * e.g. '+a +b +c -b -x' => ['a','c'];
     * Sets the dirtiness level (count negative).
     *
     * @param  string $string
     * @return array
     */
    public function unserialize($str)
    {
        $add    = array();
        $remove = array();
        foreach (explode(' ', trim($str)) as $k) {
            $key = substr($k, 1);
            $op = $k[0];
            if ($op == '+') {
                $add[] = $key;
            } else {
                $remove[] = $key;
            }
        }

        $this->dirtiness = count($remove);

        $items = array_values(array_diff($add, $remove));

        return empty($items) ? null : $items;
    }

}
