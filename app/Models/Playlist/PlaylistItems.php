<?php

namespace App\Models\Playlist;

use App\Models\Bible\Bible;
use App\Models\Bible\BibleFile;
use App\Models\Bible\BibleFileset;
use App\Models\Bible\BibleFileTimestamp;
use App\Models\Bible\BibleVerse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Spatie\EloquentSortable\Sortable;
use Spatie\EloquentSortable\SortableTrait;
use GuzzleHttp\Client;
use App\Relations\EmptyRelation;

/**
 * App\Models\Playlist
 * @mixin \Eloquent
 *
 * @property int $id
 * @property int $playlist_id
 * @property string $fileset_id
 * @property string $book_id
 * @property int $chapter_start
 * @property int $chapter_end
 * @property int $verse_start
 * @property int $verse_end
 * @property int $verses
 * @property int $duration
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 *
 * @OA\Schema (
 *     type="object",
 *     description="The Playlist Item",
 *     title="Playlist Item"
 * )
 *
 */

class PlaylistItems extends Model implements Sortable
{
    use SortableTrait;

    protected $connection = 'dbp_users';
    public $table         = 'playlist_items';
    protected $fillable   = ['playlist_id', 'fileset_id', 'book_id', 'chapter_start', 'chapter_end', 'verse_start', 'verse_end', 'duration', 'verses'];
    protected $hidden     = ['playlist_id', 'created_at', 'updated_at', 'order_column'];

    /**
     *
     * @OA\Property(
     *   title="id",
     *   type="integer",
     *   description="The playlist item id"
     * )
     *
     */
    protected $id;

    /**
     *
     * @OA\Property(
     *   title="playlist_id",
     *   type="integer",
     *   description="The playlist id"
     * )
     *
     */
    protected $playlist_id;

    /**
     *
     * @OA\Property(
     *   title="fileset_id",
     *   type="string",
     *   description="The fileset id"
     * )
     *
     */
    protected $fileset_id;
    /**
     *
     * @OA\Property(
     *   title="book_id",
     *   type="string",
     *   description="The book_id",
     * )
     *
     */
    protected $book_id;
    /**
     *
     * @OA\Property(
     *   title="chapter_start",
     *   type="integer",
     *   description="The chapter_start",
     *   minimum=0,
     *   maximum=150,
     *   example=4
     * )
     *
     */
    protected $chapter_start;
    /**
     *
     * @OA\Property(
     *   title="chapter_end",
     *   type="integer",
     *   description="If the Bible File spans multiple chapters this field indicates the last chapter of the selection",
     *   nullable=true,
     *   minimum=0,
     *   maximum=150,
     *   example=5
     * )
     *
     */
    protected $chapter_end;
    /**
     *
     * @OA\Property(
     *   title="verse_start",
     *   type="integer",
     *   description="The starting verse at which the BibleFile reference begins",
     *   minimum=1,
     *   maximum=176,
     *   example=5
     * )
     *
     */
    protected $verse_start;

    /**
     *
     * @OA\Property(
     *   title="verse_end",
     *   type="integer",
     *   description="If the Bible File spans multiple verses this value will indicate the last verse in that reference. This value is inclusive, so for the reference John 1:1-4. The value would be 4 and the reference would contain verse 4.",
     *   nullable=true,
     *   minimum=1,
     *   maximum=176,
     *   example=5
     * )
     *
     */
    protected $verse_end;

    /**
     *
     * @OA\Property(
     *   title="duration",
     *   type="integer",
     *   description="The playlist item calculated duration"
     * )
     *
     */
    protected $duration;

    /**
     *
     * @OA\Property(
     *   title="verses",
     *   type="integer",
     *   description="The playlist item verses count"
     * )
     *
     */
    protected $verses;

    /** @OA\Property(
     *   title="updated_at",
     *   type="string",
     *   description="The timestamp the playlist item was last updated at",
     *   nullable=true
     * )
     *
     * @method static Note whereUpdatedAt($value)
     * @public Carbon|null $updated_at
     */
    protected $updated_at;
    /**
     *
     * @OA\Property(
     *   title="created_at",
     *   type="string",
     *   description="The timestamp the playlist item was created at"
     * )
     *
     * @method static Note whereCreatedAt($value)
     * @public Carbon $created_at
     */
    protected $created_at;

