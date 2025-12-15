<?php
/**
 * Currency Helper Functions
 */

/**
 * Get base currency
 */
function getBaseCurrency($db = null) {
    if (!$db) {
        $db = Database::getMainInstance(); // Use base database for currencies
    }
    return $db->getRow("SELECT * FROM currencies WHERE is_base = 1 AND is_active = 1");
}

/**
 * Get all active currencies
 */
function getActiveCurrencies($db = null) {
    if (!$db) {
        $db = Database::getMainInstance(); // Use base database for currencies
    }
    $currencies = $db->getRows("SELECT * FROM currencies WHERE is_active = 1 ORDER BY is_base DESC, code ASC");
    return $currencies !== false ? $currencies : [];
}

/**
 * Get currency by ID
 */
function getCurrency($currencyId, $db = null) {
    if (!$db) {
        $db = Database::getMainInstance(); // Use base database for currencies
    }
    return $db->getRow("SELECT * FROM currencies WHERE id = :id", [':id' => $currencyId]);
}

/**
 * Get currency by code
 */
function getCurrencyByCode($code, $db = null) {
    if (!$db) {
        $db = Database::getMainInstance(); // Use base database for currencies
    }
    return $db->getRow("SELECT * FROM currencies WHERE code = :code", [':code' => strtoupper($code)]);
}

/**
 * Get exchange rate from one currency to another
 */
function getExchangeRate($fromCurrencyId, $toCurrencyId, $db = null) {
    if (!$db) {
        $db = Database::getMainInstance(); // Use base database for currencies
    }
    
    if ($fromCurrencyId == $toCurrencyId) {
        return 1.0;
    }
    
    $fromCurrency = getCurrency($fromCurrencyId, $db);
    $toCurrency = getCurrency($toCurrencyId, $db);
    
    if (!$fromCurrency || !$toCurrency) {
        return 1.0;
    }
    
    // If either is base currency
    if ($fromCurrency['is_base']) {
        return floatval($toCurrency['exchange_rate']);
    }
    if ($toCurrency['is_base']) {
        return 1.0 / floatval($fromCurrency['exchange_rate']);
    }
    
    // Convert through base currency
    $baseRate = floatval($toCurrency['exchange_rate']) / floatval($fromCurrency['exchange_rate']);
    return $baseRate;
}

/**
 * Convert amount from one currency to another
 */
function convertCurrency($amount, $fromCurrencyId, $toCurrencyId, $db = null) {
    $rate = getExchangeRate($fromCurrencyId, $toCurrencyId, $db);
    return floatval($amount) * $rate;
}

/**
 * Format currency amount
 */
function formatCurrencyAmount($amount, $currencyId = null, $db = null) {
    if (!$currencyId) {
        $currency = getBaseCurrency($db);
    } else {
        $currency = getCurrency($currencyId, $db);
    }
    
    if (!$currency) {
        return number_format($amount, 2);
    }
    
    $formatted = number_format($amount, $currency['decimal_places']);
    
    if ($currency['symbol_position'] === 'before') {
        return $currency['symbol'] . $formatted;
    } else {
        return $formatted . ' ' . $currency['symbol'];
    }
}

/**
 * Get current exchange rate for a currency (from base)
 */
function getCurrentExchangeRate($currencyId, $db = null) {
    if (!$db) {
        $db = Database::getMainInstance(); // Use base database for currencies
    }
    
    $currency = getCurrency($currencyId, $db);
    if (!$currency) {
        return 1.0;
    }
    
    if ($currency['is_base']) {
        return 1.0;
    }
    
    return floatval($currency['exchange_rate']);
}

