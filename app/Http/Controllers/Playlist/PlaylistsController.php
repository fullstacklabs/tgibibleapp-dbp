<?php

namespace App\Http\Controllers\Playlist;

use App\Traits\AccessControlAPI;
use App\Http\Controllers\APIController;
use App\Http\Controllers\Bible\BibleFileSetsController;
use App\Models\Bible\Bible;
use App\Models\Bible\BibleFile;
use App\Models\Language\Language;
use App\Models\Plan\UserPlan;
use App\Models\Playlist\Playlist;
use App\Models\Playlist\PlaylistFollower;
use App\Models\Playlist\PlaylistItems;
use App\Traits\CallsBucketsTrait;
use App\Traits\CheckProjectMembership;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use GuzzleHttp\Client;

class PlaylistsController extends APIController
{
    use AccessControlAPI;
    use CheckProjectMembership;
    use CallsBucketsTrait;

    protected $items_limit = 1000;

    /**
     * Display a listing of the resource.
     *
     * @OA\Get(
     *     path="/playlists",
     *     tags={"Playlists"},
     *     summary="List a user's playlists",
     *     operationId="v4_internal_playlists.index",
     *     @OA\Parameter(
     *          name="featured",
     *          in="query",
     *          @OA\Schema(ref="#/components/schemas/Playlist/properties/featured"),
     *          description="Return featured playlists"
     *     ),
     *     @OA\Parameter(
     *          name="show_details",
     *          in="query",
     *          @OA\Schema(type="boolean"),
     *          description="Give full details of the playlist"
     *     ),
     *     @OA\Parameter(
     *          name="show_text",
     *          in="query",
     *          @OA\Schema(type="boolean"),
     *          description="Enable the full details of the playlist and retrieve the text of the items"
     *     ),
     *     @OA\Parameter(
     *          name="iso",
     *          in="query",
     *          @OA\Schema(ref="#/components/schemas/Language/properties/iso"),
     *          description="The iso code to filter plans by. For a complete list see the `iso` field in the `/languages` route"
     *     ),
     *     security={{"api_token":{}}},
     *     @OA\Parameter(ref="#/components/parameters/limit"),
     *     @OA\Parameter(ref="#/components/parameters/page"),
     *     @OA\Parameter(ref="#/components/parameters/sort_by"),
     *     @OA\Parameter(ref="#/components/parameters/sort_dir"),
     *     @OA\Response(
     *         response=200,
     *         description="successful operation",
     *         @OA\MediaType(mediaType="application/json", @OA\Schema(ref="#/components/schemas/v4_playlist_index"))
     *     )
     * )
     *
     * @param $user_id
     *
     * @return mixed
     *
     *
     * @OA\Schema (
     *   type="object",
     *   schema="v4_playlist_index",
     *   description="The v4 playlist index response.",
     *   title="User playlists",
     *   allOf={
     *      @OA\Schema(ref="#/components/schemas/pagination"),
     *   },
     *   @OA\Property(
     *      property="data",
     *      type="array",
     *      @OA\Items(ref="#/components/schemas/v4_playlist")
     *   )
     * )
     */
    public function index(Request $request)
    {
        $user = $request->user();

        // Validate Project / User Connection
        if (!empty($user) && !$this->compareProjects($user->id, $this->key)) {
            return $this->setStatusCode(401)->replyWithError(trans('api.projects_users_not_connected'));
        }

        $sort_by    = checkParam('sort_by') ?? 'name';
        $sort_dir   = checkParam('sort_dir') ?? 'asc';
        $iso = checkParam('iso');

        $featured = checkBoolean('featured') || empty($user);
        $limit    = (int) (checkParam('limit') ?? 25);



        $show_details = checkBoolean('show_details');
        $show_text = checkBoolean('show_text');
        if ($show_text) {
            $show_details = $show_text;
        }

        $language_id = cacheRemember('v4_language_id_from_iso', [$iso], now()->addDay(), function () use ($iso) {
            return optional(Language::where('iso', $iso)->select('id')->first())->id;
        });

        if ($featured) {
            $cache_params = [$show_details, $featured, $sort_by, $sort_dir, $limit, $show_text, $language_id];
            $playlists = cacheRemember('v4_playlist_index', $cache_params, now()->addDay(), function () use ($show_details, $user, $featured, $sort_by, $sort_dir, $limit, $show_text, $language_id) {
                return $this->getPlaylists($show_details, $user, $featured, $sort_by, $sort_dir, $limit, $show_text, $language_id);
            });
            return $this->reply($playlists);
        }


        return $this->reply($this->getPlaylists($show_details, $user, $featured, $sort_by, $sort_dir, $limit, $show_text, $language_id));
    }

    private function getPlaylists($show_details, $user, $featured, $sort_by, $sort_dir, $limit, $show_text, $language_id)
    {
        $has_user = !empty($user);
        $featured = $featured || !$has_user;

        $select = ['user_playlists.*'];

        $following_playlists = [];
        if ($has_user) {
            $following_playlists = PlaylistFollower::where('user_id', $user->id)->get();
        }

        $playlists = Playlist::with('user')
            ->where('draft', 0)
            ->where('plan_id', 0)
            ->when($show_details, function ($query) {
                $query->with('items');
            })
            ->when($language_id, function ($q) use ($language_id) {
                $q->where('user_playlists.language_id', $language_id);
            })
            ->when($featured, function ($q) {
                $q->where('user_playlists.featured', '1');
            })
            ->unless($featured, function ($q) use ($user, $following_playlists) {
                $q->where('user_playlists.user_id', $user->id)
                    ->orWhereIn('user_playlists.id', $following_playlists->pluck('playlist_id'));
            })
            ->select($select)
            ->orderBy($sort_by, $sort_dir)->paginate($limit);

        if ($has_user) {
            $following_playlists = $following_playlists->pluck('playlist_id', 'playlist_id');
        }

        foreach ($playlists->getCollection() as $playlist) {
            if ($show_details) {
                $playlist->path = route('v4_internal_playlists.hls', ['playlist_id'  => $playlist->id, 'v' => $this->v, 'key' => $this->key]);
            }
            if ($show_text) {
                foreach ($playlist->items as $item) {
                    $item->verse_text = $item->getVerseText();
                }
            }
            $playlist->total_duration = PlaylistItems::where('playlist_id', $playlist->id)->sum('duration');
            $playlist->following = $following_playlists[$playlist->id] ?? false;
        }
        return $playlists;
    }

