<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('evorxa_services')) {
            Schema::create('evorxa_services', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('service_id')->unique();
                $table->string('instance_id', 64)->nullable()->index();
                $table->unsignedInteger('project_id')->nullable();
                $table->unsignedInteger('package_id')->nullable();
                $table->string('upstream_cycle', 16)->nullable();
                $table->string('name', 100)->nullable();
                $table->string('state', 20)->default('new')->index();
                $table->string('app_slug', 64)->nullable();
                $table->string('os_label', 191)->nullable();
                $table->unsignedInteger('ssh_key_id')->nullable();
                // Connection details shown to the client (the password is encrypted with APP_KEY).
                $table->string('hostname', 191)->nullable();
                $table->string('ip', 64)->nullable();
                $table->text('extra_ips')->nullable();
                $table->string('username', 64)->nullable();
                $table->text('password')->nullable();
                $table->mediumText('snapshot')->nullable();
                $table->text('error')->nullable();
                $table->timestamp('creating_at')->nullable();
                $table->timestamp('ready_at')->nullable();
                $table->timestamp('ready_notified_at')->nullable();
                $table->timestamp('last_sync_at')->nullable();
                $table->timestamp('alerted_at')->nullable();
                $table->string('last_extend_billed_till', 40)->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('evorxa_log')) {
            Schema::create('evorxa_log', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('service_id')->nullable()->index();
                $table->string('instance_id', 64)->nullable();
                $table->string('actor', 32);
                $table->string('action', 64);
                $table->boolean('ok')->default(true);
                $table->text('message')->nullable();
                $table->integer('amount_cents')->nullable();
                $table->timestamp('created_at')->nullable()->index();
            });
        }

        if (!Schema::hasTable('evorxa_settings')) {
            Schema::create('evorxa_settings', function (Blueprint $table) {
                $table->string('name', 64)->primary();
                $table->text('value')->nullable();
            });
        }
    }

    /** Never drop: the tables map paid services to running servers. */
    public function down(): void {}
};
