<?php

namespace App\Http\Controllers\Bible;

use Illuminate\Support\Str;
use App\Models\Organization\Asset;
use App\Traits\AccessControlAPI;
use App\Traits\CallsBucketsTrait;
use App\Http\Controllers\APIController;
use App\Models\Bible\Bible;
use App\Models\Bible\BibleFileset;
use App\Models\Bible\BibleFile;
use App\Models\Bible\BibleFilesetType;
use App\Models\Bible\Book;
use App\Models\Language\Language;

use Illuminate\Support\Facades\DB;

use App\Transformers\FileSetTransformer;
use Illuminate\Http\Request;

class BibleFileSetsController extends APIController
{
    use AccessControlAPI;
    use CallsBucketsTrait;

    /**
     *
     * @OA\Get(
     *     path="/bibles/filesets/{fileset_id}",
     *     tags={"Bibles"},
     *     summary="Returns Bibles Filesets",
     *     description="Returns a list of bible filesets",
     *     operationId="v4_bible_filesets.show",
     *     @OA\Parameter(name="fileset_id", in="path", description="The fileset ID", required=true,
     *          @OA\Schema(ref="#/components/schemas/BibleFileset/properties/id")
     *     ),
     *     @OA\Parameter(name="book_id", in="query", description="Will filter the results by the given book. For a complete list see the `book_id` field in the `/bibles/books` route.",
     *          @OA\Schema(ref="#/components/schemas/Book/properties/id")
     *     ),
     *     @OA\Parameter(name="chapter_id", in="query", description="Will filter the results by the given chapter",
     *          @OA\Schema(ref="#/components/schemas/BibleFile/properties/chapter_start")
     *     ),
     *     @OA\Parameter(name="asset_id", in="query", description="Will filter the results by the given Asset",
     *          @OA\Schema(ref="#/components/schemas/BibleFileset/properties/asset_id")
     *     ),
     *     @OA\Parameter(name="type", in="query", description="The fileset type", required=true,
     *          @OA\Schema(ref="#/components/schemas/BibleFileset/properties/set_type_code")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="successful operation",
     *         @OA\MediaType(mediaType="application/json", @OA\Schema(ref="#/components/schemas/v4_bible_filesets.show")),
     *         @OA\MediaType(mediaType="application/xml",  @OA\Schema(ref="#/components/schemas/v4_bible_filesets.show")),
     *         @OA\MediaType(mediaType="text/x-yaml",      @OA\Schema(ref="#/components/schemas/v4_bible_filesets.show")),
     *         @OA\MediaType(mediaType="text/csv",         @OA\Schema(ref="#/components/schemas/v4_bible_filesets.show"))
     *     )
     * )
     *
     * @param null $id
     *
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View|mixed
     * @throws \Exception
     */
    public function show($id = null, $asset_id = null, $set_type_code = null, $cache_key = 'bible_filesets_show')
    {
        $fileset_id    = checkParam('dam_id|fileset_id', true, $id);
        $book_id       = checkParam('book_id');
        $chapter_id    = checkParam('chapter_id|chapter');
        $asset_id      = checkParam('bucket|bucket_id|asset_id', false, $asset_id) ?? config('filesystems.disks.s3_fcbh.bucket');
        $type          = checkParam('type', $set_type_code !== null, $set_type_code);

        $cache_params = [$this->v, $fileset_id, $book_id, $type, $chapter_id, $asset_id];

        $fileset_chapters = cacheRemember($cache_key, $cache_params, now()->addHours(12), function () use ($fileset_id, $book_id, $type, $chapter_id, $asset_id) {
            $book = Book::where('id', $book_id)->orWhere('id_osis', $book_id)->orWhere('id_usfx', $book_id)->first();
            $fileset = BibleFileset::with('bible')->uniqueFileset($fileset_id, $asset_id, $type)->first();
            if (!$fileset) {
                return $this->setStatusCode(404)->replyWithError(trans('api.bible_fileset_errors_404'));
            }

            $access_blocked = $this->blockedByAccessControl($fileset);
            if ($access_blocked) {
                return $access_blocked;
            }

            $bible = optional($fileset->bible)->first();
            $query = BibleFile::where('hash_id', $fileset->hash_id)
                ->leftJoin(config('database.connections.dbp.database') . '.bible_books', function ($q) use ($bible) {
                    $q->on('bible_books.book_id', 'bible_files.book_id')->where('bible_books.bible_id', $bible->id);
                })
                ->leftJoin(config('database.connections.dbp.database') . '.books', 'books.id', 'bible_files.book_id')
                ->when($chapter_id, function ($query) use ($chapter_id) {
                    return $query->where('bible_files.chapter_start', $chapter_id);
                })->when($book, function ($query) use ($book) {
                    return $query->where('bible_files.book_id', $book->id);
                })
                ->select([
                    'bible_files.duration',
                    'bible_files.hash_id',
                    'bible_files.id',
                    'bible_files.book_id',
                    'bible_files.chapter_start',
                    'bible_files.chapter_end',
                    'bible_files.verse_start',
                    'bible_files.verse_end',
                    'bible_files.file_name',
                    'bible_books.name as book_name',
                    'books.protestant_order as book_order',
                ]);

            if ($type === 'video_stream') {
                $query->orderByRaw("FIELD(bible_files.book_id, 'MAT', 'MRK', 'LUK', 'JHN') ASC")
                    ->orderBy('chapter_start', 'ASC')
                    ->orderBy('verse_start', 'ASC');
            }

            $fileset_chapters = $query->get();
            if ($fileset_chapters->count() === 0) {
                return $this->setStatusCode(404)->replyWithError('No Fileset Chapters Found for the provided params');
            }

            return fractal($this->generateFilesetChapters($fileset, $fileset_chapters, $bible, $asset_id), new FileSetTransformer(), $this->serializer);
        });


        return $this->reply($fileset_chapters, [], $transaction_id ?? '');
    }

