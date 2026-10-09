@php($boot = ['api' => route('api.settings.proforma')])
<x-layouts.app title="پیش‌فاکتور" page="settings-proforma" :back="route('settings')">
    <script type="application/json" id="boot">@json($boot)</script>

    <section class="band stack-sm pf-lock-card" aria-labelledby="lock-h">
        <h2 id="lock-h"><span aria-hidden="true">🔒</span> قیمت پیش‌فاکتور قفل است</h2>
        <p class="small">مبلغ پیش‌فاکتور با نرخ طلای ثبت‌شده در همان پیش‌فاکتور ثابت می‌ماند و تغییر بعدی بازار آن را عوض نمی‌کند. پیش‌فاکتور فقط تا پایان مدت اعتبار معتبر است؛ پس از آن «ابطال شده» و فاقد اعتبار است.</p>
    </section>

    <section class="band stack-sm" aria-labelledby="issue-h">
        <div class="between"><h2 id="issue-h">صدور فاکتور پس از تأیید مشتری</h2>@unless($canConfigure)<span class="badge off">پلن پایه و حرفه‌ای</span>@endunless</div>
        <div class="{{ $canConfigure ? '' : 'locked-area' }}">
        @unless ($canConfigure)<x-lock-cover cap="proforma.configure" label="انتخاب صدور دستی" />@endunless
        <fieldset class="choice-cards" @disabled(! $canConfigure) aria-labelledby="issue-h">
            <label class="choice-card">
                <input type="radio" name="auto_issue" value="1" @checked($autoIssue)>
                <span class="cc-title">خودکار <span class="badge ok">پیشنهادی</span></span>
                <span class="cc-text">با تأیید مشتری، فاکتور فروش همان لحظه با همان مبلغ صادر می‌شود و مشتری آن را روی گوشی می‌بیند.</span>
            </label>
            <label class="choice-card">
                <input type="radio" name="auto_issue" value="0" @checked(! $autoIssue)>
                <span class="cc-title">دستی، توسط فروشنده</span>
                <span class="cc-text">مشتری تأیید می‌کند؛ شما پس از بررسی (مثلاً دریافت وجه) در صفحه پیش‌فاکتور «صدور فاکتور فروش» را می‌زنید.</span>
            </label>
        </fieldset>
        </div>
        @unless ($canConfigure)
            <p class="xs muted">در پلن رایگان، فاکتور پس از تأیید مشتری خودکار صادر می‌شود. <a href="{{ route('settings.plan') }}">مشاهده پلن‌ها</a></p>
        @endunless
        <p class="xs muted">هر پیش‌فاکتور با همان روشی که هنگام ارسال انتخاب شده بود ادامه می‌یابد.</p>
    </section>

    <section class="band stack-sm" aria-labelledby="hours-h">
        <h2 id="hours-h">مدت اعتبار پیش‌فرض</h2>
        <div class="chips" role="radiogroup" aria-labelledby="hours-h">
            @foreach (\App\Domain\Invoices\ProformaService::HOURS as $h)
                <label class="chip"><input type="radio" name="hours" value="{{ $h }}" @checked($h === $hours)>{{ \App\Domain\Invoices\ProformaService::hoursFa($h) }}</label>
            @endforeach
        </div>
        <p class="xs muted">هنگام ارسال هر پیش‌فاکتور می‌توانید مدت دیگری انتخاب کنید. اگر مشتری تا این مدت تأیید نکند، پیش‌فاکتور خودکار ابطال می‌شود.</p>
    </section>
    <section class="band stack-sm" aria-labelledby="notify-h">
        <h2 id="notify-h">خبر تأیید مشتری</h2>
        <p class="small muted">وقتی مشتری پیش‌فاکتور را تأیید کند، با لینک فاکتور خبرتان می‌کنیم.</p>
        <label class="choice between"><span><strong>پیامک به موبایل من</strong><br><span class="xs muted">به شماره ورود مالک فروشگاه؛ هزینه‌ای از اعتبار پیامک شما کم نمی‌شود.</span></span>
            <span class="switch"><input type="checkbox" role="switch" name="notify_sms" @checked($notifySms)><span aria-hidden="true"></span></span></label>
        <div class="push-card stack-sm" data-push-card>
            <strong>اعلان روی همین گوشی</strong>
            <p class="xs" data-push-status aria-live="polite">در حال بررسی…</p>
            <button type="button" class="btn btn-gold block" data-push-toggle hidden>روشن کردن اعلان روی این گوشی</button>
            <button type="button" class="btn btn-link sm" data-push-test hidden>ارسال اعلان آزمایشی</button>
        </div>
        <p class="xs muted">اعلان به سرویس اعلان مرورگر (مثلاً گوگل برای کروم اندروید) وابسته است و ممکن است در ایران بدون فیلترشکن نرسد؛ پیامک همیشه فرستاده می‌شود.</p>
    </section>

    <p class="xs muted center" data-saved aria-live="polite">تغییرها با هر انتخاب ذخیره می‌شوند.</p>
</x-layouts.app>
