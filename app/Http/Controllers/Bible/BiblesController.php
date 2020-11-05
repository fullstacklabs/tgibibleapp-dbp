<?php

namespace App\Http\Controllers\Bible;

use App\Models\Bible\Bible;
use App\Models\Bible\BibleBook;
use App\Models\Bible\BibleFilesetType;
use App\Models\Organization\Organization;
use App\Transformers\BibleTransformer;
use App\Transformers\BooksTransformer;
use App\Traits\AccessControlAPI;
use App\Traits\CheckProjectMembership;
use League\Fractal\Pagination\IlluminatePaginatorAdapter;
use App\Transformers\Serializers\DataArraySerializer;
use App\Http\Controllers\APIController;
use App\Http\Controllers\User\BookmarksController;
use App\Http\Controllers\User\HighlightsController;
use App\Http\Controllers\User\NotesController;
use App\Models\User\UserDownload;
use App\Models\Bible\BibleDefault;
use App\Models\Bible\BibleFile;
use App\Models\Bible\BibleFileset;
use App\Models\Bible\BibleFileTimestamp;
use App\Models\Bible\BibleVerse;
use App\Models\Bible\Book;
use App\Models\Language\Language;
use Illuminate\Support\Facades\DB;
use Exception;
use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use ZipArchive;

class BiblesController extends APIController
{
    use AccessControlAPI;
    use CheckProjectMembership;

    /**
     * Display a listing of the bibles.
     *
     * @OA\Get(
     *     path="/bibles",
     *     tags={"Bibles"},
     *     summary="Returns Bibles",
     *     description="The base bible route returning by default bibles and filesets that your key has access to",
     *     operationId="v4_bible.all",
     *     @OA\Parameter(
     *          name="language_code",
     *          in="query",
     *          @OA\Schema(ref="#/components/schemas/Language/properties/iso"),
     *          description="The iso code to filter results by. This will return results only in the language specified.
     *          For a complete list see the `iso` field in the `/languages` route",
     *     ),
     *     @OA\Parameter(
     *          name="organization_id",
     *          in="query",
     *          @OA\Schema(type="string"),
     *          description="The owning organization to return bibles for. For a complete list of ids see the route
     *              `/organizations`."
     *     ),
     *     @OA\Parameter(
     *          name="asset_id",
     *          in="query",
     *          @OA\Schema(type="string"),
     *          description="The asset_id to filter results by. There are three buckets provided `dbp-prod`, `dbp-vid` & `dbs-web`"
     *     ),
     *     @OA\Parameter(
     *          name="media",
     *          in="query",
     *          @OA\Schema(type="string"),
     *          description="Will filter bibles based upon the media type of their filesets"
     *     ),
     *     @OA\Parameter(
     *          name="media_exclude",
     *          in="query",
     *          @OA\Schema(type="string"),
     *          description="Will exclude bibles based upon the media type of their filesets"
     *     ),
     *     @OA\Parameter(
     *          name="size",
     *          in="query",
     *          @OA\Schema(type="string"),
     *          description="Will filter bibles based upon the size type of their filesets"
     *     ),
     *     @OA\Parameter(
     *          name="bitrate",
     *          in="query",
     *          @OA\Schema(type="string",example="64kps"),
     *          description="Will filter bibles based upon the bitrate of their filesets, the current values available are 16kbps & 64kbps"
     *     ),
     *     @OA\Parameter(
     *          name="size_exclude",
     *          in="query",
     *          @OA\Schema(type="string"),
     *          description="Will exclude bibles based upon the size type of their filesets"
     *     ),
     *     @OA\Parameter(
     *          name="show_all",
     *          in="query",
     *          @OA\Schema(type="boolean"),
     *          description="Will show all entries"
     *     ),
     *     @OA\Parameter(ref="#/components/parameters/page"),
     *     @OA\Parameter(ref="#/components/parameters/limit"),
     *     @OA\Response(
     *         response=200,
     *         description="successful operation",
     *         @OA\MediaType(mediaType="application/json", @OA\Schema(ref="#/components/schemas/v4_bible.all")),
     *         @OA\MediaType(mediaType="application/xml",  @OA\Schema(ref="#/components/schemas/v4_bible.all")),
     *         @OA\MediaType(mediaType="text/x-yaml",      @OA\Schema(ref="#/components/schemas/v4_bible.all")),
     *         @OA\MediaType(mediaType="text/csv",         @OA\Schema(ref="#/components/schemas/v4_bible.all"))
     *     )
     * )
     *
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View|mixed
     */
    public function index()
    {
        $language_code      = checkParam('language_id|language_code');
        $organization_id    = checkParam('organization_id');
        $country            = checkParam('country');
        $asset_id           = checkParam('bucket|bucket_id|asset_id') ?? config('filesystems.disks.s3_fcbh.bucket');
        $media              = checkParam('media');
        $media_exclude      = checkParam('media_exclude');
        $size               = checkParam('size');
        $size_exclude       = checkParam('size_exclude');
        $bitrate            = checkParam('bitrate');
        $show_restricted    = checkBoolean('show_all|show_restricted');
        $limit      = checkParam('limit');
        $page       = checkParam('page');

        if ($media) {
            $media_types = BibleFilesetType::select('set_type_code')->get();
            $media_type_exists = $media_types->where('set_type_code', $media);
            if ($media_type_exists->isEmpty()) {
                return $this->setStatusCode(404)->replyWithError('media type not found. must be one of ' . $media_types->pluck('set_type_code')->implode(','));
            }
        }

        $access_control = (!$show_restricted) ? $this->accessControl($this->key) : (object) ['string' => null, 'hashes' => null];
        $organization = $organization_id ? Organization::where('id', $organization_id)->orWhere('slug', $organization_id)->first() : null;
        $cache_params = [$language_code, $organization, $country, $asset_id, $access_control->string, $media, $media_exclude, $size, $size_exclude, $bitrate, $limit, $page];
        $bibles = cacheRemember('bibles', $cache_params, now()->addDay(), function () use ($language_code, $organization, $country, $asset_id, $access_control, $media, $media_exclude, $size, $size_exclude, $bitrate, $show_restricted, $limit, $page) {
            $bibles = Bible::when(!$show_restricted, function ($query) use ($access_control, $asset_id, $media, $media_exclude, $size, $size_exclude, $bitrate) {
                $query->withRequiredFilesets([
                    'access_control' => $access_control,
                    'asset_id'       => $asset_id,
                    'media'          => $media,
                    'media_exclude'  => $media_exclude,
                    'size'           => $size,
                    'size_exclude'   => $size_exclude,
                    'bitrate'        => $bitrate
                ]);
            })
                ->leftJoin('bible_translations as ver_title', function ($join) {
                    $join->on('ver_title.bible_id', '=', 'bibles.id')->where('ver_title.vernacular', 1);
                })
                ->leftJoin('bible_translations as current_title', function ($join) {
                    $join->on('current_title.bible_id', '=', 'bibles.id');
                    if (isset($GLOBALS['i18n_id'])) {
                        $join->where('current_title.language_id', '=', $GLOBALS['i18n_id']);
                    }
                })
                ->leftJoin('languages as languages', function ($join) {
                    $join->on('languages.id', '=', 'bibles.language_id');
                })
                ->leftJoin('language_translations as language_autonym', function ($join) {
                    $join->on('language_autonym.language_source_id', '=', 'bibles.language_id')
                        ->on('language_autonym.language_translation_id', '=', 'bibles.language_id')
                        ->orderBy('priority', 'desc');
                })
                ->leftJoin('language_translations as language_current', function ($join) {
                    $join->on('language_current.language_source_id', '=', 'bibles.language_id')
                        ->orderBy('priority', 'desc');
                    if (isset($GLOBALS['i18n_id'])) {
                        $join->where('language_current.language_translation_id', '=', $GLOBALS['i18n_id']);
                    }
                })
                ->filterByLanguage($language_code)
                ->when($country, function ($q) use ($country) {
                    $q->whereHas('country', function ($query) use ($country) {
                        $query->where('countries.id', $country);
                    });
                })
                ->when($organization, function ($q) use ($organization) {
                    $q->whereHas('organizations', function ($q) use ($organization) {
                        $q->where('organization_id', $organization->id);
                    })->orWhereHas('links', function ($q) use ($organization) {
                        $q->where('organization_id', $organization->id);
                    });
                })
                ->select(
                    \DB::raw(
                        'MIN(current_title.name) as ctitle,
                        MIN(ver_title.name) as vtitle,
                        MIN(bibles.language_id) as language_id,
                        MIN(languages.iso) as iso,
                        MIN(bibles.date) as date,
                        MIN(language_autonym.name) as language_autonym,
                        MIN(language_current.name) as language_current,
                        MIN(bibles.priority) as priority,
                        MIN(bibles.id) as id'
                    )
                )
                ->orderBy('bibles.priority', 'desc')->groupBy('bibles.id');

            if ($page) {
                $bibles  = $bibles->paginate($limit);
                return $this->reply(fractal($bibles->getCollection(), BibleTransformer::class)->paginateWith(new IlluminatePaginatorAdapter($bibles)));
            }

            $bibles = $bibles->limit($limit)->get();
            return fractal($bibles, new BibleTransformer(), new DataArraySerializer());
        });

        return $this->reply($bibles);
    }

