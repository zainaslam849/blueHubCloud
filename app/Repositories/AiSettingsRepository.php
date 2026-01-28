<?php

namespace App\Repositories;

use App\Models\AiSetting;
use Illuminate\Support\Facades\DB;

class AiSettingsRepository
{
    /**
     * Return all settings ordered by newest first.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function all()
    {
        return AiSetting::orderByDesc('created_at')->get();
    }

    /**
     * Find by id.
     */
    public function find(int $id): ?AiSetting
    {
        return AiSetting::find($id);
    }

    /**
     * Return the single active config, or null.
     */
    public function getActive(): ?AiSetting
    {
        return AiSetting::where('enabled', true)->first();
    }

    /**
     * Create a new setting. If enabling, ensure it's the only enabled config.
     *
     * @param array $data
     */
    public function create(array $data): AiSetting
    {
        return DB::transaction(function () use ($data) {
            $enabled = ! empty($data['enabled']);

            if ($enabled) {
                AiSetting::where('enabled', true)->update(['enabled' => false]);
            }

            return AiSetting::create($data);
        });
    }

    /**
     * Update an existing setting. If enabling, ensure uniqueness.
     */
    public function update(int $id, array $data): ?AiSetting
    {
        return DB::transaction(function () use ($id, $data) {
            $model = AiSetting::find($id);
            if (! $model) {
                return null;
            }

            $enabled = array_key_exists('enabled', $data) && $data['enabled'];
            if ($enabled) {
                AiSetting::where('enabled', true)->where('id', '!=', $id)->update(['enabled' => false]);
            }

            $model->fill($data);
            $model->save();

            return $model;
        });
    }

    /**
     * Disable all configs (helper).
     */
    public function disableAll(): int
    {
        return AiSetting::where('enabled', true)->update(['enabled' => false]);
    }
}
