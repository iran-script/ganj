# Urban AR Team Adventure Game — Product Blueprint v1

## سند مرجع رسمی طراحی محصول، معماری بازی، سناریوساز و اجرای مسابقه

# 1\. چشم‌انداز محصول

این محصول یک پلتفرم بازی شهری مشارکتی است که تیم‌های ۴ تا ۸ نفره را در محیط واقعی شهر وارد یک ماجراجویی مرحله‌ای می‌کند. تجربه بازی بر ترکیب GPS، نقشه، QR، واقعیت افزوده (AR)، معما، نقش‌های پویا، مأموریت‌های زنده و رقابت بین تیم‌ها بنا می‌شود. هدف این است که بازی به یک سناریوی ثابت وابسته نباشد و مدیر بتواند بدون تغییر کد، سناریوهای جدید بسازد، نسخه‌بندی کند، منتشر کند و در اجرای زنده روی تیم‌ها اثر بگذارد.

# 2\. ساختار مسابقه و تیم‌ها

هر Game Session باید با حداقل ۱ تیم و حداکثر ۵ تیم قابل اجرا باشد. هر تیم حداقل ۴ و حداکثر ۸ عضو دارد. مسابقه نباید به حضور همزمان ۵ تیم وابسته باشد؛ اگر فقط یک تیم ثبت‌نام کرده باشد نیز بازی باید کامل و معتبر اجرا شود. در هر Stage فقط ۴ Slot چالش اصلی فعال است؛ اعضای پنجم تا هشتم نقش پشتیبان دارند اما می‌توانند در Rotation مراحل بعدی وارد Slotهای اصلی شوند یا Individual/Live Challenge دریافت کنند.  
در هر Session، هر تیم یک Scenario مستقل دریافت می‌کند. Scenarioها از کتابخانه سناریو انتخاب می‌شوند و می‌توانند به‌صورت دستی توسط مدیر یا به‌صورت خودکار و Shuffle تخصیص داده شوند. موتور تخصیص باید بتواند از تکرار سناریویی که تیم قبلاً بازی کرده جلوگیری کند.

# 3\. اصل کلیدی: Scenario به‌عنوان Content نه Code

سناریوی بازی نباید داخل کد Backend به‌صورت if/else ثابت نوشته شود. سناریو باید یک موجودیت داده‌ای و قابل‌تعریف در پنل مدیریت باشد. اضافه‌کردن دو یا بیست سناریوی جدید نباید نیازمند تغییر Backend، Deploy یا دخالت توسعه‌دهنده باشد.  
هر Scenario از نسخه‌ها، Nodeها، Edgeها، Conditions، Variables، Subflowها و تنظیمات Runtime تشکیل می‌شود. مدیر باید بتواند Scenario را بسازد، Validate کند، تست کند و Publish نماید.

# 4\. Scenario Library

کتابخانه سناریو محل نگه‌داری همه سناریوهای قابل استفاده است. تعداد سناریوها محدود به پنج عدد نیست؛ محدودیت پنج فقط مربوط به تعداد تیم‌های همزمان در یک Game Session است. Library می‌تواند ده‌ها یا صدها سناریو داشته باشد.  
هر سناریو دارای عنوان، توضیح، Theme، درجه سختی، زمان تخمینی، مسافت تخمینی، سطح فعالیت بدنی، تعداد چالش‌های AR، تعداد معماها، برچسب‌های مناسبتی و وضعیت Draft/Published/Archived است.

# 5\. Visual Scenario Builder

مدیر باید یک محیط گرافیکی Drag & Drop شبیه Workflow Builder داشته باشد. سناریو از Nodeها و اتصال بین آن‌ها ساخته می‌شود. مسیر بازی می‌تواند خطی، شاخه‌ای، شرطی یا شامل Subflow باشد.

## Node Types پیشنهادی

Start، Finish، GPS/Location، QR Scan، AR Challenge، Puzzle، Password، Multiple Choice، Text Answer، Photo Challenge، Timer، Team Together، Split Team، Specific Player، Random Player، Assign Roles، Swap Roles، Wait، Branch، Condition، Add Score، Subtract Score، Give Hint، Live Challenge Trigger، Checkpoint، Show Message، Show Image و Show Video.

# 6\. Form Builder و تنظیمات Node

هر Node باید فرم تنظیمات مخصوص خود داشته باشد. برای مثال Node مکانی شامل مختصات، شعاع، تعداد بازیکنان موردنیاز و مدت حضور در محدوده است. Node معما شامل متن، نوع پاسخ، پاسخ صحیح، تعداد تلاش، Hint، هزینه Hint و امتیاز است. Node AR شامل Scene، بازیکن مجاز، Completion Rule و Reward است.  
فرم‌ها باید قابل توسعه باشند تا Node Typeهای جدید بدون بازطراحی کل Scenario Builder اضافه شوند.

# 7\. Scenario Variables و محتوای پویا

سیستم باید از Variableهای داینامیک پشتیبانی کند؛ مانند team.name، player.scanner، checkpoint.code، random.number، game.time\_remaining و مقادیر Runtime. متن‌ها و قوانین سناریو می‌توانند از این متغیرها استفاده کنند.  
نمونه: «{{player.scanner}}، فقط تو اجازه داری QR این مرحله را اسکن کنی.» در هر اجرای بازی می‌تواند به نام بازیکن متفاوتی تبدیل شود.

# 8\. Condition Builder

مدیر باید بتواند بدون کدنویسی شرط تعریف کند. نمونه‌ها: اگر امتیاز بیشتر از ۵۰۰ و زمان سپری‌شده کمتر از ۴۰ دقیقه بود، Bonus Challenge فعال شود؛ اگر دو پاسخ اشتباه ثبت شد، Hint ساده‌تر نمایش داده شود؛ اگر هر چهار بازیکن داخل Zone بودند، QR Unlock شود.  
Conditionها باید روی داده‌های تیم، بازیکن، زمان، امتیاز، GPS، وضعیت Nodeها، تعداد تلاش و Variableهای سناریو قابل تعریف باشند.

# 9\. Versioning، Draft، Validate و Publish

Scenarioها باید Versioned باشند. نسخه Published که یک تیم با آن بازی را شروع کرده تا پایان همان Session برای آن Team Instance Freeze می‌شود. ویرایش بعدی مدیر نباید اجرای جاری را تغییر دهد.  
چرخه استاندارد: Draft → Validate → Publish. Validation باید حداقل وجود Start و Finish، نبود Node بی‌مسیر، صحت اتصال Branchها، تعریف پاسخ برای Puzzle، اعتبار AR Scene، جلوگیری از Loop غیرمجاز و دسترسی‌پذیری مسیر پایان را بررسی کند.

# 10\. Runtime و Team Scenario Instance

Template سناریو از اجرای واقعی جداست. هنگام شروع بازی برای هر تیم یک Team Scenario Instance ساخته می‌شود که Version سناریو، Node فعلی، وضعیت Nodeهای انجام‌شده، امتیاز، Hintها، Role Assignmentها، Challengeها و State اختصاصی همان تیم را نگه می‌دارد.  
این جداسازی اجازه می‌دهد پنج تیم همزمان روی پنج Scenario متفاوت یا حتی نسخه‌های متفاوت بازی کنند بدون اینکه State آن‌ها با هم تداخل داشته باشد.

# 11\. GPS و Geofence

موقعیت بازیکنان از GPS گوشی دریافت می‌شود. هر Checkpoint می‌تواند Point یا Zone داشته باشد و ورود بازیکن یا کل تیم به شعاع موردنظر توسط Backend تأیید شود. فرکانس ارسال Location باید Adaptive باشد؛ در حرکت عادی کمتر، نزدیک Checkpoint بیشتر و در حالت سکون با فاصله زمانی بیشتر.  
برای ضدتقلب، timestamp، accuracy، speed، فاصله نسبت به موقعیت قبلی و جهش‌های غیرممکن ذخیره و بررسی می‌شوند. GPS به‌تنهایی مبنای تکمیل همه مراحل نیست و در مراحل حساس با QR، AR یا Challenge ترکیب می‌شود.

# 12\. QR و رمزهای داینامیک

QR نباید خودِ جواب مرحله باشد. QR فقط شناسه Checkpoint یا Trigger را حمل می‌کند. پس از اسکن، Server با توجه به Team، Scenario Version و Session تصمیم می‌گیرد چه Challenge یا محتوایی نمایش داده شود.  
یک QR فیزیکی می‌تواند برای Teamهای مختلف یا Sessionهای مختلف خروجی متفاوت تولید کند؛ بنابراین عکس گرفتن از QR یا لو دادن رمز ارزش محدودی خواهد داشت.

# 13\. واقعیت افزوده (AR)

پس از اسکن QR یا رسیدن به Trigger مشخص، AR می‌تواند یک شیء سه‌بعدی، صندوق، کلید، نماد، عدد، جهت یا Puzzle را روی محیط واقعی نمایش دهد. AR باید بخشی از منطق سناریو باشد و Completion Result آن به Runtime گزارش شود.  
نمونه Challengeها: صندوق سه‌بعدی که باید باز شود، رمز روی چند وجه مکعب، پیدا کردن شیء مخفی، سرنخ جهت‌دار، Puzzle سه‌بعدی یا نمایش بخشی از رمز.

# 14\. درگیر کردن اعضای تیم و چهار Slot چالش اصلی

