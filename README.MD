# Arquitectura: Bot de WhatsApp para documentos fiscales (SatGo) — Laravel 13 + Filament 5

## 0. Resumen de decisiones

| Decisión | Elegido |
|---|---|
| WhatsApp | Meta Cloud API (directo, oficial) |
| Documentos iniciales | Opinión de Cumplimiento (`/api/v2/consultar/ocfiel`) + Constancia de Situación Fiscal (`/api/v2/consultar/csffiel`) |
| Guardado de FIEL | Tú guardas `.cer` + `.key` en S3, password cifrada en DB (Opción B de SatGo: `Certificado + llavePrivada + Contrasena`) |
| Generación | Job diario 2:00 AM que genera y cachea ambos documentos por cada RFC activo |
| Multi-tenant | `Company` → muchos `User` (roles `owner` / `administrator` / `user`) |

Nota sobre el punto 3: como decidiste guardar tú el `.cer/.key/password`, cada llamada a SatGo se hace con `multipart/form-data` (Opción B), sin usar su SecretProvider. Esto significa que **tú eres responsable de la seguridad de esos archivos** — el diseño de abajo lo trata con el mismo nivel de cuidado que credenciales de firma electrónica (S3 privado, cifrado en reposo, nunca expuesto por URL pública, borrado de temporales tras cada uso).

---

## 1. Modelo de datos

```
Company 1───* User
Company 1───1 FiscalCredential  (o User 1───1 FiscalCredential, ver nota)
FiscalCredential 1───* Document
DocumentType 1───* Document
User 1───* WhatsappConversation
WhatsappConversation 1───* WhatsappMessageLog
```

### Nota importante a decidir con tu negocio: ¿el RFC es de la empresa o de cada usuario?

En México normalmente **la FIEL/CIEC pertenece a un RFC específico** (persona física o moral), no a un usuario individual del sistema. Dado que dijiste "un usuario con rol admin/owner puede ver documentos de la compañía y de usuarios relacionados", lo más común es:

- **Company** tiene **un** RFC/FIEL propio (persona moral) → documentos "de la empresa".
- Opcionalmente, cada **User** puede tener **su propio** RFC/FIEL personal (persona física, ej. el dueño facturando a título personal) → documentos "propios".

Por eso modelo `FiscalCredential` como **polimórfico** (`owner_type`, `owner_id`), pudiendo pertenecer a `Company` o a `User`. Si en tu caso *solo* existe un RFC por compañía, simplemente no uses la variante de `User` y todo se simplifica — pero dejar el morph desde el inicio evita una migración dolorosa después.

### Migraciones

```php
// companies
Schema::create('companies', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->string('rfc', 13)->nullable(); // solo informativo, el fiscal real vive en fiscal_credentials
    $table->boolean('is_active')->default(true);
    $table->timestamps();
});

// users (ampliar la migración default de Laravel)
Schema::table('users', function (Blueprint $table) {
    $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
    $table->enum('role', ['owner', 'administrator', 'user'])->default('user');
    $table->string('whatsapp_phone', 20)->nullable()->unique(); // formato E.164 sin '+', ej. 5215512345678
    $table->boolean('whatsapp_verified')->default(false);
    $table->timestamp('whatsapp_verified_at')->nullable();
});

// fiscal_credentials
Schema::create('fiscal_credentials', function (Blueprint $table) {
    $table->id();
    $table->morphs('owner'); // owner_type, owner_id -> Company o User
    $table->string('rfc', 13);
    $table->string('cer_path');       // path en disco S3 privado
    $table->string('key_path');       // path en disco S3 privado
    $table->text('password_encrypted'); // Laravel encrypted cast
    $table->date('fiel_expires_at')->nullable(); // vigencia del certificado
    $table->boolean('is_active')->default(true);
    $table->timestamp('last_validated_at')->nullable();
    $table->timestamps();

    $table->unique(['owner_type', 'owner_id', 'rfc']);
});

// document_types  (tabla, no enum puro, para poder agregar tipos sin deploy de código en el futuro)
Schema::create('document_types', function (Blueprint $table) {
    $table->id();
    $table->string('code')->unique();     // 'opinion_cumplimiento', 'constancia_fiscal'
    $table->string('name');               // "Opinión de Cumplimiento"
    $table->string('handler_class');      // FQCN de la clase que sabe llamar a SatGo para este tipo
    $table->boolean('is_active')->default(true);
    $table->unsignedInteger('sort_order')->default(0);
    $table->timestamps();
});

// documents
Schema::create('documents', function (Blueprint $table) {
    $table->id();
    $table->foreignId('fiscal_credential_id')->constrained()->cascadeOnDelete();
    $table->foreignId('document_type_id')->constrained();
    $table->string('file_path')->nullable();   // S3, null hasta que status = success
    $table->string('status')->default('pending'); // pending|success|failed
    $table->text('error_message')->nullable();
    $table->date('generated_for_date'); // el "día" al que corresponde (para el job de las 2am)
    $table->timestamp('generated_at')->nullable();
    $table->timestamps();

    $table->unique(['fiscal_credential_id', 'document_type_id', 'generated_for_date']);
});

// whatsapp_conversations  (estado de la conversación / máquina de estados)
Schema::create('whatsapp_conversations', function (Blueprint $table) {
    $table->id();
    $table->string('whatsapp_phone', 20);
    $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
    $table->string('state')->default('idle'); // idle|awaiting_document_choice|awaiting_target_choice|...
    $table->json('context')->nullable();      // datos temporales del flujo actual
    $table->timestamp('last_interaction_at')->nullable();
    $table->timestamps();
});

// whatsapp_message_logs (auditoría + idempotencia de webhooks)
Schema::create('whatsapp_message_logs', function (Blueprint $table) {
    $table->id();
    $table->string('wa_message_id')->unique()->nullable(); // id que manda Meta, evita procesar duplicados
    $table->string('direction'); // inbound|outbound
    $table->string('whatsapp_phone', 20);
    $table->json('payload');
    $table->string('status')->nullable(); // sent|delivered|read|failed (para outbound)
    $table->timestamps();
});
```

### Modelos (Eloquent)

```php
// app/Models/Company.php
class Company extends Model
{
    protected $fillable = ['name', 'rfc', 'is_active'];

    public function users(): HasMany { return $this->hasMany(User::class); }
    public function fiscalCredential(): MorphOne { return $this->morphOne(FiscalCredential::class, 'owner'); }
}

// app/Models/User.php (extiende el User default de Laravel)
class User extends Authenticatable implements FilamentUser
{
    protected $fillable = ['name', 'email', 'password', 'company_id', 'role', 'whatsapp_phone', 'whatsapp_verified'];
    protected $casts = ['whatsapp_verified' => 'boolean'];

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function fiscalCredential(): MorphOne { return $this->morphOne(FiscalCredential::class, 'owner'); }

    public function isOwnerOrAdmin(): bool
    {
        return in_array($this->role, ['owner', 'administrator']);
    }

    // Solo owner/administrator entran al panel Filament; 'user' solo usa WhatsApp
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->isOwnerOrAdmin();
    }
}

// app/Models/FiscalCredential.php
class FiscalCredential extends Model
{
    protected $fillable = ['rfc', 'cer_path', 'key_path', 'password_encrypted', 'fiel_expires_at', 'is_active'];
    protected $casts = [
        'password_encrypted' => 'encrypted', // Laravel cifra/descifra automáticamente con APP_KEY
        'fiel_expires_at' => 'date',
        'is_active' => 'boolean',
    ];

    public function owner(): MorphTo { return $this->morphTo(); }
    public function documents(): HasMany { return $this->hasMany(Document::class); }
}

// app/Models/DocumentType.php
class DocumentType extends Model
{
    protected $fillable = ['code', 'name', 'handler_class', 'is_active', 'sort_order'];
    public function documents(): HasMany { return $this->hasMany(Document::class); }
}

// app/Models/Document.php
class Document extends Model
{
    protected $fillable = [
        'fiscal_credential_id', 'document_type_id', 'file_path',
        'status', 'error_message', 'generated_for_date', 'generated_at',
    ];
    protected $casts = ['generated_for_date' => 'date', 'generated_at' => 'datetime'];

    public function fiscalCredential(): BelongsTo { return $this->belongsTo(FiscalCredential::class); }
    public function documentType(): BelongsTo { return $this->belongsTo(DocumentType::class); }

    public function scopeLatestSuccessful(Builder $q): Builder
    {
        return $q->where('status', 'success')->latest('generated_for_date');
    }
}
```

---

