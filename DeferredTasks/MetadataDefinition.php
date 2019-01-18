<?php

namespace Keboola\OutputMapping\DeferredTasks;

use Keboola\StorageApi\Client;
use Keboola\StorageApi\Metadata;

class MetadataDefinition
{
    const COLUMN_METADATA = 'column';
    const TABLE_METADATA = 'table';
    /**
     * @var string
     */
    private $destination;

    /**
     * @var string
     */
    private $provider;

    /**
     * @var array
     */
    private $metadata;

    /**
     * @var string
     */
    private $type;

    /**
     * @var Client
     */
    private $client;

    public function __construct(Client $client, $destination, $provider, $metadata, $type)
    {
        $this->client = $client;
        $this->destination = $destination;
        $this->provider = $provider;
        $this->metadata = $metadata;
        $this->type = $type;
    }

    public function set()
    {
        if ($this->type === self::COLUMN_METADATA) {
            $metadataClient = new Metadata($this->client);
            foreach ($this->metadata as $column => $metadataArray) {
                $columnId = $this->destination . "." . $column;
                $metadataClient->postColumnMetadata($columnId, $this->provider, $metadataArray);
            }
        } elseif ($this->type === self::TABLE_METADATA) {
            $metadataClient = new Metadata($this->client);
            return $metadataClient->postTableMetadata($this->destination, $this->provider, $this->metadata);
        } else {
            throw new \LogicException('Invalid type: ' . $this->type);
        }
    }
}