    /**
     * Store a newly created playlist in storage.
     *
     *  @OA\Post(
     *     path="/playlists",
     *     tags={"Playlists"},
     *     summary="Crete a playlist",
     *     operationId="v4_internal_playlists.store",
     *     security={{"api_token":{}}},
     *     @OA\RequestBody(required=true, description="Fields for User Playlist Creation", @OA\MediaType(mediaType="application/json",
     *          @OA\Schema(
     *              @OA\Property(property="name",                  ref="#/components/schemas/Playlist/properties/name"),
     *              @OA\Property(property="draft",                 ref="#/components/schemas/Playlist/properties/draft"),
     *              @OA\Property(property="external_content",      ref="#/components/schemas/Playlist/properties/external_content"),
     *              @OA\Property(property="items",                 ref="#/components/schemas/v4_playlist_items")
     *          )
     *     )),
     *     @OA\Response(response=200, ref="#/components/responses/playlist")
     * )
     *
     * @return \Illuminate\Http\Response|array
     */
    public function store(Request $request)
    {
        // Validate Project / User Connection
        $user = $request->user();
        $user_is_member = $this->compareProjects($user->id, $this->key);

        if (!$user_is_member) {
            return $this->setStatusCode(401)->replyWithError(trans('api.projects_users_not_connected'));
        }

        $name = checkParam('name', true);
        $items = checkParam('items');
        $draft = checkBoolean('draft');
        $external_content = checkParam('external_content');

        $playlist_data = [
            'user_id'           => $user->id,
            'name'              => $name,
            'featured'          => false,
            'draft'             => (bool) $draft
        ];

        if ($external_content) {
            $playlist_data['external_content'] = $external_content;
        }

        $playlist = Playlist::create($playlist_data);

        if ($items) {
            $this->createPlaylistItems($playlist, $items);
        }

        return $this->show($request, $playlist->id);
    }

    /**
     *
     * @OA\Get(
     *     path="/playlists/{playlist_id}/text",
     *     tags={"Playlists"},
     *     summary="A user's playlist text",
     *     operationId="v4_internal_playlists.show_text",
     *     security={{"api_token":{}}},
     *     @OA\Parameter(
     *          name="playlist_id",
     *          in="path",
     *          required=true,
     *          @OA\Schema(ref="#/components/schemas/Playlist/properties/id"),
     *          description="The playlist id"
     *     ),
     *     @OA\Response(response=200, ref="#/components/responses/playlist")
     * )
     *
     * @param $playlist_id
     *
     * @return mixed
     *
     *
     */
    public function showText(Request $request, $playlist_id)
    {
        $user = $request->user();

        // Validate Project / User Connection
        if (!empty($user) && !$this->compareProjects($user->id, $this->key)) {
            return $this->setStatusCode(401)->replyWithError(trans('api.projects_users_not_connected'));
        }

        $playlist = $this->getPlaylist($user, $playlist_id);

        if (!$playlist) {
            return $this->setStatusCode(404)->replyWithError('Playlist Not Found');
        }

        foreach ($playlist->items as $item) {
            $item->verse_text = $item->getVerseText();
        }


        return $this->reply($playlist->items->pluck('verse_text', 'id'));
    }
    /**
     *
     * @OA\Get(
     *     path="/playlists/{playlist_id}",
     *     tags={"Playlists"},
     *     summary="A user's playlist",
     *     operationId="v4_internal_playlists.show",
     *     security={{"api_token":{}}},
     *     @OA\Parameter(
     *          name="playlist_id",
     *          in="path",
     *          required=true,
     *          @OA\Schema(ref="#/components/schemas/Playlist/properties/id"),
     *          description="The playlist id"
     *     ),
     *     @OA\Parameter(
     *          name="show_text",
     *          in="query",
     *          @OA\Schema(type="boolean"),
     *          description="Enable the full details of the playlist and retrieve the text of the items"
     *     ),
     *     @OA\Response(response=200, ref="#/components/responses/playlist")
     * )
     *
     * @param $playlist_id
     *
     * @return mixed
     *
     *
     */
    public function show(Request $request, $playlist_id)
    {
        $user = $request->user();
        $show_text = checkBoolean('show_text');

        // Validate Project / User Connection
        if (!empty($user) && !$this->compareProjects($user->id, $this->key)) {
            return $this->setStatusCode(401)->replyWithError(trans('api.projects_users_not_connected'));
        }

        $playlist = $this->getPlaylist($user, $playlist_id);

        if (!$playlist) {
            return $this->setStatusCode(404)->replyWithError('Playlist Not Found');
        }

        if ($show_text) {
            $playlist_text_filesets = $this->getPlaylistTextFilesets($playlist_id);
            foreach ($playlist->items as $item) {
                $item->verse_text = $item->getVerseText($playlist_text_filesets);
                $item->item_timestamps = $item->getTimestamps();
            }
        }

        $playlist->path = route('v4_internal_playlists.hls', ['playlist_id'  => $playlist_id, 'v' => $this->v, 'key' => $this->key]);
        $playlist->total_duration = PlaylistItems::where('playlist_id', $playlist_id)->sum('duration');

        return $this->reply($playlist);
    }

    /**
     * Update the specified playlist.
     *
     *  @OA\Put(
     *     path="/playlists/{playlist_id}",
     *     tags={"Playlists"},
     *     summary="Update a playlist",
     *     operationId="v4_playlist.update",
     *     security={{"api_token":{}}},
     *     @OA\Parameter(name="playlist_id", in="path", required=true, @OA\Schema(ref="#/components/schemas/Playlist/properties/id")),
     *     @OA\Parameter(name="items", in="query", @OA\Schema(type="string"), description="Comma-separated ids of the playlist items to be sorted or deleted"),
     *     @OA\Parameter(name="delete_items", in="query",@OA\Schema(type="boolean"), description="Will delete all items"),
     *     @OA\RequestBody(required=true, @OA\MediaType(mediaType="application/json",
     *          @OA\Schema(
     *              @OA\Property(property="name", ref="#/components/schemas/Playlist/properties/name"),
     *              @OA\Property(property="external_content", type="string")
     *          )
     *     )),
     *     @OA\Response(response=200, ref="#/components/responses/playlist")
     * )
     *
     * @param  int $playlist_id
     *
     * @return array|\Illuminate\Http\Response
     */
    public function update(Request $request, $playlist_id)
    {
        // Validate Project / User Connection
        $user = $request->user();
        $user_is_member = $this->compareProjects($user->id, $this->key);

        if (!$user_is_member) {
            return $this->setStatusCode(401)->replyWithError(trans('api.projects_users_not_connected'));
        }

        $playlist = Playlist::with('items')
            ->with('user')
            ->where('user_id', $user->id)
            ->where('id', $playlist_id)->first();

        if (!$playlist) {
            return $this->setStatusCode(404)->replyWithError('Playlist Not Found');
        }

        $update_values = [];

        $name = checkParam('name');
        if ($name) {
            $update_values['name'] = $name;
        }

        $external_content = checkParam('external_content');
        if ($external_content) {
            $update_values['external_content'] = $external_content;
        }

        $playlist->update($update_values);

        $items = checkParam('items');
        $delete_items = checkBoolean('delete_items');

        if ($items || $delete_items) {
            $items_ids = [];
            if (!$delete_items) {
                $items_ids = explode(',', $items);
                PlaylistItems::setNewOrder($items_ids);
            }
            $deleted_items = PlaylistItems::whereNotIn('id', $items_ids)->where('playlist_id', $playlist->id);
            $deleted_items->delete();
        }

        $playlist = $this->getPlaylist($user, $playlist_id);

        return $this->reply($playlist);
    }

