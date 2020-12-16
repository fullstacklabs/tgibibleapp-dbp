<?php

namespace Tests\Integration;

use App\Models\User\Key;
use App\Models\User\User;

use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Arr;

class UserBookmarkRoutesTest extends ApiV4Test
{
    use WithFaker;

    /**
     * @category V4_API
     * @category Route Name: v4_bookmarks.index
     * @category Route Path: https://api.dbp.test/users/{user_id}/bookmarks?v=4&key={key}
     * @see      \App\Http\Controllers\User\BookmarksController::index
     * @group    V4
     * @group    travis
     * @test
     */
    public function bookmarksIndex()
    {
        /*
        // we have one key (test-key)
        // which is user_id 1, I used 451869
        // which has no bookmarks...
        $key = Key::where('key', $this->key)->first();
        //echo "UserID: ", $key->user_id, "<br>\n";
        */

        // Hard coding user_id for testing for now
        $path = route('v4_bookmarks.index', Arr::add($this->params, 'user_id', 451869));
        echo "\nTesting: GET $path";
        $response = $this->withHeaders($this->params)->get($path);
        //echo 'GET:', $response->getContent(), "\n";
        $response->assertSuccessful();
        $result = json_decode($response->getContent());
        $bookmarks = $result->data;

        $this->assertEquals(1, count($bookmarks));
        // test BooksTransformer / books() relationship
        $this->assertEquals('Psalms', $bookmarks[0]->book_name);
        // test model getVerseTextAttribute()
        $this->assertEquals('"Surely goodness and mercy shall follow me all the days of my life, and I shall dwell in the house of the LORD forever."', $bookmarks[0]->verse_text);
    }

    /**
     * @category V4_API
     * @category Route Name: v4_bookmarks.index
     * @category Route Path: https://api.dbp.test/users/{user_id}/bookmarks?v=4&key={key}
     * @see      \App\Http\Controllers\User\BookmarksController::index
     * @group    V4
     * @group    travis
     * @test
     */
    public function bookmarksIndexSearch()
    {
        // Hard coding user_id for testing for now
        $params = array_merge(['user_id' => 451869, 'query'=>'Psalms'], $this->params);

        $path = route('v4_bookmarks.index', $params);
        echo "\nTesting: GET $path";
        $response = $this->withHeaders($this->params)->get($path);
        //echo 'GET:', $response->getContent(), "\n";
        $response->assertSuccessful();
        $result = json_decode($response->getContent());
        $bookmarks = $result->data;

        // test query remote content
        $this->assertEquals(1, count($bookmarks));
        // test BooksTransformer / books() relationship
        $this->assertEquals('Psalms', $bookmarks[0]->book_name);
        // test model getVerseTextAttribute()
        $this->assertEquals('"Surely goodness and mercy shall follow me all the days of my life, and I shall dwell in the house of the LORD forever."', $bookmarks[0]->verse_text);
    }


    /**
     * @category V4_API
     * @category Route Name: v4_bookmarks.index
     * @category Route Path: https://api.dbp.test/users/{user_id}/bookmarks?v=4&key={key}
     * @see      \App\Http\Controllers\User\BookmarksController::index
     * @group    V4
     * @group    travis
     * @test
     */
    public function bookmarksIndexEmptySearch()
    {
        // Hard coding user_id for testing for now
        $params = array_merge(['user_id' => 451869, 'query'=>'X'], $this->params);

        $path = route('v4_bookmarks.index', $params);
        echo "\nTesting: GET $path";
        $response = $this->withHeaders($this->params)->get($path);
        //echo 'GET:', $response->getContent(), "\n";
        $response->assertSuccessful();
        $result = json_decode($response->getContent());
        $bookmarks = $result->data;

        // test query remote content
        $this->assertEquals(0, count($bookmarks));
    }

