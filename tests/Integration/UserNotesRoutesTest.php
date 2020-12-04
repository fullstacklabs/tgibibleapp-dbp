<?php

namespace Tests\Integration;

use App\Models\User\Key;

use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Arr;

class UserNotesRoutesTest extends ApiV4Test
{
    use WithFaker;

    /**
     * @category V4_API
     * @category Route Name: v4_notes.index
     * @category Route Path: https://api.dbp.test/users/5/notes?v=4&key={key}
     * @see      \App\Http\Controllers\User\NotesController
     * @group    V4
     * @group    travis
     * @test
     */
    public function notesIndex()
    {
        $key = Key::where('key', $this->key)->first();
        $path = route('v4_notes.index', Arr::add($this->params, 'user_id', 451869));
        echo "\nTesting: GET $path";
        $response = $this->withHeaders($this->params)->get($path);
        $response->assertSuccessful();
        $result = json_decode($response->getContent());
        $notes = $result->data;
        $this->assertEquals(15, count($notes));
        // transformer test / relationship test
        $this->assertEquals('Romans', $notes[0]->book_name);
        // getBibleNameAttribute test
        $this->assertEquals('English Standard Version', $notes[0]->bible_name);
        // getVerseTextAttribute test
        $this->assertEquals('For the wages of sin is death, but the free gift of God is eternal life in Christ Jesus our Lord.', $notes[0]->verse_text);
        sleep(10);
    }

    /**
     * @category V4_API
     * @category Route Name: v4_notes.index
     * @category Route Path: https://api.dbp.test/users/5/notes?v=4&key={key}
     * @see      \App\Http\Controllers\User\NotesController
     * @group    V4
     * @group    travis
     * @test
     */
    public function notesIndexSearch()
    {
        $key = Key::where('key', $this->key)->first();
        $params = array_merge(['user_id' => 451869, 'query'=>'Romans'], $this->params);
        $path = route('v4_notes.index', $params);
        echo "\nTesting: GET $path";
        $response = $this->withHeaders($this->params)->get($path);
        $response->assertSuccessful();
        $result = json_decode($response->getContent());
        $notes = $result->data;
        $this->assertEquals(1, count($notes));
        // transformer test / relationship test
        $this->assertEquals('Romans', $notes[0]->book_name);
        // getBibleNameAttribute test
        $this->assertEquals('English Standard Version', $notes[0]->bible_name);
        // getVerseTextAttribute test
        $this->assertEquals('For the wages of sin is death, but the free gift of God is eternal life in Christ Jesus our Lord.', $notes[0]->verse_text);
        sleep(10);
    }

    /**
     * @category V4_API
     * @category Route Name: v4_notes.index
     * @category Route Path: https://api.dbp.test/users/5/notes?v=4&key={key}
     * @see      \App\Http\Controllers\User\NotesController
     * @group    V4
     * @group    travis
     * @test
     */
    public function notesIndexSearchEmpty()
    {
        $key = Key::where('key', $this->key)->first();
        $params = array_merge(['user_id' => 451869, 'query'=>'X'], $this->params);
        $path = route('v4_notes.index', $params);
        echo "\nTesting: GET $path";
        $response = $this->withHeaders($this->params)->get($path);
        $response->assertSuccessful();
        $result = json_decode($response->getContent());
        $notes = $result->data;
        $this->assertEquals(0, count($notes));
        sleep(10);
    }

    /**
     * @category V4_API
     * @category Route Name: v4_notes.show
     * @category Route Path: https://api.dbp.test/users/451869/notes/198319?v=4&key={key}
     * @see      \App\Http\Controllers\User\NotesController
     * @group    V4
     * @group    travis
     * @test
     */
    public function notesShow()
    {
        $key = Key::where('key', $this->key)->first();
        $params = array_merge(['user_id' => 451869, 'id'=>198319], $this->params);
        $path = route('v4_notes.show', $params);
        echo "\nTesting: GET $path";
        $response = $this->withHeaders($this->params)->get($path);
        $response->assertSuccessful();
        $notes = json_decode($response->getContent(), true);
        $this->assertEquals(13, count($notes)); // expecting 13 fields...
        // transformer test / relationship test
        // weird no book name here...
        //$this->assertEquals("Romans", $notes['book_name']);
        // getBibleNameAttribute test
        $this->assertEquals('English Standard Version', $notes['bible_name']);
        // getVerseTextAttribute test
        $this->assertEquals('For the wages of sin is death, but the free gift of God is eternal life in Christ Jesus our Lord.', $notes['verse_text']);
        sleep(10);
    }

