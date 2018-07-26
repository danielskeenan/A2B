<?php


namespace DragoonBoots\A2B\Drivers;

use DragoonBoots\A2B\Annotations\IdField;
use League\Uri\Parser;

/**
 * Base class for destination drivers.
 */
abstract class AbstractDestinationDriver implements DestinationDriverInterface
{

    /**
     * @var Parser
     */
    protected $uriParser;

    /**
     * AbstractSourceDriver constructor.
     *
     * @param Parser $uriParser
     */
    public function __construct(Parser $uriParser)
    {
        $this->uriParser = $uriParser;
    }

    /**
     * Perform the necessary typecasting on the destination id value.
     *
     * @param IdField $idField
     * @param         $value
     *
     * @return int|mixed
     */
    protected function resolveDestId(IdField $idField, $value)
    {
        if ($idField->type == 'int') {
            $value = (int)$value;
        }

        return $value;
    }

    /**
     * {@inheritdoc}
     */
    public function flush()
    {
        // Do nothing, allowing drivers that don't have a buffer to avoid
        // implementing nothing.
        return;
    }
}
