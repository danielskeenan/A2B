<?php

namespace DragoonBoots\A2B\Tests\Drivers\Source;

use DragoonBoots\A2B\Annotations\DataMigration;
use DragoonBoots\A2B\Annotations\IdField;
use DragoonBoots\A2B\Drivers\Source\CsvSourceDriver;
use DragoonBoots\A2B\Exception\BadUriException;
use League\Uri\Parser;
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamDirectory;
use org\bovigo\vfs\vfsStreamWrapper;
use PHPUnit\Framework\TestCase;

class CsvSourceDriverTest extends TestCase
{

    /**
     * @var DataMigration
     */
    protected $definition;

    /**
     * @var CsvSourceDriver
     */
    protected $driver;

    public function testConfigure()
    {
        $this->setupDriver();
        $this->driver->configure($this->definition);
    }

    /**
     * @param null|string $url
     */
    protected function setupDriver(?string $url = null)
    {
        $url = $url ?? vfsStream::url('data/source.csv');
        $sourceUri = 'csv://'.$url;
        $this->definition = new DataMigration(
            [
                'source' => $sourceUri,
                'sourceIds' => [
                    new IdField(
                        [
                            'name' => 'identifier',
                            'type' => 'string',
                        ]
                    ),
                ],
            ]
        );

        $uriParser = $this->createMock(Parser::class);
        $uriParser->expects($this->once())
            ->method('parse')
            ->with($sourceUri)
            ->willReturn(['path' => $url]);
        $this->driver = new CsvSourceDriver($uriParser);
    }

    /**
     * @param string $file
     * @param string $excpetionName
     *
     * @dataProvider configureBadDataProvider
     */
    public function testConfigureBad(string $file, string $excpetionName)
    {
        $this->setupDriver(vfsStream::url('data/'.$file));
        $this->expectException($excpetionName);
        $this->driver->configure($this->definition);
    }

    public function configureBadDataProvider()
    {
        return [
            'nonexistent file' => ['no.csv', BadUriException::class],
            'empty' => ['empty.csv', BadUriException::class],
            'no contents' => ['no_contents.csv', BadUriException::class],
        ];
    }

    public function testGetIterator()
    {
        $expected = [
            [
                'identifier' => 'test',
                'field1' => 'Test',
                'field2' => 'Data',
            ],
            [
                'identifier' => 'other',
                'field1' => 'Other',
                'field2' => 'Data',
            ],
        ];

        $this->setupDriver();
        $this->driver->configure($this->definition);

        $actual = [];
        foreach ($this->driver->getIterator() as $row) {
            $actual[] = $row;
        }

        $this->assertEquals($expected, $actual);
    }

    public function testCount()
    {
        $expected = 2;

        $this->setupDriver();
        $this->driver->configure($this->definition);

        $this->assertEquals($expected, $this->driver->count());
    }

    protected function setUp()
    {
        vfsStreamWrapper::register();
        vfsStreamWrapper::setRoot(new vfsStreamDirectory('data'));
        vfsStream::copyFromFileSystem(TEST_RESOURCES_ROOT.'/Drivers/Source/CsvSourceDriverTest');
    }
}
