<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\APIController;
use App\Models\User\User;
use App\Models\User\Study\HighlightColor;
use App\Models\User\Study\Highlight;
use App\Transformers\UserHighlightsTransformer;
use App\Traits\AnnotationTags;
use App\Traits\CheckProjectMembership;
use League\Fractal\Pagination\IlluminatePaginatorAdapter;
use Illuminate\Support\Facades\DB;
use Validator;
use GuzzleHttp\Client;

use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;
use Illuminate\Pagination\LengthAwarePaginator;

class HighlightsController extends APIController
{
    use AnnotationTags;
    use CheckProjectMembership;

    /**
     * Display a listing of the resource.
     *
     * @OA\Get(
     *     path="/users/{user_id}/highlights",
     *     tags={"Annotations"},
     *     summary="List a user's highlights",
     *     description="The bible_id, book_id, and chapter parameters are optional but
     *          will allow you to specify which specific highlights you wish returned.",
     *     operationId="v4_internal_highlights.index",
     *     security={{"api_token":{}}},
     *     @OA\Parameter(
     *          name="user_id",
     *          in="path",
     *          required=true,
     *          @OA\Schema(ref="#/components/schemas/User/properties/id"),
     *          description="The user who created the highlights"
     *     ),
     *     @OA\Parameter(
     *          name="bible_id",
     *          in="query",
     *          @OA\Schema(ref="#/components/schemas/Bible/properties/id"),
     *          description="The bible to filter highlights by"
     *     ),
     *     @OA\Parameter(
     *          name="book_id",
     *          in="query",
     *          @OA\Schema(ref="#/components/schemas/Book/properties/id"),
     *          description="The book to filter highlights by. For a complete list see the `book_id` field in the `/bibles/books` route."
     *     ),
     *     @OA\Parameter(
     *          name="chapter",
     *          in="query",
     *          @OA\Schema(ref="#/components/schemas/BibleFile/properties/chapter_start"),
     *          description="The chapter to filter highlights by"
     *     ),
     *     @OA\Parameter(
     *          name="limit",
     *          in="query",
     *          @OA\Schema(type="integer",default=25),
     *          description="The number of highlights to include in each return"
     *     ),
     *     @OA\Parameter(
     *          name="page",
     *          in="query",
     *          @OA\Schema(type="integer",default=1),
     *          description="The current page of the results"
     *     ),
     *     @OA\Parameter(
     *          name="prefer_color",
     *          in="query",
     *          @OA\Schema(type="string",default="rgba",enum={"hex","rgba","rgb","full"}),
     *          description="Choose the format that highlighted colors will be returned in. If no color
     *          is not specified than the default is a six letter hexadecimal color."
     *     ),
     *     @OA\Parameter(
     *          name="color",
     *          in="query",
     *          @OA\Schema(type="string",
     *          description="One or more six letter hexadecimal colors to filter highlights by.",
     *          example="aabbcc,eedd11,112233")
     *     ),
     *     @OA\Parameter(
     *          name="query",
     *          in="query",
     *          description="The word or phrase to filter highlights by.",
     *          @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(ref="#/components/parameters/sort_by"),
     *     @OA\Parameter(ref="#/components/parameters/sort_dir"),
     *     @OA\Response(
     *         response=200,
     *         description="successful operation",
     *         @OA\MediaType(mediaType="application/json", @OA\Schema(ref="#/components/schemas/v4_internal_highlights_index"))
     *     )
     * )
     *
     * @param $user_id
     *
     * @return mixed
     */
    public function index(Request $request, $user_id)
    {
        $user = $request->user();
        $user_id = $user ? $user->id : $request->user_id;
        $sort_by    = checkParam('sort_by') ?? 'book';
        $sort_dir   = checkParam('sort_dir') ?? 'asc';
        $query      = checkParam('query');

        // Validate Project / User Connection
        $user = User::where('id', $user_id)->select('id')->first();

        if (!$user) {
            return $this->setStatusCode(404)->replyWithError(trans('api.users_errors_404'));
        }

        $user_is_member = $this->compareProjects($user_id, $this->key);

        if (!$user_is_member) {
            return $this->setStatusCode(401)->replyWithError(trans('api.projects_users_not_connected'));
        }

        // now that most validation is done, lets do something that takes time


        $bible_id     = checkParam('bible_id');
        $book_id      = checkParam('book_id');
        $chapter_id   = checkParam('chapter|chapter_id');
        $color        = checkParam('color');
        $page         = checkParam('page');
        $limit        = (int) (checkParam('limit') ?? 25);

        $sort_by_book = $sort_by === 'book';

        $select_fields = [
            'user_highlights.id',
            'user_highlights.bible_id',
            'user_highlights.book_id',
            'user_highlights.chapter',
            'user_highlights.verse_start',
            'user_highlights.verse_end',
            'user_highlights.highlight_start',
            'user_highlights.highlighted_words',
            'user_highlights.highlighted_color',
        ];
        $order_by = DB::raw('user_highlights.' . $sort_by);

        $content_config = config('services.content');
        if ($sort_by_book) {

            // get the dbp.books order
            if (empty($content_config['url'])) {
                // Local content
                $book_order_query = cacheRemember('book_order_columns', [], now()->addDay(), function () {
                    $query = collect(Schema::connection('dbp')->getColumnListing('books'))->filter(function ($column) {
                        return strpos($column, '_order') !== false;
                    })->map(function ($column) {
                        $name = str_replace('_order', '', $column);
                        return "IF(bibles.versification = '" . $name . "', books." . $name . '_order , 0)';
                    })->toArray();

                    return implode('+', $query);
                });
                $select_fields[] = DB::raw($book_order_query . ' as book_order');
                $order_by = DB::raw('book_order, user_highlights.chapter, user_highlights.verse_start');
            } else {
                // Remote content
                $book_order = cacheRemember('book_order_columns', [], now()->addDay(), function () use ($content_config) {
                    $client = new Client();
                    $res = $client->get($content_config['url'] . 'bibles/book/order'.
                      '?v=4&key=' . $content_config['key']);
                    return json_decode($res->getBody().'', true);
                });
                if (is_array($book_order)) {
                    $book_order_map = array_flip($book_order);
                } else {
                    $book_order_map = [];
                }
                $order_by = DB::raw('user_highlights.chapter, user_highlights.verse_start');
            }
        }

        $highlights = Highlight::join('user_highlight_colors', 'user_highlights.highlighted_color', '=', 'user_highlight_colors.id')
            ->where('user_id', $user_id)
            ->when($bible_id, function ($q) use ($bible_id) {
                $q->where('user_highlights.bible_id', $bible_id);
            })->when($book_id, function ($q) use ($book_id) {
                $q->where('user_highlights.book_id', $book_id);
            })->when($chapter_id, function ($q) use ($chapter_id) {
                $q->where('chapter', $chapter_id);
            })->when($color, function ($q) use ($color) {
                $color = str_replace('#', '', $color);
                $color = explode(',', $color);
                $q->whereIn('user_highlight_colors.hex', $color);
            })->when($query, function ($q) use ($query, $content_config) {
                if (empty($content_config['url'])) {
                    $dbp_database = config('database.connections.dbp.database');
                    // join to get verse_text access
                    $q->join($dbp_database . '.bible_fileset_connections as connection', 'connection.bible_id', 'user_highlights.bible_id');
                    $q->join($dbp_database . '.bible_filesets as filesets', function ($join) {
                        $join->on('filesets.hash_id', '=', 'connection.hash_id');
                    });
                    $q->where('filesets.set_type_code', 'text_plain');
                    $q->join($dbp_database . '.bible_verses as bible_verses', function ($join) use ($query) {
                        $join->on('connection.hash_id', '=', 'bible_verses.hash_id')
                            ->where('bible_verses.book_id', DB::raw('user_highlights.book_id'))
                            ->where('bible_verses.chapter', DB::raw('user_highlights.chapter'))
                            ->where('bible_verses.verse_start', '>=', DB::raw('user_highlights.verse_start'))
                            ->where('bible_verses.verse_end', '<=', DB::raw('user_highlights.verse_end'));
                    });

                    // join to get book name access
                    $q->join($dbp_database . '.bible_books as bible_books', function ($join) use ($query) {
                        $join->on('user_highlights.bible_id', '=', 'bible_books.bible_id')
                            ->on('user_highlights.book_id', '=', 'bible_books.book_id');
                    });

                    // book name or verse_text FTS
                    $q->where(function ($q) use ($query) {
                        $q->where('bible_verses.verse_text', 'like', '%' . $query . '%')
                            ->orWhere('bible_books.name', 'like', '%' . $query . '%');
                    });
                } else {
                    // Remote content
                    // we can't filter here because it's verse OR book name
                    // so we'll need to get all the data
                }
            })->when($sort_by_book, function ($q) {
                if (empty($content_config['url'])) {
                    // if sort_by_book, add books/bibles table joins...
                    $dbp_database = config('database.connections.dbp.database');
                    $q->join($dbp_database . '.books as books', function ($join) {
                        $join->on('user_highlights.book_id', '=', 'books.id');
                    });
                    $q->join($dbp_database . '.bibles as bibles', function ($join) {
                        $join->on('user_highlights.bible_id', '=', 'bibles.id');
                    });
                } else {
                    // can't join the tables for the sort
                    // but basically we need to group these by bible.versification
                    // it maps bible.versification to books.X_order as book_order
                    // and then we sort by book_order
                }
            })->select($select_fields);

        // if content server && (sort_by_book, query or chapter_id)
        if (!empty($content_config['url']) &&
           ($sort_by_book || $query || $chapter_id)) {
            // run query as is (without order and pagination)
            $highlight_collection = $highlights->get();

            // we need to know the following fields to do something:
            // get all bible_id, book_id, chapter, verse_start, verse_end

            // sort_by_book by itself needs bible data linked for the sort...
            // so we have to dl it, and skip the whens
            // and do the final sort

            // user_highlights => bible content
            $map = [];
            $biblebooks = [];
            foreach ($highlight_collection as $hl) {
                $key = $hl->bible_id;
                // list of bibles
                if (!isset($map[$key])) {
                    $map[$key] = [];
                }
                // list of books per bible
                if (!isset($biblebooks[$key])) {
                    $biblebooks[$key] = [];
                }
                $biblebooks[$key][$hl->book_id] = 1;
                // might be faster if we break down HLs into books
                $map[$key][] = $hl;
            }
            unset($highlight_collection); // free memory

            // now process each bible's highlights
            $client = new Client(); // reuseable client
            $keepers = [];
            foreach ($map as $slug => $hlrows) {
                //echo "Start[$slug] [", join(',', array_keys($biblebooks[$slug])), "]<br>\n";
                // download a copy of the verses in bible (31k rows, ~1-4MB)
                $bible_verse_text = cacheRemember('bible_verses', [$slug],
                  now()->addDay(), function () use ($content_config, $slug, $client) {
                      $res = $client->get($content_config['url'] . 'bibles/' .  $slug .
                      '/verses?v=4&key=' . $content_config['key']);
                      return json_decode($res->getBody() . '', true);
                  });

                // bible_verse_text['books'] contains book_id, name, testament, verses
                // verses are sent in this order:
                // 0=>chapter, 1=>verse_start, 2=>verse_end, 3=>verse_text

                if (0) {
                    foreach ($bible_verse_text['books'] as $book) {
                        echo 'Have [', $book['book_id'], "]<br>\n";
                    }
                }

                // gives a 4-10x reduction
                $bible_book_verses_arr = collect($bible_verse_text['books'])->filter(function ($book) use ($slug, $biblebooks) {
                    //echo "Looking [", $book['book_id'], "] in [", join(', ', array_keys($biblebooks[$slug])), "]<br>\n";
                    return in_array($book['book_id'], array_keys($biblebooks[$slug]));
                });
                // make book_id to book_data lookup map
                $bible_book_verses_map = [];
                foreach ($bible_book_verses_arr as $book) {
                    //echo "Adding [", $book['book_id'], "]\n";
                    $bible_book_verses_map[$book['book_id']] = $book;
                }
                unset($bible_book_verses_arr); // free memory

                // only keep the books we need
                // sometimes removes 0, other times 66:1-7
                $audio_sets = [];
                foreach ($biblebooks[$slug] as $book_id=>$one) {
                    $audio_sets[$book_id] = $bible_verse_text['audio_filesets'][$book_id];
                }

                $sort_position = $book_order_map[$bible_verse_text['versification']];
                // done with bible_verse_text
                unset($bible_verse_text); // free memory

                // filter bible_verse_text by hlrows
                foreach ($hlrows as $i => $hl) {
                    if (!isset($bible_book_verses_map[$hl->book_id])) {
                        //echo "No content for [$slug][", $hl->book_id, "]<br>\n";
                        continue;
                    }
                    $book_data = $bible_book_verses_map[$hl->book_id];
                    $testament = $book_data['testament'];
                    $book_name = $book_data['name'];
                    $filtered = collect($book_data['verses'])->filter(function ($arr) use ($hl, $chapter_id, $book_name, $query) {
                        // join
                        $inChapter  = $arr[0] == $hl->chapter;
                        if (!$inChapter) {
                            return false;
                        }
                        $afterStart = $arr[1] >= $hl->verse_start;
                        if (!$afterStart) {
                            return false;
                        }
                        $beforEnd   = $arr[2] <= $hl->verse_end;
                        if (!$beforEnd) {
                            return false;
                        }
                        // when chapter_id
                        $chapterOk = $chapter_id ? $chapter_id === $hl->chapter : true;
                        // when query
                        $inQuery = $query ? (
                            // in verse or book name
                            stripos($book_name, $query) !== false || stripos($arr[3], $query) !== false
                          ): true;
                        return $chapterOk && $inQuery;
                    });

                    // add results we haven't already included
                    foreach ($filtered as $arr) {
                        // handles book, chapter, verse_start ordering (and continues to order by verse_end and book_id)
                        // this variable has to be unique per $hl record
                        // FIXME: non sort_by_book support
                        // $sort_by can be any field in user_highlights, so makes it quite tough...
                        $sortable_key = $sort_position . '_'. $arr[0] . '_' . $arr[1] . '_' . $arr[2] . '_'. $hl->book_id;
                        // do we already have this key?
                        if (!isset($keepers[$sortable_key])) {
                            $hl->audio_filesets = $audio_sets[$hl->book_id][$testament];
                            $hl->verse_text = $arr[3]; // cache verse_text, so we don't have to look it up later
                            $hl->book_name = $book_name;
                            $hl->testament = $testament;
                            $hl->content_server_checked = true;
                            $keepers[$sortable_key] = $hl;
                        }
                    } // end filtered foreach
                } // end hlrows foreach
            } // end map foreach

            // handle sort directory
            if ($sort_dir === 'asc') {
                ksort($keepers);
            } else {
                krsort($keepers);
            }
            $highlight_collection = collect($keepers);
            // TODO: cache keeper count, so future limit can bail early

            // support pagination
            $paginate = new LengthAwarePaginator(
                $highlight_collection->forPage($page, $limit),
                $highlight_collection->count(),
                $limit,
                $page,
                // FIXME include query/chapter etc...
                ['path' => url('api/users/' . $user_id . '/highlights?v=4&key=' . $this->key)]
            );

            return fractal($paginate, UserHighlightsTransformer::class);
        }

        // order and paginate this builder
        $highlights = $highlights->orderBy($order_by, $sort_dir)->paginate($limit);

        // get collection
        $highlight_collection = $highlights->getCollection();

        // adapt for pagination
        $highlight_pagination = new IlluminatePaginatorAdapter($highlights);

        return $this->reply(fractal($highlight_collection, UserHighlightsTransformer::class)->paginateWith($highlight_pagination));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @OA\Post(
     *     path="/users/{user_id}/highlights",
     *     tags={"Annotations"},
     *     summary="Create a highlight",
     *     description="",
     *     operationId="v4_internal_highlights.store",
     *     security={{"api_token":{}}},
     *     @OA\Parameter(name="user_id",  in="path", required=true, @OA\Schema(ref="#/components/schemas/User/properties/id")),
     *     @OA\RequestBody(required=true, description="Fields for User Highlight Creation", @OA\MediaType(mediaType="application/json",
     *          @OA\Schema(
     *              @OA\Property(property="bible_id",                  ref="#/components/schemas/Bible/properties/id"),
     *              @OA\Property(property="user_id",                   ref="#/components/schemas/User/properties/id"),
     *              @OA\Property(property="book_id",                   ref="#/components/schemas/Book/properties/id"),
     *              @OA\Property(property="chapter",                   ref="#/components/schemas/Highlight/properties/chapter"),
     *              @OA\Property(property="verse_start",               ref="#/components/schemas/Highlight/properties/verse_start"),
     *              @OA\Property(property="verse_end",               ref="#/components/schemas/Highlight/properties/verse_end"),
     *              @OA\Property(property="highlight_start",           ref="#/components/schemas/Highlight/properties/highlight_start"),
     *              @OA\Property(property="highlighted_words",         ref="#/components/schemas/Highlight/properties/highlighted_words"),
     *              @OA\Property(property="highlighted_color",         ref="#/components/schemas/Highlight/properties/highlighted_color"),
     *          )
     *     )),
     *     @OA\Response(
     *         response=200,
     *         description="successful operation",
     *         @OA\MediaType(mediaType="application/json", @OA\Schema(ref="#/components/schemas/v4_internal_highlights_index"))
     *     )
     * )
     *
     * @return \Illuminate\Http\Response|array
     */
    public function store(Request $request)
    {
        $user = $request->user();
        $request['user_id'] = $user ? $user->id : $request->user_id;

        // Validate Project / User Connection
        $user_is_member = $this->compareProjects($request->user_id, $this->key);
        if (!$user_is_member) {
            return $this->setStatusCode(401)->replyWithError(trans('api.projects_users_not_connected'));
        }

        // Validate Highlight
        $highlight_validation = $this->validateHighlight();
        if (\is_array($highlight_validation)) {
            return $highlight_validation;
        }

        $request->highlighted_color = $this->selectColor($request->highlighted_color);
        $highlight = Highlight::create([
            'user_id'           => $request->user_id,
            'bible_id'          => $request->bible_id,
            'book_id'           => $request->book_id,
            'chapter'           => $request->chapter,
            'verse_start'       => $request->verse_start,
            'verse_end'         => $request->verse_end,
            'highlight_start'   => $request->highlight_start,
            'highlighted_words' => $request->highlighted_words,
            'highlighted_color' => $request->highlighted_color,
        ]);

        $this->handleTags($highlight);
        return $this->reply(fractal($highlight, UserHighlightsTransformer::class)->addMeta(['success' => trans('api.users_highlights_create_200')]));
    }

    /**
     * Update the specified resource in storage.
     *
     * @OA\Put(
     *     path="/users/{user_id}/highlights/{highlight_id}",
     *     tags={"Annotations"},
     *     summary="Alter a highlight",
     *     description="",
     *     operationId="v4_internal_highlights.update",
     *     security={{"api_token":{}}},
     *     @OA\Parameter(name="user_id", in="path", required=true, @OA\Schema(ref="#/components/schemas/User/properties/id")),
     *     @OA\Parameter(name="highlight_id", in="path", required=true, @OA\Schema(ref="#/components/schemas/Highlight/properties/id")),
     *     @OA\RequestBody(required=true, description="Fields for User Highlight Update", @OA\MediaType(mediaType="application/json",
     *          @OA\Schema(
     *              @OA\Property(property="bible_id",                  ref="#/components/schemas/Bible/properties/id"),
     *              @OA\Property(property="book_id",                   ref="#/components/schemas/Book/properties/id"),
     *              @OA\Property(property="chapter",                   ref="#/components/schemas/Highlight/properties/chapter"),
     *              @OA\Property(property="verse_start",               ref="#/components/schemas/Highlight/properties/verse_start"),
     *              @OA\Property(property="highlight_start",           ref="#/components/schemas/Highlight/properties/highlight_start"),
     *              @OA\Property(property="highlighted_words",         ref="#/components/schemas/Highlight/properties/highlighted_words"),
     *              @OA\Property(property="highlighted_color",         ref="#/components/schemas/Highlight/properties/highlighted_color"),
     *          )
     *     )),
     *     @OA\Response(
     *         response=200,
     *         description="successful operation",
     *         @OA\MediaType(mediaType="application/json", @OA\Schema(ref="#/components/schemas/v4_internal_highlights_index"))
     *     )
     * )
     *
     * @param Request $request
     * @param         $user_id
     * @param  int    $id
     *
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $user_id, $id)
    {
        $user = $request->user();
        $user_id = $user ? $user->id : $user_id;
        // Validate Project / User Connection
        $user_is_member = $this->compareProjects($user_id, $this->key);
        if (!$user_is_member) {
            return $this->setStatusCode(401)->replyWithError(trans('api.projects_users_not_connected'));
        }

        // Validate Highlight
        $highlight_validation = $this->validateHighlight();
        if (\is_array($highlight_validation)) {
            return $highlight_validation;
        }

        $highlight = Highlight::where('user_id', $user_id)->where('id', $id)->first();
        if (!$highlight) {
            return $this->setStatusCode(404)->replyWithError(trans('api.users_errors_404_highlights'));
        }

        if ($request->highlighted_color) {
            $color = $this->selectColor($request->highlighted_color);
            $highlight->fill(Arr::add($request->except('highlighted_color', 'project_id'), 'highlighted_color', $color))->save();
        } else {
            $highlight->fill($request->except(['project_id']))->save();
        }

        $this->handleTags($highlight);

        return $this->reply(fractal($highlight, UserHighlightsTransformer::class)->addMeta(['success' => trans('api.users_highlights_update_200')]));
    }

    /**
     * Remove the specified resource from storage.
     *
     * @OA\Delete(
     *     path="/users/{user_id}/highlights/{highlight_id}",
     *     tags={"Annotations"},
     *     summary="Delete a highlight",
     *     description="",
     *     operationId="v4_internal_highlights.delete",
     *     security={{"api_token":{}}},
     *     @OA\Parameter(name="user_id", in="path", required=true, @OA\Schema(ref="#/components/schemas/User/properties/id")),
     *     @OA\Parameter(name="highlight_id", in="path", required=true, @OA\Schema(ref="#/components/schemas/Highlight/properties/id")),
     *     @OA\Response(
     *         response=200,
     *         description="successful operation",
     *         @OA\MediaType(mediaType="application/json", @OA\Schema(type="string"))
     *     )
     * )
     *
     * @param  int $user_id
     * @param  int $id
     *
     * @return array|\Illuminate\Http\Response
     */
    public function destroy(Request $request, $user_id, $id)
    {
        $user = $request->user();
        $user_id = $user ? $user->id : $user_id;
        // Validate Project / User Connection
        $user_is_member = $this->compareProjects($user_id, $this->key);
        if (!$user_is_member) {
            return $this->setStatusCode(401)->replyWithError(trans('api.projects_users_not_connected'));
        }

        $highlight  = Highlight::where('id', $id)->first();
        if (!$highlight) {
            return $this->setStatusCode(404)->replyWithError(trans('api.users_errors_404_highlights'));
        }
        $highlight->delete();

        return $this->reply(['success' => trans('api.users_highlights_delete_200')]);
    }

    /**
     * Display a listing of the resource.
     *
     * @OA\Get(
     *     path="/users/highlights/colors",
     *     tags={"Annotations"},
     *     summary="List a user's highlights colors",
     *     description="List a user's highlights colors",
     *     operationId="v4_internal_highlights.colors",
     *     security={{"api_token":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="successful operation",
     *         @OA\MediaType(mediaType="application/json", @OA\Schema(ref="#/components/schemas/v4_internal_highlights_colors"))
     *     )
     * )
     *
     * @OA\Schema (
     *    type="array",
     *    schema="v4_internal_highlights_colors",
     *    description="The v4 highlights colors index response.",
     *    title="v4_internal_highlights_colors",
     *   @OA\Xml(name="v4_internal_highlights_colors"),
     *    @OA\Items(ref="#/components/schemas/Highlight/properties/highlighted_color"),
     *     )
     *   )
     * )
     *
     */
    public function colors(Request $request)
    {
        // Validate Project / User Connection
        $user = $request->user();
        $user_is_member = $this->compareProjects($user->id, $this->key);

        if (!$user_is_member) {
            return $this->setStatusCode(401)->replyWithError(trans('api.projects_users_not_connected'));
        }
        $colors = Highlight::join('user_highlight_colors', 'user_highlights.highlighted_color', '=', 'user_highlight_colors.id')
            ->where('user_id', $user->id)
            ->select(['user_highlight_colors.*'])
            ->groupBy('user_highlight_colors.id')->get();


        return $this->reply($colors);
    }

    private function validateHighlight()
    {
        $content_config = config('services.content');
        $validator = Validator::make(request()->all(), [
            'bible_id'          => ((request()->method() === 'POST') ? 'required|' : '') . (empty($content_config['url']) ? 'exists:dbp.bibles,id' : 'remote_biblebook_checker'),
            'user_id'           => ((request()->method() === 'POST') ? 'required|' : '') . 'exists:dbp_users.users,id',
            'book_id'           => ((request()->method() === 'POST') ? 'required|' : '') . (empty($content_config['url']) ? 'exists:dbp.books,id' : ''),
            'chapter'           => ((request()->method() === 'POST') ? 'required|' : '') . 'max:150|min:1|integer',
            'verse_start'       => ((request()->method() === 'POST') ? 'required|' : '') . 'max:177|min:1|integer',
            'verse_end'         => ((request()->method() === 'POST') ? 'required|' : '') . 'max:177|min:1|integer',
            'reference'         => 'string',
            'highlight_start'   => ((request()->method() === 'POST') ? 'required|' : '') . 'min:0|integer',
            'highlighted_words' => ((request()->method() === 'POST') ? 'required|' : '') . 'min:1|integer',
            'highlighted_color' => (request()->method() === 'POST') ? 'required' : '',
        ]);
        if ($validator->fails()) {
            return ['errors' => $validator->errors()];
        }
        return true;
    }

    /**
     * @param $color
     *
     * @return mixed
     */
    private function selectColor($color)
    {
        $matches = [];
        $selectedColor = null;
        $highlightedColor = request()->highlighted_color;

        // Try Hex
        preg_match_all('/#[a-zA-Z0-9]{6}/i', $highlightedColor, $matches, PREG_SET_ORDER);
        if (isset($matches[0][0])) {
            $selectedColor = $this->hexToRgb($color);
            $selectedColor['hex'] = str_replace('#', '', $color);
        }

        // Try RGB
        if (!$selectedColor) {
            $expression = '/rgb\((?:\s*\d+\s*,){2}\s*[\d]+\)|rgba\((\s*\d+\s*,){3}[\d\.]+\)/i';
            preg_match_all($expression, $highlightedColor, $matches, PREG_SET_ORDER);
            if (isset($matches[0][0])) {
                $selectedColor = $this->rgbParse($color);
            }
        }

        // Try HSL
        if (!$selectedColor) {
            $expression = '/hsl\(\s*\d+\s*(\s*\,\s*\d+\%){2}\)|hsla\(\s*\d+(\s*,\s*\d+\s*\%){2}\s*\,\s*[\d\.]+\)/i';
            preg_match_all($expression, $highlightedColor, $matches, PREG_SET_ORDER);
            if (isset($matches[0][0])) {
                $selectedColor = $this->hslToRgb($color, 1, 1);
            }
        }

        $highlightColor = HighlightColor::where($selectedColor)->first();
        if (!$highlightColor) {
            $selectedColor['color'] = 'generated_' . unique_random('dbp_users.user_highlight_colors', 'color', '8');
            $selectedColor['hex'] = sprintf('%02x%02x%02x', $selectedColor['red'], $selectedColor['green'], $selectedColor['blue']);
            $highlightColor = HighlightColor::create($selectedColor);
        }
        return $highlightColor->id;
    }

    /**
     * @param $rgb
     *
     * @return array|mixed
     */
    private function rgbParse($rgb)
    {
        $removals = ['rgba', 'rgb', '(', ')'];
        $rgb = str_replace($removals, '', $rgb);
        $rgb = explode(',', $rgb);
        $rgb = ['red' => $rgb[0], 'green' => $rgb[1], 'blue' => $rgb[2], 'opacity' => $rgb[3] ?? 1];
        return $rgb;
    }

    /**
     * @param     $hex
     * @param int $alpha
     *
     * @return mixed
     */
    private function hexToRgb($hex, $alpha = 1)
    {
        $hex            = str_replace('#', '', $hex);
        $length         = \strlen($hex);
        $rgba['red']     = hexdec($length === 6 ? substr($hex, 0, 2) : ($length === 3 ? str_repeat(substr($hex, 0, 1), 2) : 0));
        $rgba['green']   = hexdec($length === 6 ? substr($hex, 2, 2) : ($length === 3 ? str_repeat(substr($hex, 1, 1), 2) : 0));
        $rgba['blue']    = hexdec($length === 6 ? substr($hex, 4, 2) : ($length === 3 ? str_repeat(substr($hex, 2, 1), 2) : 0));
        $rgba['opacity'] = $alpha;
        return $rgba;
    }

    /**
     * @param $hue
     * @param $saturation
     * @param $lightness
     *
     * @return array
     */
    private function hslToRgb($hue, $saturation, $lightness)
    {
        $c = (1 - abs(2 * $lightness - 1)) * $saturation;
        $x = $c * (1 - abs(fmod($hue / 60, 2) - 1));
        $m = $lightness - ($c / 2);
        if ($hue < 60) {
            $red = $c;
            $green = $x;
            $blue = 0;
        } elseif ($hue < 120) {
            $red = $x;
            $green = $c;
            $blue = 0;
        } elseif ($hue < 180) {
            $red = 0;
            $green = $c;
            $blue = $x;
        } elseif ($hue < 240) {
            $red = 0;
            $green = $x;
            $blue = $c;
        } elseif ($hue < 300) {
            $red = $x;
            $green = 0;
            $blue = $c;
        } else {
            $red = $c;
            $green = 0;
            $blue = $x;
        }
        $red = ($red + $m) * 255;
        $green = ($green + $m) * 255;
        $blue = ($blue + $m) * 255;
        return ['red' => floor($red), 'green' => floor($green), 'blue' => floor($blue), 'alpha' => 1];
    }
}
