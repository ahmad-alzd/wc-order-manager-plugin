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
admin/
  class-wcom-admin.php      صفحه تنظیمات در پنل مدیریت
uninstall.php                پاکسازی اختیاری هنگام حذف کامل پلاگین
```

## نقشه راه

- [x] فاز ۱ — زیرساخت: دیتابیس، Key/Secret، HMAC
- [ ] فاز ۲ — API سفارشات (Verify، لیست، جزئیات، تغییر وضعیت)
- [ ] فاز ۳ — نوتیفیکیشن لحظه‌ای (Firebase)
