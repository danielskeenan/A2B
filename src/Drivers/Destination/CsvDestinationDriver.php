<?php


namespace DragoonBoots\A2B\Drivers\Destination;


use DragoonBoots\A2B\Annotations\DataMigration;
use DragoonBoots\A2B\Annotations\Driver;
use DragoonBoots\A2B\Annotations\IdField;
use DragoonBoots\A2B\Drivers\AbstractDestinationDriver;
use DragoonBoots\A2B\Drivers\DestinationDriverInterface;
use DragoonBoots\A2B\Exception\MigrationException;
use DragoonBoots\A2B\Exception\NoDestinationException;
use DragoonBoots\A2B\Exception\NoIdSetException;
use League\Csv\ColumnConsistency;
use League\Csv\Reader as CsvReader;
use League\Csv\Statement;
use League\Csv\Writer as CsvWriter;

/**
 * CSV Destination driver
 *
 * @Driver("csv")
 */
class CsvDestinationDriver extends AbstractDestinationDriver implements DestinationDriverInterface
{

    /**
     * @var CsvWriter
     */
    protected $writer;

    /**
     * @var CsvReader
     */
    protected $reader;

    /**
     * @var IdField[]
     */
    protected $destIds;

    /**
     * @var bool
     */
    protected $headerWritten = false;

    /**
     * Is the file being worked on a newly created file?
     *
     * @var bool
     */
    protected $newFile = false;

    /**
     * The temporary file the results are written to.
     *
     * @var resource
     */
    protected $tempFile;

    /**
     * @var DataMigration
     */
    protected $definition;

    /**
     * {@inheritdoc}
     */
    public function configure(DataMigration $definition)
    {
        $this->definition = $definition;

        $destination = $definition->destination;
        $this->destIds = $definition->destinationIds;

        $uri = $this->uriParser->parse($destination);

        // Ensure the destination exists.
        if (!is_dir(dirname($uri['path']))) {
            mkdir(dirname($uri['path']), 0755, true);
        }

        $this->reader = CsvReader::createFromPath($uri['path'], 'c+');

        // The file is new if it is entirely empty or only includes a header.
        $this->newFile = $this->reader->count() <= 1;
        if (!$this->newFile) {
            $this->reader->setHeaderOffset(0);
        }

        $this->tempFile = tmpfile();
        $this->writer = CsvWriter::createFromStream($this->tempFile);
        $this->writer->addValidator(new ColumnConsistency(), 'column_consistency');

        $this->headerWritten = false;
    }

    public function getExistingIds(): array
    {
        $ids = [];
        foreach ($this->reader->getIterator() as $row) {
            $id = [];
            foreach ($this->destIds as $destId) {
                $id[$destId->getName()] = $row[$destId->getName()];
            }
            $ids[] = $id;
        }

        return $ids;
    }

    /**
     * {@inheritdoc}
     * @throws \League\Csv\CannotInsertRecord
     * @throws NoIdSetException
     */
    public function write($data)
    {
        if (!isset($this->writer)) {
            throw new NoDestinationException();
        }

        if (!$this->headerWritten) {
            $this->writer->insertOne(array_keys($data));
            $this->headerWritten = true;
        }
        $this->writer->insertOne($data);

        $destIds = [];
        foreach ($this->destIds as $destId) {
            if (!isset($data[$destId->name])) {
                throw new NoIdSetException($destId->name, $data);
            }
            $destIds[$destId->name] = $this->resolveDestId($destId, $data[$destId->name]);
        }

        return $destIds;
    }

    /**
     * {@inheritdoc}
     * @throws \League\Csv\Exception
     */
    public function read(array $destIds)
    {
        if (!$this->newFile) {
            $results = $this->findEntities([$destIds]);
            $count = $results->count();
            if ($count > 1) {
                throw new \RangeException(sprintf("More than one row matched the ids:\n%s\n", var_export($destIds, true)));
            } elseif ($count == 1) {
                return $results->fetchOne();
            }
        }

        return null;
    }

    /**
     * Query the destination results
     *
     * @param array $destIdSet
     *   An array of of dest id arrays.  Each dest id array is a set of
     *   key/value pairs.
     *
     * @return \League\Csv\ResultSet
     */
    protected function findEntities(array $destIdSet)
    {
        $constraint = (new Statement())->where(
            function ($record) use ($destIdSet) {
                $found = true;
                foreach ($destIdSet as $destIds) {
                    foreach ($destIds as $key => $value) {
                        $found = $found && ($record[$key] == $value);
                    }
                }

                return $found;
            }
        );
        $results = $constraint->process($this->reader);

        return $results;
    }

    /**
     * {@inheritdoc}
     */
    public function readMultiple(array $destIdSet)
    {
        if ($this->newFile) {
            return [];
        }

        $results = $this->findEntities($destIdSet);
        $entities = [];
        foreach ($results as $result) {
            $entities[] = $result;
        }

        return $entities;
    }

    /**
     * {@inheritdoc}
     * @throws MigrationException
     *   Thrown when the destination file could not be written.
     */
    public function flush()
    {
        $destination = $this->definition->destination;
        $uri = $this->uriParser->parse($destination);
        $tempFile = stream_get_meta_data($this->tempFile)['uri'];
        if (!rename($tempFile, $uri['path'])) {
            throw new MigrationException(sprintf('Could not write to file at "%s"', $destination));
        }
    }
}
