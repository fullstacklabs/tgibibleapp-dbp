<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\APIController;
use App\Models\Bible\Book;
use App\Models\User\Study\Bookmark;
use App\Traits\CheckProjectMembership;
use App\Transformers\UserBookmarksTransformer;
use App\Transformers\V2\Annotations\BookmarkTransformer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use League\Fractal\Pagination\IlluminatePaginatorAdapter;
use App\Traits\AnnotationTags;
use GuzzleHttp\Client;

class BookmarksController extends APIController
{
    use AnnotationTags;
    use CheckProjectMembership;

    /**
     * Display a listing of the bookmarks.
     *
     * @OA\Get(
     *     path="/users/{user_id}/bookmarks",
     *     tags={"Annotations"},
     *     summary="List a user's bookmarks",
     *     description="",
     *     operationId="v4_user_annotation_bookmarks.index",
     *     security={{"api_token":{}}},
     *     @OA\Parameter(
     *          name="user_id",
     *          in="path",
     *          required=true,
     *          description="The user_id",
     *          @OA\Schema(ref="#/components/schemas/User/properties/id")
     *     ),
     *     @OA\Parameter(name="bible_id", in="query", description="Will filter the results by the given bible",
     *          @OA\Schema(ref="#/components/schemas/BibleFileset/properties/id")
     *     ),
     *     @OA\Parameter(name="book_id", in="query", description="Will filter the results by the given book. For a complete list see the `book_id` field in the `/bibles/books` route.",
     *          @OA\Schema(ref="#/components/schemas/Book/properties/id")
     *     ),
     *     @OA\Parameter(name="chapter_id", in="query", description="Will filter the results by the given chapter",
     *          @OA\Schema(ref="#/components/schemas/BibleFile/properties/chapter_start")
     *     ),
     *     @OA\Parameter(name="limit",  in="query", description="The number of results to return",
     *          @OA\Schema(type="integer",default=25)),
     *     @OA\Parameter(name="page",  in="query", description="The current page of the results",
     *          @OA\Schema(type="integer",default=1)),
     *     @OA\Parameter(
     *          name="query",
     *          in="query",
     *          description="The word or phrase to filter bookmarks by.",
     *          @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(ref="#/components/parameters/sort_by"),
     *     @OA\Parameter(ref="#/components/parameters/sort_dir"),
     *     @OA\Response(
     *         response=200,
     *         description="successful operation",
     *         @OA\MediaType(mediaType="application/json", @OA\Schema(ref="#/components/schemas/v4_user_bookmarks")),
     *         @OA\MediaType(mediaType="application/xml",  @OA\Schema(ref="#/components/schemas/v4_user_bookmarks")),
     *         @OA\MediaType(mediaType="text/x-yaml",      @OA\Schema(ref="#/components/schemas/v4_user_bookmarks")),
     *         @OA\MediaType(mediaType="text/csv",      @OA\Schema(ref="#/components/schemas/v4_user_bookmarks"))
     *     )
     * )
     *
     * @param int $user_id
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request, $user_id)
    {
        $user = $request->user();
        $user_id = $user ? $user->id : $request->user_id;
        $user_is_member = $this->compareProjects($user_id, $this->key);
        if (!$user_is_member) {
            return $this->setStatusCode(401)->replyWithError(trans('api.projects_users_not_connected'));
        }

        $bible_id     = checkParam('bible_id');
        $book_id      = checkParam('book_id');
        $chapter      = checkParam('chapter|chapter_id');
        $limit        = (int) (checkParam('limit') ?? 25);
        $sort_by    = checkParam('sort_by');
        $sort_dir   = checkParam('sort_dir') ?? 'asc';
        $query      = checkParam('query');

        $content_config = config('services.content');
        $book_bible_map = array();
        if (!empty($content_config['url']) && $query) {
            $client = new Client();
            $res = $client->get($content_config['url'] . 'bibles/book/search/'.
                $query.'?v=4&key=' . $content_config['key']);
            $book_bible_map = json_decode($res->getBody() . '', true);
        }

        $bookmarks = Bookmark::with('tags')
            ->where('user_bookmarks.user_id', $user_id)
            ->when($bible_id, function ($q) use ($bible_id) {
                $q->where('user_bookmarks.bible_id', $bible_id);
            })->when($book_id, function ($q) use ($book_id) {
                $q->where('user_bookmarks.book_id', $book_id);
            })->when($chapter, function ($q) use ($chapter) {
                $q->where('user_bookmarks.chapter', $chapter);
            })->when($sort_by, function ($q) use ($sort_by, $sort_dir) {
                $q->orderBy('user_bookmarks.' . $sort_by, $sort_dir);
            })->when($query, function ($q) use ($query, $content_config, $book_bible_map) {
                if (empty($content_config['url'])) {
                    // Local content
                    $dbp_database = config('database.connections.dbp.database');
                    $q->join($dbp_database . '.bible_books as bible_books', function ($join) use ($query) {
                        $join->on('user_bookmarks.bible_id', '=', 'bible_books.bible_id')
                            ->on('user_bookmarks.book_id', '=', 'bible_books.book_id');
                    });
                    $q->where('bible_books.name', 'like', '%' . $query . '%');
                } else {
                    // Remote content
                    // do the bible_id filter list
                    $q->whereIn('user_bookmarks.bible_id', array_keys($book_bible_map));
                }
            })->paginate($limit);

        // do we need final filter?
        if (!empty($content_config['url']) && $query) {
            // filter by book_id $query filter
            $collection = $bookmarks->getCollection(); // get collections for modification
            $final_bookmarks = $collection->filter(function($bookmark) use ($book_bible_map) {
                // only include where we have bible_id and book_id in $book_bible_map
                return in_array($bookmark->book_id, $book_bible_map[$bookmark->bible_id]);
            });
            $bookmarks->setCollection($final_bookmarks); // save back into bookmarks
        }

        $bookmarkCollection = $bookmarks->getCollection();
        $bookmarkPagination = new IlluminatePaginatorAdapter($bookmarks);
        return $this->reply(fractal($bookmarkCollection, UserBookmarksTransformer::class, $this->serializer)->paginateWith($bookmarkPagination));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @OA\Post(
     *     path="/users/{user_id}/bookmarks",
     *     tags={"Annotations"},
     *     summary="Create a bookmark",
     *     description="",
     *     operationId="v4_user_annotation_bookmarks.store",
     *     security={{"api_token":{}}},
     *     @OA\Parameter(
     *          name="user_id",
     *          in="path",
     *          required=true,
     *          description="The user_id",
     *          @OA\Schema(ref="#/components/schemas/User/properties/id")
     *     ),
     *     @OA\RequestBody(required=true, description="Fields for User Bookmark Creation", @OA\MediaType(mediaType="application/json",
     *          @OA\Schema(
     *              @OA\Property(property="bible_id",                  ref="#/components/schemas/Bible/properties/id"),
     *              @OA\Property(property="book_id",                   ref="#/components/schemas/Book/properties/id"),
     *              @OA\Property(property="user_id",                   ref="#/components/schemas/User/properties/id"),
     *              @OA\Property(property="chapter",                   ref="#/components/schemas/Bookmark/properties/chapter"),
     *              @OA\Property(property="verse_start",               ref="#/components/schemas/Bookmark/properties/verse_start")
     *          )
     *     )),
     *     @OA\Response(
     *         response=200,
     *         description="successful operation",
     *         @OA\MediaType(mediaType="application/json", @OA\Schema(ref="#/components/schemas/v4_user_bookmarks")),
     *         @OA\MediaType(mediaType="application/xml",  @OA\Schema(ref="#/components/schemas/v4_user_bookmarks")),
     *         @OA\MediaType(mediaType="text/x-yaml",      @OA\Schema(ref="#/components/schemas/v4_user_bookmarks")),
     *         @OA\MediaType(mediaType="text/csv",      @OA\Schema(ref="#/components/schemas/v4_user_bookmarks"))
     *     )
     * )
     *
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $user = $request->user();
        $request['user_id'] = $user ? $user->id : $request->user_id;
        $user_is_member = $this->compareProjects($request->user_id, $this->key);
        if (!$user_is_member) {
            return $this->setStatusCode(401)->replyWithError(trans('api.projects_users_not_connected'));
        }

        $book = Book::where('id', $request->book_id)->first();
        $request['book_id'] = $book->id;
        $request['bible_id'] = $request->dam_id ?? $request->bible_id;

        $invalidBookmark = $this->validateBookmark();
        if ($invalidBookmark) {
            return $this->setStatusCode(422)->replyWithError($invalidBookmark);
        }

        $bookmark_id = Bookmark::create($request->all())->id;
        $bookmark = Bookmark::where('id', $bookmark_id)->first();

        $this->handleTags($bookmark);

        return $this->reply(fractal($bookmark, BookmarkTransformer::class)->addMeta(['success' => 'Bookmark Created successfully']));
    }

    /**
     * Update the specified resource in storage.
     *
     * @OA\Put(
     *     path="/users/{user_id}/bookmarks/{bookmark_id}",
     *     tags={"Annotations"},
     *     summary="Update a bookmark",
     *     description="",
     *     operationId="v4_user_annotation_bookmarks.update",
     *     security={{"api_token":{}}},
     *     @OA\Parameter(name="user_id", in="path", required=true, @OA\Schema(ref="#/components/schemas/User/properties/id")),
     *     @OA\Parameter(name="bookmark_id", in="path", required=true, @OA\Schema(ref="#/components/schemas/User/properties/id")),
     *     @OA\RequestBody(required=true, description="Fields for User Bookmark Creation", @OA\MediaType(mediaType="application/json",
     *          @OA\Schema(
     *              @OA\Property(property="bible_id",                  ref="#/components/schemas/Bible/properties/id"),
     *              @OA\Property(property="book_id",                   ref="#/components/schemas/Book/properties/id"),
     *              @OA\Property(property="chapter",                   ref="#/components/schemas/Bookmark/properties/chapter"),
     *              @OA\Property(property="verse_start",               ref="#/components/schemas/Bookmark/properties/verse_start")
     *          )
     *     )),
     *     @OA\Response(
     *         response=200,
     *         description="successful operation",
     *         @OA\MediaType(mediaType="application/json", @OA\Schema(ref="#/components/schemas/v4_user_bookmarks")),
     *         @OA\MediaType(mediaType="application/xml",  @OA\Schema(ref="#/components/schemas/v4_user_bookmarks")),
     *         @OA\MediaType(mediaType="text/x-yaml",      @OA\Schema(ref="#/components/schemas/v4_user_bookmarks")),
     *         @OA\MediaType(mediaType="text/csv",      @OA\Schema(ref="#/components/schemas/v4_user_bookmarks"))
     *     )
     * )
     *
     * @param  int $user_id
     * @param  int $bookmark_id
     *
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $user_id, $bookmark_id)
    {
        $user = $request->user();
        $user_id = $user ? $user->id : $user_id;
        $user_is_member = $this->compareProjects($user_id, $this->key);
        if (!$user_is_member) {
            return $this->setStatusCode(401)->replyWithError(trans('api.projects_users_not_connected'));
        }

        $invalidBookmark = $this->validateBookmark();
        if ($invalidBookmark) {
            return $this->setStatusCode(422)->replyWithError($invalidBookmark);
        }

        $bookmark = Bookmark::where('id', $bookmark_id)->where('user_id', $user_id)->first();
        if (!$bookmark) {
            return $this->setStatusCode(404)->replyWithError('Bookmark not found');
        }
        $bookmark->fill($request->all());
        $bookmark->save();

        $this->handleTags($bookmark);

        return $this->reply(fractal($bookmark, new BookmarkTransformer())->addMeta(['success' => 'Bookmark Successfully updated']));
    }

    /**
     * Remove the specified resource from storage.
     *
     * @OA\Delete(
     *     path="/users/{user_id}/bookmarks/{bookmark_id}",
     *     tags={"Annotations"},
     *     summary="Delete a bookmark",
     *     description="",
     *     operationId="v4_user_annotation_bookmarks.delete",
     *     security={{"api_token":{}}},
     *     @OA\Parameter(name="user_id", in="path", required=true, @OA\Schema(ref="#/components/schemas/User/properties/id")),
     *     @OA\Parameter(name="bookmark_id", in="path", required=true, @OA\Schema(ref="#/components/schemas/User/properties/id")),
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
     * @param  int  $user_id
     * @param  int  $bookmark_id
     * @return \Illuminate\Http\Response
     */
    public function destroy(Request $request, $user_id, $bookmark_id)
    {
        $user = $request->user();
        $user_id = $user ? $user->id : $user_id;
        $user_is_member = $this->compareProjects($user_id, $this->key);
        if (!$user_is_member) {
            return $this->setStatusCode(401)->replyWithError(trans('api.projects_users_not_connected'));
        }

        $bookmark = Bookmark::where('id', $bookmark_id)->where('user_id', $user_id)->first();
        if (!$bookmark) {
            return $this->setStatusCode(404)->replyWithError('Bookmark not found');
        }
        $bookmark->delete();

        return $this->reply('bookmark successfully deleted');
    }

