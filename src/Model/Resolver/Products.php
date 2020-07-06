<?php
declare(strict_types=1);

namespace G4NReact\MsCatalogMagento2GraphQl\Model\Resolver;

use Exception;
use G4NReact\MsCatalog\Client\ClientFactory;
use G4NReact\MsCatalog\Document;
use G4NReact\MsCatalog\QueryInterface;
use G4NReact\MsCatalog\ResponseInterface;
use G4NReact\MsCatalogMagento2\Helper\Config as ConfigHelper;
use G4NReact\MsCatalogMagento2\Helper\Facets as FacetsHelper;
use G4NReact\MsCatalogMagento2\Helper\Query;
use G4NReact\MsCatalogMagento2GraphQl\Helper\Parser;
use G4NReact\MsCatalogMagento2GraphQl\Helper\Search as SearchHelper;
use G4NReact\MsCatalogSolr\FieldHelper;
use Magento\Catalog\Model\CategoryRepository;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\DataObject;
use Magento\Framework\Event\Manager as EventManager;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Query\Resolver\ContextInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Search\Model\Query as SearchQuery;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Class Products
 * @package Global4net\CatalogGraphQl\Model\Resolver
 */
class Products extends AbstractResolver
{
    /**
     * CatalogGraphQl products cache key
     */
    const CACHE_KEY_CATEGORY = 'G4N_CAT_GRAPH_QL_PROD';

    /**
     * @var string
     */
    const CACHE_KEY_SEARCH = 'G4N_SEARCH_PROD';

    /**
     * @var String
     */
    const PRODUCT_OBJECT_TYPE = 'product';

    /**
     * @var array
     */
    public static $defaultAttributes = [
        'category',
        'price'
    ];

    /**
     * List of attributes codes that we can skip when returning attributes for product
     *
     * @var array
     */
    public static $attributesToSkip = [];

    /**
     * @var string
     */
    public $resolveInfo;

    /**
     * @var CategoryRepository
     */
    protected $categoryRepository;

    /**
     * @var FacetsHelper
     */
    protected $facetsHelper;

    /**
     * @var SearchHelper
     */
    protected $searchHelper;

    /**
     * Products constructor
     *
     * @param CacheInterface $cache
     * @param DeploymentConfig $deploymentConfig
     * @param StoreManagerInterface $storeManager
     * @param Json $serializer
     * @param LoggerInterface $logger
     * @param ConfigHelper $configHelper
     * @param Query $queryHelper
     * @param EventManager $eventManager
     * @param CategoryRepository $categoryRepository
     * @param FacetsHelper $facetsHelper
     * @param SearchHelper $searchHelper
     */
    public function __construct(
        CacheInterface $cache,
        DeploymentConfig $deploymentConfig,
        StoreManagerInterface $storeManager,
        Json $serializer,
        LoggerInterface $logger,
        ConfigHelper $configHelper,
        Query $queryHelper,
        EventManager $eventManager,
        CategoryRepository $categoryRepository,
        FacetsHelper $facetsHelper,
        SearchHelper $searchHelper
    ) {
        $this->categoryRepository = $categoryRepository;
        $this->facetsHelper = $facetsHelper;
        $this->searchHelper = $searchHelper;

        return parent::__construct(
            $cache,
            $deploymentConfig,
            $storeManager,
            $serializer,
            $logger,
            $configHelper,
            $queryHelper,
            $eventManager
        );
    }