    /**
     * Remove the specified playlist.
     *
     *  @OA\Delete(
     *     path="/playlists/{playlist_id}",
     *     tags={"Playlists"},
     *     summary="Delete a playlist",
     *     operationId="v4_internal_playlists.destroy",
     *     security={{"api_token":{}}},
     *     @OA\Parameter(name="playlist_id", in="path", required=true, @OA\Schema(ref="#/components/schemas/Playlist/properties/id")),
     *     @OA\Response(
     *         response=200,
     *         description="successful operation",
     *         @OA\MediaType(mediaType="application/json", @OA\Schema(type="string"))
     *     )
     * )
     *
     * @param  int $playlist_id
     *
     * @return array|\Illuminate\Http\Response
     */
    public function destroy(Request $request, $playlist_id)
    {
        // Validate Project / User Connection
        $user = $request->user();
        $user_is_member = $this->compareProjects($user->id, $this->key);

        if (!$user_is_member) {
            return $this->setStatusCode(401)->replyWithError(trans('api.projects_users_not_connected'));
        }

        $playlist = Playlist::where('user_id', $user->id)->where('id', $playlist_id)->first();

        if (!$playlist) {
            return $this->setStatusCode(404)->replyWithError('Playlist Not Found');
        }

        $playlist->delete();

        return $this->reply('Playlist Deleted');
    }

    /**
     * Follow the specified playlist.
     *
     *  @OA\Post(
     *     path="/playlists/{playlist_id}/follow",
     *     tags={"Playlists"},
     *     summary="Follow a playlist",
     *     operationId="v4_internal_playlists.start",
     *     security={{"api_token":{}}},
     *     @OA\Parameter(name="playlist_id", in="path", required=true, @OA\Schema(ref="#/components/schemas/Playlist/properties/id")),
     *     @OA\Parameter(name="follow", in="query", @OA\Schema(type="boolean")),
     *     @OA\Response(response=200, ref="#/components/responses/playlist")
     * )
     *
     * @param  int $playlist_id
     *
     * @return array|\Illuminate\Http\Response
     */
    public function follow(Request $request, $playlist_id)
    {
        // Validate Project / User Connection
        $user = $request->user();
        $user_is_member = $this->compareProjects($user->id, $this->key);

        if (!$user_is_member) {
            return $this->setStatusCode(401)->replyWithError(trans('api.projects_users_not_connected'));
        }

        $playlist = Playlist::where('id', $playlist_id)->first();

        if (!$playlist) {
            return $this->setStatusCode(404)->replyWithError('Playlist Not Found');
        }

        $follow = checkBoolean('follow');


        if ($follow) {
            $follower = PlaylistFollower::firstOrNew([
                'user_id'               => $user->id,
                'playlist_id'               => $playlist->id
            ]);
            $follower->save();
        } else {
            $follower = PlaylistFollower::where('playlist_id', $playlist->id)
                ->where('user_id', $user->id);
            $follower->delete();
        }

        $playlist = $this->getPlaylist($user, $playlist_id);
        return $this->reply($playlist);
    }

    /**
     * Store a newly created playlist item.
     *
     *  @OA\Post(
     *     path="/playlists/{playlist_id}/item",
     *     tags={"Playlists"},
     *     summary="Crete a playlist item",
     *     operationId="v4_internal_playlists_items.store",
     *     security={{"api_token":{}}},
     *     @OA\Parameter(name="playlist_id", in="path", required=true, @OA\Schema(ref="#/components/schemas/Playlist/properties/id")),
     *     @OA\RequestBody(ref="#/components/requestBodies/PlaylistItems"),
     *     @OA\Response(
     *         response=200,
     *         description="successful operation",
     *         @OA\MediaType(mediaType="application/json", @OA\Schema(ref="#/components/schemas/v4_playlist_items"))
     *     )
     * )
     *
     * @OA\RequestBody(
     *     request="PlaylistItems",
     *     required=true,
     *     description="Fields for Playlist item creation",
     *     @OA\JsonContent(
     *         type="array",
     *         @OA\Items(
     *              @OA\Property(property="fileset_id", ref="#/components/schemas/PlaylistItems/properties/fileset_id"),
     *              @OA\Property(property="book_id", ref="#/components/schemas/PlaylistItems/properties/book_id"),
     *              @OA\Property(property="chapter_start", ref="#/components/schemas/PlaylistItems/properties/chapter_start"),
     *              @OA\Property(property="chapter_end", ref="#/components/schemas/PlaylistItems/properties/chapter_end"),
     *              @OA\Property(property="verse_start", ref="#/components/schemas/PlaylistItems/properties/verse_start"),
     *              @OA\Property(property="verse_end", ref="#/components/schemas/PlaylistItems/properties/verse_end")
     *         )
     *     )
     * )
     *
     * @OA\Schema (
     *   type="array",
     *   schema="v4_playlist_items",
     *   title="User created playlist items",
     *   description="The v4 playlist items creation response.",
     *   @OA\Items(ref="#/components/schemas/PlaylistItemDetail")
     * )
     * @return mixed
     */
    public function storeItem(Request $request, $playlist_id)
    {
        $user = $request->user();
        $user_is_member = $this->compareProjects($user->id, $this->key);

        if (!$user_is_member) {
            return $this->setStatusCode(401)->replyWithError(trans('api.projects_users_not_connected'));
        }

        $playlist = Playlist::with('items')
            ->with('user')
            ->where('user_id', $user->id)
            ->where('id', $playlist_id)->first();

        if (!$playlist) {
            return $this->setStatusCode(404)->replyWithError('Playlist Not Found');
        }

        $playlist_items = json_decode($request->getContent());
        $single_item = checkParam('fileset_id');

        if ($single_item) {
            $playlist_items = [$playlist_items];
        }
        $created_playlist_items = $this->createPlaylistItems($playlist, $playlist_items);

        return $this->reply($single_item ? $created_playlist_items[0] : $created_playlist_items);
    }

