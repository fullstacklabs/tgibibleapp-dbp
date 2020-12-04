<?php

namespace Tests\Integration;

use App\Models\Bible\BibleEquivalent;
use App\Models\Bible\BibleFile;
use App\Models\Bible\BibleFileset;
use App\Traits\AccessControlAPI;

use Illuminate\Support\Arr;

class BiblesRoutesTest extends ApiV4Test
{
    use AccessControlAPI;


    /**
     * @category V4_API
     * @category Route Name: v4_filesets.types
     * @category Route Path: https://api.dbp.test/bibles/filesets/media/types?v=4&key={key}
     * @see      \App\Http\Controllers\Bible\BibleFileSetsController::mediaTypes
     * @group    BibleRoutes
     * @group    V4
     * @group    travis
     * @test
     */
    public function bibleFilesetsTypes()
    {
        $path = route('v4_filesets.types', $this->params);
        echo "\nTesting: $path";

        $response = $this->withHeaders($this->params)->get($path);
        $response->assertSuccessful();
    }

    /**
     * @category V4_API
     * @category Route Name: v4_filesets.showAudio
     * @category Route Path: https://api.dbp.test/bibles/filesets/media/types?v=4&key={key}
     * @see      \App\Http\Controllers\Bible\BibleFileSetsController::mediaTypes
     * @group    BibleRoutes
     * @group    V4
     * @group    travis
     * @test
     */
    public function showAudio()
    {
        $params = array_merge(['fileset_id' => 'UBUANDP2DA'], $this->params);
        $path = route('v4_filesets.showAudio', $params);
        echo "\nTesting: GET $path";

        $response = $this->withHeaders($this->params)->get($path);
        $response->assertSuccessful();
    }

    /**
     * @category V4_API
     * @category Route Name: v4_filesets.showStream
     * @category Route Path: https://api.dbp.test/bibles/filesets/media/types?v=4&key={key}
     * @see      \App\Http\Controllers\Bible\BibleFileSetsController::mediaTypes
     * @group    BibleRoutes
     * @group    V4
     * @group    travis
     * @test
     */
    public function showStream()
    {
        $params = array_merge(['fileset_id' => 'ENGESV', 'book_id' => 'GEN'], $this->params);
        $path = route('v4_filesets.showStream', $params);
        echo "\nTesting: GET $path";

        $response = $this->withHeaders($this->params)->get($path);
        $response->assertSuccessful();
        $result = json_decode($response->getContent().'',  true);
        $this->assertEquals(50, count($result));
    }

    /**
     * @category V4_API
     * @category Route Name: v4_filesets.podcast
     * @category Route Path: https://api.dbp.test/bibles/filesets/{fileset_id}/podcast?v=4&key={key}
     * @see      \App\Http\Controllers\Bible\BibleFilesetsPodcastController::index
     * @group    BibleRoutes
     * @group    V4
     * @group    travis
     * @test
     */
    public function bibleFilesetsPodcast()
    {
        $fileset = BibleFileset::uniqueFileset(null, 'dbp-prod', 'audio')->inRandomOrder()->first();
        $this->params['id'] = $fileset->id;
        $path = route('v4_filesets.podcast', $this->params);
        echo "\nTesting: $path";

        $response = $this->withHeaders($this->params)->get($path);
        $response->assertSuccessful();
    }

    /**
     * @category V4_API
     * @category Route Name: v4_filesets.download
     * @category Route Path: https://api.dbp.test/bibles/filesets/{fileset_id}/download?v=4&key={key}
     * @see      \App\Http\Controllers\Bible\BibleFileSetsController::download
     * @group    BibleRoutes
     * @group    V4
     * @group    travis
     * @test
     */
    public function bibleFilesetsDownload()
    {
        $this->markTestIncomplete('Awaiting Fileset download zips');
        $path = route('v4_filesets.download', $this->params);
        echo "\nTesting: $path";

        $response = $this->withHeaders($this->params)->get($path);
        $response->assertSuccessful();
    }

