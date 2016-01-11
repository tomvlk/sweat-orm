<?php
/**
 * OneToMany Solver
 *
 * @author     Tom Valk <tomvalk@lt-box.info>
 * @copyright  2016 Tom Valk
 */

namespace SweetORM\Database\Solver;

use SweetORM\Database\Solver;
use SweetORM\Entity;
use SweetORM\EntityManager;

class OneToMany extends Solver
{
    /**
     * Fetch target entity or entities.
     *
     * @param Entity $entity
     * @return mixed
     */
    public function solve(Entity &$entity)
    {
        // We need to find all target entities with the ID defined in the current entity source column
        $column = $this->relation->join->column;

        $value = $entity->{$column};
        $where = array($this->relation->join->targetColumn => $value);

        $query = EntityManager::find($this->relation->targetEntity);
        return $query->where($where)->all();
    }
}