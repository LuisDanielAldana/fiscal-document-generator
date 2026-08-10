<?php

namespace App\Filament\Resources\FiscalDocuments;

use App\Enums\FiscalDocumentStatus;
use App\Filament\Resources\FiscalDocuments\Pages\ListFiscalDocuments;
use App\Jobs\GenerateDocument;
use App\Models\FiscalDocument;
use App\Services\DocumentAccessService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The generated documents, read only.
 *
 * Nothing here is editable: a document is what the SAT returned on a given day.
 * Rows are scoped by the same rule the bot uses, and downloads stream through the
 * application rather than exposing the private disk.
 *
 * @extends Resource<FiscalDocument>
 */
class FiscalDocumentResource extends Resource
{
    protected static ?string $model = FiscalDocument::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $modelLabel = 'Documento fiscal';

    protected static ?string $pluralModelLabel = 'Documentos fiscales';

    protected static ?int $navigationSort = 5;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('generated_for_date')
                    ->label('Día')
                    ->date()
                    ->sortable(),
                TextColumn::make('fiscalSubject.name')
                    ->label('Titular')
                    ->description(fn (FiscalDocument $record): string => $record->fiscalCredential?->rfc ?? '—')
                    ->searchable(),
                TextColumn::make('documentType.name')
                    ->label('Documento')
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Estado')
                    ->badge(),
                TextColumn::make('size')
                    ->label('Tamaño')
                    ->formatStateUsing(fn (?int $state): string => $state === null
                        ? '—'
                        : number_format($state / 1024, 0).' KB')
                    ->alignRight(),
                TextColumn::make('source')
                    ->label('Origen')
                    ->badge()
                    ->color('gray')
                    ->placeholder('—'),
                TextColumn::make('error_message')
                    ->label('Error')
                    ->wrap()
                    ->limit(80)
                    ->color('danger')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('completed_at')
                    ->label('Obtenido')
                    ->dateTime()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Estado')
                    ->options(FiscalDocumentStatus::class),
                SelectFilter::make('document_type_id')
                    ->label('Documento')
                    ->relationship('documentType', 'name'),
                Filter::make('failed_only')
                    ->label('Sólo con error')
                    ->query(fn (Builder $query): Builder => $query->whereNotNull('error_message')),
            ])
            ->recordActions([
                Action::make('download')
                    ->label('Descargar')
                    ->icon('heroicon-m-arrow-down-tray')
                    // Streamed, not linked: the disk stays private and the policy
                    // decides on every single download.
                    ->visible(fn (FiscalDocument $record): bool => auth()->user()?->can('download', $record) ?? false)
                    ->action(fn (FiscalDocument $record): StreamedResponse => static::download($record)),

                Action::make('regenerate')
                    ->label('Regenerar')
                    ->icon('heroicon-m-arrow-path')
                    ->requiresConfirmation()
                    ->modalDescription('Se volverá a pedir el documento al SAT para ese día. La copia actual se reemplaza sólo si la nueva llega bien.')
                    ->visible(fn (FiscalDocument $record): bool => $record->fiscal_credential_id !== null
                        && (auth()->user()?->isOwnerOrAdmin() ?? false))
                    ->action(function (FiscalDocument $record): void {
                        GenerateDocument::dispatch(
                            fiscalCredentialId: (int) $record->fiscal_credential_id,
                            documentTypeId: $record->document_type_id,
                            forDate: $record->generated_for_date->toDateString(),
                            source: 'manual',
                            requestedByUserId: auth()->id(),
                        );

                        Notification::make()
                            ->title('Se está regenerando el documento')
                            ->body('Te avisaremos en el panel cuando termine.')
                            ->success()
                            ->send();
                    }),
            ])
            ->defaultSort('generated_for_date', 'desc');
    }

    /**
     * Stream a stored document to the browser.
     */
    private static function download(FiscalDocument $document): StreamedResponse
    {
        return Storage::disk($document->disk ?? FiscalDocument::STORAGE_DISK)
            ->download((string) $document->path, $document->downloadFilename());
    }

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();

        if ($user === null) {
            return parent::getEloquentQuery()->whereRaw('1 = 0');
        }

        return app(DocumentAccessService::class)
            ->documentsFor($user)
            ->with(['documentType', 'fiscalSubject', 'fiscalCredential']);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFiscalDocuments::route('/'),
        ];
    }
}
