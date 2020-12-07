<?php

namespace App\Transformers;

use App\Models\User\Study\Highlight;
use GuzzleHttp\Client;

class UserHighlightsTransformer extends BaseTransformer
{
    public function transform(Highlight $highlight)
    {
        switch ((int) $this->version) {
             case 2:
                 return $this->transformForV2($highlight);
             case 3:
                 return $this->transformForV2($highlight);
             case 4:
                 return $this->transformForV4($highlight);
             default:
                 return $this->transformForV4($highlight);
         }
    }

    /**
     * @OA\Schema (
     *    type="object",
     *    schema="v4_internal_highlights_index",
     *    description="The v4 highlights index response. Note the fileset_id is being used to identify the item instead of the bible_id.
     *    This is important as different filesets may have different numbers for the highlighted words field depending on their revision.",
     *    title="v4_internal_highlights_index",
     *    @OA\Xml(name="v4_internal_highlights_index"),
     *      allOf={
     *        @OA\Schema(ref="#/components/schemas/pagination.alternate"),
     *      },
     *    @OA\Property(property="data", type="array",
     *      @OA\Items(
     *              @OA\Property(property="id",                     ref="#/components/schemas/Highlight/properties/id"),
     *              @OA\Property(property="fileset_id",             ref="#/components/schemas/BibleFileset/properties/id"),
     *              @OA\Property(property="book_id",                ref="#/components/schemas/Book/properties/id"),
     *              @OA\Property(property="book_name",              ref="#/components/schemas/BibleBook/properties/name"),
     *              @OA\Property(property="chapter",                ref="#/components/schemas/BibleFile/properties/chapter_start"),
     *              @OA\Property(property="verse_start",            ref="#/components/schemas/BibleFile/properties/verse_start"),
     *              @OA\Property(property="verse_end",              ref="#/components/schemas/BibleFile/properties/verse_end"),
     *              @OA\Property(property="verse_text",             ref="#/components/schemas/BibleFile/properties/verse_text"),
     *              @OA\Property(property="highlight_start",        ref="#/components/schemas/Highlight/properties/highlight_start"),
     *              @OA\Property(property="highlighted_words",      ref="#/components/schemas/Highlight/properties/highlighted_words"),
     *              @OA\Property(property="highlighted_color",      ref="#/components/schemas/Highlight/properties/highlighted_color")
     *           ),
     *     )
     *    )
     *   )
     * )
     * @param Highlight $highlight
     *
     * @return array
     */
    public function transformForV4(Highlight $highlight)
    {
        if ($highlight->color && is_object($highlight->color)) {
            $this->checkColorPreference($highlight);
        } else {
            // set a default highlight
            $highlight->color = 'green'; // V2 uses green
        }

        if (!isset($highlight->book->name)) {
            // likely remote content set up
            $book_name = '';
            $content_config = config('services.content');
            if (!empty($content_config['url'])) {
                if ($highlight->book_name) {
                    $book_name = $highlight->book_name;
                    $testament = $highlight->testament;
                } else {
                    // we can pull book name from content server
                    $client = false;
                    $book_data = cacheRemember('bible_book_data',
                       [$highlight->bible_id, $highlight->book_id], now()->addDay(),
                       function () use ($highlight, $content_config, $client) {
                           if (!$client) {
                               $client = new Client();
                           }
                           $res = $client->get($content_config['url'] . 'bibles/' .
                            $highlight->bible_id . '/book/' . $highlight->book_id .
                            '?v=4&key=' . $content_config['key']);
                           $result = json_decode($res->getBody() . '', true);
                           if ($result && $result['data'] && count($result['data'])) {
                               return $result['data'][0];
                           }
                       });
                    $book_name = $book_data['name'];
                    $testament = $book_data['testament'];
                }
            }
        } else {
            // can use relationship to get content locally
            $book_name = optional($highlight->book)->name;
        }

        if (isset($highlight->fileset_info)) {
            $highlight_fileset_info = $highlight->fileset_info;
            $verse_text = $highlight_fileset_info->get('verse_text');
            // what type is this? collection? array? object?
            $audio_filesets = $highlight_fileset_info->get('audio_filesets');
        } else {
            // likely remote content set up
            $verse_text = $highlight->verse_text;
            $audio_filesets = $highlight->audio_filesets;
            $content_config = config('services.content');
            // these may not be set if the order isnt' by book, and no chapter/query search
            if (!empty($content_config['url']) && !$highlight->content_server_checked) {
                // remote content
                $bible_id = $highlight['bible_id'];
                $book_id = $highlight['book_id'];
                // we're using $testament and $client from above
                $data = cacheRemember('bible_book_filesets_testament', [$bible_id, $book_id, $testament],
                  now()->addDay(), function () use ($content_config, $bible_id, $book_id, $testament, $client) {
                      if (!$client) {
                          $client = new Client();
                      }
                      $res = $client->get($content_config['url'] . 'bibles/' .
                      $bible_id. '/books/'. $book_id . '/testament/' . $testament .
                      '?v=4&key=' . $content_config['key']);
                      return json_decode($res->getBody() . '', true);
                  });
                $audio_filesets = $data['audio_filesets'];
                if ($data['text_fileset']) {
                    // not sure this is the same data
                    // because we're not using withVernacularMetaData filter
                    // withVernacularMetaData is tough because we'd need to pass each highlight

                    // get verse data
                    $bible_verse_text = cacheRemember('bible_verses', [$bible_id],
                      now()->addDay(), function () use ($content_config, $bible_id, $client) {
                          if (!$client) {
                              $client = new Client();
                          }
                          $res = $client->get($content_config['url'] . 'bibles/'
                          .  $bible_id . '/verses?v=4&key=' . $content_config['key']);
                          return json_decode($res->getBody() . '', true);
                      });

                    if (isset($bible_verse_text['books'])) {
                        // make book_id to book_data lookup map
                        $bible_book_verses_map = [];
                        foreach ($bible_verse_text['books'] as $book) {
                            $bible_book_verses_map[$book['book_id']] = $book;
                        }
                        if (isset($bible_book_verses_map[$highlight->book_id])) {
                            $bible_verse = collect($bible_book_verses_map[$highlight->book_id])->filter(function ($arr) use ($highlight) {
                                //$inBook     = $arr[4] == $highlight->book_id;     if (!$inBook) return false;
                                $inChapter  = $arr[0] == $highlight->chapter;
                                if (!$inChapter) {
                                    return false;
                                }
                                $afterStart = $arr[1] >= $highlight->verse_start;
                                if (!$afterStart) {
                                    return false;
                                }
                                $beforEnd   = $arr[2] <= $highlight->verse_end;
                                if (!$beforEnd) {
                                    return false;
                                }
                                return true;
                            });
                            if ($bible_verse->count()) {
                                $arr = $bible_verse->first();
                                $verse_text = $arr[3];
                            }
                        }
                    }
                }
            }
        }

        return [
            'id'                => (int) $highlight->id,
            'bible_id'          => (string) $highlight->bible_id,
            'book_id'           => (string) $highlight->book_id,
            'book_name'         => (string) $book_name,
            'chapter'           => (int) $highlight->chapter,
            'verse_start'       => (int) $highlight->verse_start,
            'verse_end'         => (int) $highlight->verse_end,
            'verse_text'        => (string) $verse_text,
            'highlight_start'   => (int) $highlight->highlight_start,
            'highlighted_words' => $highlight->highlighted_words,
            'highlighted_color' => $highlight->color,
            'tags'              => $highlight->tags,
            'audio_filesets'    => $audio_filesets
        ];
    }

