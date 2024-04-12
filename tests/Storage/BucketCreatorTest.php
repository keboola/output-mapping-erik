<?php

declare(strict_types=1);

namespace Keboola\OutputMapping\Tests\Storage;

use Keboola\OutputMapping\Exception\InvalidOutputException;
use Keboola\OutputMapping\Storage\BucketCreator;
use Keboola\OutputMapping\SystemMetadata;
use Keboola\OutputMapping\Tests\AbstractTestCase;
use Keboola\OutputMapping\Tests\Writer\CreateBranchTrait;
use Keboola\OutputMapping\Writer\Table\MappingDestination;
use Keboola\StorageApiBranch\ClientWrapper;
use Keboola\StorageApiBranch\Factory\ClientOptions;

class BucketCreatorTest extends AbstractTestCase
{
    use CreateBranchTrait;

    public function setUp(): void
    {
        parent::setUp();

        if ($this->clientWrapper->getTableAndFileStorageClient()->bucketExists('in.c-unexistsBucket')) {
            $this->clientWrapper->getTableAndFileStorageClient()->dropBucket('in.c-unexistsBucket', ['force' => true]);
        }
    }

    public function testEnsureDestinationBucket(): void
    {
        $bucketCreator = new BucketCreator($this->clientWrapper);

        $destination = new MappingDestination('in.c-unexistsBucket.testTable');
        $systemMetadata = new SystemMetadata([
            'runId' => '123',
            'componentId' => 'test',
            'configurationId' => '456',
        ]);

        $bucketInfo = $bucketCreator->ensureDestinationBucket($destination, $systemMetadata);
        $this->assertEquals('in.c-unexistsBucket', $bucketInfo->id);
    }

    public function testEnsureDestinationBucketExists(): void
    {
        $bucketCreator = new BucketCreator($this->clientWrapper);

        $destination = new MappingDestination('in.c-main.testTable');
        $systemMetadata = new SystemMetadata([
            'runId' => '123',
            'componentId' => 'test',
            'configurationId' => '456',
        ]);

        $bucketCreator->ensureDestinationBucket($destination, $systemMetadata);

        // Second call - bucket already exists
        $bucketInfo = $bucketCreator->ensureDestinationBucket($destination, $systemMetadata);
        $this->assertEquals('in.c-main', $bucketInfo->id);
    }

    public function testEnsureDestinationBucketDevBranch(): void
    {
        $bucketCreator = new BucketCreator($this->clientWrapper);

        $destination = new MappingDestination('in.c-unexistsBucket.testTable');
        $systemMetadata = new SystemMetadata([
            'runId' => '123',
            'componentId' => 'test',
            'configurationId' => '456',
        ]);

        // create bucket in main branch
        $bucketCreator->ensureDestinationBucket($destination, $systemMetadata);

        $clientWrapper = new ClientWrapper(
            new ClientOptions(
                (string) getenv('STORAGE_API_URL'),
                (string) getenv('STORAGE_API_TOKEN_MASTER'),
                null,
            ),
        );
        $branchName = self::class;
        $branchId = $this->createBranch($clientWrapper, $branchName);

        sleep(1);
        // set it to use a branch
        $this->initClient($branchId);

        $bucketCreator = new BucketCreator($this->clientWrapper);

        $expectedMessage = 'Trying to create a table in the development bucket "in.c-unexistsBucket"';
        $expectedMessage .= ' on branch "Keboola\OutputMapping\Tests\Storage\BucketCreatorTest"';
        $expectedMessage .= ' (ID "' . $branchId . '"), but the bucket is not assigned to any development branch.';

        $this->expectException(InvalidOutputException::class);
        $this->expectExceptionMessage($expectedMessage);
        $bucketCreator->ensureDestinationBucket($destination, $systemMetadata);
    }
}