## 2. Autorización: quién puede ver qué documento

Regla de negocio: `owner`/`administrator` ven todos los documentos de su compañía (empresa + de cada usuario asociado); `user` solo ve los suyos.

```php
// app/Policies/DocumentPolicy.php
class DocumentPolicy
{
    public function view(User $user, Document $document): bool
    {
        $owner = $document->fiscalCredential->owner; // Company o User

        if ($owner instanceof Company) {
            // documento de la empresa: solo owner/administrator de esa empresa
            return $user->company_id === $owner->id && $user->isOwnerOrAdmin();
        }

        if ($owner instanceof User) {
            if ($owner->is($user)) {
                return true; // es su propio documento
            }
            // owner/administrator de la misma empresa puede ver docs personales de sus usuarios
            return $user->isOwnerOrAdmin() && $user->company_id === $owner->company_id;
        }

        return false;
    }
}
```

Esta misma lógica se reutiliza en el bot de WhatsApp (para decidir qué RFCs puede consultar quien escribe) y en Filament (a través de `EloquentQuery` scoping en el resource, ver sección 6).

Método útil centralizado:

```php
// app/Services/DocumentAccessService.php
class DocumentAccessService
{
    /** Devuelve las FiscalCredential que este usuario puede consultar por WhatsApp */
    public function accessibleCredentials(User $user): Collection
    {
        if ($user->isOwnerOrAdmin() && $user->company_id) {
            return FiscalCredential::query()
                ->where(function (Builder $q) use ($user) {
                    $q->where(fn ($q) => $q->where('owner_type', Company::class)->where('owner_id', $user->company_id))
                      ->orWhere(fn ($q) => $q->where('owner_type', User::class)
                            ->whereIn('owner_id', $user->company->users()->pluck('id')));
                })
                ->where('is_active', true)
                ->get();
        }

        // rol 'user': solo su propia credencial personal (si tiene)
        return $user->fiscalCredential ? collect([$user->fiscalCredential]) : collect();
    }
}
```

---

## 3. Cliente de SatGo

### 3.1 Autenticación (JWT cacheado)

```php
// config/services.php
'satgo' => [
    'base_url' => env('SATGO_BASE_URL', 'https://api.sat-go.com'),
    'api_key' => env('SATGO_API_KEY'), // obtenida una sola vez con /api/v1/users/CreateKey (ver sección 8)
],
```

```php
// app/Services/SatGo/SatGoAuthService.php
class SatGoAuthService
{
    public function token(): string
    {
        return Cache::remember('satgo:jwt', now()->addHours(6), function () {
            $response = Http::baseUrl(config('services.satgo.base_url'))
                ->post('/api/auth/token-json', ['key' => config('services.satgo.api_key')])
                ->throw();

            $data = $response->json();
            // cachea un poco menos que expiresAt real por seguridad
            Cache::put('satgo:jwt', $data['token'], now()->parse($data['expiresAt'])->subMinutes(5));

            return $data['token'];
        });
    }
}
```

### 3.2 Cliente base + descarga temporal de FIEL desde S3

```php
// app/Services/SatGo/SatGoClient.php
class SatGoClient
{
    public function __construct(private SatGoAuthService $auth) {}

    protected function http(): PendingRequest
    {
        return Http::baseUrl(config('services.satgo.base_url'))
            ->withToken($this->auth->token())
            ->timeout(120);
    }

    /**
     * Descarga temporalmente .cer y .key de S3 a un directorio local efímero,
     * ejecuta el callback, y borra los archivos SIEMPRE (incluso si falla).
     */
    public function withTemporaryFielFiles(FiscalCredential $credential, callable $callback): mixed
    {
        $tmpDir = storage_path('app/tmp/fiel_' . Str::uuid());
        File::ensureDirectoryExists($tmpDir);

        $cerLocal = $tmpDir . '/cert.cer';
        $keyLocal = $tmpDir . '/priv.key';

        try {
            File::put($cerLocal, Storage::disk('s3_private')->get($credential->cer_path));
            File::put($keyLocal, Storage::disk('s3_private')->get($credential->key_path));

            return $callback($cerLocal, $keyLocal);
        } finally {
            File::deleteDirectory($tmpDir); // limpieza obligatoria, incluye password en memoria del proceso hijo si aplica
        }
    }

    public function opinionCumplimiento(FiscalCredential $credential): string
    {
        return $this->withTemporaryFielFiles($credential, function ($cer, $key) use ($credential) {
            $response = $this->http()
                ->attach('Certificado', file_get_contents($cer), 'cert.cer')
                ->attach('llavePrivada', file_get_contents($key), 'priv.key')
                ->post('/api/v2/consultar/ocfiel', [
                    'Contrasena' => $credential->password_encrypted, // ya viene descifrado por el cast
                ]);

            if ($response->failed()) {
                throw new SatGoException($response->body(), $response->status());
            }

            return $response->body(); // bytes del PDF
        });
    }

    public function constanciaSituacionFiscal(FiscalCredential $credential): string
    {
        return $this->withTemporaryFielFiles($credential, function ($cer, $key) use ($credential) {
            $response = $this->http()
                ->attach('Certificado', file_get_contents($cer), 'cert.cer')
                ->attach('llavePrivada', file_get_contents($key), 'priv.key')
                ->post('/api/v2/consultar/csffiel', [
                    'Contrasena' => $credential->password_encrypted,
                ]);

            if ($response->failed()) {
                throw new SatGoException($response->body(), $response->status());
            }

            return $response->body();
        });
    }
}
```

> Nota: `attach()` requiere pasar el header `RFC` que pide la doc en algunos endpoints v2 — confírmalo contra Swagger antes de producción; si lo pide, añade `->withHeaders(['RFC' => $credential->rfc])`.

### 3.3 Handlers por tipo de documento (así agregas tipos nuevos sin tocar el core)

```php
// app/Contracts/DocumentGeneratorHandler.php
interface DocumentGeneratorHandler
{
    public function generate(FiscalCredential $credential): string; // regresa bytes del archivo
    public function fileExtension(): string; // 'pdf', 'zip', etc.
}

// app/Services/SatGo/Handlers/OpinionCumplimientoHandler.php
class OpinionCumplimientoHandler implements DocumentGeneratorHandler
{
    public function __construct(private SatGoClient $client) {}
    public function generate(FiscalCredential $credential): string { return $this->client->opinionCumplimiento($credential); }
    public function fileExtension(): string { return 'pdf'; }
}

// app/Services/SatGo/Handlers/ConstanciaFiscalHandler.php
class ConstanciaFiscalHandler implements DocumentGeneratorHandler
{
    public function __construct(private SatGoClient $client) {}
    public function generate(FiscalCredential $credential): string { return $this->client->constanciaSituacionFiscal($credential); }
    public function fileExtension(): string { return 'pdf'; }
}
```

`document_types.handler_class` guarda el FQCN (`App\Services\SatGo\Handlers\OpinionCumplimientoHandler`). Para agregar un tercer documento en el futuro: creas el handler, agregas el método al `SatGoClient`, y das de alta un registro en `document_types` desde Filament. Cero cambios al job ni al bot.

---

## 4. Generación diaria (2:00 AM)

### 4.1 Job por documento (permite reintentos y paralelismo vía queue)

```php
// app/Jobs/GenerateDocumentJob.php
class GenerateDocumentJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels, Dispatchable;

    public int $tries = 3;
    public int $backoff = 60;

    public function __construct(
        public int $fiscalCredentialId,
        public int $documentTypeId,
        public string $forDate, // 'Y-m-d'
    ) {}

    public function handle(): void
    {
        $credential = FiscalCredential::findOrFail($this->fiscalCredentialId);
        $type = DocumentType::findOrFail($this->documentTypeId);

        $document = Document::updateOrCreate(
            [
                'fiscal_credential_id' => $credential->id,
                'document_type_id' => $type->id,
                'generated_for_date' => $this->forDate,
            ],
            ['status' => 'pending']
        );

        try {
            /** @var DocumentGeneratorHandler $handler */
            $handler = app($type->handler_class);
            $bytes = $handler->generate($credential);

            $path = "documents/{$credential->rfc}/{$type->code}/{$this->forDate}.{$handler->fileExtension()}";
            Storage::disk('s3_private')->put($path, $bytes);

            $document->update([
                'file_path' => $path,
                'status' => 'success',
                'generated_at' => now(),
                'error_message' => null,
            ]);
        } catch (\Throwable $e) {
            $document->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            report($e);
            throw $e; // deja que el queue reintente según $tries
        }
    }
}
```