اصل طراحی: هیچ Stage مهمی نباید به‌سادگی توسط یک نفر قابل تکمیل باشد. هر Stage حداکثر ۴ Slot چالش اصلی دارد. در تیم‌های ۵ تا ۸ نفره، اعضای خارج از Slotهای اصلی به‌عنوان Support در جست‌وجو، حل معما، هماهنگی و کمک به تیم مشارکت می‌کنند. Slotهای اصلی باید در طول بازی بین اعضا Rotation شوند تا همیشه همان چهار نفر فعال نباشند.  
Roleهای نمونه برای چهار Slot اصلی: Scanner، Navigator، Decoder و AR Operator. در Stage بعد Slotها می‌توانند بین اعضای تیم Shuffle/Rotate شوند. اطلاعات یک مرحله می‌تواند بین چهار Slot فعال تقسیم شود تا حل نهایی فقط با همکاری تیم امکان‌پذیر باشد. اعضای Support نیز واجد دریافت Individual Challenge یا Live Challenge از Game Master هستند.

## الگوهای همکاری

Split Information، Simultaneous Action، Physical Separation، Chain Unlock، AR Assembly، Secret Roles، Team Formation و Relay Mode.

# 15\. Live Challenge و Game Master Intervention

مدیر باید در زمان اجرای زنده بتواند برای یک Player، چند Player، یک Team یا کل مسابقه Challenge ارسال کند. Challenge به‌صورت یک لایه موازی با Main Scenario اجرا می‌شود و در حالت عادی نباید Stage اصلی را خراب یا Reset کند.  
انواع Live Challenge شامل GPS Mission، QR، AR، Puzzle، Photo، Timer، Custom Mission، Role Swap، Double Points، Solo Mission و Team Challenge است. برای هر Challenge می‌توان Duration، Reward، Penalty، Target، Interrupt Policy و Completion Rule تعیین کرد.  
Challenge Templateها باید از قبل قابل ذخیره باشند تا مدیر در مسابقه با یک کلیک آن‌ها را ارسال کند.

# 16\. امتیاز، Hint و Leaderboard

امتیاز می‌تواند از Completion، سرعت، حل بدون Hint، Live Challenge و Bonusها تشکیل شود. استفاده از Hint می‌تواند هزینه امتیازی داشته باشد. Leaderboard باید رتبه تیم‌ها، امتیاز، Stage و زمان را نمایش دهد و در صورت نیاز بتوان نمایش رتبه دقیق را در بخشی از بازی مخفی کرد.

# 17\. Fairness بین سناریوها

Random بودن نباید باعث نابرابری شدید شود. هر Scenario باید metadata مربوط به Difficulty، Estimated Duration، Distance، Physical Effort و Challenge Mix داشته باشد. پیش از Start، سیستم باید سناریوهای تخصیص‌یافته را از نظر زمان، مسافت و سختی مقایسه و عدم تعادل جدی را هشدار دهد.

# 18\. Template و Subflow

Scenario Templateهایی مانند Treasure Hunt، Mystery، Spy Mission، City Race، Historical Hunt و AR Adventure می‌توانند Duplicate و شخصی‌سازی شوند. Subflowها برای مجموعه‌های قابل استفاده مجدد مانند «QR \+ AR Treasure Challenge» تعریف می‌شوند.

# 19\. Anti-Cheat

موتور ضدتقلب باید حداقل جهش غیرممکن GPS، سرعت غیرواقعی، Scan خارج از محدوده، تکرار غیرعادی QR، استفاده همزمان غیرمجاز از حساب، ترتیب نامعتبر Nodeها و تلاش برای ارسال Completion مستقیم را Flag کند. سرور باید مرجع نهایی State و Completion باشد.

# 20\. پنل Game Master

پنل مدیر در زمان Live Game باید وضعیت ۱ تا ۵ تیم را نشان دهد: Scenario، Stage، موقعیت بازیکنان، اتصال/آخرین Location، امتیاز، زمان، Roleها، Challengeهای فعال و هشدارهای ضدتقلب.  
عملیات مدیر: Send Challenge، Give Hint، Add/Subtract Score، Skip Node، Pause Team، Resume، Send Message، Role Swap، Disqualify و مشاهده Timeline.

# 21\. تجربه موبایل

صفحات اصلی اپ شامل ورود/ثبت‌نام، تیم، Lobby، نقشه بازی، Stage Details، QR Scanner، AR Challenge، Puzzle/Code Entry، Live Challenge، Stage Result، Leaderboard، Notifications و Profile است. UI باید برای استفاده در فضای باز، حرکت، نور زیاد و تعامل سریع طراحی شود.

# 22\. معماری فنی پیشنهادی

Mobile App: Flutter. Backend/API: Laravel با معماری Modular Monolith. Spatial Database: PostgreSQL \+ PostGIS. Realtime/Queue: Redis، Queue Worker و WebSocket/Broadcasting. AR: ماژول AR مبتنی بر ARCore/ARKit برای نیازهای ساده یا Unity برای تجربه‌های سه‌بعدی و Puzzleهای پیچیده. پنل مدیریت وب شامل Scenario Builder، Form/Condition Builder و Live Game Master است. Stateهای مهم نباید در حافظه یک App Server نگه‌داری شوند؛ PostgreSQL و Redis مرجع State هستند تا Horizontal Scaling بعدی بدون بازطراحی هسته ممکن باشد.

# 23\. مدل داده سطح بالا

موجودیت‌های اصلی: games، game\_sessions، teams، team\_members، game\_session\_teams، scenarios، scenario\_versions، scenario\_nodes، scenario\_edges، node\_conditions، node\_actions، scenario\_variables، scenario\_subflows، team\_scenario\_instances، team\_node\_instances، player\_assignments، checkpoints، qr\_triggers، ar\_challenges، challenge\_templates، live\_challenges، scores، hints، location\_events و anti\_cheat\_flags.

# 24\. قواعد Freeze شده فعلی

حداقل تیم در هر Session برابر ۱ و حداکثر ۵ است. هر تیم حداقل ۴ و حداکثر ۸ عضو دارد. در هر Stage فقط ۴ Slot چالش اصلی فعال است؛ اعضای اضافی Support هستند و می‌توانند در Rotation یا Challengeهای فردی/زنده وارد شوند. ساخت سناریو باید کاملاً Dynamic باشد. تعداد سناریوهای Library نامحدود است. هر تیم Runtime مستقل دارد. Scenario Version پس از شروع برای Team Instance Freeze می‌شود. GPS، QR و AR اجزای اصلی هستند. نقش‌ها باید پویا و چرخشی باشند. Live Game Master و Challenge لحظه‌ای جزو هسته محصول است. مدل تجاری اصلی Per-Player Per-Game است: برای هر بازیکن یکتا در هر Game Session یک هزینه مستقل محاسبه می‌شود. IoT فعلاً خارج از Scope نسخه فعلی است.

# 25\. مسیر توسعه پیشنهادی

فاز ۰: Foundation — ✅ FROZEN. فاز ۱: Core Commercial Foundation — Identity، Teams، Billing/Commerce، Game Session، Runtime و Tenant Baseline — ✅ FROZEN DESIGN. فاز ۲: GPS/Map/Checkpoint/PostGIS — ✅ FROZEN DESIGN. فاز ۳: Scenario Core/Versioning/Runtime Engine — ✅ FROZEN DESIGN. فاز ۴: Visual Scenario Builder/Simulation — ✅ FROZEN DESIGN. فاز ۵: QR/Dynamic Trigger — ✅ FROZEN DESIGN. فاز ۶: Co-op Roles/Four Active Slots — ✅ FROZEN DESIGN. فاز ۷: AR Runtime/Content Delivery — ✅ FROZEN DESIGN. فاز ۸: Live Game Master/Challenge Engine — ✅ FROZEN DESIGN. فاز ۹: Scoring/Hints/Leaderboard — ✅ FROZEN DESIGN. فاز ۱۰: Anti-Cheat/Trust — ✅ FROZEN DESIGN. فاز ۱۱: Admin & Content Operations — ✅ FROZEN DESIGN. فاز ۱۲: Analytics/Replay/BI — ✅ FROZEN DESIGN. فاز ۱۳: Offline/Resilience/Recovery — ✅ FROZEN DESIGN. فاز ۱۴: Production Hardening/Observability/Security — ✅ FROZEN DESIGN. فاز ۱۵: Field Pilot & Operational Readiness — ✅ FROZEN DESIGN. فاز ۱۶: Commercial Release & Scale-out — ✅ FROZEN DESIGN.

# 26\. اصل محصول برای ادامه توسعه

# هر قابلیت جدید باید تا حد ممکن به‌صورت Node، Rule، Template یا Config به موتور اضافه شود نه به‌صورت منطق اختصاصی یک سناریو. هدف این است که بعد از کامل‌شدن موتور، تولید بازی جدید بیشتر شبیه طراحی محتوا باشد تا توسعه نرم‌افزار.

# 27\. مقیاس هدف و الزامات ظرفیت

