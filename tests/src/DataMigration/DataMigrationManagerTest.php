<?php

namespace DragoonBoots\A2B\Tests\DataMigration;

use Doctrine\Common\Annotations\Reader;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use DragoonBoots\A2B\Annotations\DataMigration;
use DragoonBoots\A2B\Annotations\IdField;
use DragoonBoots\A2B\DataMigration\DataMigrationInterface;
use DragoonBoots\A2B\DataMigration\DataMigrationManager;
use DragoonBoots\A2B\Drivers\DestinationDriverInterface;
use DragoonBoots\A2B\Drivers\DriverManagerInterface;
use DragoonBoots\A2B\Drivers\SourceDriverInterface;
use DragoonBoots\A2B\Exception\MigrationException;
use DragoonBoots\A2B\Exception\NonexistentMigrationException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class DataMigrationManagerTest extends TestCase
{

    /**
     * @param DataMigration $definition
     * @param DataMigration|null $resolvedDefinition
     * @param array[] $sourceKeys
     * @param array[] $destKeys
     *
     * @dataProvider addMigrationDataProvider
     */
    public function testAddMigration(
        DataMigration $definition,
        DataMigration $resolvedDefinition = null,
        array $sourceKeys = [],
        array $destKeys = []
    ): array {
        if (is_null($resolvedDefinition)) {
            $resolvedDefinition = clone $definition;
        }

        $sourceDriver = $this->createMock(SourceDriverInterface::class);
        $resolvedDefinition->setSourceDriver(get_class($sourceDriver));
        $destinationDriver = $this->createMock(DestinationDriverInterface::class);
        $resolvedDefinition->setDestinationDriver(get_class($destinationDriver));
        $driverManager = $this->createMock(DriverManagerInterface::class);
        $driverManager->expects($this->once())
            ->method('getSourceDriver')
            ->with('source_driver')
            ->willReturn($sourceDriver);
        $driverManager->expects($this->once())
            ->method('getDestinationDriver')
            ->with('dest_driver')
            ->willReturn($destinationDriver);

        /** @var DataMigrationInterface|MockObject $migration */
        $migration = $this->getMockBuilder(DataMigrationInterface::class)
            ->disableOriginalConstructor()
            ->setMockClassName('TestMigration')
            ->getMock();
        $migration->expects($this->once())
            ->method('setDefinition')
            ->with($resolvedDefinition);
        $migration->method('getDefinition')
            ->willReturn($resolvedDefinition);

        $annotationReader = $this->createMock(Reader::class);
        $annotationReader->expects($this->once())
            ->method('getClassAnnotation')
            ->with(new ReflectionClass($migration), DataMigration::class)
            ->willReturn($definition);

        $parameterBag = $this->createMock(ParameterBagInterface::class);
        $parameterBag->expects($this->exactly(2))
            ->method('resolveValue')
            ->willReturnArgument(0);

        $dataMigrationManager = new DataMigrationManager($annotationReader, $driverManager, $parameterBag);
        foreach ($sourceKeys as $key => $info) {
            $dataMigrationManager->addSource($key, $info['uri'], $info['driver']);
        }
        foreach ($destKeys as $key => $info) {
            $dataMigrationManager->addDestination($key, $info['uri'], $info['driver']);
        }

        // Do the test
        $dataMigrationManager->addMigration($migration);
        $this->assertEquals(
            new ArrayCollection(['TestMigration' => $migration]),
            $dataMigrationManager->getMigrations()
        );
        $this->assertSame($migration, $dataMigrationManager->getMigration(get_class($migration)));

        return [$migration, $dataMigrationManager];
    }

    public function addMigrationDataProvider(): array
    {
        return [
            'standard' => [
                new DataMigration(
                    [
                        'source' => 'test_source',
                        'sourceDriver' => 'source_driver',
                        'destination' => 'test_destination',
                        'destinationDriver' => 'dest_driver',
                    ]
                ),
            ],
            'with aliases' => [
                // Migration definition
                new DataMigration(
                    [
                        'source' => 'test_source_alias',
                        'destination' => 'test_destination_alias',
                    ]
                ),
                // Resolved definition
                new DataMigration(
                    [
                        'source' => 'test_source',
                        'sourceDriver' => 'source_driver',
                        'destination' => 'test_destination',
                        'destinationDriver' => 'dest_driver',
                    ]
                ),
                // Source aliases
                [
                    'test_source_alias' => [
                        'uri' => 'test_source',
                        'driver' => 'source_driver',
                    ],
                ],
                // Destination aliases
                [
                    'test_destination_alias' => [
                        'uri' => 'test_destination',
                        'driver' => 'dest_driver',
                    ],
                ],
            ],
        ];
    }

    /**
     * @dataProvider addMigrationExtendsDataProvider
     *
     * @param DataMigration $extendsDefinition
     * @param string|null $exception
     */
    public function testAddMigrationExtends(DataMigration $extendsDefinition, ?string $exception = null)
    {
        $definition = new DataMigration(
            [
                'source' => 'test_source',
                'sourceDriver' => 'source_driver',
                'sourceIds' => [new IdField(['name' => 'id'])],
                'destination' => 'test_destination',
                'destinationDriver' => 'dest_driver',
                'destinationIds' => [new IdField(['name' => 'id'])],
            ]
        );
        $migration = $this->getMockBuilder(DataMigrationInterface::class)
            ->disableOriginalConstructor()
            ->setMockClassName('TestMigration')
            ->getMock();
        $migration->method('getDefinition')
            ->willReturn($definition);
        $extendsMigration = $this->getMockBuilder(DataMigrationInterface::class)
            ->disableOriginalConstructor()
            ->setMockClassName('TestExtendsMigration')
            ->getMock();
        $extendsMigration->method('getDefinition')
            ->willReturn($extendsDefinition);

        $annotationReader = $this->createMock(Reader::class);
        $annotationReader->method('getClassAnnotation')
            ->willReturnCallback(
                function (ReflectionClass $refl, string $annotationName) use (
                    $migration,
                    $extendsMigration,
                    $definition,
                    $extendsDefinition
                ) {
                    if ($annotationName == DataMigration::class) {
                        switch ($refl->getName()) {
                            case get_class($migration):
                                return $definition;
                            case get_class($extendsMigration):
                                return $extendsDefinition;
                        }
                    }

                    return null;
                }
            );

        $sourceDriver = $this->createMock(SourceDriverInterface::class);
        $destinationDriver = $this->createMock(DestinationDriverInterface::class);
        $driverManager = $this->createMock(DriverManagerInterface::class);
        $driverManager->method('getSourceDriver')
            ->with('source_driver')
            ->willReturn($sourceDriver);
        $driverManager->method('getDestinationDriver')
            ->with('dest_driver')
            ->willReturn($destinationDriver);

        $parameterBag = $this->createMock(ParameterBagInterface::class);
        $parameterBag->method('resolveValue')
            ->willReturnArgument(0);

        $dataMigrationManager = new DataMigrationManager($annotationReader, $driverManager, $parameterBag);

        foreach ([$migration, $extendsMigration] as $item) {
            $dataMigrationManager->addMigration($item);
        }

        if (!is_null($exception)) {
            $this->expectException($exception);
        }
        self::assertSame(
            $migration,
            $dataMigrationManager->getMigration('TestExtendsMigration')
                ->getDefinition()
                ->getExtends()
        );

    }

    public function addMigrationExtendsDataProvider(): array
    {
        return [
            'good' => [
                new DataMigration(
                    [
                        'source' => 'test_source',
                        'sourceDriver' => 'source_driver',
                        'sourceIds' => [new IdField(['name' => 'id'])],
                        'destination' => 'test_destination',
                        'destinationDriver' => 'dest_driver',
                        'destinationIds' => [new IdField(['name' => 'id'])],
                        'extends' => 'TestMigration',
                    ]
                ),
            ],
            'non-matching source' => [
                new DataMigration(
                    [
                        'source' => 'testSource://other',
                        'sourceDriver' => 'source_driver',
                        'sourceIds' => [new IdField(['name' => 'id'])],
                        'destination' => 'testDestination://test',
                        'destinationDriver' => 'dest_driver',
                        'destinationIds' => [new IdField(['name' => 'id'])],
                        'extends' => 'TestMigration',
                    ]
                ),
                MigrationException::class,
            ],
            'non-matching destination' => [
                new DataMigration(
                    [
                        'source' => 'testSource://test',
                        'sourceDriver' => 'source_driver',
                        'sourceIds' => [new IdField(['name' => 'id'])],
                        'destination' => 'testDestination://other',
                        'destinationDriver' => 'dest_driver',
                        'destinationIds' => [new IdField(['name' => 'id'])],
                        'extends' => 'TestMigration',
                    ]
                ),
                MigrationException::class,
            ],
            'non-matching source ids' => [
                new DataMigration(
                    [
                        'source' => 'testSource://test',
                        'sourceDriver' => 'source_driver',
                        'sourceIds' => [
                            new IdField(
                                [
                                    'name' => 'identifier',
                                    'type' => 'string',
                                ]
                            ),
                        ],
                        'destination' => 'testDestination://test',
                        'destinationDriver' => 'dest_driver',
                        'destinationIds' => [new IdField(['name' => 'id'])],
                        'extends' => 'TestMigration',
                    ]
                ),
                MigrationException::class,
            ],
            'non-matching destination ids' => [
                new DataMigration(
                    [
                        'source' => 'testSource://test',
                        'sourceDriver' => 'source_driver',
                        'sourceIds' => [new IdField(['name' => 'id'])],
                        'destination' => 'testDestination://test',
                        'destinationDriver' => 'dest_driver',
                        'destinationIds' => [
                            new IdField(
                                [
                                    'name' => 'identifier',
                                    'type' => 'string',
                                ]
                            ),
                        ],
                        'extends' => 'TestMigration',
                    ]
                ),
                MigrationException::class,
            ],
        ];
    }

    public function testGetMigrationBad()
    {
        $annotationReader = $this->createMock(Reader::class);
        $driverManager = $this->createMock(DriverManagerInterface::class);

        $parameterBag = $this->createMock(ParameterBagInterface::class);
        $parameterBag->method('resolveValue')
            ->willReturnArgument(0);

        $dataMigrationManager = new DataMigrationManager($annotationReader, $driverManager, $parameterBag);
        $this->expectException(NonexistentMigrationException::class);
        $dataMigrationManager->getMigration('NonexistantMigration');
    }

    public function testGetMigrationsInGroup()
    {
        /** @var DataMigrationInterface[]|MockObject[] $migrations */
        $migrations = [
            'Group1Migration' => $this->getMockBuilder(DataMigrationInterface::class)
                ->disableOriginalConstructor()
                ->setMockClassName('Group1Migration')
                ->getMock(),
            'Group2Migration' => $this->getMockBuilder(DataMigrationInterface::class)
                ->disableOriginalConstructor()
                ->setMockClassName('Group2Migration')
                ->getMock(),
        ];
        $definitions = [
            'Group1Migration' => new DataMigration(['group' => 'Group1']),
            'Group2Migration' => new DataMigration(['group' => 'Group2']),
        ];
        foreach ($migrations as $id => $migration) {
            $migration->method('getDefinition')
                ->willReturn($definitions[$id]);
        }
        $annotationReader = $this->createMock(Reader::class);
        $annotationReader->method('getClassAnnotation')
            ->willReturnCallback(
                function (ReflectionClass $reflectionClass, string $annotationName) use ($definitions) {
                    return $definitions[$reflectionClass->getName()] ?? null;
                }
            );
        $driverManager = $this->createMock(DriverManagerInterface::class);

        $parameterBag = $this->createMock(ParameterBagInterface::class);
        $parameterBag->method('resolveValue')
            ->willReturnArgument(0);

        $dataMigrationManager = new DataMigrationManager($annotationReader, $driverManager, $parameterBag);

        // Inject the migrations
        $refl = new ReflectionClass($dataMigrationManager);
        $migrationsProperty = $refl->getProperty('migrations');
        $migrationsProperty->setAccessible(true);
        $migrationsProperty->setValue($dataMigrationManager, new ArrayCollection($migrations));

        $expected = new ArrayCollection(['Group1Migration' => $migrations['Group1Migration']]);
        $this->assertEquals(
            $expected,
            $dataMigrationManager->getMigrationsInGroup('Group1')
        );
    }

    /**
     * @param DataMigrationInterface[]|MockObject[] $migrations
     * @param DataMigration[] $definitions
     * @param DataMigration[] $requested
     * @param DataMigration[]|Collection $expectedRunList
     * @param string[] $expectedExtrasAdded
     *
     * @dataProvider dependencyResolutionDataProvider
     */
    public function testResolveDependencies(
        array $migrations,
        array $definitions,
        array $requested,
        $expectedRunList,
        array $expectedExtrasAdded
    ) {
        $annotationReader = $this->createMock(Reader::class);
        $annotationReader->method('getClassAnnotation')
            ->willReturnCallback(
                function (ReflectionClass $reflectionClass, string $annotationName) use ($definitions) {
                    return $definitions[$reflectionClass->getName()] ?? null;
                }
            );
        $driverManager = $this->createMock(DriverManagerInterface::class);

        $parameterBag = $this->createMock(ParameterBagInterface::class);
        $parameterBag->method('resolveValue')
            ->willReturnArgument(0);

        $dataMigrationManager = new DataMigrationManager($annotationReader, $driverManager, $parameterBag);

        // Inject the migrations
        $refl = new ReflectionClass($dataMigrationManager);
        $migrationsProperty = $refl->getProperty('migrations');
        $migrationsProperty->setAccessible(true);
        $migrationsProperty->setValue($dataMigrationManager, new ArrayCollection($migrations));

        $runList = $dataMigrationManager->resolveDependencies($requested, $extrasAdded);
        $this->assertEquals($expectedRunList, $runList);
        $this->assertEquals($expectedExtrasAdded, $extrasAdded);
    }

    public function dependencyResolutionDataProvider(): array
    {
        /** @var DataMigrationInterface[]|MockObject[] $migrations */
        $migrations = [
            'FirstMigration' => $this->getMockBuilder(DataMigrationInterface::class)
                ->disableOriginalConstructor()
                ->setMockClassName('FirstMigration')
                ->getMock(),
            'DependentMigration' => $this->getMockBuilder(DataMigrationInterface::class)
                ->disableOriginalConstructor()
                ->setMockClassName('DependentMigration')
                ->getMock(),
        ];
        $definitions = [
            'FirstMigration' => new DataMigration(['depends' => [get_class($migrations['DependentMigration'])]]),
            'DependentMigration' => new DataMigration([]),
        ];
        foreach ($migrations as $group => $migration) {
            $migration->method('getDefinition')
                ->willReturn($definitions[$group]);
        }

        return [
            'all migrations' => [
                $migrations,
                $definitions,
                // Requested list
                $migrations,
                // Run list
                new ArrayCollection(array_values(array_reverse($migrations))),
                // Extras added list
                [],
            ],
            'dependency not specified' => [
                $migrations,
                $definitions,
                // Requested list
                [$migrations['FirstMigration']],
                // Run list
                new ArrayCollection(array_values(array_reverse($migrations))),
                // Extras added list
                [get_class($migrations['DependentMigration'])],
            ],
        ];
    }
}