    public function calculateDuration()
    {
        $playlist_item = (object) $this->attributes;
        $this->attributes['duration'] = $this->getDuration($playlist_item) ?? 0;
        return $this;
    }



    private function getDuration($playlist_item)
    {
        $fileset = cacheRemember('bible_fileset', [$playlist_item->fileset_id], now()->addDay(), function () use ($playlist_item) {
            return BibleFileset::whereId($playlist_item->fileset_id)->first();
        });

        if (!$fileset) {
            return 0;
        }

        $bible_files = cacheRemember(
            'bible_file_duration',
            [$fileset->hash_id, $playlist_item->book_id, $playlist_item->chapter_start, $playlist_item->chapter_end],
            now()->addDay(),
            function () use ($fileset, $playlist_item) {
                return BibleFile::with('streamBandwidth.transportStreamTS')->with('streamBandwidth.transportStreamBytes')->where([
                    'hash_id' => $fileset->hash_id,
                    'book_id' => $playlist_item->book_id,
                ])
                    ->where('chapter_start', '>=', $playlist_item->chapter_start)
                    ->where('chapter_start', '<=', $playlist_item->chapter_end)
                    ->get();
            }
        );
        $duration = 0;
        if ($fileset->set_type_code === 'audio_stream' || $fileset->set_type_code === 'audio_drama_stream') {
            foreach ($bible_files as $bible_file) {
                $currentBandwidth = $bible_file->streamBandwidth->first();
                $transportStream = sizeof($currentBandwidth->transportStreamBytes) ? $currentBandwidth->transportStreamBytes : $currentBandwidth->transportStreamTS;
                if ($playlist_item->verse_end && $playlist_item->verse_start) {
                    $transportStream = $this->processVersesOnTransportStream($playlist_item, $transportStream, $bible_file);
                }

                foreach ($transportStream as $stream) {
                    $duration += $stream->runtime;
                }
            }
        } else {
            foreach ($bible_files as $bible_file) {
                $duration += $bible_file->duration ?? 180;
            }
        }

        return $duration;
    }

    private function processVersesOnTransportStream($item, $transportStream, $bible_file)
    {
        if ($item->chapter_end  === $item->chapter_start) {
            $transportStream = $transportStream->splice(1, $item->verse_end)->all();
            return collect($transportStream)->slice($item->verse_start - 1)->all();
        }

        $transportStream = $transportStream->splice(1)->all();
        if ($bible_file->chapter_start === $item->chapter_start) {
            return collect($transportStream)->slice($item->verse_start - 1)->all();
        }
        if ($bible_file->chapter_start === $item->chapter_end) {
            return collect($transportStream)->splice(0, $item->verse_end)->all();
        }

        return $transportStream;
    }


    protected $appends = ['completed', 'full_chapter', 'path', 'metadata'];


    public function calculateVerses()
    {
        $fileset_id = $this['fileset_id'];
        $book_id  = $this['book_id'];
        $chapter_start  = $this['chapter_start'];
        $chapter_end  = $this['chapter_end'];
        $fileset = cacheRemember('text_bible_fileset', [$fileset_id], now()->addDay(), function () use ($fileset_id) {
            return BibleFileset::where('id', $fileset_id)
                ->whereNotIn('set_type_code', ['text_format'])
                ->first();
        });

        $bible_files = cacheRemember(
            'bible_file_verses',
            [$fileset->hash_id, $book_id, $chapter_start, $chapter_end],
            now()->addDay(),
            function () use ($fileset, $book_id, $chapter_start, $chapter_end) {
                return BibleFile::where('hash_id', $fileset->hash_id)
                    ->where([
                        ['book_id', $book_id],
                        ['chapter_start', '>=', $chapter_start],
                        ['chapter_start', '<', $chapter_end],
                    ])
                    ->get();
            }
        );
        $verses_middle = 0;
        foreach ($bible_files as $bible_file) {
            $verses_middle += ($bible_file->verse_start - 1) + $bible_file->verse_end;
        }
        if (!$this['verse_start'] && !$this['verse_end']) {
            $verses = $verses_middle;
        } else {
            $verses = $verses_middle - ($this['verse_start'] - 1) + $this['verse_end'];
        }

        // Try to get the verse count from the bible_verses table
        if (!$verses) {
            $text_fileset = $fileset->bible->first()->filesets->where('set_type_code', 'text_plain')->first();
            if ($text_fileset) {
                $verses = cacheRemember('playlist_item_verses', [
                    $text_fileset->hash_id,
                    $book_id,
                    $chapter_start,
                    $chapter_end
                ], now()->addDay(), function () use ($text_fileset, $book_id, $chapter_start, $chapter_end) {
                    return BibleVerse::where('hash_id', $text_fileset->hash_id)
                        ->where([
                            ['book_id', $book_id],
                            ['chapter', '>=', $chapter_start],
                            ['chapter', '<=', $chapter_end],
                        ])
                        ->count();
                });
            }
        }

        $this->attributes['verses'] =  $verses;
        return $this;
    }

