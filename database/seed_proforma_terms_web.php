<?php
// Web-accessible version of seed script
require_once dirname(__FILE__) . '/../config.php';
require_once APP_PATH . '/includes/db.php';
require_once APP_PATH . '/includes/settings_functions.php';

header('Content-Type: text/plain');

echo "=== Seeding Proforma Terms to " . PRIMARY_DB_NAME . " ===\n\n";

// Use PRIMARY database (electrox_primary) for seeding terms
// This ensures we're seeding to the correct database, not electrox_base
$db = Database::getPrimaryInstance();

echo "Connected to database: " . PRIMARY_DB_NAME . "\n\n";

// Get company name from settings
$companyName = 'ELECTROX';
try {
    $setting = $db->getRow("SELECT value FROM settings WHERE setting_key = 'company_name' LIMIT 1");
    if ($setting && !empty($setting['value'])) {
        $companyName = $setting['value'];
    }
} catch (Exception $e) {
    // Use default if settings table doesn't exist or query fails
}

$terms = [
    [
        'title' => 'Standard Payment Terms',
        'content' => 'Payment is due within 30 days of invoice date. Late payments may incur interest charges at 2% per month. Goods remain the property of ' . $companyName . ' until full payment is received. All prices are in USD unless otherwise stated.'
    ],
    [
        'title' => 'Cash on Delivery Terms',
        'content' => 'Payment must be made in full upon delivery. No credit terms apply. Cash or bank transfer accepted. Goods will not be released until payment is confirmed. All sales are final once payment is received.'
    ],
    [
        'title' => 'Net 15 Payment Terms',
        'content' => 'Payment is due within 15 days of invoice date. Early payment discounts may apply if paid within 7 days. Returned goods must be in original condition and packaging. Warranty terms apply as per manufacturer specifications.'
    ],
    [
        'title' => 'Proforma Invoice Terms',
        'content' => 'This is a proforma invoice and does not constitute a demand for payment. Goods will be dispatched upon receipt of payment. Prices are valid for 30 days from invoice date. All products are subject to availability. ' . $companyName . ' reserves the right to cancel orders if payment is not received within the specified timeframe.'
    ],
    [
        'title' => 'Trade Terms & Conditions',
        'content' => 'Payment terms: Net 30 days. Credit limit applies based on account status. Returns accepted within 14 days in original packaging. Defective items will be replaced or refunded at our discretion. No warranty for damage caused by misuse, lightning, or power surges. All prices exclude delivery unless stated otherwise.'
    ]
];

$added = 0;
$skipped = 0;

foreach ($terms as $term) {
    // Check if term already exists
    $exists = $db->getRow("SELECT id FROM proforma_terms WHERE title = :title", [':title' => $term['title']]);
    
    if (!$exists) {
        try {
            $result = $db->insert('proforma_terms', [
                'title' => $term['title'],
                'content' => $term['content'],
                'is_active' => 1
            ]);
            if ($result) {
                echo "✓ Added: " . $term['title'] . "\n";
                $added++;
            } else {
                echo "✗ Error adding " . $term['title'] . ": Insert failed\n";
            }
        } catch (Exception $e) {
            echo "✗ Error adding " . $term['title'] . ": " . $e->getMessage() . "\n";
        }
    } else {
        echo "- Skipped (already exists): " . $term['title'] . "\n";
        $skipped++;
    }
}

echo "\n=== Seeding Summary ===\n";
echo "Added: $added\n";
echo "Skipped: $skipped\n";
echo "Total: " . count($terms) . "\n";
echo "\nSeeding completed!\n";
