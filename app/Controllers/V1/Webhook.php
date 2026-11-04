<?php

namespace App\Controllers\V1;

use App\Controllers\BaseController;
use App\Core\Api\ApiResponse;
use App\Core\Auth\CurrentToken;
use App\Core\Support\ChangeContext;
use App\Core\Support\ConfigException;

/**
 * Webhook of the token's project: an empty POST to the URL when new mail can be fetched.
 *
 * GET    /v1/webhook                                        state (URL shortened + SHA-256, last ping), data null if none
 * PUT    /v1/webhook {"url": "https://...", "cooldown_seconds": 2}   set or replace (idempotent)
 * DELETE /v1/webhook                                        remove
 *
 * The ping comes after the mail is stored and fetchable, at most once per cooldown (mail during the cooldown is
 * announced when it ends). Never repeated: the application keeps polling. Journal: webhook.updated/deleted/sent/failed.
 */
class Webhook extends BaseController
{
    public function show()
    {
        return ApiResponse::ok(['data' => service('webhookService')::present(service('webhookService')->get(CurrentToken::projectId()))]);
    }

    public function put()
    {
        $body = $this->request->getJSON(true);
        if (! is_array($body) || ! is_string($body['url'] ?? null)
            || (array_key_exists('cooldown_seconds', $body) && ! is_int($body['cooldown_seconds']))) {
            return ApiResponse::error(400, 'INVALID_REQUEST', 'Body must be {"url": "https://...", "cooldown_seconds": <int, optional>}');
        }
        try {
            [$w, $changed] = service('webhookService')->set(CurrentToken::projectId(), $body['url'], $body['cooldown_seconds'] ?? null,
                new ChangeContext('api', (string) CurrentToken::get()['token_id']));
        } catch (ConfigException $e) {
            return ApiResponse::error(422, $e->errorCode, $e->getMessage());
        }

        return ApiResponse::ok(['data' => service('webhookService')::present($w), 'changed' => $changed]);
    }

    public function delete()
    {
        $deleted = service('webhookService')->delete(CurrentToken::projectId(), new ChangeContext('api', (string) CurrentToken::get()['token_id']));

        return ApiResponse::ok(['deleted' => $deleted]);
    }
}