    /**
     * @category V4_API
     * @category Route Name: v4_notes
     * @category Route Path: https://api.dbp.test/users/5/notes?v=4&key={key}
     * @see      \App\Http\Controllers\User\NotesController
     * @group    V4
     * @group    travis
     * @test
     */
    public function notes()
    {
        $key = Key::where('key', $this->key)->first();
        $test_note = [
            'user_id' => $key->user_id,
            'bible_id' => 'ENGESV',
            'book_id' => 'GEN',
            'chapter' => 1,
            'verse_start' => 1,
            'verse_end' => 2,
            'notes' => 'A generated test note',
        ];
        $path = route('v4_notes.store', $this->params);
        echo "\nTesting: POST $path";
        $response = $this->withHeaders($this->params)->post($path, $test_note);
        $response->assertSuccessful();

        $test_created_note = json_decode($response->getContent())->data;

        $path = route('v4_notes.show', array_merge(['user_id' => $key->user_id,'note_id' => $test_created_note->id], $this->params));
        echo "\nTesting: GET $path";
        $response = $this->withHeaders($this->params)->get($path);
        $response->assertSuccessful();
        $result = json_decode($response->getContent() . '', true);
        $this->assertEquals($test_created_note->id, $result['id']);
        $this->assertEquals($test_created_note->notes, $result['notes']);

        $path = route('v4_notes.update', array_merge(['user_id' => $key->user_id,'note_id' => $test_created_note->id], $this->params));
        echo "\nTesting: PUT $path";
        $response = $this->withHeaders($this->params)->put($path, ['description' => 'A generated test note that has been updated']);
        $result = json_decode($response->getContent().'', true);
        $response->assertSuccessful();
        $this->assertEquals('Note Updated', $result['success']);

        $path = route('v4_notes.destroy', array_merge(['user_id' => $key->user_id,'note_id' => $test_created_note->id], $this->params));
        echo "\nTesting: DELETE $path";
        $response = $this->withHeaders($this->params)->delete($path);
        $response->assertSuccessful();
        $result = json_decode($response->getContent() . '', true);
        $this->assertEquals('Note Deleted', $result['success']);
    }

    /**
     * @category V4_API
     * @category Route Name: v4_notes.store
     * @category Route Path: https://api.dbp.test/users/5/notes?v=4&key={key}
     * @see      \App\Http\Controllers\User\NotesController
     * @group    V4
     * @group    travis
     * @test
     */
    public function notesMissingBook()
    {
        $key = Key::where('key', $this->key)->first();
        $test_note = [
            'user_id' => $key->user_id,
            'bible_id' => 'ENGESV',
            'book_id' => 'X',
            'chapter' => 1,
            'verse_start' => 1,
            'verse_end' => 2,
            'notes' => 'A generated test note',
        ];
        $path = route('v4_notes.store', $this->params);
        echo "\nTesting: POST $path";
        $response = $this->withHeaders($this->params)->post($path, $test_note);
        $result = json_decode($response->getContent().'', true);
        $this->assertEquals(1, count($result)); // expecting 1 field...
        $this->assertEquals(true, isset($result['errors'])); // expecting errors field
        $this->assertEquals(true, isset($result['errors']['bible_id'])); // expecting errors.bible_id field
    }

    /**
     * @category V4_API
     * @category Route Name: v4_notes.store
     * @category Route Path: https://api.dbp.test/users/5/notes?v=4&key={key}
     * @see      \App\Http\Controllers\User\NotesController
     * @group    V4
     * @group    travis
     * @test
     */
    public function notesMissingBible()
    {
        $key = Key::where('key', $this->key)->first();
        $test_note = [
            'user_id' => $key->user_id,
            'bible_id' => 'X',
            'book_id' => 'X',
            'chapter' => 1,
            'verse_start' => 1,
            'verse_end' => 2,
            'notes' => 'A generated test note',
        ];
        $path = route('v4_notes.store', $this->params);
        echo "\nTesting: POST $path";
        $response = $this->withHeaders($this->params)->post($path, $test_note);
        $result = json_decode($response->getContent().'', true);
        $this->assertEquals(1, count($result)); // expecting 1 field...
        $this->assertEquals(true, isset($result['errors'])); // expecting errors field
        $this->assertEquals(true, isset($result['errors']['bible_id'])); // expecting errors.bible_id field
    }

    /**
     * @category V4_API
     * @category Route Name: v4_notes.store
     * @category Route Path: https://api.dbp.test/users/5/notes?v=4&key={key}
     * @see      \App\Http\Controllers\User\NotesController
     * @group    V4
     * @group    travis
     * @test
     */
    public function notesMissingBible2()
    {
        $key = Key::where('key', $this->key)->first();
        $test_note = [
            'user_id' => $key->user_id,
            'bible_id' => 'X',
            'chapter' => 1,
            'verse_start' => 1,
            'verse_end' => 2,
            'notes' => 'A generated test note',
        ];
        $path = route('v4_notes.store', $this->params);
        echo "\nTesting: POST $path";
        $response = $this->withHeaders($this->params)->post($path, $test_note);
        $result = json_decode($response->getContent().'', true);
        $this->assertEquals(1, count($result)); // expecting 1 field...
        $this->assertEquals(true, isset($result['errors'])); // expecting errors field
        $this->assertEquals(true, isset($result['errors']['bible_id'])); // expecting errors.bible_id field
    }
}