    /**
     * Description:
     * Display the bible meta data for the specified ID.
     *
     * @OA\Get(
     *     path="/bibles/{id}",
     *     tags={"Bibles"},
     *     summary="",
     *     description="",
     *     operationId="v4_bible.one",
     *     @OA\Parameter(name="id",in="path",required=true,@OA\Schema(ref="#/components/schemas/Bible/properties/id")),
     *     @OA\Parameter(
     *          name="asset_id",
     *          in="query",
     *          @OA\Schema(type="string"),
     *          description="The asset_id to filter results by. There are three buckets provided `dbp-prod`, `dbp-vid` & `dbs-web`"
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="successful operation",
     *         @OA\MediaType(mediaType="application/json", @OA\Schema(ref="#/components/schemas/v4_bible.one")),
     *         @OA\MediaType(mediaType="application/xml",  @OA\Schema(ref="#/components/schemas/v4_bible.one")),
     *         @OA\MediaType(mediaType="text/x-yaml",      @OA\Schema(ref="#/components/schemas/v4_bible.one"))
     *     )
     * )
     *
     * @param  string $id
     *
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        $asset_id = checkParam('asset_id');
        $access_control = $this->accessControl($this->key);
        $cache_params = [$id, $access_control->string];
        $bible = cacheRemember('bibles_show', $cache_params, now()->addDay(), function () use ($access_control, $id) {
            return Bible::with([
                'translations', 'books.book', 'links', 'organizations.logo', 'organizations.logoIcon', 'organizations.translations', 'alphabet.primaryFont', 'equivalents',
                'filesets' => function ($query) use ($access_control) {
                    $query->whereIn('bible_filesets.hash_id', $access_control->hashes);
                }
            ])->find($id);
        });

        if (!$bible || !sizeof($bible->filesets)) {
            return $this->setStatusCode(404)->replyWithError(trans('api.bibles_errors_404', ['bible_id' => $id]));
        }

        if ($asset_id) {
            $bible->filesets = $bible->filesets->filter(function ($fileset) use ($asset_id) {
                return in_array($fileset->asset_id, explode(',', $asset_id));
            });
        }

        return $this->reply(fractal($bible, new BibleTransformer(), $this->serializer));
    }

    public function showName($id, $language)
    {
        $bible = cacheRemember('bible_with_translations', [$id], now()->addDay(), function () use ($id) {
            return Bible::whereId($id)->with(['translations'])->first();
        });
        if (!$bible) {
            return $this->setStatusCode(404)->replyWithError(trans('api.bibles_errors_404', ['bible_id' => $id]));
        }
        $ctitle = optional($bible->translations->where('language_id', $language)->first())->name;
        $vtitle = optional($bible->vernacularTranslation)->name;

        return $this->reply($vtitle ? $vtitle : $ctitle);
    }

    public function verseSearch($bible_id, $query)
    {
        // query plan based on
        // 2k bibles
        // 60k biblebooks
        // 1.5k filesets
        // 6k connections
        // 16m verses
        $q = Bible::join('bible_books', 'bible_books.bible_id', 'bibles.id')
          ->where('bibles.id', '=', $bible_id)
          ->join('bible_fileset_connections as connection', 'connection.bible_id', 'bibles.id')
          ->join('bible_filesets as filesets', function ($join) {
            $join->on('filesets.hash_id', '=', 'connection.hash_id');
          })
          ->where('filesets.set_type_code', 'text_plain')
          ->join('bible_verses as bible_verses', function ($join) {
            $join->on('connection.hash_id', '=', 'bible_verses.hash_id');
          })
          ->where('bible_verses.verse_text', 'like', '%' . $query . '%')
          ->select(['bible_books.book_id', 'chapter', 'verse_start', 'verse_end']);
        return $this->reply($q->get());
    }

    public function BibleVerses($bible_id)
    {
        // This query plan was based on the following query having 3-31k records
        $q = Bible::where('bibles.id', '=', $bible_id)
          ->join('bible_books', 'bible_books.bible_id', 'bibles.id')
          ->leftjoin('books', 'books.id', 'bible_books.book_id')
          ->join('bible_fileset_connections as connection', 'connection.bible_id', 'bible_books.bible_id')
          ->join('bible_filesets as filesets', function ($join) {
            $join->on('filesets.hash_id', '=', 'connection.hash_id');
          })
          ->where('filesets.set_type_code', 'text_plain')
          ->join('bible_verses as bible_verses', function ($join) {
            $join->on('connection.hash_id', '=', 'bible_verses.hash_id')
              ->where('bible_verses.book_id', '=', DB::raw('bible_books.book_id'));
          })
          ->select(['bibles.versification', 'bible_books.book_id',
            'chapter', 'verse_start', 'verse_end', 'verse_text', 'books.book_testament',
            'bible_books.name']);
        $bible = Bible::where('id', $bible_id)->first();
        $biblebooks = $bible->books()->get();
        $testament_audiosets = array();
        foreach($biblebooks as $bb) {
            $testament = $bb->book->book_testament;
            $book_id = $bb->book_id;
            $audio_fileset_types = collect([
              'audio_stream_drama', 'audio_drama', 'audio_stream', 'audio'
            ]);
            $bible = Bible::where('id', $bible_id)->first();
            $filesets = $bible->filesets;

            $audio_filesets = $filesets->filter(function ($fs) {
                return Str::contains($fs->set_type_code, 'audio');
            });
            // foreach audio fs type see if it's available
            $available_filesets = $audio_fileset_types->map(function ($fileset) use ($audio_filesets, $testament) {
                return $this->getFileset($audio_filesets, $fileset, $testament);
            })->filter(function ($item) {
                return $item;
            })->toArray();
            if (!isset($testament_audiosets[$book_id])) {
                $testament_audiosets[$book_id] = array();
            }
            $testament_audiosets[$book_id][$testament] = array_values($available_filesets);
        }

        // one level of nesting by book could save some more bandwidth
        // saves about 700k (4.9mb => 4.2mb)
        $compressed = array();
        $books = array();
        foreach($q->get() as $row) {
          $book_key = $row->book_id . '_'. $row->book_testament . '_'. $row->name;
          if (!isset($books[$book_key])) {
            $books[$book_key] = array(
              'book_id'   => $row->book_id,
              'name'      => $row->name,
              'testament' => $row->book_testament,
              'verses'    => array()
            );
          }
          $books[$book_key]['verses'][] = array(
            $row->chapter,
            $row->verse_start,
            $row->verse_end,
            $row->verse_text,
          );
        }
        $wrap = array(
          'versification'  => $bible->versification,
          'audio_filesets' => $testament_audiosets,
          'books'          => array_values($books), // drop unique keying
        );
        return $this->reply($wrap);
    }

    /**
     *
     * @OA\Get(
     *     path="/bibles/{id}/book",
     *     tags={"Bibles"},
     *     summary="Returns a list of translated book names and general information for the given Bible",
     *     description="The actual list of books may vary from fileset to fileset. For example, a King James Fileset may
     *          contain deuterocanonical books that are missing from one of it's sibling filesets nested within the bible
     *          parent.",
     *     operationId="v4_bible.books",
     *     @OA\Parameter(name="id",in="path",required=true,@OA\Schema(ref="#/components/schemas/Bible/properties/id")),
     *     @OA\Parameter(name="book_id",in="query", description="The book id. For a complete list see the `book_id` field in the `/bibles/books` route.",@OA\Schema(ref="#/components/schemas/Book/properties/id")),
     *     @OA\Parameter(name="testament",in="query",@OA\Schema(ref="#/components/schemas/Book/properties/book_testament")),
     *     @OA\Parameter(
     *          name="verify_content",
     *          in="query",
     *          @OA\Schema(type="boolean"),
     *          description="Filter all the books that have content"
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="successful operation",
     *         @OA\MediaType(mediaType="application/json", @OA\Schema(ref="#/components/schemas/v4_bible.books")),
     *         @OA\MediaType(mediaType="application/xml", @OA\Schema(ref="#/components/schemas/v4_bible.books")),
     *         @OA\MediaType(mediaType="text/x-yaml", @OA\Schema(ref="#/components/schemas/v4_bible.books")),
     *         @OA\MediaType(mediaType="text/csv", @OA\Schema(ref="#/components/schemas/v4_bible.books"))
     *     )
     * )
     *
     * @param string $bible_id
     * @param string|null $book_id
     *
     * @return APIController::reply()
     */
    public function books($bible_id, $book_id = null)
    {
        $book_id   = checkParam('book_id', false, $book_id);
        $testament = checkParam('testament');

        $asset_id = checkParam('asset_id') ?? config('filesystems.disks.s3_fcbh.bucket');
        $verify_content = checkBoolean('verify_content');

        $bible = Bible::find($bible_id);
        $access_control = $this->accessControl($this->key);
        $cache_params = [$bible_id, $access_control->string, $verify_content, $asset_id];
        $bible = cacheRemember('bible_books_bible', $cache_params, now()->addDay(), function () use ($access_control, $bible_id, $asset_id, $verify_content) {
            if (!$verify_content) {
                return Bible::find($bible_id);
            }

            return  Bible::with([
                'filesets' => function ($query) use ($access_control, $asset_id) {
                    $query->whereIn('bible_filesets.hash_id', $access_control->hashes);
                    if ($asset_id) {
                        $query->whereIn('bible_filesets.asset_id', explode(',', $asset_id));
                    }
                }
            ])->find($bible_id);
        });


        if (!$bible) {
            return $this->setStatusCode(404)->replyWithError(trans('api.bibles_errors_404', ['bible_id' => $bible_id]));
        }

        $cache_params = [$bible_id, $testament, $book_id];
        $books = cacheRemember('bible_books_books', $cache_params, now()->addDay(), function () use ($bible_id, $testament, $book_id, $bible) {
            $books = BibleBook::where('bible_id', $bible_id)
                ->when($testament, function ($query) use ($testament) {
                    $query->where('book_testament', $testament);
                })
                ->when($book_id, function ($query) use ($book_id) {
                    $query->where('book_id', $book_id);
                })
                ->get()->sortBy('book.' . $bible->versification . '_order')
                ->filter(function ($item) {
                    return $item->book;
                })->flatten();
            return $books;
        });

        if ($verify_content) {
            $cache_params = [$bible_id, $access_control->string, $verify_content, $asset_id, $testament, $book_id];
            $books = cacheRemember('bible_books_books_verified', $cache_params, now()->addDay(), function () use ($books, $bible) {
                $book_controller = new BooksController();
                $active_books = [];
                foreach ($bible->filesets as $fileset) {
                    $books_fileset = $book_controller->getActiveBooksFromFileset($fileset->id, $fileset->asset_id, $fileset->set_type_code)->pluck('id');
                    $active_books = $this->processActiveBooks($books_fileset, $active_books, $fileset->set_type_code);
                }

                return $books->map(function ($book) use ($active_books) {
                    if (isset($active_books[$book->book_id])) {
                        $book->content_types = array_unique($active_books[$book->book_id]);
                    }
                    return $book;
                })->filter(function ($book) {
                    return $book->content_types;
                });
            });
        }

        return $this->reply(fractal($books, new BooksTransformer));
    }

