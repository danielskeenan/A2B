<?php

namespace DragoonBoots\A2B\Command;

use DragoonBoots\A2B\Annotations\DataMigration;
use DragoonBoots\A2B\DataMigration\DataMigrationExecutorInterface;
use DragoonBoots\A2B\DataMigration\DataMigrationInterface;
use DragoonBoots\A2B\DataMigration\DataMigrationManagerInterface;
use DragoonBoots\A2B\DataMigration\DataMigrationMapperInterface;
use DragoonBoots\A2B\Drivers\Destination\DebugDestinationDriver;
use DragoonBoots\A2B\Drivers\DestinationDriverInterface;
use DragoonBoots\A2B\Drivers\DriverManagerInterface;
use League\Uri\Parser;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\VarDumper\Cloner\ClonerInterface;
use Symfony\Component\VarDumper\Dumper\AbstractDumper;

class MigrateCommand extends Command
{

    /**
     * {@inheritdoc}
     */
    protected static $defaultName = 'a2b:migrate';

    /**
     * @var DataMigrationManagerInterface
     */
    protected $dataMigrationManager;

    /**
     * @var DriverManagerInterface
     */
    protected $driverManager;

    /**
     * @var DataMigrationExecutorInterface
     */
    protected $executor;

    /**
     * @var DataMigrationMapperInterface
     */
    protected $mapper;

    /**
     * @var Parser
     */
    protected $uriParser;

    /**
     * @var ParameterBagInterface
     */
    protected $parameterBag;

    /**
     * @var AbstractDumper
     */
    protected $varDumper;

    /**
     * @var ClonerInterface
     */
    protected $varCloner;

    /**
     * @var SymfonyStyle
     */
    protected $io;

    /**
     * MigrateCommand constructor.
     *
     * @param DataMigrationManagerInterface  $dataMigrationManager
     * @param DriverManagerInterface         $driverManager
     * @param DataMigrationExecutorInterface $executor
     * @param DataMigrationMapperInterface   $mapper
     * @param Parser                         $uriParser
     * @param ParameterBagInterface          $parameterBag
     * @param AbstractDumper                 $varDumper
     * @param ClonerInterface                $varCloner
     */
    public function __construct(
        DataMigrationManagerInterface $dataMigrationManager,
        DriverManagerInterface $driverManager,
        DataMigrationExecutorInterface $executor,
        DataMigrationMapperInterface $mapper,
        Parser $uriParser,
        ParameterBagInterface $parameterBag,
        AbstractDumper $varDumper,
        ClonerInterface $varCloner
    ) {
        parent::__construct();

        $this->dataMigrationManager = $dataMigrationManager;
        $this->driverManager = $driverManager;
        $this->executor = $executor;
        $this->mapper = $mapper;
        $this->uriParser = $uriParser;
        $this->parameterBag = $parameterBag;
        $this->varDumper = $varDumper;
        $this->varCloner = $varCloner;
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setDescription('Migrate data')
            ->addOption(
                'group',
                'g',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Migration groups that should be run.  Pass --group once for each group.',
                ['default']
            )->addOption(
                'simulate',
                null,
                InputOption::VALUE_NONE,
                'Simulate results by writing output to the terminal instead of the destination.'
            )->addOption(
                'prune',
                null,
                InputOption::VALUE_NONE,
                'Remove destination entities that do not exist in the source.'
            )->addOption(
                'preserve',
                null,
                InputOption::VALUE_NONE,
                'Keep destination entities that do not exist in the source.'
            )->addArgument(
                'migrations', InputArgument::OPTIONAL | InputArgument::IS_ARRAY,
                'A list of migration classes to run.  Do not specify any migrations to run all migrations.',
                []
            );
    }

