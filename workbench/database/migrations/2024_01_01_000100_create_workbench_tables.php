<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Testbench already ships users / sessions / cache / jobs. Here we do
        // what a real application does when it goes multi-tenant: bolt the
        // discriminator onto the table it already has.
        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                $table->foreignId('tenant_id')->nullable()->after('id')->index();
            });

            // An email is only unique *within* a tenant now.
            Schema::table('users', function (Blueprint $table) {
                try {
                    $table->dropUnique('users_email_unique');
                } catch (\Throwable) {
                    // Index naming differs across drivers; not worth failing over.
                }
            });

            Schema::table('users', function (Blueprint $table) {
                $table->unique(['tenant_id', 'email'], 'users_tenant_email_unique');
            });
        } else {
            Schema::create('users', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->nullable()->index();
                $table->string('name');
                $table->string('email');
                $table->string('password')->nullable();
                $table->timestamps();
                $table->unique(['tenant_id', 'email'], 'users_tenant_email_unique');
            });
        }

        // Testbench 9+ bundles these as always-loaded default migrations, but
        // testbench 8.x only ships them behind an opt-in #[WithMigration(...)]
        // attribute we would rather not depend on. Creating them ourselves,
        // guarded by hasTable(), works unconditionally across every version.
        if (! Schema::hasTable('cache')) {
            Schema::create('cache', function (Blueprint $table) {
                $table->string('key')->primary();
                $table->mediumText('value');
                $table->integer('expiration');
            });

            Schema::create('cache_locks', function (Blueprint $table) {
                $table->string('key')->primary();
                $table->string('owner');
                $table->integer('expiration');
            });
        }

        if (! Schema::hasTable('jobs')) {
            Schema::create('jobs', function (Blueprint $table) {
                $table->id();
                $table->string('queue')->index();
                $table->longText('payload');
                $table->unsignedTinyInteger('attempts');
                $table->unsignedInteger('reserved_at')->nullable();
                $table->unsignedInteger('available_at');
                $table->unsignedInteger('created_at');
            });
        }

        if (! Schema::hasTable('failed_jobs')) {
            Schema::create('failed_jobs', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->text('connection');
                $table->text('queue');
                $table->longText('payload');
                $table->longText('exception');
                $table->timestamp('failed_at')->useCurrent();
            });
        }

        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id');
            $table->foreignId('user_id')->nullable();
            $table->string('title');
            $table->string('slug');
            $table->text('body')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->softDeletes();
            $table->timestamps();

            // Leading with tenant_id is what keeps scoped queries fast.
            $table->index(['tenant_id', 'created_at']);
            $table->unique(['tenant_id', 'slug']);
        });

        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index();
            $table->foreignId('post_id');
            $table->string('body');
            $table->timestamps();
        });

        // Central: shared by every tenant, no tenant_id anywhere.
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedInteger('price');
            $table->timestamps();
        });

        // Drift fixture: a tenant column with no guarded model behind it, and
        // no index leading with tenant_id. The audit command should say so.
        Schema::create('legacy_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id');
            $table->string('body');
            $table->timestamps();
        });

        // Drift fixture: neither tenant-owned nor allow-listed.
        Schema::create('unclassified_widgets', function (Blueprint $table) {
            $table->id();
            $table->string('label');
        });
    }

    public function down(): void
    {
        foreach ([
            'unclassified_widgets', 'legacy_notes', 'plans', 'comments', 'posts',
            'failed_jobs', 'jobs', 'cache_locks', 'cache',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        if (Schema::hasTable('users') && Schema::hasColumn('users', 'tenant_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropUnique('users_tenant_email_unique');
                $table->dropColumn('tenant_id');
            });
        }
    }
};