    /**
     * @category V4_API
     * @category Route Name: v4_filesets.copyright
     * @category Route Path: https://api.dbp.test/bibles/filesets/ENGESV/copyright?v=4&key={key}
     * @see      \App\Http\Controllers\Bible\BibleFileSetsController::copyright
     * @group    BibleRoutes
     * @group    V4
     * @group    travis
     * @test
     */
    public function bibleFilesetsCopyright()
    {
        $params = array_merge(['fileset_id' => 'UBUANDP2DA','type' => 'audio_drama'], $this->params);
        $path = route('v4_filesets.copyright', $params);
        echo "\nTesting: $path";

        $response = $this->withHeaders($this->params)->get($path);
        $response->assertSuccessful();
    }

    /**
     * @category V4_API
     * @category Route Name: v4_filesets.books
     * @category Route Path: https://api.dbp.test/bibles/filesets/ENGESV/books?v=4&key={key}&fileset_type=text_plain
     * @see      \App\Http\Controllers\Bible\BooksController::show
     * @group    BibleRoutes
     * @group    V4
     * @group    travis
     * @test
     */
    public function bibleFilesetsBooks()
    {
        $this->markTestIncomplete('Book transformer needs fix');
        $params = array_merge(['fileset_id' => 'ENGESV', 'fileset_type' => 'text_plain'], $this->params);
        $path = route('v4_filesets.books', $params);
        echo "\nTesting: GET $path";

        $response = $this->withHeaders($this->params)->get($path);
        $response->assertSuccessful();
    }


    /**
     * @category V4_API
     * @category Route Name: v4_filesets.show
     * @category Route Path: https://api.dbp.test/bibles/filesets/ENGESV?v=4&key={key}&type=text_plain&bucket=dbp-prod
     * @see      \App\Http\Controllers\Bible\BibleFileSetsController::show
     * @group    BibleRoutes
     * @group    V4
     * @group    non-travis
     * @test
     */
    public function bibleFilesetsShow()
    {
        $this->markTestIncomplete('Seed Access Control has no records for this key');
        $access_control = $this->accessControl($this->key);
        $file = BibleFile::with('fileset')->whereIn('hash_id', $access_control->hashes)->inRandomOrder()->first();

        $path = route('v4_filesets.show', array_merge([
            'fileset_id' => $file->fileset->id,
            'book_id'    => $file->book_id,
            'chapter'    => $file->chapter_start,
            'type'       => $file->fileset->set_type_code,
            'bucket'     => $file->fileset->asset_id
        ], $this->params));

        echo "\nTesting: $path";
        $response = $this->withHeaders($this->params)->get($path);
        $response->assertSuccessful();
    }

    /**
     * @category V4_API
     * @category Route Name: v4_filesets.showFeatured
     * @category Route Path: https://api.dbp.test/bibles/filesets/ENGESV/verses?v=4&key={key}&type=text_plain&bucket=dbp-prod
     * @see      \App\Http\Controllers\Bible\BibleFileSetsController::showFeatured
     * @group    BibleRoutes
     * @group    V4
     * @group    non-travis
     * @test
     */
    public function bibleFileSetsShowFeatured()
    {
        // just hard code for now
        $path = route('v4_filesets.showFeatured', array_merge([
            'bible_id' => 'BMQBSM',
        ], $this->params));

        echo "\nTesting: GET $path";
        $response = $this->withHeaders($this->params)->get($path);
        $response->assertSuccessful();
        $result = json_decode($response->getContent(), true);
        $this->assertEquals(count($result), 6);
        $this->assertEquals($result['id'], 'BMQBSM');
        $this->assertEquals($result['set_type_code'], 'text_plain');
    }

    /**
     * @category V4_API
     * @category Route Name: v4_filesets.showMultiple
     * @category Route Path: https://api.dbp.test/bibles/filesets/ENGESV/playlist?v=4&key={key}&type=text_plain&bucket=dbp-prod
     * @see      \App\Http\Controllers\Bible\BibleFileSetsController::getPlaylistMeta
     * @group    BibleRoutes
     * @group    V4
     * @group    non-travis
     * @test
     */
    public function bibleFilesetsShowMultiple()
    {
        // keeping this, because we'll utilitze when we can unhard-code
        //$access_control = $this->accessControl($this->key);
        //$file = BibleFile::with('fileset')->whereIn('hash_id', $access_control->hashes)->inRandomOrder()->first();

        // just hard code for now
        $path = route('v4_filesets.showMultiple', array_merge([
            'fileset_id' => 'BMQBSM',
            //'book_id'    => $file->book_id,
            //'chapter'    => $file->chapter_start,
            //'type'       => $file->fileset->set_type_code,
            //'bucket'     => $file->fileset->asset_id
        ], $this->params));

        echo "\nTesting: $path";
        $response = $this->withHeaders($this->params)->get($path);
        $response->assertSuccessful();
        $result = collect(json_decode($response->getContent()));
        $this->assertEquals($result->count(), 1);
        $this->assertEquals(count($result['BMQBSM']), 1);
        $this->assertEquals($result['BMQBSM'][0]->id, 'BMQBSM');
    }

