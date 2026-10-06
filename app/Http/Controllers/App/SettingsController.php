<?php

namespace App\Http\Controllers\App;

use App\Domain\Audit\Audit;
use App\Domain\DomainError;
use App\Domain\Invoices\LayoutSettings;
use App\Domain\Invoices\Qr;
use App\Domain\Sms\SmsCredit;
use App\Domain\Sms\SmsTemplate;
use App\Models\Affiliate;
use App\Models\InvoiceLayout;
use App\Models\Membership;
use App\Models\Passkey;
use App\Models\ShopProfile;
use App\Models\SmsSetting;
use App\Support\Digits;
use App\Support\Mobile;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class SettingsController extends BaseController
{
    public function index()
    {
        $tenant = $this->tenant();
        $ent = $this->ent();

        return view('app.settings', [
            'profile' => $tenant->profile,
            'summary' => $ent->summary($tenant),
            'balanceFa' => Money::toman(app(SmsCredit::class)->balance($tenant->id)),
            'perSegmentFa' => Money::toman($ent->smsPerSegmentIrr($tenant)),
            'membership' => $this->membership(),
            'user' => auth()->user(),
            'affiliate' => Affiliate::query()->where('user_id', auth()->id())->first(),
            'passkeys' => Passkey::query()->where('owner_type', 'user')->where('owner_id', auth()->id())->orderBy('id')->get(),
            'invites' => Membership::query()->with(['tenant.profile' => fn ($q) => $q->withoutGlobalScope('tenant')])->where('status', 'invited')->whereNull('user_id')->where('invited_mobile', auth()->user()->mobile)->get(),
            'shops' => Membership::query()->with(['tenant.profile' => fn ($q) => $q->withoutGlobalScope('tenant')])->where('user_id', auth()->id())->where('status', 'active')->get(),
        ]);
    }

    public function entitlements()
    {
        return response()->json($this->ent()->summary($this->tenant()));
    }

    public function business(Request $request)
    {
        return view('app.business', [
            'profile' => $this->tenant()->profile,
            'canLogo' => $this->ent()->can($this->tenant(), 'invoice.shop_logo'),
            'return' => preg_match('/^[0-9a-z]{26}$/', (string) $request->query('return')) ? $request->query('return') : null,
            'welcome' => $request->boolean('welcome'),
        ]);
    }

    public function saveBusiness(Request $request)
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:120'], 'business_mobile' => ['nullable', 'string', 'max:20'], 'landline' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:300'], 'website' => ['nullable', 'string', 'max:150'], 'license_union' => ['nullable', 'string', 'max:60'],
            'license_online' => ['nullable', 'string', 'max:60'], 'socials' => ['nullable', 'array', 'max:5'],
            'socials.*.network' => ['nullable', 'in:instagram,telegram,whatsapp,other'], 'socials.*.handle' => ['nullable', 'string', 'max:60'],
        ]);
        $errors = [];
        $clean = fn ($v, $max) => ($v = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $v)))) === '' ? null : mb_substr($v, 0, $max);
        $name = $clean($data['name'] ?? null, 60);
        if (! $name) {
            $errors['name'] = ['نام فروشگاه را وارد کنید.'];
        } elseif (SmsTemplate::containsLinkOrPhone($name)) {
            // The shop name is sent inside invoice SMS; links or numbers in it would turn SMS into spam.
            $errors['name'] = ['در نام فروشگاه لینک یا شماره تلفن مجاز نیست.'];
        } elseif (SmsTemplate::looksLikeImpersonation($name)) {
            // The name is sent in invoice SMS; bank/government/prize wording would enable phishing.
            $errors['name'] = ['نام فروشگاه نباید شبیه نام بانک، سامانه دولتی یا پیام جایزه باشد. اگر نام واقعی فروشگاه شماست، با پشتیبانی تماس بگیرید.'];
        }
        $mobileRaw = trim((string) ($data['business_mobile'] ?? ''));
        $mobile = $mobileRaw === '' ? null : Mobile::normalize($mobileRaw);
        if (! $mobile) {
            $errors['business_mobile'] = ['شماره موبایل کسب‌وکار را درست وارد کنید.'];
        }
        $landline = Digits::toLatin((string) ($data['landline'] ?? ''));
        $landline = $landline === '' ? null : preg_replace('/[^\d-]/', '', $landline);
        if ($landline && ! preg_match('/^0\d{2,3}-?\d{6,8}$/', $landline)) {
            $errors['landline'] = ['تلفن ثابت را با کد شهر وارد کنید. نمونه: ۰۲۱-۱۲۳۴۵۶۷۸'];
        }
        $address = $clean($data['address'] ?? null, 250);
        if (! $address) {
            $errors['address'] = ['آدرس کسب‌وکار را وارد کنید.'];
        }
        $website = $clean($data['website'] ?? null, 120);
        if ($website && ! preg_match('~^(https?://)?[a-z0-9.-]+\.[a-z]{2,}(/\S*)?$~i', $website)) {
            $errors['website'] = ['آدرس وب‌سایت درست نیست.'];
        }
        if ($errors) {
            throw new DomainError('VALIDATION', 'چند مورد را اصلاح کنید.', 422, ['errors' => $errors]);
        }
        $socials = [];
        foreach ((array) ($data['socials'] ?? []) as $i => $s) {
            $handle = $clean($s['handle'] ?? null, 60);
            if ($handle && preg_match('/^@?[A-Za-z0-9_.]{2,60}$/', $handle)) {
                $socials[] = ['id' => 'social_'.($s['network'] ?? 'other').'_'.($i + 1), 'network' => $s['network'] ?? 'other', 'handle' => '@'.ltrim($handle, '@')];
            }
        }
        $profile = ShopProfile::query()->firstOrFail();
        $profile->update([
            'name' => $name, 'business_mobile' => $mobile, 'landline' => $landline, 'address' => $address, 'website' => $website,
            'license_union' => $clean($data['license_union'] ?? null, 40), 'license_online' => $clean($data['license_online'] ?? null, 40), 'socials' => $socials,
        ]);
        Audit::record('profile.updated', $profile);
        $return = $request->input('return');

        return response()->json(['ok' => true, 'next' => preg_match('/^[0-9a-z]{26}$/', (string) $return) ? route('invoices.review', $return) : null]);
    }

    public function uploadLogo(Request $request)
    {
        $tenant = $this->tenant();
        $this->ent()->assertCan($tenant, 'invoice.shop_logo', 'نمایش لوگو روی فاکتور در پلن پایه و حرفه‌ای است.');
        $request->validate(['logo' => ['required', 'file', 'max:'.config('talata.uploads.logo_max_kb'), 'mimetypes:image/png,image/jpeg,image/webp']]);
        $file = $request->file('logo');
        $info = @getimagesize($file->getRealPath());
        $min = config('talata.uploads.logo_min_px');
        $max = config('talata.uploads.logo_max_px');
        if (! $info || ! in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true) || $info[0] < $min || $info[1] < $min || $info[0] > $max || $info[1] > $max) {
            throw new DomainError('LOGO_INVALID', 'تصویر لوگو باید PNG، JPG یا WebP و حداقل '.Digits::toPersian((string) $min).'×'.Digits::toPersian((string) $min).' پیکسل باشد.', 422, ['errors' => ['logo' => ['فایل لوگو قابل قبول نیست.']]]);
        }
        // Re-encode: strips metadata (EXIF/GPS) and any payload hidden in the original file.
        $src = match ($info[2]) {
            IMAGETYPE_PNG => imagecreatefrompng($file->getRealPath()), IMAGETYPE_JPEG => imagecreatefromjpeg($file->getRealPath()), default => imagecreatefromwebp($file->getRealPath())
        };
        $scale = min(1, 800 / max($info[0], $info[1]));
        $w = (int) round($info[0] * $scale);
        $h = (int) round($info[1] * $scale);
        $dst = imagecreatetruecolor($w, $h);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 255, 255, 255, 127));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $w, $h, $info[0], $info[1]);
        ob_start();
        imagepng($dst, null, 9);
        $png = ob_get_clean();
        $profile = ShopProfile::query()->firstOrFail();
        $version = $profile->logo_version + 1;
        $path = "logos/{$tenant->public_id}/v{$version}.png"; // old versions are kept for issued snapshots
        Storage::disk('local')->put($path, $png);
        $profile->update(['logo_path' => $path, 'logo_version' => $version]);
        Audit::record('profile.logo_uploaded', $profile, ['version' => $version]);

        return response()->json(['ok' => true, 'url' => route('public.logo', [$tenant->public_id, $version])]);
    }

    public function deleteLogo()
    {
        $profile = ShopProfile::query()->firstOrFail();
        $profile->update(['logo_path' => null]);
        Audit::record('profile.logo_removed', $profile);

        return response()->json(['ok' => true]);
    }

    public function appearance()
    {
        $tenant = $this->tenant();
        $layout = InvoiceLayout::query()->firstOrFail();
        $can = $this->ent()->can($tenant, 'invoice.customize');

        return view('app.appearance', [
            'settings' => LayoutSettings::effective($layout->settings, $can), 'version' => $layout->version, 'canCustomize' => $can,
            'canLogo' => $this->ent()->can($tenant, 'invoice.shop_logo'), 'profile' => $tenant->profile,
        ]);
    }

    public function saveAppearance(Request $request)
    {
        $this->ent()->assertCan($this->tenant(), 'invoice.customize', 'ویرایش ظاهر فاکتور در پلن پایه و حرفه‌ای است.');
        $data = $request->validate(['settings' => ['required', 'array'], 'version' => ['required', 'integer']]);
        $layout = DB::transaction(function () use ($data) {
            $layout = InvoiceLayout::query()->lockForUpdate()->firstOrFail();
            if ($layout->version !== (int) $data['version']) {
                throw new DomainError('LAYOUT_CONFLICT', 'ظاهر فاکتور در جای دیگری تغییر کرد. صفحه را دوباره باز کنید.', 409);
            }
            $layout->update(['settings' => LayoutSettings::sanitize($data['settings']), 'version' => $layout->version + 1, 'updated_by' => auth()->id()]);
            Audit::record('layout.updated', $layout, ['version' => $layout->version]);

            return $layout;
        });

        return response()->json(['ok' => true, 'version' => $layout->version]);
    }

    public function previewAppearance(Request $request)
    {
        $tenant = $this->tenant();
        $settings = LayoutSettings::effective((array) $request->input('settings', []), $this->ent()->can($tenant, 'invoice.customize'));
        $profile = $tenant->profile;
        $sample = SamplePreview::invoice($profile, $settings, $this->ent()->can($tenant, 'invoice.shop_logo'), ! $this->ent()->can($tenant, 'invoice.hide_provider_brand'), $tenant->public_id);

        return response()->json(['html' => view('print.invoice-body', ['v' => $sample, 'qr' => Qr::svg(config('talata.public_url').'/v/sample'), 'verifyShort' => 'talata.ir/v/…', 'sample' => true])->render(), 'settings' => $settings]);
    }

    public function smsTemplate()
    {
        $tenant = $this->tenant();
        $can = $this->ent()->can($tenant, 'sms.template_edit');

        return view('app.sms-template', ['template' => SmsSetting::query()->value('invoice_template') ?: SmsTemplate::DEFAULT, 'canEdit' => $can, 'default' => SmsTemplate::DEFAULT]);
    }

    public function saveSmsTemplate(Request $request)
    {
        $this->ent()->assertCan($this->tenant(), 'sms.template_edit', 'ویرایش متن پیامک در پلن پایه و حرفه‌ای است.');
        $data = $request->validate(['template' => ['required', 'string', 'max:400']]);
        $template = SmsTemplate::validate($data['template']);
        SmsSetting::query()->updateOrCreate([], ['invoice_template' => $template]);
        Audit::record('sms.template_updated', null);

        return response()->json(['ok' => true]);
    }
}