    public function bookSearch($query)
    {
        $books = BibleBook::where('name', 'like', '%'.$query.'%')->select(['bible_id', 'book_id'])->get();
        // expose bible_id
        $map = array();
        foreach($books as $book) {
          $book->setHidden([])->setVisible(['bible_id', 'book_id']);
          // group this way to minimize bandwidth for large result sets
          if (!isset($map[$book->bible_id])) {
            $map[$book->bible_id] = array();
          }
          $map[$book->bible_id][] = $book->book_id;
        }
        return $this->reply($map);
    }

    public function bookVerse($bible_id, $book_id, $chapter, $verse_start)
    {
        $bible = Bible::where('id', $bible_id)->first();
        $fileset = BibleFileset::join(
          'bible_fileset_connections as connection',
          'connection.hash_id',
          'bible_filesets.hash_id'
        )
            ->where('bible_filesets.set_type_code', 'text_plain')
            ->where('connection.bible_id', $bible->id)
            ->first();
              if (!$fileset) {
                  return '';
              }
              $verses = BibleVerse::withVernacularMetaData($bible)
            ->where('hash_id', $fileset->hash_id)
            ->where('bible_verses.book_id', $book_id)
            ->where('verse_start', $verse_start)
            ->where('chapter', $chapter)
            ->orderBy('verse_start')
            ->select(['bible_verses.verse_text'])
            ->get()
            ->pluck('verse_text');

        return $this->reply(implode(' ', $verses->toArray()));
    }

