const { Client, LocalAuth } = require('whatsapp-web.js');
const qrcode = require('qrcode-terminal');
const QRCode = require('qrcode');
const OpenAI = require('openai');
const express = require('express');
const fs = require('fs').promises;
const path = require('path');
require('dotenv').config({ path: '/Users/izerkoen/personal-ai-assistant/waa.env' });

// Ana konfigürasyon
const CONFIG_FILE = path.join(__dirname, 'whatsapp-ai-config.json');
const MEMORY_FILE = path.join(__dirname, 'whatsapp-ai-memory.json');

class WhatsAppAIAssistant {
    constructor() {
        this.client = null;
        this.config = {
            activeChats: [], // {chatId, chatName, prompt, schedule: {type: 'always'|'hourly'|'interval', hours: [], intervalMinutes: 0}}
            openaiApiKey: process.env.OPENAI_API_KEY || ''
        };
        this.openai = null;
        this.chatTimers = new Map(); // Chat zamanlayıcılarını tutar
        this.lastMessageTime = new Map(); // Son mesaj zamanlarını tutar

        // Öğrenme ve hafıza sistemi
        this.chatMemory = new Map(); // Her chat için konuşma geçmişi
        this.chatLearnings = new Map(); // Her chat için öğrenilmiş bilgiler
        this.persistentMemory = {}; // Kalıcı hafıza (dosyaya kaydedilecek)

        // WhatsApp bağlantı durumu
        this.isWhatsAppReady = false;
        this.currentQR = null;
    }

    // Konfigürasyonu yükle
    async loadConfig() {
        try {
            const data = await fs.readFile(CONFIG_FILE, 'utf8');
            this.config = JSON.parse(data);
            console.log('✅ Konfigürasyon yüklendi');
        } catch (error) {
            console.log('⚠️  Konfigürasyon dosyası bulunamadı, yeni oluşturulacak');
            await this.saveConfig();
        }
    }

    // Konfigürasyonu kaydet
    async saveConfig() {
        await fs.writeFile(CONFIG_FILE, JSON.stringify(this.config, null, 2));
        console.log('💾 Konfigürasyon kaydedildi');
    }

    // Hafızayı yükle
    async loadMemory() {
        try {
            const data = await fs.readFile(MEMORY_FILE, 'utf8');
            this.persistentMemory = JSON.parse(data);

            // Map'lere yükle
            for (const [chatId, memory] of Object.entries(this.persistentMemory)) {
                if (memory.history) {
                    this.chatMemory.set(chatId, memory.history);
                }
                if (memory.learnings) {
                    this.chatLearnings.set(chatId, memory.learnings);
                }
            }
            console.log('🧠 Hafıza yüklendi');
        } catch (error) {
            console.log('⚠️  Hafıza dosyası bulunamadı, yeni oluşturulacak');
            this.persistentMemory = {};
        }
    }

    // Hafızayı kaydet
    async saveMemory() {
        try {
            // Map'leri objeye dönüştür
            const memoryData = {};

            for (const [chatId, history] of this.chatMemory.entries()) {
                if (!memoryData[chatId]) memoryData[chatId] = {};
                memoryData[chatId].history = history;
            }

            for (const [chatId, learnings] of this.chatLearnings.entries()) {
                if (!memoryData[chatId]) memoryData[chatId] = {};
                memoryData[chatId].learnings = learnings;
            }

            await fs.writeFile(MEMORY_FILE, JSON.stringify(memoryData, null, 2));
            console.log('🧠 Hafıza kaydedildi');
        } catch (error) {
            console.error('❌ Hafıza kaydetme hatası:', error.message);
        }
    }

