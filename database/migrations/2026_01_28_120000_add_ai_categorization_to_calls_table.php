<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('calls', function (Blueprint $table) {
            // Foreign key to call_categories table
            if (!Schema::hasColumn('calls', 'category_id')) {
                $table->foreignId('category_id')
                    ->nullable()
                    ->after('transcript_text')
                    ->constrained('call_categories')
                    ->nullOnDelete();
            }

            // Foreign key to sub_categories table (nullable - not all calls have sub-categories)
            if (!Schema::hasColumn('calls', 'sub_category_id')) {
                $table->foreignId('sub_category_id')
                    ->nullable()
                    ->after('category_id')
                    ->constrained('sub_categories')
                    ->nullOnDelete();
            }

            // Store sub-category text when AI provides label but no matching sub-category exists
            if (!Schema::hasColumn('calls', 'sub_category_label')) {
                $table->string('sub_category_label')
                    ->nullable()
                    ->after('sub_category_id');
            }

            // Track categorization source: 'ai', 'manual', 'default'
            if (!Schema::hasColumn('calls', 'category_source')) {
                $table->enum('category_source', ['ai', 'manual', 'default'])
                    ->nullable()
                    ->after('sub_category_label')
                    ->index();
            }

            // AI confidence score (0.0 to 1.0)
            if (!Schema::hasColumn('calls', 'category_confidence')) {
                $table->decimal('category_confidence', 3, 2)
                    ->nullable()
                    ->after('category_source');
            }

            // Timestamp when categorization was performed
            if (!Schema::hasColumn('calls', 'categorized_at')) {
                $table->timestamp('categorized_at')
                    ->nullable()
                    ->after('category_confidence');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('calls', function (Blueprint $table) {
            $table->dropForeign(['category_id']);
            $table->dropForeign(['sub_category_id']);
            $table->dropColumn([
                'category_id',
                'sub_category_id',
                'sub_category_label',
                'category_source',
                'category_confidence',
                'categorized_at',
            ]);
        });
    }
};
