<?php

declare(strict_types=1);

namespace JeroenG\Explorer\Infrastructure\IndexManagement;

use JeroenG\Explorer\Application\Explored;
use JeroenG\Explorer\Application\IndexSettings;
use JeroenG\Explorer\Domain\IndexManagement\IndexConfiguration;
use JeroenG\Explorer\Domain\IndexManagement\IndexConfigurationInterface;
use JeroenG\Explorer\Domain\IndexManagement\IndexConfigurationNotFoundException;
use JeroenG\Explorer\Domain\IndexManagement\IndexConfigurationRepositoryInterface;
use RuntimeException;

class ElasticIndexConfigurationRepository implements IndexConfigurationRepositoryInterface
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * @return iterable<IndexConfigurationInterface>
     */
    public function getConfigurations(): iterable
    {
        foreach ($this->config as $key => $index) {
            if (is_string($index)) {
                yield $this->getIndexConfigurationByClass($index);
            } elseif (is_string($key) && is_array($index)) {
                yield $this->getIndexConfigurationByArray($key, $index);
            } else {
                $data = var_export($index, true);
                throw new RuntimeException(sprintf('Unable to create index for "%s"', $data));
            }
        }
    }

    public function findForIndex(string $index): IndexConfiguration
    {
        foreach ($this->getConfigurations() as $indexConfiguration) {
            if ($indexConfiguration->getName() === $index) {
                return $indexConfiguration;
            }
        }

        throw IndexConfigurationNotFoundException::index($index);
    }

    private function getIndexConfigurationByClass(string $index): IndexConfiguration
    {
        $class = (new $index());
        $settings = [];

        if ($class instanceof IndexSettings) {
            $settings = $class->indexSettings();
        }

        if ($class instanceof Explored) {
            $properties = $this->normalizeProperties($class->mappableAs());
            return IndexConfiguration::create($class->searchableAs(), $properties, $settings);
        }

        throw new RuntimeException(sprintf('Unable to create index %s, ensure it implements Explored', $index));
    }

    private function getIndexConfigurationByArray(string $name, array $index): IndexConfiguration
    {
        $properties = $this->normalizeProperties($index['properties'] ?? []);
        return IndexConfiguration::create($name, $properties, $index['settings'] ?? []);
    }

    private function normalizeProperties(array $mappings): array
    {
        $properties = [];

        foreach ($mappings as $field => $type) {
            $properties[$field] = $this->normalizeElasticType($type);
        }

        return $properties;
    }

    private function normalizeElasticType($type): array
    {
        if (is_string($type)) {
            return ['type' => $type];
        }

        if (is_array($type)) {
            if (!isset($type['type'])) {
                $type = [
                    'type' => 'nested',
                    'properties' => $type
                ];
            }

            if (isset($type['type']) && isset($type['properties'])) {
                return array_merge($type, [
                    'properties' => $this->normalizeProperties($type['properties']),
                ]);
            }

            return $type;
        }

        $dump = var_export($type, true);
        throw new RuntimeException('Unable to determine mapping type: ' . $dump);
    }
}
