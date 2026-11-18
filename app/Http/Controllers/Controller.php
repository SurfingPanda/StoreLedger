<?php

namespace App\Http\Controllers;

abstract class Controller
{
    protected function pageSize(\Illuminate\Http\Request $request, int $default = 15): int
    {
        $validated = $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);

        return (int) ($validated['per_page'] ?? $default);
    }
}
