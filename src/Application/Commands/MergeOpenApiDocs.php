<?php

namespace MatchBot\Application\Commands;

use OpenApi\Annotations\OpenApi;
use OpenApi\Generator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Yaml;

/**
 * Merges the manually written OpenAPI documentation in api.yaml
 * with the documentation generated from PHP attributes.
 */
#[AsCommand(
    name: 'matchbot:merge-openapi-docs',
    description: 'Merges manually written API docs with attribute-generated docs',
)]
class MergeOpenApiDocs extends Command
{
    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('OpenAPI Documentation Merger');

        try {
            // Step 1: Generate OpenAPI documentation from PHP attributes
            $io->section('Generating OpenAPI documentation from PHP attributes');
            $generator = new Generator();
            $attributtesOpenApi = $generator->generate([__DIR__ . '/../../../src']);

            if ($attributtesOpenApi === null) {
                throw new \RuntimeException('Failed to generate OpenAPI documentation from attritributes');
            }

            // Convert to array for easier manipulation
            $attritributesJson = $attributtesOpenApi->toJson();
            $attritributesArray = json_decode($attritributesJson, true);
            if (!is_array($attritributesArray)) {
                throw new \RuntimeException('Failed to decode OpenAPI JSON to array');
            }

            // Step 2: Load the manually written API documentation
            $io->section('Loading manually written API documentation from api.yaml');
            $manualApiPath = __DIR__ . '/../../../api.yaml';
            $manualArray = Yaml::parseFile($manualApiPath);
            if (!is_array($manualArray)) {
                throw new \RuntimeException('Failed to parse api.yaml file');
            }

            // Step 3: Merge the two documentation sources
            $io->section('Merging documentation sources');
            $mergedArray = $this->mergeOpenApiArrays($manualArray, $attritributesArray);

            // Step 4: Save the merged documentation
            $io->section('Saving merged documentation');
            $outputDir = __DIR__ . '/../../../docs';
            if (!is_dir($outputDir)) {
                mkdir($outputDir, 0755, true);
            }

            $outputPath = $outputDir . '/openapi.yaml';
            file_put_contents($outputPath, Yaml::dump($mergedArray, 10, 2));
            $io->success('Merged documentation saved successfully to ' . $outputPath);

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error('Failed to merge OpenAPI documentation: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * Recursively merge arrays with special handling for OpenAPI structures
     *
     * @psalm-suppress MixedAssignment
     * @psalm-suppress MixedArrayAssignment
     *
     * @param array<array-key, mixed> $manual The manually written API documentation
     * @param array<array-key, mixed> $attritributes The documentation generated from attritributes
     * @return array<array-key, mixed> The merged documentation
     */
    private function mergeOpenApiArrays(array $manual, array $attritributes): array
    {
        $result = $manual;

        // Special handling for paths
        if (isset($attritributes['paths']) && is_array($attritributes['paths'])) {
            if (!isset($result['paths']) || !is_array($result['paths'])) {
                $result['paths'] = [];
            }

            foreach ($attritributes['paths'] as $path => $pathData) {
                if (!is_string($path) || !is_array($pathData)) {
                    continue;
                }

                if (!isset($result['paths'][$path])) {
                    $result['paths'][$path] = $pathData;
                } elseif (is_array($result['paths'][$path])) {
                    // Merge methods (GET, POST, etc.)
                    foreach ($pathData as $method => $methodData) {
                        if (!is_string($method) || !is_array($methodData)) {
                            continue;
                        }

                        if (!isset($result['paths'][$path][$method])) {
                            $result['paths'][$path][$method] = $methodData;
                        }
                    }
                }
            }
        }

        // Special handling for components/schemas
        if (isset($attritributes['components']['schemas']) && is_array($attritributes['components']['schemas'])) {
            if (!isset($result['components'])) {
                $result['components'] = [];
            }

            if (!isset($result['components']['schemas']) || !is_array($result['components']['schemas'])) {
                $result['components']['schemas'] = [];
            }

            foreach ($attritributes['components']['schemas'] as $schema => $schemaData) {
                if (!is_string($schema) || !is_array($schemaData)) {
                    continue;
                }

                if (!isset($result['components']['schemas'][$schema])) {
                    $result['components']['schemas'][$schema] = $schemaData;
                }
            }
        }

        // Handle other components sections
        $componentSections = ['responses', 'parameters', 'examples', 'requestBodies', 'headers', 'securitySchemes', 'links', 'callbacks'];
        if (isset($attritributes['components']) && is_array($attritributes['components'])) {
            if (!isset($result['components']) || !is_array($result['components'])) {
                $result['components'] = [];
            }

            foreach ($componentSections as $section) {
                if (isset($attritributes['components'][$section]) && is_array($attritributes['components'][$section])) {
                    if (!isset($result['components'][$section]) || !is_array($result['components'][$section])) {
                        $result['components'][$section] = $attritributes['components'][$section];
                    } else {
                        $result['components'][$section] = array_merge(
                            $result['components'][$section],
                            $attritributes['components'][$section]
                        );
                    }
                }
            }
        }

        // Handle tags
        if (isset($attritributes['tags']) && is_array($attritributes['tags'])) {
            if (!isset($result['tags']) || !is_array($result['tags'])) {
                $result['tags'] = $attritributes['tags'];
            } else {
                // Merge tags by name
                $existingTagNames = array_column($result['tags'], 'name');

                foreach ($attritributes['tags'] as $tag) {
                    if (!is_array($tag) || !isset($tag['name']) || !is_string($tag['name'])) {
                        continue;
                    }

                    if (!in_array($tag['name'], $existingTagNames, true)) {
                        $result['tags'][] = $tag;
                    }
                }
            }
        }

        return $result;
    }
}
