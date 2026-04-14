# USDT Withdrawal Bot - Documentation

## نظرة عامة

نظام كامل لسحب USDT مع بوت تليجرام ولوحة تحكم لارافيل لعميل واحد فقط.

## الملفات الرئيسية

```
usdt-wallet/
├── bot/
│   ├── run.php      # تشغيل البوت (long-polling)
│   └── webhook.php  # استخدام Webhook
├── app/
│   ├── Http/Controllers/
│   │   ├── WithdrawalController.php
│   │   └── SettingsController.php
│   └── Models/
│       ├── Withdrawal.php
│       └── Setting.php
├── database/
│   ├── migrations/  # جداول قاعدة البيانات
│   └── database.sqlite  # قاعدة البيانات
└── resources/views/admin/
    ├── layout.blade.php
    ├── withdrawals.blade.php
    └── settings.blade.php
```

## الإعداد

### 1. بيانات .env

```env
TELEGRAM_BOT_TOKEN=your_bot_token
```

### 2. لوحة التحكم

اذهب إلى: `http://localhost:8000/admin/withdrawals`

### 3. الإعدادات المطلوبة

- **Network Fee**: 1$ (افتراضي)
- **Deposit Fee %**: 4% (افتراضي)
- **Wallet Address**: عنوان محفظتك TRC20
- **Telegram Bot Token**: من @BotFather
- **Allowed User ID**: ID المستخدم المسموح

## تشغيل البوت

```bash
cd /workspace/project/usdt-wallet
php bot/run.php
```

## طريقة حساب العمولات

```
المبلغ المطلوب = X USDT
Network Fee = 1 USDT
Deposit Fee = X * 4% = X * 0.04
الإجمالي المخصوم = X + 1 + (X * 0.04)
```

مثال: سحب 100 USDT
- Network Fee: 1 USDT
- Deposit Fee: 4 USDT (4%)
- الإجمالي: 105 USDT

## أوامر البوت

- `/start` - بدء البوت
- `💰 سحب USDT` - طلب سحب
- `📊 سجل السحوبات` - عرض السجل
- `❓ مساعدة` - للمساعدة