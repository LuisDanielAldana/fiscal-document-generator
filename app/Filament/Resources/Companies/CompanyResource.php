<?php

namespace App\Filament\Resources\Companies;

use App\Enums\UserRole;
use App\Filament\Resources\Companies\Pages\CreateCompany;
use App\Filament\Resources\Companies\Pages\EditCompany;
use App\Filament\Resources\Companies\Pages\ListCompanies;
use App\Models\Company;
use BackedEnum;
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

/**
 * @extends Resource<Company>
 */
class CompanyResource extends Resource
{
    protected static ?string $model = Company::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'Empresa';

    protected static ?string $pluralModelLabel = 'Empresas';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Identidad')
                ->description('El RFC es la identidad fiscal de la empresa ante el SAT.')
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->label('Nombre comercial')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('legal_name')
                        ->label('Razón social')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('rfc')
                        ->label('RFC')
                        ->required()
                        ->maxLength(13)
                        // Persona moral: 12 characters, persona física: 13.
                        ->rule('regex:/^[A-ZÑ&]{3,4}[0-9]{6}[A-Z0-9]{3}$/')
                        ->helperText('12 caracteres para persona moral, 13 para persona física.')
                        ->dehydrateStateUsing(fn (?string $state): ?string => $state === null ? null : mb_strtoupper($state))
                        ->unique(ignoreRecord: true),
                    Toggle::make('is_active')
                        ->label('Activa')
                        ->helperText('Las empresas inactivas se omiten en la generación de las 2:00 AM.')
                        ->default(true),
                ]),

            Section::make('Datos fiscales')
                ->columns(3)
                ->schema([
                    TextInput::make('fiscal_postal_code')
                        ->label('Código postal fiscal')
                        ->maxLength(5),
                    TextInput::make('tax_regime_code')
                        ->label('Clave de régimen')
                        ->maxLength(3),
                    TextInput::make('tax_regime_name')
                        ->label('Régimen fiscal')
                        ->maxLength(255),
                ]),

            Section::make('Contacto')
                ->columns(2)
                ->schema([
                    TextInput::make('email')
                        ->label('Correo')
                        ->email()
                        ->maxLength(255),
                    TextInput::make('phone_number')
                        ->label('Teléfono')
                        ->tel()
                        ->maxLength(20),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Empresa')
                    ->description(fn (Company $record): string => $record->legal_name)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('rfc')
                    ->label('RFC')
                    ->searchable()
                    ->copyable(),
                TextColumn::make('users_count')
                    ->label('Usuarios')
                    ->counts('users')
                    ->alignRight(),
                IconColumn::make('fiscalCredential')
                    ->label('Credencial')
                    ->boolean()
                    ->state(fn (Company $record): bool => $record->fiscalCredential !== null)
                    ->tooltip(fn (Company $record): string => $record->fiscalCredential === null
                        ? 'Sin e.firma ni contraseña del SAT: no se generan documentos.'
                        : 'Credencial registrada.'),
                IconColumn::make('is_active')
                    ->label('Activa')
                    ->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Activa'),
            ])
            ->defaultSort('name');
    }

    /**
     * Only a super admin manages companies; an administrator sees their own.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if ($user?->role === UserRole::SuperAdmin) {
            return $query;
        }

        return $query->whereKey($user?->company_id);
    }

    public static function canCreate(): bool
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
            'index' => ListCompanies::route('/'),
            'create' => CreateCompany::route('/create'),
            'edit' => EditCompany::route('/{record}/edit'),
        ];
    }
}
