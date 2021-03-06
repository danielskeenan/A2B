<?php

namespace DragoonBoots\A2B\Tests\Drivers\Destination;

use ArrayIterator;
use DragoonBoots\A2B\Annotations\DataMigration;
use DragoonBoots\A2B\Annotations\IdField;
use DragoonBoots\A2B\Drivers\Destination\YamlDestinationDriver;
use DragoonBoots\A2B\Factory\FinderFactory;
use DragoonBoots\A2B\Tests\Drivers\FinderTestTrait;
use DragoonBoots\YamlFormatter\Yaml\YamlDumper;
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamDirectory;
use org\bovigo\vfs\vfsStreamFile;
use org\bovigo\vfs\vfsStreamWrapper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RangeException;
use ReflectionClass;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\Yaml\Parser as YamlParser;

class YamlDestinationDriverTest extends TestCase
{

    use FinderTestTrait;

    /**
     * @var YamlDumper
     */
    protected $yamlDumper;

    /**
     * @var YamlParser
     */
    protected $yamlParser;

    /**
     * @var FinderFactory|MockObject
     */
    protected $finderFactory;

    /**
     * @var Finder|MockObject
     */
    protected $finder;

    public function testConfigure()
    {
        $path = vfsStream::url('data/new_dir');
        $definition = new DataMigration(
            [
                'destination' => $path,
                'destinationIds' => [
                    new IdField(['name' => 'group', 'type' => 'string']),
                    new IdField(['name' => 'identifier', 'type' => 'string']),
                ],
            ]
        );
        $driver = new YamlDestinationDriver($this->yamlParser, $this->yamlDumper, $this->finderFactory);
        $driver->configure($definition);
        $this->assertDirectoryIsWritable($path);

        $refl = new ReflectionClass($driver);
        $optionsProperty = $refl->getProperty('options');
        $optionsProperty->setAccessible(true);

        $newIndent = 4;
        $driver->setOption('indent', $newIndent);
        $this->assertEquals($newIndent, $optionsProperty->getValue($driver)['indent']);
        // Refs options tested when writing anchors
    }

    public function testRead()
    {
        $destIds = ['group' => 'test', 'identifier' => 'existing_file'];
        $expectedEntity = [
            'group' => 'test',
            'identifier' => 'existing_file',
            'field' => 'value',
            'list' => [
                'item1',
                'item2',
            ],
            'referenced_list' => [
                'item1',
                'item2',
            ],
        ];
        $path = vfsStream::url('data/existing_dir');
        $definition = new DataMigration(
            [
                'destination' => $path,
                'destinationIds' => [
                    new IdField(['name' => 'group', 'type' => 'string']),
                    new IdField(['name' => 'identifier', 'type' => 'string']),
                ],
            ]
        );

        $fileInfo = new SplFileInfo(
            vfsStream::url('data/existing_dir/test/existing_file.yaml'),
            'test',
            'test/existing_file.yaml'
        );
        $this->finder->method('count')
            ->willReturn(1);
        $this->finder->method('getIterator')
            ->willReturn(new ArrayIterator([$fileInfo]));

        $driver = new YamlDestinationDriver($this->yamlParser, $this->yamlDumper, $this->finderFactory);
        $driver->configure($definition);
        $foundEntity = $driver->read($destIds);
        $this->assertEquals($expectedEntity, $foundEntity);
    }

    public function testReadNonexistantEntity()
    {
        $destIds = ['group' => 'test', 'identifier' => 'nonexistent_file'];
        $path = vfsStream::url('data/nonexistent_dir');
        $definition = new DataMigration(
            [
                'destination' => $path,
                'destinationIds' => [
                    new IdField(['name' => 'group', 'type' => 'string']),
                    new IdField(['name' => 'identifier', 'type' => 'string']),
                ],
            ]
        );

        $this->finder->method('count')
            ->willReturn(0);
        $this->finder->expects($this->never())
            ->method('getIterator');

        $driver = new YamlDestinationDriver($this->yamlParser, $this->yamlDumper, $this->finderFactory);
        $driver->configure($definition);
        $foundEntity = $driver->read($destIds);
        $this->assertNull($foundEntity);
    }