### 4.2 Comando que despacha todos los jobs del día

```php
// app/Console/Commands/GenerateDailyDocuments.php
class GenerateDailyDocuments extends Command
{
    protected $signature = 'documents:generate-daily';

    public function handle(): void
    {
        $today = now()->toDateString();
        $types = DocumentType::where('is_active', true)->get();
        $credentials = FiscalCredential::where('is_active', true)->get();

        $jobs = [];
        foreach ($credentials as $credential) {
            foreach ($types as $type) {
                $jobs[] = new GenerateDocumentJob($credential->id, $type->id, $today);
            }
        }

        Bus::batch($jobs)
            ->name("daily-documents-{$today}")
            ->allowFailures()
            ->dispatch();

        $this->info(count($jobs) . ' jobs despachados.');
    }
}
```

```php
// routes/console.php (Laravel 13 ya no usa Kernel.php para scheduling por defecto)
Schedule::command('documents:generate-daily')->dailyAt('02:00')->withoutOverlapping();
```

Asegúrate de tener `queue:work` corriendo (Horizon recomendado si el volumen crece) — si no, los jobs se acumulan sin procesarse.

---

## 5. Bot de WhatsApp (Meta Cloud API)

### 5.1 Flujo conversacional

```
Usuario escribe algo
   │
   ▼
¿Teléfono vinculado a un User? ──No──▶ "No reconozco este número. Pide a tu administrador
   │ Sí                                 que lo vincule en tu perfil, o contáctanos."
   ▼
¿Tiene 1 o varias FiscalCredential accesibles?
   │
   ├─ 1 credencial ─▶ Mostrar menú de tipos de documento (botones interactivos)
   │
   └─ Varias (ej. admin viendo varios usuarios/empresa)
         ▶ Primero pedir elegir el RFC/entidad, luego el tipo de documento

Usuario elige tipo de documento
   │
   ▼
Buscar Document::latestSuccessful() para esa credencial+tipo
   │
   ├─ Existe y es de hoy o de un día hábil razonable ─▶ Enviar PDF por WhatsApp (documento adjunto)
   │
   └─ No existe todavía (nunca se generó / falló) ─▶ Avisar "Se está generando, te aviso en unos minutos"
                                                        y despachar GenerateDocumentJob on-demand
```

### 5.2 Modelos de soporte ya definidos arriba (`WhatsappConversation`, `WhatsappMessageLog`)

### 5.3 Cliente de envío (Meta Cloud API)

```php
// config/services.php
'whatsapp' => [
    'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
    'access_token' => env('WHATSAPP_ACCESS_TOKEN'),
    'api_version' => env('WHATSAPP_API_VERSION', 'v21.0'),
    'verify_token' => env('WHATSAPP_WEBHOOK_VERIFY_TOKEN'),
    'app_secret' => env('WHATSAPP_APP_SECRET'), // para validar firma del webhook
],
```

```php
// app/Services/WhatsApp/WhatsAppClient.php
class WhatsAppClient
{
    protected function http(): PendingRequest
    {
        $cfg = config('services.whatsapp');
        return Http::baseUrl("https://graph.facebook.com/{$cfg['api_version']}/{$cfg['phone_number_id']}")
            ->withToken($cfg['access_token']);
    }

    public function sendText(string $to, string $body): array
    {
        return $this->http()->post('/messages', [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'text',
            'text' => ['body' => $body],
        ])->throw()->json();
    }

    /** Botones interactivos, máx. 3 opciones por mensaje (límite de Meta) */
    public function sendButtons(string $to, string $bodyText, array $buttons): array
    {
        // $buttons = [['id' => 'doc_oc', 'title' => 'Opinión de Cumplimiento'], ...]
        return $this->http()->post('/messages', [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'interactive',
            'interactive' => [
                'type' => 'button',
                'body' => ['text' => $bodyText],
                'action' => [
                    'buttons' => collect($buttons)->map(fn ($b) => [
                        'type' => 'reply',
                        'reply' => ['id' => $b['id'], 'title' => $b['title']],
                    ])->all(),
                ],
            ],
        ])->throw()->json();
    }

    /** El PDF debe subirse primero al endpoint /media de Meta, o enviarse por 'link' público de corta vida */
    public function sendDocumentByLink(string $to, string $link, string $filename, ?string $caption = null): array
    {
        return $this->http()->post('/messages', [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'document',
            'document' => ['link' => $link, 'filename' => $filename, 'caption' => $caption],
        ])->throw()->json();
    }
}
```

Para el envío del PDF: como tu bucket S3 es privado, genera una **URL firmada temporal** (`Storage::disk('s3_private')->temporaryUrl($path, now()->addMinutes(10))`) y pásala a `sendDocumentByLink`. Nunca hagas el bucket público.

### 5.4 Webhook (recepción)

```php
// routes/api.php
Route::get('/whatsapp/webhook', [WhatsAppWebhookController::class, 'verify']);
Route::post('/whatsapp/webhook', [WhatsAppWebhookController::class, 'handle'])
    ->middleware('whatsapp.signature'); // valida X-Hub-Signature-256 con app_secret
```

```php
// app/Http/Controllers/WhatsAppWebhookController.php
class WhatsAppWebhookController extends Controller
{
    public function verify(Request $request)
    {
        if ($request->get('hub_verify_token') === config('services.whatsapp.verify_token')) {
            return response($request->get('hub_challenge'), 200);
        }
        return response('Invalid verify token', 403);
    }

    public function handle(Request $request, WhatsAppBotService $bot)
    {
        $entry = $request->input('entry.0.changes.0.value');
        $message = $entry['messages'][0] ?? null;

        if (!$message) {
            return response()->json(['status' => 'ignored']); // ej. es un status update (delivered/read)
        }

        // Idempotencia: Meta puede reintentar el mismo webhook
        if (WhatsappMessageLog::where('wa_message_id', $message['id'])->exists()) {
            return response()->json(['status' => 'duplicate']);
        }

        WhatsappMessageLog::create([
            'wa_message_id' => $message['id'],
            'direction' => 'inbound',
            'whatsapp_phone' => $message['from'],
            'payload' => $message,
        ]);

        // Procesar de forma asíncrona para responder rápido a Meta (requiere 200 en <20s)
        ProcessWhatsAppMessageJob::dispatch($message);

        return response()->json(['status' => 'ok']);
    }
}
```

### 5.5 Lógica conversacional (simplificada)

```php
// app/Services/WhatsApp/WhatsAppBotService.php
class WhatsAppBotService
{
    public function __construct(
        private WhatsAppClient $client,
        private DocumentAccessService $access,
    ) {}

    public function handleInboundMessage(array $message): void
    {
        $phone = $message['from'];
        $user = User::where('whatsapp_phone', $phone)->first();

        if (!$user) {
            $this->client->sendText($phone,
                'No reconozco este número. Pide a tu administrador que lo vincule a tu cuenta.');
            return;
        }

        $conversation = WhatsappConversation::firstOrCreate(
            ['whatsapp_phone' => $phone],
            ['user_id' => $user->id, 'state' => 'idle']
        );

        $text = $message['text']['body'] ?? null;
        $buttonReplyId = $message['interactive']['button_reply']['id'] ?? null;

        match (true) {
            $buttonReplyId !== null => $this->handleButtonReply($conversation, $user, $buttonReplyId),
            $conversation->state === 'idle' => $this->showMainMenu($conversation, $user),
            default => $this->showMainMenu($conversation, $user), // fallback simple
        };
    }

    protected function showMainMenu(WhatsappConversation $conversation, User $user): void
    {
        $credentials = $this->access->accessibleCredentials($user);

        if ($credentials->isEmpty()) {
            $this->client->sendText($conversation->whatsapp_phone, 'No tienes documentos fiscales configurados todavía.');
            return;
        }

        if ($credentials->count() === 1) {
            $conversation->update([
                'state' => 'awaiting_document_choice',
                'context' => ['fiscal_credential_id' => $credentials->first()->id],
            ]);
            $this->sendDocumentTypeButtons($conversation);
            return;
        }

        // varias credenciales (owner/admin) -> primero elegir cuál
        $conversation->update(['state' => 'awaiting_credential_choice']);
        $buttons = $credentials->take(3)->map(fn ($c) => ['id' => "cred_{$c->id}", 'title' => $c->rfc])->all();
        $this->client->sendButtons($conversation->whatsapp_phone, '¿De qué RFC quieres el documento?', $buttons);
    }

    protected function sendDocumentTypeButtons(WhatsappConversation $conversation): void
    {
        $types = DocumentType::where('is_active', true)->orderBy('sort_order')->get();
        $buttons = $types->map(fn ($t) => ['id' => "doc_{$t->id}", 'title' => $t->name])->all();
        $this->client->sendButtons($conversation->whatsapp_phone, 'Elige el documento que necesitas:', $buttons);
    }

    protected function handleButtonReply(WhatsappConversation $conversation, User $user, string $buttonId): void
    {
        if (str_starts_with($buttonId, 'cred_')) {
            $credentialId = (int) str_replace('cred_', '', $buttonId);
            $conversation->update([
                'state' => 'awaiting_document_choice',
                'context' => ['fiscal_credential_id' => $credentialId],
            ]);
            $this->sendDocumentTypeButtons($conversation);
            return;
        }

        if (str_starts_with($buttonId, 'doc_')) {
            $documentTypeId = (int) str_replace('doc_', '', $buttonId);
            $credentialId = $conversation->context['fiscal_credential_id'] ?? null;
            $this->deliverDocument($conversation, $credentialId, $documentTypeId);
            $conversation->update(['state' => 'idle', 'context' => null]);
        }
    }

    protected function deliverDocument(WhatsappConversation $conversation, int $credentialId, int $documentTypeId): void
    {
        $document = Document::query()
            ->where('fiscal_credential_id', $credentialId)
            ->where('document_type_id', $documentTypeId)
            ->latestSuccessful()
            ->first();

        if (!$document) {
            $this->client->sendText($conversation->whatsapp_phone,
                'Aún no tengo ese documento generado. Lo estoy generando, te lo mando en unos minutos.');

            GenerateDocumentJob::dispatch($credentialId, $documentTypeId, now()->toDateString());
            return;
        }

        $link = Storage::disk('s3_private')->temporaryUrl($document->file_path, now()->addMinutes(10));
        $filename = $document->documentType->name . '.pdf';

        $this->client->sendDocumentByLink($conversation->whatsapp_phone, $link, $filename);
    }
}
```

