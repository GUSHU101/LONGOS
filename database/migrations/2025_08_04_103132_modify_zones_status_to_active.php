<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('zones', function (Blueprint $table) {
            // 添加新的active字段
            $table->tinyInteger('active')->default(true)->comment('是否启用')->after('sort_order');
        });

        // 将status字段的数据迁移到active字段
        DB::table('zones')->update([
            'active' => DB::raw('CASE WHEN status = 1 THEN 1 ELSE 0 END'),
        ]);

        Schema::table('zones', function (Blueprint $table) {
            // 删除旧的status字段
            $table->dropColumn('status');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('zones', function (Blueprint $table) {
            // 添加回status字段
            $table->tinyInteger('status')->default(1)->comment('是否启用')->after('sort_order');
        });

        // 将active字段的数据迁移回status字段
        DB::table('zones')->update([
            'status' => DB::raw('CASE WHEN active = 1 THEN 1 ELSE 0 END'),
        ]);

        Schema::table('zones', function (Blueprint $table) {
            // 删除active字段
            $table->dropColumn('active');
        });
    }
};
