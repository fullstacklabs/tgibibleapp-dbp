<?php

namespace App\Models\Bible;

use Illuminate\Database\Eloquent\Model;
use App\Models\Language\Language;

/**
 * App\Models\Bible\BibleFile
 *
 * @property-read \App\Models\Bible\Bible $bible
 * @property-read \App\Models\Bible\Book $book
 * @property-read \App\Models\Bible\BibleFileTimestamp $firstReference
 * @property-read \App\Models\Language\Language $language
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\Bible\BibleFileTimestamp[] $timestamps
 * @mixin \Eloquent
 * @property-read \App\Models\Bible\BibleFileset $fileset
 * @property-read \App\Models\Bible\BibleFileTitle $title
 * @property-read \App\Models\Bible\BibleFileTitle $currentTitle
 * @property-read \App\Models\Bible\BibleFilesetConnection $connections
 *
 * @method static BibleFile whereId($value)
 * @property $id
 * @method static BibleFile whereHashId($value)
 * @property string $hash_id
 * @method static BibleFile whereBookId($value)
 * @property $book_id
 * @method static BibleFile whereChapterStart($value)
 * @property $chapter_start
 * @method static BibleFile whereChapterEnd($value)
 * @property $chapter_end
 * @method static BibleFile whereVerseStart($value)
 * @property $verse_start
 * @method static BibleFile whereVerseEnd($value)
 * @property $verse_end
 * @method static BibleFile whereVerseText($value)
 * @property $verse_text
 * @method static BibleFile whereFileName($value)
 * @property $file_name
 * @method static BibleFile whereFileSize($value)
 * @property $file_size
 * @method static BibleFile whereDuration($value)
 * @property $duration
 *
 * @OA\Schema (
 *     type="object",
 *     required={"filename"},
 *     description="The Bible File Model communicates information about biblical files stored in S3",
 *     title="BibleFile",
 *     @OA\Xml(name="BibleFile")
 * )
 *
 */
class BibleFile extends Model
{
    protected $connection = 'dbp';
    protected $table = 'bible_files';
    protected $hidden = ['created_at','updated_at'];

    /**
     *
     * @OA\Property(
     *   title="id",
     *   type="integer",
     *   description="The id",
     *   minimum=0,
     *   example=4
     * )
     *
     */
    protected $id;
    /**
     *
     * @OA\Property(
     *   title="hash_id",
     *   type="string",
     *   example="7ccd81c2546e",
     *   description="The hash_id",
     * )
     *
     */
    protected $hash_id;
    /**
     *
     * @OA\Property(
     *   title="book_id",
     *   type="string",
     *   example="MAT",
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
     *   type="string",
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
     *   title="verse_text",
     *   type="string",
     *   description="If the BibleFile model returns text instead of a file_name this field will contain it.",
     *   example="And God said unto Abraham, And as for thee, thou shalt keep my covenant, thou, and thy seed after thee throughout their generations."
     * )
     *
     */
    protected $verse_text;

    /**
     *
     * @OA\Property(
     *   title="file_name",
     *   type="string",
     *   description="The file_name",
     *   example="ACHBSU_70_MAT_1.html",
     *   maxLength=191
     * )
     *
     */
    protected $file_name;

    /**
     *
     * @OA\Property(
     *   title="file_size",
     *   type="integer",
     *   description="The file size",
     *   example="5486618"
     * )
     *
     */
    protected $file_size;

    /**
     *
     * @OA\Property(
     *   title="duration",
     *   type="integer",
     *   description="If the file has a set length of time, this field indicates that time in milliseconds",
     *   nullable=true,
     *   minimum=0,
     *   example=683
     * )
     *
     */
    protected $duration;

    public function language()
    {
        return $this->hasOne(Language::class);
    }

    public function fileset()
    {
        return $this->belongsTo(BibleFileset::class, 'hash_id', 'hash_id');
    }

    public function connections()
    {
        return $this->belongsTo(BibleFilesetConnection::class);
    }

    public function bible()
    {
        return $this->hasManyThrough(Bible::class, BibleFilesetConnection::class, 'hash_id', 'id', 'hash_id', 'bible_id');
    }

    public function book()
    {
        return $this->belongsTo(Book::class, 'book_id', 'id')->orderBy('protestant_order');
    }

    public function testament()
    {
        return $this->belongsTo(Book::class, 'book_id', 'id')->select(['book_testament','id']);
    }

    public function timestamps()
    {
        return $this->hasMany(BibleFileTimestamp::class);
    }

    public function currentTitle()
    {
        return $this->hasOne(BibleFileTitle::class, 'file_id', 'id');
    }

    public function streamBandwidth()
    {
        return $this->hasMany(StreamBandwidth::class);
    }
}