    private function processActiveBooks($books, $active_books, $set_type_code)
    {
        foreach ($books as $book) {
            $active_books[$book] =  $active_books[$book] ?? [];
            $active_books[$book][] = $set_type_code;
        }
        return $active_books;
    }

    /**
     * @OA\Get(
     *     path="/bibles/defaults/types",
     *     tags={"Bibles"},
     *     summary="Available bible defaults per language code",
     *     description="Available bible defaults per language code",
     *     operationId="v4_bible.defaults",
     *     @OA\Parameter(
     *          name="language_code",
     *          in="query",
     *          @OA\Schema(type="string",example="en"),
     *          description="The language code to filter results by"
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="successful operation",
     *         @OA\MediaType(mediaType="application/json", @OA\Schema(ref="#/components/schemas/v4_bibles_defaults")),
     *         @OA\MediaType(mediaType="application/xml",  @OA\Schema(ref="#/components/schemas/v4_bibles_defaults")),
     *         @OA\MediaType(mediaType="text/x-yaml",      @OA\Schema(ref="#/components/schemas/v4_bibles_defaults")),
     *         @OA\MediaType(mediaType="text/csv",         @OA\Schema(ref="#/components/schemas/v4_bibles_defaults"))
     *     )
     * )
     *
     * @OA\Schema (
     *    type="object",
     *    schema="v4_bibles_defaults",
     *    description="The bible defaults",
     *    title="v4_bibles_defaults",
     *    @OA\Xml(name="v4_bibles_defaults"),
     *    @OA\Property(property="en", type="object",
     *          @OA\Property(property="video", ref="#/components/schemas/Bible/properties/id"),
     *          @OA\Property(property="audio", ref="#/components/schemas/Bible/properties/id")
     *     )
     *   )
     * )
     *
     */
    public function defaults()
    {
        $language_code = checkParam('language_code');
        $defaults = BibleDefault::when($language_code, function ($q) use ($language_code) {
            $q->where('language_code', $language_code);
        })
            ->get();
        $result = [];
        foreach ($defaults as $default) {
            if (!isset($result[$default->language_code])) {
                $result[$default->language_code] = [];
            }
            $result[$default->language_code][$default->type] = $default->bible_id;
        }
        return $this->reply($result);
    }

    /**
     * @OA\Get(
     *     path="/bibles/{bible_id}/copyright",
     *     tags={"Bibles"},
     *     summary="Bible Copyright information",
     *     description="All bible fileset's copyright information and organizational connections",
     *     operationId="v4_bible.copyright",
     *     @OA\Parameter(
     *          name="bible_id",
     *          in="path",
     *          required=true,
     *          @OA\Schema(ref="#/components/schemas/Bible/properties/id"),
     *          description="The Bible ID to retrieve the copyright information for"
     *     ),
     *     @OA\Parameter(
     *          name="iso",
     *          in="query",
     *          @OA\Schema(ref="#/components/schemas/Language/properties/iso", default="eng"),
     *          description="The iso code to filter organization translations by. For a complete list see the `iso` field in the `/languages` route."
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="The requested bible copyrights",
     *         @OA\MediaType(mediaType="application/json", @OA\Schema(ref="#/components/schemas/v4_bible.copyright")),
     *         @OA\MediaType(mediaType="application/xml", @OA\Schema(ref="#/components/schemas/v4_bible.copyright")),
     *         @OA\MediaType(mediaType="text/csv", @OA\Schema(ref="#/components/schemas/v4_bible.copyright")),
     *         @OA\MediaType(mediaType="text/x-yaml", @OA\Schema(ref="#/components/schemas/v4_bible.copyright"))
     *     )
     * )
     *
     * @OA\Schema (
     *   type="array",
     *   schema="v4_bible.copyright",
     *   title="Bible copyrights response",
     *   description="The v4 bible copyrights response.",
     *   @OA\Items(ref="#/components/schemas/v4_bible_filesets.copyright")
     * )
     *
     */
    public function copyright($bible_id)
    {
        $bible = Bible::whereId($bible_id)->first();
        if (!$bible) {
            return $this->setStatusCode(404)->replyWithError('Bible not found');
        }

        $iso = checkParam('iso') ?? 'eng';

        $cache_params = [$bible_id, $iso];
        $copyrights = cacheRemember('bible_copyrights', $cache_params, now()->addDay(), function () use ($bible, $iso) {
            $language_id = optional(Language::where('iso', $iso)->select('id')->first())->id;
            return $bible->filesets->map(function ($fileset) use ($language_id) {
                return BibleFileset::where('hash_id', $fileset->hash_id)->with([
                    'copyright.organizations.logos',
                    'copyright.organizations.translations' => function ($q) use ($language_id) {
                        $q->where('language_id', $language_id);
                    }
                ])->select(['hash_id', 'id', 'asset_id', 'set_type_code as type', 'set_size_code as size'])->first();
            });
        });

        return $this->reply($copyrights);
    }

