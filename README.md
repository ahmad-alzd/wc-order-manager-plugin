# WC Order Manager — WordPress Plugin

پلاگین وردپرسی برای اتصال فروشگاه ووکامرس به اپلیکیشن موبایل مدیریت سفارشات.

## امکانات (در حال توسعه)

- تولید و مدیریت API Key/Secret برای هر دستگاه/کاربر
- احراز هویت درخواست‌ها با HMAC-SHA256 (بدون ارسال مستقیم Secret در شبکه)
- اندپوینت‌های REST برای لیست سفارشات، جزئیات سفارش و تغییر وضعیت سفارش
- ارسال Push Notification (Firebase) به محض ثبت سفارش جدید در ووکامرس

## نیازمندی‌ها

- WordPress 5.8+
- WooCommerce 6.0+
- PHP 7.4+

## نصب (حالت توسعه)

1. پوشه پلاگین را در `wp-content/plugins/` کپی کنید
2. از پنل وردپرسی، پلاگین "WC Order Manager" را فعال کنید
3. به مسیر **WC Order Manager → API Credentials** بروید و یک Key/Secret جدید بسازید
4. Key و Secret را (فقط یک‌بار نمایش داده می‌شود) در اپ موبایل وارد کنید

## ساختار پروژه

```
wc-order-manager.php        نقطه ورود پلاگین
includes/
  class-wcom-activator.php  ساخت/آپدیت جدول دیتابیس هنگام فعال‌سازی
  class-wcom-db.php         کمک‌متدهای دسترسی به دیتابیس
  class-wcom-crypto.php     رمزنگاری/رمزگشایی Secret در دیتابیس
  class-wcom-credentials.php مدیریت Key/Secret (ساخت، Revoke، Regenerate)
  class-wcom-auth.php       تایید امضای HMAC درخواست‌ها
  class-wcom-rest-api.php   تعریف اندپوینت‌های REST (namespace wcom/v1)
  class-wcom-order-formatter.php قالب‌بندی داده سفارش ووکامرس برای خروجی API
  class-wcom-device-tokens.php   ذخیره توکن FCM هر credential (دستگاه)
  class-wcom-fcm-settings.php    ذخیره رمزنگاری‌شده Service Account فایربیس
  class-wcom-fcm.php             ارسال نوتیفیکیشن از طریق FCM HTTP v1 API
  class-wcom-order-notifications.php هوک سفارش جدید → ارسال Push به همه دستگاه‌ها
admin/
  class-wcom-admin.php      صفحه تنظیمات در پنل مدیریت
uninstall.php                پاکسازی اختیاری هنگام حذف کامل پلاگین
```

## اندپوینت‌های REST (namespace: `wcom/v1`)

همه‌ی اندپوینت‌ها نیاز به هدرهای HMAC دارن (به بخش زیر مراجعه کنید):

| Method | مسیر | توضیح |
|---|---|---|
| GET | `/wp-json/wcom/v1/verify` | شناسایی سایت بعد از وارد کردن Key/Secret |
| GET | `/wp-json/wcom/v1/order-statuses` | لیست وضعیت‌های موجود سفارش در این فروشگاه |
| GET | `/wp-json/wcom/v1/orders` | لیست سفارشات (پارامتر: `status`, `page`, `per_page`) |
| GET | `/wp-json/wcom/v1/orders/{id}` | جزئیات کامل یک سفارش |
| POST | `/wp-json/wcom/v1/orders/{id}/status` | تغییر وضعیت سفارش (بدنه: `{"status": "processing"}`) |
| POST | `/wp-json/wcom/v1/device-token` | ثبت/آپدیت توکن FCM این دستگاه (بدنه: `{"fcm_token": "..."}`) |

### امضای درخواست (HMAC)

هر درخواست باید این هدرها را داشته باشد:

```
X-WCOM-Api-Key:    <api_key>
X-WCOM-Timestamp:  <unix timestamp ثانیه>
X-WCOM-Nonce:      <رشته تصادفی یکتا برای هر درخواست>
X-WCOM-Signature:  <hex HMAC-SHA256>
```

رشته‌ی امضا شونده (با `\n` به هم متصل):

```
METHOD (حروف بزرگ)
PATH (فقط مسیر داخل wcom/v1، بدون /wp-json و بدون query string — مثل /orders/123)
TIMESTAMP
NONCE
SHA256_HEX(بدنه خام درخواست؛ برای بدنه خالی = sha256(''))
```

امضا: `hex( HMAC-SHA256( رشته بالا, api_secret ) )`

درخواست‌هایی که بیش از ۵ دقیقه با ساعت سرور اختلاف داشته باشن یا Nonce تکراری داشته باشن رد می‌شن.

## راه‌اندازی نوتیفیکیشن (Firebase)

1. در [Firebase Console](https://console.firebase.google.com) یک پروژه بساز (یا از پروژه موجود استفاده کن)
2. برو به **Project Settings → Service Accounts → Generate new private key** و فایل JSON رو دانلود کن
3. در پنل وردپرسی، **WC Order Manager → Push notifications (Firebase)**، محتوای کامل فایل JSON رو پیست کن و ذخیره کن
4. از این به بعد، به محض ثبت هر سفارش جدید در ووکامرس، به تمام دستگاه‌هایی که توکن FCM ثبت کرده‌باشن (از طریق اندپوینت `/device-token`) پوش نوتیفیکیشن ارسال می‌شه

> **نکته:** فایل JSON شامل یک کلید خصوصی هست و هیچ‌وقت در دیتابیس به‌صورت متن ساده ذخیره نمی‌شه — دقیقاً مثل Secret مربوط به API Key، با همون روش رمزنگاری (AES-256-GCM) ذخیره می‌شه.
>
> **نکته درباره تحریم:** به دلیل تحریم‌های آمریکا، دریافت توکن FCM روی گوشی‌هایی که آی‌پی/حساب گوگلشون به ایران مرتبطه، گاهی و به‌صورت متناوب با خطا مواجه می‌شه (این محدودیت از طرف گوگل اعمال می‌شه، نه فیلترینگ داخلی). برای همین در فاز ۵ اپ، علاوه بر Push، یک مکانیزم Polling هم به‌عنوان Fallback در نظر گرفته می‌شه تا اگه نوتیف Push نرسید، سفارش‌های جدید باز هم با کمی تاخیر توی لیست اپ دیده بشن.

## نقشه راه

- [x] فاز ۱ — زیرساخت: دیتابیس، Key/Secret، HMAC
- [x] فاز ۲ — API سفارشات (Verify، لیست، جزئیات، تغییر وضعیت)
- [x] فاز ۳ — نوتیفیکیشن لحظه‌ای (Firebase)