مبنای طراحی Production: سامانه باید برای حداقل ۱۰۰ Game Session در روز طراحی شود. در بدترین ظرفیت تعریف‌شده، هر Session می‌تواند ۵ تیم و هر تیم ۸ بازیکن داشته باشد؛ بنابراین حداکثر ۴۰ بازیکن در یک Session و تا ۴۰۰۰ مشارکت بازیکن در روز ممکن است. معماری باید بدون تغییر هسته قابلیت پشتیبانی از چندصد تا بیش از ۱۰۰۰ کاربر همزمان را داشته باشد. Location Tracking باید Adaptive باشد؛ در حالت عادی با فاصله بیشتر، نزدیک Checkpoint با فرکانس بالاتر و در سکون با فرکانس کمتر. پردازش‌های سنگین Geofence، Anti-Cheat، Analytics و Realtime fan-out باید تا حد ممکن از مسیر Request اصلی جدا و توسط Queue/Worker اجرا شوند. برای Location History باید Partitioning و Retention Policy در نظر گرفته شود تا حجم داده GPS در بلندمدت کنترل شود.

# 28\. سیاست نگه‌داری سند تصمیمات

این Blueprint مرجع رسمی تصمیمات پروژه است. از این پس هر تصمیمی که در گفتگو صریحاً تأیید شود باید در همین سند اعمال یا اصلاح شود؛ تصمیم جدید جایگزین نسخه قبلی همان Rule می‌شود و سند نباید همزمان قواعد متناقض نگه دارد. تصمیم‌های آزمایشی یا صرفاً پیشنهادی تا زمان تأیید نهایی به‌عنوان Rule Freeze شده ثبت نمی‌شوند.  
29\. Phase 0 — Foundation (FROZEN)  
وضعیت: ✅ FROZEN / READY FOR IMPLEMENTATION.  
Stack نهایی: Laravel برای Backend/API با معماری Modular Monolith، REST API با نسخه‌بندی /api/v1، PostgreSQL \+ PostGIS برای داده و GIS، Redis برای Cache/Runtime، Laravel Queue \+ Redis برای پردازش پس‌زمینه، WebSocket/Broadcasting برای Realtime و Flutter برای Mobile App. AR در فاز پایه فقط Contract/Interface دارد و پیاده‌سازی اصلی آن در فاز AR انجام می‌شود.  
قواعد امنیت و Runtime: Auth مبتنی بر Token برای موبایل، Device Session قابل revoke، Roleهای پایه SuperAdmin/GameMaster/Player، Authorization با Policy/Gate در Server، Runtime به‌صورت Server-authoritative، Validation سمت Server، Rate Limit، Token Revocation، Password Hashing، CORS محدود و Audit عملیات مدیریتی.  
قواعد معماری: Moduleهای پایه Identity، Teams، Games، Sessions، Scenarios، Runtime و Audit. Controllerها باید Thin باشند و Business Logic در Domain/Application Service قرار گیرد. منطق اختصاصی یک Scenario نباید وارد Core شود. State حیاتی نباید فقط در RAM یک App Server نگه‌داری شود و باید در PostgreSQL/Redis قابل بازیابی باشد.  
پایداری و مقیاس: Queue Workerهای مجزا برای پردازش‌های سنگین، Structured Logging با request\_id/user\_id/session\_id/team\_id، Domain Event برای رخدادهای مهم، Idempotency برای عملیات حساس، Transaction/Lock برای Concurrency، Migration-only Schema changes، Docker برای محیط‌های Development/Production، Secrets فقط از Environment، Backup منظم همراه با Restore Test و آمادگی برای Horizontal Scaling.  
تست و کیفیت: Unit، Feature و Integration Test الزامی است و State Machine/Runtime بدون تست نباید Merge شود. Error Format API باید استاندارد و ثابت باشد. زیرساخت باید Monitoring-ready باشد و File Storage abstraction داشته باشد تا Local در Development و Object Storage در Production قابل جایگزینی باشد.  
حریم خصوصی و داده حجیم: Location فقط در محدوده نیاز Game Session پردازش شود، Raw GPS دارای Retention Policy باشد و Location/Eventهای حجیم از ابتدا قابلیت Partition/Archive داشته باشند. Target Capacity همان الزامات بخش ۲۷ است.  
30\. Phase 1.1 — Auth & Player Identity (APPROVED)  
هدف: هویت کاربر، دستگاه، نشست ورود و حضور بازیکن باید به‌گونه‌ای طراحی شود که GPS، Realtime، Challenge و Anti-Cheat در فازهای بعدی روی یک پایه قابل اعتماد سوار شوند.  
مدل User شامل شناسه، نام، موبایل یا ایمیل، password\_hash، status، last\_login\_at و timestamps است. Roleهای سیستمی همان SuperAdmin، GameMaster و Player هستند؛ Roleهای بازی مانند Scanner یا Navigator جزو Runtime هستند و در Identity ذخیره نمی‌شوند.  
برای هر Login یک Device Session واقعی ثبت می‌شود. موجودیت user\_devices شامل user\_id، device\_uuid، device\_name، platform، app\_version، last\_seen\_at، last\_ip، revoked\_at و timestamps است. کاربر می‌تواند چند Device ثبت‌شده داشته باشد، اما در هر Game Session فقط یک Device فعال برای Player مجاز است.  
Device Binding: هنگام ورود Player به Game Session، active\_device\_id برای game\_session\_player ثبت و برای همان Session Freeze می‌شود. فقط Device Bind شده اجازه Ready، GPS Submit، QR Scan، Challenge Submit و AR Completion دارد. درخواست از Device دیگر باید با خطای استاندارد DEVICE\_NOT\_BOUND رد شود. تغییر Device در حین مسابقه فقط با اقدام مجاز Game Master انجام می‌شود و Event شامل old\_device\_id، new\_device\_id، changed\_by و reason در Audit ثبت می‌شود.  
Reconnect: قطع اینترنت یا بسته‌شدن اپ نباید Runtime را از بین ببرد. Player به وضعیت DISCONNECTED می‌رود و با Auth Token \+ Device Validation، State همان Session را Reload کرده و ادامه می‌دهد.  
User Statusهای پایه: ACTIVE، SUSPENDED و BLOCKED. وضعیت حضور در Session جداست و شامل JOINED، READY، ACTIVE، DISCONNECTED و REMOVED است. این دو State نباید با هم ترکیب شوند.  
Ready باید Device-aware باشد و فقط وقتی پذیرفته شود که User احراز هویت شده، Device به Session Bind شده، Team معتبر و Registration Session معتبر باشد.  
APIهای پایه این بخش: POST /api/v1/auth/register، POST /api/v1/auth/login، POST /api/v1/auth/logout، GET /api/v1/me، GET /api/v1/devices و DELETE /api/v1/devices/{device}. عملیات Bind/Rebind Device نیز در API Session به‌صورت Server-authorized اضافه می‌شود.  
Security baseline: Rate Limit برای Login، Token Revoke، Device Revoke، Password Hashing، Server-side Validation، Audit Login/Logout و Audit Device Change.  
Error Format استاندارد API: پاسخ خطا باید ساختار ثابت error.code، error.message و error.details داشته باشد تا همه ماژول‌ها از یک قرارداد واحد استفاده کنند.  
Definition of Done Phase 1.1: Register، Login، Logout، Read Profile، Register Device، List Devices، Revoke Device، Bind Device to Session، Reconnect و Reject Wrong Device باید کامل و تست‌شده باشند.

# 31\. Phase 1.2 — Team Management (FROZEN)

# Team یک موجودیت دائمی و reusable است و می‌تواند در Game Sessionهای مختلف شرکت کند. Team می‌تواند قبل از رسیدن به حداقل اعضا وجود داشته باشد، اما eligible\_for\_game فقط در بازه ۴ تا ۸ عضو فعال true است.

# هر Team دقیقاً یک Captain فعال دارد. Captain می‌تواند Invite بسازد/لغو کند، Member را قبل از Session Start مدیریت کند، Captaincy را منتقل کند، Team را برای Session ثبت کند و Pre-game Roster را مدیریت کند. Captain هیچ قدرت Gameplay ویژه‌ای ندارد مگر Scenario Role جداگانه.

# team\_members history-preserving است؛ joined\_at، left\_at و status نگه‌داری می‌شود و حذف فیزیکی عضویت تاریخی مجاز نیست. Team status حداقل ACTIVE و ARCHIVED است؛ Team دارای سابقه مسابقه Hard Delete نمی‌شود.

# Invite Code/Link دارای code امن، expires\_at، max\_uses، uses\_count و revoked\_at است. Join باید user active، invite valid، team active، capacity \< 8 و duplicate membership را validate کند. Team پر با TEAM\_FULL رد می‌شود.

# یک User می‌تواند Teamهای تاریخی مختلف داشته باشد، اما در یک Game Session فقط در یک Team Roster فعال حضور دارد. این قاعده با DB/Domain guard enforce می‌شود.

# قبل از Start، Team membership و Session roster می‌توانند تغییر کنند و Billing events تولید کنند. در Start، Session Roster Snapshot Freeze می‌شود و تغییر Team اصلی بعدی روی Session جاری اثر ندارد.

# Captaincy Transfer فقط به Member فعال همان Team مجاز و Audit شده است. اگر Captain از Team خارج شود، انتقال Captaincy قبل از خروج اجباری است.

# Domain Eventهای پایه: team.created، team.member\_joined، team.member\_left، team.member\_removed، team.captain\_changed، team.archived، session\_roster.player\_added و session\_roster.player\_removed.

