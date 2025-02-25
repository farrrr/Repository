<?php

namespace SJFinder\Repository\Eloquent;

use Illuminate\Container\Container as Application;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Prettus\Validator\Contracts\ValidatorInterface;
use SJFinder\Repository\Contracts\CriteriaInterface;
use SJFinder\Repository\Contracts\PresenterInterface;
use SJFinder\Repository\Contracts\RepositoryCriteriaInterface;
use SJFinder\Repository\Contracts\RepositoryInterface;
use SJFinder\Repository\Exceptions\RepositoryException;

abstract class BaseRepository implements RepositoryInterface, RepositoryCriteriaInterface
{
    /**
     * @var Application
     */
    protected $app;

    /**
     * @var Model
     */
    protected $model;

    /**
     * @var string
     */
    protected $modelClass;

    /**
     * @var array
     */
    protected $searchableFields = [];

    /**
     * @var PresenterInterface
     */
    protected $presenter;

    /**
     * @var string
     */
    protected $presenterClass = null;

    /**
     * @var ValidatorInterface
     */
    protected $validator;

    /**
     * Validation Rules.
     *
     * @var array
     */
    protected $rules = null;

    /**
     * Collection of Criteria.
     *
     * @var Collection
     */
    protected $criteria;

    /**
     * @var string
     */
    protected $orderField = null;

    /**
     * @var string
     */
    protected $orderDirection = 'asc';

    /**
     * @var bool
     */
    protected $skipCriteria = false;

    /**
     * @var bool
     */
    protected $skipPresenter = false;

    /**
     * @param Application $app
     *
     * @throws RepositoryException
     */
    public function __construct(Application $app)
    {
        $this->app = $app;
        $this->criteria = new Collection();
        $this->makeModel();
        $this->makePresenter();
        $this->makeValidator();
        $this->boot();
    }

    /**
     *
     */
    public function boot()
    {
    }

    /**
     * Specify Validator class name of Prettus\Validator\Contracts\ValidatorInterface.
     *
     * @return \Illuminate\Foundation\Application|mixed|null
     */
    public function validator()
    {
        if ($this->rules !== null and is_array($this->rules)) {
            $validator = app('Prettus\Validator\LaravelValidator');

            if ($validator instanceof ValidatorInterface) {
                $validator->setRules($this->rules);

                return $validator;
            }

            return;
        }
    }

    /**
     * Set Presenter.
     *
     * @param $presenter
     *
     * @return $this
     */
    public function setPresenter($presenter)
    {
        $this->makePresenter($presenter);

        return $this;
    }

    /**
     * @throws RepositoryException
     *
     * @return Model
     */
    public function makeModel()
    {
        $model = $this->app->make($this->modelClass);

        if (!$model instanceof Model) {
            throw new RepositoryException("Class {$this->modelClass} must be an instance of Illuminate\\Database\\Eloquent\\Model");
        }

        return $this->model = $model;
    }

    /**
     * @param null $presenter
     *
     * @throws RepositoryException
     *
     * @return null|PresenterInterface
     */
    public function makePresenter($presenter = null)
    {
        $presenter = ($presenter !== null) ? $presenter : $this->presenterClass;

        if ($presenter !== null) {
            $this->presenter = is_string($presenter) ? $this->app->make($presenter) : $presenter;

            if (!$this->presenter instanceof PresenterInterface) {
                throw new RepositoryException("Class {$presenter} must be an instance of SJFinder\\Repository\\Contract\\PresenterInterface");
            }

            return $this->presenter;
        }

        return;
    }

    /**
     * @param null $validator
     *
     * @throws RepositoryException
     *
     * @return null|ValidatorInterface
     */
    public function makeValidator($validator = null)
    {
        $validator = ($validator !== null) ? $validator : $this->validator();

        if ($validator !== null) {
            $this->validator = is_string($validator) ? $this->app->make($validator) : $validator;

            if (!$this->validator instanceof ValidatorInterface) {
                throw new RepositoryException("Class {$validator} nust be an instance of Prettus\\Validator\\Contracts\\ValidatorInterface");
            }

            return $this->validator;
        }

        return;
    }

