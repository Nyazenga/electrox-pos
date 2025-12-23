<?php
/**
 * Swagger/OpenAPI Documentation Endpoint
 * 
 * Access Swagger UI at: http://localhost/electrox-pos/api/swagger-ui.php
 * Access JSON spec at: http://localhost/electrox-pos/api/swagger.json
 */

// Suppress errors
error_reporting(0);
ini_set('display_errors', 0);

// Output as JSON
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

// Read and output the static JSON file
$jsonFile = __DIR__ . '/swagger.json';
if (file_exists($jsonFile)) {
    $json = file_get_contents($jsonFile);
    // Remove any BOM or whitespace
    $json = trim($json);
    // Validate JSON
    $decoded = json_decode($json, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        echo $json;
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Invalid JSON in swagger.json']);
    }
} else {
    http_response_code(404);
    echo json_encode(['error' => 'swagger.json not found']);
}

