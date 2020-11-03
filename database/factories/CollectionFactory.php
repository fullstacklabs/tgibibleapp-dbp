<?php

use Faker\Generator as Faker;

use App\Models\User\User;
use App\Models\Collection\Collection;
use App\Models\Collection\CollectionPlaylist;
use App\Models\Playlist\Playlist;

// Collection Factory
$factory->define(Collection::class, function (Faker $faker) {
    return [
        // this is auto increment, we don't need to set it
        // random can cause conflicts
        //'id'               => random_int(0, 9999),
        //'id'               => factory(User::class)->create()->id,
        'name'             => $faker->name,
    ];
});

// Collection Playlist Factory
$factory->define(CollectionPlaylist::class, function (Faker $faker) {
    return [
        // this is auto increment, we don't need to set it
        // random can cause conflicts
        //'id'               => factory(User::class)->create()->id,
        'collection_id'              => factory(Collection::class)->create()->id,
        //'playlist_id'                => factory(Playlist::class)->create()->id,
        'playlist_id'                => 4,
        'order_column'               => random_int(0, 9999),
    ];
});