    private function createPlaylistItems($playlist, $playlist_items)
    {
        $created_playlist_items = [];

        $current_items_size = sizeof($playlist->items);
        $new_items_size = sizeof($playlist_items);

        if ($current_items_size + $new_items_size > $this->items_limit) {
            $allowed_size = $this->items_limit - $current_items_size;
            $playlist_items = array_slice($playlist_items, 0, $allowed_size);
        }

        foreach ($playlist_items as $playlist_item) {
            $verses = $playlist_items->verses ?? 0;
            $playlist_item = (object) $playlist_item;
            $created_playlist_item = PlaylistItems::create([
                'playlist_id'       => $playlist->id,
                'fileset_id'        => $playlist_item->fileset_id,
                'book_id'           => $playlist_item->book_id,
                'chapter_start'     => $playlist_item->chapter_start,
                'chapter_end'       => $playlist_item->chapter_end,
                'verse_start'       => $playlist_item->verse_start ?? null,
                'verse_end'         => $playlist_item->verse_end ?? null,
                'verses'            => $verses
            ]);
            $created_playlist_item->calculateDuration()->save();
            if (!$verses) {
                $created_playlist_item->calculateVerses()->save();
            }
            $created_playlist_items[] = $created_playlist_item;
        }

        return $created_playlist_items;
    }

    // now used by SyncCollectionsPlaylist command
    public function createTranslatedPlaylistItems($playlist, $playlist_items)
    {
        $playlist_items_to_create = [];
        $order = 1;
        foreach ($playlist_items as $playlist_item) {
            $playlist_item = (object) $playlist_item;
            $playlist_item_data = [
                'playlist_id'       => $playlist->id,
                'fileset_id'        => $playlist_item->fileset_id,
                'book_id'           => $playlist_item->book_id,
                'chapter_start'     => $playlist_item->chapter_start,
                'chapter_end'       => $playlist_item->chapter_end,
                'verse_start'       => $playlist_item->verse_start ?? null,
                'verse_end'         => $playlist_item->verse_end ?? null,
                'verses'            => $playlist_items->verses ?? 0,
                'order_column'      => $order
            ];
            $playlist_items_to_create[] = $playlist_item_data;
            $order += 1;
        }

        PlaylistItems::insert($playlist_items_to_create);
        $new_items = PlaylistItems::where('playlist_id', $playlist->id)->orderBy('order_column')->get();
        $created_playlist_items = [];
        foreach ($new_items as $key => $playlist_item) {
            $playlist_item->translated_id = $playlist_items[$key]->translated_id;

            $created_playlist_items[] = $playlist_item;
        }

        return $created_playlist_items;
    }

    /**
     * Complete a playlist item.
     *
     *  @OA\Post(
     *     path="/playlists/item/{item_id}/complete",
     *     tags={"Playlists"},
     *     summary="Complete a playlist item",
     *     operationId="v4_internal_playlists_items.complete",
     *     security={{"api_token":{}}},
     *     @OA\Parameter(name="item_id", in="path", required=true, @OA\Schema(ref="#/components/schemas/PlaylistItems/properties/id")),
     *     @OA\Parameter(name="complete", in="query", @OA\Schema(type="boolean")),
     *     @OA\Response(
     *         response=200,
     *         description="successful operation",
     *         @OA\MediaType(mediaType="application/json", @OA\Schema(ref="#/components/schemas/v4_complete_playlist_item"))
     *     )
     * )
     *
     * @OA\Schema (
     *   schema="v4_complete_playlist_item",
     *   description="The v4 plan day complete response",
     *   @OA\Property(property="message", type="string"),
     *   @OA\Property(property="percentage_completed", ref="#/components/schemas/UserPlan/properties/percentage_completed")
     * )
     * @return mixed
     */
    public function completeItem(Request $request, $item_id)
    {
        // Validate Project / User Connection
        $user = $request->user();
        $user_is_member = $this->compareProjects($user->id, $this->key);

        if (!$user_is_member) {
            return $this->setStatusCode(401)->replyWithError(trans('api.projects_users_not_connected'));
        }

        $playlist_item = PlaylistItems::where('id', $item_id)->first();

        if (!$playlist_item) {
            return $this->setStatusCode(404)->replyWithError('Playlist Item Not Found');
        }

        $user_plan = UserPlan::join('plans', function ($join) use ($user) {
            $join->on('user_plans.plan_id', '=', 'plans.id')->where('user_plans.user_id', $user->id);
        })
            ->join('plan_days', function ($join) use ($playlist_item) {
                $join->on('plan_days.plan_id', '=', 'plans.id')->where('plan_days.playlist_id', $playlist_item->playlist_id);
            })
            ->select('user_plans.*')
            ->first();

        if (!$user_plan) {
            return $this->setStatusCode(404)->replyWithError('User Plan Not Found');
        }

        $complete = checkParam('complete') ?? true;
        $complete = $complete && $complete !== 'false';

        if ($complete) {
            $playlist_item->complete();
        } else {
            $playlist_item->unComplete();
        }

        $result = $complete ? 'completed' : 'not completed';
        $user_plan->calculatePercentageCompleted()->save();

        return $this->reply([
            'percentage_completed' => $user_plan->percentage_completed,
            'message' => 'Playlist Item ' . $result
        ]);
    }

    // used by SyncCollectionsPlaylist command
    public function translate_items($bible_id, $playlist_items)
    {
        $audio_fileset_types = collect(['audio_stream', 'audio_drama_stream', 'audio', 'audio_drama']);

        // handle content pulls
        $config = config('services.content');
        if (empty($config['url'])) {
            // Local content, bible check
            $bible = Bible::whereId($bible_id)->first();
            if (!$bible) {
                return -1;
                //return $this->setStatusCode(404)->replyWithError('Bible Not Found');
            }

            $bible_language = $bible->language->name;
            $bible_audio_filesets = $bible->filesets->whereIn('set_type_code', $audio_fileset_types);
        } else {
            // Remote content, combined bible/audio check
            $bible_data = cacheRemember('bible_audio_filesets', [$bible_id], now()->addDay(), function () use ($bible_id, $config) {
                $client = new Client();
                $res = $client->get($config['url'] . 'bibles/' . $bible_id .
                  '/audio?v=4&key=' . $config['key']);
                return json_decode($res->getBody() . '');
            });
            // FIXME: handle Bible does not exist
            $bible_language = $bible_data->language;
            // convert to a collection
            $bible_audio_filesets = collect($bible_data->audio);
        }

        $translated_items = [];
        $metadata_items = [];
        $total_translated_items = 0;
        foreach ($playlist_items as $item) {
            // item->fileset is unset, so we're using item->set_type_code instead of item->fileset->set_type_code
            $ordered_types = $audio_fileset_types->filter(function ($type) use ($item) {
                return $type !== $item->set_type_code;
            })->prepend($item->set_type_code);
            $preferred_fileset = $ordered_types->map(function ($type) use ($bible_audio_filesets, $item) {
                return $this->getFileset($bible_audio_filesets, $type, $item->set_size_code);
            })->firstWhere('id');
            $has_translation = isset($preferred_fileset);
            $is_streaming = true;

            if ($has_translation) {
                $item->fileset_id = $preferred_fileset->id;
                $is_streaming = $preferred_fileset->set_type_code === 'audio_stream' || $preferred_fileset->set_type_code === 'audio_drama_stream';
                $translated_items[] = (object)[
                    'translated_id' => $item->id,
                    'fileset_id' => $item->fileset_id,
                    'book_id' => $item->book_id,
                    'chapter_start' => $item->chapter_start,
                    'chapter_end' => $item->chapter_end,
                    'verse_start' => $is_streaming ? $item->verse_start : null,
                    'verse_end' => $is_streaming ? $item->verse_end : null,
                ];
                $total_translated_items += 1;
            }
            $metadata_items[] = $item;
        }
        $translated_percentage = sizeof($playlist_items) ? $total_translated_items / sizeof($playlist_items) : 0;

        return (object)[
          'bible_language'        => $bible_language,
          'translated_items'      => $translated_items,
          'metadata_items'        => $metadata_items,
          'translated_percentage' => $translated_percentage, // or total_translated_items
        ];
    }