    /**
     * @category V4_API
     * @category Route Name: v4_bible.getAudio
     * @category Route Path: https://api.dbp.test/bibles/ENGESV/audio?v=4&key={key}&type=text_plain&bucket=dbp-prod
     * @see      \App\Http\Controllers\Bible\BibleFileSetsController::getAudio
     * @group    BibleRoutes
     * @group    V4
     * @group    non-travis
     * @test
     */
    public function bibleGetAudio()
    {
        //$this->markTestIncomplete('audio count can change...');
        // keeping this, because we'll utilitze when we can unhard-code
        //$access_control = $this->accessControl($this->key);
        //$file = BibleFile::with('fileset')->whereIn('hash_id', $access_control->hashes)->inRandomOrder()->first();

        // just hard code for now
        $path = route('v4_bible.getAudio', array_merge([
            'bible_id' => 'BMQBSM',
            //'book_id'    => $file->book_id,
            //'chapter'    => $file->chapter_start,
            //'type'       => $file->fileset->set_type_code,
            //'bucket'     => $file->fileset->asset_id
        ], $this->params));

        echo "\nTesting: $path";
        $response = $this->withHeaders($this->params)->get($path);
        $response->assertSuccessful();
        $result = json_decode($response->getContent(), true);
        // should have 2 keys: language and audio
        $this->assertEquals(2, count($result));
        // language check
        $this->assertEquals('Bomu', $result['language']);
        // audio check
        $this->assertEquals(3, collect($result['audio'])->count());
    }


    /**
     * @category V4_API
     * @category Route Name: v4_bible.bookSearch
     * @category Route Path: https://api.dbp.test/bibles/book/search/Roman?v=4&key={key}
     * @see      \App\Http\Controllers\Bible\BibleFileSetsController::bookSearch
     * @group    BibleRoutes
     * @group    V4
     * @group    non-travis
     * @test
     */
    public function bibleBookSearch()
    {
        $params = array_merge(['query' => 'e'], $this->params);
        $path = route('v4_bible.bookSearch', $params);
        echo "\nTesting: GET $path";

        $response = $this->withHeaders($this->params)->get($path);
        $response->assertSuccessful();
    }

    /**
     * @category V4_API
     * @category Route Name: v4_bible.bookVerse
     * @category Route Path: https://api.dbp.test/bibles/BIBLE_ID/book/BOOK_ID/CHATPER/VERSE_START?v=4&key={key}
     * @see      \App\Http\Controllers\Bible\BibleFileSetsController::bookVerse
     * @group    BibleRoutes
     * @group    V4
     * @group    non-travis
     * @test
     */
    public function bibleBookVerse()
    {
        $params = array_merge(['bible_id'=>'ENGESV', 'book_id'=>'ROM', 'chapter'=>6, 'verse_start'=>23], $this->params);
        $path = route('v4_bible.bookVerse', $params);
        echo "\nTesting: GET $path";

        $response = $this->withHeaders($this->params)->get($path);
        $response->assertSuccessful();
    }

    /**
     * @category V4_API
     * @category Route Name: v4_bible.oneName
     * @category Route Path: https://api.dbp.test/bibles/BIBLE_ID/name/LANGUAGE?v=4&key={key}
     * @see      \App\Http\Controllers\Bible\BibleFileSetsController::bookVerse
     * @group    BibleRoutes
     * @group    V4
     * @group    non-travis
     * @test
     */
    public function bibleShowName()
    {
        $params = array_merge(['bible_id'=>'ENGESV', 'language'=>6414], $this->params);
        $path = route('v4_bible.oneName', $params);
        echo "\nTesting: GET $path";

        $response = $this->withHeaders($this->params)->get($path);
        $response->assertSuccessful();
    }

