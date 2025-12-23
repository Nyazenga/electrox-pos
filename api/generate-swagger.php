<?php
/**
 * Generate Swagger/OpenAPI JSON file
 * Run: php api/generate-swagger.php
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

use OpenApi\Generator;

echo "Generating Swagger/OpenAPI specification...\n";

try {
    $openapi = Generator::scan([
        __DIR__ . '/v1',
        __DIR__ . '/index.php'
    ]);
    
    $json = $openapi->toJson();
    
    // Save to file
    file_put_contents(__DIR__ . '/swagger.json', $json);
    
    echo "✓ Swagger JSON generated successfully!\n";
    echo "  File: " . __DIR__ . "/swagger.json\n";
    echo "  Access Swagger UI: http://localhost/electrox-pos/api/swagger-ui.php\n";
    
} catch (Exception $e) {
    echo "✗ Error generating Swagger: " . $e->getMessage() . "\n";
    exit(1);
}

