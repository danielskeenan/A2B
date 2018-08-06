<?php


namespace DragoonBoots\A2B\DataMigration;


use Doctrine\Common\Annotations\Reader;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use DragoonBoots\A2B\Annotations\DataMigration;
use DragoonBoots\A2B\Drivers\DriverManagerInterface;
use DragoonBoots\A2B\Exception\NonexistentMigrationException;
use League\Uri\Parser;
use MJS\TopSort\Implementations\FixedArraySort;

class DataMigrationManager implements DataMigrationManagerInterface
{

    /**
     * @var Reader
     */
    protected $annotationReader;

    /**
     * @var Parser
     */
    protected $uriParser;

    /**
     * @var DriverManagerInterface
     */
    protected $driverManager;

    /**
     * @var Collection|DataMigrationInterface[]
     */
    protected $migrations = [];

    /**
     * DataMigrationManager constructor.
     *
     * @param Reader                 $annotationReader
     * @param Parser                 $uriParser
     * @param DriverManagerInterface $driverManager
     */
    public function __construct(Reader $annotationReader, Parser $uriParser, DriverManagerInterface $driverManager)
    {
        $this->annotationReader = $annotationReader;
        $this->uriParser = $uriParser;
        $this->driverManager = $driverManager;

        $this->migrations = new ArrayCollection();
    }

    /**
     * Add a new migration
     *
     * @internal
     *
     * @param DataMigrationInterface $migration
     *
     * @throws \DragoonBoots\A2B\Exception\NoDriverForSchemeException
     * @throws \DragoonBoots\A2B\Exception\UnclearDriverException
     * @throws \ReflectionException
     */
    public function addMigration(DataMigrationInterface $migration)
    {
        $reflClass = new \ReflectionClass($migration);
        /** @var DataMigration $definition */
        $definition = $this->annotationReader->getClassAnnotation($reflClass, DataMigration::class);
        if (is_null($definition->getSourceDriver())) {
            $source = $definition->getSource();
            $sourceUri = $this->uriParser->parse($source);
            $sourceDriver = $this->driverManager->getSourceDriverForScheme($sourceUri['scheme']);
            $definition->setSourceDriver(get_class($sourceDriver));
        }
        if (is_null($definition->getDestinationDriver())) {
            $destination = $definition->getDestination();
            $destinationUri = $this->uriParser->parse($destination);
            $destinationDriver = $this->driverManager->getDestinationDriverForScheme($destinationUri['scheme']);
            $definition->setDestinationDriver(get_class($destinationDriver));
        }

        $migration->setDefinition($definition);
        $this->migrations[get_class($migration)] = $migration;
    }

    public function getMigrations(): Collection
    {
        return $this->migrations;
    }

    public function getMigration(string $migrationName)
    {
        if (!$this->migrations->containsKey($migrationName)) {
            throw new NonexistentMigrationException($migrationName);
        }

        return $this->migrations[$migrationName];
    }

    public function getMigrationsInGroup(string $groupName)
    {
        $migrations = $this->migrations->filter(
            function (DataMigrationInterface $migration) use ($groupName) {
                $definition = $this->getMigration(get_class($migration))
                    ->getDefinition();

                return $definition->getGroup() == $groupName;
            }
        );

        return $migrations;
    }

    /**
     * {@inheritdoc}
     */
    public function resolveDependencies(iterable $migrations, ?array &$extrasAdded = null): Collection
    {
        if (!isset($extrasAdded)) {
            $extrasAdded = [];
        }

        $requestedMigrationList = [];
        foreach ($migrations as $migration) {
            $requestedMigrationList[] = get_class($migration);
        }

        $sorter = new FixedArraySort();
        foreach ($migrations as $migration) {
            $definition = $migration->getDefinition();
            $dependencies = $definition->getDepends();
            foreach ($dependencies as $dependency) {
                if (!in_array($dependency, $requestedMigrationList)) {
                    $extrasAdded[] = $dependency;
                    $requestedMigrationList[] = $dependency;
                }

                $sorter->add($dependency);
            }
            $sorter->add(get_class($migration), $dependencies);
        }
        $runList = $sorter->sort();

        $runMigrations = new ArrayCollection();
        foreach ($runList as $migrationId) {
            $runMigrations->add($this->getMigration($migrationId));
        }

        return $runMigrations;
    }
}