    // build higlight->color css attribute
    private function checkColorPreference($highlight)
    {
        if (!$highlight->color) {
            return;
        }
        $color_preference = checkParam('prefer_color') ?? 'rgba';
        if ($color_preference === 'hex') {
            $highlight->color = '#' . $highlight->color->hex;
        }
        if ($color_preference === 'rgb') {
            $highlight->color = 'rgb(' . $highlight->color->red . ',' . $highlight->color->green . ',' . $highlight->color->blue . ')';
        }
        if ($color_preference === 'rgba') {
            $highlight->color = 'rgba(' . $highlight->color->red . ',' . $highlight->color->green . ',' . $highlight->color->blue . ',' . $highlight->color->opacity . ')';
        }
    }

    /**
     * This transformer modifies the Highlight response to reflect
     * the expected return for the old Version 2 DBP api routes
     * and regenerates the aged dam_id from the new bible_id
     *
     * @see Controller: \App\Http\Controllers\Connections\V2Controllers\UsersControllerV2::annotationHighlight
     * @see Old Route:  http://api.bible.is/annotations/highlight?dbt_data=1&dbt_version=2&hash=test_hash&key=test_key&reply=json&user_id=313117&v=1
     * @see New Route:  https://api.dbp.test/v2/annotations/highlight?key=test_key&pretty&v=2&user_id=5
     *
     * @param $highlight
     * @return array
     */
    public function transformForV2($highlight)
    {
        $dam_id = $highlight->bible_id.substr($highlight->book->book->book_testament, 0, 1).'2ET';
        $highlight_fileset_info = $highlight->fileset_info;
        $verse_text = $highlight_fileset_info->get('verse_text');
        $audio_filesets = $highlight_fileset_info->get('audio_filesets');

        return [
            'id'                   => (string) $highlight->id,
            'user_id'              => (string) $highlight->user_id,
            'dam_id'               => $dam_id,
            'book_id'              => (string) $highlight->book->book->id_osis,
            'chapter_id'           => (string) $highlight->chapter,
            'verse_id'             => (string) $highlight->verse_start,
            'color'                => $highlight->color->color ?? 'green',
            'created'              => (string) $highlight->created_at,
            'updated'              => (string) $highlight->updated_at,
            'dbt_data'             => [[
                'book_name'        => (string) $highlight->book->name,
                'book_id'          => (string) $highlight->book_id,
                'book_order'       => (string) $highlight->book->book->protestant_order,
                'chapter_id'       => (string) $highlight->chapter,
                'chapter_title'    => 'Chapter '.$highlight->chapter,
                'verse_id'         => (string) $highlight->verse_start,
                'verse_text'       => $verse_text,
                'paragraph_number' => '1',
                'audio_filesets'   => $audio_filesets
            ]]
        ];
    }
}