# APIهای پایه: POST/GET/PATCH /api/v1/teams، POST /teams/{team}/invites، DELETE invite، POST /teams/join، POST /teams/{team}/leave، DELETE /teams/{team}/members/{user}، POST /teams/{team}/transfer-captaincy و POST /teams/{team}/archive.

# Definition of Done Phase 1.2: Create Team، Invite/Join، ظرفیت ۴..۸، Captain policies، Leave/Remove، Captain transfer، Archive، history preservation، Session roster compatibility، Billing events و Audit کامل و تست‌شده باشند.

# 

# 31\. مدل تجاری و Billing — Per-Player Per-Game (FROZEN)

مدل درآمدی اصلی محصول: هر Game Session بر اساس تعداد بازیکنان یکتای شرکت‌کننده هزینه دارد. واحد صورتحساب «یک Player یکتا در یک Game Session» است؛ Reconnect، تعویض Device، Login مجدد یا چند Device نباید باعث شارژ دوباره همان Player در همان Session شود.  
هر Game یا Game Session باید price\_per\_player، currency و Pricing Snapshot داشته باشد. مبلغ مؤثر هر Player در لحظه ایجاد تعهد مالی Snapshot می‌شود تا تغییر قیمت بعدی روی Session جاری یا خرید ثبت‌شده اثر نگذارد.  
Game Session فقط در صورتی قابل شروع است که وضعیت مالی موردنیاز آن معتبر باشد. حالت پیش‌فرض محصول Prepaid است؛ یعنی ظرفیت/بازیکنان قابل‌صورتحساب باید پیش از Start تسویه یا توسط اعتبار معتبر پوشش داده شوند. سیاست دقیق اینکه پرداخت توسط Captain، برگزارکننده یا هر Player انجام شود باید Configurable باشد و در طراحی Billing به یک Payer ثابت Hard-code نشود.  
اگر بازیکنی قبل از Start به Roster اضافه شود، مبلغ متناظر باید به سفارش اضافه یا Top-up شود. حذف Player پیش از Start باید طبق Refund/Credit Policy قابل برگشت یا آزادسازی باشد. بعد از Start، Billable Roster و قیمت‌های Snapshot شده Freeze می‌شوند؛ هر اصلاح مالی بعدی فقط با عملیات مجاز Admin/Game Master، دلیل اجباری و Audit Trail انجام می‌شود.  
پرداخت باید از طریق Payment Provider abstraction انجام شود تا درگاه پرداخت بدون تغییر Domain قابل تعویض باشد. اطلاعات حساس کارت بانکی نباید در سامانه ذخیره شود. Callback/Webhook پرداخت باید Idempotent باشد و موفقیت پرداخت فقط پس از Verify سمت Server قطعی شود.  
موجودیت‌های پایه Billing که باید از ابتدا در معماری پیش‌بینی شوند: pricing\_rules یا game\_prices، orders، order\_items با ارتباط مستقیم به Player \+ Game Session، payments، refunds/credits و billing\_events. هر Order/Payment باید وضعیت، amount، currency، provider\_reference، idempotency\_key و Audit قابل رهگیری داشته باشد.  
قاعده ضدتقلب مالی: یک Player در یک Session فقط یک Billable Participation دارد؛ حذف/افزودن مجدد، reconnect یا تعویض تیم نباید امکان دورزدن هزینه را ایجاد کند. محاسبه Billable Count باید Server-authoritative و بر اساس شناسه یکتای Player و Session باشد.  
Billing یک قابلیت تجاری هسته‌ای است و نباید به انتهای پروژه موکول شود. طراحی دیتامدل و Contractهای آن در Phase 1 انجام می‌شود؛ اتصال به درگاه واقعی می‌تواند پس از تثبیت Flow تیم و Game Session پیاده‌سازی و تست شود.  
32\. Store و Hint Packs (APPROVED)  
فروشگاه باید به‌عنوان Commerce Layer مستقل روی Billing سوار شود و امکان فروش Add-onهای اختیاری را فراهم کند. اولین Add-on رسمی محصول Hint Pack است.  
قاعده پایه Hint: هر Stage حداکثر ۳ Hint داخلی و پیش‌فرض دارد. این سه Hint جزو طراحی خود Stage هستند و ترتیب مصرف آن‌ها باید مشخص باشد. پس از مصرف سهمیه پیش‌فرض، تیم یا Player فقط در صورت داشتن Entitlement معتبر از Store می‌تواند Hint اضافی دریافت کند.  
Hint اضافی نباید جواب نهایی را مستقیماً افشا کند مگر اینکه Scenario Designer عمداً نوع «Direct Answer» را تعریف کرده باشد. سطح Hint می‌تواند Progressive باشد: Hint 1 سبک، Hint 2 واضح‌تر، Hint 3 نزدیک به پاسخ. Hintهای خریداری‌شده باید با همان Rule Engine و Audit مصرف شوند.  
Store Product باید Configurable باشد. Product Typeهای اولیه: EXTRA\_HINT\_PACK، BONUS\_HINT\_TOKEN و در آینده سایر Add-onها. هر Product دارای title، description، price، currency، quantity/credits، scope و status است. Scope می‌تواند Player، Team، Game Session یا Account باشد؛ برای Hint Pack پیش‌فرض پیشنهاد می‌شود Team \+ Game Session باشد تا کل تیم از خرید استفاده کند.  
موجودیت‌های پایه Commerce: store\_products، purchases/orders، purchase\_items، entitlements یا wallet\_credits، entitlement\_consumptions و commerce\_events. هر خرید باید به Payment Provider abstraction موجود متصل شود و Payment Verification، Idempotency، Refund/Credit و Audit را رعایت کند.  
مصرف Hint اضافی باید Server-authoritative و Atomic باشد تا دو عضو تیم همزمان نتوانند یک Credit را دوبار مصرف کنند. هر Consumption باید team\_id/player\_id، game\_session\_id، stage/node\_id، entitlement\_id، consumed\_at و source را ثبت کند.  
Fairness Rule: Add-onهای پولی نباید مسابقه را Pay-to-Win کنند. Hint خریداری‌شده فقط باید کمک اطلاعاتی بدهد و در صورت وجود Leaderboard رقابتی، استفاده از Hint می‌تواند Penalty امتیازی/زمانی داشته باشد که Rule آن در Scenario یا Game Config تعیین می‌شود. خرید Add-on نباید امکان Skip Stage، تغییر GPS validation، دورزدن QR/AR یا دست‌کاری Score را بدهد.  
Store باید قابل گسترش باشد تا بعداً Add-onهای غیر Hint نیز بدون تغییر Core اضافه شوند، اما هر Add-on جدید باید از نظر Fairness و سوءاستفاده قبل از فعال‌سازی Rule و Audit مخصوص خود را داشته باشد.

# 33\. Phase 1.3 — Game & Game Session (APPROVED)