    /**
     * @OA\Get(
     *     path="/bibles/{bible_id}/chapter",
     *     tags={"Bibles"},
     *     summary="Bible chapter information",
     *     description="All bible chapter information",
     *     operationId="v4_bible.chapter",
     *     security={{"api_token":{}}},
     *     @OA\Parameter(
     *          name="bible_id",
     *          in="path",
     *          required=true,
     *          @OA\Schema(ref="#/components/schemas/Bible/properties/id"),
     *          description="The Bible ID to retrieve the chapter information for"
     *     ),
     *     @OA\Parameter(
     *          name="book_id",
     *          in="query",
     *          required=true,
     *          description="Will filter the results by the given book. For a complete list see the `book_id` field in the `/bibles/books` route.",
     *          @OA\Schema(ref="#/components/schemas/Book/properties/id")
     *     ),
     *     @OA\Parameter(
     *          name="chapter",
     *          in="query",
     *          required=true,
     *          description="Will filter the results by the given chapter",
     *          @OA\Schema(ref="#/components/schemas/BibleFile/properties/chapter_start")
     *     ),
     *     @OA\Parameter(
     *          name="zip",
     *          in="query",
     *          @OA\Schema(type="boolean", default=false),
     *          description="Download the given data and package as a compressed file"
     *     ),
     *     @OA\Parameter(
     *          name="copyrights",
     *          in="query",
     *          @OA\Schema(type="boolean", default=false),
     *          description="Will include copyright data"
     *     ),
     *     @OA\Parameter(
     *          name="drama",
     *          in="query",
     *          @OA\Schema(type="boolean"),
     *          description="If sent, will determine whether drama or non-drama audio is sent. If  this parameter is not present drama and non-drama will be retrieved"
     *     ),
     *     @OA\Parameter(
     *          name="iso",
     *          in="query",
     *          @OA\Schema(ref="#/components/schemas/Language/properties/iso", default="eng"),
     *          description="The iso code to filter copyrights organization translations by. For a complete list see the `iso` field in the `/languages` route."
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="The requested bible chapter",
     *         @OA\MediaType(mediaType="application/json", @OA\Schema(ref="#/components/schemas/v4_bible.chapter")),
     *         @OA\MediaType(mediaType="application/xml", @OA\Schema(ref="#/components/schemas/v4_bible.chapter")),
     *         @OA\MediaType(mediaType="text/csv", @OA\Schema(ref="#/components/schemas/v4_bible.chapter")),
     *         @OA\MediaType(mediaType="text/x-yaml", @OA\Schema(ref="#/components/schemas/v4_bible.chapter"))
     *     )
     * )
     *
     * @OA\Schema (
     *   type="object",
     *   schema="v4_bible.chapter",
     *   title="Bible chapter response",
     *   description="The v4 bible chapter response.",
     *   @OA\Property(property="annotations", type="object",
     *      @OA\Property(property="bookmarks", ref="#/components/schemas/v4_user_bookmarks/properties/data"),
     *      @OA\Property(property="highlights", ref="#/components/schemas/v4_highlights_index/properties/data"),
     *      @OA\Property(property="notes", ref="#/components/schemas/v4_notes_index/properties/data")
     *   ),
     *   @OA\Property(property="bible_id", ref="#/components/schemas/Bible/properties/id"),
     *   @OA\Property(property="book_id", ref="#/components/schemas/Book/properties/id"),
     *   @OA\Property(property="chapter", ref="#/components/schemas/BibleFile/properties/chapter_start"),
     *   @OA\Property(property="copyrights",  type="array",
     *      @OA\Items(
     *          @OA\Property(property="id", ref="#/components/schemas/BibleFileset/properties/id"),
     *          @OA\Property(property="asset_id", ref="#/components/schemas/BibleFileset/properties/asset_id"),
     *          @OA\Property(property="type", ref="#/components/schemas/BibleFileset/properties/set_type_code"),
     *          @OA\Property(property="size", ref="#/components/schemas/BibleFileset/properties/set_size_code"),
     *          @OA\Property(property="copyright", ref="#/components/schemas/v4_bible_filesets.copyright")
     *      )
     *   ),
     *   @OA\Property(property="filesets", type="object",
     *      @OA\Property(property="video", type="object",
     *          @OA\Property(property="gospel_films", ref="#/components/schemas/v4_bible_filesets.show/properties/data"),
     *          @OA\Property(property="jesus_films", ref="#/components/schemas/v4_bible_chapter_jesus_films")
     *      ),
     *      @OA\Property(property="audio", type="object",
     *         @OA\Property(property="drama", ref="#/components/schemas/v4_bible.fileset_chapter"),
     *         @OA\Property(property="non_drama", ref="#/components/schemas/v4_bible.fileset_chapter"),
     *      ),
     *      @OA\Property(property="text", type="object",
     *         @OA\Property(property="verses", ref="#/components/schemas/v4_bible_filesets_chapter/properties/data"),
     *         @OA\Property(property="formatted_verses", type="string"),
     *      ),
     *   ),
     *   @OA\Property(property="timestamps", type="object",
     *       @OA\Property(property="drama", ref="#/components/schemas/v4_bible.fileset_chapter_timestamp"),
     *       @OA\Property(property="non_drama", ref="#/components/schemas/v4_bible.fileset_chapter_timestamp"),
     *   )
     * )
     * @OA\Schema(
     *      type="object",
     *      schema="v4_bible.fileset_chapter",
     *      @OA\Property(property="book_id",        ref="#/components/schemas/BibleFile/properties/book_id"),
     *      @OA\Property(property="book_name",      ref="#/components/schemas/BookTranslation/properties/name"),
     *      @OA\Property(property="chapter_start",  ref="#/components/schemas/BibleFile/properties/chapter_start"),
     *      @OA\Property(property="chapter_end",    ref="#/components/schemas/BibleFile/properties/chapter_end"),
     *      @OA\Property(property="verse_start",    ref="#/components/schemas/BibleFile/properties/verse_start"),
     *      @OA\Property(property="verse_end",      ref="#/components/schemas/BibleFile/properties/verse_end"),
     *      @OA\Property(property="thumbnail",      type="string", description="The image url", maxLength=191),
     *      @OA\Property(property="timestamp",      ref="#/components/schemas/BibleFileTimestamp/properties/timestamp"),
     *      @OA\Property(property="path",           ref="#/components/schemas/BibleFile/properties/file_name"),
     *      @OA\Property(property="duration",       ref="#/components/schemas/BibleFile/properties/duration"),
     *      @OA\Property(property="fileset", type="object",
     *          @OA\Property(property="id", ref="#/components/schemas/BibleFileset/properties/id"),
     *          @OA\Property(property="asset_id", ref="#/components/schemas/BibleFileset/properties/asset_id"),
     *          @OA\Property(property="type", ref="#/components/schemas/BibleFileset/properties/set_type_code"),
     *          @OA\Property(property="size", ref="#/components/schemas/BibleFileset/properties/set_size_code"),
     *      )
     * )
     * @OA\Schema(
     *      type="array",
     *      schema="v4_bible.fileset_chapter_timestamp",
     *      @OA\Items(
     *          @OA\Property(property="timestamp",        ref="#/components/schemas/BibleFileTimestamp/properties/timestamp"),
     *          @OA\Property(property="verse_start",      ref="#/components/schemas/BibleFile/properties/verse_start")
     *      )
     * )
     * @OA\Schema(
     *      type="array",
     *      schema="v4_bible_chapter_jesus_films",
     *      @OA\Items(
     *          @OA\Property(property="component_id", type="string"),
     *          @OA\Property(property="verses", type="array", @OA\Items(type="integer")),
     *          @OA\Property(property="meta", type="object",
     *                @OA\Property(property="thumbnail", type="string"),
     *                @OA\Property(property="thumbnail_high", type="string"),
     *                @OA\Property(property="title", type="string"),
     *                @OA\Property(property="shortDescription", type="string"),
     *                @OA\Property(property="longDescription", type="string"),
     *                @OA\Property(property="file_name", type="string")
     *          )
     *      )
     * )
     */
    public function chapter(Request $request, $bible_id)
    {
        $bible = cacheRemember('v4_chapter_bible', [$bible_id], now()->addDay(), function () use ($bible_id) {
            $access_control = $this->accessControl($this->key);
            return Bible::with([
                'filesets' => function ($query) use ($access_control) {
                    $query->whereIn('bible_filesets.hash_id', $access_control->hashes);
                }
            ])->whereId($bible_id)->first();
        });

        if (!$bible) {
            return $this->setStatusCode(404)->replyWithError('Bible not found');
        }

        $user = $request->user();
        $show_annotations = !empty($user);

        // Validate Project / User Connection
        if ($show_annotations && !$this->compareProjects($user->id, $this->key)) {
            return $this->setStatusCode(401)->replyWithError(trans('api.projects_users_not_connected'));
        }


        $book_id = checkParam('book_id', true);
        $chapter = checkParam('chapter', true);

        $zip = checkBoolean('zip');

        $copyrights = checkBoolean('copyrights');
        $drama = checkParam('drama') ?? 'all';
        if ($drama !== 'all') {
            $drama = checkBoolean('drama') ? 'drama' : 'non-drama';
        }

        $book = cacheRemember('v4_chapter_book', [$book_id], now()->addDay(), function () use ($book_id) {
            return Book::whereId($book_id)->first();
        });

        if (!$book) {
            return $this->setStatusCode(404)->replyWithError('Book not found');
        }

        $result = (object) [];

        if ($show_annotations) {
            $highlights_controller = new HighlightsController();
            $bookmarks_controller = new BookmarksController();
            $notes_controller = new NotesController();
            $request->request->add(['bible_id' => $bible_id]);
            $result->annotations = (object) [
                'highlights' => $highlights_controller->index($request, $user->id)->original['data'],
                'bookmarks' => $bookmarks_controller->index($request, $user->id)->original['data'],
                'notes' => $notes_controller->index($request, $user->id)->original['data'],
            ];
        }
        $result->bible_id = $bible->id;
        $result->book_id = $book_id;
        $result->chapter = $chapter;

        if ($copyrights) {
            $result->copyrights = cacheRemember('v4_chapter_copyrights', [$bible->id], now()->addDay(), function () use ($bible) {
                return $this->copyright($bible->id)->original;
            });
        }

        $cache_params = [$bible_id, $book_id, $chapter, $zip, $drama];

        $chapter_filesets = cacheRemember('v4_chapter_filesets', $cache_params, now()->addHours(12), function () use ($drama, $zip, $bible, $book, $bible_id, $book_id, $chapter, $user) {
            $chapter_filesets = (object) [
                'video' => (object) ['gospel_films' => [], 'jesus_films' => []],
                'audio' => (object) [],
                'text' => (object) [],
                'timestamps' => (object) [],
            ];

            if ($zip) {
                $chapter_filesets->downloads = [];
            }

            $text_plain = $this->getFileset($bible->filesets, 'text_plain', $book->book_testament);
            if ($text_plain) {
                $text_controller = new TextController();
                $verses = $text_controller->index($text_plain->id, $book_id, $chapter)->original['data'] ?? [];
                if (!empty($verses)) {
                    $chapter_filesets->text->verses = $verses;
                }
            }

            $text_format = $this->getFileset($bible->filesets, 'text_format', $book->book_testament);
            if ($text_format) {
                $fileset_controller = new BibleFileSetsController();
                $formatted_verses = $fileset_controller->show($text_format->id, $text_format->asset_id, $text_format->set_type_code, 'v4_chapter_filesets_show')->original['data'] ?? [];
                if (!empty($formatted_verses)) {
                    $path = $formatted_verses[0]['path'];
                    $cache_params = [$bible_id, $book_id, $chapter, $text_format->id];
                    $formatted_verses = cacheRemember('bible_chapter_formatted_verses', $cache_params, now()->addDay(), function () use ($path) {
                        try {
                            $client = new Client();
                            $html = $client->get($path);
                            $body = $html->getBody() . '';
                            return $body;
                        } catch (Exception $e) {
                            return false;
                        }
                    });
                    if ($formatted_verses) {
                        $chapter_filesets->text->formatted_verses = $formatted_verses;
                    }
                }
            }

            $drama_all = $drama === 'all';

            if ($drama === 'drama' || $drama_all) {
                $chapter_filesets = $this->getAudioFilesetData($chapter_filesets, $bible, $book, $chapter, 'audio_drama', 'drama', $zip, 'audio', 'non_drama', !$drama_all && $zip);

                if (!empty($user) && $zip && isset($chapter_filesets->audio->drama)) {
                    $fileset_id = $chapter_filesets->audio->drama['fileset']['id'];

                    cacheRemember('v4_user_download', [$user->id, $fileset_id], now()->addDay(), function () use ($user, $fileset_id) {
                        UserDownload::create([
                    'user_id'        => $user->id,
                    'fileset_id'     => $fileset_id,
                  ]);
                        return true;
                    });
                }
            }

            if ($drama === 'non-drama' || $drama_all) {
                $chapter_filesets = $this->getAudioFilesetData($chapter_filesets, $bible, $book, $chapter, 'audio', 'non_drama', $zip, 'audio_drama', 'drama', !$drama_all && $zip);

                if (!empty($user) && $zip && isset($chapter_filesets->audio->non_drama)) {
                    $fileset_id = $chapter_filesets->audio->non_drama['fileset']['id'];
                    cacheRemember('v4_user_download', [$user->id, $fileset_id], now()->addDay(), function () use ($user, $fileset_id) {
                        UserDownload::create([
                    'user_id'        => $user->id,
                    'fileset_id'     => $fileset_id,
                  ]);
                        return true;
                    });
                }
            }

            $video_stream = $this->getFileset($bible->filesets, 'video_stream', $book->book_testament);
            if ($video_stream) {
                $fileset_controller = new BibleFileSetsController();
                $gospel_films = $fileset_controller->show($video_stream->id, $video_stream->asset_id, $video_stream->set_type_code, 'v4_chapter_filesets_show')->original['data'] ?? [];
                $chapter_filesets->video->gospel_films = array_map(function ($gospel_film) use ($video_stream) {
                    unset($video_stream->laravel_through_key);
                    $gospel_film['fileset'] = $video_stream;
                    return $gospel_film;
                }, $gospel_films);
            }

            $video_stream_controller = new VideoStreamController();
            try {
                $jesus_films = $video_stream_controller->jesusFilmChapters($bible->language->iso)->original;
            } catch (Exception $e) {
                $jesus_films = [];
            }

            if (isset($jesus_films['verses'])) {
                $verses = $jesus_films['verses'];
                $metadata = $jesus_films['meta'];
                $films = [];

                foreach ($verses as $key => $verse) {
                    if (!$verse) {
                        continue;
                    }
                    foreach ($verse as $book_key => $chapters) {
                        foreach ($chapters as $chapter_key => $item) {
                            if (substr(strtoupper($book_key), 0, 3) === $book->id && intval($chapter_key) === intval($chapter)) {
                                $films[] = (object) ['component_id' => $key, 'verses' => $item];
                            }
                        }
                    }
                }
                $chapter_filesets->video->jesus_films = collect($films)->map(function ($film) use ($metadata) {
                    $film->meta = $metadata[$film->component_id];
                    return $film;
                });
            }

            return $chapter_filesets;
        });

        $result->filesets = $chapter_filesets;
        $result->timestamps = $result->filesets->timestamps;
        unset($result->filesets->timestamps);
        return $this->replyWithDownload($result, $zip, $bible, $book, $chapter);
    }

