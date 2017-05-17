<?php
/**
 * Recognizes mData sent from DataTables where dotted notations represent a related
 * entity. For example, defining the following in DataTables...
 *
 * "aoColumns": [
 *     { "mData": "id" },
 *     { "mData": "description" },
 *     { "mData": "customer.first_name" },
 *     { "mData": "customer.last_name" }
 * ]
 *
 * ...will result in a a related Entity called customer to be retrieved, and the
 * first and last name will be returned, respectively, from the customer entity.
 *
 * There are no entity depth limitations. You could just as well define nested
 * entity relations, such as...
 *
 *     { "mData": "customer.location.address" }
 *
 * Felix-Antoine Paradis is the author of the original implementation this is
 * built off of, see: https://gist.github.com/1638094 
 */

namespace LanKit\DatatablesBundle\Datatables;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;

use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Config\Definition\Exception\Exception;
use Symfony\Component\HttpFoundation\Response;

class Datatable
{
    /**
     * Doctrine innerJoin type
     */
    const JOIN_INNER = 'inner';

    /**
     * Doctrine leftJoin type
     */
    const JOIN_LEFT = 'left';

    /**
     * A result type of array
     */
    const RESULT_ARRAY = 'Array';

    /**
     * A result type of JSON
     */
    const RESULT_JSON = 'Json';

    /**
     * A result type of a Response object
     */
    const RESULT_RESPONSE = 'Response';

    /**
     * @var array Holds callbacks to be used
     */
    protected $callbacks = array(
        'WhereBuilder' => array(),
    );

    /**
     * @var boolean Whether or not to use the Doctrine Paginator utility
     */
    protected $useDoctrinePaginator = true;

    /**
     * @var array containing boolean values, whether to hide the callback in filtered count
     */
    protected $hideFilteredCount = [];

    /**
     * @var string Whether or not to add DT_RowId to each record
     */
    protected $useDtRowId = false;

    /**
     * @var string Whether or not to add DT_RowClass to each record if it is set
     */
    protected $useDtRowClass = true;

    /**
     * @var string The class to use for DT_RowClass
     */
    protected $dtRowClass = null;

    /**
     * @var object The serializer used to JSON encode data
     */
    protected $serializer;

    /**
     * @var string The default join type to use
     */
    protected $defaultJoinType;

    /**
     * @var object The metadata for the root entity
     */
    protected $metadata;

    /**
     * @var object The Doctrine Entity Repository
     */
    protected $repository;

    /**
     * @var object The Doctrine Entity Manager
     */
    protected $em;

    /**
     * @var string  Used as the query builder identifier value
     */
    protected $tableName;

    /**
     * @var array All the request variables as an array
     */
    protected $request;

    /**
     * @var array The parsed request variables for the DataTable
     */
    protected $parameters;

    /**
     * @var array Information relating to the specific columns requested
     */
    protected $associations = array();

    /**
     * @var array SQL joins used to construct the QueryBuilder query
     */
    protected $assignedJoins = array();

    /**
     * @var array The SQL join type to use for a column
     */
    protected $joinTypes = array();

    /**
     * @var object The QueryBuilder instance
     */
    protected $qb;

    /**
     * @var integer The number of records the DataTable can display in the current draw
     */
    protected $offset;

    /**
     * @var string Information for DataTables to use for rendering
     */
    protected $echo;

    /**
     * @var integer The display start point in the current DataTables data set
     */
    protected $amount;

    /**
     * @var string The DataTables global search string
     */
    protected $search;

    /**
     * @var array The primary/unique ID for an Entity. Needed to pull partial objects
     */
    protected $identifiers = array();

    /**
     * @var string The primary/unique ID for the root entity
     */
    protected $rootEntityIdentifier;

    /**
     * @var integer The total amount of results to get from the database
     */
    protected $limit;

    /**
     * @var array The formatted data from the search results to return to DataTables.js
     */
    protected $datatable;

    /**
     * @var DatatablesModel model containing all input and output data
     */
    protected $datatablesModel;

