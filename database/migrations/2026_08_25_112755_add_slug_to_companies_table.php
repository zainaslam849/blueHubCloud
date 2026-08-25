<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('slug')->nullable()->after('name');
        });

        // Backfill a stable, unique slug for every existing company. Generated
        // once here and never recomputed from the name afterwards, so a later
        // rename doesn't break bookmarked report URLs.
        $used = [];
        DB::table('companies')->orderBy('id')->get(['id', 'name'])->each(function ($company) use (&$used) {
            $base = Str::slug($company->name) ?: 'company';
            $slug = $base;
            $suffix = 2;
            while (in_array($slug, $used, true) || DB::table('companies')->where('slug', $slug)->exists()) {
                $slug = $base . '-' . $suffix;
                $suffix++;
            }
            $used[] = $slug;

            DB::table('companies')->where('id', $company->id)->update(['slug' => $slug]);
        });

        // A plain unique index (not a NOT NULL change — that needs
        // doctrine/dbal on this Laravel version). Application code always
        // sets the slug on create, so it's populated in practice.
        Schema::table('companies', function (Blueprint $table) {
            $table->unique('slug');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('slug');
        });
    }
};
