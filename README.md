# SEO AI Export

خروجی‌گیر هوش‌مصنوعی برای وردپرس / ووکامرس: از سایت شما دیتاست‌های JSON / CSV / Markdown می‌سازد تا بتوانید با Gemini Gem، ChatGPT، Cursor یا هر عامل سئوی دیگری روی بهینه‌سازی کار کنید.

---

## فهرست مطالب

1. [این پروژه چیست؟](#این-پروژه-چیست)
2. [پیش‌نیازها](#پیش‌نیازها)
3. [نصب روی هاست (آپلود)](#نصب-روی-هاست-آپلود)
4. [اجرا و تولید خروجی](#اجرا-و-تولید-خروجی)
5. [فایل‌های خروجی چیست؟](#فایل‌های-خروجی-چیست)
6. [کار با هوش مصنوعی (Gemini Gem و مشابه)](#کار-با-هوش-مصنوعی-gemini-gem-و-مشابه)
7. [Use-caseها](#use-caseها)
8. [ساختار پوشه روی سرور](#ساختار-پوشه-روی-سرور)
9. [امنیت و نکات مهم](#امنیت-و-نکات-مهم)
10. [نسخه و Release](#نسخه-و-release)
11. [عیب‌یابی](#عیب‌یابی)

---

## این پروژه چیست؟

یک پوشه PHP کنار وردپرس است. با یک بار اجرا، از محصولات، دسته‌ها، صفحات، مقالات و رسانه‌ها استخراج می‌کند و علاوه بر داده خام، این‌ها را هم می‌سازد:

| خروجی | کاربرد |
|--------|--------|
| `knowledge.json` | مرجع کامل موجودیت‌ها (+ `seo.keyword_coverage`) برای چت با AI |
| `keyword_map.json` | inventory کیورد، coverage، cannibalization، gaps، پیشنهاد primary/secondary |
| `audit_diff.json` | مقایسه با export قبلی (added / resolved / severity_changed) |
| `redirect_map.json` | قوانین redirect (Rank Math / Yoast / Redirection) + chain/loop |
| `hreflang.json` | زبان‌ها و جفت‌های ترجمه Polylang/WPML |
| `sitemap_coverage.json` | مقایسه URLهای sitemap با موجودیت‌های export‌شده |
| `media_seo.json` | alt خالی، تصویر حجیم، ابعاد خیلی بزرگ در media library |
| `content_duplicates.json` | گروه‌های محتوای نزدیک‌به‌تکراری و عنوان تکراری |
| `seo_audit.json` | لیست مشکلات و فرصت‌های سئو با اولویت و `ai_action` |
| `ai_context.json` | بسته شروع AI (خلاصه + impact-ranked findings + next_actions + keyword summary) |
| `site_profile.json` | visibility، permalink، robots/sitemap، `seo_plugin_globals`، صفحات ویژه |
| `site_brain.json` | بسته هوش سایت (خوشه‌های غنی با pillar، روابط، ناوبری، `entity_index`) |
| `internal_link_graph.json` | orphan، weak hub، لینک مرده، link_opportunities، category nodes |
| CSV / Markdown | جداول تخت و فایل‌های متنی per-entity |

سئو متا از **Yoast** و **Rank Math** نرمال می‌شود. برای محصولات: برند، GTIN، ورییشن، نظرات تأییدشده و breadcrumb هم استخراج می‌شود.

---

## پیش‌نیازها

- وردپرس + ووکامرس
- PHP 8+
- دسترسی به فایل‌منیجر یا SSH برای آپلود پوشه کنار `wp-load.php`
- (اختیاری) Yoast SEO یا Rank Math برای متای کامل‌تر

---

## نصب روی هاست (آپلود)

### روش پیشنهادی: دانلود از GitHub Releases

1. برو به صفحه **Releases** همین ریپازیتوری.
2. آخرین `ai-exporter.zip` را دانلود کن (معمولاً در ریلیز `latest` یا جدیدترین تگ).
3. زیپ را Extract کن.
4. پوشه `ai-exporter` را داخل ریشه وردپرس آپلود کن؛ جایی که `wp-load.php` هست:

```text
public_html/
  wp-load.php
  wp-config.php
  wp-content/
  ai-exporter/          ← این پوشه
    index.php
    config.php
    ...
```

5. مسیر داخل `index.php` این است: یک سطح بالاتر `wp-load.php` را لود می‌کند. پس پوشه باید **فرزند مستقیم** ریشه وردپرس باشد.

### روش جایگزین: کلون از گیت

اگر روی سرور گیت داری، ریپو را کلون کن و پوشه را همان‌جا بگذار (خروجی‌ها در `output/` ساخته می‌شوند و در گیت نیستند).

---

## اجرا و تولید خروجی

### از مرورگر

```text
https://your-site.com/ai-exporter/
```

یا:

```text
https://your-site.com/ai-exporter/index.php
```

بعد از اتمام، یک گزارش HTML ساده با تعداد موجودیت‌ها، خلاصه audit و لینک فایل‌های خروجی نشان داده می‌شود.

### از CLI (SSH)

```bash
cd /path/to/wordpress/ai-exporter
php index.php
```

در CLI خروجی متنی است (بدون HTML).

### کجا ذخیره می‌شود؟

همه‌چیز زیر پوشه `output/` (gitignored):

```text
output/
  json/
    ai_context.json      ← شروع کار با AI
    keyword_map.json     ← کیورد ریسرچ on-site
    knowledge.json
    site_profile.json
    site_brain.json
    internal_link_graph.json
    seo_audit.json
    manifest.json
    products.json / categories.json / posts.json / pages.json / media.json
  csv/
  markdown/
    products/{id}.md
    ...

input/                   ← اختیاری
  gsc-queries.csv        ← CSV سرچ‌کنسول برای merge حجم/کوئری
```

---

## فایل‌های خروجی چیست؟

| فایل | چه می‌گوید | کی به AI بده |
|------|------------|--------------|
| **`ai_context.json`** | بسته شروع: خلاصه سایت، `next_actions`، findings با impact، keyword summary، url_index غنی | **اولویت ۱ — شروع کار با Gem** |
| **`keyword_map.json`** | inventory، پیشنهاد کیورد، gaps، cannibalization، اختیاری GSC | کیورد ریسرچ / پیشنهاد primary |
| **`audit_diff.json`** | diff با `seo_audit` قبلی | مقایسه قبل/بعد بعد از اصلاحات |
| **`redirect_map.json`** | قوانین redirect + تحلیل chain/loop | Technical SEO / مهاجرت URL |
| **`hreflang.json`** | نقشه زبان و ترجمه‌ها | سایت چندزبانه |
| **`sitemap_coverage.json`** | شکاف sitemap ↔ export | technical SEO / ایندکس |
| **`media_seo.json`** | alt / حجم / ابعاد رسانه | بهینه‌سازی تصویر |
| **`content_duplicates.json`** | near-duplicate محتوا و عنوان | کیفیت محتوا |
| **`seo_audit.json`** | همه یافته‌های قابل‌اجرا با شدت و `ai_action` | وقتی از سقف ai_context رد شدی |
| **`manifest.json`** | خلاصه اجرا و ایندکس فایل‌ها | overview خیلی سریع |
| **`site_profile.json`** | visibility، robots، sitemap reachable، seo_plugin_globals | technical SEO سطح‌سایت |
| **`knowledge.json`** | کل موجودیت‌ها با محتوا، structure، seo، keyword_coverage | جزئیات یک URL / بازنویسی |
| **`site_brain.json`** | آمار + خوشه غنی (pillar) + گراف + ناوبری + entity_index | استراتژی و معماری |
| **`internal_link_graph.json`** | orphan / hub / unresolved / link_opportunities | لینک‌سازی داخلی |
| CSV / Markdown | خواندن دستی یا ایمپورت اکسل | گزارش انسانی، ادیت محتوا |

### فیلدهای مهم داخل موجودیت‌ها

- `structure.headings` / `internal_links` / `external_links` / `faq_candidates`
- متریک متن فارسی: `word_count`, `sentence_count`, `char_count`
- `seo.*` نرمال Yoast/Rank Math + `sources` + `schema_detected` / `schema_claimed_only`
- `structure.content_render` — حالت رندر (`blocks` / `the_content` / raw)
- محصول: `identifiers`, `variations`, `reviews`, `breadcrumb`, `total_sales`

### رندر محتوا و محدودیت Elementor

در `config.php` فلگ `content_render` را تنظیم کن:

| مقدار | رفتار |
|--------|--------|
| `blocks` (پیش‌فرض) | `do_blocks` + shortcode — مناسب اکثر سایت‌های Gutenberg |
| `the_content` | فیلتر کامل وردپرس (سنگین‌تر، دقیق‌تر برای شورت‌کدها) |
| `off` | فقط `post_content` خام |

**محدودیت:** صفحه‌سازهایی مثل Elementor که لایه‌بندی را فقط در post meta نگه می‌دارند در این pipeline رندر نمی‌شوند؛ هدینگ/لینک داخل آن لایه‌ها ممکن است دیده نشود.

---

## کار با هوش مصنوعی (Gemini Gem و مشابه)

### الگوی توصیه‌شده (همان workflow قبلی، به‌روز شده)

1. Export را روی سایت اجرا کن.
2. یک [Gemini Gem](https://gemini.google.com/) (یا Custom GPT / Claude Project) بساز.
3. متن دستورالعمل را از یکی از این فایل‌ها کپی کن:
   - فارسی: [`prompt-fa.md`](prompt-fa.md)
   - English: [`prompt-en.md`](prompt-en.md)
4. به‌عنوان Knowledge / Files به Gem بده (همه اجباری نیست؛ پرامپت جدول ROUTING دارد):
   - **حداقل پیشنهادی:** `output/json/ai_context.json`
   - بهتر: `ai_context.json` + `prompt` (دستورالعمل Gem)
   - برای کیورد: همان + `keyword_map.json`
   - کامل‌تر: + `seo_audit.json` / `knowledge.json` فقط وقتی روی URL خاصی عمیق می‌شوی
5. بعد سؤال بپرس. مدل نباید هر بار همه فایل‌ها را اسکن کند.

### Search Console CSV (اختیاری)

فایل Queries سرچ‌کنسول را به‌صورت `input/gsc-queries.csv` بگذار (ستون‌های Query / Page / Clicks / Impressions / Position). در `keyword_map.json` با inventory سایت merge می‌شود.

### اگر حجم فایل برای آپلود زیاد است

1. فقط `ai_context.json` را بده (برای اکثر سؤالات کافی است).
2. برای جزئیات یک صفحه، همان entity را از `knowledge` یا Markdown جدا کن.
3. در صورت نیاز `seo_audit.json` کامل را اضافه کن.

### نمونه سؤال‌هایی که می‌توانی بپرسی

- «۱۰ کار بعدی را از next_actions با Sprint دو هفته‌ای بده.»
- «صفحات orphan را لیست کن و برای هرکدام منبع لینک پیشنهاد بده.»
- «برای صفحات بدون focus keyword پیشنهاد primary بده.»
- «کدام محصولات focus keyword یکسان یا نزدیک دارند؟»
- «برای دسته X یک تقویت محتوا و FAQ بر اساس structure فعلی بنویس.»
- «Roadmap سه‌ماهه سئوی فروشگاه را بنویس.»

---

## Use-caseها

### ۱) مشاور سئوی شخصی با Gemini Gem

**هدف:** بعد از هر export، یک مشاور همیشگی داشته باشی که سایت را «می‌شناسد».

**چطور:** `prompt-fa.md` + `ai_context.json` را به Gem بده و سؤال بپرس؛ برای جزئیات URL از `knowledge.json` استفاده کن.

---

### ۲) اولویت‌بندی کار تیم محتوا / سئو

**هدف:** به‌جای حس، لیست کار با شدت و دلیل.

**چطور:** `seo_audit.json` را باز کن یا به AI بده؛ روی `critical` → `warning` → `opportunity` کار کن. هر finding یک `ai_action` دارد.

---

### ۳) اصلاح لینک‌سازی داخلی

**هدف:** orphanها را نجات بده، weak hubها را تقویت کن، لینک مرده را پاک کن.

**چطور:** `internal_link_graph.json` + بخش لینک در audit. از AI بخواه برای هر orphan انکر و صفحه مبدأ پیشنهاد دهد.

---

### ۴) بازنویسی Title / Meta / Outline مقاله یا محصول

**هدف:** بهبود CTR و intent match.

**چطور:** موجودیت مربوطه را از `knowledge` یا Markdown بده؛ از AI بخواه با توجه به `seo` فعلی، `structure.headings` و رقبا (اگر داری) نسخه جدید بنویسد.

---

### ۵) معماری اطلاعات و Topic Cluster

**هدف:** خوشه‌بندی محتوا و صفحات ستون (pillar).

**چطور:** `site_brain.json` (خوشه‌ها + روابط) و دسته‌ها در `knowledge`. از AI بخواه نقشه خوشه و شکاف موضوعی بسازد.

---

### ۶) آماده‌سازی Schema / Commerce SEO

**هدف:** برند، GTIN، نظر، تصویر و alt برای محصولات کلیدی.

**چطور:** فیلدهای `identifiers` / `reviews` / تصاویر در knowledge و findingهای مرتبط در audit.

---

### ۷) گزارش مدیریتی ماهانه

**هدف:** یک Executive Summary برای مدیر.

**چطور:** `manifest.json` + خلاصه audit را به AI بده و بخواه گزارش با KPI، ریسک و Next Actions بنویسد (دستور این کار داخل پرامپت هست).

---

### ۸) ایجنت اتوماتیک (Cursor / اسکریپت)

**هدف:** عامل روی findingها حلقه بزند و تسک تولید کند.

**چطور:** `seo_audit.json` را parse کن؛ روی `ai_action` و `severity` فیلتر بزن؛ برای جزئیات به `knowledge` رجوع کن.

---

### ۹) مقایسه قبل/بعد بعد از تغییرات سایت

**هدف:** ببینی audit و orphanها بهتر شده‌اند یا نه.

**چطور:** دو بار export بگیر؛ دو نسخه `seo_audit.json` / `manifest.json` را به AI بده و Diff بخواه.

---

### ۱۰) آموزش یا آنبوردینگ نیروی جدید سئو

**هدف:** سریع بفهمد سایت چه ساختاری دارد.

**چطور:** `site_brain.json` + README + چند سؤال آماده در Gem.

---

## ساختار پوشه روی سرور

```text
ai-exporter/
├── index.php              # نقطه ورود — اجرا از اینجا
├── config.php             # نسخه و فلگ‌های export
├── helpers.php
├── prompt-fa.md           # دستورالعمل Gem (فارسی)
├── prompt-en.md           # دستورالعمل Gem (English)
├── README.md
├── assets/report.css      # استایل گزارش مرورگر
├── builders/              # knowledge, link graph, site brain, seo audit, keyword map
├── exporters/             # products, categories, posts, pages, media
├── helpers/               # TextMetrics, ContentStructureExtractor
├── repositories/          # mapperها + SeoMetaExtractor
├── views/ExportReport.php
├── writers/               # JSON / CSV / Markdown
├── input/                 # اختیاری: gsc-queries.csv
└── output/                # بعد از اجرا ساخته می‌شود (آپلود لازم نیست)
```

---

## امنیت و نکات مهم

- این پوشه داده‌های کامل سایت را می‌سازد؛ بعد از استفاده، دسترسی عمومی را محدود کن (مثلاً Basic Auth، IP allowlist، یا حذف موقت پوشه).
- پوشه `output/` را در وب عمومی نگذار بدون محافظت — حاوی محتوای کامل است.
- روی سایت‌های بزرگ ممکن است اجرای اول چند دقیقه طول بکشد؛ `time_limit` و memory در `index.php` بالا تنظیم شده‌اند.
- `.cursor/` مربوط به توسعه محلی است و در ریپوی عمومی / زیپ انتشار نیست.

---

## نسخه و Release

- نسخه در `config.php` فیلد `version` است.
- بعد از هر کامیت معنادار، یک زیپ `ai-exporter.zip` از فایل‌های لازم برای آپلود هاست ساخته و در **GitHub Releases** منتشر می‌شود.
- برای استقرار روی هاست همیشه آخرین ریلیز را دانلود کن؛ نیازی به کلون کل تاریخچه گیت نیست.

ساخت دستی زیپ:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/package-release.ps1
```

خروجی در `dist/ai-exporter.zip` است. اسکریپت مسیرها را با `/` داخل زیپ می‌نویسد (سازگار با آنزیپ لینوکس/cPanel). از `Compress-Archive` ویندوز استفاده نکن — روی هاست فایل‌هایی با نام `ai-exporter\builders\...` می‌سازد به‌جای پوشه واقعی.

---

## عیب‌یابی

| مشکل | کار |
|------|-----|
| صفحه سفید / خطای PHP | PHP 8+ و لود شدن `wp-load.php` را چک کن؛ پوشه باید کنار `wp-load.php` باشد. |
| ووکامرس پیدا نشد | افزونه WooCommerce باید فعال باشد. |
| متای سئو خالی است | Yoast یا Rank Math نصب/فعال باشد؛ وگرنه فیلدهای پایه خالی‌تر می‌مانند. |
| حافظه کم / تایم‌اوت | از CLI اجرا کن؛ یا موقتاً بخشی از export را در `config.php` خاموش کن. |
| گزارش HTML نمی‌آید | فقط در اجرای مرورگر HTML است؛ CLI متنی است. |
| فایل برای Gem خیلی بزرگ است | اول audit + manifest؛ بعد تکه‌تکه knowledge. |

---

## Roadmap (انجام‌شده)

1. ~~استخراج ساختار محتوا (هدینگ، لینک داخلی با انکر، متریک فارسی)~~
2. ~~سئو متای کامل Yoast + Rank Math + غنی‌سازی محصول~~
3. ~~گراف لینک داخلی + orphan / hub / dead + link_opportunities + category nodes~~
4. ~~`seo_audit.json` برای عامل‌های AI~~
5. ~~`keyword_map.json` + coverage + پیشنهاد کیورد on-site (+ GSC CSV اختیاری)~~
6. ~~impact_score / next_actions در `ai_context` + خوشه‌های pillar-aware~~
7. ~~commerce schema essentials + sales-weighted + seo_plugin_globals~~
8. ~~رندر `do_blocks`/`the_content` + JSON-LD واقعی vs ادعای پلاگین + tag nodes~~
9. ~~`audit_diff` قبل/بعد + `redirect_map` + `hreflang`~~
10. ~~`sitemap_coverage` + `media_seo` + `content_duplicates`~~

---

اگر فقط یک مسیر را می‌خواهی به خاطر بسپاری:

**آپلود زیپ ریلیز → باز کردن `/ai-exporter/` → دادن `prompt-fa.md` + `ai_context.json` به Gemini Gem → سؤال بپرس.**
