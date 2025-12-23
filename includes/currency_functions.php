<?php
/**
 * Currency Helper Functions
 * Currencies are stored in the currencies table in each tenant database
 */

/**
 * Get all currencies from currencies table
 */
function getAllCurrencies($db = null) {
    if (!$db) {
        $db = Database::getInstance();
    }
    
    try {
        $currencies = $db->getRows("SELECT * FROM currencies ORDER BY is_base DESC, code ASC");
        if ($currencies === false || !is_array($currencies)) {
            return [];
        }
        return $currencies;
    } catch (Exception $e) {
        error_log("Error getting currencies: " . $e->getMessage());
        return [];
    }
}

/**
 * Get base currency
 */
function getBaseCurrency($db = null) {
    if (!$db) {
        $db = Database::getInstance();
    }
    
    try {
        $currency = $db->getRow("SELECT * FROM currencies WHERE is_base = 1 AND is_active = 1 LIMIT 1");
        if ($currency) {
            return $currency;
        }
    } catch (Exception $e) {
        error_log("Error getting base currency: " . $e->getMessage());
    }
    
    // Fallback to default_currency setting
    $defaultCode = getSetting('default_currency', 'USD');
    return [
        'id' => 1,
        'code' => $defaultCode,
        'name' => $defaultCode === 'USD' ? 'US Dollar' : ($defaultCode === 'ZWL' ? 'Zimbabwean Dollar' : $defaultCode),
        'symbol' => $defaultCode === 'USD' ? '$' : ($defaultCode === 'ZWL' ? 'ZWL' : $defaultCode),
        'symbol_position' => 'before',
        'decimal_places' => 2,
        'is_base' => 1,
        'is_active' => 1,
        'exchange_rate' => 1.000000
    ];
}

/**
 * Get all active currencies
 */
function getActiveCurrencies($db = null) {
    if (!$db) {
        $db = Database::getInstance();
    }
    
    try {
        $currencies = $db->getRows("SELECT * FROM currencies WHERE is_active = 1 ORDER BY is_base DESC, code ASC");
        if ($currencies === false || !is_array($currencies)) {
            return [];
        }
        return $currencies;
    } catch (Exception $e) {
        error_log("Error getting active currencies: " . $e->getMessage());
        return [];
    }
}

/**
 * Get currency by ID
 */
function getCurrency($currencyId, $db = null) {
    if (!$db) {
        $db = Database::getInstance();
    }
    
    try {
        $currency = $db->getRow("SELECT * FROM currencies WHERE id = :id", [':id' => $currencyId]);
        if ($currency) {
            return $currency;
        }
    } catch (Exception $e) {
        error_log("Error getting currency by ID: " . $e->getMessage());
    }
    
    return false;
}

/**
 * Get currency by code
 */
function getCurrencyByCode($code, $db = null) {
    if (!$db) {
        $db = Database::getInstance();
    }
    
    try {
        $codeUpper = strtoupper($code);
        $currency = $db->getRow("SELECT * FROM currencies WHERE UPPER(code) = :code", [':code' => $codeUpper]);
        if ($currency) {
            return $currency;
        }
    } catch (Exception $e) {
        error_log("Error getting currency by code: " . $e->getMessage());
    }
    
    return false;
}

/**
 * Get exchange rate from one currency to another
 */
function getExchangeRate($fromCurrencyId, $toCurrencyId, $db = null) {
    if ($fromCurrencyId == $toCurrencyId) {
        return 1.0;
    }
    
    $fromCurrency = getCurrency($fromCurrencyId, $db);
    $toCurrency = getCurrency($toCurrencyId, $db);
    
    if (!$fromCurrency || !$toCurrency) {
        return 1.0;
    }
    
    // If either is base currency
    if (isset($fromCurrency['is_base']) && $fromCurrency['is_base'] == 1) {
        return floatval($toCurrency['exchange_rate'] ?? 1.0);
    }
    if (isset($toCurrency['is_base']) && $toCurrency['is_base'] == 1) {
        return 1.0 / floatval($fromCurrency['exchange_rate'] ?? 1.0);
    }
    
    // Convert through base currency
    $baseRate = floatval($toCurrency['exchange_rate'] ?? 1.0) / floatval($fromCurrency['exchange_rate'] ?? 1.0);
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
    
    $decimalPlaces = $currency['decimal_places'] ?? 2;
    $formatted = number_format($amount, $decimalPlaces);
    
    $symbolPosition = $currency['symbol_position'] ?? 'before';
    $symbol = $currency['symbol'] ?? '';
    
    if ($symbolPosition === 'before') {
        return $symbol . $formatted;
    } else {
        return $formatted . ' ' . $symbol;
    }
}

/**
 * Get current exchange rate for a currency (from base)
 */
function getCurrentExchangeRate($currencyId, $db = null) {
    if (!$db) {
        $db = Database::getInstance();
    }
    
    $currency = getCurrency($currencyId, $db);
    if (!$currency) {
        return 1.0;
    }
    
    if (isset($currency['is_base']) && $currency['is_base'] == 1) {
        return 1.0;
    }
    
    return floatval($currency['exchange_rate'] ?? 1.0);
}