    /**
     * @param Field $field
     * @param ContextInterface $context
     * @param ResolveInfo $info
     * @param array|null $value
     * @param array|null $args
     * @return array
     * @throws GraphQlInputException
     * @throws LocalizedException
     * @throws NoSuchEntityException
     * @throws Exception
     */
    public function resolve(
        Field $field,
        $context,
        ResolveInfo $info,
        array $value = null,
        array $args = null
    ) {
        if (isset($context->args)) {
            $argsFromContext = $context->args;
            $args = $args ?: [];
            if ($argsFromContext['overwrite_args'] ?? false) {
                $args = array_merge($args, $context->args);
            } elseif ($argsFromContext['merge_args'] ?? false) {
                $oldArgs = $args;
                $args = array_merge($args, $context->args);
                if (isset($args['filter']['attributes'])) {
                    $args['filter']['attributes'] = array_merge($args['filter']['attributes'], $oldArgs['filter']['attributes']);
                }
            } else {
                $args = array_merge($context->args, $args);
            }
        }

        $resolveObject = new DataObject([
            'field' => $field,
            'context' => $context,
            'resolve_info' => $info,
            'value' => $value,
            'args' => $args
        ]);
        $this->eventManager->dispatch(
            self::PRODUCT_OBJECT_TYPE . '_resolver_resolve_before',
            ['resolve' => $resolveObject]
        );

        $value = $resolveObject->getValue();
        $args = $resolveObject->getArgs();

        if ((isset($args['redirect']) && $args['redirect']) || (isset($args['search']) && $args['search'] == '')) {
            return [
                'items' => [],
                'total_count' => 0,
            ];
        }

        if (!isset($args['search']) && !isset($args['filter'])) {
            throw new GraphQlInputException(
                __("'search' or 'filter' input argument is required.")
            );
        }

        $debug = isset($args['debug']) && $args['debug'];
        $this->resolveInfo = $info->getFieldSelection(3);
        $limit = (isset($this->resolveInfo['items']) && isset($this->resolveInfo['items']['__typename'])) ? 2 : 1;
        $onlySku = (isset($this->resolveInfo['items']) && count($this->resolveInfo['items']) <= $limit && isset($this->resolveInfo['items']['sku']))
            || (isset($this->resolveInfo['items_ids']));

        $queryFields = [];
        if (!$onlySku) {
            $queryFields = $info->getFieldSelection(3)['items'] ?? [];
        }

        $searchQuery = $args['search'] ?? '';
        if ($searchQuery) {
            $this->resolveInfo['total_count'] = true;
        }

        $query = $this->prepareQuery($args, $queryFields);

        $this->eventManager->dispatch(
            'prepare_msproduct_resolver_response_before',
            ['query' => $query, 'resolve_info' => $info, 'args' => $args]
        );
        $response = $query->getResponse();

        if (
            $searchQuery
            && $response->getNumFound() === 0
            && $this->configHelper->getConfigByPath(ConfigHelper::SPELL_CHECKING_ENABLED)
        ) {
            $originalUserInput = $args['query'] ?? '';
            $newSearchQuery = $this->useSpellchecking($originalUserInput ?: $searchQuery);
            if ($newSearchQuery) {
                $newArgs = $args;
                $newArgs['search'] = $newSearchQuery;
                $newQuery = $this->prepareQuery($newArgs, $queryFields);
                $this->eventManager->dispatch(
                    'prepare_msproduct_resolver_response_before',
                    ['query' => $newQuery, 'resolve_info' => $info, 'args' => $newArgs]
                );
                $response = $newQuery->getResponse();
                $context->correctedQuery = $newSearchQuery;
            }

        }

        $this->eventManager->dispatch(
            'prepare_msproduct_resolver_response_after',
            ['response' => $response]
        );

        $result = $this->prepareResultData($response, $debug);


        if (isset($args['search'])
            && $args['search']
            && isset($result['total_count'])
            && isset($context->magentoSearchQuery)
        ) {
            /** @var SearchQuery $magentoSearchQuery */
            $magentoSearchQuery = $context->magentoSearchQuery;
            if ($magentoSearchQuery && $magentoSearchQuery->getId()) {
                $magentoSearchQuery->setNumResults($result['total_count']);
                $this->searchHelper->updateSearchQueryNumResults($magentoSearchQuery);
            }
        }
        if (isset($context->correctedQuery)) {
            $result['corrected_query'] = $context->correctedQuery;
        }


        $resultObject = new DataObject(['result' => $result]);
        $this->eventManager->dispatch(
            self::PRODUCT_OBJECT_TYPE . '_resolver_result_return_before',
            ['result' => $resultObject]
        );
        $result = $resultObject->getData('result');

        // set args to context for eager loading etc. purposes
        $context->msProductsArgs = $args;
        $context->msProducts = $result;

        return $result;
    }