    // WhatsApp Client'ı başlat
    async initializeWhatsApp() {
        console.log('🚀 WhatsApp bağlantısı başlatılıyor...');

        this.client = new Client({
            authStrategy: new LocalAuth({
                clientId: "whatsapp-ai-assistant"
            }),
            puppeteer: {
                headless: true,
                args: [
                    '--no-sandbox',
                    '--disable-setuid-sandbox',
                    '--disable-dev-shm-usage',
                    '--disable-accelerated-2d-canvas',
                    '--no-first-run',
                    '--no-zygote',
                    '--disable-gpu',
                    '--single-process',
                    '--disable-extensions'
                ],
                executablePath: process.env.PUPPETEER_EXECUTABLE_PATH || process.env.CHROME_BIN || undefined
            },
            // Stability iyileştirmeleri
            webVersionCache: {
                type: 'remote',
                remotePath: 'https://raw.githubusercontent.com/wppconnect-team/wa-version/main/html/2.2412.54.html',
            }
        });

        // QR Kod göster
        this.client.on('qr', (qr) => {
            console.log('\n📱 QR KODU DASHBOARD\'DA GÖRÜNTÜLENECEK: http://localhost:3030\n');
            // QR kodu dashboard için kaydet
            this.currentQR = qr;

            // QR'ı dashboard için sakla
            this.currentQR = qr;
            this.isWhatsAppReady = false;
        });

        // Bağlantı hazır
        this.client.on('ready', async () => {
            console.log('✅ WhatsApp bağlantısı başarılı!');
            this.isWhatsAppReady = true;
            this.currentQR = null;
            await this.listChatsAndGroups();
        });

        // Mesaj geldiğinde
        this.client.on('message', async (message) => {
            await this.handleIncomingMessage(message);
        });

        // Bağlantı koptuğunda - otomatik reconnect
        this.client.on('disconnected', async (reason) => {
            console.log('❌ WhatsApp bağlantısı koptu:', reason);
            this.isWhatsAppReady = false;

            // LOGOUT durumunda session dosyalarını temizle
            if (reason === 'LOGOUT') {
                console.log('🔄 Session temizleniyor ve yeniden başlatılıyor...');
                // Session dosyalarını silmek yerine yeni QR bekle
                this.currentQR = null;
            } else {
                // Diğer durumlarda otomatik reconnect dene
                console.log('🔄 5 saniye içinde yeniden bağlanmaya çalışılacak...');
                setTimeout(async () => {
                    try {
                        await this.client.initialize();
                    } catch (err) {
                        console.error('❌ Yeniden bağlanma başarısız:', err.message);
                    }
                }, 5000);
            }
        });

        // Bağlantı durumu değişikliklerini takip et
        this.client.on('change_state', (state) => {
            console.log('📡 WhatsApp durumu:', state);
        });

        // Auth hatalarını yakala
        this.client.on('auth_failure', (msg) => {
            console.error('❌ Auth hatası:', msg);
            this.isWhatsAppReady = false;
        });

        // Loading ekranını takip et
        this.client.on('loading_screen', (percent, message) => {
            console.log('⏳ Yükleniyor:', percent + '%', message);
        });

        await this.client.initialize();
    }

    // AI Client'ı başlat
    initializeAI() {
        if (this.config.openaiApiKey) {
            this.openai = new OpenAI({
                apiKey: this.config.openaiApiKey
            });
            console.log('🤖 OpenAI (GPT-4o) hazır');
        } else {
            console.log('⚠️  OPENAI_API_KEY ayarlanmadı');
        }
    }

    // Chat ve grupları listele
    async listChatsAndGroups() {
        try {
            const chats = await this.client.getChats();
            console.log('\n📋 Mevcut Chat ve Gruplar:\n');

            for (let i = 0; i < Math.min(chats.length, 20); i++) {
                const chat = chats[i];
                const type = chat.isGroup ? '👥 GRUP' : '👤 CHAT';
                const active = this.config.activeChats.find(c => c.chatId === chat.id._serialized) ? '✅' : '⬜';
                console.log(`${active} ${type}: ${chat.name}`);
                console.log(`   ID: ${chat.id._serialized}\n`);
            }

            if (chats.length > 20) {
                console.log(`... ve ${chats.length - 20} daha fazla chat\n`);
            }
        } catch (error) {
            console.error('❌ Chat listesi alınamadı:', error.message);
        }
    }

    // Chat aktif mi kontrol et (zamanlama bazlı)
    isChatActive(chatConfig) {
        const now = new Date();
        const schedule = chatConfig.schedule;

        if (!schedule || schedule.type === 'always') {
            return true;
        }

        if (schedule.type === 'hourly' && schedule.hours) {
            const currentHour = now.getHours();
            return schedule.hours.includes(currentHour);
        }

        if (schedule.type === 'interval' && schedule.intervalMinutes) {
            const chatId = chatConfig.chatId;
            const lastTime = this.lastMessageTime.get(chatId);

            if (!lastTime) return true;

            const minutesPassed = (now - lastTime) / (1000 * 60);
            return minutesPassed >= schedule.intervalMinutes;
        }

        return false;
    }

