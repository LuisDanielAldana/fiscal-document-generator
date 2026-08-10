<?php

namespace App\Services\Whatsapp;

use App\Enums\WhatsAppConversationState;
use App\Jobs\GenerateDocument;
use App\Models\DocumentType;
use App\Models\FiscalDocument;
use App\Models\User;
use App\Models\WhatsappConversation;
use App\Services\DocumentAccessService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * The conversation: pick a taxpayer, pick a document, receive the PDF.
 *
 * Documents are served from what the nightly run already fetched, which is the
 * point of that run — the SAT is slow, and nobody should wait on it inside a chat.
 * Generating on demand only happens when there is no fresh copy to send.
 *
 * The set of taxpayers offered comes from DocumentAccessService, and is re-checked
 * when the document is finally sent: a conversation can sit idle for hours, and
 * access may have been revoked in between.
 */
class WhatsAppBotService
{
    /**
     * The number of options past which buttons give way to a list.
     */
    private const int BUTTON_LIMIT = WhatsAppClient::MAX_BUTTONS;

    public function __construct(
        private readonly WhatsAppClient $client,
        private readonly DocumentAccessService $access,
    ) {}

    /**
     * Handle one inbound message.
     *
     * @param  array<string, mixed>  $message  The `messages[0]` object from Meta.
     */
    public function handleInboundMessage(array $message): void
    {
        $phone = (string) ($message['from'] ?? '');

        if ($phone === '') {
            return;
        }

        $user = User::query()
            ->where('whatsapp_number', $phone)
            ->where('whatsapp_verified', true)
            ->first();

        if ($user === null) {
            $this->client->sendText(
                $phone,
                'No reconozco este número. Pide a tu administrador que lo vincule y verifique en tu cuenta.',
            );

            return;
        }

        $conversation = $this->conversationFor($phone, $user);
        $reply = $this->replyIdFrom($message);

        if ($reply !== null) {
            $this->handleReply($conversation, $user, $reply);

            return;
        }

        // Anything else — a greeting, a stray photo, a message we cannot read —
        // restarts the flow rather than leaving the sender guessing.
        $this->showTaxpayerMenu($conversation, $user);
    }

    /**
     * Offer the taxpayers this user may ask about, or skip straight ahead when
     * there is only one.
     */
    private function showTaxpayerMenu(WhatsappConversation $conversation, User $user): void
    {
        $subjects = $this->access->accessibleSubjects($user);

        if ($subjects->isEmpty()) {
            $conversation->reset();

            $this->client->sendText(
                $conversation->whatsapp_phone,
                'Todavía no tienes datos fiscales configurados. Pide a tu administrador que registre tu e.firma o tu contraseña del SAT.',
            );

            return;
        }

        if ($subjects->count() === 1) {
            $this->showDocumentMenu($conversation, $subjects->first());

            return;
        }

        $offered = $this->describeSubjects($subjects);

        $conversation->moveTo(WhatsAppConversationState::AwaitingCredentialChoice, [
            'subjects' => $offered->all(),
        ]);

        $options = $offered
            ->values()
            ->map(fn (array $subject, int $index): array => [
                'id' => "subject:{$index}",
                'title' => $subject['label'],
                'description' => $subject['rfc'],
            ])
            ->all();

        $question = '¿De quién necesitas el documento?';

        if (count($options) <= self::BUTTON_LIMIT) {
            $this->client->sendButtons($conversation->whatsapp_phone, $question, $options);

            return;
        }

        $this->client->sendList($conversation->whatsapp_phone, $question, 'Ver opciones', $options);
    }

    /**
     * Offer the document types for a chosen taxpayer.
     */
    private function showDocumentMenu(WhatsappConversation $conversation, Model $subject): void
    {
        $types = DocumentType::query()->offered()->get();

        if ($types->isEmpty()) {
            $conversation->reset();

            $this->client->sendText(
                $conversation->whatsapp_phone,
                'Por ahora no hay documentos disponibles. Intenta más tarde.',
            );

            return;
        }

        $conversation->moveTo(WhatsAppConversationState::AwaitingDocumentChoice, [
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
        ]);

        $options = $types
            ->map(fn (DocumentType $type): array => [
                'id' => "doc:{$type->id}",
                'title' => $type->name,
            ])
            ->all();

        $question = '¿Qué documento necesitas?';

        if (count($options) <= self::BUTTON_LIMIT) {
            $this->client->sendButtons($conversation->whatsapp_phone, $question, $options);

            return;
        }

        $this->client->sendList($conversation->whatsapp_phone, $question, 'Ver documentos', $options);
    }

    /**
     * Route a button or list selection.
     */
    private function handleReply(WhatsappConversation $conversation, User $user, string $reply): void
    {
        if (str_starts_with($reply, 'subject:')) {
            $this->handleTaxpayerChoice($conversation, $user, (int) substr($reply, strlen('subject:')));

            return;
        }

        if (str_starts_with($reply, 'doc:')) {
            $this->handleDocumentChoice($conversation, $user, (int) substr($reply, strlen('doc:')));

            return;
        }

        $this->showTaxpayerMenu($conversation, $user);
    }

    /**
     * Resolve a chosen taxpayer against exactly what was offered.
     *
     * Selecting by position in the stored offer, rather than trusting an id in the
     * reply, means a crafted or replayed payload cannot name a taxpayer this user
     * was never shown.
     */
    private function handleTaxpayerChoice(WhatsappConversation $conversation, User $user, int $index): void
    {
        $offered = $conversation->context['subjects'] ?? null;

        if (! is_array($offered) || ! isset($offered[$index])) {
            // The offer is gone — a reset, or a tap on an old message.
            $this->showTaxpayerMenu($conversation, $user);

            return;
        }

        $subject = $this->resolveSubject($user, $offered[$index]);

        if ($subject === null) {
            $this->showTaxpayerMenu($conversation, $user);

            return;
        }

        $this->showDocumentMenu($conversation, $subject);
    }