    public function getFileset($filesets, $type, $testament)
    {
        $available_filesets = [];

        $completeFileset = $filesets->filter(function ($fileset) use ($type) {
            return $fileset->set_type_code === $type && $fileset->set_size_code === 'C';
        })->first();

        if ($completeFileset) {
            $available_filesets[] = $completeFileset;
        }

        $size_filesets = $filesets->filter(function ($fileset) use ($type, $testament) {
            return $fileset->set_type_code === $type && $fileset->set_size_code === $testament;
        })->toArray();

        if (!empty($size_filesets)) {
            $available_filesets = array_merge($available_filesets, $size_filesets);
        }

        $size_partial_filesets = $filesets->filter(function ($fileset) use ($type, $testament) {
            return $fileset->set_type_code === $type && strpos($fileset->set_size_code, $testament) !== false;
        })->toArray();

        if (!empty($size_partial_filesets)) {
            $available_filesets = array_merge($available_filesets, $size_partial_filesets);
        }

        $partial_fileset = $filesets->filter(function ($fileset) use ($type) {
            return $fileset->set_type_code === $type && $fileset->set_size_code === 'P';
        })->first();

        if ($partial_fileset) {
            $available_filesets[] = $partial_fileset;
        }

        if (!empty($available_filesets)) {
            return (object) collect($available_filesets)->sortBy(function ($fileset) {
                return strpos($fileset['id'], '16') !== false;
            })->first();
        }

        return false;
    }