    /**
     *
     * @OA\Get(
     *     path="/playlists/{playlist_id}/translate",
     *     tags={"Playlists"},
     *     summary="Translate a user's playlist",
     *     operationId="v4_internal_playlists.translate",
     *     security={{"api_token":{}}},
     *     @OA\Parameter(
     *          name="playlist_id",
     *          in="path",
     *          required=true,
     *          @OA\Schema(ref="#/components/schemas/Playlist/properties/id"),
     *          description="The playlist id"
     *     ),
     *     @OA\Parameter(
     *          name="bible_id",
     *          in="query",
     *          required=true,
     *          @OA\Schema(ref="#/components/schemas/Bible/properties/id"),
     *          description="The id of the bible that will be used to translate the playlist"
     *     ),
     *     @OA\Parameter(
     *          name="show_details",
     *          in="query",
     *          @OA\Schema(type="boolean"),
     *          description="Give full details of the playlist"
     *     ),
     *     @OA\Response(response=200, ref="#/components/responses/playlist")
     * )
     *
     * @param $playlist_id
     *
     * @return mixed
     *
     *
     */
    public function translate(Request $request, $playlist_id, $user = false, $compare_projects = true, $plan_id = 0)
    {
        $user = $user ? $user : $request->user();

        // Validate Project / User Connection
        if ($compare_projects && !empty($user) && !$this->compareProjects($user->id, $this->key)) {
            return $this->setStatusCode(401)->replyWithError(trans('api.projects_users_not_connected'));
        }

        $show_details = checkBoolean('show_details');
        $bible_id = checkParam('bible_id', true);
        $audio_fileset_types = collect(['audio_stream', 'audio_drama_stream', 'audio', 'audio_drama']);

        $config = config('services.content');
        if (empty($config['url'])) {
            // Local content, bible check
            $bible = cacheRemember('bible_translate', [$bible_id], now()->addDay(), function () use ($bible_id) {
                return Bible::whereId($bible_id)->first();
            });

            if (!$bible) {
                return $this->setStatusCode(404)->replyWithError('Bible Not Found');
            }

            $bible_language = $bible->language->name;
        } else {
            // Remote content, combined bible/audio check

            $bible_data = cacheRemember('bible_get_audio', [$bible_id], now()->addDay(), function () use ($bible_id, $config) {
                $client = new Client();
                $res = $client->get($config['url'] . 'bibles/' . $bible_id .
                  '?v=4&key=' . $config['key']);
                $result = json_decode($res->getBody() . '', true);
                //echo "<pre>", print_r($result['data'], 1), "</pre>\n";
                $filesets = collect($result['data']['filesets']['dbp-prod']);
                $bible_audio_filesets = $filesets->filter(function($fileset) {
                    // keep it if it has audio in the name...
                    return strpos($fileset['type'], 'audio') !== false;
                })->map(function($fileset) {
                  return (object)array(
                    'id'            => $fileset['id'],
                    'set_type_code' => $fileset['type'],
                    'set_size_code' => $fileset['size'],
                  );
                });
                return array('audiofs'=>$bible_audio_filesets, 'lang'=>$result['data']['language']);
            });
            $bible_audio_filesets = $bible_data['audiofs'];
            $bible_language = $bible_data['lang'];
        }

        // get non user tied playlist
        $playlist = $this->getPlaylist(false, $playlist_id);
        if (!$playlist) {
            return $this->setStatusCode(404)->replyWithError('Playlist Not Found');
        }
        $result = $this->translate_items($bible_id, $playlist->items);
        if (!is_object($result)) {
            return $this->setStatusCode(404)->replyWithError('Bible Not Found');
        }
        $bible_language        = $result->bible_language;
        $translated_items      = $result->translated_items;
        $metadata_items        = $result->$metadata_items;
        $translated_percentage = $result->translated_percentage;

        // create new playlist
        $playlist_data = [
            'user_id'           => $user->id,
            'name'              => $playlist->name . ': ' . $bible_language . ' ' . substr($bible_id, -3),
            'external_content'  => $playlist->external_content,
            'featured'          => false,
            'draft'             => true,
            'plan_id'           => $plan_id
        ];

        // stomp $playlist with target
        $playlist = Playlist::create($playlist_data);

        $items = collect($this->createTranslatedPlaylistItems($playlist, $translated_items));

        foreach ($metadata_items as $item) {
            $new_item = $items->first(function ($new_item) use ($item) {
                return $new_item->translated_id === $item->id;
            });
            if ($new_item) {
                unset($new_item->translated_id);
                $item->translation_item = $new_item;
            }
        }

        $playlist = $this->getPlaylist($user, $playlist->id);
        $playlist->path = route('v4_internal_playlists.hls', ['playlist_id'  => $playlist->id, 'v' => $this->v, 'key' => $this->key]);
        $playlist->total_duration = PlaylistItems::where('playlist_id', $playlist->id)->sum('duration');

        if ($show_details) {
            $playlist_text_filesets = $this->getPlaylistTextFilesets($playlist->id);
            foreach ($playlist->items as $item) {
                $item->verse_text = $item->getVerseText($playlist_text_filesets);
                $item->item_timestamps = $item->getTimestamps();
            }
        }

        $playlist->translation_data = $metadata_items;
        $playlist->translated_percentage = $translated_percentage * 100;

        return $this->reply($playlist);
    }

