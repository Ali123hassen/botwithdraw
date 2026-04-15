<?php
/**
 * Telegram Bot CLI Runner
 * Use long-polling method instead of webhooks
 * 
 * Usage: php bot/run.php
 * Make sure to set TELEGRAM_BOT_TOKEN in .env
 */

use Illuminate\Database\Capsule\Manager as Capsule;

require __DIR__ . '/../vendor/autoload.php';

// Load .env manually
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            if (!getenv($key)) {
                putenv("$key=$value");
            }
        }
    }
}

// Setup Laravel Eloquent with MySQL
$capsule = new Capsule;

$host = getenv('DB_HOST') ?: '127.0.0.1';
$port = getenv('DB_PORT') ?: '3306';
$database = getenv('DB_DATABASE') ?: 'botwithdraw';
$username = getenv('DB_USERNAME') ?: 'root';
$password = getenv('DB_PASSWORD') ?: '';

$capsule->addConnection([
    'driver' => 'mysql',
    'host' => $host,
    'port' => $port,
    'database' => $database,
    'username' => $username,
    'password' => $password,
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix' => '',
]);

$capsule->setAsGlobal();
$capsule->bootEloquent();

$botToken = getenv('TELEGRAM_BOT_TOKEN') ?: (isset($_ENV['TELEGRAM_BOT_TOKEN']) ? $_ENV['TELEGRAM_BOT_TOKEN'] : '');
$apiUrl = "https://api.telegram.org/bot$botToken";

if (empty($botToken)) {
    die("Please set TELEGRAM_BOT_TOKEN in .env file\n");
}

// Use capsule directly instead of DB facade
function getSetting($key, $default = '') {
    global $capsule;
    $result = $capsule->table('settings')->where('key', $key)->first();
    return $result ? $result->value : $default;
}

function sendMessage($chatId, $text, $keyboard = null) {
    global $apiUrl;
    
    $data = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML',
    ];
    
    if ($keyboard) {
        $data['reply_markup'] = json_encode($keyboard);
    }
    
    $ch = curl_init($apiUrl . '/sendMessage');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    
    return curl_exec($ch);
}

function getFeeSettings() {
    return [
        'network_fee' => (float) getSetting('network_fee', 1),
        'deposit_fee_percent' => (float) getSetting('deposit_fee_percent', 4),
    ];
}

// MEXC API Configuration
function getMexcConfig() {
    return [
        'api_key' => getSetting('mexc_api_key', ''),
        'api_secret' => getSetting('mexc_api_secret', ''),
        'base_url' => 'https://api.mexc.com',
    ];
}

// Generate MEXC signature
function generateMexcSignature($apiSecret, $timestamp, $method, $requestPath, $body = '') {
    $signatureStr = $timestamp . $method . $requestPath . $body;
    return strtoupper(hash_hmac('sha256', $signatureStr, $apiSecret));
}

// Withdraw USDT via MEXC API
function withdrawViaMexc($toAddress, $amount) {
    global $capsule;
    
    $config = getMexcConfig();
    $apiKey = $config['api_key'];
    $apiSecret = $config['api_secret'];
    $baseUrl = $config['base_url'];
    
    if (empty($apiKey) || empty($apiSecret)) {
        // Debug: Log what we got
        error_log("MEXC Config - API Key: " . ($apiKey ? "SET" : "EMPTY"));
        error_log("MEXC Config - API Secret: " . ($apiSecret ? "SET" : "EMPTY"));
        return ['ok' => false, 'error' => 'MEXC API not configured'];
    }
    
    $method = 'POST';
    $requestPath = '/api/v3/capital/withdraw';
    
    $params = [
        'coin' => 'USDT',
        'address' => $toAddress,
        'amount' => (string) $amount,
        'network' => 'TRC20',
    ];
    
    $body = json_encode($params);
    $timestamp = time() * 1000;
    $signature = generateMexcSignature($apiSecret, $timestamp, $method, $requestPath, $body);
    
    // Debug logging
    error_log("MEXC Request - Timestamp: $timestamp");
    error_log("MEXC Request - Signature: " . substr($signature, 0, 20) . "...");
    
    $headers = [
        'Content-Type: application/json',
        'X-MEXC-APIKEY: ' . $apiKey,
        'X-MEXC-SIGNATURE: ' . $signature,
        'X-MEXC-TIMESTAMP: ' . $timestamp,
    ];
    
    $url = $baseUrl . $requestPath;
    error_log("MEXC Request URL: $url");
    error_log("MEXC Request Body: $body");

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    // Debug logging
    error_log("MEXC Response - HTTP: $httpCode, Body: $response, Error: $error");
    
    $result = json_decode($response, true);
    
    if ($httpCode === 200 && isset($result['id'])) {
        return ['ok' => true, 'txid' => $result['id'], 'withdrawId' => $result['id']];
    } else {
        return ['ok' => false, 'error' => $result['msg'] ?? 'Withdrawal failed', 'response' => $result, 'http_code' => $httpCode];
    }
}