    // is this a good name for this?
    public function getFilesetVernacularMetaData($bible_id, $book_id, $testament) {
        // testament is a set_size_code
        // we maybe able to get testament via book_id

        $bible = Bible::where('id', $bible_id)->first();
        $filesets = $bible->filesets;
        $text_fileset = $filesets->firstWhere('set_type_code', 'text_plain');

        // get audio_filesets
        $fileset_types = collect([
            'audio_stream_drama', 'audio_drama', 'audio_stream', 'audio'
        ]);

        $audio_filesets = $filesets->filter(function ($fs) {
            return Str::contains($fs->set_type_code, 'audio');
        });
        $available_filesets = $fileset_types->map(
          function ($fileset) use ($audio_filesets, $testament) {
            return $this->getFileset($audio_filesets, $fileset, $testament);
        })->filter(function ($item) {
            return $item;
        })->toArray();

        return $this->reply(collect(['text_fileset' => $text_fileset, 'audio_filesets' => array_values($available_filesets)]));
    }

    public function getAudio($bible_id) {
        // save the db server the query(s)
        $bible = cacheRemember('bible_translate', [$bible_id], now()->addDay(), function () use ($bible_id) {
            return Bible::whereId($bible_id)->first();
        });
        $audio_fileset_types = collect(['audio_stream', 'audio_drama_stream', 'audio', 'audio_drama']);
        $bible_audio_filesets = $bible->filesets->whereIn('set_type_code', $audio_fileset_types);

        return $this->reply(array(
            'language'=>$bible->language->name,
            'audio'=>$bible_audio_filesets,
        ));
    }