> Esto es un esqueleto funcional pero simplificado — para producción conviene envolver `handleInboundMessage` en un `try/catch` que responda algo genérico y reporte el error, y mover el "generar bajo demanda" a que también notifique automáticamente al usuario cuando el job termine (ej. `GenerateDocumentJob` dispara un evento que el bot escucha para hacer `sendDocumentByLink` cuando el status pase a `success`).

---

## 6. Filament 5 (panel para `owner`/`administrator`)

Con `canAccessPanel()` ya restringido a esos dos roles (ver modelo `User`), en cada Resource aplica scoping por compañía:

```php
// app/Filament/Resources/DocumentResource.php
class DocumentResource extends Resource
{
    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();

        return parent::getEloquentQuery()
            ->whereHas('fiscalCredential', function (Builder $q) use ($user) {
                $q->where(fn ($q) => $q->where('owner_type', Company::class)->where('owner_id', $user->company_id))
                  ->orWhere(fn ($q) => $q->where('owner_type', User::class)
                        ->whereIn('owner_id', $user->company->users()->pluck('id')));
            });
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('fiscalCredential.rfc'),
            TextColumn::make('documentType.name'),
            BadgeColumn::make('status')->colors(['success' => 'success', 'danger' => 'failed', 'warning' => 'pending']),
            TextColumn::make('generated_for_date')->date(),
        ])->actions([
            Action::make('download')
                ->visible(fn (Document $r) => $r->status === 'success')
                ->url(fn (Document $r) => Storage::disk('s3_private')->temporaryUrl($r->file_path, now()->addMinutes(10)))
                ->openUrlInNewTab(),
        ]);
    }
}
```

Resources recomendados: `CompanyResource`, `UserResource` (con select de `role` y `company_id`, campo `whatsapp_phone`), `FiscalCredentialResource` (FileUpload apuntando al disco S3 privado para `.cer`/`.key`, campo password como `TextInput::make('password')->password()` mapeado al cast `encrypted`), `DocumentTypeResource` (para habilitar/deshabilitar tipos y ver `handler_class`), `DocumentResource` (solo lectura + descarga).

`FiscalCredentialResource` — importante: el campo password en el form NO debe mostrarse en texto plano al editar; usa `->dehydrateStateUsing()` para solo actualizarlo si se llenó, y nunca muestres el valor actual descifrado.

---

## 7. Seguridad (checklist)

- Disco S3 dedicado y **privado** (`s3_private`) sin ACL pública, separado del disco público de assets si tienes uno.
- Bucket con versionado + `PutObject`-only para el usuario IAM de la app en la ruta de FIEL; considera bucket policy que bloquee `s3:GetObject` público explícitamente.
- Cifrado en reposo: server-side encryption S3 (SSE-S3 o SSE-KMS) + el password ya cifrado a nivel aplicación con `encrypted` cast de Laravel (doble capa).
- Rotar `APP_KEY` es un evento peligroso: si cambias `APP_KEY` sin re-encriptar los `password_encrypted` existentes, quedan ilegibles. Documenta este procedimiento antes de que pase en producción.
- Verificar la firma `X-Hub-Signature-256` de cada webhook de Meta usando `app_secret` (middleware dedicado) — de lo contrario cualquiera puede pegarle a tu endpoint.
- Los archivos temporales del `.cer/.key` que bajas a disco local (sección 3.2) deben limpiarse siempre (usa `finally`), e idealmente ese `storage/app/tmp` no debe ser accesible por el servidor web.
- Rate-limit al webhook y usa colas — nunca proceses síncronamente una llamada a SatGo dentro del request del webhook (Meta espera respuesta <20s).
- Considera expirar/rotar los certificados FIEL vencidos: usa `fiel_expires_at` para que Filament marque credenciales por vencer y el job diario las salte con una alerta.

---

## 8. Cómo obtener las credenciales

### 8.1 SatGo
1. Regístrate y entra a `https://web.sat-go.com`.
2. Llama una sola vez a `POST /api/v1/users/CreateKey` (autenticado) para generar tu **API Key** permanente.
3. Guarda esa key en `.env` como `SATGO_API_KEY` — con ella tu backend obtiene un JWT nuevo cada vez que expira, vía `/api/auth/token-json` (ya implementado en `SatGoAuthService`).

