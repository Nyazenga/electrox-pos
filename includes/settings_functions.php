<?php
// Prevent direct access
if (!defined('APP_PATH')) {
    exit('No direct script access allowed');
}

// Note: getSetting() and setSetting() are defined in functions.php
// This file only contains tax calculation functions
// Do not declare getSetting() or setSetting() here to avoid redeclaration errors

/**
 * Calculate price with tax included
 * @param float $price Price without tax
 * @param float $taxRate Tax rate as percentage (e.g., 15.5 for 15.5%)
 * @return float Price with tax included
 */
function calculatePriceWithTax($price, $taxRate = 15.5) {
    if ($taxRate <= 0) {
        return $price;
    }
    $taxDecimal = $taxRate / 100;
    return $price * (1 + $taxDecimal);
}

/**
 * Calculate price without tax from tax-inclusive price
 * @param float $priceWithTax Price with tax included
 * @param float $taxRate Tax rate as percentage (e.g., 15.5 for 15.5%)
 * @return float Price without tax
 */
function calculatePriceWithoutTax($priceWithTax, $taxRate = 15.5) {
    if ($taxRate <= 0) {
        return $priceWithTax;
    }
    $taxDecimal = $taxRate / 100;
    return $priceWithTax / (1 + $taxDecimal);
}

/**
 * Calculate tax amount from tax-inclusive price
 * @param float $priceWithTax Price with tax included
 * @param float $taxRate Tax rate as percentage (e.g., 15.5 for 15.5%)
 * @return float Tax amount
 */
function calculateTaxFromInclusivePrice($priceWithTax, $taxRate = 15.5) {
    if ($taxRate <= 0) {
        return 0;
    }
    $taxDecimal = $taxRate / 100;
    return $priceWithTax * ($taxDecimal / (1 + $taxDecimal));
}

/**
 * Get default tax rate from settings or fiscal config
 * @return float Default tax rate as percentage
 */
function getDefaultTaxRate() {
    // getSetting() is defined in functions.php, which should be included before this file
    if (!function_exists('getSetting')) {
        return 15.5; // Default fallback
    }
    $taxRate = getSetting('default_tax_rate', '15.5');
    return floatval($taxRate);
}
