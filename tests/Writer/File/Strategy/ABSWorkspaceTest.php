<?php

declare(strict_types=1);

namespace Keboola\OutputMapping\Tests\Writer\File\Strategy;

use Keboola\FileStorage\Abs\ClientFactory;
use Keboola\InputMapping\Staging\NullProvider;
use Keboola\InputMapping\Staging\ProviderInterface;
use Keboola\OutputMapping\Exception\InvalidOutputException;
use Keboola\OutputMapping\Exception\OutputOperationException;
use Keboola\OutputMapping\Tests\AbstractTestCase;
use Keboola\OutputMapping\Tests\InitSynapseStorageClientTrait;
use Keboola\OutputMapping\Writer\File\Strategy\ABSWorkspace;
use Keboola\StorageApi\Workspaces;
use Monolog\Logger;
use stdClass;
use Symfony\Component\Yaml\Yaml;

class ABSWorkspaceTest extends AbstractTestCase
{
    use InitSynapseStorageClientTrait;

    protected function initClient(?string $branchId = ''): void
    {
        $this->clientWrapper = $this->getSynapseClientWrapper();
    }

    private function getProvider(array $data = []): ProviderInterface
    {
        $mock = self::getMockBuilder(NullProvider::class)
            ->setMethods(['getWorkspaceId', 'getCredentials'])
            ->getMock();
        $mock->method('getWorkspaceId')->willReturnCallback(
            function () use ($data): string {
                if (!$this->workspaceId) {
                    $workspaces = new Workspaces($this->clientWrapper->getBranchClient());
                    $workspace = $workspaces->createWorkspace(['backend' => 'abs'], true);
                    $this->workspaceId = (string) $workspace['id'];
                    $this->workspace = $data ?: $workspace;
                }
                return $this->workspaceId;
            },
        );
        $mock->method('getCredentials')->willReturnCallback(
            function () use ($data): array {
                if (!$this->workspaceId) {
                    $workspaces = new Workspaces($this->clientWrapper->getBranchClient());
                    $workspace = $workspaces->createWorkspace(['backend' => 'abs'], true);
                    $this->workspaceId = (string) $workspace['id'];
                    $this->workspace = $data ?: $workspace;
                }
                return $this->workspace['connection'];
            },
        );
        /** @var ProviderInterface $mock */
        return $mock;
    }

    public function testCreateStrategyInvalidWorkspace(): void
    {
        self::expectException(OutputOperationException::class);
        self::expectExceptionMessage('Invalid credentials received: foo, bar');
        new ABSWorkspace(
            $this->clientWrapper,
            new Logger('testLogger'),
            $this->getProvider(['connection' => ['foo' => 'bar', 'bar' => 'Kochba']]),
            $this->getProvider(['connection' => ['foo' => 'bar', 'bar' => 'Kochba']]),
            'json',
        );
    }

    public function testListFilesNoFiles(): void
    {
        $strategy = new ABSWorkspace(
            $this->clientWrapper,
            new Logger('testLogger'),
            $this->getProvider(),
            $this->getProvider(),
            'json',
        );
        $files = $strategy->listFiles('data/out/files');
        self::assertSame([], $files);
    }

    public function testListFilesWorkspaceDropped(): void
    {
        $strategy = new ABSWorkspace(
            $this->clientWrapper,
            new Logger('testLogger'),
            $this->getProvider(),
            $this->getProvider(),
            'json',
        );
        $workspaces = new Workspaces($this->clientWrapper->getBranchClient());
        $workspaces->deleteWorkspace($this->workspace['id'], [], true);
        self::expectException(InvalidOutputException::class);
        self::expectExceptionMessage('Failed to list files: "The specified container does not exist.".');
        $strategy->listFiles('data/out/files');
    }

