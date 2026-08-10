<?php

namespace App\Filament\Resources\FiscalCredentials;

use App\Enums\SatCredentialMethod;
use App\Enums\UserRole;
use App\Filament\Resources\FiscalCredentials\Pages\CreateFiscalCredential;
use App\Filament\Resources\FiscalCredentials\Pages\EditFiscalCredential;
use App\Filament\Resources\FiscalCredentials\Pages\ListFiscalCredentials;
use App\Models\Company;
use App\Models\FiscalCredential;
use App\Models\User;
use App\Services\DocumentAccessService;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\MorphToSelect;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Where the FIEL and CIEC are entered.
 *
 * This is the most sensitive screen in the application: it accepts e-signature
 * files and two SAT passwords. Three rules hold throughout — the files go to the
 * private `fiscal-credentials` disk and are never served from here, a stored
 * password is never sent back to the browser, and a blank password field on edit
 * means "leave it alone" rather than "erase it".
 *
 * @extends Resource<FiscalCredential>
 */
class FiscalCredentialResource extends Resource
{
    protected static ?string $model = FiscalCredential::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-key';

    protected static ?string $recordTitleAttribute = 'rfc';

    protected static ?string $modelLabel = 'Credencial fiscal';

    protected static ?string $pluralModelLabel = 'Credenciales fiscales';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Titular')
                ->description('El RFC al que pertenece esta credencial: la empresa (persona moral) o una persona física.')
                ->columns(2)
                ->schema([
                    MorphToSelect::make('owner')
                        ->label('Titular')
                        ->types([
                            MorphToSelect\Type::make(Company::class)
                                ->label('Empresa')
                                ->titleAttribute('name'),
                            MorphToSelect\Type::make(User::class)
                                ->label('Persona física')
                                ->titleAttribute('name'),
                        ])
                        ->searchable()
                        ->preload()
                        ->required(),
                    TextInput::make('rfc')
                        ->label('RFC')
                        ->required()
                        ->maxLength(13)
                        ->rule('regex:/^[A-ZÑ&]{3,4}[0-9]{6}[A-Z0-9]{3}$/')
                        ->dehydrateStateUsing(fn (?string $state): ?string => $state === null ? null : mb_strtoupper($state))
                        ->helperText('Debe coincidir exactamente con el RFC de la e.firma.'),
                ]),

            Section::make('e.firma (FIEL)')
                ->description('Los archivos se guardan en almacenamiento privado y nunca se exponen por URL.')
                ->columns(2)
                ->schema([
                    FileUpload::make('cer_path')
                        ->label('Certificado (.cer)')
                        ->disk(FiscalCredential::STORAGE_DISK)
                        ->directory('fiel')
                        ->visibility('private')
                        // Random stored names: the original filename usually contains
                        // the RFC, and object keys leak through logs and listings.
                        ->preserveFilenames(false)
                        ->downloadable(false)
                        ->openable(false)
                        ->maxSize(512)
                        ->helperText('Archivo .cer emitido por el SAT.'),
                    FileUpload::make('key_path')
                        ->label('Llave privada (.key)')
                        ->disk(FiscalCredential::STORAGE_DISK)
                        ->directory('fiel')
                        ->visibility('private')
                        ->preserveFilenames(false)
                        ->downloadable(false)
                        ->openable(false)
                        ->maxSize(512)
                        ->helperText('Archivo .key de la e.firma.'),
                    TextInput::make('fiel_password')
                        ->label('Contraseña de la llave privada')
                        ->password()
                        ->revealable()
                        ->maxLength(255)
                        ->autocomplete('new-password')
                        // Never hydrate the stored value: the cast would decrypt it
                        // straight into the page source.
                        ->afterStateHydrated(fn (TextInput $component): mixed => $component->state(null))
                        ->dehydrated(fn (?string $state): bool => filled($state))
                        ->helperText('Déjalo vacío para conservar la contraseña guardada.'),
                    DatePicker::make('fiel_expires_at')
                        ->label('Vigencia del certificado')
                        ->helperText('Al vencer, la generación cambia automáticamente a la CIEC si está registrada.'),
                ]),

