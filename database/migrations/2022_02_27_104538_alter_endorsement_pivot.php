<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void
  {
    Schema::dropIfExists('rating_user');

    // Create pivots WITH composite PKs to satisfy DO's sql_require_primary_key
    Schema::create('endorsement_rating', function (Blueprint $table) {
      $table->unsignedBigInteger('endorsement_id');
      $table->unsignedInteger('rating_id');
      $table->primary(['endorsement_id', 'rating_id']);
    });

    Schema::create('endorsement_position', function (Blueprint $table) {
      $table->unsignedBigInteger('endorsement_id');
      $table->unsignedBigInteger('position_id');
      $table->primary(['endorsement_id', 'position_id']);
    });

    Schema::create('area_endorsement', function (Blueprint $table) {
      $table->unsignedInteger('area_id');
      $table->unsignedBigInteger('endorsement_id');
      $table->primary(['area_id', 'endorsement_id']);
    });
  }

  public function down(): void
  {
    Schema::dropIfExists('area_endorsement');
    Schema::dropIfExists('endorsement_position');
    Schema::dropIfExists('endorsement_rating');
  }
};