    public function __construct(array $request, EntityRepository $repository, ClassMetadata $metadata, EntityManager $em, $serializer)
    {
        $this->em = $em;
        $this->datatablesModel = new DatatablesModel($request);

        $this->request = $request;
        $this->repository = $repository;
        $this->metadata = $metadata;
        $this->serializer = $serializer;
        $this->tableName = Container::camelize($metadata->getTableName());
        $this->defaultJoinType = self::JOIN_INNER;
        $this->defaultResultType = self::RESULT_RESPONSE;
        $this->setParameters();
        $this->qb = $em->createQueryBuilder();
        $this->echo = $this->datatablesModel->getDraw();
        $this->search = $this->datatablesModel->getSearch()['value'];
        $this->offset = $this->datatablesModel->getStart();
        $this->amount = $this->datatablesModel->getLength();

        $identifiers = $this->metadata->getIdentifierFieldNames();
        $this->rootEntityIdentifier = array_shift($identifiers);
    }

    /**
     * @return array All the paramaters (columns) used for this request
     */
    public function getParameters()
    {
        return $this->parameters;
    }

    /**
     * @param boolean $useDtRowId Whether or not to add DT_RowId to each record
     * @return Datatable
     */
    public function useDtRowId($useDtRowId)
    {
        $this->useDtRowId = (bool) $useDtRowId;

        return $this;
    }

    /**
     * @param boolean $useDtRowClass Whether or not to add DT_RowClass to each record
     * @return Datatable
     */
    public function useDtRowClass($useDtRowClass)
    {
        $this->useDtRowClass = (bool) $useDtRowClass;

        return $this;
    }

    /**
     * @param string $dtRowClass The class to use for DT_RowClass on each record
     * @return Datatable
     */
    public function setDtRowClass($dtRowClass)
    {
        $this->dtRowClass = $dtRowClass;

        return $this;
    }

    /**
     * @param boolean $useDoctrinePaginator Whether or not to use the Doctrine Paginator utility
     * @return Datatable
     */
    public function useDoctrinePaginator($useDoctrinePaginator)
    {
        $this->useDoctrinePaginator = (bool) $useDoctrinePaginator;

        return $this;
    }

    /**
     * Parse and configure parameter/association information for this DataTable request
     */
    public function setParameters()
    {
        $params = array();
        $associations = array();
        foreach ($this->datatablesModel->getColumns() as $key => $data) {
            // if a function or a number is used in the data property
            // it should not be considered
            if( !preg_match('/^(([\d]+)|(function)|(\s*)|(^$))$/', $data['data']) ) {
                $fields = explode('.', $data['data']);
                $params[$key] = $data['data'];
                $associations[$key] = array('containsCollections' => false);

                if (count($fields) > 1) {
                    $this->setRelatedEntityColumnInfo($associations[$key], $fields);
                } else {
                    $this->setSingleFieldColumnInfo($associations[$key], $fields[0]);
                }
            }
        }

        $this->parameters = $params;
        // do not reindex new array, just add them
        $this->associations = $associations + $this->associations;
    }