    // Gelen mesajı işle
    async handleIncomingMessage(message) {
        try {
            const chatId = message.from;
            const chatConfig = this.config.activeChats.find(c => c.chatId === chatId);

            // Bu chat için AI aktif değilse, geç
            if (!chatConfig) return;

            // Kendi mesajlarımızı atlat
            if (message.fromMe) return;

            // Zamanlama kontrolü
            if (!this.isChatActive(chatConfig)) {
                console.log(`⏰ Chat "${chatConfig.chatName}" şu anda aktif değil (zamanlama)`);
                return;
            }

            console.log(`\n💬 Mesaj geldi: ${chatConfig.chatName}`);
            console.log(`📝 İçerik: ${message.body.substring(0, 100)}...`);

            // AI yanıtı oluştur (hafıza ile)
            const aiResponse = await this.generateAIResponseWithMemory(chatId, chatConfig.prompt, message.body);

            if (aiResponse) {
                // "Yazıyor..." göster
                const chat = await message.getChat();
                await chat.sendStateTyping();

                // Kısa bir bekleme (daha doğal görünmesi için)
                await new Promise(resolve => setTimeout(resolve, 1500));

                // Mesajı gönder
                await message.reply(aiResponse);
                console.log(`✅ AI yanıtı gönderildi`);

                // Son mesaj zamanını güncelle
                this.lastMessageTime.set(chatId, new Date());

                // Hafızayı her 5 mesajda bir kaydet
                const history = this.chatMemory.get(chatId) || [];
                if (history.length % 5 === 0) {
                    await this.saveMemory();
                }
            }

        } catch (error) {
            console.error('❌ Mesaj işleme hatası:', error.message);
        }
    }

    // Hafızalı AI yanıtı oluştur
    async generateAIResponseWithMemory(chatId, systemPrompt, userMessage) {
        if (!this.openai) {
            console.log('⚠️  AI servisi hazır değil');
            return null;
        }

        try {
            // Konuşma geçmişini al veya oluştur
            if (!this.chatMemory.has(chatId)) {
                this.chatMemory.set(chatId, []);
            }
            const history = this.chatMemory.get(chatId);

            // Öğrenilmiş bilgileri al
            const learnings = this.chatLearnings.get(chatId) || {};
            let learningContext = '';
            if (Object.keys(learnings).length > 0) {
                learningContext = `\n\nÖğrenilmiş Bilgiler:\n${JSON.stringify(learnings, null, 2)}`;
            }

            // Mesajları hazırla (son 20 mesaj)
            const messages = [
                {
                    role: 'system',
                    content: systemPrompt + learningContext + '\n\nÖNEMLİ: Konuşma geçmişini dikkate al ve tutarlı ol. Kişinin konuşma stilini, tercihlerini ve geçmişteki konuları hatırla.'
                }
            ];

            // Son 20 mesajı ekle
            const recentHistory = history.slice(-20);
            messages.push(...recentHistory);

            // Yeni kullanıcı mesajını ekle
            messages.push({
                role: 'user',
                content: userMessage
            });

            const response = await this.openai.chat.completions.create({
                model: 'gpt-5-chat-latest',
                messages: messages,
                temperature: 0.7,
                max_tokens: 1000
            });

            const aiResponse = response.choices[0].message.content;

            // Konuşma geçmişine ekle
            history.push({
                role: 'user',
                content: userMessage
            });
            history.push({
                role: 'assistant',
                content: aiResponse
            });

            // Öğrenme: Anahtar bilgileri çıkar (her 10 mesajda bir)
            if (history.length % 10 === 0) {
                await this.learnFromConversation(chatId, history);
            }

            return aiResponse;
        } catch (error) {
            console.error('❌ AI yanıt hatası:', error.message);
            return null;
        }
    }

