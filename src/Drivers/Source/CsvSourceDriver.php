<?php


namespace DragoonBoots\A2B\Drivers\Source;


use DragoonBoots\A2B\Annotations\DataMigration;
use DragoonBoots\A2B\Annotations\Driver;
use DragoonBoots\A2B\Drivers\AbstractSourceDriver;
use DragoonBoots\A2B\Drivers\SourceDriverInterface;
use DragoonBoots\A2B\Exception\BadUriException;
use League\Csv\Exception as CsvException;
use League\Csv\Reader as CsvReader;

/**
 * CSV file source driver.
 *
 * @Driver()
 */
class CsvSourceDriver extends AbstractSourceDriver implements SourceDriverInterface
{

    /**
     * @var CsvReader
     */
    protected $reader;

    /**
     * Set the source of this driver.
     *
     * @param DataMigration $definition
     *   The migration definition.
     *
     * @throws BadUriException
     *   Thrown when the given URI is not valid.
     * @throws CsvException
     *   Thrown when the CSV cannot be read.
     */
    public function configure(DataMigration $definition)
    {
        parent::configure($definition);

        // Ensure the source exists.
        if (!is_file($this->migrationDefinition->getSource())) {
            throw new BadUriException($definition->getSource());
        }

        $this->reader = CsvReader::createFromPath($this->migrationDefinition->getSource(), 'r');

        // Don't try reading an empty file.
        $emptyFile = $this->reader->count() <= 1;
        if ($emptyFile) {
            throw new BadUriException($definition->getSource());
        }
        $this->reader->setHeaderOffset(0);
    }

    /**
     * {@inheritdoc}
     */
    public function getIterator()
    {
        return $this->reader->getRecords();
    }

    /**
     * {@inheritdoc}
     */
    public function count(): int
    {
        return $this->reader->count();
    }
}