    /**
     * Deliver, or start generating, the chosen document.
     */
    private function handleDocumentChoice(WhatsappConversation $conversation, User $user, int $typeId): void
    {
        $context = $conversation->context ?? [];
        $type = DocumentType::query()->offered()->find($typeId);

        $subject = isset($context['subject_type'], $context['subject_id'])
            ? $this->resolveSubject($user, [
                'type' => $context['subject_type'],
                'id' => $context['subject_id'],
            ])
            : null;

        if ($type === null || $subject === null) {
            $this->showTaxpayerMenu($conversation, $user);

            return;
        }

        $document = $this->access->deliverableDocument($subject, $type);

        if ($document === null) {
            $this->requestGeneration($conversation, $subject, $type, $user);

            return;
        }

        $this->send($conversation, $document);
        $conversation->reset();
    }

    /**
     * Send a stored document as a WhatsApp attachment.
     */
    private function send(WhatsappConversation $conversation, FiscalDocument $document): void
    {
        $disk = Storage::disk($document->disk ?? FiscalDocument::STORAGE_DISK);
        $path = (string) $document->path;

        if (! $disk->exists($path)) {
            // The row says there is a file and the disk disagrees. Re-fetching is
            // the only recovery, and the taxpayer should not be told "here it is".
            $this->client->sendText(
                $conversation->whatsapp_phone,
                'Tuve un problema al recuperar tu documento. Ya lo estoy generando de nuevo, te aviso en unos minutos.',
            );

            $this->dispatchGeneration($document->fiscal_credential_id, $document->document_type_id, $conversation->user_id);

            return;
        }

        $this->client->sendDocument(
            to: $conversation->whatsapp_phone,
            contents: (string) $disk->get($path),
            filename: $document->downloadFilename(),
            caption: sprintf('%s — %s', $document->documentType->name, $document->generated_for_date->toDateString()),
        );
    }

    /**
     * Nothing fresh is stored, so fetch it now.
     */
    private function requestGeneration(
        WhatsappConversation $conversation,
        Model $subject,
        DocumentType $type,
        User $user,
    ): void {
        $credential = $this->access->credentialFor($subject);

        if ($credential === null) {
            $conversation->reset();

            $this->client->sendText(
                $conversation->whatsapp_phone,
                'No tengo credenciales vigentes para ese RFC. Pide a tu administrador que actualice la e.firma o la contraseña del SAT.',
            );

            return;
        }

        $this->client->sendText(
            $conversation->whatsapp_phone,
            'Estoy pidiendo tu documento al SAT. Te lo mando en cuanto esté listo.',
        );

        $this->dispatchGeneration($credential->id, $type->id, $user->id);

        $conversation->reset();
    }

    /**
     * Queue an on-demand fetch for today.
     */
    private function dispatchGeneration(?int $credentialId, int $typeId, ?int $requestedByUserId): void
    {
        if ($credentialId === null) {
            return;
        }

        GenerateDocument::dispatch(
            fiscalCredentialId: $credentialId,
            documentTypeId: $typeId,
            forDate: now()->toDateString(),
            source: 'whatsapp',
            requestedByUserId: $requestedByUserId,
        );
    }

    /**
     * Re-check that a taxpayer is still one this user may ask about.
     *
     * @param  array{type?: mixed, id?: mixed}  $reference
     */
    private function resolveSubject(User $user, array $reference): ?Model
    {
        $type = $reference['type'] ?? null;
        $id = $reference['id'] ?? null;

        if (! is_string($type) || $id === null) {
            return null;
        }

        return $this->access
            ->accessibleSubjects($user)
            ->first(fn (Model $subject): bool => $subject->getMorphClass() === $type
                && (string) $subject->getKey() === (string) $id);
    }

    /**
     * Flatten taxpayers into what the menu shows and the reply resolves against.
     *
     * @param  Collection<int, Model>  $subjects
     * @return Collection<int, array{type: string, id: int|string, label: string, rfc: string}>
     */
    private function describeSubjects(Collection|EloquentCollection $subjects): Collection
    {
        return $subjects
            ->map(fn (Model $subject): array => [
                'type' => $subject->getMorphClass(),
                'id' => $subject->getKey(),
                'label' => (string) ($subject->name ?? $subject->rfc ?? 'RFC'),
                'rfc' => (string) ($subject->rfc ?? ''),
            ])
            ->values();
    }

    /**
     * The conversation for a number, kept pointed at the account that owns it.
     */
    private function conversationFor(string $phone, User $user): WhatsappConversation
    {
        $conversation = WhatsappConversation::firstOrCreate(
            ['whatsapp_phone' => $phone],
            [
                'user_id' => $user->id,
                'state' => WhatsAppConversationState::Idle,
                'last_interaction_at' => now(),
            ],
        );

        // A number can be reassigned to another account; the conversation must
        // follow, or the new holder would inherit the previous one's selections.
        if ($conversation->user_id !== $user->id) {
            $conversation->update([
                'user_id' => $user->id,
                'state' => WhatsAppConversationState::Idle,
                'context' => null,
            ]);
        }

        $conversation->update(['last_interaction_at' => now()]);

        return $conversation;
    }

    /**
     * The id behind a button tap or list selection, if this message is one.
     *
     * @param  array<string, mixed>  $message
     */
    private function replyIdFrom(array $message): ?string
    {
        $interactive = $message['interactive'] ?? [];

        $id = $interactive['button_reply']['id']
            ?? $interactive['list_reply']['id']
            ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }
}
