<?php

namespace ApolloPY\SimpleES;

use Closure;
use Illuminate\Support\Collection;
use \Illuminate\Pagination\LengthAwarePaginator as Paginator;

/**
 * Class Builder
 *
 * @see Illuminate\Database\Query\Builder
 * @see Jenssegers\Mongodb\Query\Builder
 * @package ApolloPY\SimpleES
 */
class Builder
{
    /**
     * @var \Elastica\Client
     */
    protected $client;

    /**
     * The Eloquent Model Name
     * @var string
     */
    protected $eloquent_name;

    /**
     * The index name
     *
     * @var string
     */
    protected $index;

    /**
     * The type name
     *
     * @var null | string
     */
    protected $type;

    /**
     * The where constraints for the query.
     *
     * @var array
     */
    protected $wheres;

    /**
     * The orderings for the query.
     *
     * @var array
     */
    protected $orders;

    /**
     * The maximum number of records to return.
     *
     * @var int
     */
    protected $limit;

    /**
     * The number of records to skip.
     *
     * @var int
     */
    protected $offset;

    /**
     * All of the available clause operators.
     *
     * @var array
     */
    protected $operators = array(
        '=', '<', '>', '<=', '>=',
        'text', 'range'
    );

    /**
     * Create a new query builder instance.
     *
     * @param \Elastica\Client $client
     * @param string $index
     * @param string $type
     */
    public function __construct(\Elastica\Client $client, $index, $type = null)
    {
        $this->client = $client;
        $this->index = $index;
        $this->type = $type;
    }

    public function setEloquentName($model_name)
    {
        $this->eloquent_name = $model_name;
    }

    /**
     * Get a new instance of the query builder.
     *
     * @return \ApolloPY\SimpleES\Builder
     */
    public function newSearch()
    {
        return new Builder($this->client, $this->index, $this->type);
    }

    /**
     * Add a basic where clause to the query.
     *
     * @param  string $column
     * @param  string $operator
     * @param  mixed $value
     * @param  string $boolean
     * @return \ApolloPY\SimpleES\Builder
     */
    public function where($column, $operator = null, $value = null, $boolean = 'must')
    {
        // Here we will make some assumptions about the operator. If only 2 values are
        // passed to the method, we will assume that the operator is an equals sign
        // and keep going. Otherwise, we'll require the operator to be passed in.
        if (func_num_args() == 2) {
            list($value, $operator) = array($operator, '=');
        } elseif ($this->invalidOperatorAndValue($operator, $value)) {
            throw new \InvalidArgumentException("Value must be provided.");
        }

        // If the columns is actually a Closure instance, we will assume the developer
        // wants to begin a nested where statement which is wrapped in parenthesis.
        // We'll add that Closure to the query then return back out immediately.
        if ($column instanceof Closure) {
            return $this->whereNested($column, $boolean);
        }

        // If the given operator is not found in the list of valid operators we will
        // assume that the developer is just short-cutting the '=' operators and
        // we will set the operators to '=' and set the values appropriately.
        if (!in_array(strtolower($operator), $this->operators, true)) {
            list($value, $operator) = array($operator, '=');
        }

        if ($operator == '=') $operator = 'term';

        $conversion = [
            '<'  => 'lt',
            '<=' => 'lte',
            '>'  => 'gt',
            '>=' => 'gte',
        ];
        if (isset($conversion[$operator])) {
            $value = [$conversion[$operator] => $value];
            $operator = 'range';
        }

        $this->wheres[] = compact('operator', 'column', 'value', 'boolean');

        return $this;
    }

    /**
     * Add an "or where" clause to the query.
     *
     * @param  string  $column
     * @param  string  $operator
     * @param  mixed   $value
     * @return \ApolloPY\SimpleES\Builder
     */
    public function orWhere($column, $operator = null, $value = null)
    {
        return $this->where($column, $operator, $value, 'should');
    }

    /**
     * Determine if the given operator and value combination is legal.
     *
     * @param  string  $operator
     * @param  mixed  $value
     * @return bool
     */
    protected function invalidOperatorAndValue($operator, $value)
    {
        $isOperator = in_array($operator, $this->operators);

        return ($isOperator && $operator != '=' && is_null($value));
    }

    /**
     * Add a nested where statement to the query.
     *
     * @param  \Closure $callback
     * @param  string   $boolean
     * @return \ApolloPY\SimpleES\Builder
     */
    public function whereNested(Closure $callback, $boolean = 'must')
    {
        // To handle nested queries we'll actually create a brand new query instance
        // and pass it off to the Closure that we have. The Closure can simply do
        // do whatever it wants to a query then we will store it for compiling.
        $search = $this->newSearch();

        call_user_func($callback, $search);

        if (count($search->wheres)) {
            $operator = 'nested';

            $this->wheres[] = compact('operator', 'search', 'boolean');
        }

        return $this;
    }

