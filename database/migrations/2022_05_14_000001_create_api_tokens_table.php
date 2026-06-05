<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void
  {
    // Original creates `api_keys` without a PK; DO requires PK.
    Schema::create('api_keys', function (Blueprint $table) {
      // UUID stored as CHAR(36) and set as the primary key
      $table->char('id', 36);
      $table->primary('id');                       // ✅ add PK
      $table->string('name');
      $table->boolean('read_only')->default(true);
      $table->timestamp('last_used_at')->nullable();
      $table->timestamp('created_at')->useCurrent();
      // (no updated_at in original schema)
    });
  }

  public function down(): void
  {
    Schema::dropIfExists('api_keys');
  }
};
