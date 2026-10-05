# Talata.ir — Phase 1 planning

مستندات شروع ساخت سرویس SaaS ماژولار طلافروشی، با Laravel و تجربه کاربری فارسی برای صاحبان فروشگاه کم‌تجربه در استفاده از نرم‌افزار.

## فایل‌های اصلی

| فایل | کاربرد |
| --- | --- |
| [Phase 1 Master Prompt](docs/prompts/PHASE_1_MASTER_PROMPT.md) | پرامپ اجرایی کامل معماری، داده، محاسبات، امنیت، پلن‌ها و مراحل ساخت |
| [UX-first preliminary prompt](docs/prompts/UI_UX_DISCOVERY_PROMPT.md) | پرامپ اولیه تحقیق و طراحی تجربه کاربری؛ مستقل از کتابخانه |
| [UI/UX tool shortlist](docs/research/UI_UX_TOOL_SHORTLIST.md) | گزینه‌های بررسی‌شده برای Claude، کتابخانه‌ها و لینک نمونه‌ها |
| [Implementation checklist](docs/PHASE_1_CHECKLIST.md) | معیارهای پذیرش و پیگیری مراحل |

## نحوه استفاده با Claude Code

پرامپ اصلی انگلیسی است تا قراردادهای فنی دقیق باشند؛ تمام متن‌های محصول باید فارسی و RTL باشند.

```text
Read CLAUDE.md, docs/prompts/PHASE_1_MASTER_PROMPT.md,
docs/prompts/UI_UX_DISCOVERY_PROMPT.md and docs/PHASE_1_CHECKLIST.md.
Start with repository inspection, an implementation plan and foundational backend work.
Respect the pending UI selection. Do not install a UI library or design skill yet.
Record assumptions and progress in docs; implement in small verified milestones.
```

تصمیم کتابخانه UI، ابزار طراحی و جهت بصری هنوز گرفته نشده است. بریف UX اکنون قابل استفاده است، اما پرامپ نهایی UI بعد از انتخاب صاحب پروژه اضافه می‌شود. این ریپو در این مرحله حاوی مستندات است؛ اپلیکیشن هنوز پیاده‌سازی نشده است.

## تصمیم‌های پایه

- مدل واقعی چندفروشگاهی، جداسازی اطلاعات هر فروشگاه و کنترل دسترسی سمت سرور.
- محاسبات دقیق و نسخه‌دار؛ ذخیره مستقل اصل طلا، اجرت، سود، حق‌العمل، تخفیف و مالیات.
- فاکتور قطعی با تصویر ثابت نرخ، اطلاعات فروشگاه و خروجی محاسبات.
- ماشین‌حساب مستقل؛ حسابداری، انبار، آب‌شده و اتصال مؤدیان در فازهای بعد.
- نرخ مالیات تنظیم‌پذیر بر اساس تاریخ اثرگذاری؛ پیش‌فرض نمونه ۱۰٪ روی خدمات مشمول، با ثبت منبع تأیید برای استفاده عملیاتی.
- PWA و جابه‌جایی بدون بارگذاری کامل؛ نرخ آفلاین با برچسب صریح آخرین به‌روزرسانی.

Prepared: 2026-10-05.