    /**
     * Add a raw where clause to the query.
     *
     * @param \Elastica\Query\AbstractQuery $query
     * @param string $boolean
     * @return \ApolloPY\SimpleES\Builder
     */
    public function whereRaw(\Elastica\Query\AbstractQuery $query, $boolean = 'must')
    {
        $operator = 'raw';
        $this->wheres[] = compact('operator', 'query', 'boolean');

        return $this;
    }

    /**
     * Add a raw or where clause to the query.
     *
     * @param \Elastica\Query\AbstractQuery $query
     * @return \ApolloPY\SimpleES\Builder
     */
    public function orWhereRaw(\Elastica\Query\AbstractQuery $query)
    {
        return $this->whereRaw($query, 'should');
    }

    /**
     * Add a test where clause to the query.
     *
     * @param $column
     * @param $value
     * @param string $boolean
     * @return \ApolloPY\SimpleES\Builder
     */
    public function whereText($column, $value, $boolean = 'must')
    {
        return $this->where($column, 'text', $value, $boolean);
    }

    /**
     * Add a test or where clause to the query.
     *
     * @param $column
     * @param $value
     * @return \ApolloPY\SimpleES\Builder
     */
    public function orWhereText($column, $value)
    {
        return $this->whereText($column, $value, 'should');
    }

    /**
     * Add a where between statement to the query.
     *
     * @param  string  $column
     * @param  array   $values
     * @param  string  $boolean
     * @return \ApolloPY\SimpleES\Builder
     */
    public function whereBetween($column, array $values, $boolean = 'must')
    {
        $operator = 'range';

        list($gte, $lte) = $values;
        $value = compact('gte', 'lte');

        $this->wheres[] = compact('operator', 'column', 'value', 'boolean');

        return $this;
    }

    /**
     * Add an or where between statement to the query.
     *
     * @param $column
     * @param array $values
     * @return \ApolloPY\SimpleES\Builder
     */
    public function orWhereBetween($column, array $values)
    {
        return $this->whereBetween($column, $values, 'should');
    }

    /**
     * Set the "offset" value of the query.
     *
     * @param  int $value
     * @return \ApolloPY\SimpleES\Builder
     */
    public function offset($value)
    {
        $this->offset = max(0, $value);

        return $this;
    }

    /**
     * Alias to set the "offset" value of the query.
     *
     * @param  int  $value
     * @return \ApolloPY\SimpleES\Builder
     */
    public function skip($value)
    {
        return $this->offset($value);
    }

    /**
     * Set the "limit" value of the query.
     *
     * @param  int $value
     * @return \ApolloPY\SimpleES\Builder
     */
    public function limit($value)
    {
        if ($value > 0) $this->limit = $value;

        return $this;
    }

    /**
     * Alias to set the "limit" value of the query.
     *
     * @param  int  $value
     * @return \ApolloPY\SimpleES\Builder
     */
    public function take($value)
    {
        return $this->limit($value);
    }

    /**
     * Set the limit and offset for a given page.
     *
     * @param  int  $page
     * @param  int  $perPage
     * @return \ApolloPY\SimpleES\Builder
     */
    public function forPage($page, $perPage = 15)
    {
        return $this->skip(($page - 1) * $perPage)->take($perPage);
    }

    /**
     * Add an "order by" clause to the query.
     *
     * @param  string $column
     * @param  string $direction
     * @return \ApolloPY\SimpleES\Builder
     */
    public function orderBy($column, $direction = 'asc')
    {
        $direction = strtolower($direction) == 'asc' ? 'asc' : 'desc';
        $this->orders[] = [$column => $direction];

        return $this;
    }

    /**
     * Execute the query
     *
     * @return \Elastica\ResultSet
     */
    protected function _get()
    {
        $query = new \Elastica\Query();

        if ($this->hasWhere()) {
            $query->setQuery($this->compileWhere($this->wheres));
        }

        if ($this->offset) {
            $query->setFrom($this->offset);
        }

        if ($this->limit) {
            $query->setSize($this->limit);
        }

        if ($this->orders) {
            $query->setSort($this->orders);
        }

        $client = $this->client->getIndex($this->index);
        if ($this->type) {
            $client = $client->getType($this->type);
        }

        return $client->search($query);
    }

