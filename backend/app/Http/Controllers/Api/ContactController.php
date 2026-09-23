<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AssignContactRequest;
use App\Http\Requests\ListContactsRequest;
use App\Http\Requests\StoreContactRequest;
use App\Http\Requests\UpdateContactRequest;
use App\Http\Resources\ContactResource;
use App\Models\Contact;
use App\Services\ContactService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class ContactController extends Controller
{
    public function __construct(private readonly ContactService $contacts) {}

    public function index(ListContactsRequest $request): JsonResponse
    {
        $contacts = $this->contacts->list($request->user(), $request->validated());

        return ContactResource::collection($contacts)->response();
    }

    public function store(StoreContactRequest $request): JsonResponse
    {
        $contact = $this->contacts->create($request->user(), $request->validated());

        return (new ContactResource($contact->loadMissing('assignedAgent')))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Contact $contact): JsonResponse
    {
        $this->authorize('view', $contact);

        $contact->load([
            'assignedAgent',
            'interactions' => fn ($query) => $query->with('creator')->orderBy('timestamp', 'desc')->orderBy('id', 'desc')->limit(20),
        ]);

        return (new ContactResource($contact))->response();
    }

    public function update(UpdateContactRequest $request, Contact $contact): JsonResponse
    {
        $updated = $this->contacts->update($contact, $request->validated());

        return (new ContactResource($updated))->response();
    }

    public function destroy(Contact $contact): JsonResponse
    {
        $this->authorize('delete', $contact);

        $this->contacts->archive($contact);

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    public function restore(Contact $contact): JsonResponse
    {
        $this->authorize('restore', $contact);

        $this->contacts->restore($contact);

        return (new ContactResource($contact->refresh()->loadMissing('assignedAgent')))->response();
    }

    public function assign(AssignContactRequest $request, Contact $contact): JsonResponse
    {
        $updated = $this->contacts->assign($contact, $request->validated());

        return (new ContactResource($updated))->response();
    }
}
