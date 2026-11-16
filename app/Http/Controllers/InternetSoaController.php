<?php

namespace App\Http\Controllers;

use App\Models\InternetSoa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class InternetSoaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['search' => ['nullable', 'string', 'max:150'], 'page' => ['nullable', 'integer', 'min:1']]);
        $query = InternetSoa::query();
        if (!empty($data['search'])) {
            $query->where('store_name', 'like', '%'.addcslashes($data['search'], '%_\\').'%');
        }
        $records = $query->orderBy('due_date')->orderBy('id')->paginate(15);
        $records->through(fn ($record) => array_merge($record->toArray(), [
            'attachment_url' => route('internet-soa.attachment', $record),
        ]));
        return response()->json($records);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->role === 'admin', 403);
        $data = $request->validate([
            'store_name' => ['required', 'string', 'max:150'],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:9999999999.99'],
            'due_date' => ['required', 'date_format:Y-m-d'],
            'soa' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);
        $file = $request->file('soa');
        $path = $file->store('internet-soas', 'local');
        abort_unless($path, 500, 'Could not save the SOA attachment. Please try again.');
        try {
            $record = new InternetSoa(collect($data)->except('soa')->all());
            $record->attachment_path = $path;
            $record->attachment_name = mb_substr(basename(str_replace('\\', '/', $file->getClientOriginalName())), 0, 255);
            $record->created_by = $request->user()->id;
            $record->save();
        } catch (Throwable $error) {
            Storage::disk('local')->delete($path);
            throw $error;
        }
        return response()->json(['message' => 'Internet SOA saved with its attachment.'], 201);
    }

    public function attachment(InternetSoa $internetSoa): StreamedResponse
    {
        abort_unless(Storage::disk('local')->exists($internetSoa->attachment_path), 404);
        return Storage::disk('local')->download($internetSoa->attachment_path, $internetSoa->attachment_name, [
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
