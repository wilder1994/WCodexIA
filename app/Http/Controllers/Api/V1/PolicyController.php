<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Security\Services\PolicyCatalog;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

final class PolicyController extends Controller
{
    public function __invoke(PolicyCatalog $catalog): JsonResponse
    {
        return response()->json([
            'data' => $catalog->all(),
        ]);
    }
}