    /**
     * @category V4_API
     * @category Route Name: v4_bible.links
     * @category Route Path: https://api.dbp.test/bibles/links?v=4&key={key}
     * @see      \App\Http\Controllers\Bible\BibleLinksController::index
     * @group    BibleRoutes
     * @group    V4
     * @group    travis
     * @test
     */
    public function bibleLinks()
    {
        $path = route('v4_bible.links', Arr::add($this->params, 'iso', 'eng'));
        echo "\nTesting: $path";
        $response = $this->withHeaders($this->params)->get($path);
        $response->assertSuccessful();
    }

    /**
     * @category V4_API
     * @category Route Name: v4_bible_books_all
     * @category Route Path: https://api.dbp.test/bibles/books/?v=4&key={key}
     * @see      \App\Http\Controllers\Bible\BooksController::index
     * @group    BibleRoutes
     * @group    V4
     * @group    travis
     * @test
     */
    public function bibleBooksAll()
    {
        $path = route('v4_bible_books_all', $this->params);
        echo "\nTesting: $path";
        $response = $this->withHeaders($this->params)->get($path);
        $response->assertSuccessful();
    }

    /**
     * @category V4_API
     * @category Route Name: v4_bible_equivalents.all
     * @category Route Path: https://api.dbp.test/bible/equivalents?v=4&key={key}
     * @see      \App\Http\Controllers\Bible\BibleEquivalentsController::index
     * @group    BibleRoutes
     * @group    V4
     * @group    travis
     * @test
     */
    public function bibleEquivalentsAll()
    {
        $path = route('v4_bible_equivalents.all', $this->params);
        echo "\nTesting: $path";
        $response = $this->withHeaders($this->params)->get($path);
        $response->assertSuccessful();
    }

    /*     * @group    travis
     * @test */
    public function bibleEquivalentsCanBeFilteredByBible()
    {
        $bible_path = route('v4_bible_equivalents.all', array_merge(['bible_id' => 'ENGESV'], $this->params));
        $response = $this->withHeaders($this->params)->get($bible_path);
        $response->assertSuccessful();
    }

    /*     * @group    travis
     * @test */
    public function bibleEquivalentsCanBeFilteredByOrganization()
    {
        $bible_equivalents = BibleEquivalent::inRandomOrder()->first();
        $org_path = route('v4_bible_equivalents.all', array_merge(['organization_id' => $bible_equivalents->organization_id], $this->params));
        $response = $this->withHeaders($this->params)->get($org_path);
        $response->assertSuccessful();

        $content = collect(json_decode($response->getContent()))->pluck('organization_id')->unique();
        $this->assertEquals($content->count(), 1);
        $this->assertEquals($content[0], $bible_equivalents->organization_id);
    }


    /**
     * @category V4_API
     * @category Route Name: v4_bible.books
     * @category Route Path: https://api.dbp.test/bibles/ENGESV/book?v=4&key={key}
     * @see      \App\Http\Controllers\Bible\BiblesController::books
     * @group    BibleRoutes
     * @group    V4
     * @group    travis
     * @test
     */
    public function bibleBooks()
    {
        $path = route('v4_bible.books', array_merge(['bible_id' => 'ENGESV', 'book' => 'MAT'], $this->params));
        echo "\nTesting: $path";
        $response = $this->withHeaders($this->params)->get($path);
        $response->assertSuccessful();
    }

    /**
     * @category V4_API
     * @category Route Name: v4_bible.archival
     * @category Route Path: https://api.dbp.test/bibles/archival?v=4&key={key}
     * @see      \App\Http\Controllers\Bible\BiblesController::archival
     * @group    BibleRoutes
     * @group    V4
     * @group    travis
     * @test
     */
    public function bibleArchival()
    {
        $this->markTestIncomplete('Route is not defined');
        $path = route('v4_bible.archival', $this->params);
        echo "\nTesting: $path";
        $response = $this->withHeaders($this->params)->get($path);
        $response->assertSuccessful();
    }