    /**
      * @category V4_API
      * @category Route Name: v4_bookmarks.store
      * @category Route Path: https://api.dbp.test/users/{user_id}/bookmarks?v=4&key={key}
      * @see      \App\Http\Controllers\User\BookmarksController::index
      * @group    V4
      * @group    travis
      * @test
      */
    public function bookmarks()
    {
        // we have one key (test-key)
        $key = Key::where('key', $this->key)->first();

        $test_bookmark = [
            'bible_id'      => 'ENGESV',
            'user_id'       => $key->user_id,
            'book_id'       => 'GEN',
            'chapter'       => 1,
            'verse_start'   => 10,
        ];
        $path = route('v4_bookmarks.store', Arr::add($this->params, 'user_id', $key->user_id));
        echo "\nTesting: POST $path";
        $response = $this->withHeaders($this->params)->post($path, $test_bookmark);
        $response->assertSuccessful();
        $result = json_decode($response->getContent().'', true);
        $this->assertEquals(1, count($result['meta']));
        $this->assertEquals('Bookmark Created successfully', $result['meta']['success']);

        $test_bookmark = json_decode($response->getContent())->data;

        $path = route('v4_bookmarks.update', array_merge(['user_id' => $key->user_id,'bookmark_id' =>$test_bookmark->id], $this->params));
        echo "\nTesting: PUT $path";
        $response = $this->withHeaders($this->params)->put($path, ['book_id' => 'EXO']);
        $response->assertSuccessful();
        $result = json_decode($response->getContent().'', true);
        $this->assertEquals(1, count($result['meta']));
        $this->assertEquals('Bookmark Successfully updated', $result['meta']['success']);

        $path = route('v4_bookmarks.destroy', array_merge(['user_id' => $key->user_id,'bookmark_id' =>$test_bookmark->id], $this->params));
        echo "\nTesting: DELETE $path";
        $response = $this->withHeaders($this->params)->delete($path);
        $response->assertSuccessful();
        $this->assertEquals('"bookmark successfully deleted"', $response->getContent());
    }

    /**
     * @category V4_API
     * @category Route Name: v4_bookmarks.store
     * @category Route Path: https://api.dbp.test/users/{user_id}/bookmarks?v=4&key={key}
     * @see      \App\Http\Controllers\User\BookmarksController::index
     * @group    V4
     * @group    travis
     * @test
     */
    public function bookmarksMissingBook()
    {
        // we have one key (test-key)
        $key = Key::where('key', $this->key)->first();

        $test_bookmark = [
            'bible_id'      => 'ENGESV',
            'user_id'       => $key->user_id,
            'book_id'       => 'X',
            'chapter'       => 1,
            'verse_start'   => 10,
        ];
        $path = route('v4_bookmarks.store', Arr::add($this->params, 'user_id', $key->user_id));
        echo "\nTesting: POST $path";
        $response = $this->withHeaders($this->params)->post($path, $test_bookmark);
        $result = json_decode($response->getContent().'', true);
        $this->assertEquals(1, count($result)); // expecting 1 field...
        $this->assertEquals(true, isset($result['error'])); // expecting error field
        $this->assertEquals(true, isset($result['error']['message'])); // expecting error.message field
        $this->assertEquals(422, isset($result['error']['status_code'])); // expecting error.status_code field
    }

    /**
     * @category V4_API
     * @category Route Name: v4_bookmarks.store
     * @category Route Path: https://api.dbp.test/users/{user_id}/bookmarks?v=4&key={key}
     * @see      \App\Http\Controllers\User\BookmarksController::index
     * @group    V4
     * @group    travis
     * @test
     */
    public function bookmarksMissingBible()
    {
        // we have one key (test-key)
        $key = Key::where('key', $this->key)->first();

        $test_bookmark = [
            'bible_id'      => 'X',
            'user_id'       => $key->user_id,
            'book_id'       => 'X',
            'chapter'       => 1,
            'verse_start'   => 10,
        ];
        $path = route('v4_bookmarks.store', Arr::add($this->params, 'user_id', $key->user_id));
        echo "\nTesting: POST $path";
        $response = $this->withHeaders($this->params)->post($path, $test_bookmark);
        $result = json_decode($response->getContent().'', true);
        $this->assertEquals(1, count($result)); // expecting 1 field...
        $this->assertEquals(true, isset($result['error'])); // expecting error field
        $this->assertEquals(true, isset($result['error']['message'])); // expecting error.message field
        $this->assertEquals(422, isset($result['error']['status_code'])); // expecting error.status_code field
    }

    /**
     * @category V4_API
     * @category Route Name: v4_bookmarks.store
     * @category Route Path: https://api.dbp.test/users/{user_id}/bookmarks?v=4&key={key}
     * @see      \App\Http\Controllers\User\BookmarksController::index
     * @group    V4
     * @group    travis
     * @test
     */
    public function bookmarksMissingBible2()
    {
        // we have one key (test-key)
        $key = Key::where('key', $this->key)->first();

        $test_bookmark = [
            'bible_id'      => 'X',
            'user_id'       => $key->user_id,
            'chapter'       => 1,
            'verse_start'   => 10,
        ];
        $path = route('v4_bookmarks.store', Arr::add($this->params, 'user_id', $key->user_id));
        echo "\nTesting: POST $path";
        $response = $this->withHeaders($this->params)->post($path, $test_bookmark);
        $result = json_decode($response->getContent().'', true);
        $this->assertEquals(1, count($result)); // expecting 1 field...
        $this->assertEquals(true, isset($result['error'])); // expecting error field
        $this->assertEquals(true, isset($result['error']['message'])); // expecting error.message field
        $this->assertEquals(422, isset($result['error']['status_code'])); // expecting error.status_code field
    }
}