    // Konuşmadan öğren
    async learnFromConversation(chatId, history) {
        try {
            // Son 10 mesajı analiz et
            const recentMessages = history.slice(-10);
            const conversationText = recentMessages
                .map(m => `${m.role}: ${m.content}`)
                .join('\n');

            const learningResponse = await this.openai.chat.completions.create({
                model: 'gpt-5-chat-latest',
                messages: [
                    {
                        role: 'system',
                        content: `Sen bir konuşma analizcisin. Aşağıdaki konuşmadan kullanıcı hakkında önemli bilgileri çıkar:
- İsim, yaş, meslek gibi kişisel bilgiler
- İlgi alanları ve hobiler
- Konuşma stili (samimi/resmi, kısa/uzun cevaplar, emoji kullanımı)
- Sık kullandığı kelimeler ve ifadeler
- Tercihler ve beğeniler

JSON formatında yanıt ver. Örnek:
{
  "name": "...",
  "interests": [...],
  "conversation_style": "...",
  "common_phrases": [...],
  "preferences": {...}
}`
                    },
                    {
                        role: 'user',
                        content: conversationText
                    }
                ],
                temperature: 0.3,
                max_tokens: 500
            });

            const learningText = learningResponse.choices[0].message.content;

            // JSON parse et
            const match = learningText.match(/\{[\s\S]*\}/);
            if (match) {
                const newLearnings = JSON.parse(match[0]);

                // Mevcut öğrenmeleri güncelle
                const existingLearnings = this.chatLearnings.get(chatId) || {};
                const updatedLearnings = { ...existingLearnings, ...newLearnings, lastUpdated: new Date().toISOString() };

                this.chatLearnings.set(chatId, updatedLearnings);
                console.log(`🧠 ${chatId} için yeni bilgiler öğrenildi`);
            }
        } catch (error) {
            console.error('❌ Öğrenme hatası:', error.message);
        }
    }

    // AI yanıtı oluştur (eski metod - uyumluluk için)
    async generateAIResponse(systemPrompt, userMessage) {
        if (!this.openai) {
            console.log('⚠️  AI servisi hazır değil');
            return null;
        }

        try {
            const response = await this.openai.chat.completions.create({
                model: 'gpt-5-chat-latest',
                messages: [
                    {
                        role: 'system',
                        content: systemPrompt
                    },
                    {
                        role: 'user',
                        content: userMessage
                    }
                ],
                temperature: 0.7,
                max_tokens: 1000
            });

            return response.choices[0].message.content;
        } catch (error) {
            console.error('❌ AI yanıt hatası:', error.message);
            return null;
        }
    }

    // Chat ekle/güncelle
    async addOrUpdateChat(chatId, chatName, prompt, schedule = { type: 'always' }) {
        const existingIndex = this.config.activeChats.findIndex(c => c.chatId === chatId);

        const chatConfig = {
            chatId,
            chatName,
            prompt,
            schedule
        };

        if (existingIndex >= 0) {
            this.config.activeChats[existingIndex] = chatConfig;
            console.log(`🔄 Chat güncellendi: ${chatName}`);
        } else {
            this.config.activeChats.push(chatConfig);
            console.log(`➕ Yeni chat eklendi: ${chatName}`);
        }

        await this.saveConfig();
    }

    // Chat kaldır
    async removeChat(chatId) {
        const index = this.config.activeChats.findIndex(c => c.chatId === chatId);
        if (index >= 0) {
            const chatName = this.config.activeChats[index].chatName;
            this.config.activeChats.splice(index, 1);
            await this.saveConfig();
            console.log(`➖ Chat kaldırıldı: ${chatName}`);
            return true;
        }
        return false;
    }

    // Web dashboard başlat
    startWebDashboard(port = 3030) {
        const app = express();
        app.use(express.json());
        app.use(express.static('public'));

        // Ana sayfa
        app.get('/', (req, res) => {
            res.send(this.getWebInterface());
        });

        // Chat listesi al
        app.get('/api/chats', async (req, res) => {
            try {
                // Client hazır değilse boş liste dön
                if (!this.client || !this.isWhatsAppReady) {
                    return res.json([]);
                }

                const chats = await this.client.getChats();
                res.json(chats.map(c => ({
                    id: c.id._serialized,
                    name: c.name,
                    isGroup: c.isGroup,
                    active: !!this.config.activeChats.find(ac => ac.chatId === c.id._serialized)
                })));
            } catch (error) {
                console.error('Chat yükleme hatası:', error);
                res.status(500).json({ error: error.message });
            }
        });

        // WhatsApp durumu kontrol
        app.get('/api/whatsapp-status', async (req, res) => {
            let qrDataURL = null;
            if (this.currentQR) {
                try {
                    qrDataURL = await QRCode.toDataURL(this.currentQR);
                } catch (error) {
                    console.error('QR kod oluşturma hatası:', error);
                }
            }

            res.json({
                isReady: this.isWhatsAppReady,
                qrCode: qrDataURL
            });
        });

        // Aktif chatler
        app.get('/api/active-chats', (req, res) => {
            res.json(this.config.activeChats);
        });

        // Chat ekle/güncelle
        app.post('/api/chat', async (req, res) => {
            const { chatId, chatName, prompt, schedule } = req.body;
            await this.addOrUpdateChat(chatId, chatName, prompt, schedule);
            res.json({ success: true });
        });

        // Chat sil
        app.delete('/api/chat/:chatId', async (req, res) => {
            const success = await this.removeChat(req.params.chatId);
            res.json({ success });
        });

        // API key güncelle
        app.post('/api/api-key', async (req, res) => {
            this.config.openaiApiKey = req.body.apiKey;
            await this.saveConfig();
            this.initializeAI();
            res.json({ success: true });
        });

        app.listen(port, () => {
            console.log(`\n🌐 Web Dashboard: http://localhost:${port}\n`);
        });
    }

