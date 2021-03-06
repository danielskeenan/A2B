<?php

namespace DragoonBoots\A2B\Tests\DependencyInjection;

use DragoonBoots\A2B\DataMigration\DataMigrationManager;
use DragoonBoots\A2B\Tests\A2BKernelTestCase;
use DragoonBoots\A2B\Tests\A2BTestKernel;
use ProxyManager\Proxy\LazyLoadingInterface;
use ProxyManager\Proxy\ValueHolderInterface;
use ReflectionClass;

class ConfigurationTest extends A2BKernelTestCase
{

    protected static $class = ConfigurationTestKernel::class;

    public function testConfiguration()
    {
        // Reflection can't be used with proxy objects, so force initialize it.
        /** @var LazyLoadingInterface|ValueHolderInterface $migrationManager */
        $migrationManager = self::$container->get('a2b.data_migration_manager');
        $migrationManager->initializeProxy();
        /** @var DataMigrationManager $migrationManager */
        $migrationManager = $migrationManager->getWrappedValueHolderValue();

        $refl = new ReflectionClass($migrationManager);
        $sourcesProperty = $refl->getProperty('sources');
        $sourcesProperty->setAccessible(true);
        $destinationsProperty = $refl->getProperty('destinations');
        $destinationsProperty->setAccessible(true);
        $this->assertEquals(
            [
                'test_source' => [
                    'uri' => 'source_test',
                    'driver' => 'source_driver',
                ],
            ],
            $sourcesProperty->getValue($migrationManager)
        );
        $this->assertEquals(
            [
                'test_destination' => [
                    'uri' => 'dest_test',
                    'driver' => 'dest_driver',
                ],
            ],
            $destinationsProperty->getValue($migrationManager)
        );
    }

    protected function setUp(): void
    {
        self::bootKernel();
    }

}

class ConfigurationTestKernel extends A2BTestKernel
{

    /**
     * {@inheritdoc}
     */
    protected function getConfiguration(): array
    {
        $config = parent::getConfiguration();

        $config['a2b'] = [
            'sources' => [
                [
                    'name' => 'test_source',
                    'uri' => 'source_test',
                    'driver' => 'source_driver',
                ],
            ],
            'destinations' => [
                [
                    'name' => 'test_destination',
                    'uri' => 'dest_test',
                    'driver' => 'dest_driver',
                ],
            ],
        ];

        return $config;
    }

}
