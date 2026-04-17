<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TelegramController extends Controller
{
    protected $botToken;
    protected $apiUrl;
    
    public function __construct()
    {
        $this->botToken = config('services.telegram.bot_token');
        $this->apiUrl = "https://api.telegram.org/bot{$this->botToken}";
    }
    
    /**
     * Test route (GET) - للاختبار فقط
     */
    public function test()
    {
        return response()->json([
            'ok' => true, 
            'message' => 'Webhook is working!',
            'bot_token_configured' => !empty($this->botToken),
            'bot_token' => $this->botToken ? substr($this->botToken, 0, 10) . '...' : 'NOT SET'
        ]);
    }
    
    /**
     * Handle incoming Telegram webhook
     */
    public function handle(Request $request)
    {
        // للتشخيص
        \Log::info('Telegram webhook received', $request->all());
        
        // Check if bot token is configured
        if (empty($this->botToken)) {
            \Log::error('Telegram bot token is not configured!');
            return response()->json(['ok' => false, 'error' => 'Bot token not configured']);
        }
        
        $update = $request->all();
        
        if (empty($update)) {
            \Log::warning('Empty update received');
            return response()->json(['ok' => true, 'message' => 'Webhook is working!']);
        }
        
        if (isset($update['callback_query'])) {
            $this->processCallbackQuery($update['callback_query']);
        } elseif (isset($update['message'])) {
            $this->processMessage($update['message']);
        }
        
        return response()->json(['ok' => true]);
    }
    
    /**
     * Process incoming messages
     */
    protected function processMessage($message)
    {
        $chatId = $message['chat']['id'];
        $userId = (string) $message['from']['id'];
        $text = $message['text'] ?? '';
        $firstName = $message['from']['first_name'] ?? 'User';
        
        // Check if user is allowed
        $allowedUserId = $this->getSetting('allowed_user_id', '');
        
        if ($allowedUserId && $userId !== $allowedUserId) {
            $this->sendMessage($chatId, "⛔ عذراً، ليس لديك صلاحية استخدام هذا البوت.");
            return;
        }
        
        // Commands
        if ($text === '/start' || $text === 'الرئيسية') {
            $welcomeMsg = "🎉 مرحباً بك في بوت سحب USDT!\n\n";
            $welcomeMsg .= "👤 المستخدم: $firstName\n";
            $welcomeMsg .= "🆔 ID: $userId\n\n";
            $welcomeMsg .= "اختر من القائمة أدناه:";
            
            $this->sendMessage($chatId, $welcomeMsg, $this->mainMenuKeyboard());
            return;
        }
        
        if ($text === '💰 سحب USDT' || $text === '/withdraw') {
            $walletAddress = $this->getSetting('wallet_address', '');
            
            if (empty($walletAddress)) {
                $this->sendMessage($chatId, "⚠️ لم يتم إعداد عنوان المحفظة. يرجى التواصل مع المسؤول.");
                return;
            }
            
            $msg = "💸 <b>سحب USDT</b>\n\n";
            $msg .= "📋 للعملية:\n";
            $msg .= "- العملة: USDT\n";
            $msg .= "- الشبكة: TRX\n\n";
            $msg .= "📝 أدخل عنوان محفظتك (TRX):";
            
            // Store step in session
            session(['telegram_state_' . $userId => ['step' => 'withdraw_wallet']]);
            
            $this->sendMessage($chatId, $msg);
            return;
        }
        
        if ($text === '📊 سجل السحوبات' || $text === '/history') {
            $withdrawals = DB::table('withdrawals')
                ->where('telegram_user_id', $userId)
                ->orderBy('created_at', 'desc')
                ->limit(5)
                ->get();
            
            if ($withdrawals->isEmpty()) {
                $this->sendMessage($chatId, "📭 لا توجد سحبات سابقة.");
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
            
            $this->sendMessage($chatId, $msg);
            return;
        }
        
        if ($text === '❓ مساعدة' || $text === '/help') {
            $fees = $this->getFeeSettings();
            
            $msg = "❓ <b>مساعدة</b>\n\n";
            $msg .= "📌 كيفية السحب:\n";
            $msg .= "1. اضغط على 'سحب USDT'\n";
            $msg .= "2. أدخل عنوان محفظتك (TRX)\n";
            $msg .= "3. أدخل المبلغ المراد سحبه\n";
            $msg .= "4. Confirm the details and confirm\n\n";
            $msg .= "💳 العمولات:\n";
            $msg .= "- Network Fee: \${$fees['network_fee']}\n";
            $msg .= "- Deposit Fee: {$fees['deposit_fee_percent']}%\n\n";
            $msg .= "📧 For support, contact the admin.";
            
            $this->sendMessage($chatId, $msg);
            return;
        }
        
        // Handle conversation flow
        $stateKey = 'telegram_state_' . $userId;
        $state = session($stateKey);
        
        if ($state) {
            if ($state['step'] === 'withdraw_wallet') {
                // Validate wallet address
                if (strlen($text) < 26 || substr($text, 0, 1) !== 'T') {
                    $this->sendMessage($chatId, "❌ عنوان محفظة TRX غير صالح. يجب أن يبدأ بـ 'T' ويكون 26 حرفاً على الأقل.");
                    return;
                }
                
                session([$stateKey => [
                    'step' => 'withdraw_amount',
                    'wallet' => $text,
                ]]);
                
                $fees = $this->getFeeSettings();
                
                $msg = "✅ تم حفظ العنوان: <code>$text</code>\n\n";
                $msg .= "💰 أدخل المبلغ المراد سحبه (USDT):\n\n";
                $msg .= "📊 ملاحظة:\n";
                $msg .= "- Network Fee: \${$fees['network_fee']}\n";
                $msg .= "- Deposit Fee: {$fees['deposit_fee_percent']}%";
                
                $this->sendMessage($chatId, $msg);
                return;
            }
            
            if ($state['step'] === 'withdraw_amount') {
                $amount = (float) $text;
                
                if ($amount <= 0) {
                    $this->sendMessage($chatId, "❌ المبلغ يجب أن يكون أكبر من صفر.");
                    return;
                }
                
                if ($amount < 10) {
                    $this->sendMessage($chatId, "❌ الحد الأدنى للسحب هو 10 USDT.");
                    return;
                }
                
                $fees = $this->getFeeSettings();
                $calc = $this->calculateFees($amount, $fees['network_fee'], $fees['deposit_fee_percent']);
                
                session([$stateKey => [
                    'step' => 'confirm',
                    'wallet' => $state['wallet'],
                    'amount' => $amount,
                ]]);
                
                $msg = "📋 <b>تفاصيل السحب</b>\n\n";
                $msg .= "━━━━━━━━━━━━━━━━\n";
                $msg .= "📍 العنوان: <code>{$state['wallet']}</code>\n";
                $msg .= "💰 المبلغ المطلوب: {$amount} USDT\n";
                $msg .= "🔗 الشبكة: TRX\n";
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
                
                $this->sendMessage($chatId, $msg, $keyboard);
                return;
            }
        }
    }
    
    /**
     * Process callback queries (inline buttons)
     */
    protected function processCallbackQuery($callback)
    {
        $chatId = $callback['message']['chat']['id'];
        $userId = (string) $callback['from']['id'];
        $data = $callback['data'];
        
        $stateKey = 'telegram_state_' . $userId;
        
        if (strpos($data, 'confirm_withdraw_') === 0) {
            $state = session($stateKey);
            
            if (!$state || $state['step'] !== 'confirm') {
                $this->sendMessage($chatId, "❌ No pending withdrawal request.");
                return;
            }
            
            $fees = $this->getFeeSettings();
            $amount = $state['amount'];
            $wallet = $state['wallet'];
            
            $calc = $this->calculateFees($amount, $fees['network_fee'], $fees['deposit_fee_percent']);
            
            // Save withdrawal to database
            $id = DB::table('withdrawals')->insertGetId([
                'telegram_user_id' => $userId,
                'wallet_address' => $wallet,
                'amount' => $amount,
                'network_fee' => $fees['network_fee'],
                'deposit_fee_percent' => $fees['deposit_fee_percent'],
                'total_deducted' => $calc['client_receives'],
                'network' => 'TRX',
                'currency' => 'USDT',
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            
            // Clear state
            session()->forget($stateKey);
            
            // Execute withdrawal via MEXC
            $result = $this->executeWithdrawal($id, $userId, $wallet, $calc['client_receives'], $chatId);
            
            if ($result['ok']) {
                $msg = "✅ <b>تم التحويل بنجاح!</b>\n\n";
                $msg .= "📋 رقم الطلب: #$id\n";
                $msg .= "💰 المبلغ: $amount USDT\n";
                $msg .= "📍 العنوان: <code>$wallet</code>\n";
                $msg .= "🔗 TX: <code>{$result['txid']}</code>";
            } else {
                $msg = "❌ <b>فشل التحويل!</b>\n\n";
                $msg .= "السبب: " . ($result['error'] ?? 'خطأ غير معروف');
            }
            
            $this->sendMessage($chatId, $msg, $this->mainMenuKeyboard());
            
            // Notify admin
            $adminId = $this->getSetting('admin_telegram_id', '');
            if ($adminId) {
                $statusIcon = $result['ok'] ? '✅' : '❌';
                $adminMsg = "$statusIcon <b>New Withdrawal!</b>\n\n";
                $adminMsg .= "👤 User: $userId\n";
                $adminMsg .= "💰 Amount: $amount USDT\n";
                $adminMsg .= "📍 Wallet: <code>$wallet</code>\n";
                $adminMsg .= "🆔 Withdrawal ID: #$id";
                if ($result['ok']) {
                    $adminMsg .= "\n🔗 TX: <code>{$result['txid']}</code>";
                }
                
                $this->sendMessage($adminId, $adminMsg);
            }
            
            return;
        }
        
        if (strpos($data, 'cancel_withdraw_') === 0) {
            session()->forget($stateKey);
            $this->sendMessage($chatId, "❌ تم إلغاء طلب السحب.", $this->mainMenuKeyboard());
            return;
        }
    }
    
    /**
     * Execute withdrawal via MEXC API
     */
    protected function executeWithdrawal($withdrawalId, $telegramUserId, $walletAddress, $amount, $chatId)
    {
        $apiKey = $this->getSetting('mexc_api_key', '');
        $apiSecret = $this->getSetting('mexc_api_secret', '');
        
        if (empty($apiKey) || empty($apiSecret)) {
            return ['ok' => false, 'error' => 'MEXC API not configured'];
        }
        
        $baseUrl = 'https://api.mexc.com';
        $requestPath = '/api/v3/capital/withdraw';
        $timestamp = time() * 1000;
        
        // Build query params - sorted alphabetically
        $params = [
            'address' => $walletAddress,
            'amount' => (string) $amount,
            'coin' => 'USDT',
            'netWork' => 'TRX',
            'timestamp' => $timestamp,
            'recvWindow' => '5000',
        ];
        
        ksort($params);
        $queryString = http_build_query($params);
        
        // Signature: ONLY the query string - lowercase
        $signature = strtolower(hash_hmac('sha256', $queryString, $apiSecret));
        
        // Full URL
        $url = $baseUrl . $requestPath . '?' . $queryString . '&signature=' . $signature;
        
        // Make request
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'X-MEXC-APIKEY: ' . $apiKey,
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $result = json_decode($response, true);
        
        if ($httpCode === 200 && isset($result['id'])) {
            // Update withdrawal status
            DB::table('withdrawals')
                ->where('id', $withdrawalId)
                ->update([
                    'status' => 'completed',
                    'tx_hash' => $result['id'],
                    'updated_at' => now(),
                ]);
            
            return ['ok' => true, 'txid' => $result['id']];
        } else {
            // Update status to failed
            DB::table('withdrawals')
                ->where('id', $withdrawalId)
                ->update([
                    'status' => 'failed',
                    'updated_at' => now(),
                ]);
            
            return ['ok' => false, 'error' => $result['msg'] ?? 'Withdrawal failed'];
        }
    }
    
    /**
     * Calculate fees
     */
    protected function calculateFees($amount, $networkFee, $depositFeePercent)
    {
        $depositFee = ($amount - $networkFee) * ($depositFeePercent / 100);
        $clientReceives = $amount - $networkFee - $depositFee;
        
        return [
            'amount' => $amount,
            'network_fee' => $networkFee,
            'deposit_fee_percent' => $depositFeePercent,
            'deposit_fee' => $depositFee,
            'client_receives' => max(0, $clientReceives),
        ];
    }
    
    /**
     * Get settings from database
     */
    protected function getSetting($key, $default = '')
    {
        $result = DB::table('settings')->where('key', $key)->first();
        return $result ? $result->value : $default;
    }
    
    /**
     * Get fee settings
     */
    protected function getFeeSettings()
    {
        return [
            'network_fee' => (float) $this->getSetting('network_fee', 1),
            'deposit_fee_percent' => (float) $this->getSetting('deposit_fee_percent', 4),
        ];
    }
    
    /**
     * Send message to Telegram
     */
    protected function sendMessage($chatId, $text, $keyboard = null)
    {
        $data = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
        ];
        
        if ($keyboard) {
            $data['reply_markup'] = json_encode($keyboard);
        }
        
        file_get_contents($this->apiUrl . "/sendMessage?" . http_build_query($data));
    }
    
    /**
     * Main menu keyboard
     */
    protected function mainMenuKeyboard()
    {
        return [
            'keyboard' => [
                [['text' => '💰 سحب USDT'], ['text' => '🏧 إيداع USDT']],
                [['text' => '📊 سجل السحوبات'], ['text' => '❓ مساعدة']],
            ],
            'resize_keyboard' => true,
        ];
    }
}
