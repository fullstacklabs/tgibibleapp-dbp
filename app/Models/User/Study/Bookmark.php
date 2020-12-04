<?php

namespace App\Models\User\Study;

use App\Models\Bible\Bible;
use App\Models\Bible\BibleBook;
use App\Models\Bible\BibleFileset;
use App\Models\Bible\BibleVerse;
use App\Models\Bible\Book;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use App\Relations\EmptyRelation;
use GuzzleHttp\Client;

/**
 * App\Models\User\Study
 * @mixin \Eloquent
 *
 * @property int $id
 * @property string $book_id
 * @property int $chapter
 * @property int $verse_start
 * @property string $user_id
 * @property string $bible_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @method static Note whereId($value)
 * @method static Note whereBookId($value)
 * @method static Note whereChapter($value)
 * @method static Note whereVerseStart($value)
 * @method static Note whereUserId($value)
 * @method static Note whereBibleId($value)
 *
 * @OA\Schema (
 *     type="object",
 *     description="The User created Bookmark",
 *     title="Bookmark",
 *     @OA\Xml(name="Bookmark")
 * )
 *
 */
class Bookmark extends Model
{
    protected $connection = 'dbp_users';
    protected $table = 'user_bookmarks';
    protected $fillable = [
    'id',
    'bible_id',
    'v2_id',
    'user_id',
    'book_id',
    'chapter',
    'verse_start'
  ];

    /**
     *
     * @OA\Property(
     *   title="id",
     *   type="integer",
     *   description="The unique incrementing id for each Bookmark",
     *   minimum=0
     * )
     */
    protected $id;

    /**
     *
     * @OA\Property(ref="#/components/schemas/Book/properties/id")
     */
    protected $book_id;

    /**
     *
     * @OA\Property(ref="#/components/schemas/BibleFile/properties/chapter_start")
     */
    protected $chapter;

    /**
     *
     * @OA\Property(ref="#/components/schemas/BibleFile/properties/verse_start")
     */
    protected $verse_start;

    /**
     *
     * @OA\Property(ref="#/components/schemas/User/properties/id")
     */
    protected $user_id;

    /**
     *
     * @OA\Property(ref="#/components/schemas/Bible/properties/id")
     */
    protected $bible_id;

    /** @OA\Property(
     *   title="updated_at",
     *   type="string",
     *   description="The timestamp the Note was last updated at",
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
     *   description="The timestamp the note was created at"
     * )
     *
     * @method static Note whereCreatedAt($value)
     * @public Carbon $created_at
     */
    protected $created_at;

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function book()
    {
        $content_config = config('services.content');
        if (empty($content_config['url'])) {
            return $this->hasOne(BibleBook::class, 'book_id', 'book_id')->where(
                'bible_id',
                $this['bible_id']
            );
        } else {
            return new EmptyRelation();
        }
    }

    public function bible()
    {
        $content_config = config('services.content');
        if (empty($content_config['url'])) {
            return $this->belongsTo(Bible::class);
        } else {
            return new EmptyRelation();
        }
    }

    public function tags()
    {
        return $this->hasMany(AnnotationTag::class, 'bookmark_id', 'id');
    }

    public function getVerseTextAttribute()
    {
        $bookmark = $this->toArray();
        $chapter = $bookmark['chapter'];
        $verse_start = $bookmark['verse_start'];

        $content_config = config('services.content');
        if (empty($content_config['url'])) {
            $bible = Bible::where('id', $bookmark['bible_id'])->first();
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
                ->where('bible_verses.book_id', $bookmark['book_id'])
                ->where('verse_start', $verse_start)
                ->where('chapter', $chapter)
                ->orderBy('verse_start')
                ->select(['bible_verses.verse_text'])
                ->get()
                ->pluck('verse_text');
            return implode(' ', $verses->toArray());
        } else {
            $bible_id = $bookmark['bible_id'];
            $book_id = $bookmark['book_id'];
            $chapter = $bookmark['chapter'];
            $verse_start = $bookmark['verse_start'];

            $verse_data = cacheRemember('book_verse_text_data', [
              $bible_id, $book_id, $chapter, $verse_start], now()->addDay(), function () use ($bible_id, $book_id, $chapter, $verse_start, $content_config) {
                  $client = new Client();
                  $res = $client->get($content_config['url'] . 'bibles/' .
                   $bible_id . '/book/' . $book_id . '/' .
                   $chapter . '/' . $verse_start .
                   '?v=4&key=' . $content_config['key']);
                  return $res->getBody() . '';
              });
            return $verse_data;
        }
    }
}