    /**
     * @category V4_API
     * @category Route Name: v4_bible.one
     * @category Route Path: https://api.dbp.test/bibles/{bible_id}?v=4&key={key}
     * @see      \App\Http\Controllers\Bible\BiblesController::show
     * @group    BibleRoutes
     * @group    V4
     * @group    travis
     * @test
     */
    public function bibleOne()
    {
        $this->markTestIncomplete('Seed data does not have this bible');
        $path = route('v4_bible.one', Arr::add($this->params, 'bible_id', 'ENGESV'));
        echo "\nTesting: $path";
        $response = $this->withHeaders($this->params)->get($path);
        $response->assertSuccessful();
    }

    /**
     * @category V4_API
     * @category Route Name: v4_bible.all
     * @category Route Path: https://api.dbp.test/bibles?v=4&key={key}
     * @see      \App\Http\Controllers\Bible\BiblesController::index
     * @group    BibleRoutes
     * @group    V4
     * @group    travis
     * @test
     */
    public function bibleAll()
    {
        $path = route('v4_bible.all', $this->params);
        echo "\nTesting: $path";
        $response = $this->withHeaders($this->params)->get($path);
        $response->assertSuccessful();
    }

    /**
     * @category V4_API
     * @category Route Name: v4_bible.chapter.annotations
     * @category Route Path: https://api.dbp.test/bibles/BIBLE_ID/chapter/annotations?v=4&key={key}
     * @see      \App\Http\Controllers\Bible\BiblesController::annotations
     * @group    BibleRoutes
     * @group    V4
     * @group    travis
     * @test
     */
    public function bibleAnnotations()
    {
        $params = array_merge([
          'bible_id' => 'ENGESV',
          'key' => 'Fiv9LrnDNlT1WjHaCktVqTnn',
          // update user_api_tokens set api_token = '456542f8a59eac51e895c5ff9f388dc93fa0f0f88fc43e3e3ec16bacf20dafd0' where user_id=1255630;
          'api_token' => 'IRSooPKAWU5dUeEVw6W2rQy3o6ursYtbjMGSeLjljcDSUjopSbEXXIBweli7'
        ], $this->params);
        $path = route('v4_bible.chapter.annotations', $params);
        $response = $this->withHeaders($this->params)->get($path);
        $response->assertSuccessful();
    }

    /**
      * @category Route Name: v4_bible.bibleVerses
      * @category Route Path: https://api.dbp.test/bibles/X/verses?v=4&key={key}
      * @see      \App\Http\Controllers\Bible\BiblesController::bibleVerses
      * @group    BibleRoutes
      * @group    V4
      * @group    travis
      * @test
      */
    public function bibleVerses()
    {
        $params = array_merge([ 'bible_id' => 'ENGESV' ], $this->params);
        $path = route('v4_bible.bibleVerses', $params);
        echo "\nTesting: $path";
        $response = $this->withHeaders($this->params)->get($path);
        $response->assertSuccessful();
    }

    /**
     * @category V4_API
     * @category Route Name: v4_bible.getFilesetVernacularMetaData
     * @category Route Path: https://api.dbp.test/bibles/X/books/Y/testament/Z?v=4&key={key}
     * @see      \App\Http\Controllers\Bible\BiblesController::bibleVerses
     * @group    BibleRoutes
     * @group    V4
     * @group    travis
     * @test
     */
    public function bibleGetFilesetVernacularMetaData()
    {
        $params = array_merge([ 'bible_id' => 'ENGESV', 'book_id' => 'GEN', 'testament' => 'NT' ], $this->params);
        $path = route('v4_bible.bibleVerses', $params);
        echo "\nTesting: $path";
        $response = $this->withHeaders($this->params)->get($path);
        $response->assertSuccessful();
    }

    /**
     * @category V4_API
     * @category Route Name: v4_bible.bookOrder
     * @category Route Path: https://api.dbp.test/bibles/book/order?v=4&key={key}
     * @see      \App\Http\Controllers\Bible\BiblesController::getBookOrder
     * @group    BibleRoutes
     * @group    V4
     * @group    travis
     * @test
     */
    public function bibleGetBookOrder()
    {
        $params = array_merge([ 'bible_id' => 'ENGESV', 'book_id' => 'GEN', 'testament' => 'NT' ], $this->params);
        $path = route('v4_bible.bookOrder', $params);
        echo "\nTesting: $path";
        $response = $this->withHeaders($this->params)->get($path);
        $response->assertSuccessful();
    }
}
