<?php

namespace App\Filament\Resources\DocumentTypes;

use App\Enums\DocumentTypeCode;
use App\Enums\UserRole;
use App\Filament\Resources\DocumentTypes\Pages\ListDocumentTypes;
use App\Models\DocumentType;
use App\Services\SatGo\DocumentGeneratorRegistry;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Which documents the bot offers, and how stale a copy may be.
 *
 * The code is not free text: it selects the handler that knows how to fetch the
 * document from SatGo, so only codes with a shipped handler can be chosen.
 *
 * @extends Resource<DocumentType>
 */
class DocumentTypeResource extends Resource
{
    protected static ?string $model = DocumentType::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'Tipo de documento';

    protected static ?string $pluralModelLabel = 'Tipos de documento';

    protected static ?int $navigationSort = 4;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('code')
                    ->label('Documento')
                    ->options(fn (): array => static::generatableCodes())
                    ->required()
                    ->native(false)
                    ->unique(ignoreRecord: true)
                    ->disabledOn('edit')
                    ->helperText('Sólo se listan los documentos que la aplicación sabe descargar.'),
                TextInput::make('name')
                    ->label('Nombre visible')
                    ->required()
                    ->maxLength(255)
                    ->helperText('Es el texto que ve el usuario en WhatsApp.'),
                Textarea::make('description')
                    ->label('Descripción')
                    ->rows(2)
                    ->maxLength(255),
                TextInput::make('max_age_days')
                    ->label('Antigüedad máxima (días)')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(255)
                    ->default(2)
                    ->required()
                    ->helperText('Pasado este plazo el bot prefiere volver a pedirlo al SAT antes que enviar una copia vieja.'),
                TextInput::make('sort_order')
                    ->label('Orden')
                    ->numeric()
                    ->default(0)
                    ->required(),
                Toggle::make('is_active')
                    ->label('Se ofrece')
                    ->default(true)
                    ->helperText('Si se desactiva, deja de generarse y de aparecer en el menú.'),
            ])
            ->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Documento')
                    ->description(fn (DocumentType $record): ?string => $record->description)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('code')
                    ->label('Código')
                    ->badge(),
                TextColumn::make('max_age_days')
                    ->label('Antigüedad máx.')
                    ->suffix(' días')
                    ->alignRight(),
                TextColumn::make('documents_count')
                    ->label('Generados')
                    ->counts('documents')
                    ->alignRight(),
                IconColumn::make('is_active')
                    ->label('Se ofrece')
                    ->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->defaultSort('sort_order')
            ->reorderable('sort_order');
    }

    /**
     * The codes some shipped handler can actually fetch.
     *
     * @return array<string, string>
     */
    private static function generatableCodes(): array
    {
        $registry = app(DocumentGeneratorRegistry::class);

        return collect(DocumentTypeCode::cases())
            ->filter(fn (DocumentTypeCode $code): bool => $registry->supports($code))
            ->mapWithKeys(fn (DocumentTypeCode $code): array => [$code->value => $code->label()])
            ->all();
    }

    /**
     * Document types are global, so only a super admin changes them: enabling one
     * adds a nightly SAT call for every taxpayer in the system.
     */
    public static function canViewAny(): bool
    {
        return auth()->user()?->isOwnerOrAdmin() ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->role === UserRole::SuperAdmin;
    }

    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return auth()->user()?->role === UserRole::SuperAdmin;
    }

    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return auth()->user()?->role === UserRole::SuperAdmin;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDocumentTypes::route('/'),
        ];
    }
}
