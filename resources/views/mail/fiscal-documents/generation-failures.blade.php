<x-mail::message>
# Fiscal document generation failed

The nightly run for **{{ $forDate }}** finished with **{{ $failures->count() }}** document(s) that could not be
retrieved from the SAT. Each was attempted 5 times.

<x-mail::table>
| Taxpayer | RFC | Document | Reason |
| :------- | :-- | :------- | :----- |
@foreach ($failures as $failure)
| {{ $failure->fiscalSubject?->name ?? '—' }} | {{ $failure->fiscalCredential?->rfc ?? '—' }} | {{ $failure->documentType->name }} | {{ \Illuminate\Support\Str::limit($failure->error_message ?? 'Unknown error', 160) }} |
@endforeach
</x-mail::table>

@php
    $stillDeliverable = $failures->filter(fn ($failure) => $failure->hasFile());
@endphp

@if ($stillDeliverable->isNotEmpty())
{{ $stillDeliverable->count() }} of these already had a copy stored for {{ $forDate }}, which is still being served
while the problem lasts. The rest have nothing to send for that day.
@endif

An expired certificate or a changed SAT password is fixed from the panel; the next run picks it up automatically.

<x-mail::button :url="url('/admin')">
Open the panel
</x-mail::button>

<x-mail::subcopy>
You are receiving this because you are a super administrator. Failures already reported are not repeated.
</x-mail::subcopy>
</x-mail::message>
