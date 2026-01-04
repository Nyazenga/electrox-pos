<?php
/**
 * Temporary script to check transfer status error logs
 * Access this via: https://electrox.bulconsultancy.com/check_transfer_logs.php
 * 
 * SECURITY: Delete this file after debugging!
 */

// Simple authentication check - use your admin credentials
session_start();

// Basic security - require login or password
$password = $_GET['pass'] ?? '';
$correctPassword = 'debug2024'; // Temporary password - CHANGE THIS!

if ($password !== $correctPassword && empty($_SESSION['admin_logged_in'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['password'] === $correctPassword) {
        $_SESSION['admin_logged_in'] = true;
    } else {
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <title>Check Transfer Logs</title>
            <style>
                body { font-family: Arial; max-width: 800px; margin: 50px auto; padding: 20px; }
                form { background: #f5f5f5; padding: 20px; border-radius: 5px; }
                input[type="password"] { padding: 10px; width: 200px; margin: 10px 0; }
                button { padding: 10px 20px; background: #007bff; color: white; border: none; border-radius: 3px; cursor: pointer; }
            </style>
        </head>
        <body>
            <h1>Check Transfer Logs</h1>
            <form method="POST">
                <label>Password: <input type="password" name="password" required></label><br>
                <button type="submit">View Logs</button>
            </form>
        </body>
        </html>
        <?php
        exit;
    }
}

$_SESSION['admin_logged_in'] = true;

// Get log file path
$logFile = __DIR__ . '/logs/transfer_status_error.log';
$errorLog = __DIR__ . '/logs/error.log';

?>
<!DOCTYPE html>
<html>
<head>
    <title>Transfer Status Error Logs</title>
    <style>
        body { font-family: 'Courier New', monospace; background: #1e1e1e; color: #d4d4d4; padding: 20px; }
        h1 { color: #4ec9b0; }
        h2 { color: #569cd6; margin-top: 30px; }
        .log-container { background: #252526; border: 1px solid #3e3e42; padding: 15px; border-radius: 5px; margin: 20px 0; overflow-x: auto; }
        .log-line { margin: 2px 0; white-space: pre-wrap; word-wrap: break-word; }
        .log-error { color: #f48771; }
        .log-success { color: #4ec9b0; }
        .log-warning { color: #dcdcaa; }
        .info { background: #0078d4; padding: 10px; border-radius: 3px; margin: 10px 0; }
        .no-log { color: #808080; font-style: italic; }
        a { color: #4ec9b0; }
        .refresh-btn { background: #0078d4; color: white; padding: 10px 20px; border: none; border-radius: 3px; cursor: pointer; margin: 10px 0; }
        .refresh-btn:hover { background: #005a9e; }
    </style>
</head>
<body>
    <h1>Transfer Status Error Logs</h1>
    
    <a href="?refresh=1" class="refresh-btn">🔄 Refresh Logs</a>
    
    <div class="info">
        <strong>Log File Path:</strong> <?= htmlspecialchars($logFile) ?><br>
        <strong>File Exists:</strong> <?= file_exists($logFile) ? 'Yes' : 'No' ?><br>
        <?php if (file_exists($logFile)): ?>
            <strong>File Size:</strong> <?= number_format(filesize($logFile)) ?> bytes<br>
            <strong>Last Modified:</strong> <?= date('Y-m-d H:i:s', filemtime($logFile)) ?><br>
        <?php endif; ?>
    </div>
    
    <h2>Transfer Status Error Log</h2>
    <div class="log-container">
        <?php if (file_exists($logFile) && is_readable($logFile)): ?>
            <?php 
            $logContent = file_get_contents($logFile);
            if (empty($logContent)): 
            ?>
                <div class="no-log">Log file exists but is empty.</div>
            <?php else: ?>
                <?php 
                $lines = explode("\n", $logContent);
                $recentLines = array_slice($lines, -200); // Last 200 lines
                foreach ($recentLines as $line): 
                    $line = htmlspecialchars($line);
                    $class = '';
                    if (stripos($line, 'error') !== false || stripos($line, 'fatal') !== false || stripos($line, 'failed') !== false) {
                        $class = 'log-error';
                    } elseif (stripos($line, 'success') !== false || stripos($line, 'completed') !== false) {
                        $class = 'log-success';
                    } elseif (stripos($line, 'warning') !== false) {
                        $class = 'log-warning';
                    }
                ?>
                    <div class="log-line <?= $class ?>"><?= $line ?></div>
                <?php endforeach; ?>
            <?php endif; ?>
        <?php else: ?>
            <div class="no-log">Log file does not exist or is not readable.</div>
            <div class="log-warning" style="margin-top: 10px;">
                <strong>Possible reasons:</strong><br>
                1. The transfer hasn't been tested yet since the logging was added<br>
                2. The logs/ directory doesn't have write permissions<br>
                3. The error occurs before the logging code runs
            </div>
        <?php endif; ?>
    </div>
    
    <h2>General Error Log (Last 50 lines related to transfers)</h2>
    <div class="log-container">
        <?php if (file_exists($errorLog) && is_readable($errorLog)): ?>
            <?php 
            $errorContent = file_get_contents($errorLog);
            $errorLines = explode("\n", $errorContent);
            $transferLines = array_filter($errorLines, function($line) {
                return stripos($line, 'transfer') !== false || 
                       stripos($line, 'update_transfer_status') !== false ||
                       stripos($line, '500') !== false;
            });
            $recentErrorLines = array_slice($transferLines, -50);
            if (empty($recentErrorLines)): 
            ?>
                <div class="no-log">No transfer-related errors found in error log.</div>
            <?php else: ?>
                <?php foreach ($recentErrorLines as $line): 
                    $line = htmlspecialchars($line);
                    $class = '';
                    if (stripos($line, 'fatal') !== false || stripos($line, 'error') !== false) {
                        $class = 'log-error';
                    }
                ?>
                    <div class="log-line <?= $class ?>"><?= $line ?></div>
                <?php endforeach; ?>
            <?php endif; ?>
        <?php else: ?>
            <div class="no-log">Error log file does not exist or is not readable.</div>
        <?php endif; ?>
    </div>
    
    <div class="info" style="margin-top: 30px;">
        <strong>⚠️ SECURITY WARNING:</strong> Delete this file (check_transfer_logs.php) after debugging!
    </div>
</body>
</html>

