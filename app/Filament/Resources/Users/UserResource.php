<?php

namespace App\Filament\Resources\Users;

use App\Enums\UserRole;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Resource<User>
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-users';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'Usuario';

    protected static ?string $pluralModelLabel = 'Usuarios';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Cuenta')
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->label('Nombre')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('email')
                        ->label('Correo')
                        ->email()
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->maxLength(255),
                    TextInput::make('password')
                        ->label('Contraseña')
                        ->password()
                        ->revealable()
                        ->required(fn (string $operation): bool => $operation === 'create')
                        // Left blank on edit, the existing password stands. The
                        // stored hash is never sent to the browser.
                        ->dehydrated(fn (?string $state): bool => filled($state))
                        ->dehydrateStateUsing(fn (string $state): string => Hash::make($state))
                        ->helperText(fn (string $operation): ?string => $operation === 'edit'
                            ? 'Déjalo vacío para conservar la contraseña actual.'
                            : null),
                    Select::make('role')
                        ->label('Rol')
                        ->options(fn (): array => static::assignableRoles())
                        ->default(UserRole::User)
                        ->required()
                        ->native(false)
                        ->helperText('Sólo los roles administrativos entran a este panel; un usuario simple usa WhatsApp.'),
                    Select::make('company_id')
                        ->label('Empresa')
                        ->relationship('company', 'name')
                        ->searchable()
                        ->preload()
                        ->visible(fn (): bool => auth()->user()?->role === UserRole::SuperAdmin)
                        ->helperText('Pertenecer a una empresa no da acceso a los documentos de la empresa; eso lo define el rol.'),
                ]),

            Section::make('Datos fiscales')
                ->description('Sólo si esta persona factura como persona física con su propio RFC.')
                ->columns(3)
                ->schema([
                    TextInput::make('rfc')
                        ->label('RFC')
                        ->maxLength(13)
                        ->rule('regex:/^[A-ZÑ&]{3,4}[0-9]{6}[A-Z0-9]{3}$/')
                        ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? mb_strtoupper($state) : null)
                        ->unique(ignoreRecord: true),
                    TextInput::make('fiscal_postal_code')
                        ->label('Código postal fiscal')
                        ->maxLength(5),
                    TextInput::make('tax_regime_name')
                        ->label('Régimen fiscal')
                        ->maxLength(255),
                ]),

            Section::make('WhatsApp')
                ->description('El número con el que esta persona pide documentos al bot.')
                ->columns(2)
                ->schema([
                    TextInput::make('whatsapp_number')
                        ->label('Número')
                        ->tel()
                        ->maxLength(20)
                        ->rule('regex:/^[0-9]{10,20}$/')
                        ->helperText('Formato E.164 sin el "+", como lo envía Meta: 5215512345678.')
                        ->unique(ignoreRecord: true),
                    Toggle::make('whatsapp_verified')
                        ->label('Número verificado')
                        ->helperText('El bot sólo atiende números verificados.')
                        // Stamping the date here keeps "verified" and "when" from
                        // drifting apart when an operator flips the toggle.
                        ->afterStateUpdated(function (?bool $state, Model|string|null $record, callable $set): void {
                            $set('whatsapp_verified_at', $state ? now() : null);
                        })
                        ->live(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nombre')
                    ->description(fn (User $record): string => $record->email)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('role')
                    ->label('Rol')
                    ->badge(),
                TextColumn::make('company.name')
                    ->label('Empresa')
                    ->searchable()
                    ->placeholder('—')
                    ->visible(fn (): bool => auth()->user()?->role === UserRole::SuperAdmin),
                TextColumn::make('rfc')
                    ->label('RFC')
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('whatsapp_number')
                    ->label('WhatsApp')
                    ->placeholder('—'),
                IconColumn::make('whatsapp_verified')
                    ->label('Verificado')
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('role')
                    ->label('Rol')
                    ->options(UserRole::class),
            ])
            ->defaultSort('name');
    }

    /**
     * An administrator only ever sees their own company's people.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if ($user?->role === UserRole::SuperAdmin) {
            return $query;
        }

        return $query->where('company_id', $user?->company_id);
    }

    /**
     * Roles the signed-in account may hand out.
     *
     * A company administrator cannot mint a super admin: that role reads across
     * every company, so granting it is an escalation out of their own tenancy.
     *
     * @return array<string, string>
     */
    private static function assignableRoles(): array
    {
        $roles = auth()->user()?->role === UserRole::SuperAdmin
            ? UserRole::cases()
            : array_filter(UserRole::cases(), fn (UserRole $role): bool => $role !== UserRole::SuperAdmin);

        return collect($roles)
            ->mapWithKeys(fn (UserRole $role): array => [$role->value => $role->label()])
            ->all();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
