<?php

namespace Keboola\OutputMapping\Tests\Writer;

use Keboola\InputMapping\Staging\NullProvider;
use Keboola\InputMapping\Staging\ProviderInterface;
use Keboola\InputMapping\Staging\Scope;
use Keboola\OutputMapping\Staging\StrategyFactory;
use Keboola\OutputMapping\Tests\InitSynapseStorageClientTrait;
use Keboola\OutputMapping\Writer\FileWriter;
use Keboola\OutputMapping\Writer\TableWriter;
use Keboola\StorageApi\Options\ListFilesOptions;
use Keboola\StorageApi\Workspaces;
use MicrosoftAzure\Storage\Blob\BlobRestProxy;
use Psr\Log\NullLogger;

class AbsWriterWorkspaceTest extends BaseWriterWorkspaceTest
{
    use InitSynapseStorageClientTrait;
    
    /** @var array */
    protected $workspace;

    public function setUp()
    {
        if (!$this->checkSynapseTests()) {
            self::markTestSkipped('Synapse tests disabled.');
        }
        parent::setUp();
        $this->clearBuckets([
            'in.c-output-mapping-test',
            'out.c-output-mapping-test',
        ]);
    }

    protected function initClient()
    {
        $this->clientWrapper = $this->getSynapseClientWrapper();
    }

    protected function getStagingFactory($clientWrapper = null, $format = 'json', $logger = null, $backend = [StrategyFactory::WORKSPACE_SNOWFLAKE, 'snowflake'])
    {
        $stagingFactory = new StrategyFactory(
            $clientWrapper ? $clientWrapper : $this->clientWrapper,
            $logger ? $logger : new NullLogger(),
            $format
        );
        $mockWorkspace = self::getMockBuilder(NullProvider::class)
            ->setMethods(['getWorkspaceId', 'getCredentials'])
            ->getMock();
        $mockWorkspace->method('getWorkspaceId')->willReturnCallback(
            function () use ($backend) {
                if (!$this->workspaceId) {
                    $workspaces = new Workspaces($this->clientWrapper->getBasicClient());
                    $workspace = $workspaces->createWorkspace(['backend' => $backend[1]]);
                    $this->workspaceId = $workspace['id'];
                    $this->workspace = $workspace;
                    $this->workspaceCredentials = $workspace['connection'];
                }
                return $this->workspaceId;
            }
        );
        $mockWorkspace->method('getCredentials')->willReturnCallback(
            function () use ($backend) {
                if (!$this->workspaceId) {
                    $workspaces = new Workspaces($this->clientWrapper->getBasicClient());
                    $workspace = $workspaces->createWorkspace(['backend' => $backend[1]]);
                    $this->workspaceId = $workspace['id'];
                    $this->workspace = $workspace;
                    $this->workspaceCredentials = $workspace['connection'];
                }
                return $this->workspaceCredentials;
            }
        );
        $mockLocal = self::getMockBuilder(NullProvider::class)
            ->setMethods(['getPath'])
            ->getMock();
        $mockLocal->method('getPath')->willReturnCallback(
            function () {
                return $this->tmp->getTmpFolder();
            }
        );
        /** @var ProviderInterface $mockLocal */
        /** @var ProviderInterface $mockWorkspace */
        $stagingFactory->addProvider(
            $mockLocal,
            [
                $backend[0] => new Scope([Scope::FILE_METADATA, Scope::TABLE_METADATA]),
            ]
        );
        $stagingFactory->addProvider(
            $mockWorkspace,
            [
                $backend[0] => new Scope([Scope::FILE_METADATA, Scope::FILE_DATA, Scope::TABLE_METADATA, Scope::TABLE_DATA])
            ]
        );
        return $stagingFactory;
    }

