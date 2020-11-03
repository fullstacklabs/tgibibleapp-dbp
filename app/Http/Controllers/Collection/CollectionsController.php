<?php

namespace App\Http\Controllers\Collection;

use App\Traits\AccessControlAPI;
use App\Http\Controllers\APIController;
use App\Models\Collection\Collection;
use App\Models\Collection\CollectionPlaylist;
use App\Http\Controllers\Playlist\PlaylistsController;
use App\Models\Language\Language;
use App\Traits\CheckProjectMembership;
use Illuminate\Http\Request;

class CollectionsController extends APIController
{
    use AccessControlAPI;
    use CheckProjectMembership;
    //

    /**
     * Display a listing of the resource.
     *
     * @OA\Get(
     *     path="/collections",
     *     tags={"Collections"},
     *     summary="List a user's collections",
     *     operationId="v4_collections.index",
     *     @OA\Parameter(
     *          name="featured",
     *          in="query",
     *          @OA\Schema(ref="#/components/schemas/Collection/properties/featured"),
     *          description="Return featured collections"
     *     ),
     *     security={{"api_token":{}}},
     *     @OA\Parameter(
     *          name="iso",
     *          in="query",
     *          @OA\Schema(ref="#/components/schemas/Language/properties/iso"),
     *          description="The iso code to filter collections by. For a complete list see the `iso` field in the `/languages` route"
     *     ),
     *     @OA\Parameter(ref="#/components/parameters/limit"),
     *     @OA\Parameter(ref="#/components/parameters/page"),
     *     @OA\Parameter(ref="#/components/parameters/sort_by"),
     *     @OA\Parameter(ref="#/components/parameters/sort_dir"),
     *     @OA\Response(
     *         response=200,
     *         description="successful operation",
     *         @OA\MediaType(mediaType="application/json", @OA\Schema(ref="#/components/schemas/v4_collection_index")),
     *         @OA\MediaType(mediaType="application/xml",  @OA\Schema(ref="#/components/schemas/v4_collection_index")),
     *         @OA\MediaType(mediaType="text/x-yaml",      @OA\Schema(ref="#/components/schemas/v4_collection_index")),
     *         @OA\MediaType(mediaType="text/csv",      @OA\Schema(ref="#/components/schemas/v4_collection_index"))
     *     )
     * )
     *
     *
     * @return mixed
     *
     *
     * @OA\Schema (
     *   type="object",
     *   schema="v4_collection_index_detail",
     *   allOf={
     *      @OA\Schema(ref="#/components/schemas/v4_collection"),
     *   }
     * )
     *
     * @OA\Schema (
     *   type="object",
     *   schema="v4_collection_index",
     *   description="The v4 collection index response.",
     *   title="User collections",
     *   allOf={
     *      @OA\Schema(ref="#/components/schemas/pagination"),
     *   },
     *   @OA\Property(
     *      property="data",
     *      type="array",
     *      @OA\Items(ref="#/components/schemas/v4_collection_index_detail")
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

        $featured = checkBoolean('featured') || empty($user);
        $limit        = (int) (checkParam('limit') ?? 25);
        $sort_by    = checkParam('sort_by') ?? 'name';
        $sort_dir   = checkParam('sort_dir') ?? 'asc';
        $iso = checkParam('iso');

        $language_id = cacheRemember('v4_language_id_from_iso', [$iso], now()->addDay(), function () use ($iso) {
            return optional(Language::where('iso', $iso)->select('id')->first())->id;
        });

        if ($featured) {
            $cache_params = [$featured, $limit, $sort_by, $sort_dir, $iso];
            $collections = cacheRemember('v4_collection_index', $cache_params, now()->addDay(), function () use ($featured, $limit, $sort_by, $sort_dir, $user, $language_id) {
                return $this->getCollections($featured, $limit, $sort_by, $sort_dir, $user, $language_id);
            });
            return $this->reply($collections);
        }

        return $this->reply($this->getCollections($featured, $limit, $sort_by, $sort_dir, $user, $language_id));
    }

    private function getCollections($featured, $limit, $sort_by, $sort_dir, $user, $language_id)
    {
        $collections = Collection::with('user')
            ->when($language_id, function ($q) use ($language_id) {
                $q->where('collections.language_id', $language_id);
            })
            ->when($featured || empty($user), function ($q) {
                $q->where('collections.featured', '1');
            }) /* ->unless($featured, function ($q) use ($user) {
                $q->join('user_plans', function ($join) use ($user) {
                    $join->on('user_plans.plan_id', '=', 'plans.id')->where('user_plans.user_id', $user->id);
                });
                $q->select(['plans.*', 'user_plans.start_date', 'user_plans.percentage_completed']);
            }) */
            ->orderBy($sort_by, $sort_dir)->paginate($limit);