    // from getVerseText
    public function bibleVerses($fileset_id)
    {
        $book_id = checkParam('book_id'); // can be all, specified or omitted for none
        $chapter = checkParam('chapter_id|chapter');
        $chapter_start = checkParam('chapter_start');
        $chapter_end   = checkParam('chapter_end');
        $verse_start   = checkParam('verse_start');
        $verse_end     = checkParam('verse_end');

        // get first bible via fileset
        // used join logic from PlaylistItems::getVerseText
        $bible = BibleFileset::where('id', $fileset_id)->first()->bible->first();

        // workhose query
        $result = Bible::where('bibles.id', '=', $bible->id)
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
          ->when($book_id && $book_id !== 'all', function ($query) use ($book_id) {
              return $query->where('bible_books.book_id', $book_id);
          })
          ->when($chapter, function ($query) use ($chapter) {
              return $query->where('bible_verses.chapter', $chapter);
          })
          ->when($chapter_start, function ($query) use ($chapter_start) {
              return $query->where('bible_verses.chapter', '>=', $chapter_start);
          })
          ->when($chapter_end, function ($query) use ($chapter_end) {
              return $query->where('bible_verses.chapter', '<=', $chapter_end);
          })
          ->when($verse_start, function ($query) use ($verse_start) {
              return $query->where('bible_verses.verse_start', '>=', $verse_start);
          })
          ->when($verse_end, function ($query) use ($verse_end) {
              return $query->where('bible_verses.verse_end',   '<=', $verse_end);
          })
          ->select(['bibles.versification', 'bible_books.bible_id',
            'bible_books.book_id', 'books.book_testament', 'bible_books.name',
            'chapter', 'verse_start', 'verse_end', 'verse_text'])->get();

        $books = array();
        // bandwidth optimize nested results
        if ($book_id) {
          $books = array();
          foreach($result as $row) {
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
        }

        // throw bible level stuff up top
        return $this->reply(array(
          'bible_id'      => $bible->id,
          'versification' => $bible->versification,
          'books'         => array_values($books),
        ), [], '');
    }

    public function getPlaylistMeta($fileset_ids)
    {
        // laravel pass array from route to controller
        // https://stackoverflow.com/a/47695952/287696
        $filesets = explode(',', $fileset_ids);

        // lookup filesets and get hashes
        $filesets_hashes = DB::connection('dbp')
            ->table('bible_filesets')
            ->select(['hash_id', 'id'])
            ->whereIn('id', $filesets)->get();

        // convert fileset hashes into bible_ids
        $hashes_bibles = DB::connection('dbp')
            ->table('bible_fileset_connections')
            ->select(['hash_id', 'bible_id'])
            ->whereIn('hash_id', $filesets_hashes->pluck('hash_id'))->get();

        // convert bible_ids into text filesets + bible_ids
        $text_filesets = DB::connection('dbp')
            ->table('bible_fileset_connections as fc')
            ->join('bible_filesets as f', 'f.hash_id', '=', 'fc.hash_id')
            // I think we may only need: id, hash_id, set_type_code
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
            if (!isset($fileset_text_info[$fileset]) || !isset($bible_hash[$fileset_text_info[$fileset]])) {
                if (!isset($fileset_text_info[$fileset])) {
                    echo "No [$fileset]<Br>\n";
                } else {
                    echo "No Bible[", $fileset_text_info[$fileset], "]<Br>\n";
                }
                continue;
            }
            $bible_id = $bible_hash[$fileset_text_info[$fileset]];
            // fetch text for bible_id
            $fileset_text_info[$fileset] = $text_filesets[$bible_id];
        }
        return $this->reply($fileset_text_info, [], '');
    }

