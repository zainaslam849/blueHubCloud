<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class AdminSettingsController extends Controller
{
    public function show(): JsonResponse
    {
        $settings = AppSetting::query()->first();

        return response()->json([
            'data' => [
                'site_name' => $settings?->site_name,
                'admin_logo_url' => $settings?->admin_logo_url,
                'admin_favicon_url' => $settings?->admin_favicon_url,
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'site_name' => ['nullable', 'string', 'max:120'],
            'admin_logo' => ['nullable', 'file', 'mimes:png,jpg,jpeg,svg,webp', 'max:2048'],
            'admin_favicon' => ['nullable', 'file', 'mimes:png,ico,svg', 'max:1024'],
            'admin_logo_clear' => ['nullable', 'boolean'],
            'admin_favicon_clear' => ['nullable', 'boolean'],
        ]);

        $settings = AppSetting::query()->first();
        if (! $settings) {
            $settings = new AppSetting();
        }

        if (array_key_exists('site_name', $validated)) {
            $settings->site_name = $validated['site_name'];
        }

        if ($request->boolean('admin_logo_clear')) {
            $this->deleteStoredFile($settings->admin_logo_url);
            $settings->admin_logo_url = null;
        }

        if ($request->boolean('admin_favicon_clear')) {
            $this->deleteStoredFile($settings->admin_favicon_url);
            $settings->admin_favicon_url = null;
        }

        if ($request->hasFile('admin_logo')) {
            $this->deleteStoredFile($settings->admin_logo_url);
            $path = $request->file('admin_logo')->store('app-settings', 'public');
            $settings->admin_logo_url = Storage::disk('public')->url($path);
        }

        if ($request->hasFile('admin_favicon')) {
            $this->deleteStoredFile($settings->admin_favicon_url);
            $path = $request->file('admin_favicon')->store('app-settings', 'public');
            $settings->admin_favicon_url = Storage::disk('public')->url($path);
        }

        $settings->save();

        return response()->json([
            'data' => [
                'site_name' => $settings->site_name,
                'admin_logo_url' => $settings->admin_logo_url,
                'admin_favicon_url' => $settings->admin_favicon_url,
            ],
        ]);
    }

    private function deleteStoredFile(?string $url): void
    {
        if (! $url) {
            return;
        }

        $path = parse_url($url, PHP_URL_PATH);
        if (! is_string($path) || $path === '') {
            return;
        }

        $prefix = '/storage/';
        if (str_starts_with($path, $prefix)) {
            $relative = ltrim(substr($path, strlen($prefix)), '/');
            if ($relative) {
                Storage::disk('public')->delete($relative);
            }
        }
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = Auth::guard('admin')->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (! Hash::check($validated['current_password'], $user->password)) {
            return response()->json(['message' => 'Current password is incorrect.'], 422);
        }

        $user->password = Hash::make($validated['new_password']);
        $user->save();

        return response()->json(['message' => 'Password updated.']);
    }
}
