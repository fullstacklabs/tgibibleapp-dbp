<?php

namespace Tests\Integration;

use App\Http\Controllers\Playlist\PlaylistController;

class PlaylistRoutesTest extends ApiV4Test
{

    /**
     * @category V4_API
     * @category Route Name: v4_internal_playlists.show
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
        $path = route('v4_internal_playlists.show', array_merge($this->params, [
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
     * @category Route Name: v4_internal_playlists.show_text
     * @category Route Path: https://api.dbp.test/playlists/?v=4&key={key}
     * @see      PlaylistsController::show
     * @group    V4
     * @group    travis
     * @test
     */
    public function showText()
    {
        // just hard code for now
        $test_playlist_id = 875;
        $path = route('v4_internal_playlists.show_text', array_merge($this->params, [
            'playlist_id'    => $test_playlist_id,
        ]));
        echo "\nTesting: $path";
        $response = $this->withHeaders($this->params)->get($path);
        echo $response->getContent();
        $result = json_decode($response->getContent(), true);
        $response->assertSuccessful();
        $this->assertEquals(19, count($result)); // has 13 fields...
        $keys = array_keys($result);
        // first playlist_item
        $this->assertEquals(107078, $keys[0]);
        $this->assertEquals(31, count($result[107078]));
        //$this->assertEquals($result->user->id, '1223628');
    }


    /**
     * @category V4_API
     * @category Route Name: v4_internal_playlists.hls
     * @category Route Path: https://api.dbp.test/playlists/{playlist_id}/hls?v=4&key={key}
     * @see      PlaylistsController::hls
     * @group    V4
     * @group    travis
     * @test
     */
    public function hls()
    {
        $this->markTestIncomplete('TGI doesnt need this. It requires aws/cloudfront signing');
        // just hard code for now
        $test_playlist_id = 875;
        $path = route('v4_internal_playlists.hls', array_merge($this->params, [
            'playlist_id'    => $test_playlist_id,
        ]));
        echo "\nTesting: $path";
        $response = $this->withHeaders($this->params)->get($path);
        $result = json_decode($response->getContent());
        $response->assertSuccessful();
    }

    /**
     * @category V4_API
     * @category Route Name: v4_internal_playlists_item.hls
     * @category Route Path: https://api.dbp.test/playlists/{playlist_item_id}/item-hls?v=4&key={key}
     * @see      PlaylistsController::itemHls
     * @group    V4
     * @group    travis
     * @test
     */
    public function itemHls()
    {
        $this->markTestIncomplete('TGI doesnt need this. It requires aws/cloudfront signing');
        // just hard code for now
        $test_playlist_item_id = 107078; // part of 875
        $path = route('v4_internal_playlists_item.hls', array_merge($this->params, [
            'playlist_item_id'    => $test_playlist_item_id,
        ]));
        echo "\nTesting: $path";
        $response = $this->withHeaders($this->params)->get($path);
        $result = json_decode($response->getContent());
        $response->assertSuccessful();
    }
}
