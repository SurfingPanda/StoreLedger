<?php

namespace App\Http\Controllers;

use App\Models\InboxNotification;
use App\Services\RequestInbox;
use App\Services\RequestModule;
use Illuminate\Http\Request;

class InboxController extends Controller
{
    private function owned(Request $request)
    {
        return InboxNotification::where('user_id', $request->user()->id)->where('role', $request->user()->role);
    }

    public function index(Request $request)
    {
        $data = $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'filter' => ['sometimes', 'in:all,unread']]);
        RequestInbox::sync($request->user());
        $query = $this->owned($request);
        $unread = (clone $query)->whereNull('read_at')->whereNull('resolved_at')->count();
        $latest = (clone $query)->max('id') ?? 0;
        if (($data['filter'] ?? 'all') === 'unread') $query->whereNull('read_at')->whereNull('resolved_at');
        $notifications = $query->orderByDesc('id')->paginate(10)->through(fn ($item) => [
            'id' => $item->id, 'kind' => $item->kind, 'title' => $item->title, 'body' => $item->body,
            'read_at' => $item->read_at, 'resolved_at' => $item->resolved_at, 'created_at' => $item->created_at,
            'url' => array_key_exists($item->module, RequestModule::LABELS) ? route($item->module.'.detail', $item->request_id) : null,
        ]);
        return response()->json(['notifications' => $notifications, 'unread_count' => $unread, 'latest_id' => $latest])
            ->header('Cache-Control', 'private, no-store');
    }

    public function read(Request $request, int $notification)
    {
        $item = $this->owned($request)->findOrFail($notification);
        if (!$item->read_at) $item->update(['read_at' => now()]);
        return response()->json(['message' => 'Notification marked as read.']);
    }

    public function readAll(Request $request)
    {
        $data = $request->validate(['through_id' => ['required', 'integer', 'min:0']]);
        $this->owned($request)->where('id', '<=', $data['through_id'])->whereNull('read_at')->update(['read_at' => now()]);
        return response()->json(['message' => 'Notifications marked as read.']);
    }
}