    public function testAbsTableOutputMapping()
    {
        $factory = $this->getStagingFactory(null, 'json', null, [StrategyFactory::WORKSPACE_ABS, 'abs']);
        // initialize the workspace mock
        $factory->getTableOutputStrategy(StrategyFactory::WORKSPACE_ABS)->getDataStorage()->getWorkspaceId();
        $root = $this->tmp->getTmpFolder();
        $this->prepareWorkspaceWithTables('abs');

        $configs = [
            [
                'source' => 'table1a',
                'destination' => 'out.c-output-mapping-test.table1a',
                'incremental' => true,
                'columns' => ['Id'],
            ],
            [
                'source' => 'table2a',
                'destination' => 'out.c-output-mapping-test.table2a',
            ],
        ];
        file_put_contents(
            $root . '/table1a.manifest',
            json_encode(
                ['columns' => ['Id', 'Name']]
            )
        );
        file_put_contents(
            $root . '/table2a.manifest',
            json_encode(
                ['columns' => ['Id2', 'Name2']]
            )
        );

        $writer = new TableWriter($factory);
        $tableQueue = $writer->uploadTables(
            $root,
            ['mapping' => $configs],
            ['componentId' => 'foo'],
            'workspace-abs'
        );
        $jobIds = $tableQueue->waitForAll();
        $this->assertCount(2, $jobIds);

        $tables = $this->clientWrapper->getBasicClient()->listTables('out.c-output-mapping-test');
        $this->assertCount(2, $tables);
        $tableIds = [$tables[0]['id'], $tables[1]['id']];
        sort($tableIds);
        $this->assertEquals(['out.c-output-mapping-test.table1a', 'out.c-output-mapping-test.table2a'], $tableIds);
        $this->assertCount(2, $jobIds);
        $this->assertNotEmpty($jobIds[0]);
        $this->assertNotEmpty($jobIds[1]);
        $job = $this->clientWrapper->getBasicClient()->getJob($jobIds[0]);
        $this->assertEquals('out.c-output-mapping-test.table1a', $job['tableId']);
        $this->assertEquals(true, $job['operationParams']['params']['incremental']);
        $this->assertEquals(['Id'], $job['operationParams']['params']['columns']);
        $data = $this->clientWrapper->getBasicClient()->getTableDataPreview('out.c-output-mapping-test.table1a');
        $job = $this->clientWrapper->getBasicClient()->getJob($jobIds[1]);
        $this->assertEquals('out.c-output-mapping-test.table2a', $job['tableId']);
        $this->assertEquals(false, $job['operationParams']['params']['incremental']);
        $this->assertEquals(['Id2', 'Name2'], $job['operationParams']['params']['columns']);

        $rows = explode("\n", trim($data));
        sort($rows);
        // convert to lowercase because of https://keboola.atlassian.net/browse/KBC-864
        $rows = array_map(
            'strtolower',
            $rows
        );
        // 1a has only the id column
        $this->assertEquals(['"id"', '"test"'], $rows);
    }
    
    public function testWriteBasicFiles()
    {
        $factory = $this->getStagingFactory(null, 'json', null, [StrategyFactory::WORKSPACE_ABS, 'abs']);
        // initialize the workspace mock
        $factory->getFileOutputStrategy(StrategyFactory::WORKSPACE_ABS);
        $blobClient = BlobRestProxy::createBlobService($this->workspaceCredentials['connectionString']);
        $blobClient->createBlockBlob($this->workspace['connection']['container'], 'upload/file1', 'test');
        $blobClient->createBlockBlob($this->workspace['connection']['container'], 'upload/file2', 'test');
        $blobClient->createBlockBlob(
            $this->workspace['connection']['container'],
            'upload/file2.manifest',
            '{"tags": ["output-mapping-test", "xxx"],"is_public": false}'
        );
        $blobClient->createBlockBlob(
            $this->workspace['connection']['container'],
            'upload/file3',
            'test'
        );
        $blobClient->createBlockBlob(
            $this->workspace['connection']['container'],
            'upload/file3.manifest',
            '{"tags": ["output-mapping-test"],"is_permanent": true}'
        );
        $configs = [
            [
                'source' => 'file1',
                'tags' => ['output-mapping-test']
            ],
            [
                'source' => 'file2',
                'tags' => ['output-mapping-test', 'another-tag'],
                'is_permanent' => true
            ]
        ];

        $writer = new FileWriter($factory);
        $writer->uploadFiles('/upload', ['mapping' => $configs], StrategyFactory::WORKSPACE_ABS);
        sleep(1);

        $options = new ListFilesOptions();
        $options->setTags(['output-mapping-test']);
        $files = $this->clientWrapper->getBasicClient()->listFiles($options);
        $this->assertCount(3, $files);

        $file1 = $file2 = $file3 = null;
        foreach ($files as $file) {
            if ($file['name'] == 'file1') {
                $file1 = $file;
            }
            if ($file['name'] == 'file2') {
                $file2 = $file;
            }
            if ($file['name'] == 'file3') {
                $file3 = $file;
            }
        }

        $this->assertNotNull($file1);
        $this->assertNotNull($file2);
        $this->assertNotNull($file3);
        $this->assertEquals(4, $file1['sizeBytes']);
        $this->assertEquals(['output-mapping-test'], $file1['tags']);
        $this->assertEquals(['output-mapping-test', 'another-tag'], $file2['tags']);
        $this->assertEquals(['output-mapping-test'], $file3['tags']);
        $this->assertNotNull($file1['maxAgeDays']);
        $this->assertNull($file2['maxAgeDays']);
        $this->assertNull($file3['maxAgeDays']);
    }
}
