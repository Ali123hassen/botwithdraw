<?php
/**
 * USDT Withdrawal Telegram Bot
 * Single user bot with fee calculation
 * 
 * Usage: Set BOT_TOKEN and ALLOWED_USER_ID in .env or settings
 * Run with: php bot/webhook.php
 */

use Dotenv\Dotenv;

require __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// Database connection
$databasePath = __DIR__ . '/../database/database.sqlite';

if (!file_exists($databasePath)) {
    die("Database not found. Please run migrations.\n");
}

try {
    $pdo = new PDO("sqlite:$databasePath");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage() . "\n");
}

// Telegram Bot Configuration
$botToken = getenv('TELEGRAM_BOT_TOKEN') ?: '';
$apiUrl = "https://api.telegram.org/bot$botToken";

// Allowed user ID (set in admin panel)
function getSetting($pdo, $key, $default = '') {
    $stmt = $pdo->prepare("SELECT value FROM settings WHERE key = ?");
    $stmt->execute([$key]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result ? $result['value'] : $default;
}

function getFeeSettings($pdo) {
    return [
        'network_fee' => (float) getSetting($pdo, 'network_fee', 1),
        'deposit_fee_percent' => (float) getSetting($pdo, 'deposit_fee_percent', 4),
    ];
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
    curl_exec($ch);
    curl_close($ch);
}

function sendPhoto($chatId, $photoUrl, $caption = '') {
    global $apiUrl;
    
    $data = [
        'chat_id' => $chatId,
        'photo' => $photoUrl,
        'caption' => $caption,
        'parse_mode' => 'HTML',
    ];
    
    $ch = curl_init($apiUrl . '/sendPhoto');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch);
    curl_close($ch);
}

// Calculate fees
function calculateFees($amount, $networkFee, $depositFeePercent) {
    $depositFee = $amount * ($depositFeePercent / 100);
    $totalDeducted = $amount + $networkFee + $depositFee;
    
    return [
        'amount' => $amount,
        'network_fee' => $networkFee,
        'deposit_fee_percent' => $depositFeePercent,
        'deposit_fee' => $depositFee,
        'total_deducted' => $totalDeducted,
    ];
}

