<?php

namespace App\Transformers;

use App\Models\User\Study\Bookmark;
use GuzzleHttp\Client;

class UserBookmarksTransformer extends BaseTransformer
{
    public function transform(Bookmark $bookmark)
    {
        switch ((int) $this->version) {
            case 2:
                return $this->transformForV2($bookmark);
            case 3:
                return $this->transformForV2($bookmark);
            case 4:
                return $this->transformForV4($bookmark);
            default:
                return $this->transformForV4($bookmark);
        }
    }

    /**
     * This transformer modifies the Bookmark response to reflect
     * the expected return for the old Version 2 DBP api route
     * and regenerates the old dam_id from the new bible_id
     *
     * @see Controller: \App\Http\Controllers\Connections\V2Controllers\UsersControllerV2::annotationBookmark
     * @see Old Route:  http://api.bible.is/annotations/bookmark?dbt_data=1&dbt_version=2&hash=test_hash&key=test_key&reply=json&user_id=313117&v=1
     * @see New Route:  https://api.dbp.test/v2/annotations/bookmark?key=test_key&pretty&v=2&user_id=5
     *
     * @param $bookmark
     * @return array
     */
    public function transformForV2(Bookmark $bookmark)
    {
        return [
            'id'                   => (string) $bookmark->id,
            'user_id'              => (string) $bookmark->user_id,
            'dam_id'               => $bookmark->bible_id.substr($bookmark->book->book_testament, 0, 1).'2ET',
            'book_id'              => (string) $bookmark->book->id_osis ? $bookmark->book->id_osis : $bookmark->book_id,
            'chapter_id'           => (string) $bookmark->chapter,
            'verse_id'             => (string) $bookmark->verse_start,
            'created'              => (string) $bookmark->created_at,
            'updated'              => (string) $bookmark->updated_at,
            'dbt_data'             => [[
                'book_name'        => (string) $bookmark->book->name,
                'book_id'          => (string) $bookmark->book->id_osis,
                'book_order'       => (string) $bookmark->book->protestant_order,
                'chapter_id'       => (string) $bookmark->chapter,
                'chapter_title'    => trans('api.chapter_title_prefix').' '.$bookmark->chapter,
                'verse_id'         => (string) $bookmark->verse_start,
                'verse_text'       => 'ipsum lorem',
                'paragraph_number' => '1'
            ]]
        ];
    }

    /**
     * @OA\Schema (
     *        type="object",
     *        schema="v4_internal_user_bookmarks",
     *        description="The transformed user bookmarks",
     *        title="v4_internal_user_bookmarks",
     *      @OA\Xml(name="v4_internal_user_bookmarks"),
     *      allOf={
     *        @OA\Schema(ref="#/components/schemas/pagination.alternate"),
     *      },
     *   @OA\Property(property="data", type="array",
     *      @OA\Items(
     *          @OA\Property(property="id",             type="integer"),
     *          @OA\Property(property="bible_id",       ref="#/components/schemas/Bible/properties/id"),
     *          @OA\Property(property="book_id",        ref="#/components/schemas/Book/properties/id"),
     *          @OA\Property(property="book_name",              ref="#/components/schemas/BibleBook/properties/name"),
     *          @OA\Property(property="chapter",        ref="#/components/schemas/BibleFile/properties/chapter_start"),
     *          @OA\Property(property="verse",          ref="#/components/schemas/BibleFile/properties/verse_start"),
     *          @OA\Property(property="verse_text",          ref="#/components/schemas/BibleFile/properties/verse_text"),
     *          @OA\Property(property="created_at",     ref="#/components/schemas/Bookmark/properties/created_at"),
     *          @OA\Property(property="updated_at",     ref="#/components/schemas/Bookmark/properties/updated_at")
     *        )
     *    )
     *   )
     *)
     *
     * @param Bookmark $bookmark
     * @return array
     */
    public function transformForV4(Bookmark $bookmark)
    {
        // no book relationship?
        if (!isset($bookmark->book->name)) {
            // likely remote content set up
            $book_name = '';
            $content_config = config('services.content');
            if (!empty($content_config['url'])) {
                // we can pull book name from content server
                $book_name = cacheRemember('book_name_data',
                  [$bookmark->bible_id, $bookmark->book_id], now()->addDay(),
                  function () use ($bookmark, $content_config) {
                      $client = new Client();
                      $res = $client->get($content_config['url'] . 'bibles/' .
                       $bookmark->bible_id . '/book/' . $bookmark->book_id .
                       '?v=4&key=' . $content_config['key']);
                      $result = json_decode($res->getBody() . '', true);
                      if ($result && $result['data'] && count($result['data'])) {
                          return $result['data'][0]['name'];
                      }
                  });
            }
        } else {
            // can use relationship to get content locally
            $book_name = optional($bookmark->book)->name;
        }
        return [
          'id' => (int) $bookmark->id,
          'bible_id' => (string) $bookmark->bible_id,
          'book_id' => (string) $bookmark->book_id,
          'book_name' => (string) $book_name,
          'chapter' => (int) $bookmark->chapter,
          'verse' => (int) $bookmark->verse_start,
          'verse_text' => (string) $bookmark->verse_text,
          'created_at' => (string) $bookmark->created_at,
          'updated_at' => (string) $bookmark->updated_at,
          'tags' => $bookmark->tags
        ];
    }
}
