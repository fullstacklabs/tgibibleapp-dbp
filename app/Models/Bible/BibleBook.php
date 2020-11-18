<?php

namespace App\Models\Bible;

use Illuminate\Database\Eloquent\Model;

/**
 * App\Models\Bible\BibleBook
 *
 * @mixin \Eloquent
 *
 * @OA\Schema (
 *     type="object",
 *     description="The Bible Book Model stores the vernacular book titles and chapters",
 *     title="Bible Book",
 *     @OA\Xml(name="BibleBook")
 * )
 *
 */
class BibleBook extends Model
{
    protected $connection = 'dbp';
    protected $table = 'bible_books';
    public $incrementing = false;
    public $fillable = ['abbr','book_id', 'name', 'name_short', 'chapters'];
    public $hidden = ['created_at','updated_at','bible_id'];


    /**
     *
     * @OA\Property(ref="#/components/schemas/Bible/properties/id")
     * @method static BibleFileset whereBibleId($value)
     * @property string $bible_id
     *
     */
    protected $bible_id;

    /**
     *
     * @OA\Property(ref="#/components/schemas/Book/properties/id")
     * @method static BibleFileset whereBookId($value)
     * @property string $book_id
     *
     */
    protected $book_id;

    /**
     *
     * @OA\Property(
     *     title="name",
     *     description="The name of the book in the language of the bible",
     *     type="string",
     *     example="1 Corinthians",
     *     maxLength=191
     * )
     *
     * @method static BibleFileset whereName($value)
     * @property string $name
     *
     */
    protected $name;

    /**
     *
     * @OA\Property(
     *     title="name_short",
     *     description="If the vernacular name has an abbreviated form, it will be stored hre",
     *     type="string",
     *     example="1 Corinthians",
     *     maxLength=191
     * )
     *
     * @method static BibleFileset whereNameShort($value)
     * @property string $name_short
     *
     */
    protected $name_short;

    /**
     *
     * @OA\Property(
     *     title="chapters",
     *     description="A string of the chapters in the book separated by a comma",
     *     type="string",
     *     example="1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16",
     *     maxLength=491
     * )
     *
     * @method static BibleFileset whereChapters($value)
     * @property string $chapters
     *
     */
    protected $chapters;

    /**
     * Remove brackets from uncertain book names
     *
     * @param  string $name
     *
     * @return string
     */
    public function getNameAttribute($name)
    {
        return $this->attributes['name'] = trim($name, '[]');
    }

    public function getNameShortAttribute($name_short)
    {
        return $this->attributes['name_short'] = trim($name_short, '[]');
    }


    public function bible()
    {
        return $this->belongsTo(Bible::class);
    }

    public function book()
    {
        return $this->belongsTo(Book::class);
    }
}