    // Web arayüzü HTML
    getWebInterface() {
        return `<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WhatsApp AI Asistan</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        .container { max-width: 1200px; margin: 0 auto; }
        .header {
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
            margin-bottom: 30px;
            text-align: center;
        }
        h1 { color: #333; margin-bottom: 10px; }
        .subtitle { color: #666; }

        /* QR Login Screen */
        .login-screen {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 80vh;
        }
        .qr-container {
            background: white;
            padding: 50px;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.2);
            text-align: center;
            max-width: 500px;
        }
        .qr-code {
            margin: 30px 0;
            padding: 20px;
            background: white;
            border-radius: 15px;
            display: inline-block;
        }
        .qr-code img {
            width: 300px;
            height: 300px;
            border: 3px solid #667eea;
            border-radius: 10px;
        }
        .loading-spinner {
            width: 50px;
            height: 50px;
            border: 5px solid #f3f3f3;
            border-top: 5px solid #667eea;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 30px auto;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        .instructions {
            background: #f8f9ff;
            padding: 20px;
            border-radius: 10px;
            margin-top: 20px;
            text-align: left;
        }
        .instructions ol {
            margin-left: 20px;
        }
        .instructions li {
            margin: 10px 0;
            color: #666;
        }

        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 30px; }
        .panel {
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
        }
        h2 { color: #333; margin-bottom: 20px; font-size: 22px; }
        .chat-list {
            max-height: 400px;
            overflow-y: auto;
            border: 1px solid #eee;
            border-radius: 8px;
            padding: 10px;
        }
        .chat-item {
            padding: 15px;
            border: 2px solid #eee;
            border-radius: 8px;
            margin-bottom: 10px;
            cursor: pointer;
            transition: all 0.3s;
        }
        .chat-item:hover { border-color: #667eea; background: #f8f9ff; }
        .chat-item.active { border-color: #28a745; background: #f0fff4; }
        .chat-name { font-weight: 600; margin-bottom: 5px; }
        .chat-type { font-size: 12px; color: #666; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; font-weight: 600; color: #333; }
        input, textarea, select {
            width: 100%;
            padding: 12px;
            border: 2px solid #eee;
            border-radius: 8px;
            font-size: 14px;
            transition: border 0.3s;
        }
        input:focus, textarea:focus, select:focus {
            outline: none;
            border-color: #667eea;
        }
        textarea { min-height: 120px; resize: vertical; font-family: inherit; }
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }
        .btn-primary {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
        }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 5px 20px rgba(102, 126, 234, 0.4); }
        .btn-danger {
            background: #dc3545;
            color: white;
        }
        .active-chats { margin-top: 20px; }
        .active-chat-item {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 15px;
            border-left: 4px solid #28a745;
        }
        .active-chat-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        .schedule-badge {
            display: inline-block;
            padding: 4px 12px;
            background: #667eea;
            color: white;
            border-radius: 12px;
            font-size: 12px;
            margin-left: 10px;
        }
        .prompt-preview {
            font-size: 13px;
            color: #666;
            font-style: italic;
            margin: 10px 0;
        }
        .example-prompts {
            background: #f8f9ff;
            padding: 15px;
            border-radius: 8px;
            margin-top: 10px;
            border-left: 3px solid #667eea;
        }
        .example-prompts h4 {
            font-size: 14px;
            margin-bottom: 10px;
            color: #667eea;
        }
        .example-prompts p {
            font-size: 12px;
            margin-bottom: 8px;
            padding: 8px;
            background: white;
            border-radius: 5px;
            cursor: pointer;
        }
        .example-prompts p:hover {
            background: #e8ecff;
        }
        @media (max-width: 768px) {
            .grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- QR Login Screen -->
        <div id="loginScreen" class="login-screen" style="display:none;">
            <div class="qr-container">
                <h1>📱 WhatsApp'a Bağlan</h1>
                <p style="color: #666; margin: 20px 0;">Telefonunuzun kamerasıyla QR kodu okutun</p>

                <div class="qr-code" id="qrCodeContainer">
                    <div class="loading-spinner"></div>
                </div>

                <div class="instructions">
                    <h3 style="margin-bottom: 15px;">📝 Nasıl Bağlanırım?</h3>
                    <ol>
                        <li>Telefonunuzda WhatsApp'ı açın</li>
                        <li><strong>Ayarlar</strong> > <strong>Bağlı Cihazlar</strong>'a gidin</li>
                        <li><strong>Cihaz Bağla</strong> butonuna tıklayın</li>
                        <li>Yukarıdaki QR kodu kamerayla okutun</li>
                    </ol>
                </div>
            </div>
        </div>

        <!-- Dashboard Screen -->
        <div id="dashboardScreen" style="display:none;">
            <div class="header">
                <h1>🤖 WhatsApp AI Asistan</h1>
                <p class="subtitle">Chat'lerinize ve gruplarınıza özel AI asistan ekleyin</p>
            </div>

            <div class="grid">
            <div class="panel">
                <h2>📱 WhatsApp Chat'leri</h2>
                <div id="chatList" class="chat-list">
                    <p style="text-align: center; color: #999;">Yükleniyor...</p>
                </div>
            </div>

            <div class="panel">
                <h2>⚙️ AI Asistan Ayarları</h2>

                <div class="form-group">
                    <label>Seçili Chat</label>
                    <input type="text" id="selectedChatName" readonly placeholder="Soldan bir chat seçin">
                    <input type="hidden" id="selectedChatId">
                </div>

                <div class="form-group">
                    <label>AI Asistan Rolü (System Prompt)</label>
                    <textarea id="promptText" placeholder="Örn: Sen yardımsever bir müşteri destek asistanısın. Kibar ve profesyonel bir şekilde cevap ver."></textarea>

                    <div class="example-prompts">
                        <h4>📝 Örnek Promptlar (Tıklayarak kullanın):</h4>
                        <p onclick="usePrompt(this)">Sen profesyonel bir müşteri destek asistanısın. Kibar, yardımsever ve çözüm odaklı cevaplar ver.</p>
                        <p onclick="usePrompt(this)">Sen eğlenceli ve samimi bir arkadaşsın. Emoji kullan ve rahat bir dille konuş.</p>
                        <p onclick="usePrompt(this)">Sen bir satış danışmanısın. Ürünler hakkında bilgi ver, müşterilere yardımcı ol ve satışı teşvik et.</p>
                        <p onclick="usePrompt(this)">Sen bir kişisel asistansın. Randevuları, hatırlatmaları ve günlük işleri organize etmeye yardım et.</p>
                    </div>
                </div>

                <div class="form-group">
                    <label>Zamanlama Tipi</label>
                    <select id="scheduleType" onchange="updateScheduleOptions()">
                        <option value="always">Her Zaman Aktif</option>
                        <option value="hourly">Belirli Saatlerde</option>
                        <option value="interval">Dakika Aralığında</option>
                    </select>
                </div>

                <div class="form-group" id="hourlyOptions" style="display:none;">
                    <label>Aktif Saatler (virgülle ayırın, 0-23)</label>
                    <input type="text" id="activeHours" placeholder="Örn: 9,10,11,12,13,14,15,16,17,18">
                    <small style="color: #666;">Mesela 9-18 arası çalışma saatleri için: 9,10,11,12,13,14,15,16,17,18</small>
                </div>

                <div class="form-group" id="intervalOptions" style="display:none;">
                    <label>Kaç Dakikada Bir Cevap Versin?</label>
                    <input type="number" id="intervalMinutes" min="1" placeholder="Örn: 5">
                    <small style="color: #666;">Örneğin 5 yazarsanız, her 5 dakikada bir cevap verir</small>
                </div>

                <button class="btn btn-primary" onclick="saveChat()" style="width: 100%;">
                    💾 Kaydet ve Aktif Et
                </button>

                <div class="active-chats">
                    <h3 style="margin-bottom: 15px;">✅ Aktif AI Asistanlar</h3>
                    <div id="activeChats"></div>
                </div>
            </div>
        </div>
    </div>

    <script>
        let allChats = [];
        let selectedChat = null;

        function usePrompt(element) {
            document.getElementById('promptText').value = element.textContent;
        }

        async function loadChats() {
            try {
                const res = await fetch('/api/chats');
                allChats = await res.json();
                renderChats();
            } catch (error) {
                console.error('Chat yükleme hatası:', error);
            }
        }

        function renderChats() {
            const html = allChats.map(chat => {
                const type = chat.isGroup ? '👥 Grup' : '👤 Chat';
                const activeClass = chat.active ? 'active' : '';
                return \`
                    <div class="chat-item \${activeClass}" onclick="selectChat('\${chat.id}', '\${chat.name}')">
                        <div class="chat-name">\${chat.name}</div>
                        <div class="chat-type">\${type} • ID: \${chat.id.substring(0, 20)}...</div>
                    </div>
                \`;
            }).join('');
            document.getElementById('chatList').innerHTML = html || '<p style="text-align:center;color:#999;">Chat bulunamadı</p>';
        }

        function selectChat(id, name) {
            selectedChat = { id, name };
            document.getElementById('selectedChatId').value = id;
            document.getElementById('selectedChatName').value = name;

            // Eğer bu chat zaten aktifse, ayarlarını yükle
            loadChatSettings(id);
        }

        async function loadChatSettings(chatId) {
            const res = await fetch('/api/active-chats');
            const activeChats = await res.json();
            const chatConfig = activeChats.find(c => c.chatId === chatId);

            if (chatConfig) {
                document.getElementById('promptText').value = chatConfig.prompt;
                document.getElementById('scheduleType').value = chatConfig.schedule?.type || 'always';
                updateScheduleOptions();

                if (chatConfig.schedule?.hours) {
                    document.getElementById('activeHours').value = chatConfig.schedule.hours.join(',');
                }
                if (chatConfig.schedule?.intervalMinutes) {
                    document.getElementById('intervalMinutes').value = chatConfig.schedule.intervalMinutes;
                }
            } else {
                // Yeni chat için formu temizle
                document.getElementById('promptText').value = '';
                document.getElementById('scheduleType').value = 'always';
                updateScheduleOptions();
            }
        }

        function updateScheduleOptions() {
            const type = document.getElementById('scheduleType').value;
            document.getElementById('hourlyOptions').style.display = type === 'hourly' ? 'block' : 'none';
            document.getElementById('intervalOptions').style.display = type === 'interval' ? 'block' : 'none';
        }

        async function saveChat() {
            if (!selectedChat) {
                alert('⚠️ Lütfen soldan bir chat seçin');
                return;
            }

            const prompt = document.getElementById('promptText').value.trim();
            if (!prompt) {
                alert('⚠️ Lütfen bir AI asistan rolü (prompt) girin');
                return;
            }

            const scheduleType = document.getElementById('scheduleType').value;
            let schedule = { type: scheduleType };

            if (scheduleType === 'hourly') {
                const hoursInput = document.getElementById('activeHours').value;
                if (!hoursInput) {
                    alert('⚠️ Lütfen aktif saatleri girin');
                    return;
                }
                const hours = hoursInput.split(',')
                    .map(h => parseInt(h.trim()))
                    .filter(h => h >= 0 && h <= 23);
                if (hours.length === 0) {
                    alert('⚠️ Geçerli saatler girin (0-23 arası)');
                    return;
                }
                schedule.hours = hours;
            } else if (scheduleType === 'interval') {
                const minutes = parseInt(document.getElementById('intervalMinutes').value);
                if (!minutes || minutes < 1) {
                    alert('⚠️ Geçerli bir dakika değeri girin');
                    return;
                }
                schedule.intervalMinutes = minutes;
            }

            await fetch('/api/chat', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    chatId: selectedChat.id,
                    chatName: selectedChat.name,
                    prompt,
                    schedule
                })
            });

            alert('✅ AI Asistan kaydedildi ve aktif edildi!');
            loadChats();
            loadActiveChats();
        }

        async function loadActiveChats() {
            const res = await fetch('/api/active-chats');
            const activeChats = await res.json();

            const html = activeChats.map(chat => {
                let scheduleBadge = '🟢 Her Zaman';
                if (chat.schedule?.type === 'hourly') {
                    scheduleBadge = \`🕐 Saat: \${chat.schedule.hours.join(', ')}\`;
                } else if (chat.schedule?.type === 'interval') {
                    scheduleBadge = \`⏱️ Her \${chat.schedule.intervalMinutes} dk\`;
                }

                return \`
                    <div class="active-chat-item">
                        <div class="active-chat-header">
                            <strong>\${chat.chatName}</strong>
                            <button class="btn btn-danger" onclick="removeChat('\${chat.chatId}')" style="padding: 6px 12px; font-size: 12px;">Kaldır</button>
                        </div>
                        <div class="schedule-badge">\${scheduleBadge}</div>
                        <div class="prompt-preview">\${chat.prompt.substring(0, 150)}...</div>
                    </div>
                \`;
            }).join('');

            document.getElementById('activeChats').innerHTML = html || '<p style="color:#999;">Henüz aktif asistan yok</p>';
        }

        async function removeChat(chatId) {
            if (!confirm('Bu AI asistanı kaldırmak istediğinize emin misiniz?')) return;

            await fetch(\`/api/chat/\${encodeURIComponent(chatId)}\`, { method: 'DELETE' });
            alert('✅ AI Asistan kaldırıldı');
            loadChats();
            loadActiveChats();
        }

        // WhatsApp durumunu kontrol et ve ekranları yönet
        async function checkWhatsAppStatus() {
            const res = await fetch('/api/whatsapp-status');
            const status = await res.json();

            if (status.isReady) {
                // WhatsApp bağlı - Dashboard göster
                document.getElementById('loginScreen').style.display = 'none';
                document.getElementById('dashboardScreen').style.display = 'block';
                loadChats();
                loadActiveChats();
            } else {
                // WhatsApp bağlı değil - QR kod göster
                document.getElementById('loginScreen').style.display = 'flex';
                document.getElementById('dashboardScreen').style.display = 'none';

                if (status.qrCode) {
                    document.getElementById('qrCodeContainer').innerHTML = \`<img src="\${status.qrCode}" alt="QR Kod">\`;
                } else {
                    document.getElementById('qrCodeContainer').innerHTML = '<div class="loading-spinner"></div><p style="margin-top: 20px; color: #666;">QR kod oluşturuluyor...</p>';
                }
            }
        }

        // Sayfa yüklendiğinde
        checkWhatsAppStatus();
        setInterval(checkWhatsAppStatus, 2000); // Her 2 saniyede bir kontrol et
        setInterval(loadActiveChats, 5000); // Her 5 saniyede bir aktif chatları güncelle
    </script>
    </div>
</body>
</html>`;
    }

