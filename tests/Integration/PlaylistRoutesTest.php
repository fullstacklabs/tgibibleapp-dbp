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
        //
        $test_playlist_id = 472982;
        $path = route('v4_playlists.show', array_merge($this->params, [
            'playlist_id'    => $test_playlist_id,
        ]));
        echo "\nTesting: $path";
        $response = $this->withHeaders($this->params)->get($path);
        $response->assertSuccessful();
        $result = collect(json_decode($response->getContent()));
        $this->assertEquals($result->count(), 13); // has 13 fields...
        $this->assertEquals($result['id'], $test_playlist_id);
        //$this->assertEquals($result->user->id, '1223628');
    }
}
