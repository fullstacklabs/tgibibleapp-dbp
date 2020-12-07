<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Collection\Collection;
use App\Models\Collection\CollectionPlaylist;
use App\Models\Playlist\Playlist;
use App\Models\Playlist\PlaylistItems;
use Illuminate\Support\Facades\DB;

class SyncCollectionsPlaylistsAndroid extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync:collectionsPlaylistsAndroid';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Extract html/xml from old android src';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
        $this->bible_id = 'ENGESV'; // should be ENGGID
        $this->fileset_id = 'ENGESV'; // should be ENGGID?
        $this->user_id = 1255627; // my userid, the FK enforces this to be a valid user...
    }

    private function ensurePlaylistItem($playlist_id, $book_id, $chapter, $verseList)
    {
        // find playlist
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
    private function ensurePlaylist($collection_id, $name, $language_id = 6414, $create = true)
    {
        // find playlist
        $coll = DB::connection('dbp_users')->table('user_playlists')
          ->where('name', '=', $name)->where('user_id', '=', $this->user_id)
          ->where('language_id', '=', $language_id)->get();
        if (!$coll->count()) {
            // if not found then create
            if ($create) {
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
            } else {
                return 0;
            }
        }
        $collRow = $coll->toArray();
        $this->ensurePlaylistCollection($collection_id, $collRow[0]->id);
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

    private function commasDashes($str)
    {
        if (strpos($str, '–') !== false) {
            $parts = explode('–', $str);
            $start = $parts[0];
            $stop  = $parts[1];
            return range($start, $stop);
        }
        if (strpos($str, '-') !== false) {
            $parts = explode('-', $str);
            $start = $parts[0];
            $stop  = $parts[1];
            return range($start, $stop);
        }
        if (strpos($str, ',') !== false) {
            $arr = explode(',', $str);
            foreach ($arr as $i=>$item) {
                $arr[$i] = trim($item);
            }
            return $arr;
        }
        return [$str];
    }

    private function decodeChapterVerse($verse)
    {
        $parts = explode(' ', $verse);
        $book = array_shift($parts);
        // handle "1 Thess."
        if ($book === '1') {
            $book .= ' ' . array_shift($parts);
        }
        if ($book === '2') {
            $book .= ' ' . array_shift($parts);
        }
        $book = str_replace('.', '', $book);
        $book_id = $this->locateBook($book);
        if (!$book_id) {
            echo "[$book] does not exist\n";
            exit();
        }
        $verses_part = join(' ', $parts);
        if (strpos($verses_part, ':') !== false) {
            $startstop = explode(':', $verses_part);
            $chapter = $startstop[0];
            $verses  = $this->commasDashes($startstop[1]);
        } else {
            $chapter = $verses_part;
            $verses = [];
        }
        return [
        'book_id' => $book_id,
        'chapter' => $chapter,
        'verses' => $verses
      ];
    }

    private function reloadPlaylistItems($playlist_id, $bible_id, $verse_data)
    {
        // delete all items in this playlist...
        // clear new playlist if not clear
        DB::connection('dbp_users')->table('playlist_items')
          ->where('playlist_id', '=', $playlist_id)->delete();

        $book_id = $verse_data['book_id'];
        $chapter = $verse_data['chapter'];
        $verses  = $verse_data['verses'];
        $playlist_items_to_create = [];
        $order = 1;
        foreach ($verses as $verse) {
            $playlist_items_to_create[] = [
                'playlist_id'       => $playlist_id,
                'fileset_id'        => $bible_id,
                'book_id'           => $book_id,
                'chapter_start'     => $chapter,
                'chapter_end'       => $chapter,
                // because we're unclear if the range is contigeous
                // we'll just create them one by one
                'verse_start'       => $verse,
                'verse_end'         => $verse,
                'verses'            => 1,
                'order_column'      => $order
            ];
            $order += 1;
        }
        PlaylistItems::insert($playlist_items_to_create);
    }

    private function readXmlStrings($file)
    {
        if (!file_exists($file)) {
            echo "$file not found\n";
            return;
        }
        $xml = simplexml_load_file($file);

        $strings = [];
        foreach ($xml->string as $obj) {
            $key = $obj['name'] . '';
            $html = $obj . '';
            $strings[$key] = $html;
        }
        // $strings will contain the following keys
        // kjv_decision1, kjv_decision2, esv_decision1, esv_decision2
        // also help_default_dam but not sure what it is (PESTPVN2ET)
        return $strings;
    }

    private function readXML($language_id, $bible_id, $file, $collection_id)
    {
        $en_file = preg_replace('|values[A-Za-z-\.]+|', 'values', $file);
        if (!file_exists($file)) {
            echo "[$language_id][$bible_id] $file not found\n";
            return;
        }
        echo $file, "\n";

        $xml = simplexml_load_file($file);
        $playlists = [];
        foreach ($xml as $sa) {
            $eng_collection_title = $sa['name'].'';
            $i = 0;
            foreach ($sa->item as $item) {

                // fix the one bad json line in the whole bunch
                if ($item.'' === '{\"topic\":\"Sexe\":[\"Eph. 5:3, 4\",\"1 Thess. 4:3, 4\",\"2Tim 2:22\"]}') {
                    $item = '{\"topic\":\"Sexe\",\"verses\":[\"Eph. 5:3, 4\",\"1 Thess. 4:3, 4\",\"2Tim 2:22\"]}';
                }
                $json = json_decode(stripslashes($item).'', true);
                $title = $json['topic'];
                $playlist_id = $this->ensurePlaylist($collection_id, $title, $language_id);

                $playlists[$json['topic']] = [
                  'verses'=>[],
                ];
                // commit data
                if (is_array($json['verses'])) {
                    foreach ($json['verses'] as $verse) {
                        $playlists[$json['topic']]['verses'] = $this->decodeChapterVerse($verse);
                        $this->reloadPlaylistItems($playlist_id, $bible_id, $playlists[$json['topic']]['verses']);
                    }
                } else {
                    echo "Verses is not an array\n";
                    print_r($item);
                    exit();
                }
                $i++;
            }
        }
        // key is translated playlist name
        // values are verses in versespeak
        return $playlists;
    }

    private function spreadsheet($file, $data, $bible_id, $lang)
    {
        $line = '';
        if ($data === null) {
            return;
        } // files dne
        if (!is_array($data)) {
            echo "Data incorrect format [$data][", gettype($data), "]\n";
            print_r($data);
            exit(1);
        }
        foreach ($data as $name => $v) {
            $verses = $v['verses'];
            $line .= '"' . $lang . '","' . $bible_id . '","' . $name . '","' . $verses['book_id'] . ' ' . $verses['chapter'] . ':' . join(',', $verses['verses']) . '"' . "\n";
        }
        file_put_contents($file, $line, FILE_APPEND);
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
        $dir = 'storage/data/main/res/';

        $headerLine = '"lang","bible_id","playlist name","verses"' . "\n";
        file_put_contents('col17.csv', $headerLine);
        file_put_contents('col18.csv', $headerLine);
        file_put_contents('col19.csv', $headerLine);

        // Open a known directory, and proceed to read its contents
        if (is_dir($dir)) {
            if ($dh = opendir($dir)) {
                $trans = [];
                while (($file = readdir($dh)) !== false) {
                    if ($file[0] === '.') {
                        continue;
                    }
                    if (strpos($file, 'values-') !== false) {
                        $parts = explode('-', $file);
                        $lang = array_pop($parts);
                        echo $lang, '=>', $file, "\n";
                        $language_id = $this->getLanguage($lang);
                        if (!$language_id) {
                            echo "Can't find[$lang]\n";
                        }
                        if (strlen($lang) === 3 && $lang[0] === 'r') {
                            echo "Chinese [$lang]\n";
                        }
                        $bible_id = $this->getDefaultBibleLanguage($lang);
                        if (!$bible_id) {
                            echo "Can't find default bible for [$lang]\n";
                            continue;
                        }
                        $strings_path = $dir . $file . '/strings.xml';
                        if (file_exists($strings_path)) {
                            $xml = simplexml_load_file($strings_path);
                            $trans[$lang] = [];
                            foreach ($xml->string as $obj) {
                                $name = $obj['name'].'';
                                $trans[$lang][$name] = $obj.'';
                            }
                        } else {
                            echo "No strings.xml for [$file]\n";
                            exit();
                        }
                        $hton_path = $dir . $file . '/help_times_of_need_topics.xml';
                        $hlp_path = $dir . $file . '/help_life_problems_topics.xml';
                        $cc_path = $dir . $file . '/christian_character_topics.xml';
                        $col17 = $this->readXML($language_id, $bible_id, $hton_path, 17);
                        $col18 = $this->readXML($language_id, $bible_id, $hlp_path, 18);
                        $col19 = $this->readXML($language_id, $bible_id, $cc_path, 19);

                        if (0) {
                            $strings_path = $dir . $file . '/gideons_res.xml';
                            $strings = $this->readXmlStrings($strings_path);
                            // maybe add to a languages csv?
                        }

                        $this->spreadsheet('col17.csv', $col17, $bible_id, $lang);
                        $this->spreadsheet('col18.csv', $col18, $bible_id, $lang);
                        $this->spreadsheet('col19.csv', $col19, $bible_id, $lang);
                    }
                }
                closedir($dh);
            }
        }
    }
}