    // Sistemi başlat
    async start() {
        console.log('🎯 WhatsApp AI Asistan Başlatılıyor...\n');

        await this.loadConfig();
        await this.loadMemory();
        this.initializeAI();

        // Web sunucusunu hemen başlat
        this.startWebDashboard();

        // WhatsApp'ı arka planda başlat
        this.initializeWhatsApp().catch(err => {
            console.error('❌ WhatsApp başlatma hatası:', err);
        });

        // Hafızayı düzenli olarak kaydet (her 5 dakikada bir)
        setInterval(async () => {
            await this.saveMemory();
        }, 5 * 60 * 1000);

        // Keepalive - WhatsApp bağlantısını canlı tut
        setInterval(async () => {
            if (this.isWhatsAppReady && this.client) {
                try {
                    // State kontrolü yaparak bağlantıyı canlı tut
                    const state = await this.client.getState();
                    if (state !== 'CONNECTED') {
                        console.log('⚠️  WhatsApp bağlantısı zayıf, durum:', state);
                    }
                } catch (err) {
                    console.error('⚠️  Keepalive hatası:', err.message);
                }
            }
        }, 30 * 1000); // Her 30 saniyede bir kontrol
    }
}

// Ana program
const assistant = new WhatsAppAIAssistant();
assistant.start();

// Graceful shutdown
process.on('SIGINT', async () => {
    console.log('\n🔄 Kapatılıyor...');
    if (assistant.client) {
        await assistant.client.destroy();
    }
    process.exit(0);
});
