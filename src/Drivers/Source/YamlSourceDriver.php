<?php


namespace DragoonBoots\A2B\Drivers\Source;


use DragoonBoots\A2B\Annotations\DataMigration;
use DragoonBoots\A2B\Annotations\Driver;
use DragoonBoots\A2B\Drivers\AbstractSourceDriver;
use DragoonBoots\A2B\Drivers\SourceDriverInterface;
use DragoonBoots\A2B\Drivers\YamlDriverTrait;
use DragoonBoots\A2B\Exception\BadUriException;
use DragoonBoots\A2B\Factory\FinderFactory;
use League\Uri\Parser;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Parser as YamlParser;

/**
 * Source driver for yaml files
 *
 * @Driver({"yaml", "yml"})
 */
class YamlSourceDriver extends AbstractSourceDriver implements SourceDriverInterface
{

    use YamlDriverTrait;

    /**
     * @var YamlParser
     */
    protected $yamlParser;

    /**
     * @var FinderFactory
     */
    protected $finderFactory;

    /**
     * @var Finder
     */
    protected $finder;

    /**
     * YamlDestinationDriver constructor.
     *
     * @param Parser        $uriParser
     * @param YamlParser    $yamlParser
     * @param FinderFactory $finderFactory
     */
    public function __construct(Parser $uriParser, YamlParser $yamlParser, FinderFactory $finderFactory)
    {
        parent::__construct($uriParser);

        $this->yamlParser = $yamlParser;
        $this->finderFactory = $finderFactory;
    }

    /**
     * {@inheritdoc}
     */
    public function configure(DataMigration $definition)
    {
        parent::configure($definition);

        if (!is_dir($this->sourceUri['path'])) {
            throw new BadUriException($definition->getSource());
        }
        $this->finder = $this->finderFactory->get()
            ->files()
            ->in($this->sourceUri['path'])
            ->name('`.+\.ya?ml$`')
            ->followLinks()
            ->ignoreDotFiles(true);
    }

    /**
     * {@inheritdoc}
     */
    public function getIterator()
    {
        foreach ($this->finder->getIterator() as $fileInfo) {
            $entity = $this->yamlParser->parse($fileInfo->getContents());
            $sourceIds = $this->buildIdsFromFilePath($fileInfo, $this->sourceIds);
            $entity = $this->addIdsToEntity($sourceIds, $entity);

            yield $entity;
        }
        unset($entity);
    }

    /**
     * {@inheritdoc}
     */
    public function count(): int
    {
        return $this->finder->count();
    }
}
