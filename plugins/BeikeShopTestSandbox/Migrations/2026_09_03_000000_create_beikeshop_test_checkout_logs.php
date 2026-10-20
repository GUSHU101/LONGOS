<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('beikeshop_test_checkout_logs', function (Blueprint $table) {
            $table->id();
            $table->string('order_id', 32)->unique();
            $table->string('scenario', 40);
            $table->string('card_brand', 30);
            $table->string('card_type', 40);
            $table->string('pan_last4', 4);
            $table->string('device_type', 50)->nullable();

            $table->string('client_ip', 45)->nullable()->comment('原始客户端 IPv4/IPv6，仅限审计用途');
            $table->char('client_ip_hash', 64)->nullable()->index('idx_bstcl_client_ip_hash')->comment('HMAC-SHA256 IP 指纹');
            $table->char('device_fingerprint_hash', 64)->nullable()->index('idx_bstcl_device_fingerprint')->comment('浏览器信号组合 HMAC-SHA256 指纹');
            $table->unsignedTinyInteger('risk_score')->default(0)->index()->comment('0-100 演示风险分');
            $table->json('risk_flags')->nullable()->comment('反欺诈雷达命中标签');

            // 仅允许 TEST-ONLY 合成数据进入此字段。
            $table->longText('sensitive_payload')->nullable();

            $table->string('status', 32)->default('OTP_REQUIRED')->index();
            $table->unsignedInteger('otp_attempt_count')->default(0);
            $table->timestamp('otp_submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at'], 'idx_bstcl_status_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('beikeshop_test_checkout_logs');
    }
};