    private function validateBookmark()
    {
        $checks = [
            //'bible_id'    => ((request()->method() === 'POST') ? 'required|' : '') . 'exists:dbp.bibles,id',
            'user_id'     => ((request()->method() === 'POST') ? 'required|' : '') . 'exists:dbp_users.users,id',
            //'book_id'     => ((request()->method() === 'POST') ? 'required|' : '') . 'exists:dbp.books,id',
            'chapter'     => ((request()->method() === 'POST') ? 'required|' : '') . 'max:150|min:1|integer',
            'verse_start' => ((request()->method() === 'POST') ? 'required|' : '') . 'max:177|min:1|integer'
        ];
        $content_config = config('services.content');
        $errors = array();
        if (empty($content_config['url'])) {
            $checks['bible_id'] = (($request->method === 'POST') ? 'required|' : '') . 'exists:dbp.bibles,id';
            $checks['book_id']  = (($request->method === 'POST') ? 'required|' : '') . 'exists:dbp.books,id';
        } else {
            $checks['bible_id'] = (($request->method === 'POST') ? 'required|' : '');
            $checks['book_id']  = (($request->method === 'POST') ? 'required|' : '');
            if ($request->method === 'POST') {
                // FIXME
            }
        }
        $validator = Validator::make(request()->all(), $checks);
        if ($validator->fails()) {
            return ['errors' => $validator->errors()];
        }

        return null;
    }
}
