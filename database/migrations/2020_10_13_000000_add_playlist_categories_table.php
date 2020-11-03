<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddPlaylistCategoriesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // add table
        if (!Schema::connection('dbp_users')->hasTable('collections')) {
            Schema::connection('dbp_users')->create(
              'collections', function (Blueprint $table) {
                $table->increments('id');
                $table->string('name', 64);
                $table->boolean('featured')->default(false);
                $table->integer('user_id')->unsigned();
                $table->integer('language_id')->unsigned();
                $table->integer('order_column')->unsigned();
                $table->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
                $table->timestamp('updated_at')->default(DB::raw('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'));
            });
        }
        if (!Schema::connection('dbp_users')->hasTable('collection_playlists')) {
            Schema::connection('dbp_users')->create(
              'collection_playlists', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('collection_id')->unsigned();
                $table->integer('playlist_id')->unsigned();
                $table->integer('order_column')->unsigned();
                $table->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
                $table->timestamp('updated_at')->default(DB::raw('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'));
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::connection('dbp_users')->dropIfExists('collections');
        Schema::connection('dbp_users')->dropIfExists('collection_playlists');
    }
}