    /**
     * @inheritDoc
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        parent::initialize($input, $output);
        $this->io = new SymfonyStyle($input, $output);
    }

    /**
     * {@inheritdoc}
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @throws \DragoonBoots\A2B\Exception\NoDriverForSchemeException
     * @throws \DragoonBoots\A2B\Exception\NonexistentDriverException
     * @throws \DragoonBoots\A2B\Exception\NonexistentMigrationException
     * @throws \DragoonBoots\A2B\Exception\UnclearDriverException
     * @throws \DragoonBoots\A2B\Exception\NoIdSetException
     * @throws \DragoonBoots\A2B\Exception\BadUriException
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Validate options
        if ($input->getOption('prune') && $input->getOption('preserve')) {
            $this->io->error('You cannot use both "--prune" and "--preserve" together.');

            return;
        }

        $migrations = $this->getMigrations($input->getOption('group'), $input->getArgument('migrations'));

        foreach ($migrations as $migration) {
            $definition = $migration->getDefinition();

            // Resolve container parameters source/destination urls
            $definition->setSource($this->parameterBag->resolveValue($definition->getSource()));
            $definition->setDestination($this->parameterBag->resolveValue($definition->getDestination()));

            // Get source driver
            if (!is_null($definition->getSourceDriver())) {
                $sourceDriver = $this->driverManager->getSourceDriver($definition->getSourceDriver());
            } else {
                $sourceUri = $this->uriParser->parse($definition->getSource());
                $sourceDriver = $this->driverManager->getSourceDriverForScheme($sourceUri['scheme']);
            }
            $sourceDriver->configure($definition);
            $migration->configureSource($sourceDriver);

            // Get destination driver
            if ($input->getOption('simulate') === true) {
                $destinationDriver = $this->driverManager->getDestinationDriver(DebugDestinationDriver::class);
                $fakeDefinition = new DataMigration(['destination' => 'debug:stderr']);
                $destinationDriver->configure($fakeDefinition);
            } else {
                if (!is_null($definition->getDestinationDriver())) {
                    $destinationDriver = $this->driverManager->getDestinationDriver($definition->getDestinationDriver());
                } else {
                    $destinationUri = $this->uriParser->parse($definition->getDestination());
                    $destinationDriver = $this->driverManager->getDestinationDriverForScheme($destinationUri['scheme']);
                }
                $destinationDriver->configure($definition);
                $migration->configureDestination($destinationDriver);
            }

            // Run migration
            $orphans = $this->executor->execute($migration, $definition, $sourceDriver, $destinationDriver);

            if (!empty($orphans) && !$input->getOption('prune')) {
                if ($input->getOption('preserve')) {
                    $this->writeOrphans($orphans, $migration, $definition, $destinationDriver);
                } else {
                    $this->askAboutOrphans($orphans, $migration, $definition, $destinationDriver);
                }
            }
            $destinationDriver->flush();
        }
    }

    /**
     * @param array $groups
     * @param array $migrationIds
     *
     * @return DataMigrationInterface[]
     *
     * @throws \DragoonBoots\A2B\Exception\NonexistentMigrationException
     */
    protected function getMigrations(array $groups, array $migrationIds)
    {
        $migrations = [];
        if (empty($migrationIds)) {
            foreach ($groups as $group) {
                $migrations = array_merge(
                    $migrations,
                    $this->dataMigrationManager
                        ->getMigrationsInGroup($group)
                        ->toArray()
                );
            }
        } else {
            foreach ($migrationIds as $migrationId) {
                $migrations[$migrationId] = $this->dataMigrationManager->getMigration($migrationId);
            }
        }

        return $migrations;
    }

    /**
     * Ask the user how to handle each orphan.
     *
     * @param array                      $orphans
     * @param DataMigrationInterface     $migration
     * @param DataMigration              $definition
     * @param DestinationDriverInterface $destinationDriver
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\DBAL\Schema\SchemaException
     * @throws \DragoonBoots\A2B\Exception\NoDestinationException
     */
    protected function askAboutOrphans(array $orphans, DataMigrationInterface $migration, DataMigration $definition, DestinationDriverInterface $destinationDriver)
    {
        define('ORPHANS_REMOVE', 'n');
        define('ORPHANS_KEEP', 'y');
        define('ORPHANS_CHECK', 'c');

        $choices = [
            ORPHANS_KEEP => 'Keep all orphans',
            ORPHANS_REMOVE => 'Remove all orphans',
            ORPHANS_CHECK => 'Make a decision for each orphan',
        ];
        $q = new ChoiceQuestion(
            sprintf('%d entities existed in the destination but do not exist in the source.  Keep them?', count($orphans)),
            $choices,
            ORPHANS_KEEP
        );
        $decision = $this->io->askQuestion($q);
        if ($decision == ORPHANS_KEEP) {
            $this->writeOrphans($orphans, $migration, $definition, $destinationDriver);
        } elseif ($decision == ORPHANS_CHECK) {
            $entityChoices = [
                ORPHANS_KEEP => 'Keep',
                ORPHANS_REMOVE => 'Remove',
            ];
            foreach ($orphans as $orphan) {
                $printableEntity = $this->varDumper->dump($this->varCloner->cloneVar($orphan), true);
                $entityQ = new ChoiceQuestion(
                    sprintf("Keep this entity?\n%s", $printableEntity),
                    $entityChoices,
                    ORPHANS_KEEP
                );
                $entityDecision = $this->io->askQuestion($entityQ);
                if ($entityDecision == ORPHANS_KEEP) {
                    $this->writeOrphan($orphan, $migration, $definition, $destinationDriver);
                }
            }
        }
    }

    /**
     * Write an orphan to the destination and mapping table.
     *
     * @param                            $orphan
     * @param DataMigrationInterface     $migration
     * @param DataMigration              $definition
     * @param DestinationDriverInterface $destinationDriver
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\DBAL\Schema\SchemaException
     * @throws \DragoonBoots\A2B\Exception\NoDestinationException
     */
    protected function writeOrphan($orphan, DataMigrationInterface $migration, DataMigration $definition, DestinationDriverInterface $destinationDriver): void
    {
        $destIds = $destinationDriver->write($orphan);

        // Make a fake set of source ids for the mapper.
        $sourceIds = array_fill_keys(array_keys($destIds), null);
        $this->mapper->addMapping(get_class($migration), $definition, $sourceIds, $destIds);
    }

    /**
     * Write multiple orphans to the destination and mapping table.
     *
     * @param array                      $orphans
     * @param DataMigrationInterface     $migration
     * @param DataMigration              $definition
     * @param DestinationDriverInterface $destinationDriver
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\DBAL\Schema\SchemaException
     * @throws \DragoonBoots\A2B\Exception\NoDestinationException
     */
    protected function writeOrphans(array $orphans, DataMigrationInterface $migration, DataMigration $definition, DestinationDriverInterface $destinationDriver): void
    {
        foreach ($orphans as $orphan) {
            $this->writeOrphan($orphan, $migration, $definition, $destinationDriver);
        }
    }
}
