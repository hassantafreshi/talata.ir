<?php

namespace App\Http\Controllers\App;

use App\Domain\Settings\SettingsBackups;
use App\Models\SettingsBackup;
use App\Models\User;
use App\Support\Mobile;
use Illuminate\Http\Request;

/** Settings backups for the shop owner (docs/SETTINGS_BACKUPS.md). */
class BackupsController extends BaseController
{
    public function __construct(private readonly SettingsBackups $backups) {}

    public function index()
    {
        $tenant = $this->tenant();
        $list = SettingsBackup::query()->orderByDesc('id')->limit(100)->get();
        $users = User::query()->whereIn('id', $list->pluck('created_by')->filter())->pluck('mobile', 'id');

        return view('app.backups', [
            'list' => $list, 'enabled' => $this->backups->enabled($tenant), 'keep' => $this->backups->keep($tenant),
            'who' => fn (SettingsBackup $b) => $b->created_by_staff ? 'پشتیبانی' : ($b->created_by ? Mobile::display($users[$b->created_by] ?? null) : 'سامانه'),
            'differs' => fn (SettingsBackup $b) => $this->backups->differs($b->payload),
            'sections' => SettingsBackups::SECTIONS,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['label' => ['nullable', 'string', 'max:80']]);
        $tenant = $this->tenant();
        $this->ent()->assertCan($tenant, 'settings.backup', 'پشتیبان تنظیمات در پلن پایه و حرفه‌ای است.');
        $backup = $this->backups->capture($tenant, 'manual', $data['label'] ?? null, null, true);

        return response()->json(['ok' => true, 'id' => $backup?->id], 201);
    }

    public function restore(Request $request, int $backup)
    {
        $data = $request->validate(['sections' => ['required', 'array'], 'sections.*' => ['string', 'max:20']]);
        $row = SettingsBackup::query()->findOrFail($backup);
        $undo = $this->backups->restore($this->tenant(), $row, $data['sections']);

        return response()->json(['ok' => true, 'undo_backup' => $undo?->id]);
    }
}
