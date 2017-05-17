<?php

namespace LanKit\DatatablesBundle\Datatables;

use Symfony\Component\HttpFoundation\Request;

/**
 * Description of DatatablesModel
 *
 * All values and descriptions referencing Datatables.net
 *  https://datatables.net/upgrade/1.10-convert
 *  https://datatables.net/manual/server-side       >= v1.10
 *  http://legacy.datatables.net/usage/server-side  <= v1.9
 *
 * @author: nschoch
 */
class DatatablesModel {

    #region attributes

    const outputNames = [
        '1' => [
            'totalRecords' => 'iTotalRecords',
            'displayRecords' => 'iTotalDisplayRecords',
            'draw' => 'sEcho',
            'data' => 'aaData'
        ],
        '2' => [
            'totalRecords' => 'recordsTotal',
            'displayRecords' => 'recordsFiltered',
            'draw' => 'draw',
            'data' => 'data'
        ]
    ];

    /**
     * Draw counter to ensure correct sequence
     *
     * @var int draw counter
     */
    private $draw;

    /**
     * Indicator referencing start point of data set
     *
     * @var int
     */
    private $start;

    /**
     * Number of records to display
     * -1 means all records
     *
     * @var int
     */
    private $length;

    /**
     * Search array containing
     *  ['value'] string    - Global search value
     *  ['regex'] bool      - Should the global search value be treated as a regex pattern
     *
     * @var array
     */
    private $search = array();

    /**
     * Order array containing arrays for order values of each column
     *  [i]
     *      ['column'] int  - Column to which ordering should be applied
     *      ['dir'] string  - (asc|desc) Ordering direction for this column
     *
     * @var array
     */
    private $order = array();

    /**
     * Data source containing arrays with column data
     * [i]
     *   ['data'] string    - Column's data source
     *   ['name'] string    - Column's name
     *   ['searchable'] bool- Flag to indicate if this column is searchable
     *   ['orderable'] bool - Flag to indicate if this column is orderable
     *   ['search'] array
     *     ['value'] string - Column search value
     *     ['regex'] bool   - Should the column search value be treated as a regex pattern
     *
     * @var array
     */
    private $columns = array();

    /**
     * If version is null, it is an unsupported version
     *  1 - Datatables <= v1.9
     *  2 - Datatables >= v1.10
     * @var int|null
     */
    private $version = null;

    #endregion attributes

    #region construct

    /**
     * DatatableModel constructor.
     *
     * @param array $requestData
     */
    public function __construct(array $requestData) {
        $this->setVersion($requestData);
        $this->prepareData($requestData);
    }

    /**
     * Set version dependent on the underlying data
     *
     * @param array $requestData
     */
    private function setVersion(array $requestData) {
        if (isset($requestData['draw'])) {
            $this->version = 2;
        } elseif (isset($requestData['sEcho'])) {
            $this->version = 1;
        } else {
            $this->version = null;
        }
    }

    /**
     * Set data dependent on the underlying data
     *
     * @param array $requestData
     */
    private function prepareData(array $requestData) {
        if (null === $this->version) {
            return;
        }

        if (1 === $this->version) {
            $this->prepareDataV1($requestData);
        }

        if (2 === $this->version) {
            $this->prepareDataV2($requestData);
        }
    }

    /**
     * Set data dependent on the underlying data for version 1
     *
     * @param array $requestData
     */
    private function prepareDataV1(array $requestData) {
        $this->draw = (int)$requestData['sEcho'];
        $this->start = (int)$requestData['iDisplayStart'];
        $this->length = (int)$requestData['iDisplayLength'];

        $this->search = array(
            'value' => $requestData['sSearch'],
            'regex' => $requestData['bRegex']
        );

        $orderCount = $requestData['iSortingCols'];
        for ($i = 0; $i < $orderCount; $i++) {
            $this->order[$i] = array(
                'column' => (int)$requestData['iSortCol_' . $i],
                'dir' => $requestData['sSortDir_' . $i]
            );
        }

        $columnNames = explode(',', $requestData['sColumns']);
        $columnCount = (int)$requestData['iColumns'];
        for ($i = 0; $i < $columnCount; $i++) {
            $this->columns[$i] = array(
                'data' => $requestData['mDataProp_' . $i],
                'name' => $columnNames[$i],
                'searchable' => $requestData['bSearchable_' . $i],
                'orderable' => $requestData['bSortable_' . $i],
                'search' => array(
                    'value' => $requestData['sSearch_' . $i],
                    'regex' => $requestData['bRegex_' . $i]
                )
            );
        }
    }

    /**
     * Set data dependent on the underlying data for version 2
     *
     * @param array $requestData
     */
    private function prepareDataV2(array $requestData) {
        $this->draw = (int)$requestData['draw'];
        $this->start = (int)$requestData['start'];
        $this->length = (int)$requestData['length'];
        $this->search = $requestData['search'];
        $this->order = $requestData['order'];
        $this->columns = $requestData['columns'];
    }

    #endregion construct

    #region getter/setter

    /**
     * @return int
     */
    public function getDraw() {
        $this->checkVersion();

        return $this->draw;
    }

    /**
     * @param int $draw
     */
    public function setDraw($draw) {
        $this->draw = $draw;
    }

    /**
     * @return int
     */
    public function getStart() {
        $this->checkVersion();

        return $this->start;
    }

    /**
     * @param int $start
     */
    public function setStart($start) {
        $this->start = $start;
    }

    /**
     * @return int
     */
    public function getLength() {
        $this->checkVersion();

        return $this->length;
    }

    /**
     * @param int $length
     */
    public function setLength($length) {
        $this->length = $length;
    }

    /**
     * @return array
     */
    public function getSearch() {
        $this->checkVersion();

        return $this->search;
    }

    /**
     * @param array $search
     */
    public function setSearch($search) {
        $this->search = $search;
    }

    /**
     * @return array
     */
    public function getOrder() {
        $this->checkVersion();

        return $this->order;
    }

    /**
     * @param array $order
     */
    public function setOrder($order) {
        $this->order = $order;
    }

    /**
     * @return array
     */
    public function getColumns() {
        $this->checkVersion();

        return $this->columns;
    }

    /**
     * @param array $columns
     */
    public function setColumns($columns) {
        $this->columns = $columns;
    }

    /**
     * @return int|null
     */
    public function getVersion() {
        $this->checkVersion();

        return $this->version;
    }

    #endregion

    #region methods

    /**
     * Check for valid version
     *
     * @throws \Exception
     */
    private function checkVersion() {
        if (null === $this->version) {
            throw new \Exception("version not implemented");
        }
    }

    /**
     * With given parameters build output array based on v1 or v2 datatables names
     *
     * @param array $data
     * @param $drawCounter
     * @param $totalRecords
     * @param $displayRecords
     * @return array
     * @throws \Exception
     */
    public function getOutputData(array $data, $drawCounter, $totalRecords, $displayRecords) {
        $this->checkVersion();

        if (!is_int($drawCounter) || !is_int($totalRecords) || !is_int($displayRecords)) {
            throw new \Exception("given input data not properly formatted");
        }

        return [
            self::outputNames[$this->version]['data'] => $data,
            self::outputNames[$this->version]['draw'] => $drawCounter,
            self::outputNames[$this->version]['totalRecords'] => $totalRecords,
            self::outputNames[$this->version]['displayRecords'] => $displayRecords
        ];
    }

    #endregion
}