<?php

namespace Tests\Integration;

use App\Http\Controllers\Playlist\PlaylistController;

class PlaylistRoutesTest extends ApiV4Test
{

    /**
     * @category V4_API
     * @category Route Name: v4_playlists.show
     * @category Route Path: https://api.dbp.test/playlists/?v=4&key={key}
     * @see      PlaylistsController::show
     * @group    V4
     * @group    travis
     * @test
     */
    public function show()
    {
        // just hard code for now
        $test_playlist_id = 875;
        $path = route('v4_playlists.show', array_merge($this->params, [        
            'playlist_id'    => $test_playlist_id,
        ]));
        echo "\nTesting: $path";
        $response = $this->withHeaders($this->params)->get($path);
        $result = json_decode($response->getContent(), true);
        $response->assertSuccessful();
        $this->assertEquals(13, count($result)); // has 13 fields...
        $this->assertEquals($test_playlist_id, $result['id']);
        $this->assertEquals(19, count($result['items']));
        //$this->assertEquals($result->user->id, '1223628');
    }

    /**
     * @category V4_API
     * @category Route Name: v4_playlists.translate
     * @category Route Path: https://api.dbp.test/playlists/X/translate?v=4&key={key}&bible_id=ENGESV
     * @see      PlaylistsController::show
     * @group    V4
     * @group    travis
     * @test
     */
    public function translate()
    {
        // just hard code for now
        $test_playlist_id = 875;
        $path = route('v4_playlists.translate', array_merge($this->params, [
            'playlist_id' => $test_playlist_id,
            'bible_id'    => 'ENGESV',
            'api_token'   => 'IRSooPKAWU5dUeEVw6W2rQy3o6ursYtbjMGSeLjljcDSUjopSbEXXIBweli7',
        ]));
        echo "\nTesting: $path";
        $response = $this->withHeaders($this->params)->get($path);
        $result = json_decode($response->getContent(), true);
        $response->assertSuccessful();
        $this->assertEquals(15, count($result)); // has 15 fields...
        // it's making a new playlist, don't need to check id
        // double check to make sure the correct number of items are in the translation
        $this->assertEquals(18, count($result['items']));
        $this->assertEquals('Love: English ESV', $result['name']);
    }


    /**
     * @category V4_API
     * @category Route Name: v4_playlists.hls
     * @category Route Path: https://api.dbp.test/playlists/{playlist_id}/hls?v=4&key={key}
     * @see      PlaylistsController::hls
     * @group    V4
     * @group    travis
     * @test
     */
    public function hls()
    {
        // just hard code for now
        $this->markTestIncomplete('hls routes can not run on gideons to cloudfront signing issues');
        $test_playlist_id = 875;
        $path = route('v4_playlists.hls', array_merge($this->params, [
            'playlist_id'    => $test_playlist_id,
        ]));
        echo "\nTesting: $path";
        $response = $this->withHeaders($this->params)->get($path);
        $result = json_decode($response->getContent());
        $response->assertSuccessful();
    }

    /**
     * @category V4_API
     * @category Route Name: v4_playlists_item.hls
     * @category Route Path: https://api.dbp.test/playlists/{playlist_item_id}/item-hls?v=4&key={key}
     * @see      PlaylistsController::itemHls
     * @group    V4
     * @group    travis
     * @test
     */
    public function itemHls()
    {
        $this->markTestIncomplete('hls routes can not run on gideons due to cloudfront signing issues');
        // just hard code for now
        $test_playlist_item_id = 107078; // part of 875
        $path = route('v4_playlists_item.hls', array_merge($this->params, [
            'playlist_item_id'    => $test_playlist_item_id,
        ]));
        echo "\nTesting: $path";
        $response = $this->withHeaders($this->params)->get($path);
        $result = json_decode($response->getContent());
        $response->assertSuccessful();
    }
}
