# رفع مشکل خطای SEP در مرورگرهای قدیمی

## مشکل
در برخی مرورگرهای قدیمی، هنگام پرداخت از طریق درگاه SEP، خطای زیر نمایش داده می‌شد:
```
عدم همخوانی آدرس اجرا دهنده به آدرسهای تعریف شده (پارامترهای ارسال شده نا معتبر است)
```

## علت مشکل
این خطا معمولاً به دلایل زیر رخ می‌دهد:

1. **مشکل در ارسال Referer Header**: مرورگرهای قدیمی ممکن است Referer header را به درستی ارسال نکنند یا آن را تغییر دهند
2. **مشکل در Encoding URL**: مرورگرهای قدیمی ممکن است URL را به صورت متفاوتی encode کنند
3. **اضافه شدن Query Parameters**: برخی مرورگرهای قدیمی ممکن است query parameters اضافی به URL اضافه کنند
4. **مشکل در Port Numbers**: مرورگرهای قدیمی ممکن است port number را به URL اضافه کنند
5. **مشکل HTTP vs HTTPS**: برخی مرورگرهای قدیمی ممکن است HTTP را به جای HTTPS ارسال کنند

## راه‌حل‌های پیاده‌سازی شده

### 1. بهبود Referrer Policy
- تغییر `referrer policy` از `origin-when-cross-origin` به `origin` در فایل HTML
- این تغییر باعث می‌شود که Referer header به درستی با دامنه اصلی ارسال شود

**فایل‌های تغییر یافته:**
- `weekilaw_frontend/index.html`
- `weekilaw_frontend/dist/index.html`

### 2. بهبود URL Normalization
- اضافه شدن منطق نرمال‌سازی URL در `SEPPaymentService`
- حذف trailing slash
- اطمینان از استفاده از HTTPS
- حذف port numbers پیش‌فرض (80, 443)
- حذف query parameters و fragments (SEP آنها را مجاز نمی‌داند)
- lowercase کردن hostname

**فایل تغییر یافته:**
- `weekilaw_backend/app/Services/SEPPaymentService.php`

### 3. بهبود روش Redirect
- استفاده از `window.location.replace` به جای `window.location.href` برای سازگاری بهتر با مرورگرهای قدیمی
- اضافه شدن fallback به `window.location.href` در صورت خطا
- اطمینان از تنظیم referrer meta tag قبل از redirect

**فایل تغییر یافته:**
- `weekilaw_frontend/src/components/AddMoneyModal.jsx`

## نکات مهم

### 1. Whitelist در پنل SEP
- مطمئن شوید که callback URL دقیقاً همان چیزی است که در پنل SEP whitelist شده است
- URL باید بدون trailing slash، بدون query parameters، و با HTTPS باشد

### 2. بررسی Logs
- در صورت بروز مشکل، لاگ‌های زیر را بررسی کنید:
  - `SEP Callback URL being sent` - برای دیدن URL نرمال‌سازی شده
  - `SEP Payment Request Response` - برای دیدن پاسخ SEP

### 3. تست در مرورگرهای مختلف
- تست در مرورگرهای قدیمی (IE11, Chrome قدیمی، Firefox قدیمی)
- تست در مرورگرهای موبایل قدیمی

## مثال URL صحیح
```
https://payment.weekilaw.com/api/payment/payment-listener
```

## مثال URL نادرست (که باعث خطا می‌شود)
```
https://payment.weekilaw.com/api/payment/payment-listener/  (trailing slash)
https://payment.weekilaw.com:443/api/payment/payment-listener  (port number)
http://payment.weekilaw.com/api/payment/payment-listener  (HTTP به جای HTTPS)
https://payment.weekilaw.com/api/payment/payment-listener?param=value  (query parameter)
```

## تغییرات در کد

### SEPPaymentService.php
- اضافه شدن منطق parse_url برای نرمال‌سازی
- حذف query parameters و fragments
- اطمینان از HTTPS
- حذف port numbers پیش‌فرض

### AddMoneyModal.jsx
- استفاده از `window.location.replace`
- اضافه شدن try-catch برای fallback
- اطمینان از تنظیم referrer meta tag

### index.html
- تغییر referrer policy به `origin`

## تست
پس از اعمال تغییرات، موارد زیر را تست کنید:
1. پرداخت در مرورگرهای مدرن (Chrome, Firefox, Safari جدید)
2. پرداخت در مرورگرهای قدیمی (IE11, Chrome قدیمی)
3. پرداخت در مرورگرهای موبایل
4. بررسی لاگ‌ها برای اطمینان از نرمال‌سازی صحیح URL
