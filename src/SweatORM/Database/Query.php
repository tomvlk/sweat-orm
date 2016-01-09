<?php
/**
 * Query Builder for ORM
 *
 * @author     Tom Valk <tomvalk@lt-box.info>
 * @copyright  2016 Tom Valk
 */

namespace SweatORM\Database;
use SweatORM\ConnectionManager;
use SweatORM\Entity;
use SweatORM\EntityManager;
use SweatORM\Exception\QueryException;
use SweatORM\Structure\EntityStructure;

/**
 * Query Building on Entities
 *
 * @package SweatORM\Database
 */
class Query
{
    /**
     * @var string
     */
    private $class;

    /**
     * @var EntityStructure
     */
    private $structure;

    /**
     * Hold last exception (chain)
     * @var null|\Exception
     */
    private $exception = null;

    /**
     * Holds the full query after building is complete
     *
     * @var string
     */
    private $query = "";

    /* ===== Query Parts ===== */
    private $select = "";
    private $from = "";
    private $where = "";
    private $order = "";
    private $limit = "";


    /* ===== Building Variables ===== */
    /** @var array */
    private $whereConditions = array();
    /** @var null|int */
    private $limitCount = null;
    /** @var null|int */
    private $limitOffset = null;
    /** @var null|string */
    private $sortBy = null;
    /** @var null|string */
    private $sortOrder = null;

    /* ===== Storage for Binding ===== */
    private $bindValues = array();
    private $bindTypes = array();


    /**
     * Query Builder constructor
     * @param $entityClass
     */
    public function __construct($entityClass)
    {
        $this->class = $entityClass;
        $this->structure = EntityManager::getInstance()->getEntityStructure($entityClass);

        $this->generator = new QueryGenerator();
    }


    /**
     * Fake select method, used to maximize compatibility
     * @param string $columns
     * @return Query $this
     */
    public function select($columns = "*")
    {
        $this->select = $columns;
        return $this;
    }

    /**
     * @param string $table
     * @return Query $this
     */
    public function from($table)
    {
        $this->from = $table;
        return $this;
    }


    /**
     * Add where conditions, you can give the conditions in multiple styles:
     * 1. -> where('id', 1)                    for id= 1 condition
     * 2. -> where('id', '=', 1)               for id = 1 condition
     * 3. -> where('id', 'IN', array(1, 2))    for id IN (1,2) condition
     * 4. -> where(array('id' => 1))           same as first style
     * 5. -> where(array('id' => array('=' => 1)) same as second style
     *
     * @param string|array $criteria String with column name, or array with condition for full where syntax
     *
     * @param string|null $operator Only when using column name in first parameter, fill this by the value when comparing
     * or fill in the operator used to compare
     *
     * @param string|null $value Only when using column name in first parameter and filling in the operator value.
     *
     * @return Query $this Return chained query.
     */
    public function where($criteria, $operator = null, $value = null)
    {
        // If the operator is the value, then we are going to use the = operator
        if (! is_array($criteria) && $value === null && $this->validValue($operator, "=")) {
            // Operator is now value!
            $criteria = array($criteria => array("=" => $operator));
        }

        // If it's the shorthand of the where, convert it to the normal criteria.
        if (! is_array($criteria) && $this->validOperator($operator) && $this->validValue($value, $operator)) {
            $criteria = array($criteria => array($operator => $value));
        }

        // Get column names of table
        $columnNames = $this->structure->columnNames;

        // Parse criteria, validate and add to the current where clause.
        foreach ($criteria as $column => $compare) {
            // If using shorthand for = compare
            if (! is_array($compare)) {
                $criteria[$column] = array('=' => $compare);
                $compare = array('=' => $compare);
            }

            // Validate compare, validate column name
            if (! in_array($column, $columnNames)) {
                $this->exception = new QueryException("Trying to prepare a where with column condition for a undefined column!", 0, $this->exception);
                continue;
            }

            $operator = array_keys($compare);
            $operator = $operator[0];
            $value = $compare[$operator];

            if ($this->validOperator($operator) && $this->validValue($value, $operator)) {
                // Add to the Query Where stack
                $this->whereConditions[] = array(
                    'column' => $column,
                    'operator' => $operator,
                    'value' => $value
                );
            }
            // Skip if not valid.
        }
        return $this;
    }


