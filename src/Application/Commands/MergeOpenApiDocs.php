<?php

namespace MatchBot\Application\Commands;

use OpenApi\Generator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Yaml;

/**
 * Merges the manually written OpenAPI documentation in api.yaml
 * with the documentation generated from PHP annotations.
 */
#[AsCommand(
    name: 'matchbot:merge-openapi-docs',
    description: 'Merges manually written API docs with annotation-generated docs',
)]
class MergeOpenApiDocs extends Command
{
    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('OpenAPI Documentation Merger');

        try {
            // Step 1: Generate OpenAPI documentation from PHP annotations
            $io->section('Generating OpenAPI documentation from PHP annotations');
            $annotationsOpenApi = Generator::scan([__DIR__ . '/../../../src']);

            // Convert to array for easier manipulation
            $annotationsArray = json_decode($annotationsOpenApi->toJson(), true);

            // Step 2: Load the manually written API documentation
            $io->section('Loading manually written API documentation from api.yaml');
            $manualApiPath = __DIR__ . '/../../../api.yaml';
            $manualArray = Yaml::parseFile($manualApiPath);

            // Step 3: Merge the two documentation sources
            $io->section('Merging documentation sources');
            $mergedArray = $this->mergeOpenApiArrays($manualArray, $annotationsArray);

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
     */
    private function mergeOpenApiArrays(array $manual, array $annotations): array
    {
        $result = $manual;

        // Special handling for paths
        if (isset($annotations['paths']) && isset($result['paths'])) {
            foreach ($annotations['paths'] as $path => $pathData) {
                if (!isset($result['paths'][$path])) {
                    $result['paths'][$path] = $pathData;
                } else {
                    // Merge methods (GET, POST, etc.)
                    foreach ($pathData as $method => $methodData) {
                        if (!isset($result['paths'][$path][$method])) {
                            $result['paths'][$path][$method] = $methodData;
                        }
                    }
                }
            }
        }

        // Special handling for components/schemas
        if (isset($annotations['components']['schemas']) && isset($result['components']['schemas'])) {
            foreach ($annotations['components']['schemas'] as $schema => $schemaData) {
                if (!isset($result['components']['schemas'][$schema])) {
                    $result['components']['schemas'][$schema] = $schemaData;
                }
            }
        } elseif (isset($annotations['components']['schemas']) && !isset($result['components']['schemas'])) {
            $result['components']['schemas'] = $annotations['components']['schemas'];
        }

        // Handle other components sections
        $componentSections = ['responses', 'parameters', 'examples', 'requestBodies', 'headers', 'securitySchemes', 'links', 'callbacks'];
        foreach ($componentSections as $section) {
            if (isset($annotations['components'][$section])) {
                if (!isset($result['components'][$section])) {
                    $result['components'][$section] = $annotations['components'][$section];
                } else {
                    $result['components'][$section] = array_merge($result['components'][$section], $annotations['components'][$section]);
                }
            }
        }

        // Handle tags
        if (isset($annotations['tags'])) {
            if (!isset($result['tags'])) {
                $result['tags'] = $annotations['tags'];
            } else {
                // Merge tags by name
                $existingTagNames = array_column($result['tags'], 'name');
                foreach ($annotations['tags'] as $tag) {
                    if (!in_array($tag['name'], $existingTagNames)) {
                        $result['tags'][] = $tag;
                    }
                }
            }
        }

        return $result;
    }
}
