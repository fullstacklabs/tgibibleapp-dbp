<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddCollectionsTable extends Migration
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
                'collections',
                function (Blueprint $table) {
                    $table->bigIncrements('id');
                    $table->string('name');
                    $table->boolean('featured')->default(false);
                    $table->integer('user_id')->unsigned();
                    $table->integer('language_id')->unsigned();
                    $table->string('thumbnail_url')->nullable()->default(null);
                    $table->integer('order_column')->unsigned();
                    $table->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
                    $table->timestamp('updated_at')->default(DB::raw('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'));
                }
            );
        }
        if (!Schema::connection('dbp_users')->hasTable('collection_playlists')) {
            Schema::connection('dbp_users')->create(
                'collection_playlists',
                function (Blueprint $table) {
                    $table->bigIncrements('id');
                    $table->bigInteger('collection_id')->unsigned();
                    $table->foreign('collection_id', 'FK_collection_collection_playlists')->references('id')->on(config('database.connections.dbp_users.database') . '.collections')->onDelete('cascade')->onUpdate('cascade');
                    $table->bigInteger('playlist_id')->unsigned()->nullable();
                    $table->foreign('playlist_id', 'FK_playlist_collection_playlists')->references('id')->on(config('database.connections.dbp_users.database') . '.user_playlists')->onDelete('cascade')->onUpdate('cascade');
                    $table->integer('order_column')->unsigned();
                    $table->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
                    $table->timestamp('updated_at')->default(DB::raw('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'));
                }
            );
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