    /**
     * Parse a dotted-notation column format from the mData, and sets association
     * information
     *
     * @param array $association Association information for a column (by reference)
     * @param array $fields The column fields from dotted notation
     */
    protected function setRelatedEntityColumnInfo(array &$association, array $fields) {
        $mdataName = implode('.', $fields);
        $lastField = Container::camelize(array_pop($fields));
        $joinName = $this->tableName;
        $entityName = '';
        $columnName = '';

        // loop through the related entities, checking the associations as we go
        $metadata = $this->metadata;
        while ($field = array_shift($fields)) {
            $columnName .= empty($columnName) ? $field : ".$field";
            $entityName = lcfirst(Container::camelize($field));
            if ($metadata->hasAssociation($entityName)) {
                $joinOn = "$joinName.$entityName";
                if ($metadata->isCollectionValuedAssociation($entityName)) {
                    $association['containsCollections'] = true;
                }
                $metadata = $this->em->getClassMetadata(
                    $metadata->getAssociationTargetClass($entityName)
                );
                $joinName .= '_' . $this->getJoinName(
                    $metadata,
                    Container::camelize($metadata->getTableName()),
                    $entityName
                );
                // The join required to get to the entity in question
                if (!isset($this->assignedJoins[$joinName])) {
                    $this->assignedJoins[$joinName]['joinOn'] = $joinOn;
                    $this->assignedJoins[$joinName]['mdataColumn'] = $columnName;
                    $this->identifiers[$joinName] = $metadata->getIdentifierFieldNames();
                }
            }
            else {
                throw new Exception(
                    "Association  '$entityName' not found ($mdataName)",
                    '404'
                );
            }
        }

        // Check the last field on the last related entity of the dotted notation
        if (!$metadata->hasField(lcfirst($lastField))) {
            throw new Exception(
                "Field '$lastField' on association '$entityName' not found ($mdataName)",
                '404'
            );
        }
        $association['entityName'] = $entityName;
        $association['fieldName'] = $lastField;
        $association['joinName'] = $joinName;
        $association['fullName'] = $this->getFullName($association);
    }

    /**
     * Configures association information for a single field request from the main entity
     *
     * @param array $association The association information as a reference
     * @param string $fieldName The field name on the main entity
     */
    protected function setSingleFieldColumnInfo(array &$association, $fieldName) {
        $fieldName = Container::camelize($fieldName);

        if (!$this->metadata->hasField(lcfirst($fieldName))) {
            throw new Exception(
                "Field '$fieldName' not found.)",
                '404'
            );
        }

        $association['fieldName'] = $fieldName;
        $association['entityName'] = $this->tableName;
        $association['fullName'] = $this->tableName . '.' . lcfirst($fieldName);
    }

    /**
     * Based on association information and metadata, construct the join name
     *
     * @param ClassMetadata $metadata Doctrine metadata for an association
     * @param string $tableName The table name for the join
     * @param string $entityName The entity name of the table
     * @return string
     */
    protected function getJoinName(ClassMetadata $metadata, $tableName, $entityName)
    {
        $joinName = $tableName;

        // If it is self-referencing then we must avoid collisions
        if ($metadata->getName() == $this->metadata->getName()) {
            $joinName .= "_$entityName";   
        }

        return $joinName;
    }

    /**
     * Based on association information, construct the full name to refer to in queries
     *
     * @param array $associationInfo Association information for the column
     * @return string The full name to refer to this column as in QueryBuilder statements
     */
    protected function getFullName(array $associationInfo)
    {
        return $associationInfo['joinName'] . '.' . lcfirst($associationInfo['fieldName']);
    }

    /**
     * Set the default join type to use for associations. Defaults to JOIN_INNER
     *
     * @param string $joinType The join type to use, should be of either constant: JOIN_INNER, JOIN_LEFT
     * @return Datatable
     */
    public function setDefaultJoinType($joinType)
    {
        if (defined('self::JOIN_' . strtoupper($joinType))) {
            $this->defaultJoinType = constant('self::JOIN_' . strtoupper($joinType));
        }

        return $this;
    }

    /**
     * Set the type of join for a specific column/parameter
     *
     * @param string $column The column/parameter name
     * @param string $joinType The join type to use, should be of either constant: JOIN_INNER, JOIN_LEFT
     * @return Datatable
     */
    public function setJoinType($column, $joinType)
    {
        if (defined('self::JOIN_' . strtoupper($joinType))) {
            $this->joinTypes[$column] = constant('self::JOIN_' . strtoupper($joinType));
        }

        return $this;
    }

    /**
     * Set the scope of the result set
     *
     * @param QueryBuilder $qb The Doctrine QueryBuilder object
     */
    public function setLimit(QueryBuilder $qb)
    {
        if (isset($this->offset) && $this->amount != '-1') {
            $qb->setFirstResult($this->offset)->setMaxResults($this->amount);
        }
    }