    private function signedPath($bible, $fileset, $fileset_chapter)
    {
        switch ($fileset->set_type_code) {
            case 'audio_drama':
            case 'audio':
                $fileset_type = 'audio';
                break;
            case 'text_plain':
            case 'text_format':
                $fileset_type = 'text';
                break;
            case 'video_stream':
            case 'video':
                $fileset_type = 'video';
                break;
            case 'app':
                $fileset_type = 'app';
                break;
            default:
                $fileset_type = 'text';
                break;
        }

        return $fileset_type . '/' . ($bible ? $bible->id . '/' : '') . $fileset->id . '/' . $fileset_chapter->file_name;
    }

    /**
     *
     * @OA\Get(
     *     path="/bibles/filesets/{fileset_id}/download",
     *     tags={"Bibles"},
     *     summary="Download a Fileset",
     *     description="Returns a an entire fileset or a selected portion of a fileset for download",
     *     operationId="v4_bible_filesets.download",
     *     @OA\Parameter(name="fileset_id", in="path", required=true, description="The fileset ID",
     *          @OA\Schema(ref="#/components/schemas/BibleFileset/properties/id")
     *     ),
     *     @OA\Parameter(name="asset_id", in="query", required=true, description="The fileset ID",
     *          @OA\Schema(ref="#/components/schemas/Asset/properties/id")
     *     ),
     *     @OA\Parameter(name="fileset_type", in="query", description="The type of fileset being queried",
     *         @OA\Schema(ref="#/components/schemas/BibleFileset/properties/set_type_code")
     *     ),
     *     @OA\Parameter(name="book_ids", in="query", required=true,
     *          description="The list of book ids to download content for separated by commas. For a complete list see the `book_id` field in the `/bibles/books` route.",
     *          example="GEN,EXO,MAT,REV",
     *          @OA\Schema(ref="#/components/schemas/Book/properties/id")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="The requested fileset as a zipped download",
     *         @OA\MediaType(mediaType="application/zip")
     *     )
     * )
     *
     * @param $id
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View|mixed
     */
    public function download($id)
    {
        $id        = checkParam('fileset_id', true, $id);
        $asset_id  = checkParam('bucket|bucket_id|asset_id') ?? config('filesystems.disks.s3_fcbh.bucket');
        $type      = checkParam('fileset_type');
        $books     = checkParam('book_ids');
        $files     = null;

        $fileset = BibleFileset::uniqueFileset($id, $asset_id, $type)->first();
        if (!$fileset) {
            return $this->replyWithError('Fileset ID not found');
        }

        // Filter Download By Books
        if ($books) {
            $books = explode(',', $books);
            $files = BibleFile::with('book')->where('hash_id', $fileset->hash_id)->whereIn('book_id', $books)->get();
            if (!$files) {
                return $this->setStatusCode(404)->replyWithError('Files not found');
            }
            $books = $files->map(function ($file) {
                $testamentLetter = ($file->book->book_testament === 'NT') ? 'B' : 'A';
                return $testamentLetter . str_pad($file->book->testament_order, 2, 0, STR_PAD_LEFT);
            })->unique();
        }

        Asset::download($files, 's3_fcbh', 'dbp.test', 5, $books);
        return $this->reply('download successful');
    }