    /**
     * Execute the query and get the first result.
     *
     * @return \Elastica\Result | \Illuminate\Database\Eloquent\Model | null
     */
    public function first()
    {
        $results = $this->take(1)->_get();
        $result = count($results->getResults()) > 0 ? reset($results->getResults()) : null;

        if ($result == null) {
            return $result;
        }

        if (!is_null($this->eloquent_name) && class_exists($this->eloquent_name)) {
            $model = new $this->eloquent_name();
            if (is_subclass_of($model, '\Illuminate\Database\Eloquent\Model')) {
                return $model->find($result->getId());
            }
        }

        return $result;
    }

    /**
     * Execute the query
     *
     * @return \Elastica\ResultSet | \Illuminate\Database\Eloquent\Collection
     */
    public function get()
    {
        $results = $this->_get();

        if (!is_null($this->eloquent_name) && class_exists($this->eloquent_name)) {
            $model = new $this->eloquent_name();
            if (is_subclass_of($model, '\Illuminate\Database\Eloquent\Model')) {
                $ids = [];
                foreach ($results->getResults() as $val) {
                    /* @var $val \Elastica\Result */
                    $ids[] = $val->getId();
                }

                if (!$ids) {
                    return new \Illuminate\Database\Eloquent\Collection();
                }

                return $model->whereIn('_id', $ids)->get()->sort(build_callback_for_collection_sort($ids));
            }
        }

        return $results;
    }

    /**
     * Get a paginator for the "select" statement.
     *
     * @param int $perPage
     * @return \Illuminate\Pagination\LengthAwarePaginator
     */
    public function paginate($perPage = 15)
    {
        $page = Paginator::resolveCurrentPage();
        $results = $this->forPage($page, $perPage)->_get();

        if (!$results->count()) {
            return new Paginator([], $results->getTotalHits(), $perPage, $page, [
                'path' => Paginator::resolveCurrentPath(),
            ]);
        }

        if (!is_null($this->eloquent_name) && class_exists($this->eloquent_name)) {
            $model = new $this->eloquent_name();
            if (is_subclass_of($model, '\Illuminate\Database\Eloquent\Model')) {
                $ids = [];
                foreach ($results->getResults() as $val) {
                    /* @var $val \Elastica\Result */
                    $ids[] = $val->getId();
                }

                $_results = $model->whereIn('_id', $ids)->get()->sort(build_callback_for_collection_sort($ids));
                return new Paginator($_results->all(), $results->getTotalHits(), $perPage, $page, [
                    'path' => Paginator::resolveCurrentPath(),
                ]);
            }
        }

        return new Paginator($results->getResults(), $results->getTotalHits(), $perPage, $page, [
            'path' => Paginator::resolveCurrentPath(),
        ]);
    }

    /**
     * Compile Where
     *
     * @param array $wheres
     * @return \Elastica\Query\AbstractQuery
     */
    protected function compileWhere($wheres)
    {
        $queries = [];
        foreach ($wheres as $val) {
            switch ($val['operator']) {
                case 'nested':
                    $_query = $this->compileWhere($val['search']->wheres);
                    break;
                case 'term':
                    $_query = new \Elastica\Query\Term();
                    $_query->setTerm($val['column'], $val['value']);
                    break;
                case 'text':
                    $_query = new \Elastica\Query\Match();
                    $_query->setFieldQuery($val['column'], $val['value']);
                    break;
                case 'range':
                    $_query = new \Elastica\Query\Range();
                    $_query->addField($val['column'], $val['value']);
                    break;
                case 'raw':
                    $_query = $val['query'];
                    break;
                default:
                    throw new \InvalidArgumentException(sprintf('$operator: %s unsupported', $val['operator']));
                    break;
            }

            $queries[] = ['query' => $_query, 'boolean' => $val['boolean']];
        }

        if (1 == count($queries)) {
            return $queries[0]['query'];
        }

        $query = new \Elastica\Query\Bool();
        foreach ($queries as $i => $val) {
            // The next item in a "chain" of wheres devices the boolean of the
            // first item. So if we see that there are multiple wheres, we will
            // use the operator of the next where.
            if ($i == 0 and count($queries) > 1 and $val['boolean'] == 'must') {
                $val['boolean'] = $queries[1]['boolean'];
            }

            // should | must | must_not
            $function_name = 'add' . studly_case($val['boolean']);
            $query->$function_name($val['query']);
        }

        return $query;
    }

    /**
     * @return bool
     */
    protected function hasWhere()
    {
        return (bool)count($this->wheres);
    }

}