    /**
     *  @OA\Post(
     *     path="/playlists/{playlist_id}/draft",
     *     tags={"Playlists"},
     *     summary="Change draft status in a playlist.",
     *     operationId="v4_internal_playlists.draft",
     *     security={{"api_token":{}}},
     *     @OA\Parameter(name="playlist_id", in="path", required=true, @OA\Schema(ref="#/components/schemas/Playlist/properties/id")),
     *     @OA\Parameter(name="draft", in="query", @OA\Schema(type="boolean")),
     *     @OA\Response(
     *         response=200,
     *         description="successful operation",
     *         @OA\MediaType(mediaType="application/json", @OA\Schema(type="string"))
     *     )
     * )
     */
    public function draft(Request $request, $playlist_id)
    {
        // Validate Project / User Connection
        $user = $request->user();
        $user_is_member = $this->compareProjects($user->id, $this->key);

        if (!$user_is_member) {
            return $this->setStatusCode(401)->replyWithError(trans('api.projects_users_not_connected'));
        }

        $playlist = Playlist::where('user_id', $user->id)->where('id', $playlist_id)->first();

        if (!$playlist) {
            return $this->setStatusCode(404)->replyWithError('Playlist Not Found');
        }

        $draft = checkBoolean('draft');
        $playlist->draft = $draft;

        $playlist->save();

        return $this->reply('Playlist draft status changed');
    }

    private function getFileset($filesets, $type, $size)
    {
        $available_filesets = [];

        $complete_fileset = $filesets->where('set_type_code', $type)->where('set_size_code', 'C')->first();
        if ($complete_fileset) {
            $available_filesets[] = $complete_fileset;
        }

        $size_filesets = $filesets->where('set_type_code', $type)->where('set_size_code', $size)->first();
        if ($size_filesets) {
            $available_filesets[] = $size_filesets;
        }

        $size__partial_filesets = $filesets->filter(function ($item) use ($type, $size) {
            return $item->set_type_code === $type && strpos($item->set_size_code, $size . 'P') !== false;
        })->first();
        if ($size__partial_filesets) {
            $available_filesets[] = $size__partial_filesets;
        }

        $partial_fileset = $filesets->where('set_type_code', $type)->where('set_size_code', 'P')->first();
        if ($partial_fileset) {
            $available_filesets[] = $partial_fileset;
        }

        if (!empty($available_filesets)) {
            $available_filesets =
                collect($available_filesets)->sortBy(function ($item) {
                    return  strpos($item->id, '16');
                });

            return $available_filesets->first();
        }

        return false;
    }

    public function itemHls(Response $response, $playlist_item_id)
    {
        $download = checkBoolean('download');
        $playlist_item = PlaylistItems::whereId($playlist_item_id)->first();
        if (!$playlist_item) {
            return $this->setStatusCode(404)->replyWithError('Playlist Item Not Found');
        }

        $hls_playlist = $this->getHlsPlaylist($response, [$playlist_item], $download);

        if ($download) {
            return $this->reply(['hls' => $hls_playlist['file_content'], 'signed_files' => $hls_playlist['signed_files']]);
        }

        return response($hls_playlist['file_content'], 200, [
            'Content-Disposition' => 'attachment; filename="item_' . $playlist_item_id . '.m3u8"',
            'Content-Type'        => 'application/x-mpegURL'
        ]);
    }

    public function hls(Response $response, $playlist_id)
    {
        $download = checkBoolean('download');
        $playlist = Playlist::with('items')->find($playlist_id);
        if (!$playlist) {
            return $this->setStatusCode(404)->replyWithError('Playlist Not Found');
        }

        $hls_playlist = $this->getHlsPlaylist($response, $playlist->items, $download);

        if ($download) {
            return $this->reply(['hls' => $hls_playlist['file_content'], 'signed_files' => $hls_playlist['signed_files']]);
        }

        return response($hls_playlist['file_content'], 200, [
            'Content-Disposition' => 'attachment; filename="' . $playlist_id . '.m3u8"',
            'Content-Type'        => 'application/x-mpegURL'
        ]);
    }

    private function processHLSAudio($bible_files, $signed_files, $transaction_id, $item, $download)
    {
        $durations = [];
        $hls_items = '';
        foreach ($bible_files as $bible_file) {
            $currentBandwidth = $bible_file->streamBandwidth->first();

            $transportStream = sizeof($currentBandwidth->transportStreamBytes) ? $currentBandwidth->transportStreamBytes : $currentBandwidth->transportStreamTS;
            if ($item->verse_end && $item->verse_start) {
                $transportStream = $this->processVersesOnTransportStream($item, $transportStream, $bible_file);
            }

            $fileset = $bible_file->fileset;

            foreach ($transportStream as $stream) {
                $durations[] = $stream->runtime;
                $hls_items .= "\n#EXTINF:$stream->runtime," . $item->id;
                if (isset($stream->timestamp)) {
                    $hls_items .= "\n#EXT-X-BYTERANGE:$stream->bytes@$stream->offset";
                    $fileset = $stream->timestamp->bibleFile->fileset;
                    $stream->file_name = $stream->timestamp->bibleFile->file_name;
                }
                $bible_path = $bible_file->fileset->bible->first()->id;
                $file_path = 'audio/' . $bible_path . '/' . $fileset->id . '/' . $stream->file_name;
                if (!isset($signed_files[$file_path])) {
                    $signed_files[$file_path] = $this->signedUrl($file_path, $fileset->asset_id, $transaction_id);
                }
                $hls_file_path = $download ? $file_path : $signed_files[$file_path];
                $hls_items .= "\n" . $hls_file_path;
            }
        }

        return (object) ['hls_items' => $hls_items, 'signed_files' => $signed_files, 'durations' => $durations];
    }

    private function processMp3Audio($bible_files, $signed_files, $transaction_id, $download, $item)
    {
        $durations = [];
        $hls_items = '';
        foreach ($bible_files as $bible_file) {
            $default_duration = $bible_file->duration ?? 180;
            $durations[] = $default_duration;
            $hls_items .= "\n#EXTINF:$default_duration," . $item->id;

            $bible_path = $bible_file->fileset->bible->first()->id;
            $file_path = 'audio/' . $bible_path . '/' . $bible_file->fileset->id . '/' . $bible_file->file_name;
            $hls_items .= "\n";
            if (!isset($signed_files[$file_path])) {
                $signed_files[$file_path] = $this->signedUrl($file_path, $bible_file->fileset->asset_id, $transaction_id);
            }
            $hls_file_path = $download ? $file_path : $signed_files[$file_path];
            $hls_items .= "\n" . $hls_file_path;
        }

        return (object) ['hls_items' => $hls_items, 'signed_files' => $signed_files, 'durations' => $durations];
    }

