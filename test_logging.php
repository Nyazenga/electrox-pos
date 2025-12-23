<?php
// Test if error_log is working
error_log("TEST LOG: This is a test log entry at " . date('Y-m-d H:i:s'));
echo "Test log written. Check logs/error.log for the entry.\n";