    /**
     *
     * Copyright
     *
     * @OA\Get(
     *     path="/bibles/filesets/{fileset_id}/copyright",
     *     tags={"Bibles"},
     *     summary="Fileset Copyright information",
     *     description="A fileset's copyright information and organizational connections",
     *     operationId="v4_bible_filesets.copyright",
     *     @OA\Parameter(
     *          name="fileset_id",
     *          in="path",
     *          required=true,
     *          @OA\Schema(ref="#/components/schemas/BibleFileset/properties/id"),
     *          description="The fileset ID to retrieve the copyright information for"
     *     ),
     *     @OA\Parameter(
     *          name="asset_id",
     *          in="query",
     *          required=true,
     *          @OA\Schema(ref="#/components/schemas/BibleFileset/properties/asset_id"),
     *          description="The asset id which contains the Fileset"
     *     ),
     *     @OA\Parameter(
     *          name="type",
     *          in="query",
     *          required=true,
     *          @OA\Schema(ref="#/components/schemas/BibleFileset/properties/set_type_code"),
     *          description="The set type code for the fileset"
     *     ),
     *     @OA\Parameter(
     *          name="iso",
     *          in="query",
     *          @OA\Schema(ref="#/components/schemas/Language/properties/iso", default="eng"),
     *          description="The iso code to filter organization translations by. For a complete list see the `iso` field in the `/languages` route."
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="The requested fileset copyright",
     *         @OA\MediaType(mediaType="application/json", @OA\Schema(ref="#/components/schemas/v4_bible_filesets.copyright")),
     *         @OA\MediaType(mediaType="application/xml", @OA\Schema(ref="#/components/schemas/v4_bible_filesets.copyright")),
     *         @OA\MediaType(mediaType="text/csv", @OA\Schema(ref="#/components/schemas/v4_bible_filesets.copyright")),
     *         @OA\MediaType(mediaType="text/x-yaml", @OA\Schema(ref="#/components/schemas/v4_bible_filesets.copyright"))
     *     )
     * )
     *
     * @OA\Schema (
     *     type="object",
     *     schema="v4_bible_filesets.copyright",
     *     description="v4_bible_filesets.copyright",
     *     title="v4_bible_filesets.copyright",
     *     @OA\Xml(name="v4_bible_filesets.copyright"),
     *     @OA\Property(property="id", ref="#/components/schemas/BibleFileset/properties/id"),
     *     @OA\Property(property="asset_id", ref="#/components/schemas/BibleFileset/properties/asset_id"),
     *     @OA\Property(property="type", ref="#/components/schemas/BibleFileset/properties/set_type_code"),
     *     @OA\Property(property="size", ref="#/components/schemas/BibleFileset/properties/set_size_code"),
     *     @OA\Property(property="copyright", ref="#/components/schemas/BibleFilesetCopyright")
     * )
     *
     * @see https://api.dbp.test/bibles/filesets/ENGESV/copyright?key=API_KEY&v=4&type=text_plain&pretty
     * @param string $id
     * @return mixed
     */
    public function copyright($id)
    {
        $iso = checkParam('iso') ?? 'eng';
        $type = checkParam('type', true);
        $asset_id = checkParam('bucket|bucket_id|asset_id') ?? 'dbp-prod';

        $cache_params = [$asset_id, $id, $type, $iso];
        $fileset = cacheRemember('bible_fileset_copyright', $cache_params, now()->addDay(), function () use ($iso, $type, $asset_id, $id) {
            $language_id = optional(Language::where('iso', $iso)->select('id')->first())->id;
            return BibleFileset::where('id', $id)->with([
                'copyright.organizations.logos',
                'copyright.organizations.translations' => function ($q) use ($language_id) {
                    $q->where('language_id', $language_id);
                }
            ])
                ->when($asset_id, function ($q) use ($asset_id) {
                    $q->where('asset_id', $asset_id);
                })
                ->when($type, function ($q) use ($type) {
                    $q->where('set_type_code', $type);
                })->select(['hash_id', 'id', 'asset_id', 'set_type_code as type', 'set_size_code as size'])->first();
        });

        return $this->reply($fileset);
    }



