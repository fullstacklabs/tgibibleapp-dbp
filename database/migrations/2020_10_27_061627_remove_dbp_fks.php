<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class RemoveDbpFks extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // SELECT * FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_SCHEMA != CONSTRAINT_SCHEMA;
        Schema::connection('dbp_users')->table('access_group_api_keys', function (Blueprint $table) {
            $data = DB::select(DB::raw('SELECT * FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_SCHEMA != CONSTRAINT_SCHEMA and TABLE_NAME="access_group_api_keys"'));
            // the name in seed doesn't match migrations
            // so be smartish about it
            if (count($data)) {
                // access_group_api_keys_ibfk_1 (seed) or FK_access_groups_access_group_api_keys (migration)
                $table->dropForeign($data[0]->CONSTRAINT_NAME);
            }
        });
        Schema::connection('dbp_users')->table('access_group_keys', function (Blueprint $table) {
            $data = DB::select(DB::raw('SELECT * FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_SCHEMA != CONSTRAINT_SCHEMA and TABLE_NAME="access_group_api_keys"'));
            // the name in seed isn't in any migration
            if (count($data)) {
                // access_group_keys_ibfk_1 (seed) or FK_access_group_keys (migration)
                $table->dropForeign($data[0]->CONSTRAINT_NAME);
            }
        });
        Schema::connection('dbp_users')->table('article_tags', function (Blueprint $table) {
            $table->dropForeign('article_tags_iso_foreign');
        });
        Schema::connection('dbp_users')->table('article_translations', function (Blueprint $table) {
            $table->dropForeign('article_translations_iso_foreign');
        });
        Schema::connection('dbp_users')->table('articles', function (Blueprint $table) {
            $table->dropForeign('articles_iso_foreign');
            $table->dropForeign('articles_organization_id_foreign');
        });
        Schema::connection('dbp_users')->table('plans', function (Blueprint $table) {
            $table->dropForeign('FK_languages_plans');
        });
        Schema::connection('dbp_users')->table('playlist_items', function (Blueprint $table) {
            $table->dropForeign('FK_books_playlist_items');
            $table->dropForeign('FK_filesets_playlist_items');
        });
        Schema::connection('dbp_users')->table('profiles', function (Blueprint $table) {
            $table->dropForeign('FK_countries_profiles');
        });
        Schema::connection('dbp_users')->table('role_user', function (Blueprint $table) {
            $table->dropForeign('role_user_organization_id_foreign');
        });
        Schema::connection('dbp_users')->table('user_bookmarks', function (Blueprint $table) {
            $table->dropForeign('FK_bibles_user_bookmarks');
            $table->dropForeign('FK_books_user_bookmarks');
        });
        Schema::connection('dbp_users')->table('user_downloads', function (Blueprint $table) {
            $table->dropForeign('FK_bible_filesets_user_downloads');
        });
        Schema::connection('dbp_users')->table('user_highlights', function (Blueprint $table) {
            $table->dropForeign('FK_bibles_user_highlights');
            $table->dropForeign('FK_books_user_highlights');
        });
        Schema::connection('dbp_users')->table('user_notes', function (Blueprint $table) {
            $table->dropForeign('FK_bibles_user_notes');
            $table->dropForeign('FK_books_user_notes');
        });
        Schema::connection('dbp_users')->table('user_playlists', function (Blueprint $table) {
            $table->dropForeign('FK_languages_playlists');
        });
        Schema::connection('dbp_users')->table('user_settings', function (Blueprint $table) {
            $table->dropForeign('FK_bibles_user_settings');
            $table->dropForeign('FK_books_user_settings');
            $table->dropForeign('FK_languages_user_settings');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::connection('dbp_users')->table('access_group_api_keys', function (Blueprint $table) {
            // this adds back as FK_access_groups_access_group_api_keys
            $table->foreign('access_group_id', 'FK_access_groups_access_group_api_keys')->references('id')->on(config('database.connections.dbp.database').'.access_groups')->onUpdate('cascade')->onDelete('cascade');
        });
        Schema::connection('dbp_users')->table('access_group_keys', function (Blueprint $table) {
            // there's no migration for this one...
            $table->foreign('access_group_id', 'FK_access_group_keys')->references('id')->on(config('database.connections.dbp.database').'.access_groups')->onUpdate('cascade')->onDelete('cascade');
        });
        Schema::connection('dbp_users')->table('article_tags', function (Blueprint $table) {
            $table->foreign('iso', 'FK_languages_article_tags')->references('iso')->on(config('database.connections.dbp.database').'.languages')->onUpdate('cascade');
        });
        Schema::connection('dbp_users')->table('article_translations', function (Blueprint $table) {
            $table->foreign('iso', 'FK_languages_article_translations')->references('iso')->on(config('database.connections.dbp.database').'.languages')->onUpdate('cascade');
        });
        Schema::connection('dbp_users')->table('articles', function (Blueprint $table) {
            $table->foreign('organization_id', 'FK_organizations_articles')->references('id')->on(config('database.connections.dbp.database').'.organizations')->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('user_id', 'FK_users_articles')->references('id')->on(config('database.connections.dbp_users.database').'.users')->onUpdate('cascade')->onDelete('cascade');
        });
        Schema::connection('dbp_users')->table('plans', function (Blueprint $table) {
            $table->foreign('language_id', 'FK_languages_plans')->references('id')->on(config('database.connections.dbp.database') . '.languages')->onUpdate('cascade');
        });
        Schema::connection('dbp_users')->table('playlist_items', function (Blueprint $table) {
            $table->foreign('fileset_id', 'FK_bible_filesets_plan_days_items')->references('id')->on(config('database.connections.dbp.database') . '.bible_filesets')->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('book_id', 'FK_books_plan_days_items')->references('id')->on(config('database.connections.dbp.database') . '.books');
        });
        Schema::connection('dbp_users')->table('profiles', function (Blueprint $table) {
            $table->foreign('country_id', 'FK_countries_profiles')->references('id')->on(config('database.connections.dbp.database').'.countries')->onUpdate('cascade');
        });
        Schema::connection('dbp_users')->table('role_user', function (Blueprint $table) {
            $table->foreign('organization_id', 'FK_organizations_role_user')->references('id')->on(config('database.connections.dbp.database').'.organizations');
        });
        Schema::connection('dbp_users')->table('user_bookmarks', function (Blueprint $table) {
            $table->foreign('bible_id', 'FK_bibles_user_bookmarks')->references('id')->on(config('database.connections.dbp.database').'.bibles')->onDelete('cascade')->onUpdate('cascade');
            $table->foreign('book_id', 'FK_books_user_bookmarks')->references('id')->on(config('database.connections.dbp.database').'.books')->onUpdate('cascade')->onDelete('cascade');
        });
        Schema::connection('dbp_users')->table('user_downloads', function (Blueprint $table) {
            $table->foreign('fileset_id', 'FK_bible_filesets_user_downloads')->references('id')->on(config('database.connections.dbp.database') . '.bible_filesets')->onUpdate('cascade')->onDelete('cascade');
        });
        Schema::connection('dbp_users')->table('user_highlights', function (Blueprint $table) {
            $table->foreign('bible_id', 'FK_bibles_user_highlights')->references('id')->on(config('database.connections.dbp.database').'.bibles')->onDelete('cascade')->onUpdate('cascade');
            $table->foreign('book_id', 'FK_books_user_highlights')->references('id')->on(config('database.connections.dbp.database').'.books');
        });
        Schema::connection('dbp_users')->table('user_notes', function (Blueprint $table) {
            $table->foreign('bible_id', 'FK_bibles_user_notes')->references('id')->on(config('database.connections.dbp.database').'.bibles')->onDelete('cascade')->onUpdate('cascade');
            $table->foreign('book_id', 'FK_books_user_notes')->references('id')->on(config('database.connections.dbp.database').'.books')->onUpdate('cascade')->onDelete('cascade');
        });
        Schema::connection('dbp_users')->table('user_playlists', function (Blueprint $table) {
            $table->foreign('language_id', 'FK_languages_playlists')->references('id')->on(config('database.connections.dbp.database') . '.languages')->onUpdate('cascade');
        });
        Schema::connection('dbp_users')->table('user_settings', function (Blueprint $table) {
            $table->foreign('bible_id', 'FK_bibles_user_settings')->references('id')->on(config('database.connections.dbp.database').'.bibles')->onDelete('cascade')->onUpdate('cascade');
            $table->foreign('book_id', 'FK_books_user_settings')->references('id')->on(config('database.connections.dbp.database').'.books');
            $table->foreign('language_id', 'FK_languages_user_settings')->references('id')->on(config('database.connections.dbp.database').'.languages')->onUpdate('cascade');
        });
    }
}
