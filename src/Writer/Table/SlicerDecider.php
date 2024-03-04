<?php

declare(strict_types=1);

namespace Keboola\OutputMapping\Writer\Table;

use Keboola\OutputMapping\Configuration\Table\Manifest;
use Keboola\OutputMapping\Exception\InvalidOutputException;
use Keboola\OutputMapping\Mapping\MappingFromRawConfigurationAndPhysicalDataWithManifest;
use Psr\Log\LoggerInterface;
use SplFileInfo;
use function Aws\map;

class SlicerDecider
{

    public function __construct(readonly private LoggerInterface $logger)
    {
    }

    /**
     * @param MappingFromRawConfigurationAndPhysicalDataWithManifest[] $combinedSources
     */
    public function decideSliceFiles(array $combinedSources): array
    {
        $filesForSlicing = [];
        $sourceOccurrences = [];
        foreach ($combinedSources as $combinedSource) {
            $occurrences = ($sourceOccurrences[$combinedSource->getSourceName()] ?? 0) + 1;
            $sourceOccurrences[$combinedSource->getSourceName()] = $occurrences;
        }

        foreach ($combinedSources as $combinedSource) {
            if ($sourceOccurrences[$combinedSource->getSourceName()] > 1) {
                throw new InvalidOutputException(sprintf( // TODO měl by být warning?? změna chování
                    'Source "%s" has multiple destinations set.',
                    $combinedSource->getSourceName(),
                ));
                continue;
            }

            if ($this->decideSliceFile($combinedSource)) {
                $filesForSlicing[] = $combinedSource;
            }
            
        }
        return $filesForSlicing;
    }

    private function decideSliceFile(MappingFromRawConfigurationAndPhysicalDataWithManifest $combinedSource): bool
    {
        if ($combinedSource->isSliced() && !$combinedSource->getManifest()) {
            $this->logger->warning('Sliced files without manifest are not supported.');
            return false;
        }

        $sourceFile = new SplFileInfo($combinedSource->getPathName());
        if (!$sourceFile->getSize()) {
            $this->logger->warning('Empty files cannot be sliced.');
            return false;
        }

        $mapping = $combinedSource->getConfiguration();
        if (!$mapping) {
            return true;
        }
        $hasNonDefaultDelimiter = $mapping->getDelimiter() !== Manifest::DEFAULT_DELIMITER;
        $hasNonDefaultEnclosure = $mapping->getEnclosure() !== Manifest::DEFAULT_ENCLOSURE;
        $hasColumns = $mapping->getColumns();

        if ($hasNonDefaultDelimiter || $hasNonDefaultEnclosure || $hasColumns) { // TODO měl by být warning?? změna chování
            throw new InvalidOutputException('Params "delimiter", "enclosure" or "columns" specified in mapping are not longer supported.');
        }

        return true;
    }
}