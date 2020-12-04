<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Collection\Collection;
use App\Models\Collection\CollectionPlaylist;
use App\Models\Playlist\Playlist;
use App\Models\Playlist\PlaylistItems;
use App\Http\Controllers\Playlist\PlaylistsController;
use Illuminate\Support\Facades\DB;

class SyncCollectionsPlaylists extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync:collectionsPlaylists';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync CSV with collections/collections_playlist';

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

    // only used by english collection playlists
    // this builds the items inside the newly created playlists
    private function ensurePlaylistItem($playlist_id, $book_id, $chapter, $verseList)
    {
        // did we already create this playlist...
        $coll = DB::connection('dbp_users')->table('playlist_items')
          ->where('playlist_id', '=', $playlist_id)->where('book_id', '=', $book_id)
          ->where('fileset_id', '=', $this->fileset_id)
          ->where('chapter_start', '=', $chapter)->where('chapter_end', '=', $chapter)
          ->where('verse_start', '=', $verseList)->where('verse_end', '=', $verseList)
          ->get();
        if (!$coll->count()) {
            // if not found then create
            echo "Creating [$playlist_id]playlistItem book[$book_id] $chapter:$verseList\n";
            $id = PlaylistItems::insertGetId([
                'playlist_id'   => $playlist_id,
                'book_id'       => $book_id,
                'fileset_id'    => $this->fileset_id,
                'chapter_start' => $chapter,
                'chapter_end'   => $chapter,
                'verse_start'   => $verseList,
                'verse_end'     => $verseList,
                'verses'        => 1,
                //'duration'      => 0,
            ]);
            return $id;
        }
        $collRow = $coll->toArray();
        return $collRow[0]->id;
    }

    // for every playlist we create, we need to link it to a collection
    private function ensurePlaylistCollection($collection_id, $playlist_id)
    {
        // find link
        $coll = DB::connection('dbp_users')->table('collection_playlists')
          ->where('collection_id', '=', $collection_id)->where('playlist_id', '=', $playlist_id)->get();
        if (!$coll->count()) {
            // if not found then create
            echo "Creating link [{$collection_id}\\$playlist_id]\n";
            $id = CollectionPlaylist::insertGetId([
              'collection_id' => $collection_id,
              'playlist_id'   => $playlist_id,
            ]);
            return $id;
        }
        $collRow = $coll->toArray();
        return $collRow[0]->id;
    }

    // this also ensures a collection playlist for this playlist
    private function ensurePlaylist($collection_id, $name, $language_id = 6414)
    {
        // find playlist
        $coll = DB::connection('dbp_users')->table('user_playlists')
          ->where('name', '=', $name)->where('user_id', '=', $this->user_id)
          ->where('language_id', '=', $language_id)->get();
        if (!$coll->count()) {
            // if not found then create
            echo "Creating playlist [$name]\n";
            $id = Playlist::insertGetId([
              'name'        => $name,
              'featured'    => true, // since it has to be tied to a user, I think we need this for access
              'user_id'     => $this->user_id,
              'draft'       => 0,
              'language_id' => $language_id,
            ]);
            $this->ensurePlaylistCollection($collection_id, $id);
            return $id;
        }
        $collRow = $coll->toArray();
        $this->ensurePlaylistCollection($collection_id, $collRow[0]->id);
        return $collRow[0]->id;
    }

    private function ensureCollection($name)
    {
        // find
        $coll = DB::connection('dbp_users')->table('collections')->where('name', '=', $name)->get();
        if (!$coll->count()) {
            // if not found then create
            echo "Creating collection [$name]\n";
            $id = Collection::insertGetId([
              'name'        => $name,
              'featured'    => true,
              'language_id' => 6414,
            ]);
            return $id;
        }
        $collRow = $coll->toArray();
        return $collRow[0]->id;
    }

    private function getLanguage($lang)
    {
        // find
        if (strlen($lang) !== 2) {
            // XX_YY, where X is lang and Y is region
            // there's nothing to match, so just fail for now
            $coll = DB::connection('dbp')->table('languages')->where('iso1', '=', $lang)->get();
        } else {
            $coll = DB::connection('dbp')->table('languages')->where('iso1', '=', $lang)->get();
        }
        if (!$coll->count()) {
            return 0;
        }
        $collRow = $coll->toArray();
        return $collRow[0]->id;
    }

    private function getDefaultBibleLanguage($lang)
    {
        $coll = DB::connection('dbp')->table('bibles_defaults')->where('type', '=', 'audio')
          ->where('language_code', '=', $lang)->select(['bible_id'])->get();
        if (!$coll->count()) {
            return 0;
        }
        $collRow = $coll->toArray();
        return $collRow[0]->bible_id;
    }

    private function ensurePlaylistItems($src_playlist_id, $trg_playlist_id, $trg_bible_id)
    {
        $plc = new PlaylistsController;
        // get a list of items
        // can't directly pull from playlist_items table
        // supporting functions expect the remote split
        $srcList = $plc->getPlaylist(false, $src_playlist_id);
        if (!$srcList->items->count()) {
            echo "Playlist [$src_playlist_id] has no playlist items\n";
            return false;
        }

        $result = $plc->translate_items($trg_bible_id, $srcList->items);
        if ($result === -1) {
            echo "trg_bible_id[$trg_bible_id] not found\n";
            return;
        }
        if (!is_object($result)) {
            echo "trg_bible_id[$trg_bible_id] not found\n";
            return;
        }
        $translated_items = $result->translated_items;
        foreach ($translated_items as $item) {
            // before we create it, make sure the fileset_id exists...
            $bible = DB::connection('dbp')->table('bible_filesets')
              ->where('id', '=', $item->fileset_id);
            if (!$bible->count()) {
                echo 'Missing fileset_id[', $item->fileset_id, "]\n";
            }
        }

        $dst = DB::connection('dbp_users')->table('user_playlists')
          ->where('id', '=', $trg_playlist_id)->first();

        // clear new playlist if not clear
        DB::connection('dbp_users')->table('playlist_items')
          ->where('playlist_id', '=', $trg_playlist_id)->delete();

        // add to new playlist if not already there...
        $created_items = $plc->createTranslatedPlaylistItems($dst, $translated_items);
        if (!count($created_items)) {
            //echo "in[", $srcList->items->count(),"]($src_playlist_id) xlated[", count($translated_items), "]($trg_bible_id) out[", count($created_items), "]($trg_playlist_id)\n";
        }
        return true;
    }

    private function locateBook($name)
    {
        $fixUps = [
          '1 Peter'    => '1Pet',
          '2 Peter'    => '2Pet',
          'Hebrews'    => 'Heb',
          'James'      => 'Jas',
          'Matthew'    => 'Matt',
          'Psalm'      => 'Ps',
          'Romans'     => 'Rom',
          '2 Timothy'  => '2Tim',
          'Revelation' => 'Rev',
        ];
        $noSpaceName = str_replace(' ', '', $name);
        if (isset($fixUps[$name])) {
            $noSpaceName = $fixUps[$name];
        }
        $coll = DB::connection('dbp')->table('books')->where('id_osis', '=', $noSpaceName)->get();
        if ($coll->count() > 1) {
            echo "$noSpaceName: ", $coll->count(), "\n";
        }
        $collRow = $coll->toArray();
        if ($coll->count() !== 1) {
            return 0;
        }
        $collRow = $coll->toArray();
        $book_id = $collRow[0]->id;
        // join to make sure it's attached to the associated bible...
        $coll2 = DB::connection('dbp')->table('bible_books')->where('bible_id', '=', $this->bible_id)->where('book_id', '=', $book_id)->get();
        if ($coll2->count() !== 1) {
            echo "[$book_id] doesn't exist in [", $this->bible_id, '] count[', $coll2->count(), "]\n";
            return 0;
        }
        return $book_id;
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        // this will nuke everything from the user and all collection playlists...
        /*
        delete from collections where user_id = 1255627;
        delete from user_playlists where user_id = 1255627;
        delete from collection_playlists;
        */

        $collections_eng_path = storage_path('data/201104_CollectionPlaylistVerseMatrix.csv');
        $collections_eng = csvToArray($collections_eng_path);
        $missing = 0;
        $missingBooks = [];
        foreach ($collections_eng as $row) {
            // Collection, Playlist Title is same in both

            // Collection
            $collection_id = $this->ensureCollection($row['Collection']);
            // Playlist Title (en)
            $playlist_id = $this->ensurePlaylist($collection_id, $row['Playlist Title'], 6414);

            // 1104 version
            // Book, Chapter, VersesList
            $book_id = $this->locateBook($row['Book']);
            if (!$book_id) {
                if (!isset($missingBooks[$row['Book']])) {
                    echo 'Could not locate book[', $row['Book'], "]\n";
                    $missingBooks[$row['Book']] = 1;
                }
                $missing++;
                continue;
            }
            // create a playlist item based on chapter/VersesList
            $this->ensurePlaylistItem($playlist_id, $book_id, $row['Chapter'], $row['VersesList']);
        }
        echo "Missing book records[$missing] Unique missing books[", count($missingBooks), "]\n";

        $collections_path = storage_path('data/AllTranslations-ByLanguageCode.csv');
        $collections = csvToArray($collections_path);
        foreach ($collections as $row) {
            // only interested in collections
            if ($row['Grouping Type'] !== 'Collection') {
                continue;
            }

            $collection_id = $this->ensureCollection($row['Grouping Name']);
            $en_playlist_id = $this->ensurePlaylist($collection_id, $row['English Text to Translate'], 6414);


            $keys = array_keys($row);
            $lang = $row[$keys[0]]; // just grab the first field...
            // don't need to translate english
            // and skip langs that getLanguage fail on
            $skip_langs = ['en', 'en-US', 'es-MX', 'fr-CA', 'pt-BR', 'zh_TW'];
            if (in_array($lang, $skip_langs)) {
                continue;
            }

            $en_title = $row['English Text to Translate'];
            // these ahve no items in playlist
            $skip_titles = ['Christian Character', 'Help In Time of Need', 'Help With Life\'s Problems'];
            if (in_array($en_title, $skip_titles)) {
                continue;
            }

            $language_id = $this->getLanguage($lang);
            if (!$language_id) {
                echo "[$collection_id] Can't find [$lang]\n";
                exit();
            }
            if ($language_id === 6414) {
                continue;
            } // don't need to translate english

            if ($lang === 'ro') {
                $lang = 'ro-RO';
            } // fix up
            $langBible_id = $this->getDefaultBibleLanguage($lang);
            if (!$langBible_id) {
                echo "[$collection_id] Can't find default bible for [$lang]\n";
                exit();
            }

            // so we need to ensure a playlist with this language and custom title
            $playlist_id = $this->ensurePlaylist($collection_id, $row['Translated Text'], $language_id);

            // copy playlist_items from this playlist_id...
            if (!$this->ensurePlaylistItems($en_playlist_id, $playlist_id, $langBible_id)) {
                echo "Aborting, please find the correct english(6414) title for playlist_id($en_playlist_id) and fix the csv\n";
                echo "Incorrect title[$en_title]\n";
                exit();
            }
        }
    }
}
