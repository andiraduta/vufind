<?php
/**
 * Summon Controller
 *
 * PHP version 5
 *
 * Copyright (C) Villanova University 2010.
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
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301  USA
 *
 * @category VuFind
 * @package  Controller
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://vufind.org Main Site
 */
namespace VuFind\Controller;

use Zend\Mvc\MvcEvent;
use Zend\ServiceManager\ServiceLocatorInterface;

/**
 * Summon Controller
 *
 * @category VuFind
 * @package  Controller
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://vufind.org Main Site
 */
class SummonController extends AbstractSearch
{
    /**
     * Constructor
     *
     * @param ServiceLocatorInterface $sm Service locator
     */
    public function __construct(ServiceLocatorInterface $sm)
    {
        $this->searchClassId = 'Summon';
        parent::__construct($sm);
    }

    /**
     * Is the result scroller active?
     *
     * @return bool
     */
    protected function resultScrollerActive()
    {
        $config = $this->serviceLocator->get('VuFind\Config\PluginManager')
            ->get('Summon');
        return isset($config->Record->next_prev_navigation)
            && $config->Record->next_prev_navigation;
    }

    /**
     * Use preDispatch event to add Summon message.
     *
     * @param MvcEvent $e Event object
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function injectSummonMessage(MvcEvent $e)
    {
        $this->layout()->poweredBy
            = 'Powered by Summon™ from Serials Solutions, a division of ProQuest.';
    }

    /**
     * Register the default events for this controller
     *
     * @return void
     */
    protected function attachDefaultListeners()
    {
        parent::attachDefaultListeners();
        $events = $this->getEventManager();
        $events->attach(
            MvcEvent::EVENT_DISPATCH, [$this, 'injectSummonMessage'], 1000
        );
    }

    /**
     * Handle an advanced search
     *
     * @return mixed
     */
    public function advancedAction()
    {
        // Standard setup from base class:
        $view = parent::advancedAction();

        // Set up facet information:
        $view->facetList = $this->processAdvancedFacets(
            $this->getAdvancedFacets(), $view->saved
        );
        $specialFacets = $this->parseSpecialFacetsSetting(
            $view->options->getSpecialAdvancedFacets()
        );
        if (isset($specialFacets['checkboxes'])) {
            $view->checkboxFacets = $this->processAdvancedCheckboxes(
                $specialFacets['checkboxes'], $view->saved
            );
        }
        $view->ranges = $this
            ->getAllRangeSettings($specialFacets, $view->saved, 'Summon');

        return $view;
    }

    /**
     * Home action
     *
     * @return mixed
     */
    public function homeAction()
    {
        return $this->createViewModel(
            [
                'results' => $this->getResultsManager()->get('Summon'),
                'facetList' => $this->getHomePageFacets(),
            ]
        );
    }

    /**
     * Search action -- call standard results action
     *
     * @return mixed
     */
    public function searchAction()
    {
        return $this->resultsAction();
    }

    /**
     * Return a Search Results object containing advanced facet information.  This
     * data may come from the cache.
     *
     * @return array
     */
    protected function getAdvancedFacets()
    {
        // Check if we have facet results cached, and build them if we don't.
        $cache = $this->serviceLocator->get('VuFind\Cache\Manager')
            ->getCache('object');
        $language = $this->serviceLocator->get('Zend\Mvc\I18n\Translator')
            ->getLocale();
        $cacheKey = 'summonSearchAdvancedFacetsList-' . $language;
        if (!($list = $cache->getItem($cacheKey))) {
            $config = $this->serviceLocator->get('VuFind\Config\PluginManager')
                ->get('Summon');
            $limit = isset($config->Advanced_Facet_Settings->facet_limit)
                ? $config->Advanced_Facet_Settings->facet_limit : 100;
            $results = $this->getResultsManager()->get('Summon');
            $params = $results->getParams();
            $facetsToShow = isset($config->Advanced_Facets)
                 ? $config->Advanced_Facets
                 : ['Language' => 'Language', 'ContentType' => 'Format'];
            if (isset($config->Advanced_Facet_Settings->orFacets)) {
                $orFields = array_map(
                    'trim', explode(',', $config->Advanced_Facet_Settings->orFacets)
                );
            } else {
                $orFields = [];
            }
            foreach ($facetsToShow as $facet => $label) {
                $useOr = (isset($orFields[0]) && $orFields[0] == '*')
                    || in_array($facet, $orFields);
                $params->addFacet(
                    $facet . ',or,1,' . $limit, $label, $useOr
                );
            }

            // We only care about facet lists, so don't get any results:
            $params->setLimit(0);

            // force processing for cache
            $list = $results->getFacetList();

            $cache->setItem('summonSearchAdvancedFacetsList', $list);
        }

        return $list;
    }

    /**
     * Return a Search Results object containing homepage facet information.  This
     * data may come from the cache.
     *
     * @return array
     */
    protected function getHomePageFacets()
    {
        // For now, we'll use the same fields as the advanced search screen.
        return $this->getAdvancedFacets();
    }

    /**
     * Process the facets to be used as limits on the Advanced Search screen.
     *
     * @param array  $facetList    The advanced facet values
     * @param object $searchObject Saved search object (false if none)
     *
     * @return array               Sorted facets, with selected values flagged.
     */
    protected function processAdvancedFacets($facetList, $searchObject = false)
    {
        // Process the facets, assuming they came back
        foreach ($facetList as $facet => $list) {
            foreach ($list['list'] as $key => $value) {
                // Build the filter string for the URL:
                $fullFilter = ($value['operator'] == 'OR' ? '~' : '')
                    . $facet . ':"' . $value['value'] . '"';

                // If we haven't already found a selected facet and the current
                // facet has been applied to the search, we should store it as
                // the selected facet for the current control.
                if ($searchObject
                    && $searchObject->getParams()->hasFilter($fullFilter)
                ) {
                    $facetList[$facet]['list'][$key]['selected'] = true;
                    // Remove the filter from the search object -- we don't want
                    // it to show up in the "applied filters" sidebar since it
                    // will already be accounted for by being selected in the
                    // filter select list!
                    $searchObject->getParams()->removeFilter($fullFilter);
                }
            }
        }
        return $facetList;
    }
}
