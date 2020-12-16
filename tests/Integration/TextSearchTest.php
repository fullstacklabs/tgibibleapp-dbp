<?php

namespace Tests\Integration;

use App\Models\Bible\BibleVerse;

class TextSearchTest extends ApiV4Test
{
    public $params = [];

    /**
     *
     * @category V4_API
     * @category Route Name: v4_text_search
     * @category Route Path: https://api.dbp.test/search?v=4&key={key}
     * @see      \App\Http\Controllers\Bible\TextController::search
     * @group    V4
     * @group    travis
     * @test
     */
    public function nonMatchingSearchResultsReturnsEmpty()
    {
        $bible_verse = false;
        while (!$bible_verse) {
            $bible_verse = BibleVerse::with('fileset')->where('id', random_int(1, BibleVerse::count()))->first();
        }
        $this->params['asset_id']     = $bible_verse->fileset->asset_id;
        $this->params['fileset_id']   = $bible_verse->fileset->id;
        $this->params['query']        = 'supercalafragalisticz';
        $path = route('v4_text_search', $this->params);
        echo "\n Testing GET ". $path;
        $results = json_decode($this->get($path)->getContent(), true);
        $this->assertEmpty($results['verses']['data']);
    }

    /* - Swagger -------------------------*/
    /**
     * @category V4_API
     * @category Route Name: v4_text_search
     * @category Route Path: https://api.dbp.test/search?v=4&key={key}
     * @see      \App\Http\Controllers\Bible\TextController::search
     * @group    V4
     * @group    travis
     * @test
     */
    public function v4SwaggerForTextSearch()
    {
        $bible_verse = false;
        while (!$bible_verse) {
            $bible_verse = BibleVerse::with('fileset')->where('id', random_int(1, BibleVerse::count()))->first();
        }
        $word = $this->selectSearchableWord($bible_verse);
        $this->params['asset_id'] = $bible_verse->fileset->asset_id;
        $this->params['dam_id']   = $bible_verse->fileset->id;
        $this->params['query']    = preg_replace("/(?![.=$'€%-])\p{P}/u", '', $word);
        $this->params['limit']    = 6;

        $path = route('v4_text_search', array_merge(['fileset_id' => $bible_verse->fileset->id,'query' => $word], $this->params));
        echo "\n Testing ". $path;

        $response = $this->withHeaders($this->params)->get($path);
        $response->assertSuccessful();
    }

    /**
     * @category V2_Text
     * @see \App\Http\Controllers\Bible\TextController::search()
     * @category Swagger ID: TextSearch
     * @category Route Name: v2_text_search
     * @link Route Path: https://api.dbp.test/text/search?v=2&query=God&dam_id=ENGESV&limit=5&pretty&key={key}
     * @group    V2
     * @group    travis
     * @test
     */
    public function v2SwaggerForTextSearch()
    {
        $this->params['v'] = 2;
        $bible_verse = false;
        while (!$bible_verse) {
            $bible_verse = BibleVerse::with('fileset')->where('id', random_int(1, BibleVerse::count()))->first();
        }
        $word = $this->selectSearchableWord($bible_verse);
        $this->params['asset_id'] = $bible_verse->fileset->asset_id;
        $this->params['dam_id']   = $bible_verse->fileset->id;
        $this->params['query']    = preg_replace("/(?![.=$'€%-])\p{P}/u", '', $word);
        $this->params['limit']    = 6;

        $path = route('v2_text_search', $this->params);
        echo $path;

        $response = $this->withHeaders($this->params)->get($path);
        $response_content = json_decode($response->getContent(), true);
        $response->assertSuccessful();

        echo "\n Testing ".route('v2_text_search', $this->params);
        $this->assertLessThanOrEqual($this->params['limit'], count($response_content['verses']));
        foreach ($response_content['verses'] as $verse) {
            $this->assertContains($word, $verse['verse_text'], 'Search Term Not Present in Result', 1);
        }
    }