    public function testListFiles(): void
    {
        $strategy = new ABSWorkspace(
            $this->clientWrapper,
            new Logger('testLogger'),
            $this->getProvider(),
            $this->getProvider(),
            'json',
        );
        $blobClient = ClientFactory::createClientFromConnectionString(
            $this->workspace['connection']['connectionString'],
        );
        $blobClient->createAppendBlob(
            $this->workspace['connection']['container'],
            'data/out/files/my-file',
        );
        $blobClient->createAppendBlob(
            $this->workspace['connection']['container'],
            'data/out/files/my-file.manifest',
        );
        $blobClient->createAppendBlob(
            $this->workspace['connection']['container'],
            'data/out/files/my-second-file',
        );
        $blobClient->createAppendBlob(
            $this->workspace['connection']['container'],
            'data/out/files/my-second-file.manifest',
        );
        $blobClient->createAppendBlob(
            $this->workspace['connection']['container'],
            'data/out/tables/my-other-file',
        );
        $files = $strategy->listFiles('data/out/files');
        $fileNames = [];
        foreach ($files as $file) {
            $fileNames[$file->getPathName()] = $file->getPath();
        }
        self::assertEquals(['data/out/files/my-file', 'data/out/files/my-second-file'], array_keys($fileNames));
        self::assertStringEndsWith('data/out/files', $fileNames['data/out/files/my-file']);
    }

    public function testListFilesMaxItems(): void
    {
        $strategy = new ABSWorkspace(
            $this->clientWrapper,
            new Logger('testLogger'),
            $this->getProvider(),
            $this->getProvider(),
            'json',
        );
        $blobClient = ClientFactory::createClientFromConnectionString(
            $this->workspace['connection']['connectionString'],
        );
        for ($i = 0; $i < 1000; $i++) {
            $blobClient->createAppendBlob($this->workspace['connection']['container'], 'data/out/files/my-file' . $i);
        }
        self::expectException(OutputOperationException::class);
        self::expectExceptionMessage('Maximum number of files in workspace reached.');
        $strategy->listFiles('data/out/files');
    }

    public function testListManifestsWorkspaceDropped(): void
    {
        $strategy = new ABSWorkspace(
            $this->clientWrapper,
            new Logger('testLogger'),
            $this->getProvider(),
            $this->getProvider(),
            'json',
        );
        $workspaces = new Workspaces($this->clientWrapper->getBranchClient());
        $workspaces->deleteWorkspace($this->workspace['id'], [], true);
        self::expectException(InvalidOutputException::class);
        self::expectExceptionMessage('Failed to list files: "The specified container does not exist.".');
        $strategy->listManifests('data/out/files');
    }

    public function testListManifests(): void
    {
        $strategy = new ABSWorkspace(
            $this->clientWrapper,
            new Logger('testLogger'),
            $this->getProvider(),
            $this->getProvider(),
            'json',
        );
        $blobClient = ClientFactory::createClientFromConnectionString(
            $this->workspace['connection']['connectionString'],
        );
        $blobClient->createAppendBlob(
            $this->workspace['connection']['container'],
            'data/out/files/my-file',
        );
        $blobClient->createAppendBlob(
            $this->workspace['connection']['container'],
            'data/out/files/my-file.manifest',
        );
        $blobClient->createAppendBlob(
            $this->workspace['connection']['container'],
            'data/out/files/my-second-file',
        );
        $blobClient->createAppendBlob(
            $this->workspace['connection']['container'],
            'data/out/files/my-second-file.manifest',
        );
        $blobClient->createAppendBlob(
            $this->workspace['connection']['container'],
            'data/out/tables/my-other-file',
        );
        $files = $strategy->listManifests('data/out/files');
        $fileNames = [];
        foreach ($files as $file) {
            $fileNames[$file->getPathName()] = $file->getPath();
        }
        self::assertEquals(
            ['data/out/files/my-file.manifest', 'data/out/files/my-second-file.manifest'],
            array_keys($fileNames),
        );
        self::assertStringEndsWith('data/out/files', $fileNames['data/out/files/my-file.manifest']);
    }

