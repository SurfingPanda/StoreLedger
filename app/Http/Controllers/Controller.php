<?php

namespace App\Http\Controllers;

abstract class Controller
{
    protected function pageSize(\Illuminate\Http\Request $request, int $default = 15, string $parameter = 'per_page'): int
    {
        $validated = $request->validate([$parameter => ['sometimes', 'integer', 'min:1', 'max:100']]);

        return (int) ($validated[$parameter] ?? $default);
    }
}