    private function getAudioFilesetData($results, $bible, $book, $chapter, $type, $name, $download = false, $secondary_type, $secondary_name, $get_secondary = false)
    {
        $fileset_controller = new BibleFileSetsController();
        $fileset = $this->getStreamNonStreamFileset($download, $bible, $type, $book);

        if (!$fileset && $get_secondary) {
            $name = $secondary_name;
            $fileset = $this->getStreamNonStreamFileset($download, $bible, $secondary_type, $book);
        }

        if ($fileset) {
            $fileset = BibleFileset::where([
                'id' => $fileset->id,
                'asset_id' => $fileset->asset_id,
                'set_type_code' => $fileset->set_type_code,
                'set_size_code' => $fileset->set_size_code,
            ])->first();

            // Get fileset
            $fileset_result = $fileset_controller->show($fileset->id, $fileset->asset_id, $fileset->set_type_code, 'v4_chapter_filesets_show')->original['data'] ?? [];
            if (!empty($fileset_result)) {
                $results->audio->$name = $fileset_result[0];
                $results->audio->$name['fileset'] = $fileset;
                if ($download) {
                    $file_name = $fileset->id . '-' . $book->id . '-' . $chapter . '.mp3';
                    $results->downloads[] = (object) ['path' => $results->audio->$name['path'], 'file_name' => $file_name];
                    $results->audio->$name['path'] = $file_name;
                }
            }

            // Get timestamps
            $audio_controller = new AudioController();
            $audioTimestamps = $audio_controller->timestampsByReference($fileset->id, $book->id,  $chapter, $fileset->asset_id)->original['data'] ?? [];
            $results->timestamps->$name = $audioTimestamps;
        }
        return $results;
    }

    private function getStreamNonStreamFileset($download, $bible, $type, $book)
    {
        $stream = $download ? false : $this->getFileset($bible->filesets, $type . '_stream', $book->book_testament);
        $non_stream = $this->getFileset($bible->filesets,  $type, $book->book_testament);
        return $stream ? $stream :  $non_stream;
    }

    private function replyWithDownload($result, $zip, $bible, $book, $chapter)
    {
        if (!$zip) {
            return $this->reply($result);
        }

        $public_dir = public_path() . '/downloads';
        if (!File::exists($public_dir)) {
            File::makeDirectory($public_dir);
        }
        $file_name = $bible->id . '-' . $book->id . '-' . $chapter . '.zip';
        $zip_file = rand() . time() . '-' . $file_name;
        $file_to_path = $public_dir . '/' . $zip_file;
        $zip = new ZipArchive;
        if ($zip->open($file_to_path, ZipArchive::CREATE) === true) {
            $zip->addFromString('contents.json', json_encode($result));

            foreach ($result->filesets->downloads as $download) {
                $client = new Client();
                $mp3 = $client->get($download->path);
                $zip->addFromString($download->file_name, $mp3->getBody());
            }
            unset($result->filesets->downloads);
            $zip->close();
        }

        $headers = ['Content-Type' => 'application/octet-stream'];
        $response = response()->download($file_to_path, $file_name, $headers)->deleteFileAfterSend(true);
        return $response;
    }

    /**
     * @OA\Get(
     *     path="/bibles/{bible_id}/chapter/annotations",
     *     tags={"Bibles"},
     *     summary="Bible chapter annotations",
     *     description="Bible chapter annotations",
     *     operationId="v4_bible.chapter.annotations",
     *     security={{"api_token":{}}},
     *     @OA\Parameter(
     *          name="bible_id",
     *          in="path",
     *          required=true,
     *          @OA\Schema(ref="#/components/schemas/Bible/properties/id"),
     *          description="The Bible ID to retrieve the chapter information for"
     *     ),
     *     @OA\Parameter(
     *          name="book_id",
     *          in="query",
     *          description="Will filter the results by the given book. For a complete list see the `book_id` field in the `/bibles/books` route.",
     *          @OA\Schema(ref="#/components/schemas/Book/properties/id")
     *     ),
     *     @OA\Parameter(
     *          name="chapter",
     *          in="query",
     *          description="Will filter the results by the given chapter",
     *          @OA\Schema(ref="#/components/schemas/BibleFile/properties/chapter_start")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="The requested bible chapter annotations",
     *         @OA\MediaType(mediaType="application/json", @OA\Schema(ref="#/components/schemas/v4_bible.chapter.annotations")),
     *         @OA\MediaType(mediaType="application/xml", @OA\Schema(ref="#/components/schemas/v4_bible.chapter.annotations")),
     *         @OA\MediaType(mediaType="text/csv", @OA\Schema(ref="#/components/schemas/v4_bible.chapter.annotations")),
     *         @OA\MediaType(mediaType="text/x-yaml", @OA\Schema(ref="#/components/schemas/v4_bible.chapter.annotations"))
     *     )
     * )
     *
     * @OA\Schema (
     *   type="object",
     *   schema="v4_bible.chapter.annotations",
     *   title="Bible chapter annotations response",
     *   description="The v4 bible chapter annotations response.",
     *   @OA\Property(property="annotations", type="object",
     *      @OA\Property(property="bookmarks", ref="#/components/schemas/v4_user_bookmarks/properties/data"),
     *      @OA\Property(property="highlights", ref="#/components/schemas/v4_highlights_index/properties/data"),
     *      @OA\Property(property="notes", ref="#/components/schemas/v4_notes_index/properties/data")
     *   )
     * )
     */
    public function annotations(Request $request, $bible_id)
    {
        $content_config = config('services.content');
        if (empty($content_config['url'])) {
            $map = cacheRemember('bible_exist', [$bible_id], now()->addDay(),
              function () use ($bible_id, $content_config) {
                $client = new Client();
                $res = $client->get($content_config['url'] . 'bibles/'.
                      $bible_id.'/name/'.$GLOBALS['i18n_id'].'?v=4&key=' . $content_config['key']);
                $map = json_decode($res->getBody() . '', true);
                return $map;
            });
            // 404
            if ($map['error']) {
                return $this->setStatusCode(404)->replyWithError('Bible not found');
            }
        } else {
            $bible = Bible::whereId($bible_id)->first();
            if (!$bible) {
                return $this->setStatusCode(404)->replyWithError('Bible not found');
            }
        }

        $user = $request->user();

        // Validate Project / User Connection
        if (!$this->compareProjects($user->id, $this->key)) {
            return $this->setStatusCode(401)->replyWithError(trans('api.projects_users_not_connected'));
        }


        $book_id = checkParam('book_id');
        $chapter = checkParam('chapter');

        if ($book_id) {
            $book = Book::whereId($book_id)->first();

            if (!$book) {
                return $this->setStatusCode(404)->replyWithError('Book not found');
            }
        }


        $result = (object) [];


        $highlights_controller = new HighlightsController();
        $bookmarks_controller = new BookmarksController();
        $notes_controller = new NotesController();
        $request->request->add(['bible_id' => $bible_id]);
        if (empty($content_config['url'])) {
            $result->highlights = $highlights_controller->index($request, $user->id)->original['data'];
        } else {
            $result->highlights = $highlights_controller->index($request, $user->id)->toArray();
        }
        $result->bookmarks = $bookmarks_controller->index($request, $user->id)->original['data'];
        $result->notes = $notes_controller->index($request, $user->id)->original['data'];

        $result->bible_id = $bible->id;
        $result->book_id = $book_id;
        $result->chapter = $chapter;

        return $this->reply($result);
    }
}