    /**
     * Set any column ordering that has been requested
     *
     * @param QueryBuilder $qb The Doctrine QueryBuilder object
     */
    public function setOrderBy(QueryBuilder $qb)
    {
        foreach ($this->datatablesModel->getOrder() as $key => $order) {
            if ((bool)$this->datatablesModel->getColumns()[$order['column']]['orderable'] === true) {
                // if sort col is not existent in associations
                // try to find one
                for ($index = $order['column']; $index < count($this->datatablesModel->getColumns()); $index++) {
                    if ( array_key_exists($index, $this->associations) ) {
                        break;
                    }
                }

                if ( array_key_exists($index, $this->associations) ) {
                    $qb->addOrderBy(
                        $this->associations[$index]['fullName'],
                        $order['dir']
                    );
                }
            }
        }
    }

    /**
     * Configure the WHERE clause for the Doctrine QueryBuilder if any searches are specified
     *
     * @param QueryBuilder $qb The Doctrine QueryBuilder object
     */
    public function setWhere(QueryBuilder $qb)
    {
        // Global filtering
        if (!empty($this->search)) {
            // search Text is splitted so each word can be searched
            $searchArray = array_filter(explode(' ', $this->search));
            $andExpr = $qb->expr()->andX();
            foreach ($searchArray as $index => $searchField) {
                $orExpr = $qb->expr()->orX();
                foreach (array_keys($this->parameters) as $key) {
                    if ((bool)$this->datatablesModel->getColumns()[$key]['searchable'] === true) {
                        $qbParam = "sSearch_global_{$this->associations[$key]['entityName']}_{$this->associations[$key]['fieldName']}_{$index}";
                        $orExpr->add($qb->expr()->like(
                            $this->associations[$key]['fullName'],
                            ":$qbParam"
                        ));
                        $qb->setParameter($qbParam, "%" . $searchField . "%");
                    }
                }
                $andExpr->add($orExpr);
            }
            $qb->andWhere($andExpr);
        }

        // Individual column filtering
        $andExpr = $qb->expr()->andX();
        foreach (array_keys($this->parameters) as $key) {
            if ((bool)$this->datatablesModel->getColumns()[$key]['searchable'] === true && !empty($this->datatablesModel->getColumns()[$key]['search']['value'])) {
                $qbParam = "sSearch_single_{$this->associations[$key]['entityName']}_{$this->associations[$key]['fieldName']}";
                $andExpr->add($qb->expr()->like(
                    $this->associations[$key]['fullName'],
                    ":$qbParam"
                ));
                $qb->setParameter($qbParam, "%" . $this->datatablesModel->getSearch()[$key]['search']['value'] . "%");
            }
        }
        if ($andExpr->count() > 0) {
            $qb->andWhere($andExpr);
        }

        if (!empty($this->callbacks['WhereBuilder'])) {
            foreach ($this->callbacks['WhereBuilder'] as $callback) {
                $callback($qb);
            }
        }
    }
	
	/**
     * Adds a manual association
     * 
     * @param string $name - the dotted notation like in mData of the field you need adding
     */
    public function addManualAssociation($name) {
        $newAssociation = array('containsCollections' => false);
        $fields = explode('.', $name);
        $this->setRelatedEntityColumnInfo($newAssociation, $fields);
        $this->associations[] = $newAssociation;
    }

    /**
     * Configure joins for entity associations
     *
     * @param QueryBuilder $qb The Doctrine QueryBuilder object
     */
    public function setAssociations(QueryBuilder $qb)
    {
        foreach ($this->assignedJoins as $joinName => $joinInfo) {
            $joinType = isset($this->joinTypes[$joinInfo['mdataColumn']]) ?
                $this->joinTypes[$joinInfo['mdataColumn']] :  $this->defaultJoinType;
            call_user_func_array(array($qb, $joinType . 'Join'), array(
                $joinInfo['joinOn'],
                $joinName
            ));
        }
    }