اصل تفکیک: Game یک تعریف/محصول قابل استفاده مجدد است؛ Game Session یک اجرای واقعی و زمان‌مند از Game است. Game شامل تنظیمات پایه، Pricing Reference، قوانین عمومی، Store/Commerce availability و محدودیت‌های کلی است. Game Session شامل تاریخ/زمان اجرا، ظرفیت، تیم‌های ثبت‌شده، Pricing Snapshot، Scenario Assignment، Roster Snapshot، وضعیت Runtime و Timeline همان اجراست.  
Session Capacity: هر Game Session حداقل ۱ و حداکثر ۵ Team دارد. هر Team در زمان ثبت‌نام Session باید بین ۴ تا ۸ Player معتبر داشته باشد. ظرفیت Session باید Server-authoritative کنترل شود و ثبت تیم ششم با خطای SESSION\_TEAM\_CAPACITY\_REACHED رد شود.  
State Machine اصلی Game Session: DRAFT → REGISTRATION → READY → RUNNING ↔ PAUSED → FINISHED. وضعیت CANCELLED فقط از DRAFT/REGISTRATION/READY یا با عملیات اضطراری مجاز قبل از FINISHED قابل اعمال است. FINISHED و CANCELLED Terminal هستند و هیچ Reopen عمومی ندارند.  
Registration Window: مدیر برای Session می‌تواند زمان باز و بسته‌شدن ثبت‌نام تعریف کند. ثبت Team خارج از Window فقط با Override مجاز Admin/GameMaster، reason اجباری و Audit مجاز است.  
Pre-Game Validation: Start فقط وقتی مجاز است که حداقل یک Team ثبت شده باشد، هیچ Team بیشتر از ۸ یا کمتر از ۴ عضو نداشته باشد، برای هر Team یک Scenario Version معتبر Assign شده باشد، Billing وضعیت معتبر داشته باشد، حداقل ۴ Player در هر Team Ready باشند و حداقل ۴ Device معتبر Bind شده باشد.  
Ready Rule: برای Start لازم نیست همه ۵ تا ۸ عضو Ready باشند؛ حداقل ۴ Player Ready کافی است. Playerهای اضافه اگر از قبل عضو Roster Snapshot باشند می‌توانند طبق Late Join Policy بعداً وارد Runtime شوند.  
Late Join Policy پیش‌فرض: ENABLED فقط برای Playerهایی که قبل از Start داخل Roster Snapshot بوده‌اند. افزودن عضو جدید بعد از Start ممنوع است. Late Join Player بعد از ورود باید Device Bind معتبر داشته باشد و Billing آن از قبل در Billable Roster پوشش داده شده باشد.  
Roster Freeze: در لحظه Start برای هر Team یک Session Roster Snapshot ساخته و Freeze می‌شود. این Snapshot شامل Playerها، Team Membership، Active Device Binding، Billable Participation، Pricing Snapshot و Eligibility است. تغییر عضویت Team اصلی بعد از Start نباید روی Session جاری اثر بگذارد.  
Scenario Assignment: قبل از Start هر Team دقیقاً یک scenario\_version\_id معتبر دارد. Assignment می‌تواند Manual یا Automatic باشد. Runtime باید نسخه سناریو را Pin کند تا Publish نسخه جدید روی Session جاری اثر نگذارد.  
Session Start: Start یک Command حساس، Transactional و Idempotent است. هنگام Start باید به‌صورت Atomic این موارد انجام شود: Validate Session، Validate Billing، Freeze Roster، Freeze Pricing Snapshot، Freeze Scenario Version، ایجاد game\_session\_players، ایجاد team\_scenario\_instances، ثبت Session Started Event و Transition به RUNNING. اگر هر بخش Fail شود کل Start Rollback می‌شود.  
Pause/Resume: GameMaster می‌تواند کل Session یا Team مشخص را Pause/Resume کند. Pause باید Timerهای مؤثر بازی را متوقف کند و Runtime State را حفظ کند. Resume باید از همان State ادامه دهد. Pause/Resume همیشه Audit و Timeline Event دارد.  
Finish: FINISH فقط وقتی مجاز است که Session RUNNING یا PAUSED باشد. Finish باید final\_score، final\_runtime\_state، consumed entitlements، billing summary و timeline closure را Snapshot کند. پس از FINISHED تغییر Score یا Runtime فقط از مسیر Adjustment/Audit مخصوص و بدون Reopen مجاز است.  
Cancel: قبل از Start، Cancel باید Billing/Reservation release یا Refund/Credit policy را اجرا کند. بعد از Start فقط Emergency Cancel با Permission بالاتر مجاز است و باید reason، actor و financial resolution ثبت شود.  
Team Registration: Team می‌تواند در چند Game Session مختلف شرکت کند، اما یک Team در یک Session فقط یک registration فعال دارد. یک User در یک Session نمی‌تواند همزمان در بیش از یک Team Roster باشد.  
Game Session Timeline: Eventهای پایه شامل session.created، registration.opened، team.registered، player.ready، scenario.assigned، billing.validated، session.started، team.paused، team.resumed، session.paused، session.resumed، player.late\_joined، player.disconnected، player.reconnected، session.finished و session.cancelled است.  
Billing Gate: Session Start نباید Billing را دور بزند. Billable Player Count بر اساس Roster Snapshot نهایی و شناسه یکتای Player در Session محاسبه می‌شود. Payment/credit validity باید قبل از Start Verify شده باشد. هر Top-up قبل از Start باید قبل از Freeze کامل شود.  
Commerce Integration: Store purchase و Hint Pack به Game Session و Team قابل Scope هستند. Entitlementهای Session-scoped پس از FINISHED یا CANCELLED طبق Product Policy منقضی یا قابل انتقال می‌شوند؛ این رفتار باید Configurable باشد و در Purchase Snapshot ثبت شود.  
APIهای اصلی این بخش: POST /api/v1/games، GET/PATCH /api/v1/games/{game}، POST /api/v1/game-sessions، GET/PATCH /api/v1/game-sessions/{session}، POST /api/v1/game-sessions/{session}/open-registration، POST /api/v1/game-sessions/{session}/register-team، POST /api/v1/game-sessions/{session}/ready، POST /api/v1/game-sessions/{session}/start، POST /api/v1/game-sessions/{session}/pause، POST /api/v1/game-sessions/{session}/resume، POST /api/v1/game-sessions/{session}/finish، POST /api/v1/game-sessions/{session}/cancel، GET /api/v1/game-sessions/{session}/state و GET /api/v1/game-sessions/{session}/timeline.  
Definition of Done Phase 1.3: ایجاد Game، ایجاد Session، Registration Window، ثبت ۱ تا ۵ Team، Ready حداقل ۴ Player در هر Team، Billing Gate، Scenario Assignment، Atomic Start، Roster/Price/Scenario Freeze، Late Join Roster-only، Pause/Resume، Reconnect compatibility، Finish/Cancel و Timeline باید کامل و تست‌شده باشند.  
34\. Phase 1.4 — Runtime State & Event Timeline (FROZEN)  
Runtime مرجع نهایی اجرای بازی است و Server-authoritative باقی می‌ماند. برای هر Team در هر Session یک team\_scenario\_instance و برای هر Player یک game\_session\_player runtime وجود دارد. Runtime شامل current\_node، status، score state، active challenge slots، support players، timers، pause state، role assignments، consumed hints، active live challenges، last acknowledged event و timestamps است.  
هر Transition باید Command-based باشد و مستقیماً با update پراکنده جدول‌ها انجام نشود. Commandهای اصلی: start\_node، submit\_action، complete\_node، fail\_node، assign\_role، consume\_hint، pause\_team، resume\_team، reconnect\_player، finish\_team. هر Command باید Authorization، current-state guard، idempotency و optimistic/pessimistic concurrency protection داشته باشد.  
Event Timeline append-only است. رخدادهای Domain با event\_id یکتا، aggregate\_type، aggregate\_id، session\_id، team\_id، player\_id، event\_type، payload\_version، payload، occurred\_at، actor\_type و actor\_id ذخیره می‌شوند. Eventهای ثبت‌شده برای Audit نباید update/delete عادی شوند؛ اصلاح فقط با compensating event مجاز است.  
Redis برای hot state و presence/cache استفاده می‌شود، ولی PostgreSQL منبع پایدار و قابل بازیابی است. هر state حیاتی باید از DB قابل reconstruct باشد. از Redis-only state برای Score، Completion، Payment، Entitlement یا Scenario Progress استفاده نمی‌شود.  
Presence شامل online/offline/last\_seen است و eventual consistency آن قابل قبول است؛ اما Completion، Score، Billing، Hint Consumption و State Transition باید strong-consistency داشته باشند.  
Timerها بر اساس server timestamps و accumulated\_paused\_duration محاسبه می‌شوند؛ Countdown حیاتی نباید به ساعت Device اعتماد کند. Pause باید روی timerهای gameplay اعمال شود اما زمان Audit واقعی را تغییر ندهد.  
Outbox Pattern برای Domain Eventهای نیازمند Queue/Realtime استفاده می‌شود تا commit دیتابیس و انتشار event از هم جدا نشوند. Workerها باید idempotent باشند و retry/backoff داشته باشند.  
Definition of Done: Runtime مستقل Team، Player Presence، Command Guards، Concurrency، append-only Timeline، Outbox، Pause-aware timers، Reconnect restore و deterministic state read باید تست‌شده باشند.

35\. Phase 1.5 — Billing, Payments & Commerce Core (FROZEN)  
مدل تجاری پایه Per-Player Per-Game و Store/Add-on از این فاز جزو Core است. Billing، Payment و Commerce از Gameplay جدا ولی با Contract مشخص به Session متصل هستند.  
موجودیت‌های اصلی: pricing\_rules، pricing\_snapshots، orders، order\_items، billable\_participations، payments، refunds، credits، billing\_events، store\_products، entitlements، entitlement\_consumptions و commerce\_events.  
هر Player یکتا در هر Session فقط یک billable\_participation دارد. reconnect، device change، team rejoin یا retry نباید charge جدید تولید کند. Unique constraint در DB این قاعده را enforce می‌کند.  
Payment Provider abstraction اجباری است. Callback/verify باید idempotent و server-verified باشد. هیچ اطلاعات خام کارت بانکی ذخیره نمی‌شود. وضعیت‌های Payment حداقل CREATED، PENDING، VERIFIED، FAILED، CANCELLED و REFUNDED است.  
قیمت و Currency در Pricing Snapshot Freeze می‌شوند. Session Start فقط وقتی Billing Gate PASS باشد مجاز است. Admin override مالی فقط با permission سطح بالا، reason اجباری و Audit کامل مجاز است.  
Store محصول generic دارد. Hint Pack اولین Add-on رسمی است. هر Stage حداکثر ۳ Hint پیش‌فرض دارد؛ Hint اضافه از Entitlement خریداری‌شده مصرف می‌شود. مصرف Credit اتمیک است و Fairness Rule اجازه Skip/Score manipulation/GPS bypass/QR bypass/AR bypass نمی‌دهد.  
Commerce باید از Coupon/Promotion در آینده پشتیبانی‌پذیر باشد، اما در نسخه اولیه Coupon Engine جزو Runtime Gameplay نیست و نباید پیچیدگی Session را افزایش دهد.  
Definition of Done: pricing snapshot، order/payment lifecycle، billing gate، refund/credit پایه، store product، hint entitlement، atomic consumption، audit و idempotency کامل و تست‌شده باشند.

