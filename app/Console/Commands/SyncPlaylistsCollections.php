<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Collection\Collection;
use App\Models\Collection\CollectionPlaylist;
use App\Models\Playlist\Playlist;
use App\Models\Playlist\PlaylistItems;
use Illuminate\Support\Facades\DB;

class SyncPlaylistsCollections extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync:playlistsCollections';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create collection_playlists from CSV';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
        $this->bible_id = 'ENGESV'; // should be ENGGID
        $this->fileset_id = 'ENGESVN2SA'; // should be ENGGID?
        $this->user_id = 1255627; // my userid, the FK enforces this to be a valid user...
    }

    public function createPlaylistItem($playlist_id, $book_id, $chapter, $verse_start, $verse_end)
    {
        $playlist_item = DB::connection('dbp_users')->table('playlist_items')
          ->where('playlist_id', '=', $playlist_id)->where('book_id', '=', $book_id)
          ->where('fileset_id', '=', $this->fileset_id)
          ->where('chapter_start', '=', $chapter)->where('chapter_end', '=', $chapter)
          ->where('verse_start', '=', $verseList)->where('verse_end', '=', $verseList)
          ->get();

        if (!$playlist_item->count()) {
            echo "Creating [$playlist_id]playlistItem book[$book_id] $chapter:$verse_start-$verse_end\n";
            $id = PlaylistItems::insertGetId([
                'playlist_id'   => $playlist_id,
                'book_id'       => $book_id,
                'fileset_id'    => $this->fileset_id,
                'chapter_start' => $chapter,
                'chapter_end'   => $chapter,
                'verse_start'   => $verse_start,
                'verse_end'     => $verse_end,
                'verses'        => 1,
                'duration'      => 0,
            ]);
            return $id;
        }

        $items_row = $playlist_item->toArray();
        return $items_row[0]->id;
    }

    public function createPlaylistCollection($collection_id, $playlist_id)
    {
        $coll_playlist = DB::connection('dbp_users')->table('collection_playlists')
              ->where('collection_id', '=', $collection_id)->where('playlist_id', '=', $playlist_id)->get();
        if (!$coll_playlist->count()) {
            echo "Creating link [{$collection_id}\\$playlist_id]\n";
            $id = CollectionPlaylist::insertGetId([
                  'collection_id' => $collection_id,
                  'playlist_id'   => $playlist_id,
                ]);
            return $id;
        }
        $coll_array = $coll_playlist->toArray();
        return $coll_array[0]->id;
    }


    public function createPlaylist($collection_id, $name, $language_id = 6414)
    {
        // find playlist
        $playlist = DB::connection('dbp_users')->table('user_playlists')
          ->where('name', '=', $name)->where('user_id', '=', $this->user_id)
          ->where('language_id', '=', $language_id)->get();
        
        if (!$playlist->count()) {
            echo "Creating playlist [$name]\n";
            $id = Playlist::insertGetId([
              'name'        => $name,
              'featured'    => true,
              'user_id'     => $this->user_id,
              'draft'       => 0,
              'language_id' => $language_id,
            ]);
            $this->createPlaylistCollection($collection_id, $id);
            return $id;
        }

        $playlist_array = $playlist->toArray();
        $this->createPlaylistCollection($collection_id, $playlist_array[0]->id);
        return $playlist_array[0]->id;
    }

    public function createCollection($name)
    {
        $coll = DB::connection('dbp_users')->table('collections')->where('name', '=', $name)->get();
        if (!$coll->count()) {
            echo "Creating collection [$name]\n";
            $id = Collection::insertGetId([
              'name'        => $name,
              'featured'    => true,
              'language_id' => 6414,
            ]);
            return $id;
        }
        $coll_array = $coll->toArray();
        return $coll_array[0]->id;
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $collections_eng_path = storage_path('data/prepared_collection_data.csv');
        $collections_eng = csvToArray($collections_eng_path);

        foreach ($collections_eng as $row) {
            $csv_items = $row['ChapterVerses2'];
            $csv_items = explode(',', $csv_items);

            foreach ($csv_items as $item) {
                if ($item !== '') {
                    $item_with_book = explode('*', $item);
                    $item_with_chapter = explode(':', $item_with_book[1]);

                    // validation for verse start and end missing with -
                    // php takes it as \u2013

                    $book_id = $item_with_book[0];
                    $chapter = $item_with_chapter[0];
                }
            }
        }
    }
}