    public function testReadMultipleResults()
    {
        $destIds = ['group' => 'test', 'identifier' => 'multiple_files'];
        $path = vfsStream::url('data/existing_dir');
        $definition = new DataMigration(
            [
                'destination' => $path,
                'destinationIds' => [
                    new IdField(['name' => 'group', 'type' => 'string']),
                    new IdField(['name' => 'identifier', 'type' => 'string']),
                ],
            ]
        );

        $driver = new YamlDestinationDriver($this->yamlParser, $this->yamlDumper, $this->finderFactory);
        $driver->configure($definition);
        $this->expectException(RangeException::class);
        $driver->read($destIds);
    }

    public function testReadMultiple()
    {
        $destIdSet = [
            ['group' => 'test', 'identifier' => 'existing_file'],
            ['group' => 'test', 'identifier' => 'other_file'],
        ];
        $expectedEntities = [
            [
                'group' => 'test',
                'identifier' => 'existing_file',
                'field' => 'value',
                'list' => [
                    'item1',
                    'item2',
                ],
                'referenced_list' => [
                    'item1',
                    'item2',
                ],
            ],
            [
                'group' => 'test',
                'identifier' => 'other_file',
                'field' => 'value',
                'list' => [
                    'item1',
                    'item2',
                ],
                'referenced_list' => [
                    'item1',
                    'item2',
                ],
            ],
        ];
        $path = vfsStream::url('data/existing_dir');
        $definition = new DataMigration(
            [
                'destination' => $path,
                'destinationIds' => [
                    new IdField(['name' => 'group', 'type' => 'string']),
                    new IdField(['name' => 'identifier', 'type' => 'string']),
                ],
            ]
        );

        $fileInfo = [
            new SplFileInfo(
                vfsStream::url('data/existing_dir/test/existing_file.yaml'),
                'test',
                'test/existing_file.yaml'
            ),
            new SplFileInfo(vfsStream::url('data/existing_dir/test/other_file.yaml'), 'test', 'text/other_file.yaml'),
        ];
        $this->finder->method('count')
            ->willReturn(1);
        $this->finder->method('getIterator')
            ->willReturn(new ArrayIterator($fileInfo));

        $driver = new YamlDestinationDriver($this->yamlParser, $this->yamlDumper, $this->finderFactory);
        $driver->configure($definition);
        $foundEntities = $driver->readMultiple($destIdSet);
        $this->assertEquals($expectedEntities, $foundEntities);
    }

    public function testGetExistingIds()
    {
        $destIdSet = [['group' => 'test', 'identifier' => 'existing_file']];
        $path = vfsStream::url('data/existing_dir');
        $definition = new DataMigration(
            [
                'destination' => $path,
                'destinationIds' => [
                    new IdField(['name' => 'group', 'type' => 'string']),
                    new IdField(['name' => 'identifier', 'type' => 'string']),
                ],
            ]
        );

        $fileInfo = new SplFileInfo(
            vfsStream::url('data/existing_dir/test/existing_file.yaml'),
            'test',
            'test/existing_file.yaml'
        );
        $this->finder->method('count')
            ->willReturn(1);
        $this->finder->method('getIterator')
            ->willReturn(new ArrayIterator([$fileInfo]));

        $driver = new YamlDestinationDriver($this->yamlParser, $this->yamlDumper, $this->finderFactory);
        $driver->configure($definition);
        $foundIds = $driver->getExistingIds();
        $this->assertEquals($destIdSet, $foundIds);
    }

