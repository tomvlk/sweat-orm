<?php
/**
 * Relation Abstract Annotation Class
 *
 * @author     Tom Valk <tomvalk@lt-box.info>
 * @copyright  2016 Tom Valk
 */

namespace SweatORM\Structure;

use Doctrine\Common\Annotations\Annotation\Enum;
use Doctrine\Common\Annotations\Annotation\Required;

/**
 * Class Relation
 * @package SweatORM\Structure
 *
 *
 */
abstract class Relation implements Annotation
{
    /**
     * Full Entity class notation for the target Entity of the relationship.
     * @var string
     */
    public $targetEntity;

    /**
     * Don't change this, it's not supported to not have lazy loaders in your entity relations yet!
     *
     * @Enum({"LAXY"})
     * @var string
     */
    public $fetch = 'LAZY';




    /**
     * Property in our Entity which holds the foreign key for the target Entity.
     *
     * @var string
     */
    public $property;

    /**
     * Property on the target entity which holds our primary key.
     *
     * @var string
     */
    public $targetProperty;

    /**
     * The Joining column name, only declare at the start of the relation.
     * @var string
     */
    public $column;
}