// Check MEXC withdrawal status
function checkMexcWithdrawal($withdrawId) {
    $config = getMexcConfig();
    $apiKey = $config['api_key'];
    $apiSecret = $config['api_secret'];
    $baseUrl = $config['base_url'];
    
    if (empty($apiKey) || empty($apiSecret)) {
        error_log('MEXC API Key: ' . $apiKey);
    error_log('MEXC API Secret: ' . substr($apiSecret, 0, 5) . '...');
    if (empty($apiKey) || empty($apiSecret)) {
        return ['ok' => false, 'error' => 'MEXC API not configured'];
    }
    }
    
    $method = 'GET';
    $requestPath = '/api/v3/capital/withdraw/history?' . http_build_query(['withdrawId' => $withdrawId]);
    
    $timestamp = time() * 1000;
    $signature = generateMexcSignature($apiSecret, $timestamp, $method, $requestPath);
    
    $headers = [
        'X-MEXC-APIKEY: ' . $apiKey,
        'X-MEXC-SIGNATURE: ' . $signature,
        'X-MEXC-TIMESTAMP: ' . $timestamp,
    ];
    
    $ch = curl_init($baseUrl . $requestPath);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    return json_decode($response, true);
}

// Get MEXC wallet balance
function getMexcBalance() {
    $config = getMexcConfig();
    $apiKey = $config['api_key'];
    $apiSecret = $config['api_secret'];
    $baseUrl = $config['base_url'];
    
    if (empty($apiKey) || empty($apiSecret)) {
        return 0;
    }
    
    $method = 'GET';
    $requestPath = '/api/v3/account/balance';
    
    $timestamp = time() * 1000;
    $signature = generateMexcSignature($apiSecret, $timestamp, $method, $requestPath);
    
    $headers = [
        'X-MEXC-APIKEY: ' . $apiKey,
        'X-MEXC-SIGNATURE: ' . $signature,
        'X-MEXC-TIMESTAMP: ' . $timestamp,
    ];
    
    $ch = curl_init($baseUrl . $requestPath);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    $data = json_decode($response, true);
    
    if (!empty($data['balances'])) {
        foreach ($data['balances'] as $balance) {
            if ($balance['asset'] === 'USDT') {
                return (float) $balance['free'];
            }
        }
    }
    
    return 0;
}

function calculateFees($amount, $networkFee, $depositFeePercent) {
    // Fees are deducted FROM the requested amount
    // Client receives: amount - networkFee - depositFee
    $depositFee = ($amount - $networkFee) * ($depositFeePercent / 100);
    $totalDeducted = $amount;
    $clientReceives = $amount - $networkFee - $depositFee;
    
    return [
        'amount' => $amount,
        'network_fee' => $networkFee,
        'deposit_fee_percent' => $depositFeePercent,
        'deposit_fee' => $depositFee,
        'total_deducted' => $totalDeducted,
        'client_receives' => max(0, $clientReceives),
    ];
}