// Save withdrawal request
function saveWithdrawal($pdo, $userId, $walletAddress, $amount, $networkFee, $depositFeePercent, $totalDeducted) {
    $stmt = $pdo->prepare("
        INSERT INTO withdrawals 
        (telegram_user_id, wallet_address, amount, network_fee, deposit_fee_percent, total_deducted, network, currency, status, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, 'TRC20', 'USDT', 'pending', datetime('now'), datetime('now'))
    ");
    
    $stmt->execute([
        $userId,
        $walletAddress,
        $amount,
        $networkFee,
        $depositFeePercent,
        $totalDeducted
    ]);
    
    return $pdo->lastInsertId();
}

// Get user withdrawals history
function getUserWithdrawals($pdo, $userId, $limit = 5) {
    $stmt = $pdo->prepare("
        SELECT * FROM withdrawals 
        WHERE telegram_user_id = ?
        ORDER BY created_at DESC
        LIMIT ?
    ");
    $stmt->execute([$userId, $limit]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get wallet address from settings
function getWalletAddress($pdo) {
    return getSetting($pdo, 'wallet_address', '');
}

// Main menu keyboard
function mainMenuKeyboard() {
    return [
        'keyboard' => [
            [['text' => '💰 سحب USDT'], ['text' => '📊 سجل السحوبات']],
            [['text' => '❓ مساعدة']],
        ],
        'resize_keyboard' => true,
    ];
}

// Process incoming update
function processUpdate($update, $pdo) {
    if (!isset($update['message'])) {
        return;
    }
    
    $message = $update['message'];
    $chatId = $message['chat']['id'];
    $userId = (string) $message['from']['id'];
    $text = $message['text'] ?? '';
    $firstName = $message['from']['first_name'] ?? 'User';
    
    // Get allowed user ID
    $allowedUserId = getSetting($pdo, 'allowed_user_id', '');
    
    // Check if user is allowed
    if ($allowedUserId && $userId !== $allowedUserId) {
        sendMessage($chatId, "⛔ عذراً، ليس لديك صلاحية استخدام هذا البوت.");
        return;
    }
    
    // Commands
    if ($text === '/start' || $text === 'الرئيسية') {
        $welcomeMsg = "🎉 مرحباً بك في بوت سحب USDT!\n\n";
        $welcomeMsg .= "👤 المستخدم: $firstName\n";
        $welcomeMsg .= "🆔 ID: $userId\n\n";
        $welcomeMsg .= "اختر من القائمة أدناه:";
        
        sendMessage($chatId, $welcomeMsg, mainMenuKeyboard());
        return;
    }
    
    if ($text === '💰 سحب USDT' || $text === '/withdraw') {
        $walletAddress = getWalletAddress($pdo);
        
        if (empty($walletAddress)) {
            sendMessage($chatId, "⚠️ لم يتم إعداد عنوان المحفظة. يرجى التواصل مع المسؤول.");
            return;
        }
        
        $msg = "💸 <b>سحب USDT</b>\n\n";
        $msg .= "📋 للعملية:\n";
        $msg .= "- العملة: USDT\n";
        $msg .= "- الشبكة: TRC20\n\n";
        $msg .= "📝 أدخل عنوان محفظتك (TRC20):";
        
        // Store step in database or session (simple approach using user state file)
        file_put_contents(__DIR__ . "/state_$userId.json", json_encode(['step' => 'wallet']));
        
        sendMessage($chatId, $msg);
        return;
    }
    
    if ($text === '📊 سجل السحوبات' || $text === '/history') {
        $withdrawals = getUserWithdrawals($pdo, $userId);
        
        if (empty($withdrawals)) {
            sendMessage($chatId, "📭 لا توجد سحبات سابقة.");
            return;
        }
        
        $msg = "📊 <b>سجل السحوبات</b>\n\n";
        
        foreach ($withdrawals as $w) {
            $status = $w['status'] === 'completed' ? '✅' : ($w['status'] === 'rejected' ? '❌' : '⏳');
            $msg .= "━━━━━━━━━━━━━━━━\n";
            $msg .= "💰 المبلغ: {$w['amount']} USDT\n";
            $msg .= "📍 العنوان: <code>" . substr($w['wallet_address'], 0, 10) . "...</code>\n";
            $msg .= "📊 الحالة: {$status} " . ucfirst($w['status']) . "\n";
            $msg .= "📅 التاريخ: {$w['created_at']}\n";
            if ($w['tx_hash']) {
                $msg .= "🔗 TX: <code>" . substr($w['tx_hash'], 0, 10) . "...</code>\n";
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
    
    // Handle conversation flow
    $stateFile = __DIR__ . "/state_$userId.json";
    if (file_exists($stateFile)) {
        $state = json_decode(file_get_contents($stateFile), true);
        
        if ($state['step'] === 'wallet') {
            // Validate wallet address (TRC20 starts with T)
            if (strlen($text) < 26 || substr($text, 0, 1) !== 'T') {
                sendMessage($chatId, "❌ عنوان محفظة TRC20 غير صالح. يجب أن يبدأ بـ 'T' ويكون 26 حرفاً على الأقل.");
                return;
            }
            
            $state = [
                'step' => 'amount',
                'wallet' => $text,
            ];
            file_put_contents($stateFile, json_encode($state));
            
            $fees = getFeeSettings($pdo);
            
            $msg = "✅ تم حفظ العنوان: <code>$text</code>\n\n";
            $msg .= "💰 أدخل المبلغ المراد سحبه (USDT):\n\n";
            $msg .= "📊 ملاحظة:\n";
            $msg .= "-Network Fee: \${$fees['network_fee']}\n";
            $msg .= "-Deposit Fee: {$fees['deposit_fee_percent']}%";
            
            sendMessage($chatId, $msg);
            return;
        }
        
        if ($state['step'] === 'amount') {
            $amount = (float) $text;
            
            if ($amount <= 0) {
                sendMessage($chatId, "❌ المبلغ يجب أن يكون أكبر من صفر.");
                return;
            }
            
            if ($amount < 10) {
                sendMessage($chatId, "❌ الحد الأدنى للسحب هو 10 USDT.");
                return;
            }
            
            $fees = getFeeSettings($pdo);
            $calc = calculateFees($amount, $fees['network_fee'], $fees['deposit_fee_percent']);
            
            $state['amount'] = $amount;
            $state['step'] = 'confirm';
            file_put_contents($stateFile, json_encode($state));
            
            $msg = "📋 <b>تفاصيل السحب</b>\n\n";
            $msg .= "━━━━━━━━━━━━━━━━\n";
            $msg .= "📍 العنوان: <code>{$state['wallet']}</code>\n";
            $msg .= "💰 المبلغ: {$amount} USDT\n";
            $msg .= "🔗 الشبكة: TRC20\n";
            $msg .= "━━━━━━━━━━━━━━━━\n";
            $msg .= "💵 العمولات:\n";
            $msg .= "• Network Fee: {$calc['network_fee']} USDT\n";
            $msg .= "• Deposit Fee ({$calc['deposit_fee_percent']}%): {$calc['deposit_fee']} USDT\n";
            $msg .= "━━━━━━━━━━━━━━━━\n";
            $msg .= "📊 <b>الإجمالي المخصوم: {$calc['total_deducted']} USDT</b>\n";
            $msg .= "━━━━━━━━━━━━━━━━\n\n";
            $msg .= "✅ Confirmer le retrait?";
            
            $keyboard = [
                'inline_keyboard' => [
                    [['text' => '✅ Confirm', 'callback_data' => 'confirm_withdraw'], ['text' => '❌ Annuler', 'callback_data' => 'cancel_withdraw']],
                ]
            ];
            
            sendMessage($chatId, $msg, $keyboard);
            return;
        }
    }
}

// Handle callback queries (inline buttons)
function processCallbackQuery($callback, $pdo) {
    $chatId = $callback['message']['chat']['id'];
    $userId = (string) $callback['from']['id'];
    $data = $callback['data'];
    
    $stateFile = __DIR__ . "/state_$userId.json";
    
    if ($data === 'confirm_withdraw') {
        if (!file_exists($stateFile)) {
            sendMessage($chatId, "❌ No pending withdrawal request.");
            return;
        }
        
        $state = json_decode(file_get_contents($stateFile), true);
        
        if ($state['step'] !== 'confirm') {
            sendMessage($chatId, "❌ Invalid request.");
            return;
        }
        
        $fees = getFeeSettings($pdo);
        $amount = $state['amount'];
        $wallet = $state['wallet'];
        
        $calc = calculateFees($amount, $fees['network_fee'], $fees['deposit_fee_percent']);
        
        // Save withdrawal
        $id = saveWithdrawal($pdo, $userId, $wallet, $amount, $fees['network_fee'], $fees['deposit_fee_percent'], $calc['total_deducted']);
        
        // Clean state
        unlink($stateFile);
        
        $msg = "✅ <b>تم تقديم طلب السحب بنجاح!</b>\n\n";
        $msg .= "📋 رقم الطلب: #$id\n";
        $msg .= "💰 المبلغ: $amount USDT\n";
        $msg .= "📍 العنوان: <code>$wallet</code>\n";
        $msg .= "📊 الإجمالي المخصوم: {$calc['total_deducted']} USDT\n\n";
        $msg .= "⏳ Status: En attente\n\n";
        $msg .= "🔔 You will be notified once processed.";
        
        sendMessage($chatId, $msg, mainMenuKeyboard());
        
        // Notify admin (optional - implement if webhook URL is set)
        $adminId = getSetting($pdo, 'admin_telegram_id', '');
        if ($adminId) {
            $adminMsg = "🔔 <b>Nouveau retrait!</b>\n\n";
            $adminMsg .= "👤 User: $userId\n";
            $adminMsg .= "💰 Amount: $amount USDT\n";
            $adminMsg .= "📍 Wallet: <code>$wallet</code>\n";
            $adminMsg .= "📊 Total: {$calc['total_deducted']} USDT\n";
            $adminMsg .= "🆔 Withdrawal ID: #$id";
            
            sendMessage($adminId, $adminMsg);
        }
        
        return;
    }
    
    if ($data === 'cancel_withdraw') {
        if (file_exists($stateFile)) {
            unlink($stateFile);
        }
        
        sendMessage($chatId, "❌ تم إلغاء طلب السحب.", mainMenuKeyboard());
        return;
    }
}

// Webhook handler
$input = file_get_contents('php://input');
$update = json_decode($input, true);

if ($update) {
    if (isset($update['callback_query'])) {
        processCallbackQuery($update['callback_query'], $pdo);
    } else {
        processUpdate($update, $pdo);
    }
}

echo "OK";