        /*
        foreach ($collections as $collection) {
            $collection->total_days = sizeof($collection->days);
            unset($collection->days);
        }
        */
        return $collections;
    }

    /**
     * Store a newly created collection in storage.
     *
     *  @OA\Post(
     *     path="/collections",
     *     tags={"Collections"},
     *     summary="Crete a collection",
     *     operationId="v4_collections.store",
     *     security={{"api_token":{}}},
     *     @OA\RequestBody(required=true, description="Fields for User Collection Creation",
     *           @OA\MediaType(mediaType="application/json",
     *              @OA\Schema(
     *                  @OA\Property(property="name", ref="#/components/schemas/Collection/properties/name"),
     *              )
     *          )
     *     ),
     *     @OA\Response(response=200, ref="#/components/responses/collection")
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
        $language_id = checkParam('language_id', true);
        $order_column = checkParam('order_column', true);

        // create a collection
        $collection = Collection::create([
            'user_id'               => $user->id,
            'language_id'           => $language_id,
            'order_column'          => $order_column,
            'name'                  => $name,
            'featured'              => false,
        ]);

        return $this->reply($collection);
    }

    /**
     *
     * @OA\Get(
     *     path="/collections/{collection_id}",
     *     tags={"Collections"},
     *     summary="A user's collection",
     *     operationId="v4_collections.show",
     *     security={{"api_token":{}}},
     *     @OA\Parameter(
     *          name="collection_id",
     *          in="path",
     *          required=true,
     *          @OA\Schema(ref="#/components/schemas/User/properties/id"),
     *          description="The collection id"
     *     ),
     *     @OA\Parameter(
     *          name="show_details",
     *          in="query",
     *          @OA\Schema(type="boolean"),
     *          description="Give full details of the collection"
     *     ),
     *     @OA\Parameter(
     *          name="show_text",
     *          in="query",
     *          @OA\Schema(type="boolean"),
     *          description="Enable the full details of the collection and retrieve the text of the playlists items"
     *     ),
     *     @OA\Response(response=200, ref="#/components/responses/collection")
     * )
     *
     * @param $collection_id
     *
     * @return mixed
     *
     *
     */
    public function show(Request $request, $collection_id)
    {
        $user = $request->user();

        // Validate Project / User Connection
        if (!empty($user) && !$this->compareProjects($user->id, $this->key)) {
            return $this->setStatusCode(401)->replyWithError(trans('api.projects_users_not_connected'));
        }

        $collection = $this->getCollection($collection_id, $user);

        if (!$collection) {
            return $this->setStatusCode(404)->replyWithError('Collection Not Found');
        }

        $show_details = checkBoolean('show_details');
        $show_text = checkBoolean('show_text');
        if ($show_text) {
            $show_details = $show_text;
        }

        $playlist_controller = new PlaylistsController();
        if ($show_details) {
            // days?
            /*
            foreach ($collection->days as $day) {
                $day_playlist = $playlist_controller->getPlaylist($user, $day->playlist_id);
                $day_playlist->path = route('v4_playlists.hls', ['playlist_id'  => $day_playlist->id, 'v' => $this->v, 'key' => $this->key]);
                if ($show_text) {
                    foreach ($day_playlist->items as $item) {
                        $item->verse_text = $item->getVerseText();
                    }
                }
                $day->playlist = $day_playlist;
            }
            */
        }

        return $this->reply($collection);
    }

    /**
     * Update the specified collection.
     *
     *  @OA\Put(
     *     path="/collections/{collection_id}",
     *     tags={"Collections"},
     *     summary="Update a collection",
     *     operationId="v4_collections.update",
     *     security={{"api_token":{}}},
     *     @OA\Parameter(name="collection_id", in="path", required=true, @OA\Schema(ref="#/components/schemas/Collection/properties/id")),
     *     @OA\RequestBody(required=true, @OA\MediaType(mediaType="application/json",
     *          @OA\Schema(
     *              @OA\Property(property="name", ref="#/components/schemas/Collection/properties/name"),
     *          )
     *     )),
     *     @OA\Response(response=200, ref="#/components/responses/collection")
     * )
     *
     * @param  int $collection_id
     * @param  string $days
     *
     * @return array|\Illuminate\Http\Response
     */
    public function update(Request $request, $collection_id)
    {
        // Validate Project / User Connection
        $user = $request->user();
        $user_is_member = $this->compareProjects($user->id, $this->key);

        if (!$user_is_member) {
            return $this->setStatusCode(401)->replyWithError(trans('api.projects_users_not_connected'));
        }

        $collection = Collection::where('user_id', $user->id)->where('id', $collection_id)->first();

        if (!$collection) {
            return $this->setStatusCode(404)->replyWithError('Collection Not Found');
        }

        $update_values = [];

        $name = checkParam('name');
        if ($name) {
            $update_values['name'] = $name;
        }

        $suggested_start_date = checkParam('suggested_start_date');
        if ($suggested_start_date) {
            $update_values['suggested_start_date'] = $suggested_start_date;
        }

        $collection->update($update_values);

        /*
        $days = checkParam('days');
        $delete_days = checkBoolean('delete_days');

        if ($days || $delete_days) {
            $days_ids = [];
            if (!$delete_days) {
                $days_ids = explode(',', $days);
                PlanDay::setNewOrder($days_ids);
            }
            $deleted_days = PlanDay::whereNotIn('id', $days_ids)
               ->where('plan_id', $collection->id);
            $playlists_ids = $deleted_days->pluck('playlist_id')->unique();
            $playlists = Playlist::whereIn('id', $playlists_ids);
            $deleted_days->delete();
            $playlists->delete();
        }
        */

        $collection = $this->getCollection($collection->id, $user);

        return $this->reply($collection);
    }

    /**
     * Remove the specified collection.
     *
     *  @OA\Delete(
     *     path="/collections/{collection_id}",
     *     tags={"Collections"},
     *     summary="Delete a collection",
     *     operationId="v4_collections.destroy",
     *     security={{"api_token":{}}},
     *     @OA\Parameter(name="collection_id", in="path", required=true, @OA\Schema(ref="#/components/schemas/Collection/properties/id")),
     *     @OA\Response(
     *         response=200,
     *         description="successful operation",
     *         @OA\MediaType(mediaType="application/json", @OA\Schema(type="string")),
     *         @OA\MediaType(mediaType="application/xml",  @OA\Schema(type="string")),
     *         @OA\MediaType(mediaType="text/x-yaml",      @OA\Schema(type="string")),
     *         @OA\MediaType(mediaType="text/csv",      @OA\Schema(type="string"))
     *     )
     * )
     *
     * @param  int $collection_id
     *
     * @return array|\Illuminate\Http\Response
     */
    public function destroy(Request $request, $collection_id)
    {
        // Validate Project / User Connection
        $user = $request->user();
        $user_is_member = $this->compareProjects($user->id, $this->key);

        if (!$user_is_member) {
            return $this->setStatusCode(401)->replyWithError(trans('api.projects_users_not_connected'));
        }

        $collection = Collection::where('user_id', $user->id)->where('id', $collection_id)->first();

        if (!$collection) {
            return $this->setStatusCode(404)->replyWithError('Collection Not Found');
        }

        /*
        $collection_playlists_ids = $collection->playlists()->pluck('id')->unique();
        $collection_playlists = CollectionPlaylist::whereIn('id', $collection_playlists_ids);
        $collection_playlists->delete();
        */
        //$user_plans = UserPlan::where('collection_id', $collection_id);
        //$user_plans->delete();

        $collection->playlists()->delete();
        $collection->delete();

        return $this->reply('Collection Deleted');
    }

    private function validateCollection()
    {
        $validator = Validator::make(request()->all(), [
            'name'              => 'required|string'
        ]);
        if ($validator->fails()) {
            return ['errors' => $validator->errors()];
        }
        return true;
    }

    /**
     *  @OA\Schema (
     *   type="object",
     *   schema="v4_collection",
     *   @OA\Property(property="id", ref="#/components/schemas/Collection/properties/id"),
     *   @OA\Property(property="name", ref="#/components/schemas/Collection/properties/name"),
     *   @OA\Property(property="featured", ref="#/components/schemas/Collection/properties/featured"),
     *   @OA\Property(property="created_at", ref="#/components/schemas/Collection/properties/created_at"),
     *   @OA\Property(property="updated_at", ref="#/components/schemas/Collection/properties/updated_at"),
     *   @OA\Property(property="user", ref="#/components/schemas/v4_collection_index_user"),
     * )
     *
     *  @OA\Schema (
     *   type="object",
     *   schema="v4_collection_playlists",
     *   @OA\Property(property="id", ref="#/components/schemas/CollectionPlaylist/properties/id"),
     *   @OA\Property(property="collection_id", ref="#/components/schemas/CollectionPlaylist/properties/collection_id"),
     *   @OA\Property(property="playlist_id", ref="#/components/schemas/CollectionPlaylist/properties/playlist_id"),
     *   @OA\Property(property="order_column", ref="#/components/schemas/CollectionPlaylist/properties/order_column"),
     *   @OA\Property(property="created_at", ref="#/components/schemas/Collection/properties/created_at"),
     *   @OA\Property(property="updated_at", ref="#/components/schemas/Collection/properties/updated_at"),
     * )
     *
     * @OA\Schema (
     *   type="object",
     *   schema="v4_collection_index_user",
     *   description="The user who created the collection",
     *   @OA\Property(property="id", type="integer"),
     *   @OA\Property(property="name", type="string")
     * )
     *
     * @OA\Schema (
     *   type="object",
     *   schema="v4_collection_detail",
     *   allOf={
     *      @OA\Schema(ref="#/components/schemas/v4_collection"),
     *   }
     * )
     *
     *
     * @OA\Response(
     *   response="collection",
     *   description="collection Object",
     *   @OA\MediaType(mediaType="application/json", @OA\Schema(ref="#/components/schemas/v4_collection_detail")),
     *   @OA\MediaType(mediaType="application/xml",  @OA\Schema(ref="#/components/schemas/v4_collection_detail")),
     *   @OA\MediaType(mediaType="text/x-yaml",      @OA\Schema(ref="#/components/schemas/v4_collection_detail")),
     *   @OA\MediaType(mediaType="text/csv",         @OA\Schema(ref="#/components/schemas/v4_collection_detail"))
     * )
     */

    private function getCollection($collection_id, $user, $with_order = false)
    {
        $select = ['collections.*'];
        $plan = Collection::with('user')
            ->with('playlists')
            ->where('collections.id', $collection_id)
            ->select($select)->first();

        return $plan;
    }

    /**
     * Store the newly created collection playlist.
     *
     *  @OA\Post(
     *     path="/collections/{collection_id}/playlists",
     *     tags={"Collections"},
     *     summary="Create collection playlist",
     *     operationId="v4_collection_playlists.store",
     *     security={{"api_token":{}}},
     *     @OA\Parameter(name="collection_id", in="path", required=true, @OA\Schema(ref="#/components/schemas/Collection/properties/id")),
     *     @OA\Response(
     *         response=200,
     *         description="successful operation",
     *         @OA\MediaType(mediaType="application/json", @OA\Schema(ref="#/components/schemas/v4_collection_playlists")),
     *         @OA\MediaType(mediaType="application/xml",  @OA\Schema(ref="#/components/schemas/v4_collection_playlists")),
     *         @OA\MediaType(mediaType="text/x-yaml",      @OA\Schema(ref="#/components/schemas/v4_collection_playlists")),
     *         @OA\MediaType(mediaType="text/csv",         @OA\Schema(ref="#/components/schemas/v4_collection_playlists"))
     *     )
     * )
     *
     * @OA\Schema (
     *   type="array",
     *   schema="v4_collections_playlists",
     *   title="User created collection playlists",
     *   description="The v4 collection playlists creation response.",
     *   @OA\Items(ref="#/components/schemas/CollectionPlaylist")
     * )
     * @return mixed
     */
    public function storePlaylist(Request $request, $collection_id)
    {
        $user = $request->user();
        $user_is_member = $this->compareProjects($user->id, $this->key);
        if (!$user_is_member) {
            return $this->setStatusCode(401)->replyWithError(trans('api.projects_users_not_connected'));
        }

        $collection = Collection::where('user_id', $user->id)->where('id', $collection_id)->first();

        if (!$collection) {
            return $this->setStatusCode(404)->replyWithError('Collection Not Found');
        }

        $playlist_id  = intval(checkParam('playlist_id', true));
        $order_column = intval(checkParam('order_column', true));

        // create a collection playlist
        $collection_playlist = CollectionPlaylist::create([
          'collection_id' => $collection_id,
          'playlist_id'   => $playlist_id,
          'order_column'  => $order_column
        ]);

        return $this->reply($collection_playlist);
    }

    /**
     *
     * @OA\Get(
     *     path="/collections/{collection_id}/playlists",
     *     tags={"Collection"},
     *     summary="A user's collection",
     *     operationId="v4_collections.playlists",
     *     security={{"api_token":{}}},
     *     @OA\Parameter(
     *          name="collection_id",
     *          in="path",
     *          required=true,
     *          @OA\Schema(ref="#/components/schemas/Collection/properties/id"),
     *          description="The collection id"
     *     ),
     *     @OA\Parameter(
     *          name="show_details",
     *          in="query",
     *          @OA\Schema(type="boolean"),
     *          description="Give full details of the plan"
     *     ),
     *     @OA\Parameter(
     *          name="show_text",
     *          in="query",
     *          @OA\Schema(type="boolean"),
     *          description="Enable the full details of the plan and retrieve the text of the playlists items"
     *     ),
     *     @OA\Response(response=200, ref="#/components/responses/plan")
     * )
     *
     * @param $plan_id
     *
     * @return mixed
     *
     *
     */
    public function getPlaylists(Request $request, $collection_id)
    {
        $user = $request->user();

        // Validate Project / User Connection
        if (!empty($user) && !$this->compareProjects($user->id, $this->key)) {
            return $this->setStatusCode(401)->replyWithError(trans('api.projects_users_not_connected'));
        }

        $collection = $this->getCollection($collection_id, $user);

        if (!$collection) {
            return $this->setStatusCode(404)->replyWithError('Collection Not Found');
        }

        $show_details = checkBoolean('show_details');
        $show_text = checkBoolean('show_text');
        if ($show_text) {
            $show_details = $show_text;
        }

        if ($show_details) {
            $playlist_controller = new PlaylistsController();
            // get those playlists
            $playlists = $collection->playlists;
            foreach($playlists as $colPlaylist) {
                $playlist = $playlist_controller->getPlaylist($user, $colPlaylist->playlist_id);
                $playlist->path = route('v4_playlists.hls', ['playlist_id'  => $colPlaylist->playlist_id, 'v' => $this->v, 'key' => $this->key]);
                if ($show_text) {
                    foreach ($playlist->items as $item) {
                        $item->verse_text = $item->getVerseText();
                    }
                }
                $colPlaylist->playlist = $playlist;
            }
            $collection->playlists = $playlists;
        }

        return $this->reply($collection);
    }

    /**
     *
     * @OA\Put(
     *     path="/collections/{collection_id}/playlists/{playlist_id}",
     *     tags={"Collections"},
     *     summary="Update a collection playlist",
     *     description="Update a collection playlist",
     *     operationId="v4_collection_playlists.update",
     *     @OA\Parameter(name="collection_id", in="path", required=true, @OA\Schema(ref="#/components/schemas/Collection/properties/id")),
     *     @OA\Parameter(name="playlist_id", in="path", required=true, @OA\Schema(ref="#/components/schemas/Playlist/properties/id")),
     *     @OA\RequestBody(required=true, description="Fields for Collection Playlist Update",
     *          @OA\MediaType(mediaType="application/json",                  @OA\Schema(ref="#/components/schemas/CollectionPlaylist")),
     *          @OA\MediaType(mediaType="application/x-www-form-urlencoded", @OA\Schema(ref="#/components/schemas/CollectionPlaylist"))
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="The collection playlist just edited",
     *         @OA\MediaType(mediaType="application/json", @OA\Schema(ref="#/components/schemas/CollectionPlaylist")),
     *         @OA\MediaType(mediaType="application/xml",  @OA\Schema(ref="#/components/schemas/CollectionPlaylist")),
     *         @OA\MediaType(mediaType="text/x-yaml",      @OA\Schema(ref="#/components/schemas/CollectionPlaylist")),
     *         @OA\MediaType(mediaType="text/csv",      @OA\Schema(ref="#/components/schemas/CollectionPlaylist"))
     *     )
     * )
     *
     * @param $id
     *
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function updatePlaylist(Request $request, $collection_id, $playlist_id)
    {
        $order_column = intval(checkParam('order_column', true));

        // Validate Project / User Connection
        $user = $request->user();
        $user_is_member = $this->compareProjects($user->id, $this->key);

        if (!$user_is_member) {
            return $this->setStatusCode(401)->replyWithError(trans('api.projects_users_not_connected'));
        }

        $collection = Collection::where('user_id', $user->id)->where('id', $collection_id)->first();

        if (!$collection) {
            return $this->setStatusCode(404)->replyWithError('Collection Not Found');
        }
        $playlists = $collection->playlists()->where('playlist_id', $playlist_id);
        if (!$playlists->count()) {
            return $this->setStatusCode(404)->replyWithError('Playlist Not Found');
        }
        // request()->all() has too many fields
        $playlists->update([
          'collection_id' => $collection_id,
          'playlist_id'   => $playlist_id,
          'order_column'  => $order_column,
        ]);
        //$playlists->save();

        return $this->reply($playlists);
    }

    /**
     * Remove the specified plan.
     *
     *  @OA\Delete(
     *     path="/collections/{collection_id}/playlists/{playlist_id}",
     *     tags={"Collections"},
     *     summary="Delete a collection",
     *     operationId="v4_collection_playlists.destroy",
     *     security={{"api_token":{}}},
     *     @OA\Parameter(name="collection_id", in="path", required=true, @OA\Schema(ref="#/components/schemas/Collection/properties/id")),
     *     @OA\Parameter(name="playlist_id", in="path", required=true, @OA\Schema(ref="#/components/schemas/Playlist/properties/id")),
     *     @OA\Response(
     *         response=200,
     *         description="successful operation",
     *         @OA\MediaType(mediaType="application/json", @OA\Schema(type="string")),
     *         @OA\MediaType(mediaType="application/xml",  @OA\Schema(type="string")),
     *         @OA\MediaType(mediaType="text/x-yaml",      @OA\Schema(type="string")),
     *         @OA\MediaType(mediaType="text/csv",      @OA\Schema(type="string"))
     *     )
     * )
     *
     * @param  int $collection_id
     * @param  int $playlist_id
     *
     * @return array|\Illuminate\Http\Response
     */
    public function destroyPlaylist(Request $request, $collection_id, $playlist_id)
    {
        // Validate Project / User Connection
        $user = $request->user();
        $user_is_member = $this->compareProjects($user->id, $this->key);

        if (!$user_is_member) {
            return $this->setStatusCode(401)->replyWithError(trans('api.projects_users_not_connected'));
        }

        $collection = Collection::where('user_id', $user->id)->where('id', $collection_id)->first();

        if (!$collection) {
            return $this->setStatusCode(404)->replyWithError('Collection Not Found');
        }
        $playlists = $collection->playlists()->where('playlist_id', $playlist_id);
        if (!$playlists->count()) {
            return $this->setStatusCode(404)->replyWithError('Playlist Not Found');
        }
        $playlists->delete();
        return $this->reply('Collection Playlist Deleted');
    }

}