function saveWithdrawal($userId, $walletAddress, $amount, $networkFee, $depositFeePercent, $totalDeducted) {
    global $capsule;
    return $capsule->table('withdrawals')->insertGetId([
        'telegram_user_id' => $userId,
        'wallet_address' => $walletAddress,
        'amount' => $amount,
        'network_fee' => $networkFee,
        'deposit_fee_percent' => $depositFeePercent,
        'total_deducted' => $totalDeducted,
        'network' => 'TRC20',
        'currency' => 'USDT',
        'status' => 'pending',
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
}

function getUserWithdrawals($userId, $limit = 5) {
    global $capsule;
    return $capsule->table('withdrawals')
        ->where('telegram_user_id', $userId)
        ->orderBy('created_at', 'desc')
        ->limit($limit)
        ->get()
        ->toArray();
}

function getWalletAddress() {
    return getSetting('wallet_address', '');
}

function mainMenuKeyboard() {
    return [
        'keyboard' => [
            [['text' => '💰 سحب USDT'], ['text' => '🏧 إيداع USDT']],
            [['text' => '📊 سجل السحوبات'], ['text' => '❓ مساعدة']],
        ],
        'resize_keyboard' => true,
    ];
}

function processMessage($message) {
    $chatId = $message['chat']['id'];
    $userId = (string) $message['from']['id'];
    $text = $message['text'] ?? '';
    $firstName = $message['from']['first_name'] ?? 'User';
    
    $allowedUserId = getSetting('allowed_user_id', '');
    
    if ($allowedUserId && $userId !== $allowedUserId) {
        sendMessage($chatId, "⛔ عذراً، ليس لديك صلاحية استخدام هذا البوت.");
        return;
    }
    
    if ($text === '/start' || $text === 'الرئيسية') {
        $welcomeMsg = "🎉 مرحباً بك في بوت سحب USDT!\n\n";
        $welcomeMsg .= "👤 المستخدم: $firstName\n";
        $welcomeMsg .= "🆔 ID: $userId\n\n";
        $welcomeMsg .= "اختر من القائمة أدناه:";
        
        sendMessage($chatId, $welcomeMsg, mainMenuKeyboard());
        return;
    }
    
    if ($text === '💰 سحب USDT' || $text === '/withdraw') {
        $walletAddress = getWalletAddress();
        
        if (empty($walletAddress)) {
            sendMessage($chatId, "⚠️ لم يتم إعداد عنوان المحفظة. يرجى التواصل مع المسؤول.");
            return;
        }
        
        $msg = "💸 <b>سحب USDT</b>\n\n";
        $msg .= "📋 للعملية:\n";
        $msg .= "- العملة: USDT\n";
        $msg .= "- الشبكة: TRC20\n\n";
        $msg .= "📝 أدخل عنوان محفظتك (TRC20):";
        
        file_put_contents(__DIR__ . "/state_$userId.json", json_encode(['step' => 'withdraw_wallet']));
        
        sendMessage($chatId, $msg);
        return;
    }
    
    // إيداع USDT
    if ($text === '🏧 إيداع USDT' || $text === '/deposit') {
        $walletAddress = getWalletAddress();
        
        if (empty($walletAddress)) {
            sendMessage($chatId, "⚠️ لم يتم إعداد عنوان المحفظة. يرجى التواصل مع المسؤول.");
            return;
        }
        
        $msg = "🏧 <b>إيداع USDT</b>\n\n";
        $msg .= "📋 للعملية:\n";
        $msg .= "- العملة: USDT\n";
        $msg .= "- الشبكة: TRC20\n\n";
        $msg .= "🎯 أرسل USDT على العنوان التالي:\n\n";
        $msg .= "<code>$walletAddress</code>\n\n";
        $msg .= "⚠️ تأكد من إرسال USDT TRC20 فقط!\n";
        $msg .= "💡 بعد الإرسال، أرسل المبلغ المراد إيداعه للتحقق.";
        
        file_put_contents(__DIR__ . "/state_$userId.json", json_encode(['step' => 'deposit_amount', 'wallet' => $walletAddress]));
        
        sendMessage($chatId, $msg);
        return;
    }
    
    if ($text === '📊 سجل السحوبات' || $text === '/history') {
        $withdrawals = getUserWithdrawals($userId);
        
        if (empty($withdrawals)) {
            sendMessage($chatId, "📭 لا توجد سحبات سابقة.");
            return;
        }
        
        $msg = "📊 <b>سجل السحوبات</b>\n\n";
        
        foreach ($withdrawals as $w) {
            $status = $w->status === 'completed' ? '✅' : ($w->status === 'rejected' ? '❌' : '⏳');
            $msg .= "━━━━━━━━━━━━━━━━\n";
            $msg .= "💰 المبلغ: {$w->amount} USDT\n";
            $msg .= "📍 العنوان: <code>" . substr($w->wallet_address, 0, 10) . "...</code>\n";
            $msg .= "📊 الحالة: {$status} " . ucfirst($w->status) . "\n";
            $msg .= "📅 التاريخ: {$w->created_at}\n";
            if ($w->tx_hash) {
                $msg .= "🔗 TX: <code>" . substr($w->tx_hash, 0, 10) . "...</code>\n";
            }
        }
        
        sendMessage($chatId, $msg);
        return;
    }
    
    if ($text === '❓ مساعدة' || $text === '/help') {
        $msg = "❓ <b>مساعدة</b>\n\n";
        $msg .= "📌 كيفية السحب:\n";
        $msg .= "1. اضغط على 'سحب USDT'\n";
        $msg .= "2. أدخل عنوان محفظتك (TRC20)\n";
        $msg .= "3. أدخل المبلغ المراد سحبه\n";
        $msg .= "4. Confirm the details and confirm\n\n";
        $msg .= "💳 العمولات:\n";
        $msg .= "-Network Fee: $1\n";
        $msg .= "-Deposit Fee: 4%\n\n";
        $msg .= "📧 For support, contact the admin.";
        
        sendMessage($chatId, $msg);
        return;
    }
    
    $stateFile = __DIR__ . "/state_$userId.json";
    if (file_exists($stateFile)) {
        $state = json_decode(file_get_contents($stateFile), true);
        
        if ($state['step'] === 'withdraw_wallet') {
            if (strlen($text) < 26 || substr($text, 0, 1) !== 'T') {
                sendMessage($chatId, "❌ عنوان محفظة TRC20 غير صالح. يجب أن يبدأ بـ 'T' ويكون 26 حرفاً على الأقل.");
                return;
            }
            
            $state = [
                'step' => 'withdraw_amount',
                'wallet' => $text,
            ];
            file_put_contents($stateFile, json_encode($state));
            
            $fees = getFeeSettings();
            
            $msg = "✅ تم حفظ العنوان: <code>$text</code>\n\n";
            $msg .= "💰 أدخل المبلغ المراد سحبه (USDT):\n\n";
            $msg .= "📊 ملاحظة:\n";
            $msg .= "-Network Fee: \${$fees['network_fee']}\n";
            $msg .= "-Deposit Fee: {$fees['deposit_fee_percent']}%";
            
            sendMessage($chatId, $msg);
            return;
        }
        
        // إيداع - إدخال المبلغ للتحقق
        if ($state['step'] === 'deposit_amount') {
            $amount = (float) $text;
            
            if ($amount <= 0) {
                sendMessage($chatId, "❌ المبلغ يجب أن يكون أكبر من صفر.");
                return;
            }
            
            if ($amount < 1) {
                sendMessage($chatId, "❌ الحد الأدنى للإيداع هو 1 USDT.");
                return;
            }
            
            // حفظ الإيداع
            $wallet = $state['wallet'];
            
            $msg = "✅ <b>تم تسجيل الإيداع!</b>\n\n";
            $msg .= "💰 المبلغ: $amount USDT\n";
            $msg .= "📍 العنوان: <code>$wallet</code>\n";
            $msg .= "📅 التاريخ: " . date('Y-m-d H:i:s') . "\n\n";
            $msg .= "⏳ في انتظار التحويل...\n\n";
            $msg .= "💡 سيتم إشعارك عند اكتمال التحقق.";
            
            // إشعار الأدمن
            $adminId = getSetting('admin_telegram_id', '');
            if ($adminId) {
                $adminMsg = "🏧 <b>إيداع USDT جديد!</b>\n\n";
                $adminMsg .= "👤 المستخدم: $userId\n";
                $adminMsg .= "💰 المبلغ: $amount USDT\n";
                $adminMsg .= "📍 العنوان: <code>$wallet</code>\n";
                $adminMsg .= "🆔 Chat ID: $chatId";
                
                sendMessage($adminId, $adminMsg);
            }
            
            sendMessage($chatId, $msg, mainMenuKeyboard());
            @unlink($stateFile);
            return;
        }
        
        if ($state['step'] === 'withdraw_amount') {
            $amount = (float) $text;
            
            if ($amount <= 0) {
                sendMessage($chatId, "❌ المبلغ يجب أن يكون أكبر من صفر.");
                return;
            }
            
            if ($amount < 10) {
                sendMessage($chatId, "❌ الحد الأدنى للسحب هو 10 USDT.");
                return;
            }
            
            $fees = getFeeSettings();
            $calc = calculateFees($amount, $fees['network_fee'], $fees['deposit_fee_percent']);
            
            $state['amount'] = $amount;
            $state['step'] = 'confirm';
            file_put_contents($stateFile, json_encode($state));
            
            $msg = "📋 <b>تفاصيل السحب</b>\n\n";
            $msg .= "━━━━━━━━━━━━━━━━\n";
            $msg .= "📍 العنوان: <code>{$state['wallet']}</code>\n";
            $msg .= "💰 المبلغ المطلوب: {$amount} USDT\n";
            $msg .= "🔗 الشبكة: TRC20\n";
            $msg .= "━━━━━━━━━━━━━━━━\n";
            $msg .= "💵 العمولات:\n";
            $msg .= "• Network Fee: {$calc['network_fee']} USDT\n";
            $msg .= "• Deposit Fee ({$calc['deposit_fee_percent']}%): {$calc['deposit_fee']} USDT\n";
            $msg .= "━━━━━━━━━━━━━━━━\n";
            $msg .= "✅ <b>المبلغ الذي سيستلمه العميل: {$calc['client_receives']} USDT</b>\n";
            $msg .= "━━━━━━━━━━━━━━━━\n\n";
            $msg .= "✅ Confirm the withdrawal?";
            
            $keyboard = [
                'inline_keyboard' => [
                    [['text' => '✅ Confirm', 'callback_data' => 'confirm_withdraw_' . $userId], ['text' => '❌ Cancel', 'callback_data' => 'cancel_withdraw_' . $userId]],
                ]
            ];
            
            sendMessage($chatId, $msg, $keyboard);
            return;
        }
    }
}

// Execute automatic withdrawal via MEXC
function executeWithdrawal($withdrawalId, $telegramUserId, $walletAddress, $amount, $chatId) {
    global $capsule;
    
    // Get wallet address from settings (where to withdraw TO - client's wallet)
    $fromWallet = getSetting('wallet_address', '');
    
    if (empty($fromWallet)) {
        return ['ok' => false, 'error' => 'No wallet configured'];
    }
    
    // Execute withdrawal via MEXC
    $result = withdrawViaMexc($walletAddress, $amount);
    
    if ($result['ok']) {
        // Update withdrawal status
        $capsule->table('withdrawals')
            ->where('id', $withdrawalId)
            ->update([
                'status' => 'processing',
                'tx_hash' => $result['txid'] ?? '',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        
        // Notify user
        $msg = "🔄 <b>جاري التحويل...</b>\n\n";
        $msg .= "💰 المبلغ: $amount USDT\n";
        $msg .= "📍 إلى العنوان: <code>$walletAddress</code>\n";
        $msg .= "🔗 TX: <code>{$result['txid']}</code>";
        
        sendMessage($chatId, $msg);
        
        return ['ok' => true, 'txid' => $result['txid']];
    } else {
        // Update status to failed
        $capsule->table('withdrawals')
            ->where('id', $withdrawalId)
            ->update([
                'status' => 'failed',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        
        // Notify admin
        $adminId = getSetting('admin_telegram_id', '');
        if ($adminId) {
            sendMessage($adminId, "❌ فشل التحويل: " . ($result['error'] ?? 'Unknown error'));
        }
        
        return $result;
    }
}

function processCallback($callback) {
    $chatId = $callback['message']['chat']['id'];
    $userId = (string) $callback['from']['id'];
    $data = $callback['data'];
    
    $stateFile = __DIR__ . "/state_$userId.json";
    
    if (strpos($data, 'confirm_withdraw_') === 0) {
        if (!file_exists($stateFile)) {
            sendMessage($chatId, "❌ No pending withdrawal request.");
            return;
        }
        
        $state = json_decode(file_get_contents($stateFile), true);
        
        if ($state['step'] !== 'confirm') {
            sendMessage($chatId, "❌ Invalid request.");
            return;
        }
        
        $fees = getFeeSettings();
        $amount = $state['amount'];
        $wallet = $state['wallet'];
        
        $calc = calculateFees($amount, $fees['network_fee'], $fees['deposit_fee_percent']);
        
        // Store the amount that will be sent (what client receives)
        $id = saveWithdrawal($userId, $wallet, $amount, $fees['network_fee'], $fees['deposit_fee_percent'], $calc['client_receives']);
        
        unlink($stateFile);
        
        $msg = "✅ <b>تم تقديم طلب السحب بنجاح!</b>\n\n";
        $msg .= "📋 رقم الطلب: #$id\n";
        $msg .= "💰 المبلغ المطلوب: $amount USDT\n";
        $msg .= "📍 العنوان: <code>$wallet</code>\n";
        $msg .= "💵 العمولات: {$calc['network_fee']} + {$calc['deposit_fee']} = " . ($calc['network_fee'] + $calc['deposit_fee']) . " USDT\n";
        $msg .= "✅ <b>المبلغ الذي سيستلمه العميل: {$calc['client_receives']} USDT</b>\n\n";
        $msg .= "⏳ الحالة: في الانتظار\n\n";
        $msg .= "🔔 جاري التحويل تلقائياً...";
        
        sendMessage($chatId, $msg);
        
        // Execute automatic withdrawal - send the amount client will receive
        $withdrawResult = executeWithdrawal($id, $userId, $wallet, $calc['client_receives'], $chatId);
        
        if (!$withdrawResult['ok']) {
            $msg = "❌ <b>فشل التحويل!</b>\n\n";
            $msg .= "السبب: " . ($withdrawResult['error'] ?? 'خطأ غير معروف');
            $msg .= "\n\nسيتم إشعارك لاحقاً.";
            sendMessage($chatId, $msg, mainMenuKeyboard());
        }
        
        $adminId = getSetting('admin_telegram_id', '');
        if ($adminId) {
            $statusIcon = $withdrawResult['ok'] ? '✅' : '❌';
            $adminMsg = "$statusIcon <b>New Withdrawal!</b>\n\n";
            $adminMsg .= "👤 User: $userId\n";
            $adminMsg .= "💰 Requested: $amount USDT\n";
            $adminMsg .= "💵 Fees: " . ($calc['network_fee'] + $calc['deposit_fee']) . " USDT\n";
            $adminMsg .= "📤 Sent to client: {$calc['client_receives']} USDT\n";
            $adminMsg .= "📍 Wallet: <code>$wallet</code>\n";
            $adminMsg .= "🆔 Withdrawal ID: #$id";
            if ($withdrawResult['ok']) {
                $adminMsg .= "\n🔗 TX: <code>{$withdrawResult['txid']}</code>";
            }
            
            sendMessage($adminId, $adminMsg);
        }
        
        return;
    }
    
    if (strpos($data, 'cancel_withdraw_') === 0) {
        if (file_exists($stateFile)) {
            unlink($stateFile);
        }
        
        sendMessage($chatId, "❌ تم إلغاء طلب السحب.", mainMenuKeyboard());
        return;
    }
}

echo "🤖 Bot started. Waiting for messages...\n";
echo "Press Ctrl+C to stop.\n\n";

$offset = 0;

while (true) {
    try {
        $response = file_get_contents($apiUrl . "/getUpdates?offset=$offset&timeout=60");
        $updates = json_decode($response, true);
        
        if ($updates['ok'] && !empty($updates['result'])) {
            foreach ($updates['result'] as $update) {
                $offset = $update['update_id'] + 1;
                
                if (isset($update['callback_query'])) {
                    processCallback($update['callback_query']);
                } elseif (isset($update['message'])) {
                    processMessage($update['message']);
                }
            }
        }
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
        sleep(5);
    }
}