    /**
     * @param array $args
     * @param array $queryFields
     * @param bool $skipSort
     * @param bool $skipFacets
     * @return QueryInterface
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function prepareQuery(array $args, array $queryFields, bool $skipSort = false, bool $skipFacets = false): QueryInterface
    {
        $searchEngineClient = $this->getSearchEngineClient();

        $query = $searchEngineClient->getQuery();
        $this->handleFilters($query, $args);
        if (!$skipSort) {
            $this->handleSort($query, $args);
        }
        if (!$skipFacets) {
            $this->handleFacets($query, $args);
        }


        if (!$queryFields) {
            $maxPageSize = 50000;
            $query->addFieldsToSelect([
                $this->queryHelper->getFieldByAttributeCode('sku'),
            ]);
        } else {
            $this->handleFieldsToSelect($query, $queryFields);
            $maxPageSize = 100; // @todo this should depend on maximum page size in listing
        }

        $pageSize = (isset($args['pageSize']) && ($args['pageSize'] < $maxPageSize)) ? $args['pageSize'] : $maxPageSize;
        $query->setPageSize($pageSize);

        $searchQuery = $args['search'] ?? '';
        if ($searchQuery) {
            $searchText = Parser::parseSearchText($searchQuery);
            $query->setQueryText($searchText);
            $query->setQueryPrepend($this->configHelper->getConfigByPath(ConfigHelper::SEARCH_QUERY_BOOST) . $query->getQueryPrepend());
        }

        return $query;
    }


    /**
     * @param QueryInterface $query
     * @param $args
     * @throws LocalizedException
     */
    public function handleFilters($query, $args)
    {
        $query->addFilters([
            [$this->queryHelper->getFieldByProductAttributeCode(
                'store_id',
                $this->storeManager->getStore()->getId()
            )],
            [$this->queryHelper->getFieldByProductAttributeCode(
                'object_type',
                'product'
            )]
        ]);

        if (!isset($args['filter']['skus'])) {
            $query->addFilter($this->queryHelper->getFieldByProductAttributeCode(
                'visibility',
                $this->prepareFilterValue(['gt' => 1])
            ));
        }

        $this->addOutOfStockFilterProducts($query);

        if (isset($args['filter']) && is_array($args['filter']) && ($filters = $this->prepareFiltersByArgsFilter($args['filter'], $args['remove_tag_excluded'] ?? []))) {
            $query->addFilters($filters);
        }

        $this->eventManager->dispatch('prepare_msproduct_resolver_filters_add_after', ['query' => $query, 'args' => $args]);
    }

    /**
     * @param array $value
     *
     * @return array|Document\FieldValue|string
     */
    protected function prepareFilterValue(array $value)
    {
        // temporary leave below

        $key = key($value);
        if (count($value) > 1) {
            return implode(',', $value);
        }

        if ($key) {
            if (isset($value[$key]) && !is_numeric($value[$key]) && !is_array($value[$key])) {
                return $value[$key];
            }
            switch ($key) {
                case 'in':
                    return $value[$key];
                case 'gt':
                    return new Document\FieldValue(null, $value[$key] + 1, Document\FieldValue::IFINITY_CHARACTER);
                case 'lt':
                    return new Document\FieldValue(null, Document\FieldValue::IFINITY_CHARACTER, $value[$key] - 1);
                case 'gteq':
                    return new Document\FieldValue(null, $value[$key], Document\FieldValue::IFINITY_CHARACTER);
                case 'lteq':
                    return new Document\FieldValue(null, Document\FieldValue::IFINITY_CHARACTER, $value[$key]);
                case 'eq':
                default:
                    return (string)$value[$key];

            }
        }

        return '';
    }

    /**
     * @param QueryInterface $query
     * @throws LocalizedException
     */
    protected function addOutOfStockFilterProducts($query)
    {
        if (!$this->configHelper->getShowOutOfStockProducts()) {
            $query->addFilter(
                $this->queryHelper->getFieldByProductAttributeCode('status', Status::STATUS_ENABLED)
            );
        }
    }

    /**
     * @param array $filters
     *
     * @param array $removeTagExcluded
     * @return array
     */
    public function prepareFiltersByArgsFilter(array $filters, array $removeTagExcluded = [])
    {
        $preparedFilters = [];
        foreach ($filters as $key => $filter) {
            if ($key === 'attributes') {
                $preparedFilters = array_merge($preparedFilters, $this->prepareAttributes($filter, $removeTagExcluded));
            } else {
                $field = $this->queryHelper->getFieldByProductAttributeCode($key, $filter);
                $preparedFilters[] = [$field];
            }
        }

        return $preparedFilters;
    }

    /**
     * @param $attributes
     * @param array $removeTagExcluded
     * @return array
     */
    public function prepareAttributes($attributes, $removeTagExcluded = [])
    {
        $preparedFilters = [];

        foreach ($attributes as $attribute => $value) {
            $filterData = explode('=', $value);
            if (count($filterData) < 2) {
                continue;
            }
            $valueParts = explode(',', $filterData[1]);
            if (count($valueParts) > 1) {
                $fieldValue = ['in' => $valueParts];
            } else {
                $fieldValue = ['eq' => $filterData[1]];
            }
            if ($field = $this->queryHelper->getFieldByAttributeCode(
                $filterData[0],
                $this->prepareFilterValue($fieldValue)
            )) {
                if (!in_array($filterData[0], $removeTagExcluded)) {
                    $field->setExcluded(true);
                }
                $preparedFilters[] = [$field];
            }
        }

        return $preparedFilters;
    }