    private function processVersesOnTransportStream($item, $transportStream, $bible_file)
    {
        // if request single chatper
        if ($item->chapter_end  === $item->chapter_start) {
            // put from beginning to verse_end into transportStream
            $transportStream = $transportStream->splice(1, $item->verse_end)->all();
            // return from verse_start to verse_end
            return collect($transportStream)->slice($item->verse_start - 1)->all();
        }

        // shift first item
        $transportStream = $transportStream->splice(1)->all();

        // if requested starting point
        if ($bible_file->chapter_start === $item->chapter_start) {
            // skip verse_start and return
            return collect($transportStream)->slice($item->verse_start - 1)->all();
        }
        // if requested ending point at this start
        if ($bible_file->chapter_start === $item->chapter_end) {
            // remove items after verse_end
            return collect($transportStream)->splice(0, $item->verse_end)->all();
        }

        // return all but first item...
        return $transportStream;
    }

    private function getHlsPlaylist($response, $items, $download)
    {
        $signed_files = [];
        $transaction_id = random_int(0, 10000000);
        try {
            apiLogs(request(), $response->getStatusCode(), $transaction_id);
        } catch (\Exception $e) {
            Log::error($e);
        }
        $durations = [];
        $hls_items = [];
        $content_config = config('services.content');
        if (!empty($content_config['url'])) {
            $client = new Client();
            $biblefileset_controller = new BibleFileSetsController;
        }
        foreach ($items as $item) {
            if (empty($content_config['url'])) {
                // Local content
                $fileset = $item->fileset;
                if (!Str::contains($fileset->set_type_code, 'audio')) {
                    continue;
                }
                $bible_files = BibleFile::with('streamBandwidth.transportStreamTS')->with('streamBandwidth.transportStreamBytes')->where([
                    'hash_id' => $fileset->hash_id,
                    'book_id' => $item->book_id,
                ])
                    ->where('chapter_start', '>=', $item->chapter_start)
                    ->where('chapter_start', '<=', $item->chapter_end)
                    ->get();
                if ($fileset->set_type_code === 'audio_stream' || $fileset->set_type_code === 'audio_drama_stream') {
                    $result = $this->processHLSAudio($bible_files, $signed_files, $transaction_id, $item, $download);
                    $hls_items[] = $result->hls_items;
                    $signed_files = $result->signed_files;
                    $durations[] = collect($result->durations)->sum();
                } else {
                    $result = $this->processMp3Audio($bible_files, $signed_files, $transaction_id, $download, $item);
                    $hls_items[] = $result->hls_items;
                    $signed_files = $result->signed_files;
                    $durations[] = collect($result->durations)->sum();
                }
            } else {
                // Remote content
                $fileset_id = $item->fileset_id;

                // existence and get set_type_code
                $result = cacheRemember('playlist_item_fileset_audio',
                  [$fileset_id], now()->addDay(),
                  function () use ($fileset_id, $client, $content_config) {
                    $res = $client->get($content_config['url'] . 'bibles/filesets/'.
                      $fileset_id.'/audio?v=4&key=' . $content_config['key']);
                    $result = json_decode($res->getBody() . '', true);
                    return $result;
                });

                // fileset doesn't exist
                if (!count($result)) {
                    // skip it
                    continue;
                }

                // make sure fileset is audio-ish
                if (!Str::contains($result[0]['set_type_code'], 'audio')) {
                    continue;
                }

                // we could batch by (fileset_id, book_id) but hard to pass
                //   an array like this...
                $book_id = $item->book_id;
                $result = cacheRemember('playlist_item_fileset_audio',
                  [$fileset_id, $book_id], now()->addDay(),
                  function () use ($fileset_id, $book_id, $client, $content_config) {
                    $res = $client->get($content_config['url'] . 'bibles/filesets/'.
                      $fileset_id.'/stream/'.$book_id.'?v=4&key=' .
                      $content_config['key']);
                    $result = json_decode($res->getBody() . '', true);
                    return $result;
                });
                // enabling this will stop file download, and let you view in a browser
                //echo str_repeat('       ', 2048), "\n";

                // process playlist item joins
                $filesets = collect($result)->filter(function ($hls_item) use ($item) {
                    // consder only if
                    // chapter_start >= item->chapter_start AND
                    // chapter_start <= item->chapter_end
                    if ($hls_item['chapter_start'] >= $item->chapter_start) return true;
                    if ($hls_item['chapter_start'] <= $item->chapter_end)   return true;
                    return false;
                });

                // hls data structure translation
                // add to signed_files and hls_items as needed
                $biblefileset_controller->getHLSPlaylistText(
                  $filesets->toArray(), $signed_files, $hls_items, $durations, $transaction_id,
                  $item, $download
                );
            }
        }
        $hls_items = join("\n" . '#EXT-X-DISCONTINUITY', $hls_items);
        $current_file = "#EXTM3U\n";
        $current_file .= '#EXT-X-TARGETDURATION:' . ceil(collect($durations)->sum()) . "\n";
        $current_file .= "#EXT-X-VERSION:4\n";
        $current_file .= '#EXT-X-MEDIA-SEQUENCE:0';
        $current_file .= $hls_items;
        $current_file .= "\n#EXT-X-ENDLIST";

        return ['signed_files' => $signed_files, 'file_content' => $current_file];
    }

    /**
     * @OA\Schema (
     *   type="object",
     *   schema="PlaylistItemDetail",
     *   @OA\Property(property="id", ref="#/components/schemas/PlaylistItems/properties/id"),
     *   @OA\Property(property="bible_id", ref="#/components/schemas/Bible/properties/id"),
     *   @OA\Property(property="fileset_id", ref="#/components/schemas/PlaylistItems/properties/fileset_id"),
     *   @OA\Property(property="book_id", ref="#/components/schemas/PlaylistItems/properties/book_id"),
     *   @OA\Property(property="chapter_start", ref="#/components/schemas/PlaylistItems/properties/chapter_start"),
     *   @OA\Property(property="chapter_end", ref="#/components/schemas/PlaylistItems/properties/chapter_end"),
     *   @OA\Property(property="verse_start", ref="#/components/schemas/PlaylistItems/properties/verse_start"),
     *   @OA\Property(property="verse_end", ref="#/components/schemas/PlaylistItems/properties/verse_end"),
     *   @OA\Property(property="duration", ref="#/components/schemas/PlaylistItems/properties/duration"),
     *   @OA\Property(property="completed", ref="#/components/schemas/PlaylistItems/properties/completed")
     * )
     * @OA\Schema (
     *   type="object",
     *   schema="v4_playlist",
     *   @OA\Property(property="id", ref="#/components/schemas/Playlist/properties/id"),
     *   @OA\Property(property="name", ref="#/components/schemas/Playlist/properties/name"),
     *   @OA\Property(property="featured", ref="#/components/schemas/Playlist/properties/featured"),
     *   @OA\Property(property="created_at", ref="#/components/schemas/Playlist/properties/created_at"),
     *   @OA\Property(property="updated_at", ref="#/components/schemas/Playlist/properties/updated_at"),
     *   @OA\Property(property="external_content", ref="#/components/schemas/Playlist/properties/external_content"),
     *   @OA\Property(property="following", ref="#/components/schemas/Playlist/properties/following"),
     *   @OA\Property(property="user", ref="#/components/schemas/v4_playlist_index_user"),
     * )
     *
     * @OA\Schema (
     *   type="object",
     *   schema="v4_playlist_index_user",
     *   description="The user who created the playlist",
     *   @OA\Property(property="id", type="integer"),
     *   @OA\Property(property="name", type="string")
     * )
     *
     * @OA\Schema (
     *   type="object",
     *   schema="v4_playlist_detail",
     *   allOf={
     *      @OA\Schema(ref="#/components/schemas/v4_playlist"),
     *   },
     *   @OA\Property(property="items",type="array",@OA\Items(ref="#/components/schemas/PlaylistItemDetail"))
     * )
     *
     * @OA\Response(
     *   response="playlist",
     *   description="Playlist Object",
     *   @OA\MediaType(mediaType="application/json", @OA\Schema(ref="#/components/schemas/v4_playlist_detail"))
     * )
     */