    /**
     * Configure the specific columns to select for the query
     *
     * @param QueryBuilder $qb The Doctrine QueryBuilder object
     */
    public function setSelect(QueryBuilder $qb)
    {
        $columns = array();
        $partials = array();

        // Make sure all related joins are added as needed columns. A column many entities deep may rely on a
        // column not specifically requested in the mData
        foreach (array_keys($this->assignedJoins) as $joinName) {
            $columns[$joinName] = array();
        }

        // Combine all columns to pull
        foreach ($this->associations as $column) {
            $parts = explode('.', $column['fullName']);
            $columns[$parts[0]][] = $parts[1];
        }

        // Partial column results on entities require that we include the identifier as part of the selection
        foreach ($this->identifiers as $joinName => $identifiers) {
            if (!in_array($identifiers[0], $columns[$joinName])) {
                array_unshift($columns[$joinName], $identifiers[0]);
            }
        }

        // Make sure to include the identifier for the main entity
        if (!in_array($this->rootEntityIdentifier, $columns[$this->tableName])) {
            array_unshift($columns[$this->tableName], $this->rootEntityIdentifier);
        }

        foreach ($columns as $columnName => $fields) {
            $partials[] = 'partial ' . $columnName . '.{' . implode(',', $fields) . '}';
        }

        $qb->select(implode(',', $partials));
        $qb->from($this->metadata->getName(), $this->tableName);
    }

    /**
     * Method to execute after constructing this object. Configures the object before
     * executing getSearchResults()
     */
    public function makeSearch() 
    {
        $this->setSelect($this->qb);
        $this->setAssociations($this->qb);
        $this->setWhere($this->qb);
        $this->setOrderBy($this->qb);
        $this->setLimit($this->qb);

        return $this;
    }

    /**
     * Check if an array is associative or not.
     *
     * @link http://stackoverflow.com/questions/173400/php-arrays-a-good-way-to-check-if-an-array-is-associative-or-numeric
     * @param array $array An arrray to check
     * @return bool true if associative
     */
    protected function isAssocArray(array $array) {
        return (bool)count(array_filter(array_keys($array), 'is_string'));
    }

    /**
     * Execute the QueryBuilder object, parse and save the results
     */
    public function executeSearch()
    {
        // consider translations in database
        $query = $this->qb->getQuery()
            ->setHydrationMode(Query::HYDRATE_ARRAY);
        if (defined("\\Gedmo\\Translatable\\TranslatableListener::HINT_FALLBACK")) {
            $query
                ->setHint(
                    Query::HINT_CUSTOM_OUTPUT_WALKER,
                    'Gedmo\\Translatable\\Query\\TreeWalker\\TranslationWalker'
                )
                ->setHint(constant("\\Gedmo\\Translatable\\TranslatableListener::HINT_FALLBACK"), true);
        }
        $items = $this->useDoctrinePaginator ?
            new Paginator($query, $this->doesQueryContainCollections()) : $query->execute();

        $data = [];
        foreach ($items as $item) {
            if ($this->useDtRowClass && !is_null($this->dtRowClass)) {
                $item['DT_RowClass'] = $this->dtRowClass;
            }
            if ($this->useDtRowId) {
                $item['DT_RowId'] = $item[$this->rootEntityIdentifier];
            }
            // Go through each requested column, transforming the array as needed for DataTables
            foreach ($this->parameters as $index => $parameter) { //($i = 0 ; $i < count($this->parameters); $i++) {
                // Results are already correctly formatted if this is the case...
                if (!$this->associations[$index]['containsCollections']) {
                    continue;
                }

                $rowRef = &$item;
                $fields = explode('.', $this->parameters[$index]);

                // Check for collection based entities and format the array as needed
                while ($field = array_shift($fields)) {
                    $rowRef = &$rowRef[$field];
                    // We ran into a collection based entity. Combine, merge, and continue on...
                    if (!empty($fields) && !$this->isAssocArray($rowRef)) {
                        $children = array();
                        while ($childItem = array_shift($rowRef)) {
                            $children = array_merge_recursive($children, $childItem);
                        }
                        $rowRef = $children;
                    }
                }
            }
            $data[] = $item;
        }

        $this->datatable = $this->datatablesModel->getOutputData($data, (int)$this->echo, $this->getCountAllResults(), $this->getCountFilteredResults());
        return $this;
    }