    public function testLoadFileToStorageEmptyConfig(): void
    {
        $strategy = new ABSWorkspace(
            $this->clientWrapper,
            new Logger('testLogger'),
            $this->getProvider(),
            $this->getProvider(),
            'json',
        );
        $blobClient = ClientFactory::createClientFromConnectionString(
            $this->workspace['connection']['connectionString'],
        );
        $blobClient->createBlockBlob(
            $this->workspace['connection']['container'],
            'data/out/files/my-file_one',
            'my-data',
        );
        $blobClient->createBlockBlob(
            $this->workspace['connection']['container'],
            'data/out/files/my-file_one.manifest',
            'manifest',
        );
        $fileId = $strategy->loadFileToStorage('data/out/files/my-file_one', []);
        $this->clientWrapper->getTableAndFileStorageClient()->getFile($fileId);
        $destination = $this->temp->getTmpFolder() . 'destination';
        $this->clientWrapper->getTableAndFileStorageClient()->downloadFile($fileId, $destination);
        $contents = (string) file_get_contents($destination);
        self::assertEquals('my-data', $contents);

        $file = $this->clientWrapper->getTableAndFileStorageClient()->getFile($fileId);
        self::assertEquals($fileId, $file['id']);
        self::assertEquals('my_file_one', $file['name']);
        self::assertEquals([], $file['tags']);
        self::assertFalse($file['isPublic']);
        self::assertTrue($file['isEncrypted']);
        self::assertEquals(15, $file['maxAgeDays']);
    }

    public function testLoadFileToStorageFullConfig(): void
    {
        $strategy = new ABSWorkspace(
            $this->clientWrapper,
            new Logger('testLogger'),
            $this->getProvider(),
            $this->getProvider(),
            'json',
        );
        $blobClient = ClientFactory::createClientFromConnectionString(
            $this->workspace['connection']['connectionString'],
        );
        $blobClient->createBlockBlob(
            $this->workspace['connection']['container'],
            'data/out/files/my-file_one',
            'my-data',
        );
        $blobClient->createBlockBlob(
            $this->workspace['connection']['container'],
            'data/out/files/my-file_one.manifest',
            'manifest',
        );
        $fileId = $strategy->loadFileToStorage(
            'data/out/files/my-file_one',
            [
                'notify' => false,
                'tags' => ['first-tag', 'second-tag'],
                'is_public' => true,
                'is_permanent' => true,
                'is_encrypted' => true,
            ],
        );
        $this->clientWrapper->getTableAndFileStorageClient()->getFile($fileId);
        $destination = $this->temp->getTmpFolder() . 'destination';
        $this->clientWrapper->getTableAndFileStorageClient()->downloadFile($fileId, $destination);
        $contents = (string) file_get_contents($destination);
        self::assertEquals('my-data', $contents);

        $file = $this->clientWrapper->getTableAndFileStorageClient()->getFile($fileId);
        self::assertEquals($fileId, $file['id']);
        self::assertEquals('my_file_one', $file['name']);
        self::assertEquals(['first-tag', 'second-tag'], $file['tags']);
        self::assertFalse($file['isPublic']);
        self::assertTrue($file['isEncrypted']);
        self::assertNull($file['maxAgeDays']);
    }

    public function testLoadFileToStorageFileDoesNotExist(): void
    {
        $strategy = new ABSWorkspace(
            $this->clientWrapper,
            new Logger('testLogger'),
            $this->getProvider(),
            $this->getProvider(),
            'json',
        );
        self::expectException(InvalidOutputException::class);
        self::expectExceptionMessage(
            'File "data/out/files/my-file_one" does not exist in container "' .
            $this->workspace['connection']['container'] . '".',
        );
        $strategy->loadFileToStorage('data/out/files/my-file_one', []);
    }

    public function testLoadFileToStorageFileNameEmpty(): void
    {
        $strategy = new ABSWorkspace(
            $this->clientWrapper,
            new Logger('testLogger'),
            $this->getProvider(),
            $this->getProvider(),
            'json',
        );
        self::expectException(InvalidOutputException::class);
        self::expectExceptionMessage('File "\'\'" is empty.');
        $strategy->loadFileToStorage('', []);
    }