    /**
     * Returns the Available Media Types for Filesets within the API.
     *
     * @OA\Get(
     *     path="/bibles/filesets/media/types",
     *     tags={"Bibles"},
     *     summary="Available fileset types",
     *     description="A list of all the file types that exist within the filesets",
     *     operationId="v4_bible_filesets.types",
     *     @OA\Response(
     *         response=200,
     *         description="The fileset types",
     *         @OA\MediaType(
     *            mediaType="application/json",
     *            @OA\Schema(type="object",example={"audio_drama"="Dramatized Audio","audio"="Audio","text_plain"="Plain Text","text_format"="Formatted Text","video"="Video","app"="Application"})
     *         ),
     *         @OA\MediaType(
     *            mediaType="application/xml",
     *            @OA\Schema(type="object",example={"audio_drama"="Dramatized Audio","audio"="Audio","text_plain"="Plain Text","text_format"="Formatted Text","video"="Video","app"="Application"})
     *         ),
     *         @OA\MediaType(
     *            mediaType="text/x-yaml",
     *            @OA\Schema(type="object",example={"audio_drama"="Dramatized Audio","audio"="Audio","text_plain"="Plain Text","text_format"="Formatted Text","video"="Video","app"="Application"})
     *         ),
     *         @OA\MediaType(
     *            mediaType="text/csv",
     *            @OA\Schema(type="object",example={"audio_drama"="Dramatized Audio","audio"="Audio","text_plain"="Plain Text","text_format"="Formatted Text","video"="Video","app"="Application"})
     *         )
     *     )
     * )
     *
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View|mixed
     *
     */
    public function mediaTypes()
    {
        return $this->reply(BibleFilesetType::all()->pluck('name', 'set_type_code'));
    }

