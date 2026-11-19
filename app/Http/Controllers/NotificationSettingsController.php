<?php

namespace App\Http\Controllers;

use App\Models\NotificationSetting;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class NotificationSettingsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->role === 'admin', 403);
        return response()->json(['settings' => collect(NotificationSetting::EVENTS)->map(function ($role, $event) {
            $setting = NotificationSetting::find($event);
            return ['event' => $event, 'mode' => $setting?->mode ?? 'role', 'emails' => $setting?->emails ?? [],
                'role' => $role, 'role_emails' => NotificationSetting::teamRecipients($event)->pluck('email')->all(),
                'resolved_emails' => NotificationSetting::recipients($event)->pluck('email')->all()];
        })->values(), 'mail_configured' => config('mail.default') === 'smtp' && filled(config('mail.mailers.smtp.username')) && filled(config('mail.mailers.smtp.password'))]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->role === 'admin', 403);
        $data = $request->validate([
            'settings' => ['required', 'array', 'size:'.count(NotificationSetting::EVENTS)],
            'settings.*.event' => ['required', Rule::in(array_keys(NotificationSetting::EVENTS)), 'distinct'],
            'settings.*.mode' => ['required', Rule::in(['role', 'custom', 'off'])],
            'settings.*.emails' => ['present', 'array', 'max:20'],
            'settings.*.emails.*' => ['required', 'string', 'email:rfc', 'max:254'],
        ]);
        foreach ($data['settings'] as $index => $setting) {
            if ($setting['mode'] === 'custom' && !$setting['emails']) {
                throw ValidationException::withMessages(["settings.$index.emails" => 'Enter at least one recipient email.']);
            }
        }
        DB::transaction(function () use ($data) {
            foreach ($data['settings'] as $setting) {
                NotificationSetting::updateOrCreate(['event' => $setting['event']], [
                    'mode' => $setting['mode'],
                    'emails' => collect($setting['emails'])->map(fn ($email) => strtolower(trim($email)))->unique()->values()->all(),
                ]);
            }
        });
        return response()->json(['message' => 'Notification recipients saved.']);
    }
}