    /**
     * @param mixed $useRefs
     * @param string $expected
     *
     * @dataProvider writeDataProvider
     */
    public function testWrite($useRefs, string $expected)
    {
        $destIds = ['group' => 'new_group', 'identifier' => 'new_file'];
        $newEntity = [
            'group' => 'new_group',
            'identifier' => 'new_file',
            'scalar_field' => 'value',
            'list' => [
                'item1',
                'item2',
            ],
            'referenced_list' => [
                'item1',
                'item2',
            ],
            'referenced_scalar_field' => 'value',
            'mapping_field' => [
                'inner_field' => 'inner value',
            ],
            'other_mapping_field' => [
                'inner_field' => 'inner value',
            ],
            'deep_mapping_field' => [
                'inner_field' => 'inner value',
            ],
            'deep_mapping_field_extra' => [
                'inner_field' => 'inner value',
                'other_field' => 'other value',
            ],
        ];
        $path = vfsStream::url('data/existing_dir');
        $definition = new DataMigration(
            [
                'destination' => $path,
                'destinationIds' => [
                    new IdField(['name' => 'group', 'type' => 'string']),
                    new IdField(['name' => 'identifier', 'type' => 'string']),
                ],
            ]
        );

        $driver = new YamlDestinationDriver($this->yamlParser, $this->yamlDumper, $this->finderFactory);
        $driver->configure($definition);
        $driver->setOption('refs', $useRefs);
        $newIds = $driver->write($newEntity);

        // Test proper ids are returned
        $this->assertEquals($destIds, $newIds);

        // Test file contents are written properly
        $driver->flush();
        $innerPath = str_replace('vfs://', '', $path);
        /** @var vfsStreamFile|null $file */
        $file = vfsStreamWrapper::getRoot()
            ->getChild($innerPath.'/new_group/new_file.yaml');
        $this->assertNotNull($file, 'File was not copied to destination.');
        $this->assertEquals($expected, $file->getContent());

        // Test that output is valid yaml
        $parsedEntity = $this->yamlParser->parse($file->getContent());
        $parsedEntity['group'] = $newEntity['group'];
        $parsedEntity['identifier'] = $newEntity['identifier'];
        $this->assertEquals($newEntity, $parsedEntity);
    }

    public function writeDataProvider(): array
    {
        return [
            'no refs' => [
                false,
                <<<YAML
scalar_field: value
list:
  - item1
  - item2
referenced_list:
  - item1
  - item2
referenced_scalar_field: value
mapping_field:
  inner_field: 'inner value'
other_mapping_field:
  inner_field: 'inner value'
deep_mapping_field:
  inner_field: 'inner value'
deep_mapping_field_extra:
  inner_field: 'inner value'
  other_field: 'other value'

YAML
                ,
            ],
            'build all refs' => [
                true,
                <<<YAML
scalar_field: value
list: &list
  - item1
  - item2
referenced_list: *list
referenced_scalar_field: value
mapping_field: &mapping_field
  inner_field: &mapping_field.inner_field 'inner value'
other_mapping_field: *mapping_field
deep_mapping_field: *mapping_field
deep_mapping_field_extra:
  inner_field: *mapping_field.inner_field
  other_field: 'other value'

YAML
                ,
            ],
            'build included refs' => [
                ['include' => ['`[^.]+field$`']],
                <<<YAML
scalar_field: value
list:
  - item1
  - item2
referenced_list:
  - item1
  - item2
referenced_scalar_field: value
mapping_field: &mapping_field
  inner_field: 'inner value'
other_mapping_field: *mapping_field
deep_mapping_field: *mapping_field
deep_mapping_field_extra:
  inner_field: 'inner value'
  other_field: 'other value'

YAML
                ,
            ],
            'build excluded refs' => [
                ['exclude' => ['`[^.]+field$`']],
                <<<YAML
scalar_field: value
list: &list
  - item1
  - item2
referenced_list: *list
referenced_scalar_field: value
mapping_field:
  inner_field: 'inner value'
other_mapping_field:
  inner_field: 'inner value'
deep_mapping_field:
  inner_field: 'inner value'
deep_mapping_field_extra:
  inner_field: 'inner value'
  other_field: 'other value'

YAML
                ,
            ],
            'build complex refs' => [
                [
                    'include' => ['`[^.]+field$`'],
                    'exclude' => ['`.+\.inner_field`'],
                ],
                <<<YAML
scalar_field: value
list:
  - item1
  - item2
referenced_list:
  - item1
  - item2
referenced_scalar_field: value
mapping_field: &mapping_field
  inner_field: 'inner value'
other_mapping_field: *mapping_field
deep_mapping_field: *mapping_field
deep_mapping_field_extra:
  inner_field: 'inner value'
  other_field: 'other value'

YAML
                ,
            ],
        ];
    }

    protected function setUp(): void
    {
        vfsStreamWrapper::register();
        vfsStreamWrapper::setRoot(new vfsStreamDirectory('data'));
        vfsStream::copyFromFileSystem(TEST_RESOURCES_ROOT.'/Drivers/Destination/YamlDestinationDriverTest');

        $this->yamlDumper = new YamlDumper();
        $this->yamlParser = new YamlParser();

        $this->finder = $this->createMock(Finder::class);
        $this->finder = $this->buildFinderMock($this->finder);
        $this->finderFactory = $this->createMock(FinderFactory::class);
        $this->finderFactory->method('get')->willReturn($this->finder);
    }
}
