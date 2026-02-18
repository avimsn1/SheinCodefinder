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
            const response = await fetch('/api/index.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
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
            
            // Small delay to avoid rate limiting
            await new Promise(resolve => setTimeout(resolve, 2000));
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