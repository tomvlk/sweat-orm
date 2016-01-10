<?php
/**
 * Entity Manager
 *
 * @author     Tom Valk <tomvalk@lt-box.info>
 * @copyright  2016 Tom Valk
 */

namespace SweatORM;


use SweatORM\Database\Query;
use SweatORM\Exception\RelationException;
use SweatORM\Structure\EntityStructure;
use SweatORM\Structure\Indexer\EntityIndexer;
use SweatORM\Structure\RelationManager;

class EntityManager
{
    /** @var EntityManager */
    private static $instance;

    /** @var EntityStructure[] Structures */
    private $entities = array();

    /**
     * Get entity manager
     * @return EntityManager
     */
    public static function getInstance()
    {
        if (self::$instance == null) {
            self::$instance = new EntityManager();
        }
        return self::$instance;
    }

    /**
     * After Fetch entity hook
     *
     * @param bool $saved
     * @param Entity[]|Entity|null $result
     * @return Entity|Entity[]|null
     */
    public function afterFetch($saved, $result)
    {
        // Multiple results
        if (is_array($result)) {
            $all = array();
            foreach ($result as $entity) {
                if ($entity instanceof Entity) {
                    $all[] = self::afterFetch($saved, $entity);
                }
            }
            return $all;
        }

        // Normal single processing.
        if ($result instanceof Entity) {
            $result->_saved = $saved;

            // Process relation properties, delete the 'real' props
            $this->injectVirtualProperties($result);
        }
        return $result;
    }

    /**
     * Inject Virtual Properties for relations
     *
     * @param Entity $entity
     */
    public function injectVirtualProperties($entity)
    {
        $structure = $this->getEntityStructure($entity);
        if (count($structure->relationProperties) > 0) {
            foreach ($structure->relationProperties as $removeProperty) {
                unset($entity->{$removeProperty});
            }
        }
    }

    /**
     * Register and index Entity class
     * @param string|Entity $entityClassName
     */
    public function registerEntity($entityClassName)
    {
        $indexer = new EntityIndexer($entityClassName);
        $structure = $indexer->getEntity();

        $this->entities[$structure->name] = $structure;
    }

    /**
     * Is the entity already registered?
     * @param string|Entity $entityClassName
     * @return bool
     */
    public function isRegistered($entityClassName)
    {
        if ($entityClassName instanceof Entity) {
            $reflection = new \ReflectionClass($entityClassName); // @codeCoverageIgnore
            $entityClassName = $reflection->getName(); // @codeCoverageIgnore
        } // @codeCoverageIgnore

        return isset($this->entities[$entityClassName]);
    }

    /**
     * Will clear all registered entities!
     */
    public function clearRegisteredEntities()
    {
        $this->entities = array();
    }


    /**
     * Get entity structure class for using metadata
     *
     * @param string|Entity $entityClassName
     * @return EntityStructure|false
     */
    public function getEntityStructure($entityClassName)
    {
        if ($entityClassName instanceof Entity) {
            $reflection = new \ReflectionClass($entityClassName);
            $entityClassName = $reflection->getName();
        }

        if (! $this->isRegistered($entityClassName)) {
            $this->registerEntity($entityClassName);
        }
        return $this->entities[$entityClassName];
    }


    /**
     * Will be called for getting the relationship result, lazy loading.
     *
     * @param Entity $entity
     * @param string $name
     *
     * @return mixed
     * @throws RelationException When not found in relation, or the relation is invalid.
     */
    public function getLazy($entity, $name)
    {
        // Verify if virtual property exists
        if (! in_array($name, $this->getEntityStructure($entity)->relationProperties)) {
            throw new RelationException("Property '".$name."' is not a valid and declared property, or relation property!");
        }

        return RelationManager::with($entity)->fetch($name);
    }

    /**
     * Set a virtual property
     *
     * @param Entity $entity
     * @param string $name
     * @param Entity $value
     * @throws RelationException
     * @throws \Exception
     */
    public function setLazy($entity, $name, $value)
    {
        // Verify if virtual property exists
        if (! in_array($name, $this->getEntityStructure($entity)->relationProperties)) {
            throw new RelationException("Property '".$name."' is not a valid and declared property, or relation property!");
        }

        // Verify if value is also an entity!
        if (! $value instanceof Entity && $value !== null) {
            throw new RelationException("Property '".$name."' is a reference to a relationship, you should set the entity of that relationship!");
        }

        // Pass to the relationmanager
        RelationManager::with($entity)->set($name, $value);
    }


    /** ==== Entity Instance Operations **/

    /**
     * Save Entity (will insert or update)
     *
     * @param Entity $entity
     *
     * @return bool status of save
     */
    public function save($entity)
    {
        $query = new Query($entity, false);
        $structure = $this->getEntityStructure($entity);

        if ($entity->_saved) {
            // Update
            return $query->update()->set($this->getEntityDataArray($entity))->where(array($structure->primaryColumn->name => $entity->_id))->apply();
        } else {
            // Insert
            $id = $query->insert()->into($structure->tableName)->values($this->getEntityDataArray($entity))->apply();

            if ($id === false) {
                return false; // @codeCoverageIgnore
            }

            // Save ID and state
            $entity->{$structure->primaryColumn->propertyName} = $id;
            $entity->_id = $id;
            $entity->_saved = true;

            return true;
        }
    }


    /**
     * Delete entity from database
     * @param Entity $entity
     * @return bool
     */
    public function delete($entity)
    {
        $query = new Query($entity, false);
        $structure = $this->getEntityStructure($entity);

        if ($entity->_saved) {
            return $query->delete($structure->tableName)->where(array($structure->primaryColumn->name => $entity->_id))->apply();
        }
        return false;
    }


    /** ==== Entity Operation Functions, will apply on specific entities ==== **/


    /**
     * Start a query
     *
     * @param $entity
     * @return Query
     */
    public static function find($entity)
    {
        return new Query($entity);
    }

    /**
     * Get Entity with Primary Key value
     *
     * @param string $entity
     * @param int|string $primaryValue
     * @return false|Entity
     * @throws \Exception
     */
    public static function get($entity, $primaryValue)
    {
        $query = new Query($entity);
        $column = self::getInstance()->getEntityStructure($entity)->primaryColumn;
        $query->where($column->name, $primaryValue);
        return $query->one();
    }

    /** ====== **/

    /**
     * Get entity column=>value array.
     * @param Entity $entity
     * @return array
     */
    private function getEntityDataArray($entity)
    {
        $data = array();

        $structure = $this->getEntityStructure($entity);
        $columns = $structure->columns;

        foreach ($columns as $column) {
            if (isset($entity->{$column->propertyName})) {
                $data[$column->name] = $entity->{$column->propertyName};
            } else {
                $data[$column->name] = null;
            }
        }
        return $data;
    }
}