<?php

namespace App\Models\Collection;

use Illuminate\Database\Eloquent\Model;

/**
 * App\Models\CollectionPlaylist
 * @mixin \Eloquent
 *
 * @property int $id
 * @property int $collection_id
 * @property int $playlist_id
 * @property int $order_column
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * * @OA\Schema (
 *     type="object",
 *     description="The Collection Playlists data",
 *     title="CollectionPlaylist"
 * )
 */
class CollectionPlaylist extends Model
{
    protected $connection = 'dbp_users';
    public $table         = 'collection_playlists';
    protected $fillable   = ['collection_id', 'playlist_id', 'order_column'];

    /**
     *
     * @OA\Property(
     *   title="id",
     *   type="integer",
     *   description="The collection playlist id",
     *   minimum=0
     * )
     *
     */
    protected $id;
    /**
     *
     * @OA\Property(
     *   title="collection_id",
     *   type="integer",
     *   description="The collection id",
     *   minimum=0
     * )
     *
     */
    protected $collection_id;
    /**
     *
     * @OA\Property(
     *   title="playlist_id",
     *   type="integer",
     *   description="The playlist id",
     *   minimum=0
     * )
     *
     */
    protected $playlist_id;
    /**
     *
     * @OA\Property(
     *   title="order_column",
     *   type="integer",
     *   description="The collection playlist item order",
     *   minimum=0
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
}