    /**
     * @return boolean Whether any mData contains an association that is a collection
     */
    protected function doesQueryContainCollections()
    {
        foreach ($this->associations as $column) {
            if ($column['containsCollections']) {
                return true;
            }
        }
        return false;
    }

    /**
     * Set the default result type to use when calling getSearchResults
     *
     * @param string $resultType The result type to use, should be one of: RESULT_JSON, RESULT_ARRAY, RESULT_RESPONSE
     * @return Datatable
     */
    public function setDefaultResultType($resultType)
    {
        if (defined('self::RESULT_' . strtoupper($resultType))) {
            $this->defaultResultType = constant('self::RESULT_' . strtoupper($resultType));
        }

        return $this;
    }

    /**
     * Creates and executes the DataTables search, returns data in the requested format
     *
     * @param string $resultType The result type to use, should be one of: RESULT_JSON, RESULT_ARRAY, RESULT_RESPONSE
     * @return mixed The DataTables data in the requested/default format
     */
    public function getSearchResults($resultType = '')
    {
        if (empty($resultType) || !defined('self::RESULT_' . strtoupper($resultType))) {
            $resultType = $this->defaultResultType;
        }
        else {
            $resultType = constant('self::RESULT_' . strtoupper($resultType));
        }

        $this->makeSearch();
        $this->executeSearch();

        return call_user_func(array(
            $this, 'getSearchResults' . $resultType
        ));
    }

    /**
     * @return string The DataTables search result as JSON
     */
    public function getSearchResultsJson()
    {
        return $this->serializer->serialize($this->datatable, 'json');
    }

    /**
     * @return array The DataTables search result as an array
     */
    public function getSearchResultsArray()
    {
        return $this->datatable;
    }

    /**
     * @return object The DataTables search result as a Response object
     */
    public function getSearchResultsResponse()
    {
        $response = new Response($this->serializer->serialize($this->datatable, 'json'));
        $response->headers->set('Content-Type', 'application/json');

        return $response;
    }

    /**
     * @return int Total query results before searches/filtering
     */
    public function getCountAllResults()
    {
        $qb = $this->repository->createQueryBuilder($this->tableName)
            ->select('count(' . $this->tableName . '.' . $this->rootEntityIdentifier . ')');

        if (!empty($this->callbacks['WhereBuilder']))  {
            foreach ($this->callbacks['WhereBuilder'] as $key => $callback) {
                if (true === $this->hideFilteredCount[$key]) {
                    $callback($qb);
                }
            }
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }
    
    /**
     * @return int Total query results after searches/filtering
     */
    public function getCountFilteredResults()
    {
        $qb = $this->repository->createQueryBuilder($this->tableName);
        $qb->select('count(distinct ' . $this->tableName . '.' . $this->rootEntityIdentifier . ')');
        $this->setAssociations($qb);
        $this->setWhere($qb);
        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * @param object $callback A callback function to be used at the end of 'setWhere'
     * @param bool $hideFiltered Whether to hide the callback in filtered count
     * @return Datatable
     * @throws \Exception
     */
    public function addWhereBuilderCallback($callback, $hideFiltered = true) {
        if (!is_callable($callback)) {
            throw new \Exception("The callback argument must be callable.");
        }
        $this->callbacks['WhereBuilder'][] = $callback;
        $this->hideFilteredCount[] = $hideFiltered;

        return $this;
    }

    public function getOffset()
    {
        return $this->offset;
    }

    public function getEcho()
    {
        return $this->echo;
    }

    public function getAmount()
    {
        return $this->amount;
    }

    public function getSearch()
    {
        return  "%" . $this->search . "%";
    }

    public function getQueryBuilder()
    {
        return  $this->qb;
    }
}
