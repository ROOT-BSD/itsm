<?php

namespace App\Controllers\Api;

use App\Core\ApiResponse;
use App\Services\ApiSerializer;

/** GET /api/v1/me — хто я за цим токеном (зручно для перевірки підключення). */
class MeApiController extends ApiController
{
    public function show(): void
    {
        ApiResponse::ok([
            'user' => ApiSerializer::user($this->user),
            'token' => [
                'name' => $this->token['name'],
                'scope' => $this->token['scope'],
                'expires_at' => ApiSerializer::iso($this->token['expires_at'] ?? null),
            ],
        ]);
    }
}