    /**
     * @category V2_Text
     * @see \app\Http\Controllers\TextController::searchGroup()
     * @category Swagger ID: TextSearchGroup
     * @category Route Name: v2_text_search_group
     * @link Route Path: https://api.dbp.test/text/searchgroup?v=2&query=God&dam_id=ENGESV&limit=5&pretty&key={key}
     * @group    V2
     * @group    travis
     * @test
     */
    public function v2SwaggerForTextSearchGroup()
    {
        $this->params['v'] = 2;
        $bible_verse = false;
        while (!$bible_verse) {
            $bible_verse = BibleVerse::with('fileset')->where('id', random_int(1, BibleVerse::count()))->first();
        }
        $word = $this->selectSearchableWord($bible_verse);

        $this->params['dam_id'] = $bible_verse->fileset->id;
        $this->params['asset_id'] = $bible_verse->fileset->asset_id;
        $this->params['query']  = preg_replace("/(?![.=$'€%-])\p{P}/u", '', $word);
        $this->params['limit']  = 5;

        echo "\n Testing ".route('v2_text_search_group', $this->params);

        $response = $this->withHeaders($this->params)->get(route('v2_text_search_group'), $this->params);
        $response_content = json_decode($response->getContent());
        $response->assertSuccessful();

        foreach ($response_content[1] as $verse) {
            $this->assertContains($word, $verse->verse_text, 'Search Term Not Present in Result', 1);
        }
    }

    private function selectSearchableWord($bible_verse)
    {
        $words = explode(' ', $bible_verse->verse_text);

        // sort values by longest
        usort($words, function ($a, $b) {
            return \strlen($b) <=> \strlen($a);
        });

        // return one word taken from the top 5 longest, stripped of punctuation
        $word = preg_replace("/(?![.=$'€%-])\p{P}/u", '', collect($words)->take(5)->random(1)->first());
        $word = rtrim($word, '.');
        return $word;
    }

    /**
     * @see \app\Http\Controllers\TextController::searchGroup()
     * @category Route Name: v4_library_search
     * @link Route Path: https://api.dbp.test/search/library?v=4&key={key}&query=God&pretty
     * @group    V4
     * @group    travis
     * @test
     */
    public function searchLibrary()
    {
        $params = array_merge([
          'query' => 'e',
          'key' => 'Fiv9LrnDNlT1WjHaCktVqTnn',
           // update user_api_tokens set api_token = '456542f8a59eac51e895c5ff9f388dc93fa0f0f88fc43e3e3ec16bacf20dafd0' where user_id=1255630;
           'api_token' => 'IRSooPKAWU5dUeEVw6W2rQy3o6ursYtbjMGSeLjljcDSUjopSbEXXIBweli7'
        ], $this->params);
        $path = route('v4_library_search', $params);
        echo "\n Testing GET ". $path;
        $response = $this->get($path);
        $results = json_decode($response->getContent(), true);
        $response->assertSuccessful();
    }

    /**
     * @see \app\Http\Controllers\TextController::searchGroup()
     * @category Route Name: v4_library_search
     * @link Route Path: https://api.dbp.test/search/library?v=4&key={key}&query=God&pretty
     * @group    V4
     * @group    travis
     * @test
     */
    public function searchLibraryEmpty()
    {
        $params = array_merge([
          'query' => 'God',
          'key' => 'Fiv9LrnDNlT1WjHaCktVqTnn',
           // update user_api_tokens set api_token = '456542f8a59eac51e895c5ff9f388dc93fa0f0f88fc43e3e3ec16bacf20dafd0' where user_id=1255630;
           'api_token' => 'IRSooPKAWU5dUeEVw6W2rQy3o6ursYtbjMGSeLjljcDSUjopSbEXXIBweli7'
        ], $this->params);
        $path = route('v4_library_search', $params);
        echo "\n Testing GET ". $path;
        $response = $this->get($path);
        $results = json_decode($response->getContent(), true);
        //print_r($results);
        $response->assertSuccessful();
        $this->assertEmpty($results['bookmarks']);
        $this->assertEmpty($results['highlights']);
        $this->assertEmpty($results['notes']);
        $this->assertEmpty($results['plans']);
        $this->assertEmpty($results['playlists']);
    }
}