    /**
     * @param string $originalSearchQuery
     * @return string|null
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function useSpellchecking(string $originalSearchQuery): ?string
    {
        $spellingCheckResponse = $this->getSearchEngineClient()->checkSpelling($originalSearchQuery);
        $alternativeSerchTexts = $this->searchHelper->getAlternativeSearchTexts($originalSearchQuery, $spellingCheckResponse);

        $responses = [];
        $bestText = null;
        $bestTextCount = 0;
        foreach ($alternativeSerchTexts as $serchText) {
            $testQuery = $this->prepareQuery(['search' => $serchText], [], true, true);
            $testResponse = $testQuery->getResponse();
            if (
                (!$bestText && ($testResponse->getNumFound() > 0)) ||
                $testResponse->getNumFound() > $bestTextCount
            ) {
                $bestText = $serchText;
                $bestTextCount = $testResponse->getNumFound();
            }
        }
        if (!$bestText) {
            $alternativeSearchTextsAdditionalTry = array_diff(
                $this->searchHelper->getAlternativeSearchTexts($originalSearchQuery, $spellingCheckResponse, false),
                $alternativeSerchTexts
            );
            foreach ($alternativeSearchTextsAdditionalTry as $serchText) {
                $testQuery = $this->prepareQuery(['search' => $serchText], [], true, true);
                $testResponse = $testQuery->getResponse();
                if (
                    (!$bestText && ($testResponse->getNumFound() > 0)) ||
                    $testResponse->getNumFound() > $bestTextCount
                ) {
                    $bestText = $serchText;
                    $bestTextCount = $testResponse->getNumFound();
                }
            }

        }


        return $bestText;
    }

    /**
     * @param QueryInterface $query
     * @param $args
     * @throws LocalizedException
     */
    public function handleSort($query, $args)
    {
        $sortDir = 'ASC';

        if (isset($args['sort']['sort_order']) && in_array($args['sort']['sort_order'], ['ASC', 'DESC'])) {
            $sortDir = $args['sort']['sort_order'];
        }

        $sort = false;
        if (isset($args['sort']) && isset($args['sort']['sort_by'])) {
            $sort = $this->prepareSortField($args['sort']['sort_by'], $args['sort']['sort_order']);
        } elseif (isset($args['search']) && $args['search']) {
            $sort = $this->prepareSortField('score');
            $sortDir = 'DESC';
        }

        $this->eventManager->dispatch('prepare_msproduct_resolver_sort_add_before', ['sort' => $sort, 'sortDir' => $sortDir]);

        if ($sort) {
            $query->addSort($sort);
        }

        $this->eventManager->dispatch('prepare_msproduct_resolver_sort_add_after', ['query' => $query, 'args' => $args]);
    }

    /**
     * @param $sort
     * @param string $sortDir
     * @return Document\Field
     * @throws LocalizedException
     */
    protected function prepareSortField($sort, $sortDir = 'DESC')
    {
        return $this->queryHelper->getFieldByAttributeCode($sort, $sortDir);
    }

    /**
     * @param QueryInterface $query
     * @param $args
     * @throws LocalizedException
     */
    public function handleFacets($query, $args)
    {
        if ($categoryFilter = $query->getFilter('category_id')) {
            $facetFields = $this->facetsHelper->getFacetFieldsByCategory($categoryFilter['field']->getValue());
//            $query->addFacets($facetFields);
            $query->addFacets($facetFields);

            $statsFields = $this->facetsHelper->getStatsFieldsByCategory($categoryFilter['field']->getValue());
            $query->addStats($statsFields);
        }

        if ($baseStats = $this->configHelper->getProductAttributesBaseStats()) {
            foreach ($baseStats as $baseStat) {
                $query->addStat($this->queryHelper->getFieldByAttributeCode($baseStat));
            }
        }

        if ($baseFacets = $this->configHelper->getProductAttributesBaseFacets()) {
            foreach ($baseFacets as $baseFacet) {
                $query->addFacet($this->queryHelper->getFieldByAttributeCode($baseFacet));
            }
        }

        /**
         * @todo only for testing purpopse, eventually handle facets from category,
         */
        $query->addFacet(
            $this->queryHelper->getFieldByAttributeCode('category_id')
        );
    }

    /**
     * @param QueryInterface $query
     * @param array $queryFields
     * @throws LocalizedException
     */
    public function handleFieldsToSelect($query, $queryFields)
    {
        $fields = $this->parseQueryFields($queryFields);

        $fieldsToSelect = [];
        foreach ($fields as $attributeCode => $value) {
            $fieldsToSelect[] = $this->queryHelper->getFieldByAttributeCode($attributeCode);
        }

        $query->addFieldsToSelect($fieldsToSelect);
    }

