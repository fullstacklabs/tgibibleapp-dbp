<?php

namespace App\Models\Bible;

use Illuminate\Database\Eloquent\Model;

/**
 * App\Models\Bible\BibleFileTimestamp
 *
 * @property-read \App\Models\Bible\Book $book
 * @mixin \Eloquent
 *
 * @OA\Schema (
 *     type="object",
 *     description="The Bible File Timestamp tag model partitions the file into verse by verse sections",
 *     title="Bible File Timestamp",
 *     @OA\Xml(name="BibleFileTimestamp")
 * )
 *
 */

class BibleFileTimestamp extends Model
{
    protected $connection = 'dbp';
    protected $table = 'bible_file_timestamps';

    /**
     *
     * @OA\Property(
     *   title="file_id",
     *   type="integer",
     *   description="The incrementing id of the file timestamp",
     *   minimum=1
     * )
     *
     * @method static BibleFileTimestamp whereFileId($value)
     * @property int $id
     */
    protected $id;

    /**
     *
     * @OA\Property(
     *   title="verse_start",
     *   type="integer",
     *   description="The starting verse for the file timestamp",
     *   example=1,
     *   minimum=1
     * )
     *
     * @method static BibleFileTimestamp whereVerseStart($value)
     * @property int|null $verse_start
     *
     */
    protected $verse_start;

    /**
     *
     * @OA\Property(
     *   title="verse_end",
     *   type="integer",
     *   example=10,
     *   description="The ending verse for the file timestamp",
     *   minimum=1
     * )
     *
     * @method static BibleFileTimestamp whereVerseEnd($value)
     * @property int|null $verse_end
     *
     */
    protected $verse_end;

    /**
     *
     * @OA\Property(
     *   title="timestamp",
     *   type="number",
     *   description="The time (in seconds) represented by the timestamp",
     *   example=10.19,
     *   minimum=1
     * )
     *
     * @method static BibleFileTimestamp whereTimestamp($value)
     * @property float $timestamp
     *
     */
    protected $timestamp;



    public $incrementing = false;

    public function bibleFile()
    {
        return $this->belongsTo(BibleFile::class);
    }

    public function book()
    {
        return $this->belongsTo(Book::class);
    }

    public function fileset()
    {
        return $this->belongsTo(BibleFileset::class, 'hash_id', 'hash_id');
    }
}
