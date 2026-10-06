<?php

namespace App\Domain\Customers;

use App\Domain\Audit\Audit;
use App\Domain\DomainError;
use App\Domain\Plans\Entitlements;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Mobile;
use Illuminate\Support\Facades\DB;

final class CustomerService
{
    public function __construct(private readonly Entitlements $entitlements) {}

    public function create(Tenant $tenant, User $user, array $data): Customer
    {
        $name = trim(strip_tags((string) ($data['name'] ?? '')));
        $mobileRaw = trim((string) ($data['mobile'] ?? ''));
        $mobile = $mobileRaw === '' ? null : Mobile::normalize($mobileRaw);
        $errors = [];
        if ($name === '' || mb_strlen($name) > 80) {
            $errors['name'] = ['نام مشتری را وارد کنید.'];
        }
        if ($mobileRaw !== '' && ! $mobile) {
            $errors['mobile'] = ['شماره موبایل درست نیست.'];
        }
        if ($errors) {
            throw new DomainError('VALIDATION', 'اطلاعات مشتری کامل نیست.', 422, ['errors' => $errors]);
        }

        return DB::transaction(function () use ($tenant, $user, $name, $mobile, $data) {
            Tenant::query()->whereKey($tenant->id)->lockForUpdate()->first();
            if ($mobile && ($dup = Customer::query()->where('mobile', $mobile)->first())) {
                throw new DomainError('DUPLICATE_CUSTOMER', "این شماره قبلاً برای «{$dup->name}» ثبت شده است.", 409, ['customer' => ['id' => $dup->public_id, 'name' => $dup->name, 'url' => route('customers.show', $dup)]]);
            }
            $this->entitlements->assertQuota($tenant, 'new_customers_per_month');
            $customer = Customer::create([
                'name' => mb_substr(preg_replace('/\s+/u', ' ', $name), 0, 80), 'mobile' => $mobile,
                'note' => isset($data['note']) ? mb_substr(strip_tags((string) $data['note']), 0, 250) : null, 'created_by' => $user->id,
            ]);
            Audit::record('customer.created', $customer);

            return $customer;
        });
    }

    public function update(Customer $customer, array $data): Customer
    {
        $name = trim(strip_tags((string) ($data['name'] ?? $customer->name)));
        $mobileRaw = trim((string) ($data['mobile'] ?? ''));
        $mobile = $mobileRaw === '' ? null : Mobile::normalize($mobileRaw);
        if ($name === '' || ($mobileRaw !== '' && ! $mobile)) {
            throw new DomainError('VALIDATION', 'نام یا موبایل درست نیست.', 422, ['errors' => array_filter(['name' => $name === '' ? ['نام را وارد کنید.'] : null, 'mobile' => $mobileRaw !== '' && ! $mobile ? ['شماره موبایل درست نیست.'] : null])]);
        }
        if ($mobile && Customer::query()->where('mobile', $mobile)->whereKeyNot($customer->id)->exists()) {
            throw new DomainError('DUPLICATE_CUSTOMER', 'این شماره برای مشتری دیگری ثبت شده است.', 409);
        }
        $customer->update([
            'name' => mb_substr($name, 0, 80), 'mobile' => $mobile,
            'note' => isset($data['note']) ? mb_substr(strip_tags((string) $data['note']), 0, 250) : $customer->note,
            'sms_opt_out' => (bool) ($data['sms_opt_out'] ?? $customer->sms_opt_out),
        ]);
        Audit::record('customer.updated', $customer);

        return $customer;
    }
}
