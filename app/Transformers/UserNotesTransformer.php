<?php

namespace App\Transformers;

use App\Models\User\Study\Note;
use League\Fractal\TransformerAbstract;
use GuzzleHttp\Client;

class UserNotesTransformer extends TransformerAbstract
{
    /**
     * @OA\Schema (
     *        type="object",
     *        schema="v4_notes_index",
     *        description="The transformed user notes",
     *        title="v4_user_notes",
     *      @OA\Xml(name="v4_notes_index"),
     *      allOf={
     *        @OA\Schema(ref="#/components/schemas/pagination.alternate"),
     *      },
     *   @OA\Property(property="data", type="array",
     *      @OA\Items(ref="#/components/schemas/v4_note")
     *    )
     *  )
     *)
     * @OA\Schema(
     *  schema="v4_note",
     *  type="object",
     *          @OA\Property(property="id",             ref="#/components/schemas/Note/properties/id"),
     *          @OA\Property(property="bible_id",       ref="#/components/schemas/Note/properties/bible_id"),
     *          @OA\Property(property="book_id",        ref="#/components/schemas/Note/properties/book_id"),
     *          @OA\Property(property="book_name",      ref="#/components/schemas/BibleBook/properties/name"),
     *          @OA\Property(property="chapter",        ref="#/components/schemas/Note/properties/chapter"),
     *          @OA\Property(property="verse_start",    ref="#/components/schemas/Note/properties/verse_start"),
     *          @OA\Property(property="verse_end",      ref="#/components/schemas/Note/properties/verse_end"),
     *          @OA\Property(property="verse_text",     ref="#/components/schemas/BibleFile/properties/verse_text"),
     *          @OA\Property(property="notes",          ref="#/components/schemas/Note/properties/notes"),
     *          @OA\Property(property="created_at",     ref="#/components/schemas/Note/properties/created_at"),
     *          @OA\Property(property="updated_at",     ref="#/components/schemas/Note/properties/updated_at"),
     *          @OA\Property(property="bible_name",     ref="#/components/schemas/Note/properties/bible_name"),
     *          @OA\Property(property="tags",           ref="#/components/schemas/AnnotationTag"),
     * )
     *
     *
     * @param Note $note
     * @return array
     */
    public function transform(Note $note)
    {
        $content_config = config('services.content');
        if (empty($content_config['url'])) {
            $book_name = optional($note->book)->name;
            $bible_name = $note->bible_name;
        } else {
            // getBibleNameAttribute
            $bible_name = cacheRemember('bible_name', [$note->bible_id, $GLOBALS['i18n_id']], now()->addDay(), function () use ($note, $content_config) {
                $client = new Client();
                $res = $client->get($content_config['url'] . 'bibles/' . $note->bible_id
                  . '/name/' . $GLOBALS['i18n_id'] . '?v=4&key=' . $content_config['key']);
                return $res->getBody().'';
            });
            // book
            $book_name = cacheRemember('book_data', [$note->bible_id, $note->book_id], now()->addDay(), function () use ($note, $content_config) {
                $client = new Client();
                $res = $client->get($content_config['url'] . 'bibles/' . $note->bible_id .
                  '/book/' . $note->book_id . '?v=4&key=' . $content_config['key']);
                $book_data = json_decode($res->getBody() . '');
                $book = '';
                if ($book_data && $book_data->data &&count($book_data->data)) {
                  $book = $book_data->data[0]->name;
                }
                return $book;
            });
        }
        return [
          'id' => (int) $note->id,
          'bible_id' => (string) $note->bible_id,
          'bible_name' => (string) $bible_name,
          'book_id' => (string) $note->book_id,
          'book_name' => (string) $book_name,
          'chapter' => (int) $note->chapter,
          'verse_start' => (int) $note->verse_start,
          'verse_end' => (int) $note->verse_end,
          'verse_text' => (string) $note->verse_text,
          'notes' => (string) $note->notes,
          'created_at' => (string) $note->created_at,
          'updated_at' => (string) $note->updated_at,
          'tags' => $note->tags
        ];
    }
}