    public function getPlaylist($user, $playlist_id)
    {
        $select = ['user_playlists.*', DB::Raw('IF(playlists_followers.user_id, true, false) as following')];
        $playlist = Playlist::with('items')
            ->with('user')
            ->leftJoin('playlists_followers as playlists_followers', function ($join) use ($user) {
                $user_id = empty($user) ? 0 : $user->id;
                $join->on('playlists_followers.playlist_id', '=', 'user_playlists.id')->where('playlists_followers.user_id', $user_id);
            })
            ->where('user_playlists.id', $playlist_id)
            ->select($select)
            ->first();

        if (!$playlist) {
            return $this->setStatusCode(404)->replyWithError('No playlist could be found for: ' . $playlist_id);
        }

        $config = config('services.content');
        if (empty($config['url'])) {
            // local content
            $playlist->items = $playlist->items->map(function ($item) {
                $bible = $item->fileset->bible->first();
                if ($bible) {
                    $item->bible_id = $bible->id;
                }
                $item->set_type_code = $item->fileset->set_type_code;
                unset($item->fileset);
                return $item;
            });
        } else {
            // remote content

            // get a unique lists of filesets we need to look up
            $fileset_ids = $playlist->items->map(function ($item) {
                return $item->fileset_id;
            })->unique();

                // could be more granular (by fileset_id)
                $filesets_bibles = [];
                $lookups = [];
                $cache_key = 'bible_filesets_playlist';
                foreach($fileset_ids as $fileset_id) {
                    $cache_string = generateCacheString($cache_key, [$fileset_id]);
                    $fileset = cacheGet($cache_string);
                    if ($fileset) {
                        $filesets_bibles[$fileset_id] = $fileset;
                    } else {
                        $lookups[] = $fileset_id;
                    }
                }
                // run the one content lookup if we even need it
                if (count($lookups)) {
                    // query content server
                    $client = new Client();
                    $res = $client->get($config['url'] . 'bibles/filesets/'.
                      join(',',$fileset_ids->toArray()).'/playlist?v=4&key=' . $config['key']);
                    $filesets_bibles_download = json_decode($res->getBody() . '', true);
                    foreach($filesets_bibles_download as $fileset_id => $fileset) {
                        $filesets_bibles[$fileset_id] = $fileset;
                        $cache_string = generateCacheString($cache_key, [$fileset_id]);
                        cacheAdd($cache_string, $fileset, now()->addDay());
                    }
                }

                // process result
                $playlist->items = $playlist->items->map(
                  function ($item) use ($filesets_bibles) {
                    $res = $filesets_bibles[$item->fileset_id];
                    if ($res && count($res)) {
                        $item->bible_id = $res[0]['bible_id'];
                        // which will be one of ['audio_stream', 'audio_drama_stream', 'audio', 'audio_drama']
                        $item->set_type_code = $res[0]['set_type_code'];
                    } else {
                        echo 'Content server does not have fileset[', $item->fileset_id, "]\n";
                    }
                    unset($item->fileset);
                    return $item;
                });
            } // else no items
        }

        return $playlist;
    }

    public function getPlaylistTextFilesets($playlist_id)
    {
        $filesets = Arr::pluck(DB::connection('dbp_users')
            ->select('select DISTINCT(fileset_id) from playlist_items where playlist_id = ?', [$playlist_id]), 'fileset_id');
        $fileset_text_info = array();

        $config = config('services.content');

        // if configured to use content server
        if (!empty($config['url'])) {
            $client = new Client();
            $res = $client->get($config['url'] . 'bibles/filesets/'.
              join(',',$filesets).'/playlist?v=4&key=' . $config['key']);
            $filesets_hashes = collect(json_decode($res->getBody() . ''));

            $fileset_text_info = array();
            foreach ($filesets as $fileset) {
                // f data
                $fileset_text_info[$fileset] = $filesets_hashes[$fileset] ?? null;
            }
        } else {
            // else use local data

            // lookup filesets and get hashes
            // map id to hash_id
            $filesets_hashes = DB::connection('dbp')
                ->table('bible_filesets')
                ->select(['hash_id', 'id'])
                ->whereIn('id', $filesets)->get();

            // convert fileset hashes into bible_ids
            // map hash_id to bible_id
            $hashes_bibles = DB::connection('dbp')
                ->table('bible_fileset_connections')
                ->select(['hash_id', 'bible_id'])
                ->whereIn('hash_id', $filesets_hashes->pluck('hash_id'))->get();

            // convert bible_ids into text filesets + bible_ids
            // map bible_id to f data
            $text_filesets = DB::connection('dbp')
                ->table('bible_fileset_connections as fc')
                ->join('bible_filesets as f', 'f.hash_id', '=', 'fc.hash_id')
                ->select(['f.*', 'fc.bible_id'])
                ->where('f.set_type_code', 'text_plain')
                ->whereIn('fc.bible_id', $hashes_bibles->pluck('bible_id'))->get()->groupBy('bible_id');

            // create fileset lookup to hash
            $fileset_text_info = $filesets_hashes->pluck('hash_id', 'id');
            // create hash lookup to id
            $bible_hash = $hashes_bibles->pluck('bible_id', 'hash_id');

            // build fileset_text_info from fileset
            foreach ($filesets as $fileset) {
                // need text
                // get bible_id for this fileset
                $bible_id = $bible_hash[$fileset_text_info[$fileset]];
                // fetch text for bible_id or set to null
                $fileset_text_info[$fileset] = $text_filesets[$bible_id] ?? null;
            }
        }
        return $fileset_text_info;
    }
}
