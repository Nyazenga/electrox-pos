<?php
// Test if error logging works
error_log("TEST ERROR LOG: This is a test message at " . date('Y-m-d H:i:s'));
echo "Test error logged. Check logs/error.log\n";

