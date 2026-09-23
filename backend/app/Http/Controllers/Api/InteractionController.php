<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListInteractionsRequest;
use App\Http\Requests\StoreInteractionRequest;
use App\Http\Resources\InteractionResource;
use App\Models\Contact;
use App\Services\InteractionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class InteractionController extends Controller
{
    public function __construct(private readonly InteractionService $interactions) {}

    public function index(ListInteractionsRequest $request, Contact $contact): JsonResponse
    {
        $interactions = $this->interactions->list($contact, $request->validated());

        return InteractionResource::collection($interactions)->response();
    }

    public function store(StoreInteractionRequest $request, Contact $contact): JsonResponse
    {
        ['interaction' => $interaction, 'replayed' => $replayed] = $this->interactions->create(
            $request->user(),
            $contact,
            $request->validated(),
        );

        return (new InteractionResource($interaction))
            ->response()
            ->setStatusCode($replayed ? Response::HTTP_OK : Response::HTTP_CREATED)
            ->header('Idempotency-Replayed', $replayed ? 'true' : 'false');
    }
}
