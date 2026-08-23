<?php

namespace App\Http\Responses;

use App\Enums\FederationErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

/**
 * The federation error envelope with its defined codes and HTTP mappings
 * (API-9).
 *
 * // api-design.md §Errors
 */
class FederationErrorResponse
{
    /**
     * @param  array<string, mixed>  $details
     */
    public static function make(FederationErrorCode $code, string $message, array $details = []): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $code->value,
                'message' => $message,
                'details' => $details === [] ? ['well_known' => '/.well-known/openyacht'] : $details,
            ],
            'meta' => [
                'request_id' => (string) Str::uuid(),
                'time' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            ],
        ], $code->httpStatus());
    }
}