    public function getVerseText($text_filesets = null)
    {
        if ($text_filesets) {
            $text_fileset = $text_filesets[$this['fileset_id']][0] ?? null;
            $hash_id = $text_fileset->hash_id;
        } else {
            $config = config('services.content');
            // if configured to use content server
            if (!empty($config['url'])) {

              $fileset_id = $this['fileset_id'];
              $cache_params = [$fileset_id];
              $text_fileset = cacheRemember('playlist_item_fileset_content_verses', $cache_params, now()->addDay(), function () use ($fileset_id, $config) {
                  $client = new Client();
                  $res = $client->get($config['url'] . 'bibles/filesets/'.
                    $fileset_id.'/verses?v=4&key=' . $config['key']);
                  return collect(json_decode($res->getBody() . ''));
              });
              $hash_id = $text_fileset['hash_id'];
            } else {
              $fileset = BibleFileset::where('id', $this['fileset_id'])->first();
              $text_fileset = $fileset->bible->first()->filesets->where('set_type_code', 'text_plain')->first();
              $hash_id = $text_fileset->hash_id;
            }
        }

        $verses = null;
        if ($hash_id) {
            $where = [
                ['book_id', $this['book_id']],
                ['chapter', '>=', $this['chapter_start']],
                ['chapter', '<=', $this['chapter_end']],
            ];
            if ($this['verse_start'] && $this['verse_end']) {
                $where[] = ['verse_start', '>=', $this['verse_start']];
                $where[] = ['verse_end', '<=', $this['verse_end']];
            }
            $cache_params = [$hash_id, $this['book_id'], $this['chapter_start'], $this['chapter_end'], $this['verse_start'], $this['verse_end']];
            $verses = cacheRemember('playlist_item_text', $cache_params, now()->addDay(), function () use ($hash_id, $where) {
                return BibleVerse::where('hash_id', $hash_id)
                    ->where($where)
                    ->get()->pluck('verse_text');
            });
        }

        return $verses;
    }