36\. Phase 1.6 — Organizer / Multi-Tenant Baseline (FROZEN)  
برای جلوگیری از بازطراحی تجاری در آینده، محصول از ابتدا Tenant-aware طراحی می‌شود. موجودیت Organization/Organizer مالک Games، Sessions، Pricing، Store Products و GameMaster assignments است.  
نسخه اولیه می‌تواند فقط یک Organization فعال داشته باشد، اما همه resourceهای تجاری باید organization\_id داشته باشند و Query Scope/Policy سمت Server مانع دسترسی بین Tenantها شود.  
Roleهای سیستمی به scope وابسته می‌شوند: SuperAdmin سراسری، OrganizationAdmin در Tenant، GameMaster روی Game/Sessionهای مجاز و Player روی داده‌های خودش. هیچ authorization بر اساس UI پنهان‌سازی کافی نیست.  
Branding، invoice profile، payment provider config و commercial settings در سطح Organization قابل توسعه هستند.  
Definition of Done: organization scoping، role scope، tenant-safe queries، ownership guards و تست جلوگیری از cross-tenant leakage.

37\. Phase 1 — Core Commercial Foundation (FROZEN)  
وضعیت Phase 1 پس از پیاده‌سازی Sections 1.1 تا 1.6: READY FOR IMPLEMENTATION. Phase 1 شامل Identity/Device، Team، Game/Session، Runtime/Timeline، Billing/Commerce و Tenant baseline است. هیچ GPS/QR/AR واقعی در Phase 1 لازم نیست، اما Contractهای آن‌ها نباید نیازمند تغییر شکستن Core در فازهای بعد باشند.

38\. Phase 2 — GPS, Map, Checkpoint & PostGIS (FROZEN DESIGN)  
Location ingestion از Flutter شامل lat، lng، accuracy، altitude اختیاری، speed اختیاری، heading اختیاری، client\_timestamp و monotonic sequence است. Server received\_at مرجع زمانی Audit است.  
Tracking Adaptive است: cadence پایه در حرکت، cadence سریع‌تر نزدیک Checkpoint و cadence کندتر در سکون. مقدار دقیق cadence Configurable است و Server می‌تواند policy را برای Session تغییر دهد.  
Latest Location در Redis cache می‌شود؛ Raw Location Event در PostgreSQL/PostGIS ذخیره می‌شود. geometry(Point,4326) با GiST index الزامی است. Checkpoint می‌تواند Point+radius یا Polygon Zone باشد.  
Geofence verification با PostGIS و accuracy-aware logic انجام می‌شود. یک GPS sample با accuracy ضعیف نباید به‌تنهایی Completion حساس را تأیید کند. Node می‌تواند min\_accuracy، dwell\_time، required\_player\_count و required\_roles داشته باشد.  
Route history از Raw points جداست. برای Replay و Analytics مسیر simplified تولید می‌شود. Raw GPS retention محدود، partitioned و archive/purge policy دارد.  
Background location فقط هنگام Game Session فعال و با رضایت/permission لازم استفاده می‌شود. وقتی Player در Session فعال نیست، tracking بازی باید خاموش باشد.  
Offline Location Outbox در Mobile نگه‌داری می‌شود؛ replay به Server با sequence/idempotency انجام می‌شود. Server باید late/out-of-order points را تشخیص دهد و با event\_time و received\_at جداگانه نگه دارد.  
Map UI شامل موقعیت خود Player، Team context موردنیاز، Checkpointهای مجاز و وضعیت Stage است؛ موقعیت رقبا به‌صورت پیش‌فرض نمایش داده نمی‌شود مگر Scenario صریحاً اجازه دهد.  
Definition of Done: live location، PostGIS schema/index، checkpoint verification، geofence، adaptive tracking، offline replay، retention، privacy controls و load test location ingestion.

39\. Phase 3 — Scenario Core, Versioning & Runtime Engine (FROZEN DESIGN)  
Scenario موجودیت reusable و ScenarioVersion immutable-after-publish است. Draft قابل ویرایش است؛ Publish نسخه جدید می‌سازد و Sessionها فقط version\_id pinned را اجرا می‌کنند.  
Graph از Node و Edge ساخته می‌شود. Node Type از Registry/Contract استفاده می‌کند و هر Type دارای config schema، validation، runtime handler، completion contract و presentation metadata است.  
Nodeهای Core: Start، End، Message، GPS/Location، QR، AR، Puzzle، Password، Multiple Choice، Text Answer، Timer، Assign Roles، Team Together، Split Team، Random/Specific Player، Branch، Condition، Score Action، Hint، Wait و Subflow.  
Condition Engine با DSL محدود و allow-list شده اجرا می‌شود؛ اجرای کد دلخواه/eval ممنوع است. Variables typed هستند و read/write scope دارند.  
Scenario Seed برای انتخاب‌های random در شروع Team Instance Freeze می‌شود تا رفتار قابل بازتولید و Audit باشد.  
Subflow versioned است و هنگام Publish dependency version آن pin می‌شود. تغییر Subflow بعدی نباید نسخه Published قبلی را عوض کند.  
Validation شامل exactly one Start، حداقل یک End، node key uniqueness، no dangling edge، reachable End، unsupported node rejection، invalid condition rejection، invalid subflow dependency، loop policy و config schema validation است.  
Runtime transitionها فقط از Engine انجام می‌شوند و Client هیچ current\_node دلخواهی تعیین نمی‌کند.  
Definition of Done: create/publish/version، graph validation، deterministic runtime، variables/conditions، subflow pinning، runtime handler contract و tests.

40\. Phase 4 — Visual Scenario Builder & Simulation (FROZEN DESIGN)  
Builder وب با graph editor حرفه‌ای ساخته می‌شود؛ React Flow یا معادل پایدار قابل استفاده است. Canvas فقط presentation است و Backend schema/validation مرجع نهایی است.  
امکانات لازم: Drag/Drop Node، connect edge، node config form، typed condition builder، variables browser، copy/paste، duplicate، undo/redo، autosave Draft، validation panel، publish flow، version history و read-only Published view.  
هر Node Config بر اساس JSON/schema metadata خود Node Type فرم می‌سازد تا اضافه‌کردن Node جدید نیازمند ساخت فرم دستی کامل نباشد.  
Simulation Mode بدون GPS واقعی باید بتواند مسیرهای Scenario را با ورودی mock اجرا کند، Branchها را تست کند و dead-endها را نشان دهد.  
Preview باید Team size 4 تا 8، چهار Active Slot و Support rotation را شبیه‌سازی کند.  
Publish دو مرحله‌ای است: Validate سپس explicit Publish. Publish بدون validation PASS ممنوع است.  
Definition of Done: Builder، schema-driven forms، conditions، validation، simulation، publish/versioning، permissions و usability test.  
41\. Phase 5 — QR & Dynamic Trigger Engine (FROZEN DESIGN)  
QR فقط Trigger Identifier است و نباید Secret/Answer خام داخل QR ذخیره شود. QR فیزیکی می‌تواند static باشد اما پاسخ Server بر اساس session\_id، team\_id، scenario\_version، node\_id و runtime state متفاوت تولید شود.  
Scan Request شامل qr\_identifier، session/team/player context، device binding و optional location evidence است. Server باید eligibility، active node، player permission، rate limit و replay protection را بررسی کند.  
برای Nodeهای حساس، Scan فقط در geofence معتبر و با device bound پذیرفته می‌شود. Node می‌تواند specific\_player، active\_slot یا any\_team\_member policy داشته باشد.  
هر Scan موفق یک one-time challenge token کوتاه‌عمر تولید می‌کند. Token باید signed/opaque، session-bound و replay-protected باشد.  
QR abuse detection شامل scan burst، scan خارج از Stage، استفاده QR اشتباه، replay token، cross-team token و impossible location است.  
Dynamic Trigger Registry باید بعداً NFC/BLE/IoT را بدون تغییر Scenario Core پشتیبانی کند؛ IoT در نسخه فعلی disabled است.  
Definition of Done: QR registry، scan validation، dynamic response، one-time token، permission policies، anti-replay، geofence integration و tests.

42\. Phase 6 — Co-op Roles, Four Active Slots & Team Mechanics (FROZEN DESIGN)  
هر Team بین ۴ تا ۸ عضو دارد اما هر Stage حداکثر چهار Active Challenge Slot دارد. اعضای خارج از Slot Support هستند و می‌توانند در جست‌وجو/حل/هماهنگی کمک کنند یا Challenge فردی دریافت کنند.  
Slot Assignment policy قابل تنظیم است: rotation، least-recently-used، random seeded، specific role requirement یا GameMaster manual override. default باید fairness-aware باشد تا همه اعضا در طول بازی فرصت مشارکت داشته باشند.  
Roleهای پایه: Scanner، Navigator، Decoder و AR Operator. Role Typeها extensible هستند و Scenario می‌تواند role label سفارشی داشته باشد ولی capability contract ثابت بماند.  
Mechanicهای Core: Split Information، Simultaneous Action، Team Together، Split Team، Chain Unlock، Relay، Secret Task، Multi-point Presence و Synchronization Barrier.  
Barrier Node فقط وقتی تکمیل می‌شود که شروط همه subtaskهای لازم پاس شده باشد. completion باید Server-side و idempotent باشد.  
Team size change بعد از Start ممنوع است؛ Late Join فقط Roster member قبلی. Rotation باید late join را از Stage بعدی قابل استفاده کند مگر Node فعلی explicit allow داشته باشد.  
هیچ Stage مهمی نباید به یک Device واحد وابسته باشد مگر عمداً Solo Challenge تعریف شده باشد.  
Definition of Done: active slots، rotation fairness، role assignment، split/sync mechanics، solo challenge، support behavior و tests برای Team size 4..8.

