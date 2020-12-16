<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Validator;
use GuzzleHttp\Client;

class ContentServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        Validator::extend('remote_biblebook_checker', function ($attribute, $value, $parameters, $validator) {
            $content_config = config('services.content');
            $valid = true;
            if (!empty($content_config['url'])) {
                $input = $validator->getData();
                $book_id = array_get($input, 'book_id');
                $client = new Client();
                if ($book_id) {
                    // validate bible_id and book_id exists
                    $result = cacheRemember('bibles_book_existence',
                      [$value, $book_id], now()->addDay(), function () use ($value, $book_id, $content_config, $client) {
                          try {
                              $res = $client->get($content_config['url'] .
                               'bibles/' . $value . '/book/'. $book_id .
                               '?v=4&key=' . $content_config['key'],
                               ['http_errors' => false]);
                              return json_decode($res->getBody() . '', true);
                          } catch (\GuzzleHttp\Exception\RequestException $e) {
                              return ['error' => 'request exception'];
                          }
                      });
                    if (isset($result['error'])) {
                        $valid = false;
                    } elseif (isset($result['data']) && !count($result['data'])) {
                        // book missing
                        $valid = false;
                    }
                } else {
                    // validate (only) bible_id exists
                    $result = cacheRemember('bibles_existence', [$value],
                      now()->addDay(), function () use ($value, $content_config, $client) {
                          try {
                              $res = $client->get($content_config['url'] . 'bibles/' . $value .
                               '/name/'. $GLOBALS['i18n_id'] . '?v=4&key=' . $content_config['key'],
                               ['http_errors' => false]);
                              return json_decode($res->getBody() . '', true);
                          } catch (\GuzzleHttp\Exception\RequestException $e) {
                              return ['error' => 'request exception'];
                          }
                      });
                    if (isset($result['error'])) {
                        // bible missing
                        $valid = false;
                    }
                }
            }
            return $valid;
        }, ':attribute does not exist');
    }

    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        //
    }
}