            Section::make('Contraseña del SAT (CIEC)')
                ->description('Alternativa a la e.firma. Puedes registrar ambas.')
                ->columns(2)
                ->schema([
                    TextInput::make('ciec_password')
                        ->label('Contraseña del SAT')
                        ->password()
                        ->revealable()
                        ->maxLength(255)
                        ->autocomplete('new-password')
                        ->afterStateHydrated(fn (TextInput $component): mixed => $component->state(null))
                        ->dehydrated(fn (?string $state): bool => filled($state))
                        ->helperText('Déjalo vacío para conservar la contraseña guardada.'),
                    Select::make('preferred_method')
                        ->label('Método preferido')
                        ->options(SatCredentialMethod::class)
                        ->native(false)
                        ->placeholder('Automático (e.firma primero)')
                        ->helperText('El SAT da más peso a los documentos obtenidos con la e.firma.'),
                ]),

            Section::make('Estado')
                ->schema([
                    Toggle::make('is_active')
                        ->label('Activa')
                        ->default(true)
                        ->helperText('Si se desactiva, la generación de las 2:00 AM omite este RFC.'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('rfc')
                    ->label('RFC')
                    ->searchable()
                    ->sortable()
                    ->copyable(),
                TextColumn::make('owner.name')
                    ->label('Titular')
                    ->description(fn (FiscalCredential $record): string => $record->owner instanceof Company
                        ? 'Empresa'
                        : 'Persona física')
                    ->searchable(),
                IconColumn::make('has_fiel')
                    ->label('e.firma')
                    ->boolean()
                    ->state(fn (FiscalCredential $record): bool => $record->hasFiel()),
                IconColumn::make('has_ciec')
                    ->label('CIEC')
                    ->boolean()
                    ->state(fn (FiscalCredential $record): bool => $record->hasCiec()),
                TextColumn::make('fiel_expires_at')
                    ->label('Vigencia')
                    ->date()
                    ->placeholder('—')
                    ->badge()
                    ->color(fn (FiscalCredential $record): string => match (true) {
                        $record->hasExpired() => 'danger',
                        $record->fiel_expires_at?->isBefore(now()->addDays(30)) ?? false => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('method')
                    ->label('Se usará')
                    ->badge()
                    ->state(fn (FiscalCredential $record): string => $record->method()?->label() ?? 'Ninguno')
                    ->color(fn (FiscalCredential $record): string => $record->method() === null ? 'danger' : 'success'),
                IconColumn::make('is_active')
                    ->label('Activa')
                    ->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Activa'),
            ])
            ->defaultSort('rfc');
    }

    /**
     * Reject a credential that could not be used for anything.
     *
     * Checked against the merged result of the record and the submitted form,
     * because a blank password field on edit means the stored one stands.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public static function assertUsable(array $data, ?FiscalCredential $record = null): void
    {
        $hasFiel = filled($data['cer_path'] ?? $record?->cer_path)
            && filled($data['key_path'] ?? $record?->key_path)
            && (filled($data['fiel_password'] ?? null) || filled($record?->fiel_password));

        $hasCiec = filled($data['ciec_password'] ?? null) || filled($record?->ciec_password);

        if ($hasFiel || $hasCiec) {
            return;
        }

        throw ValidationException::withMessages([
            'data.ciec_password' => 'Registra la e.firma completa (.cer, .key y su contraseña) o la contraseña del SAT.',
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();

        if ($user === null) {
            return parent::getEloquentQuery()->whereRaw('1 = 0');
        }

        // The same rule the bot uses, so the panel and WhatsApp cannot disagree
        // about who a credential belongs to.
        return app(DocumentAccessService::class)
            ->credentialsFor($user)
            ->with('owner');
    }

    /**
     * Only administrative roles handle signing material.
     */
    public static function canViewAny(): bool
    {
        return auth()->user()?->isOwnerOrAdmin() ?? false;
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return auth()->user()?->role === UserRole::SuperAdmin;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFiscalCredentials::route('/'),
            'create' => CreateFiscalCredential::route('/create'),
            'edit' => EditFiscalCredential::route('/{record}/edit'),
        ];
    }
}