    public function getTimestamps()
    {

        // Check Params
        $fileset_id = $this['fileset_id'];
        $book = $this['book_id'];
        $chapter_start = $this['chapter_start'];
        $chapter_end = $this['chapter_end'];
        $verse_start = $this['verse_start'];
        $verse_end = $this['verse_end'];
        $cache_params = [$fileset_id, $book, $chapter_start, $chapter_end, $verse_start, $verse_end];
        return cacheRemember('playlist_item_timestamps', $cache_params, now()->addDay(), function () use ($fileset_id, $book, $chapter_start, $chapter_end, $verse_start, $verse_end) {
            $fileset = BibleFileset::where('id', $fileset_id)->first();

            $bible_files = BibleFile::where('hash_id', $fileset->hash_id)
                ->when($book, function ($query) use ($book) {
                    return $query->where('book_id', $book);
                })->where('chapter_start', '>=', $chapter_start)
                ->where('chapter_end', '<=', $chapter_end)
                ->get();

            // Fetch Timestamps
            $audioTimestamps = BibleFileTimestamp::whereIn('bible_file_id', $bible_files->pluck('id'))->orderBy('verse_start')->get();


            if ($audioTimestamps->isEmpty() && ($fileset->set_type_code === 'audio_stream' || $fileset->set_type_code === 'audio_drama_stream')) {
                $audioTimestamps = [];
                $bible_files_ids = BibleFile::where([
                    'hash_id' => $fileset->hash_id,
                    'book_id' => $book,
                ])
                    ->where('chapter_start', '>=', $chapter_start)
                    ->where('chapter_start', '<=', $chapter_end)
                    ->get()->pluck('id');


                foreach ($bible_files_ids as $bible_file_id) {
                    $timestamps = DB::connection('dbp')->select('select t.* from bible_file_stream_bandwidths as b
                    join bible_file_stream_bytes as s
                    on s.stream_bandwidth_id = b.id
                    join bible_file_timestamps as t
                    on t.id = s.timestamp_id
                    where b.bible_file_id = ? and  s.timestamp_id IS NOT NULL', [$bible_file_id]);
                    $audioTimestamps = array_merge($audioTimestamps, $timestamps);
                }
            } else {
                $audioTimestamps = $audioTimestamps->toArray();
            }

            if ($verse_start && $verse_end) {
                $audioTimestamps =  Arr::where($audioTimestamps, function ($timestamp) use ($verse_start, $verse_end) {
                    return $timestamp->verse_start >= $verse_start && $timestamp->verse_start <= $verse_end;
                });
            }

            $audioTimestamps = Arr::pluck($audioTimestamps, 'timestamp', 'verse_start');
            return $audioTimestamps;
        });
    }

    /**
     * @OA\Property(
     *   property="completed",
     *   title="completed",
     *   type="boolean",
     *   description="If the playlist item is completed"
     * )
     */
    public function getCompletedAttribute()
    {
        $user = Auth::user();
        if (empty($user)) {
            return false;
        }

        $complete = PlaylistItemsComplete::where('playlist_item_id', $this->attributes['id'])
            ->where('user_id', $user->id)->first();

        return !empty($complete);
    }

    /**
     * @OA\Property(
     *   property="full_chapter",
     *   title="full_chapter",
     *   type="boolean",
     *   description="If the playlist item is a full chapter item"
     * )
     */
    public function getFullChapterAttribute()
    {
        return (bool) !$this->attributes['verse_start'] && !$this->attributes['verse_end'];
    }

    /**
     * @OA\Property(
     *   property="path",
     *   title="path",
     *   type="string",
     *   description="Hls path of the playlist item"
     * )
     */
    public function getPathAttribute()
    {
        return route('v4_internal_playlists_item.hls', ['playlist_item_id'  => $this->attributes['id'], 'v' => checkParam('v'), 'key' => checkParam('key')]);
    }

    /**
     * @OA\Property(
     *   property="metadata",
     *   title="metadata",
     *   type="object",
     *   description="Bible metadata info",
     *      @OA\Property(property="bible_id", ref="#/components/schemas/Bible/properties/id"),
     *      @OA\Property(property="bible_name", ref="#/components/schemas/BibleTranslation/properties/name"),
     *      @OA\Property(property="bible_vname", ref="#/components/schemas/BibleTranslation/properties/name"),
     *      @OA\Property(property="book_name", ref="#/components/schemas/BookTranslation/properties/name")
     * )
     */
    public function getMetadataAttribute()
    {
        $fileset_id = $this['fileset_id'];
        $book_id = $this['book_id'];

        return cacheRemember(
            'playlist_item_metadata',
            [$fileset_id, $book_id],
            now()->addDay(),
            function () use ($fileset_id, $book_id) {
                $fileset = BibleFileset::whereId($fileset_id)->first();
                if (!$fileset) {
                    return null;
                }
                $bible = $fileset->bible->first();
                if (!$bible) {
                    return null;
                }
                $bible = Bible::whereId($bible->id)->with(['translations', 'books.book'])->first();

                return [
                    'bible_id' => $bible->id,
                    'bible_name' => optional($bible->translations->where('language_id', $GLOBALS['i18n_id'])->first())->name,
                    'bible_vname' =>  optional($bible->vernacularTranslation)->name,
                    'book_name' => optional($bible->books->where('book_id', $book_id)->first())->name
                ];
            }
        );
    }

    public function fileset()
    {
        $content_config = config('services.content');
        if (empty($content_config['url'])) {
            // how does this work because ENGESV has multiple records...
            return $this->belongsTo(BibleFileset::class);
        } else {
            return new EmptyRelation();
        }
    }

    public function complete()
    {
        $user = Auth::user();
        $completed_item = PlaylistItemsComplete::firstOrNew([
            'user_id'               => $user->id,
            'playlist_item_id'      => $this['id']
        ]);
        $completed_item->save();
    }

    public function unComplete()
    {
        $user = Auth::user();
        $completed_item = PlaylistItemsComplete::where('playlist_item_id', $this['id'])
            ->where('user_id', $user->id);
        $completed_item->delete();
    }
}