    /**
     * @OA\Post(
     *     path="/bibles/filesets/check/types",
     *     tags={"Bibles"},
     *     summary="Check fileset types",
     *     description="Check Bible File locations if they have audio or video.",
     *     operationId="v4_bible_filesets.checkTypes",
     *     @OA\RequestBody(ref="#/components/requestBodies/PlaylistItems"),
     *     @OA\Response(
     *         response=200,
     *         description="successful operation",
     *         @OA\MediaType(mediaType="application/json", @OA\Schema(ref="#/components/schemas/v4_fileset_check")),
     *         @OA\MediaType(mediaType="application/xml",  @OA\Schema(ref="#/components/schemas/v4_fileset_check")),
     *         @OA\MediaType(mediaType="text/x-yaml",      @OA\Schema(ref="#/components/schemas/v4_fileset_check")),
     *         @OA\MediaType(mediaType="text/csv",         @OA\Schema(ref="#/components/schemas/v4_fileset_check"))
     *     )
     * )
     *
     * @OA\Schema (
     *   type="array",
     *   schema="v4_fileset_check",
     *   title="Fileset check types response",
     *   description="The v4 fileset check types response.",
     *   @OA\Items(
     *      @OA\Property(property="fileset_id", ref="#/components/schemas/PlaylistItems/properties/fileset_id"),
     *      @OA\Property(property="book_id", ref="#/components/schemas/PlaylistItems/properties/book_id"),
     *      @OA\Property(property="chapter_start", ref="#/components/schemas/PlaylistItems/properties/chapter_start"),
     *      @OA\Property(property="chapter_end", ref="#/components/schemas/PlaylistItems/properties/chapter_end"),
     *      @OA\Property(property="verse_start", ref="#/components/schemas/PlaylistItems/properties/verse_start"),
     *      @OA\Property(property="verse_end", ref="#/components/schemas/PlaylistItems/properties/verse_end"),
     *      @OA\Property(property="has_audio", type="boolean"),
     *      @OA\Property(property="has_video", type="boolean")
     *   )
     * )
     */
    public function checkTypes(Request $request)
    {
        $bible_locations = json_decode($request->getContent());
        $result = [];
        foreach ($bible_locations as $bible_location) {
            $cache_params = [$bible_location->fileset_id];
            $hashes = cacheRemember('v4_bible_filesets.checkTypes', $cache_params, now()->addMonth(), function () use ($bible_location) {
                $filesets = BibleFileset::where('id', $bible_location->fileset_id)
                    ->whereNotIn('set_type_code', ['text_format'])
                    ->first()
                    ->bible
                    ->first()->filesets;
                $audio_filesets_hashes = $filesets->whereIn('set_type_code', ['audio_drama', 'audio', 'audio_stream', 'audio_drama_stream'])->pluck('hash_id')->flatten();
                $video_filesets_hashes = $filesets->where('set_type_code', 'video_stream')->flatten();
                return ['audio' => $audio_filesets_hashes, 'video' => $video_filesets_hashes];
            });
            $where_fields = [
                ['book_id', $bible_location->book_id],
                ['chapter_start', '>=', $bible_location->chapter_start],
                [\DB::raw('IFNULL( chapter_end, chapter_start)'), '<=', $bible_location->chapter_end],
            ];
            if (isset($bible_location->verse_start)) {
                $where_fields[] = ['verse_start', '<=', (int) $bible_location->verse_start];
                $where_fields[] = [\DB::raw('IFNULL( chapter_end, ' . (int) $bible_location->verse_end . ')'), '>=', $bible_location->verse_end];
            }
            $bible_location->has_audio = BibleFile::whereIn('hash_id', $hashes['audio'])->where($where_fields)->exists();
            $bible_location->has_video = BibleFile::whereIn('hash_id', $hashes['video'])->where($where_fields)->exists();
            $result[] = $bible_location;
        }


        return $this->reply($result);
    }

    /**
     * @param      $fileset
     * @param      $fileset_chapters
     * @param      $bible
     * @param      $asset_id
     *
     * @throws \Exception
     * @return array
     */
    private function generateFilesetChapters($fileset, $fileset_chapters, $bible, $asset_id)
    {
        $is_stream = $fileset->set_type_code === 'video_stream' || $fileset->set_type_code === 'audio_stream' || $fileset->set_type_code === 'audio_drama_stream';
        $is_video = Str::contains($fileset->set_type_code, 'video');

        if ($is_stream) {
            foreach ($fileset_chapters as $key => $fileset_chapter) {
                $fileset_chapters[$key]->file_name = route(
                    'v4_media_stream',
                    [
                        'fileset_id' => $fileset->id,
                        'book_id' => $fileset_chapter->book_id,
                        'chapter' => $fileset_chapter->chapter_start,
                        'verse_start' => $fileset_chapter->verse_start,
                        'verse_end' => $fileset_chapter->verse_end,
                    ]
                );
            }
        }

        // do we have a signing key?
        $hasSigningKey = config('filesystems.disks.cloudfront.key');
        if ($hasSigningKey) {
            if (!$is_stream) {
                foreach ($fileset_chapters as $key => $fileset_chapter) {
                    //$fileset_chapters[$key]->file_name = $this->signedUrl($this->signedPath($bible, $fileset, $fileset_chapter), $asset_id, random_int(0, 10000000));
                }
            }

            if ($is_video) {
                if (empty($content_config['url'])) {
                    // we're a content provider, so we'll sign
                    foreach ($fileset_chapters as $key => $fileset_chapter) {
                        //$fileset_chapters[$key]->thumbnail = $this->signedUrl('video/thumbnails/' . $fileset_chapters[$key]->book_id . '_' . str_pad($fileset_chapter->chapter_start, 2, '0', STR_PAD_LEFT) . '.jpg', $asset_id, random_int(0, 10000000));
                    }
                }
            }
        } // end hasSigningKey

        return $fileset_chapters;
    }
}