### 8.2 WhatsApp (Meta Cloud API)
1. Crea una app en [developers.facebook.com](https://developers.facebook.com/apps) tipo "Business".
2. Agrega el producto **WhatsApp** a la app.
3. En modo pruebas obtienes un número de prueba + token temporal (24h) — úsalo solo para desarrollo.
4. Para producción: crea un **System User** en Meta Business Suite, asígnale el activo WhatsApp Business Account (WABA), y genera un **token permanente** con permisos `whatsapp_business_messaging` y `whatsapp_business_management`.
5. Anota el **Phone Number ID** y el **WABA ID** (los ves en la configuración de la app → WhatsApp → API Setup).
6. Configura el **Webhook**: URL pública (`https://tu-dominio.com/api/whatsapp/webhook`) + el **Verify Token** que tú definas (debe matchear `WHATSAPP_WEBHOOK_VERIFY_TOKEN`), suscríbete al campo `messages`.
7. Registra el número de teléfono de producción (portar un número existente o usar uno nuevo comprado vía Meta) y complétalo el proceso de verificación de negocio (Business Verification) — sin esto Meta limita el volumen de mensajes salientes.
8. Ten en cuenta la **ventana de 24 horas**: solo puedes responder libremente a un usuario si te escribió en las últimas 24h; para enviar el documento *de forma proactiva* (ej. avisar que ya está listo sin que el usuario haya escrito primero) necesitas una **plantilla de mensaje (template)** aprobada por Meta.

---

## 9. Próximos pasos sugeridos

1. Confirmar si el RFC vive a nivel `Company`, `User`, o ambos (afecta si de verdad necesitas el morph o puedes simplificar a `Company hasOne FiscalCredential`).
2. Decidir la política de expiración/retención de `Document` (¿borras los de hace 90 días de S3?).
3. Definir qué pasa si SatGo falla 3 veces seguidas para una credencial (¿alerta a Filament? ¿notificación al owner por WhatsApp?).
4. Confirmar el header `RFC` exacto que exige cada endpoint FIEL contra Swagger antes de codificar (`https://api.sat-go.com/swagger`), ya que no siempre aparece explícito en el doc público.

¿Quieres que continúe con el código completo de las migraciones + Filament resources como archivos listos para copiar a tu proyecto, o prefieres que primero resolvamos las preguntas del punto 9?
# Arquitectura: Bot de WhatsApp para documentos fiscales (SatGo) — Laravel 13 + Filament 5

## 0. Resumen de decisiones

| Decisión | Elegido |
|---|---|
| WhatsApp | Meta Cloud API (directo, oficial) |
| Documentos iniciales | Opinión de Cumplimiento (`/api/v2/consultar/ocfiel`) + Constancia de Situación Fiscal (`/api/v2/consultar/csffiel`) |
| Guardado de FIEL | Tú guardas `.cer` + `.key` en S3, password cifrada en DB (Opción B de SatGo: `Certificado + llavePrivada + Contrasena`) |
| Generación | Job diario 2:00 AM que genera y cachea ambos documentos por cada RFC activo |
| Multi-tenant | `Company` → muchos `User` (roles `owner` / `administrator` / `user`) |

Nota sobre el punto 3: como decidiste guardar tú el `.cer/.key/password`, cada llamada a SatGo se hace con `multipart/form-data` (Opción B), sin usar su SecretProvider. Esto significa que **tú eres responsable de la seguridad de esos archivos** — el diseño de abajo lo trata con el mismo nivel de cuidado que credenciales de firma electrónica (S3 privado, cifrado en reposo, nunca expuesto por URL pública, borrado de temporales tras cada uso).

---

## 1. Modelo de datos

```
Company 1───* User
Company 1───1 FiscalCredential  (o User 1───1 FiscalCredential, ver nota)
FiscalCredential 1───* Document
DocumentType 1───* Document
User 1───* WhatsappConversation
WhatsappConversation 1───* WhatsappMessageLog
```

### Nota importante a decidir con tu negocio: ¿el RFC es de la empresa o de cada usuario?

En México normalmente **la FIEL/CIEC pertenece a un RFC específico** (persona física o moral), no a un usuario individual del sistema. Dado que dijiste "un usuario con rol admin/owner puede ver documentos de la compañía y de usuarios relacionados", lo más común es:

- **Company** tiene **un** RFC/FIEL propio (persona moral) → documentos "de la empresa".
- Opcionalmente, cada **User** puede tener **su propio** RFC/FIEL personal (persona física, ej. el dueño facturando a título personal) → documentos "propios".

Por eso modelo `FiscalCredential` como **polimórfico** (`owner_type`, `owner_id`), pudiendo pertenecer a `Company` o a `User`. Si en tu caso *solo* existe un RFC por compañía, simplemente no uses la variante de `User` y todo se simplifica — pero dejar el morph desde el inicio evita una migración dolorosa después.

### Migraciones

```php
// companies
Schema::create('companies', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->string('rfc', 13)->nullable(); // solo informativo, el fiscal real vive en fiscal_credentials
    $table->boolean('is_active')->default(true);
    $table->timestamps();
});

// users (ampliar la migración default de Laravel)
Schema::table('users', function (Blueprint $table) {
    $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
    $table->enum('role', ['owner', 'administrator', 'user'])->default('user');
    $table->string('whatsapp_phone', 20)->nullable()->unique(); // formato E.164 sin '+', ej. 5215512345678
    $table->boolean('whatsapp_verified')->default(false);
    $table->timestamp('whatsapp_verified_at')->nullable();
});

// fiscal_credentials
Schema::create('fiscal_credentials', function (Blueprint $table) {
    $table->id();
    $table->morphs('owner'); // owner_type, owner_id -> Company o User
    $table->string('rfc', 13);
    $table->string('cer_path');       // path en disco S3 privado
    $table->string('key_path');       // path en disco S3 privado
    $table->text('password_encrypted'); // Laravel encrypted cast
    $table->date('fiel_expires_at')->nullable(); // vigencia del certificado
    $table->boolean('is_active')->default(true);
    $table->timestamp('last_validated_at')->nullable();
    $table->timestamps();

    $table->unique(['owner_type', 'owner_id', 'rfc']);
});

// document_types  (tabla, no enum puro, para poder agregar tipos sin deploy de código en el futuro)
Schema::create('document_types', function (Blueprint $table) {
    $table->id();
    $table->string('code')->unique();     // 'opinion_cumplimiento', 'constancia_fiscal'
    $table->string('name');               // "Opinión de Cumplimiento"
    $table->string('handler_class');      // FQCN de la clase que sabe llamar a SatGo para este tipo
    $table->boolean('is_active')->default(true);
    $table->unsignedInteger('sort_order')->default(0);
    $table->timestamps();
});

// documents
Schema::create('documents', function (Blueprint $table) {
    $table->id();
    $table->foreignId('fiscal_credential_id')->constrained()->cascadeOnDelete();
    $table->foreignId('document_type_id')->constrained();
    $table->string('file_path')->nullable();   // S3, null hasta que status = success
    $table->string('status')->default('pending'); // pending|success|failed
    $table->text('error_message')->nullable();
    $table->date('generated_for_date'); // el "día" al que corresponde (para el job de las 2am)
    $table->timestamp('generated_at')->nullable();
    $table->timestamps();

    $table->unique(['fiscal_credential_id', 'document_type_id', 'generated_for_date']);
});

// whatsapp_conversations  (estado de la conversación / máquina de estados)
Schema::create('whatsapp_conversations', function (Blueprint $table) {
    $table->id();
    $table->string('whatsapp_phone', 20);
    $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
    $table->string('state')->default('idle'); // idle|awaiting_document_choice|awaiting_target_choice|...
    $table->json('context')->nullable();      // datos temporales del flujo actual
    $table->timestamp('last_interaction_at')->nullable();
    $table->timestamps();
});

// whatsapp_message_logs (auditoría + idempotencia de webhooks)
Schema::create('whatsapp_message_logs', function (Blueprint $table) {
    $table->id();
    $table->string('wa_message_id')->unique()->nullable(); // id que manda Meta, evita procesar duplicados
    $table->string('direction'); // inbound|outbound
    $table->string('whatsapp_phone', 20);
    $table->json('payload');
    $table->string('status')->nullable(); // sent|delivered|read|failed (para outbound)
    $table->timestamps();
});
```

### Modelos (Eloquent)

```php
// app/Models/Company.php
class Company extends Model
{
    protected $fillable = ['name', 'rfc', 'is_active'];

    public function users(): HasMany { return $this->hasMany(User::class); }
    public function fiscalCredential(): MorphOne { return $this->morphOne(FiscalCredential::class, 'owner'); }
}

// app/Models/User.php (extiende el User default de Laravel)
class User extends Authenticatable implements FilamentUser
{
    protected $fillable = ['name', 'email', 'password', 'company_id', 'role', 'whatsapp_phone', 'whatsapp_verified'];
    protected $casts = ['whatsapp_verified' => 'boolean'];

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function fiscalCredential(): MorphOne { return $this->morphOne(FiscalCredential::class, 'owner'); }

    public function isOwnerOrAdmin(): bool
    {
        return in_array($this->role, ['owner', 'administrator']);
    }

    // Solo owner/administrator entran al panel Filament; 'user' solo usa WhatsApp
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->isOwnerOrAdmin();
    }
}

// app/Models/FiscalCredential.php
class FiscalCredential extends Model
{
    protected $fillable = ['rfc', 'cer_path', 'key_path', 'password_encrypted', 'fiel_expires_at', 'is_active'];
    protected $casts = [
        'password_encrypted' => 'encrypted', // Laravel cifra/descifra automáticamente con APP_KEY
        'fiel_expires_at' => 'date',
        'is_active' => 'boolean',
    ];

    public function owner(): MorphTo { return $this->morphTo(); }
    public function documents(): HasMany { return $this->hasMany(Document::class); }
}

// app/Models/DocumentType.php
class DocumentType extends Model
{
    protected $fillable = ['code', 'name', 'handler_class', 'is_active', 'sort_order'];
    public function documents(): HasMany { return $this->hasMany(Document::class); }
}

// app/Models/Document.php
class Document extends Model
{
    protected $fillable = [
        'fiscal_credential_id', 'document_type_id', 'file_path',
        'status', 'error_message', 'generated_for_date', 'generated_at',
    ];
    protected $casts = ['generated_for_date' => 'date', 'generated_at' => 'datetime'];

    public function fiscalCredential(): BelongsTo { return $this->belongsTo(FiscalCredential::class); }
    public function documentType(): BelongsTo { return $this->belongsTo(DocumentType::class); }

    public function scopeLatestSuccessful(Builder $q): Builder
    {
        return $q->where('status', 'success')->latest('generated_for_date');
    }
}
```

---

## 2. Autorización: quién puede ver qué documento

Regla de negocio: `owner`/`administrator` ven todos los documentos de su compañía (empresa + de cada usuario asociado); `user` solo ve los suyos.

```php
// app/Policies/DocumentPolicy.php
class DocumentPolicy
{
    public function view(User $user, Document $document): bool
    {
        $owner = $document->fiscalCredential->owner; // Company o User

        if ($owner instanceof Company) {
            // documento de la empresa: solo owner/administrator de esa empresa
            return $user->company_id === $owner->id && $user->isOwnerOrAdmin();
        }

        if ($owner instanceof User) {
            if ($owner->is($user)) {
                return true; // es su propio documento
            }
            // owner/administrator de la misma empresa puede ver docs personales de sus usuarios
            return $user->isOwnerOrAdmin() && $user->company_id === $owner->company_id;
        }

        return false;
    }
}
```

Esta misma lógica se reutiliza en el bot de WhatsApp (para decidir qué RFCs puede consultar quien escribe) y en Filament (a través de `EloquentQuery` scoping en el resource, ver sección 6).

Método útil centralizado:

```php
// app/Services/DocumentAccessService.php
class DocumentAccessService
{
    /** Devuelve las FiscalCredential que este usuario puede consultar por WhatsApp */
    public function accessibleCredentials(User $user): Collection
    {
        if ($user->isOwnerOrAdmin() && $user->company_id) {
            return FiscalCredential::query()
                ->where(function (Builder $q) use ($user) {
                    $q->where(fn ($q) => $q->where('owner_type', Company::class)->where('owner_id', $user->company_id))
                      ->orWhere(fn ($q) => $q->where('owner_type', User::class)
                            ->whereIn('owner_id', $user->company->users()->pluck('id')));
                })
                ->where('is_active', true)
                ->get();
        }

        // rol 'user': solo su propia credencial personal (si tiene)
        return $user->fiscalCredential ? collect([$user->fiscalCredential]) : collect();
    }
}
```

---

## 3. Cliente de SatGo

### 3.1 Autenticación (JWT cacheado)

```php
// config/services.php
'satgo' => [
    'base_url' => env('SATGO_BASE_URL', 'https://api.sat-go.com'),
    'api_key' => env('SATGO_API_KEY'), // obtenida una sola vez con /api/v1/users/CreateKey (ver sección 8)
],
```

```php
// app/Services/SatGo/SatGoAuthService.php
class SatGoAuthService
{
    public function token(): string
    {
        return Cache::remember('satgo:jwt', now()->addHours(6), function () {
            $response = Http::baseUrl(config('services.satgo.base_url'))
                ->post('/api/auth/token-json', ['key' => config('services.satgo.api_key')])
                ->throw();

            $data = $response->json();
            // cachea un poco menos que expiresAt real por seguridad
            Cache::put('satgo:jwt', $data['token'], now()->parse($data['expiresAt'])->subMinutes(5));

            return $data['token'];
        });
    }
}
```

### 3.2 Cliente base + descarga temporal de FIEL desde S3

```php
// app/Services/SatGo/SatGoClient.php
class SatGoClient
{
    public function __construct(private SatGoAuthService $auth) {}

    protected function http(): PendingRequest
    {
        return Http::baseUrl(config('services.satgo.base_url'))
            ->withToken($this->auth->token())
            ->timeout(120);
    }

    /**
     * Descarga temporalmente .cer y .key de S3 a un directorio local efímero,
     * ejecuta el callback, y borra los archivos SIEMPRE (incluso si falla).
     */
    public function withTemporaryFielFiles(FiscalCredential $credential, callable $callback): mixed
    {
        $tmpDir = storage_path('app/tmp/fiel_' . Str::uuid());
        File::ensureDirectoryExists($tmpDir);

        $cerLocal = $tmpDir . '/cert.cer';
        $keyLocal = $tmpDir . '/priv.key';

        try {
            File::put($cerLocal, Storage::disk('s3_private')->get($credential->cer_path));
            File::put($keyLocal, Storage::disk('s3_private')->get($credential->key_path));

            return $callback($cerLocal, $keyLocal);
        } finally {
            File::deleteDirectory($tmpDir); // limpieza obligatoria, incluye password en memoria del proceso hijo si aplica
        }
    }

    public function opinionCumplimiento(FiscalCredential $credential): string
    {
        return $this->withTemporaryFielFiles($credential, function ($cer, $key) use ($credential) {
            $response = $this->http()
                ->attach('Certificado', file_get_contents($cer), 'cert.cer')
                ->attach('llavePrivada', file_get_contents($key), 'priv.key')
                ->post('/api/v2/consultar/ocfiel', [
                    'Contrasena' => $credential->password_encrypted, // ya viene descifrado por el cast
                ]);

            if ($response->failed()) {
                throw new SatGoException($response->body(), $response->status());
            }

            return $response->body(); // bytes del PDF
        });
    }

    public function constanciaSituacionFiscal(FiscalCredential $credential): string
    {
        return $this->withTemporaryFielFiles($credential, function ($cer, $key) use ($credential) {
            $response = $this->http()
                ->attach('Certificado', file_get_contents($cer), 'cert.cer')
                ->attach('llavePrivada', file_get_contents($key), 'priv.key')
                ->post('/api/v2/consultar/csffiel', [
                    'Contrasena' => $credential->password_encrypted,
                ]);

            if ($response->failed()) {
                throw new SatGoException($response->body(), $response->status());
            }

            return $response->body();
        });
    }
}
```

> Nota: `attach()` requiere pasar el header `RFC` que pide la doc en algunos endpoints v2 — confírmalo contra Swagger antes de producción; si lo pide, añade `->withHeaders(['RFC' => $credential->rfc])`.

### 3.3 Handlers por tipo de documento (así agregas tipos nuevos sin tocar el core)

```php
// app/Contracts/DocumentGeneratorHandler.php
interface DocumentGeneratorHandler
{
    public function generate(FiscalCredential $credential): string; // regresa bytes del archivo
    public function fileExtension(): string; // 'pdf', 'zip', etc.
}

// app/Services/SatGo/Handlers/OpinionCumplimientoHandler.php
class OpinionCumplimientoHandler implements DocumentGeneratorHandler
{
    public function __construct(private SatGoClient $client) {}
    public function generate(FiscalCredential $credential): string { return $this->client->opinionCumplimiento($credential); }
    public function fileExtension(): string { return 'pdf'; }
}

// app/Services/SatGo/Handlers/ConstanciaFiscalHandler.php
class ConstanciaFiscalHandler implements DocumentGeneratorHandler
{
    public function __construct(private SatGoClient $client) {}
    public function generate(FiscalCredential $credential): string { return $this->client->constanciaSituacionFiscal($credential); }
    public function fileExtension(): string { return 'pdf'; }
}
```

`document_types.handler_class` guarda el FQCN (`App\Services\SatGo\Handlers\OpinionCumplimientoHandler`). Para agregar un tercer documento en el futuro: creas el handler, agregas el método al `SatGoClient`, y das de alta un registro en `document_types` desde Filament. Cero cambios al job ni al bot.

---

## 4. Generación diaria (2:00 AM)

### 4.1 Job por documento (permite reintentos y paralelismo vía queue)

```php
// app/Jobs/GenerateDocumentJob.php
class GenerateDocumentJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels, Dispatchable;

    public int $tries = 3;
    public int $backoff = 60;

    public function __construct(
        public int $fiscalCredentialId,
        public int $documentTypeId,
        public string $forDate, // 'Y-m-d'
    ) {}

    public function handle(): void
    {
        $credential = FiscalCredential::findOrFail($this->fiscalCredentialId);
        $type = DocumentType::findOrFail($this->documentTypeId);

        $document = Document::updateOrCreate(
            [
                'fiscal_credential_id' => $credential->id,
                'document_type_id' => $type->id,
                'generated_for_date' => $this->forDate,
            ],
            ['status' => 'pending']
        );

        try {
            /** @var DocumentGeneratorHandler $handler */
            $handler = app($type->handler_class);
            $bytes = $handler->generate($credential);

            $path = "documents/{$credential->rfc}/{$type->code}/{$this->forDate}.{$handler->fileExtension()}";
            Storage::disk('s3_private')->put($path, $bytes);

            $document->update([
                'file_path' => $path,
                'status' => 'success',
                'generated_at' => now(),
                'error_message' => null,
            ]);
        } catch (\Throwable $e) {
            $document->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            report($e);
            throw $e; // deja que el queue reintente según $tries
        }
    }
}
```

### 4.2 Comando que despacha todos los jobs del día

```php
// app/Console/Commands/GenerateDailyDocuments.php
class GenerateDailyDocuments extends Command
{
    protected $signature = 'documents:generate-daily';

    public function handle(): void
    {
        $today = now()->toDateString();
        $types = DocumentType::where('is_active', true)->get();
        $credentials = FiscalCredential::where('is_active', true)->get();

        $jobs = [];
        foreach ($credentials as $credential) {
            foreach ($types as $type) {
                $jobs[] = new GenerateDocumentJob($credential->id, $type->id, $today);
            }
        }

        Bus::batch($jobs)
            ->name("daily-documents-{$today}")
            ->allowFailures()
            ->dispatch();

        $this->info(count($jobs) . ' jobs despachados.');
    }
}
```

```php
// routes/console.php (Laravel 13 ya no usa Kernel.php para scheduling por defecto)
Schedule::command('documents:generate-daily')->dailyAt('02:00')->withoutOverlapping();
```

Asegúrate de tener `queue:work` corriendo (Horizon recomendado si el volumen crece) — si no, los jobs se acumulan sin procesarse.

---

## 5. Bot de WhatsApp (Meta Cloud API)

### 5.1 Flujo conversacional

```
Usuario escribe algo
   │
   ▼
¿Teléfono vinculado a un User? ──No──▶ "No reconozco este número. Pide a tu administrador
   │ Sí                                 que lo vincule en tu perfil, o contáctanos."
   ▼
¿Tiene 1 o varias FiscalCredential accesibles?
   │
   ├─ 1 credencial ─▶ Mostrar menú de tipos de documento (botones interactivos)
   │
   └─ Varias (ej. admin viendo varios usuarios/empresa)
         ▶ Primero pedir elegir el RFC/entidad, luego el tipo de documento

Usuario elige tipo de documento
   │
   ▼
Buscar Document::latestSuccessful() para esa credencial+tipo
   │
   ├─ Existe y es de hoy o de un día hábil razonable ─▶ Enviar PDF por WhatsApp (documento adjunto)
   │
   └─ No existe todavía (nunca se generó / falló) ─▶ Avisar "Se está generando, te aviso en unos minutos"
                                                        y despachar GenerateDocumentJob on-demand
```

### 5.2 Modelos de soporte ya definidos arriba (`WhatsappConversation`, `WhatsappMessageLog`)

### 5.3 Cliente de envío (Meta Cloud API)

```php
// config/services.php
'whatsapp' => [
    'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
    'access_token' => env('WHATSAPP_ACCESS_TOKEN'),
    'api_version' => env('WHATSAPP_API_VERSION', 'v21.0'),
    'verify_token' => env('WHATSAPP_WEBHOOK_VERIFY_TOKEN'),
    'app_secret' => env('WHATSAPP_APP_SECRET'), // para validar firma del webhook
],
```

```php
// app/Services/WhatsApp/WhatsAppClient.php
class WhatsAppClient
{
    protected function http(): PendingRequest
    {
        $cfg = config('services.whatsapp');
        return Http::baseUrl("https://graph.facebook.com/{$cfg['api_version']}/{$cfg['phone_number_id']}")
            ->withToken($cfg['access_token']);
    }

    public function sendText(string $to, string $body): array
    {
        return $this->http()->post('/messages', [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'text',
            'text' => ['body' => $body],
        ])->throw()->json();
    }

    /** Botones interactivos, máx. 3 opciones por mensaje (límite de Meta) */
    public function sendButtons(string $to, string $bodyText, array $buttons): array
    {
        // $buttons = [['id' => 'doc_oc', 'title' => 'Opinión de Cumplimiento'], ...]
        return $this->http()->post('/messages', [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'interactive',
            'interactive' => [
                'type' => 'button',
                'body' => ['text' => $bodyText],
                'action' => [
                    'buttons' => collect($buttons)->map(fn ($b) => [
                        'type' => 'reply',
                        'reply' => ['id' => $b['id'], 'title' => $b['title']],
                    ])->all(),
                ],
            ],
        ])->throw()->json();
    }

    /** El PDF debe subirse primero al endpoint /media de Meta, o enviarse por 'link' público de corta vida */
    public function sendDocumentByLink(string $to, string $link, string $filename, ?string $caption = null): array
    {
        return $this->http()->post('/messages', [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'document',
            'document' => ['link' => $link, 'filename' => $filename, 'caption' => $caption],
        ])->throw()->json();
    }
}
```

Para el envío del PDF: como tu bucket S3 es privado, genera una **URL firmada temporal** (`Storage::disk('s3_private')->temporaryUrl($path, now()->addMinutes(10))`) y pásala a `sendDocumentByLink`. Nunca hagas el bucket público.

### 5.4 Webhook (recepción)

```php
// routes/api.php
Route::get('/whatsapp/webhook', [WhatsAppWebhookController::class, 'verify']);
Route::post('/whatsapp/webhook', [WhatsAppWebhookController::class, 'handle'])
    ->middleware('whatsapp.signature'); // valida X-Hub-Signature-256 con app_secret
```

```php
// app/Http/Controllers/WhatsAppWebhookController.php
class WhatsAppWebhookController extends Controller
{
    public function verify(Request $request)
    {
        if ($request->get('hub_verify_token') === config('services.whatsapp.verify_token')) {
            return response($request->get('hub_challenge'), 200);
        }
        return response('Invalid verify token', 403);
    }

    public function handle(Request $request, WhatsAppBotService $bot)
    {
        $entry = $request->input('entry.0.changes.0.value');
        $message = $entry['messages'][0] ?? null;

        if (!$message) {
            return response()->json(['status' => 'ignored']); // ej. es un status update (delivered/read)
        }

        // Idempotencia: Meta puede reintentar el mismo webhook
        if (WhatsappMessageLog::where('wa_message_id', $message['id'])->exists()) {
            return response()->json(['status' => 'duplicate']);
        }

        WhatsappMessageLog::create([
            'wa_message_id' => $message['id'],
            'direction' => 'inbound',
            'whatsapp_phone' => $message['from'],
            'payload' => $message,
        ]);

        // Procesar de forma asíncrona para responder rápido a Meta (requiere 200 en <20s)
        ProcessWhatsAppMessageJob::dispatch($message);

        return response()->json(['status' => 'ok']);
    }
}
```

### 5.5 Lógica conversacional (simplificada)

```php
// app/Services/WhatsApp/WhatsAppBotService.php
class WhatsAppBotService
{
    public function __construct(
        private WhatsAppClient $client,
        private DocumentAccessService $access,
    ) {}

    public function handleInboundMessage(array $message): void
    {
        $phone = $message['from'];
        $user = User::where('whatsapp_phone', $phone)->first();

        if (!$user) {
            $this->client->sendText($phone,
                'No reconozco este número. Pide a tu administrador que lo vincule a tu cuenta.');
            return;
        }

        $conversation = WhatsappConversation::firstOrCreate(
            ['whatsapp_phone' => $phone],
            ['user_id' => $user->id, 'state' => 'idle']
        );

        $text = $message['text']['body'] ?? null;
        $buttonReplyId = $message['interactive']['button_reply']['id'] ?? null;

        match (true) {
            $buttonReplyId !== null => $this->handleButtonReply($conversation, $user, $buttonReplyId),
            $conversation->state === 'idle' => $this->showMainMenu($conversation, $user),
            default => $this->showMainMenu($conversation, $user), // fallback simple
        };
    }

    protected function showMainMenu(WhatsappConversation $conversation, User $user): void
    {
        $credentials = $this->access->accessibleCredentials($user);

        if ($credentials->isEmpty()) {
            $this->client->sendText($conversation->whatsapp_phone, 'No tienes documentos fiscales configurados todavía.');
            return;
        }

        if ($credentials->count() === 1) {
            $conversation->update([
                'state' => 'awaiting_document_choice',
                'context' => ['fiscal_credential_id' => $credentials->first()->id],
            ]);
            $this->sendDocumentTypeButtons($conversation);
            return;
        }

        // varias credenciales (owner/admin) -> primero elegir cuál
        $conversation->update(['state' => 'awaiting_credential_choice']);
        $buttons = $credentials->take(3)->map(fn ($c) => ['id' => "cred_{$c->id}", 'title' => $c->rfc])->all();
        $this->client->sendButtons($conversation->whatsapp_phone, '¿De qué RFC quieres el documento?', $buttons);
    }

    protected function sendDocumentTypeButtons(WhatsappConversation $conversation): void
    {
        $types = DocumentType::where('is_active', true)->orderBy('sort_order')->get();
        $buttons = $types->map(fn ($t) => ['id' => "doc_{$t->id}", 'title' => $t->name])->all();
        $this->client->sendButtons($conversation->whatsapp_phone, 'Elige el documento que necesitas:', $buttons);
    }

    protected function handleButtonReply(WhatsappConversation $conversation, User $user, string $buttonId): void
    {
        if (str_starts_with($buttonId, 'cred_')) {
            $credentialId = (int) str_replace('cred_', '', $buttonId);
            $conversation->update([
                'state' => 'awaiting_document_choice',
                'context' => ['fiscal_credential_id' => $credentialId],
            ]);
            $this->sendDocumentTypeButtons($conversation);
            return;
        }

        if (str_starts_with($buttonId, 'doc_')) {
            $documentTypeId = (int) str_replace('doc_', '', $buttonId);
            $credentialId = $conversation->context['fiscal_credential_id'] ?? null;
            $this->deliverDocument($conversation, $credentialId, $documentTypeId);
            $conversation->update(['state' => 'idle', 'context' => null]);
        }
    }

    protected function deliverDocument(WhatsappConversation $conversation, int $credentialId, int $documentTypeId): void
    {
        $document = Document::query()
            ->where('fiscal_credential_id', $credentialId)
            ->where('document_type_id', $documentTypeId)
            ->latestSuccessful()
            ->first();

        if (!$document) {
            $this->client->sendText($conversation->whatsapp_phone,
                'Aún no tengo ese documento generado. Lo estoy generando, te lo mando en unos minutos.');

            GenerateDocumentJob::dispatch($credentialId, $documentTypeId, now()->toDateString());
            return;
        }

        $link = Storage::disk('s3_private')->temporaryUrl($document->file_path, now()->addMinutes(10));
        $filename = $document->documentType->name . '.pdf';

        $this->client->sendDocumentByLink($conversation->whatsapp_phone, $link, $filename);
    }
}
```

> Esto es un esqueleto funcional pero simplificado — para producción conviene envolver `handleInboundMessage` en un `try/catch` que responda algo genérico y reporte el error, y mover el "generar bajo demanda" a que también notifique automáticamente al usuario cuando el job termine (ej. `GenerateDocumentJob` dispara un evento que el bot escucha para hacer `sendDocumentByLink` cuando el status pase a `success`).

---

## 6. Filament 5 (panel para `owner`/`administrator`)

Con `canAccessPanel()` ya restringido a esos dos roles (ver modelo `User`), en cada Resource aplica scoping por compañía:

```php
// app/Filament/Resources/DocumentResource.php
class DocumentResource extends Resource
{
    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();

        return parent::getEloquentQuery()
            ->whereHas('fiscalCredential', function (Builder $q) use ($user) {
                $q->where(fn ($q) => $q->where('owner_type', Company::class)->where('owner_id', $user->company_id))
                  ->orWhere(fn ($q) => $q->where('owner_type', User::class)
                        ->whereIn('owner_id', $user->company->users()->pluck('id')));
            });
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('fiscalCredential.rfc'),
            TextColumn::make('documentType.name'),
            BadgeColumn::make('status')->colors(['success' => 'success', 'danger' => 'failed', 'warning' => 'pending']),
            TextColumn::make('generated_for_date')->date(),
        ])->actions([
            Action::make('download')
                ->visible(fn (Document $r) => $r->status === 'success')
                ->url(fn (Document $r) => Storage::disk('s3_private')->temporaryUrl($r->file_path, now()->addMinutes(10)))
                ->openUrlInNewTab(),
        ]);
    }
}
```

Resources recomendados: `CompanyResource`, `UserResource` (con select de `role` y `company_id`, campo `whatsapp_phone`), `FiscalCredentialResource` (FileUpload apuntando al disco S3 privado para `.cer`/`.key`, campo password como `TextInput::make('password')->password()` mapeado al cast `encrypted`), `DocumentTypeResource` (para habilitar/deshabilitar tipos y ver `handler_class`), `DocumentResource` (solo lectura + descarga).

`FiscalCredentialResource` — importante: el campo password en el form NO debe mostrarse en texto plano al editar; usa `->dehydrateStateUsing()` para solo actualizarlo si se llenó, y nunca muestres el valor actual descifrado.

---

## 7. Seguridad (checklist)

- Disco S3 dedicado y **privado** (`s3_private`) sin ACL pública, separado del disco público de assets si tienes uno.
- Bucket con versionado + `PutObject`-only para el usuario IAM de la app en la ruta de FIEL; considera bucket policy que bloquee `s3:GetObject` público explícitamente.
- Cifrado en reposo: server-side encryption S3 (SSE-S3 o SSE-KMS) + el password ya cifrado a nivel aplicación con `encrypted` cast de Laravel (doble capa).
- Rotar `APP_KEY` es un evento peligroso: si cambias `APP_KEY` sin re-encriptar los `password_encrypted` existentes, quedan ilegibles. Documenta este procedimiento antes de que pase en producción.
- Verificar la firma `X-Hub-Signature-256` de cada webhook de Meta usando `app_secret` (middleware dedicado) — de lo contrario cualquiera puede pegarle a tu endpoint.
- Los archivos temporales del `.cer/.key` que bajas a disco local (sección 3.2) deben limpiarse siempre (usa `finally`), e idealmente ese `storage/app/tmp` no debe ser accesible por el servidor web.
- Rate-limit al webhook y usa colas — nunca proceses síncronamente una llamada a SatGo dentro del request del webhook (Meta espera respuesta <20s).
- Considera expirar/rotar los certificados FIEL vencidos: usa `fiel_expires_at` para que Filament marque credenciales por vencer y el job diario las salte con una alerta.

---

## 8. Cómo obtener las credenciales

### 8.1 SatGo
1. Regístrate y entra a `https://web.sat-go.com`.
2. Llama una sola vez a `POST /api/v1/users/CreateKey` (autenticado) para generar tu **API Key** permanente.
3. Guarda esa key en `.env` como `SATGO_API_KEY` — con ella tu backend obtiene un JWT nuevo cada vez que expira, vía `/api/auth/token-json` (ya implementado en `SatGoAuthService`).

### 8.2 WhatsApp (Meta Cloud API)
1. Crea una app en [developers.facebook.com](https://developers.facebook.com/apps) tipo "Business".
2. Agrega el producto **WhatsApp** a la app.
3. En modo pruebas obtienes un número de prueba + token temporal (24h) — úsalo solo para desarrollo.
4. Para producción: crea un **System User** en Meta Business Suite, asígnale el activo WhatsApp Business Account (WABA), y genera un **token permanente** con permisos `whatsapp_business_messaging` y `whatsapp_business_management`.
5. Anota el **Phone Number ID** y el **WABA ID** (los ves en la configuración de la app → WhatsApp → API Setup).
6. Configura el **Webhook**: URL pública (`https://tu-dominio.com/api/whatsapp/webhook`) + el **Verify Token** que tú definas (debe matchear `WHATSAPP_WEBHOOK_VERIFY_TOKEN`), suscríbete al campo `messages`.
7. Registra el número de teléfono de producción (portar un número existente o usar uno nuevo comprado vía Meta) y complétalo el proceso de verificación de negocio (Business Verification) — sin esto Meta limita el volumen de mensajes salientes.
8. Ten en cuenta la **ventana de 24 horas**: solo puedes responder libremente a un usuario si te escribió en las últimas 24h; para enviar el documento *de forma proactiva* (ej. avisar que ya está listo sin que el usuario haya escrito primero) necesitas una **plantilla de mensaje (template)** aprobada por Meta.

---

## 9. Próximos pasos sugeridos

1. Confirmar si el RFC vive a nivel `Company`, `User`, o ambos (afecta si de verdad necesitas el morph o puedes simplificar a `Company hasOne FiscalCredential`).
2. Decidir la política de expiración/retención de `Document` (¿borras los de hace 90 días de S3?).
3. Definir qué pasa si SatGo falla 3 veces seguidas para una credencial (¿alerta a Filament? ¿notificación al owner por WhatsApp?).
4. Confirmar el header `RFC` exacto que exige cada endpoint FIEL contra Swagger antes de codificar (`https://api.sat-go.com/swagger`), ya que no siempre aparece explícito en el doc público.

¿Quieres que continúe con el código completo de las migraciones + Filament resources como archivos listos para copiar a tu proyecto, o prefieres que primero resolvamos las preguntas del punto 9?
