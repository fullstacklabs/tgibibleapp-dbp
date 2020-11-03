<?php

namespace App\Models\Collection;

use Illuminate\Database\Eloquent\Model;
use App\Models\Collection\CollectionPlaylist;
use App\Models\User\User;

/**
 * App\Models\Collection
 * @mixin \Eloquent
 *
 * @property int $id
 * @property string $name
 * @property bool $featured
 * @property string $user_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 *
 * @OA\Schema (
 *     type="object",
 *     description="The User created Collection",
 *     title="Collection"
 * )
 *
 */
class Collection extends Model
{
    protected $connection = 'dbp_users';
    public $table         = 'collections';
    protected $fillable   = ['user_id', 'name', 'language_id', 'order_column'];

    /**
     *
     * @OA\Property(
     *   title="id",
     *   type="integer",
     *   description="The collection id",
     *   minimum=0
     * )
     *
     */
    protected $id;
    /**
     *
     * @OA\Property(
     *   title="name",
     *   type="string",
     *   description="The name of the collection"
     * )
     *
     */
    protected $name;
    /**
     *
     * @OA\Property(
     *   title="featured",
     *   type="boolean",
     *   description="If the collection is featured"
     * )
     *
     */
    protected $featured;
    /**
     *
     * @OA\Property(
     *   title="user_id",
     *   type="string",
     *   description="The user that created the collection"
     * )
     *
     */
    protected $user_id;
    /**
     *
     * @OA\Property(
     *   title="language_id",
     *   type="string",
     *   description="The lanauge of the collection"
     * )
     *
     */
    protected $language_id;
    /**
     *
     * @OA\Property(
     *   title="order_column",
     *   type="string",
     *   description="The order of this collection in user's collections"
     * )
     *
     */
    protected $order_column;
    /**
     *
     * @OA\Property(
     *   title="created_at",
     *   type="string",
     *   description="The timestamp the collection was created at"
     * )
     *
     * @method static Note whereCreatedAt($value)
     * @public Carbon $created_at
     */
    protected $created_at;
    /** @OA\Property(
     *   title="updated_at",
     *   type="string",
     *   description="The timestamp the collection was last updated at",
     *   nullable=true
     * )
     *
     * @method static Note whereUpdatedAt($value)
     * @public Carbon|null $updated_at
     */
    protected $updated_at;

    public function getFeaturedAttribute($featured)
    {
        return (bool) $featured;
    }

    public function user()
    {
        return $this->belongsTo(User::class)->select('id', 'name');
    }

    public function playlists()
    {
        return $this->hasMany(CollectionPlaylist::class)->orderBy('order_column');
    }
}
