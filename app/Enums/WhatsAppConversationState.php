<?php

namespace App\Enums;

/**
 * Where a WhatsApp conversation currently sits in the document request flow.
 */
enum WhatsAppConversationState: string
{
    /** Nothing in progress; the next message opens the menu. */
    case Idle = 'idle';

    /** The user administers several RFCs and must pick one first. */
    case AwaitingCredentialChoice = 'awaiting_credential_choice';

    /** An RFC is chosen; waiting for the document type. */
    case AwaitingDocumentChoice = 'awaiting_document_choice';
}