    /**
     * take limit data of repository.
     *
     * @param int $limit
     *
     * @return $this
     */
    public function take($limit)
    {
        $this->model = $this->model->take($limit);

        return $this;
    }

    /**
     * Add an "order by" clause to the query.
     *
     * @param string $column
     * @param string $direction
     *
     * @return $this
     */
    public function orderBy($column, $direction = 'asc')
    {
        $direction = strtolower($direction) === 'asc' ? 'asc' : 'desc';
        $this->model = $this->model->orderBy($column, $direction);

        return $this;
    }

    /**
     * @return $this
     */
    public function applyOrder()
    {
        if (count($this->model->getQuery()->orders) === 0) {
            $field = ($this->orderField !== null) ? $this->orderField : $this->getKeyName();
            $direction = strtolower($this->orderDirection) === 'asc' ? 'asc' : 'desc';

            $this->model = $this->model->orderBy($field, $direction);
        }

        return $this;
    }

    public function getKeyName()
    {
        if ($this->model instanceof Model) {
            return $this->model->getKeyName();
        }

        $model = $this->app->make($this->modelClass);

        return $model->getKeyName();
    }

    /**
     * Retrieve all data of repository.
     *
     * @param array $columns
     * @param bool  $isCount
     *
     * @throws RepositoryException
     *
     * @return mixed
     */
    public function all(array $columns = ['*'], $isCount = false)
    {
        $this->applyCriteria();

        if (!$isCount) {
            $this->applyOrder();
        }

        if ($isCount) {
            $results = $this->model->count();
        } else {
            if ($this->model instanceof Builder) {
                $results = $this->model->get($columns);
            } else {
                $results = $this->model->all($columns);
            }
        }

        $this->makeModel();

        return ($isCount) ? $results : $this->parseResult($results);
    }

    /**
     * Retrieve all data of repository, paginated.
     *
     * @param null  $limit
     * @param array $columns
     *
     * @return mixed
     */
    public function paginate($limit = null, array $columns = ['*'])
    {
        $this->applyCriteria()->applyOrder();
        $limit = $limit ?: config('repository.pagination.limit', 15);
        $results = $this->model->paginate($limit, $columns);

        $this->makeModel();

        return $this->parseResult($results);
    }

    /**
     * Find data by id.
     *
     * @param $id
     * @param array $columns
     *
     * @return mixed
     */
    public function find($id, array $columns = ['*'])
    {
        $this->applyCriteria()->applyOrder();
        $model = $this->model->findOrFail($id, $columns);

        $this->makeModel();

        return $this->parseResult($model);
    }

    /**
     * Find data by field and value.
     *
     * @param $field
     * @param $value
     * @param array $columns
     * @param bool  $isCount
     *
     * @throws RepositoryException
     *
     * @return mixed
     */
    public function findBy($field, $value, array $columns = ['*'], $isCount = false)
    {
        $this->applyCriteria();

        if (!$isCount) {
            $this->applyOrder();
            $model = $this->model->ofValue($field, $value)->get($columns);
        } else {
            $counts = $this->model->ofValue($field, $value)->count();
        }

        $this->makeModel();

        return $isCount ? $counts : $this->parseResult($model);
    }

    /**
     * Find data by multiple fields.
     *
     * @param array $where
     * @param array $columns
     * @param bool  $isCount
     *
     * @throws RepositoryException
     *
     * @return mixed
     */
    public function findWhere(array $where, array $columns = ['*'], $isCount = false)
    {
        $this->applyCriteria();

        if (!$isCount) {
            $this->applyOrder();
        }

        foreach ($where as $field => $value) {
            if (is_array($value)) {
                list($field, $condition, $value) = $value;
                $this->model = $this->model->where($field, $condition, $value);
            } else {
                $this->model = $this->model->ofValue($field, $value);
            }
        }

        if ($isCount) {
            $counts = $this->model->count();
        } else {
            $model = $this->model->get($columns);
        }

        $this->makeModel();

        return $isCount ? $counts : $this->parseResult($model);
    }

