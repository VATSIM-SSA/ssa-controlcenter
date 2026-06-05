<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCountryRating extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('country_rating', function (Blueprint $table) {
            $table->primary(['country_id', 'rating_id']);

            $table->unsignedInteger('country_id');
            $table->unsignedInteger('rating_id');

            $table->unsignedInteger('required_vatsim_rating')->nullable();
            $table->unsignedInteger('queue_length')->nullable();

            $table->foreign('country_id')->references('id')->on('countries');
            $table->foreign('rating_id')->references('id')->on('ratings');
        });

        // Insert available VATSIM-Ratings
        DB::table('country_rating')->insert([

            // Denmark
            ['country_id' => 1, 'rating_id' => 2, 'required_vatsim_rating' => null],
            ['country_id' => 1, 'rating_id' => 3, 'required_vatsim_rating' => 3],
            ['country_id' => 1, 'rating_id' => 4, 'required_vatsim_rating' => 4],

        ]);

        // Insert available Endorsement-Ratings
        DB::table('country_rating')->insert([
        ]);
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('country_rating');
    }
}
