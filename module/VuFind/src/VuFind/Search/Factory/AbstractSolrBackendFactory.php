<?php

/**
 * Abstract factory for SOLR backends.
 *
 * PHP version 5
 *
 * Copyright (C) Villanova University 2013.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @category VuFind2
 * @package  Search
 * @author   David Maus <maus@hab.de>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://vufind.org   Main Site
 */

namespace VuFind\Search\Factory;

use VuFindSearch\Backend\BackendInterface;
use VuFindSearch\Backend\Solr\QueryBuilder;
use VuFindSearch\Backend\Solr\Connector;
use VuFindSearch\Backend\Solr\Backend;

use Zend\ServiceManager\ServiceLocatorInterface;
use Zend\ServiceManager\FactoryInterface;

/**
 * Abstract factory for SOLR backends.
 *
 * @category VuFind2
 * @package  Search
 * @author   David Maus <maus@hab.de>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://vufind.org   Main Site
 */
abstract class AbstractSolrBackendFactory implements FactoryInterface
{
    /**
     * Logger.
     *
     * @var Zend\Log\LoggerInterface
     */
    protected $logger;

    /**
     * Superior service manager.
     *
     * @var ServiceLocatorInterface
     */
    protected $serviceLocator;

    /**
     * Search configuration file identifier.
     *
     * @var string
     */
    protected $searchConfig;

    /**
     * YAML searchspecs filename.
     *
     * @var string
     */
    protected $searchYaml;

    /**
     * Main VuFind configuration
     *
     * @var \Zend\Config\Config
     */
    protected $config;

    /**
     * Solr core name
     *
     * @var string
     */
    protected $solrCore = '';

    /**
     * Constructor
     */
    public function __construct()
    {}

    /**
     * Create the backend.
     *
     * @param ServiceLocatorInterface $serviceLocator Superior service manager
     *
     * @return BackendInterface
     */
    public function createService (ServiceLocatorInterface $serviceLocator)
    {
        $this->serviceLocator = $serviceLocator;
        $this->config         = $this->serviceLocator->get('VuFind\Config');
        if ($this->serviceLocator->has('VuFind\Logger')) {
            $this->logger = $this->serviceLocator->get('VuFind\Logger');
        }
        $connector = $this->createConnector();
        $backend   = $this->createBackend($connector);
        $this->createListeners($backend);
        return $backend;
    }

    /**
     * Create the SOLR backend.
     *
     * @param Connector $connector  Connector
     *
     * @return Backend
     */
    protected function createBackend (Connector $connector)
    {
        $backend = new Backend($connector);
        $specs   = $this->loadSpecs();
        $builder = new QueryBuilder($specs);
        $backend->setQueryBuilder($builder);

        if ($this->logger) {
            $backend->setLogger($this->logger);
        }
        return $backend;
    }

    /**
     * Create listeners.
     *
     * @param Backend $backend Backend
     *
     * @return void
     */
    protected function createListeners (Backend $backend)
    {
    }

    /**
     * Create the SOLR connector.
     *
     * @return Connector
     */
    protected function createConnector ()
    {
        $config = $this->config->get('config');
        $search = $this->config->get('searches');

        $url    = $config->Index->url . '/' . $this->solrCore;
        $connector = new Connector($url);
        $connector->setTimeout(
            isset($config->Index->timeout) ? $config->Index->timeout : 30
        );
        $connector->setQueryDefaults(
            array('wt' => 'json', 'json.nl' => 'arrarr', 'fl' => '*,score')
        );

        $hl = !isset($search->General->highlighting) ? false : $search->General->highlighting;
        $sn = !isset($search->General->snippets)     ? false : $search->General->snippets;
        if ($hl || $sn) {
            $connector->addQueryAppend('hl', 'true');
            $connector->addQueryAppend('hl.fl', '*');
            $connector->addQueryAppend('hl.simple.pre', '{{{{START_HILITE}}}}');
            $connector->addQueryAppend('hl.simple.post', '{{{{END_HILITE}}}}');
        }

        // Hidden filters
        if (isset($search->HiddenFilters)) {
            foreach ($search->HiddenFilters as $field => $value) {
                $connector->addQueryAppend('fq', sprintf('%s:"%s"', $field, $value));
            }
        }
        // Raw hidden filters
        if (isset($search->RawHiddenFilters)) {
            foreach ($search->RawHiddenFilters as $filter) {
                $connector->addQueryAppend('fq', $filter);
            }
        }

        if ($this->logger) {
            $connector->setLogger($this->logger);
        }
        if ($this->serviceLocator->has('VuFind\Http')) {
            $connector->setProxy($this->serviceLocator->get('VuFind\Http'));
        }
        return $connector;
    }

    /**
     * Load the search specs.
     *
     * @return array
     */
    protected function loadSpecs ()
    {
        $specs = $this->serviceLocator->get('VuFind\SearchSpecsReader')->get($this->searchYaml);
        return $specs;
    }
}