    /**
     * Create a new entity in repository.
     *
     * @param array $attributes
     *
     * @return mixed
     */
    public function create(array $attributes)
    {
        if ($this->validator !== null) {
            $this->validator->with($attributes)->passOrFail(ValidatorInterface::RULE_CREATE);
        }

        $model = $this->app->make($this->modelClass);
        $entity = $model->create($attributes);

        $this->makeModel();

        return $this->parseResult($entity);
    }

    /**
     * Retrieve matched entity or create new one.
     *
     * @param array $attributes
     *
     * @return mixed
     */
    public function firstOrCreate(array $attributes)
    {
        if ($entity = $this->take(1)->findWhere($attributes)->first()) {
            return $entity;
        }

        $this->makeModel();

        return $this->create($attributes);
    }

    /**
     * Update a entity in repository by id.
     *
     * @param array $attributes
     * @param $id
     *
     * @return mixed
     */
    public function update(array $attributes, $id)
    {
        if ($this->validator !== null) {
            $this->validator->with($attributes)->setId($id)->passOrFail(ValidatorInterface::RULE_UPDATE);
        }
        $_skipPresenter = $this->skipPresenter;
        $this->skipPresenter(true);

        $model = $this->find($id);
        $model->fill($attributes);
        $model->save();

        $this->skipPresenter($_skipPresenter);

        $this->makeModel();

        return $this->parseResult($model);
    }

    /**
     * Delete multiple entity in repository.
     *
     * @param $id
     *
     * @return int
     */
    public function delete($id)
    {
        $this->makeModel();

        return $this->model->destroy($id);
    }

    /**
     * Load relations.
     *
     * @param $relations
     *
     * @return this
     */
    public function with($relations)
    {
        $relations = is_array($relations) ? $relations : func_get_args();
        $this->model = $this->model->with($relations);

        return $this;
    }

    /**
     * Set hidden fields.
     *
     * @param array $fields
     *
     * @return this
     */
    public function hidden(array $fields)
    {
        $this->model->setHidden($fields);

        return $this;
    }

    /**
     * Set visible fields.
     *
     * @param array $fields
     *
     * @return this
     */
    public function visible(array $fields)
    {
        $this->model->setVisible($fields);

        return $this;
    }

    /**
     * Get searchable fields.
     *
     * @return array
     */
    public function getSearchableFields()
    {
        return $this->searchableFields;
    }

    /**
     * Skip Presenter.
     *
     * @param bool $status
     *
     * @return this
     */
    public function skipPresenter($status = true)
    {
        $this->skipPresenter = $status;

        return $this;
    }

    /**
     * Push Criteria for filter the query.
     *
     * @param CriteriaInterface $criteria
     *
     * @return mixed
     */
    public function pushCriteria(CriteriaInterface $criteria)
    {
        $this->criteria->push($criteria);

        return $this;
    }

    /**
     * Get Collection of Criteria.
     *
     * @return mixed
     */
    public function getCriteria()
    {
        return $this->criteria;
    }

    /**
     * Find data by Criteria.
     *
     * @param CriteriaInterface $criteria
     *
     * @return mixed
     */
    public function getByCriteria(CriteriaInterface $criteria)
    {
        $this->model = $criteria->apply($this->model, $this);
        $results = $this->model->get();

        return $this->parseResult($results);
    }

    /**
     * Skip Criteria.
     *
     * @param bool $status
     *
     * @return mixed
     */
    public function skipCriteria($status = true)
    {
        $this->skipCriteria = $status;

        return $this;
    }

    /**
     * Reset Criteria after applyCriteria.
     */
    public function resetCriteria()
    {
        $this->criteria = new Collection();
    }

    /**
     * Apply criteria in current query.
     *
     * @return $this
     */
    public function applyCriteria()
    {
        if ($this->skipCriteria === true) {
            return $this;
        }

        $criteria = $this->getCriteria();

        if ($criteria) {
            foreach ($criteria as $c) {
                if ($c instanceof CriteriaInterface) {
                    $this->model = $c->apply($this->model, $this);
                }
            }
        }
        $this->resetCriteria();

        return $this;
    }

    /**
     * @param $result
     *
     * @return mixed
     */
    public function parseResult($result)
    {
        if (!$this->skipPresenter and $this->presenter instanceof PresenterInterface) {
            return $this->presenter->present($result);
        }

        return $result;
    }
}