    public function testReadFileManifestFull(): void
    {
        $strategy = new ABSWorkspace(
            $this->clientWrapper,
            new Logger('testLogger'),
            $this->getProvider(),
            $this->getProvider(),
            'json',
        );
        $blobClient = ClientFactory::createClientFromConnectionString(
            $this->workspace['connection']['connectionString'],
        );
        $blobClient->createBlockBlob(
            $this->workspace['connection']['container'],
            'data/out/files/my-file_one',
            'my-data',
        );
        $sourceData = [
            'is_public' => true,
            'is_permanent' => true,
            'is_encrypted' => true,
            'notify' => false,
            'tags' => [
                'my-first-tag',
                'second-tag',
            ],
        ];
        $blobClient->createBlockBlob(
            $this->workspace['connection']['container'],
            'data/out/files/my-file_one.manifest',
            (string) json_encode($sourceData),
        );
        $manifestData = $strategy->readFileManifest('data/out/files/my-file_one.manifest');
        self::assertEquals(
            $sourceData,
            $manifestData,
        );
    }

    public function testReadFileManifestFullYaml(): void
    {
        $strategy = new ABSWorkspace(
            $this->clientWrapper,
            new Logger('testLogger'),
            $this->getProvider(),
            $this->getProvider(),
            'yaml',
        );
        $blobClient = ClientFactory::createClientFromConnectionString(
            $this->workspace['connection']['connectionString'],
        );
        $blobClient->createBlockBlob(
            $this->workspace['connection']['container'],
            'data/out/files/my-file_one',
            'my-data',
        );
        $sourceData = [
            'is_public' => true,
            'is_permanent' => true,
            'is_encrypted' => true,
            'notify' => false,
            'tags' => [
                'my-first-tag',
                'second-tag',
            ],
        ];
        $blobClient->createBlockBlob(
            $this->workspace['connection']['container'],
            'data/out/files/my-file_one.manifest',
            Yaml::dump($sourceData),
        );
        $manifestData = $strategy->readFileManifest('data/out/files/my-file_one.manifest');
        self::assertEquals(
            $sourceData,
            $manifestData,
        );
    }

    public function testReadFileManifestEmpty(): void
    {
        $strategy = new ABSWorkspace(
            $this->clientWrapper,
            new Logger('testLogger'),
            $this->getProvider(),
            $this->getProvider(),
            'json',
        );
        $blobClient = ClientFactory::createClientFromConnectionString(
            $this->workspace['connection']['connectionString'],
        );
        $blobClient->createBlockBlob(
            $this->workspace['connection']['container'],
            'data/out/files/my-file_one',
            'my-data',
        );
        $expectedData = [
            'is_public' => false,
            'is_permanent' => false,
            'is_encrypted' => true,
            'notify' => false,
            'tags' => [],
        ];
        $blobClient->createBlockBlob(
            $this->workspace['connection']['container'],
            'data/out/files/my-file_one.manifest',
            (string) json_encode(new stdClass()),
        );
        $manifestData = $strategy->readFileManifest('data/out/files/my-file_one.manifest');
        self::assertEquals(
            $expectedData,
            $manifestData,
        );
    }

    public function testReadFileManifestNotExists(): void
    {
        $strategy = new ABSWorkspace(
            $this->clientWrapper,
            new Logger('testLogger'),
            $this->getProvider(),
            $this->getProvider(),
            'json',
        );
        self::expectException(InvalidOutputException::class);
        self::expectExceptionMessage(
            'Failed to read manifest "data/out/files/my-file_one.manifest": "The specified blob does not exist.',
        );
        $strategy->readFileManifest('data/out/files/my-file_one.manifest');
    }

    public function testReadFileManifestInvalid(): void
    {
        $strategy = new ABSWorkspace(
            $this->clientWrapper,
            new Logger('testLogger'),
            $this->getProvider(),
            $this->getProvider(),
            'json',
        );
        $blobClient = ClientFactory::createClientFromConnectionString(
            $this->workspace['connection']['connectionString'],
        );
        $blobClient->createBlockBlob(
            $this->workspace['connection']['container'],
            'data/out/files/my-file_one.manifest',
            'not a valid json',
        );
        self::expectException(InvalidOutputException::class);
        self::expectExceptionMessage(
            'Failed to parse manifest file "data/out/files/my-file_one.manifest" as "json": Syntax error',
        );
        $strategy->readFileManifest('data/out/files/my-file_one.manifest');
    }
}
