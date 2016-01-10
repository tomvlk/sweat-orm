<?php
/**
 * Join in a Relation
 *
 * @author     Tom Valk <tomvalk@lt-box.info>
 * @copyright  2016 Tom Valk
 */

namespace SweatORM\Structure;

use Doctrine\Common\Annotations\Annotation as DoctrineAnnotation;
use Doctrine\Common\Annotations\Annotation\Required;
use Doctrine\Common\Annotations\Annotation\Enum;

/**
 * Join declaration
 *
 * @package SweatORM\Structure
 *
 * @Annotation
 * @Target("PROPERTY")
 */
class Join implements Annotation
{
    /**
     * Local Column
     * @var string
     * @Required()
     */
    public $column;

    /**
     * Foreign key at the target side
     * @var string
     * @Required()
     */
    public $targetForeignColumn;
}