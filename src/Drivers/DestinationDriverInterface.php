<?php


namespace DragoonBoots\A2B\Drivers;

use DragoonBoots\A2B\Annotations\DataMigration;
use DragoonBoots\A2B\Exception\BadUriException;
use DragoonBoots\A2B\Exception\NoDestinationException;

/**
 * Destination driver interface.
 */
interface DestinationDriverInterface
{

    /**
     * Set the destination of this driver.
     *
     * @param DataMigration $definition
     *   The migration definition.
     *
     * @throws BadUriException
     *   Thrown when the given URI is not valid.
     */
    public function configure(DataMigration $definition);

    /**
     * Read the ids that presently exist in the destination.
     *
     * @return array
     */
    public function getExistingIds(): array;

    /**
     * Get the entity as last migrated from the destination for updating.
     *
     * @param array $destIds
     *   A list of key-value pairs where the key is the destination id field and
     *   the value is destination id value.
     *
     * @return mixed|null
     *   Returns the selected entity, or null if it does not exist in the
     *   destination.
     */
    public function read(array $destIds);

    /**
     * Read multiple entities.
     *
     * @see read()
     *
     * @param array $destIdSet
     *   A list of key/value pairs.  An empty array will fetch all entities.
     *
     * @return array
     *   Returns the selected entities that exist.  This means if no entities
     *   were found, an empty array is returned.
     */
    public function readMultiple(array $destIdSet);

    /**
     * Write the transformed data.
     *
     * @param $data
     *
     * @return array|null
     *   An associative array with the destination keys.  If no keys can
     *   logically exist (e.g. output only), return null.
     *
     * @throws NoDestinationException
     *   Thrown when the destination is not configured.
     */
    public function write($data);

    /**
     * Flush remaining data that has not been written.
     *
     * Implementors should also perform any cleanup that needs to be done here.
     */
    public function flush();
}