    /**
     * @param array $queryFields
     * @return array
     */
    public function parseQueryFields(array $queryFields)
    {
        foreach ($queryFields as $name => $value) {
            if (is_array($value)) {
                unset($queryFields[$name]);
                continue;
            }
        }

        return $queryFields;
    }

    /**
     * @param $response
     * @param $debug
     * @return array
     */
    public function prepareResultData($response, $debug)
    {
        $debugInfo = [];
        if ($debug) {
            $debugQuery = $response->getDebugInfo();
            $debugInfo = $debugQuery['params'] ?? [];
            $debugInfo['code'] = $debugQuery['code'] ?? 0;
            $debugInfo['message'] = $debugQuery['message'] ?? '';
            $debugInfo['uri'] = $debugQuery['uri'] ?? '';
        }

        $products = $this->getProducts($response->getDocumentsCollection());
        $data = [
            'total_count' => $response->getNumFound(),
            'items_ids' => $products['items_ids'],
            'items' => $products['items'],
            'page_info' => [
                'page_size' => count($response->getDocumentsCollection()),
                'current_page' => $response->getCurrentPage(),
                'total_pages' => $response->getNumFound()
            ],
            'facets' => $this->prepareFacets($response->getFacets()),
            'stats' => $this->prepareStats($response->getStats()),
            'debug_info' => $debugInfo,
        ];

        return $data;
    }

    /**
     * @param $documentCollection
     * @param string $idType
     * @return array
     */
    public function getProducts($documentCollection)
    {
        $products = [];
        $productIds = [];

        $i = 300; // default for product order in search

        /** @var Document $productDocument */
        foreach ($documentCollection as $productDocument) {
            $this->eventManager->dispatch('prepare_msproduct_resolver_result_before', ['productDocument' => $productDocument]);

            $productData = [];
            foreach ($productDocument->getFields() as $field) {
                if ($field->getName() == 'sku') {
                    $productIds[] = $field->getValue();
                }
                $productData[$field->getName()] = $field->getValue();
            }

            $this->eventManager->dispatch('prepare_msproduct_resolver_result_after', ['productData' => $productData]);
            $products[$i] = $productData;
            $i++;
        }

        ksort($productIds);
        ksort($products);

        return ['items' => $products, 'items_ids' => $productIds];
    }

    /**
     * @param array $facets
     * @return array
     */
    public function prepareFacets($facets)
    {
        $preparedFacets = [];

        foreach ($facets as $field => $values) {
            $preparedValues = [];

            foreach ($values as $valueId => $count) {
                if ($valueId) {
                    $preparedValues[] = [
                        'value_id' => $valueId,
                        'count' => $count
                    ];
                }
            }

            if ($preparedValues) {
                $preparedFacets[] = [
                    'code' => $field,
                    'values' => $preparedValues
                ];
            }
        }

        return $preparedFacets;
    }

    /**
     * @param $stats
     * @return array
     */
    public function prepareStats($stats)
    {
        $preparedStats = [];
        foreach ($stats as $field => $value) {
            $preparedStats[] = [
                'code' => FieldHelper::createFieldByResponseField($field, null)->getName(),
                'values' => $value
            ];
        }

        return $preparedStats;
    }

    /**
     * @param array $solrAttributes
     * @return array
     */
    public function parseAttributeCode($solrAttributes = [])
    {
        $newSolrAttributes = [];
        foreach ($solrAttributes as $key => $attribute) {
            $attributeCode = str_replace(['_facet', '_f'], ['', ''], $attribute['code']);
            $newSolrAttributes[$attributeCode]['code'] = $attributeCode;
            $newSolrAttributes[$attributeCode]['values'] = $attribute['values'];
        }

        return $newSolrAttributes;
    }

    /**
     * @param Document $document
     * @return array
     */
    protected function prepareProductAttributes(Document $document): array
    {
        $attributes = [];
        /** @var Document\Field $field */
        foreach ($document->getFields() as $field) {
            if (in_array($field->getName(), self::$attributesToSkip)) {
                continue;
            }

            $attribute = [];

            $name = $field->getName();
            $value = $field->getValue();
            if (is_array($value)) {
                $value = implode(', ', $value);
            }

            $attribute['attribute_code'] = $name;
            $attribute['value'] = $value;

            $attributes[] = $attribute;
        }

        return $attributes;
    }
}
