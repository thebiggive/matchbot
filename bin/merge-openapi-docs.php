<?php

/**
 * Merge OpenAPI Documentation Script
 *
 * This script merges the manually written OpenAPI documentation in api.yaml
 * with the documentation generated from PHP annotations.
 *
 * Usage:
 * php bin/merge-openapi-docs.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use OpenApi\Generator;
use Symfony\Component\Yaml\Yaml;

// Set up logging
$logger = new \Monolog\Logger('openapi-merger');
$logger->pushHandler(new \Monolog\Handler\StreamHandler('php://stdout', \Monolog\Logger::INFO));

$logger->info('Starting OpenAPI documentation merge process');

// Step 1: Generate OpenAPI documentation from PHP annotations
$logger->info('Generating OpenAPI documentation from PHP annotations');
$annotationsOpenApi = Generator::scan([__DIR__ . '/../src']);

// Convert to array for easier manipulation
$annotationsArray = json_decode($annotationsOpenApi->toJson(), true);

// Step 2: Load the manually written API documentation
$logger->info('Loading manually written API documentation from api.yaml');
try {
    $manualApiPath = __DIR__ . '/../api.yaml';
    $manualArray = Yaml::parseFile($manualApiPath);
} catch (\Exception $e) {
    $logger->error('Failed to parse api.yaml: ' . $e->getMessage());
    exit(1);
}

// Step 3: Merge the two documentation sources
$logger->info('Merging documentation sources');

// Function to recursively merge arrays with special handling for OpenAPI structures
function mergeOpenApiArrays(array $manual, array $annotations): array
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

// Perform the merge
$mergedArray = mergeOpenApiArrays($manualArray, $annotationsArray);

// Step 4: Save the merged documentation
$logger->info('Saving merged documentation to docs/openapi.yaml');
try {
    $outputDir = __DIR__ . '/../docs';
    if (!is_dir($outputDir)) {
        mkdir($outputDir, 0755, true);
    }

    $outputPath = $outputDir . '/openapi.yaml';
    file_put_contents($outputPath, Yaml::dump($mergedArray, 10, 2));
    $logger->info('Merged documentation saved successfully to ' . $outputPath);
} catch (\Exception $e) {
    $logger->error('Failed to save merged documentation: ' . $e->getMessage());
    exit(1);
}

$logger->info('OpenAPI documentation merge process completed successfully');