43\. Phase 7 — AR Runtime & Content Delivery (FROZEN DESIGN)  
برای تجربه‌های ساده می‌توان native ARCore/ARKit bridge داشت، اما معماری رسمی AR باید امکان Unity module برای scene/puzzle پیچیده را فراهم کند. Flutter مالک App shell و Runtime context است؛ AR module فقط challenge scene را اجرا و result signed/validated برمی‌گرداند.  
AR Challenge تعریف versioned دارد: scene\_id، asset\_bundle\_version، supported\_platforms، required\_capabilities، entry\_contract، completion\_contract و fallback\_policy.  
Assetها باید از App binary جدا قابل version باشند؛ download/cache با checksum، version pin و rollback-safe manifest انجام می‌شود. Session باید asset version موردنیاز ScenarioVersion را pin کند.  
اگر Device AR support کافی ندارد، Scenario باید fallback تعریف کند؛ اگر fallback وجود ندارد Player قبل از Start incompatible شناخته می‌شود و تیم باید Device مناسب داشته باشد.  
Completion فقط بر اساس client boolean پذیرفته نمی‌شود. AR module یک completion payload با challenge nonce، event sequence و evidence metadata می‌فرستد و Server current-state را verify می‌کند.  
AR UX در فضای شهری نباید بازیکن را مجبور کند هنگام عبور از خیابان یا حرکت سریع به صفحه خیره شود. Nodeهای AR باید stationary-safe طراحی شوند و Route/Checkpoint review این موضوع را پوشش دهد.  
Definition of Done: Flutter↔AR contract، scene versioning، asset caching، capability check، fallback، completion verification، offline cached scene support و field test.

44\. Phase 8 — Live Game Master & Challenge Engine (FROZEN DESIGN)  
Live Challenge لایه موازی با Main Scenario است و default نباید current node را reset کند. Target می‌تواند Player، subset of Players، Team، چند Team یا کل Session باشد.  
Challenge Template versioned و reusable است. Typeهای اولیه: GPS Mission، QR، AR، Puzzle، Photo، Timer، Message/Secret Mission، Role Swap، Bonus Objective و Team Objective.  
هر Challenge دارای priority، starts\_at، expires\_at، duration، target policy، reward/penalty، interrupt\_policy، completion\_rule و fairness\_class است.  
GameMaster نمی‌تواند بدون Audit امتیاز یا زمان را دلخواه تغییر دهد. Manual Adjustment باید reason اجباری، before/after value و actor داشته باشد.  
برای مسابقه رقابتی، سیستم fairness guard دارد: challengeهای targeted که روی leaderboard اثر محسوس دارند باید در policy Session مجاز باشند. Challenge غیرمنصفانه می‌تواند cosmetic/side objective باشد یا امتیاز محدود داشته باشد.  
Realtime delivery از WebSocket انجام می‌شود و fallback polling برای reconnect وجود دارد. Ack/Seen/Started/Completed state ثبت می‌شود.  
اگر Challenge هنگام offline بودن Player ارسال شود، expiry policy تعیین می‌کند که بعد از reconnect قابل اجرا هست یا نه.  
Definition of Done: templates، targeting، realtime delivery، state lifecycle، fairness caps، manual adjustment audit، expiry/reconnect behavior و tests.

45\. Phase 9 — Scoring, Hints, Leaderboard & Rewards (FROZEN DESIGN)  
Scoring Rule در Game/Scenario Version snapshot می‌شود. Score calculation باید deterministic و Server-authoritative باشد.  
Score Sourceهای پایه: node completion، time bonus، first-attempt bonus، no-hint bonus، live challenge reward، penalties، manual audited adjustment. هیچ Client endpointی مقدار score دلخواه نمی‌پذیرد.  
Hint Rule: هر Stage حداکثر ۳ Hint داخلی دارد. Hint Consumption ترتیب‌دار و progressive است. Extra Hint فقط با Entitlement معتبر و atomic consumption. Scenario می‌تواند score/time penalty برای هر hint level تعریف کند.  
Leaderboard از persistent score state تولید می‌شود و Redis فقط cache/realtime view است. DB source of truth باقی می‌ماند.  
Tie-breaker پیش‌فرض: higher score، سپس lower effective elapsed time، سپس fewer hints، سپس earlier finish timestamp. این ترتیب در Session snapshot ذخیره می‌شود.  
Leaderboard visibility configurable است: full ranking، top-N، own rank only، hidden-until-finish.  
Achievement/Reward framework metadata-only در این فاز اضافه می‌شود تا بعداً badge/reward بدون تغییر score core اضافه شود.  
Definition of Done: deterministic scoring، hint penalties، extra hint integration، leaderboard cache، tie-breaker، visibility modes، adjustment audit و tests.

46\. Phase 10 — Anti-Cheat, Trust & Abuse Prevention (FROZEN DESIGN)  
Anti-Cheat چندلایه است و هدف آن Flag/Prevent رفتار نامعتبر است نه اتکای کامل به یک سیگنال.  
Device layer: bound device، token/session validation، optional platform attestation، root/jailbreak/emulator signal به‌عنوان risk signal نه proof قطعی.  
Location layer: mock-location signals در صورت دسترسی، impossible travel، unrealistic speed، teleport، poor accuracy abuse، stale location، sequence gap و checkpoint mismatch.  
QR/Trigger layer: scan rate limit، replay detection، wrong-stage scan، cross-team token، copied token و location mismatch.  
Runtime layer: invalid transition، duplicate completion، stale version، out-of-order action، direct score mutation attempt و bypass of required subtask.  
Commerce layer: duplicate callback، replayed payment verify، entitlement double-spend، refund abuse و billable participation bypass.  
Risk Engine هر event را severity و reason code می‌دهد. رفتار مشکوک می‌تواند allow+flag، soft block، hard block یا require GameMaster review باشد.  
GameMaster dashboard باید flags و evidence summary را نمایش دهد ولی تصمیم انسانی برای موارد مبهم حفظ شود.  
Ban/Suspension User از Session Disqualification جداست. همه enforcementها Audit می‌شوند.

Definition of Done: rules، risk flags، enforcement policies، dashboard feed، evidence retention، false-positive test و security test.  
47\. Phase 10 Exit Gate — MVP Competition Ready  
وقتی Phase 0 تا 10 پیاده‌سازی و Definition of Doneهایشان PASS شوند، محصول از نظر قابلیت‌های اصلی برای برگزاری مسابقه پولی واقعی آماده Pilot است: Team 4..8، Session 1..5 Teams، Billing، Store، GPS، Scenario Builder، QR، AR، Co-op، GameMaster، Scoring و Anti-Cheat.

48\. Phase 11 — Admin, Content Operations & Media (FROZEN DESIGN)  
پنل Admin باید از عملیات روزمره بدون نیاز به Developer پشتیبانی کند: مدیریت Organization، Users، Teams، Games، Sessions، Scenarios، Pricing، Store، Payments، Challenge Templates، Media و Audit.  
Scenario Library دارای search، filter، tags، status، duplicate، archive، version history و dependency view است. Published versionها read-only هستند.  
Media Library برای image/audio/video/3D asset دارای ownership، mime/type validation، size limit، checksum، version، usage references و safe delete guard است؛ asset در حال استفاده Hard Delete نمی‌شود.  
Localization از ابتدا key-based است. متن‌های Scenario، Store و UI content باید قابلیت fa/en و زبان‌های بعدی داشته باشند؛ direction RTL/LTR بخشی از presentation است نه data model.  
Admin Actions حساس مثل refund، score adjustment، device rebind، emergency cancel، disqualification، pricing change و publish همگی permission \+ reason \+ audit دارند.  
Bulk import/export فقط از format versioned و validated انجام می‌شود و bypass domain rules ممنوع است.  
Definition of Done: Admin RBAC، content library، media management، version-safe operations، localization readiness، audit و operational usability.

49\. Phase 12 — Analytics, Replay & Business Intelligence (FROZEN DESIGN)  
Analytics بر Event Schema نسخه‌بندی‌شده ساخته می‌شود، نه query مستقیم از جداول عملیاتی برای هر گزارش. Eventها باید session/team/player/scenario/node dimensions و timestamps استاندارد داشته باشند.  
گزارش‌های پایه: Sessions/day، players/session، completion rate، average duration، stage drop-off، hint usage، AR/QR failure rate، team size، disconnect rate، anti-cheat flags، revenue/session، revenue/player، store conversion، refund rate و scenario performance.  
Replay عملیاتی باید timeline \+ simplified route \+ node transitions \+ live challenge events را بازسازی کند؛ Replay ابزار Audit/Support است و Raw GPS کامل فقط طبق retention/privacy policy قابل دسترسی است.  
Dashboard GameMaster زنده از operational read model استفاده می‌کند؛ BI سنگین نباید DB عملیاتی را کند کند. در scale بالاتر read replica/warehouse/export pipeline قابل اضافه‌شدن است.  
PII باید از analytics eventهای غیرضروری حذف شود و identifierهای pseudonymous برای گزارش aggregate ترجیح دارند.  
Definition of Done: event taxonomy، KPI definitions، replay، operational dashboards، commerce analytics، privacy-safe export و performance isolation.

