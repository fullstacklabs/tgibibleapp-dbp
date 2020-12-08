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
            $cache_params = [$featured, $limit, $sort_by, $sort_dir, $iso, $language_id];
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
            })
            ->orderBy($sort_by, $sort_dir)->paginate($limit);

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
            // thumbnail_url is not fillable by user...
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
            foreach ($collection->playlists as $playlist) {
                $playlist_data = $playlist_controller->getPlaylist($user, $playlist->playlist_id);
                if ($show_text) {
                    foreach ($playlist_data->items as $item) {
                        $item->verse_text = $item->getVerseText();
                    }
                }
                $playlist->playlist = $playlist_data;
            }
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

        $playlists = checkParam('playlists');
        $delete_playlists = checkBoolean('delete_playlists');

        if ($playlists || $delete_playlists) {
            $playlists_ids = [];
            if (!$delete_playlists) {
                $playlists_ids = explode(',', $playlists);
                CollectionPlaylist::setNewOrder($playlists_ids);
            }
            $deleted_playlists = CollectionPlaylist::whereNotIn('id', $playlists_ids)->where('collection_id', $collection->id);
            $playlists_ids = $deleted_playlists->pluck('playlist_id')->unique();
            $collection_playlists = Playlist::whereIn('id', $playlists_ids);
            $deleted_playlists->delete();
            $collection_playlists->delete();
        }

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
        $collection = Collection::with('user')
            ->with('playlists')
            ->where('collections.id', $collection_id)
            ->select(['collections.*'])->first();

        return $collection;
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
}