    /**
     * Limit the result
     *
     * @param int $limit Give the number of limited entities returned.
     * @return Query $this The current query stack.
     */
    public function limit($limit)
    {
        if (! is_int($limit) || $limit < 0) {
            $this->exception = new QueryException("Limit value should be an positive integer!", 0, $this->exception);
            return $this;
        }
        $this->limitCount = intval($limit);

        return $this;
    }

    /**
     * Offset the results
     *
     * @param int $offset Give the number of offset applied to the results.
     * @return Query $this The current query stack.
     */
    public function offset($offset)
    {
        if (! is_int($offset) || $offset < 0) {
            $this->exception = new QueryException("Offset value should be an positive integer!", 0, $this->exception);
            return $this;
        }
        $this->limitOffset = intval($offset);

        return $this;
    }


    /**
     * Sort by column value, Ascending or descending
     * @param string $column Column name to order with.
     * @param string $type Either ASC or DESC for the order type.
     * @return Query $this The current query stack.
     */
    public function sort($column, $type = 'ASC')
    {
        // First lets upper the type.
        $type = strtoupper($type);

        if (! $this->validOrderType($type)) {
            $this->exception = new QueryException("Sorting requires a type that is either 'ASC' or 'DESC'!", 0, $this->exception);
            return $this;
        }

        // Validate the column
        if (in_array($column, $this->structure->columnNames)) {
            $this->sortBy = $column;
            $this->sortOrder = $type;
        }

        return $this;
    }


    /**
     * Execute Query and fetch all records as entities
     *
     * @return Entity[]|false Entities as successful result or false on not found.
     * @throws \Exception|null
     */
    public function all()
    {
        if ($this->exception !== null) {
            throw $this->exception;
        }

        $this->fetch(true);
    }

    /**
     * Execute Query and fetch a single record as entity
     *
     * @return Entity[]|false Entities as successful result or false on not found.
     * @throws \Exception|null
     */
    public function one()
    {
        if ($this->exception !== null) {
            throw $this->exception;
        }

        $this->fetch(false);
    }


    /**
     * @param $multi
     * @return array|mixed
     */
    private function fetch($multi)
    {
        // Let the generator do his work now,
        // Where
        $this->generator->generateWhere($this->whereConditions, $this->where, $this->bindValues, $this->bindTypes);

        // Order
        $this->generator->generateOrder($this->sortBy, $this->sortOrder, $this->order);

        // Limit
        $this->generator->generateLimit($this->limitCount, $this->limitOffset, $this->limit);


        // Combine parts
        $this->query = "";
        $this->combineQuery();

        // Get connection and prepare
        $connection = ConnectionManager::getConnection();
        $query = $connection->prepare($this->query);

        // Bind all values
        $idx = 1;
        foreach ($this->bindValues as $key => $value) {
            $query->bindValue($idx, $value, $this->bindTypes[$key]);
            $idx++;
        }

        // Set fetch mode
        $query->setFetchMode(\PDO::FETCH_CLASS, $this->class);

        // Fetch and return
        if ($multi) {
            return $query->fetchAll();
        }
        return $query->fetch();
    }

















    /**
     * Check if given operator is a valid operator.
     * @param string $operator
     * @return bool
     */
    private function validOperator($operator)
    {
        $valid = array("=", "!=", "LIKE", ">", "<", ">=", "<=", "IN", "<>");
        return in_array($operator, $valid, true);
    }

    /**
     * Validate value for given operator
     * @param mixed $value
     * @param string $operator
     * @return bool
     */
    private function validValue($value, $operator)
    {
        if (! $this->validOperator($operator)) {
            return false;
        }

        if ($operator === "IN") {
            // Valid should be an array!
            return is_array($value);
        }

        return !is_array($value);
    }

    /**
     * Validate type of ordering columns
     * @param string $type
     * @return bool
     */
    private function validOrderType($type)
    {
        return strtolower($type) === 'asc' || strtolower($type) === 'desc';
    }


    /**
     * Combine Query
     */
    private function combineQuery()
    {
        $this->query = "SELECT $this->select FROM $this->from";

        if (! empty($this->where)) {
            $this->query .= " WHERE $this->where";
        }

        if (! empty($this->order)) {
            $this->query .= " ORDER BY $this->order";
        }

        if (! empty($this->limit)) {
            $this->query .= " LIMIT $this->limit";
        }
    }
}