50\. Phase 13 — Offline, Resilience & Recovery (FROZEN DESIGN)  
Mobile دارای durable local database و Outbox است. Actionها سه دسته‌اند: offline-safe، offline-queueable و online-required.  
offline-safe: مشاهده محتوای cache شده، اجرای AR asset cache شده و ثبت local progress موقت. offline-queueable: location samples، acknowledgements و برخی puzzle submissions که server reconciliation دارند. online-required: Payment، Store purchase، GameMaster live command، حساس‌ترین QR token exchange و هر action که بدون Server امکان تقلب جدی دارد.  
هر queued action دارای client\_action\_id/idempotency\_key، sequence، created\_at، payload\_version و dependency references است. Retry با exponential backoff و jitter انجام می‌شود.  
Conflict Policy بر اساس domain است؛ Client هرگز Server Runtime را overwrite نمی‌کند. در conflict، Server state پیروز است و Mobile state reconcile می‌شود؛ اگر action معتبر ولی دیر رسیده باشد event با late-arrival metadata ثبت می‌شود.  
Session package شامل ScenarioVersion، allowed assets، config snapshots و minimal offline data با signature/checksum cache می‌شود. Published content بعد از Start به شکل silent تغییر نمی‌کند.  
Recovery بعد از crash/app reinstall/device replacement از Server Snapshot \+ Event Timeline انجام می‌شود. Device replacement همان policy audited Phase 1.1 را رعایت می‌کند.  
Definition of Done: durable outbox، retries، offline cache، conflict handling، session package integrity، crash recovery و network chaos tests.

51\. Phase 14 — Production Hardening, Observability & Security (FROZEN DESIGN)  
Deployment containerized و stateless App nodes است. Load Balancer، multiple Laravel app instances، Queue Worker pools، Redis و PostgreSQL/PostGIS اجزای scale-out رسمی هستند.  
Observability شامل structured logs، metrics، traces/request correlation، queue depth، websocket connections، DB latency، cache hit rate، location ingestion rate، payment errors، runtime transition errors و anti-cheat rates است.  
SLO اولیه: critical API availability، payment verification reliability، session start success و runtime command latency باید تعریف و مانیتور شوند. Alertها برای payment failures، queue backlog، DB saturation، Redis outage، realtime outage و elevated 5xx وجود دارد.  
Load Test باید حداقل target بیش از ۱۰۰۰ concurrent users، location ingestion burst، concurrent session starts، leaderboard updates، WebSocket fan-out و payment callbacks را پوشش دهد.  
Database: tested indexes، partition maintenance، vacuum/analyze policy، backup schedule، point-in-time recovery در صورت زیرساخت مناسب و Restore Drill دوره‌ای.  
Security: dependency scanning، secret scanning، rate limiting، authorization tests، tenant isolation tests، OWASP API review، file upload hardening، webhook signature/verification، replay protection و penetration test قبل از commercial release.  
Disaster Recovery Runbook شامل RPO/RTO هدف، backup restore، Redis loss behavior، degraded realtime mode و emergency maintenance mode است.  
Definition of Done: load/security tests PASS، observability dashboards، alerts، restore drill، runbooks و release checklist.

52\. Phase 15 — Field Pilot & Operational Readiness (FROZEN DESIGN)  
Pilot ابتدا با تعداد محدود Session واقعی اجرا می‌شود و قبل از فروش گسترده باید field evidence جمع شود. Pilot باید شرایط واقعی GPS، نور روز، باتری، mobile data، AR support، QR damage، crowd behavior و reconnect را پوشش دهد.  
هر Route قبل از انتشار توسط Organizer review می‌شود: crossing خطرناک، ملک خصوصی، محدوده ممنوع، ساعات نامناسب، accessibility و نقاطی که توقف بازیکن خطر ایجاد می‌کند بررسی می‌شوند.  
Game Safety Rule: Scenario نباید بازیکن را به دویدن در خیابان، استفاده از گوشی هنگام عبور، ورود غیرمجاز یا رفتار خطرناک تشویق کند. GameMaster امکان Pause/Emergency Message/Emergency Cancel دارد.  
Operational Runbook برای GameMaster شامل preflight check، team readiness، payment status، device compatibility، QR/AR check، escalation، player support، incident handling و closeout است.  
Support tooling باید Session lookup، Player/Device state، payment trace، timeline replay، entitlement state و controlled recovery actions را در اختیار اپراتور مجاز قرار دهد.  
Feedback بعد از Pilot به issue taxonomy تبدیل می‌شود: gameplay، UX، location، AR، performance، fairness، commerce و operations. تغییرات breaking فقط با version migration وارد می‌شوند.  
Definition of Done: pilot sessions موفق، safety review، support/runbook، issue closure criteria، UX fixes و go/no-go checklist.

53\. Phase 16 — Commercial Release & Scale-out (FROZEN DESIGN)  
Commercial Release فقط پس از Exit Gate فاز ۱۴ و Pilot Gate فاز ۱۵ انجام می‌شود.  
مدل عرضه از ابتدا هم B2C مستقیم و هم B2B Organizer-compatible است. Organization می‌تواند Games/Sessions/Pricing/Branding/GameMasters خود را در scope مجاز مدیریت کند.  
Commercial controls شامل Terms/Privacy consent، location disclosure، refund policy، age/eligibility policy قابل تنظیم با بازار، receipts/invoices قابل توسعه، customer support references و data retention policy است. جزئیات حقوقی هر بازار باید قبل از launch همان بازار توسط مشاور حقوقی محلی نهایی شود.  
Pricing Engine باید بتواند price per player، organization-specific pricing، promotion/coupon در آینده و Store add-ons را بدون تغییر gameplay core پشتیبانی کند.  
Feature flags برای rollout تدریجی AR scenes، new node types، store products و experiments استفاده می‌شوند؛ feature flag نباید authorization را جایگزین کند.  
Release Management شامل semantic/app version، backward-compatible API policy، mobile minimum\_supported\_version، forced update فقط برای موارد ضروری و migration/runbook است.  
Capacity scaling افقی App/Worker ممکن است بدون تغییر Domain انجام شود. DB bottleneck در scale بالاتر با query optimization، partitioning، read replicas برای analytics و در نهایت service extraction فقط در صورت نیاز واقعی حل می‌شود؛ Microservice از ابتدا هدف نیست.  
Commercial KPIهای اصلی: paid players، revenue/player، session completion، repeat teams، refund rate، store attach rate، support rate و organizer retention.  
Definition of Done: production launch checklist، legal/commercial readiness، payment production verification، support coverage، feature flags، capacity validation و first-release monitoring.

54\. Global Non-Functional Requirements (FROZEN)  
تمام timestampها در Backend به UTC ذخیره و در UI با timezone Session/User نمایش داده می‌شوند. شناسه‌های عمومی نباید sequence قابل حدس ساده برای عملیات حساس باشند؛ UUID/ULID یا opaque public id ترجیح دارد.  
تمام API mutationهای حساس باید idempotency strategy داشته باشند. Pagination برای listهای رشدپذیر اجباری است. N+1 query، unbounded query و unbounded export ممنوع است.  
Data retention قابل تنظیم است و delete/anonymization workflow باید بدون شکستن financial/audit obligations طراحی شود.  
Backward compatibility API تا زمان deprecation رسمی حفظ می‌شود. Mobile client باید graceful handling برای unknown fields و server-declared minimum version داشته باشد.  
Accessibility، RTL، outdoor readability، touch target مناسب و battery/network efficiency الزامات UX هستند.  
هیچ قابلیت جدیدی نباید بدون Authorization، Audit impact، Scale impact، Offline behavior، Fairness impact و Test plan وارد Core شود.

55\. Test Strategy & Quality Gates (FROZEN)  
Unit Tests برای domain rules و calculators؛ Feature/API Tests برای auth/policies/validation؛ Integration Tests برای PostgreSQL/PostGIS/Redis/Queue/Payment callbacks؛ Contract Tests برای Flutter/API و AR bridge؛ Concurrency Tests برای Start/Score/Hint/Payment؛ Load Tests برای scale target؛ Field Tests برای GPS/AR؛ Security Tests برای auth/tenant/payment/file upload.  
هر Phase فقط وقتی Implementation Complete محسوب می‌شود که Definition of Done، automated tests، migration rollback/forward plan، observability hooks و documentation آن PASS باشد.  
Critical flows دارای end-to-end test هستند: paid registration→ready→start→scenario progress→hint purchase→QR/AR→live challenge→finish→leaderboard؛ reconnect/device replacement؛ cancellation/refund؛ offline/reconcile.  
Production deploy باید health check، migration check، queue check، cache/realtime check و smoke test داشته باشد.

56\. Codex Implementation Contract (FROZEN)  
Codex باید Phase-by-Phase کار کند و اجازه ندارد برای سریع‌تر شدن Domain Rules را حذف یا bypass کند. هر Phase ابتدا schema/migrations، domain model/services، policies/guards، API contracts، events/outbox، tests و docs را کامل می‌کند.  
Breaking change به Rule Freeze شده فقط با ثبت ADR/Blueprint amendment مجاز است. Codex نباید Scenario logic را hard-code کند، payment verification را client-trusted کند، critical state را Redis-only نگه دارد یا authorization را فقط در UI اعمال کند.  
خروجی هر milestone باید شامل فایل‌های تغییرکرده، migration status، tests run/results، known limitations و next milestone باشد.

