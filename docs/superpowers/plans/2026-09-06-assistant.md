# Support Assistant Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A floating support assistant on every page that answers from a knowledge base, gathers an issue from signed-in people, checks for a duplicate, drafts a ticket they confirm, and attaches the transcript to the ticket; with admin screens for articles and conversations.

**Architecture:** Laravel AI SDK agents: a Concierge orchestrator with structured output (reply plus card) delegating to a Knowledge sub-agent and a Triage sub-agent, each with only its own tools. The model drafts, the person confirms, a plain action creates the ticket. React panel mounted once at the app root with an `assistant` slice mirrored to localStorage.

**Tech Stack:** Laravel 13, PHP 8.4, MySQL 8, `laravel/ai` 0.11, Anthropic `claude-haiku-4-5-20251001`. React 19, TypeScript, Redux Toolkit and RTK Query, DaisyUI 5, Vitest.

## Global Constraints

- Spec: `docs/superpowers/specs/2026-09-06-assistant-design.md`. Repos: `helpdesk-api` (this one) and `../helpdesk-web`.
- Provider Anthropic only. Model `claude-haiku-4-5-20251001` on every agent. `ASSISTANT_LIVE_TESTS=1` gates the single live test; every other test uses fakes.
- SDK tables `agent_conversations` and `agent_conversation_messages` are published once and `participant_id` becomes `string(36)`; nothing else in them changes.
- One action class per operation under `app/Actions`, `handle()` method, action log entry inside the transaction, notifications with `afterCommit()`.
- Errors keep the `{ error: { code, message, details? } }` shape. New codes: `ASSISTANT_UNAVAILABLE` (502), `CONVERSATION_CLOSED` (409).
- Copy: sentence case, no em dashes, no exclamation marks. Comments describe logic only, no emojis, no doc references.
- Git: never run git commands. Every commit step prints the command for the user to run.
- Role labels in the UI: User, Moderator, Admin. The User role has no `ticket:create` grant after Task 4.
- Run `vendor/bin/pint --dirty` before each API commit; `npm run typecheck && npx oxlint src` before each web commit.

---

## File map

API (`helpdesk-api`):

| Path | Responsibility |
|---|---|
| `config/ai.php` | Published SDK config: default provider anthropic, haiku model, `generate_title` off |
| `database/migrations/…create_agent_conversations_table.php` | Published SDK migration with string participant ids |
| `database/migrations/2026_09_07_000001_create_assistant_guests_table.php` | Guest identities |
| `database/migrations/2026_09_07_000002_create_knowledge_articles_table.php` | Articles with full-text index |
| `database/migrations/2026_09_07_000003_add_source_and_conversation_to_tickets_table.php` | `source`, `conversation_id` |
| `database/migrations/2026_09_07_000004_create_assistant_sessions_table.php` | Session tracking |
| `app/Models/AssistantGuest.php`, `AssistantSession.php`, `KnowledgeArticle.php` | Eloquent models |
| `app/Enums/AssistantOutcome.php`, `TicketSource.php`, `CardType.php` | Vocabulary |
| `app/Http/Middleware/AuthenticateIfPresent.php` | Bearer optional: authenticates when present |
| `app/Support/AssistantParticipant.php` | Resolves the caller to a user or a guest |
| `app/Ai/AssistantContext.php` | What the Concierge knows about the person |
| `app/Ai/Cards/CardValidator.php` | Validates the model's card, downgrades to none |
| `app/Ai/Tools/SearchKnowledge.php`, `FindOpenTickets.php`, `ListCategories.php` | Tools |
| `app/Ai/Agents/KnowledgeAgent.php`, `TriageAgent.php`, `Concierge.php` | Agents |
| `app/Actions/Knowledge/CreateArticle.php`, `UpdateArticle.php`, `SetArticleActive.php` | Article operations |
| `app/Actions/Assistant/StartConversation.php`, `SendMessage.php`, `RaiseTicketFromConversation.php`, `ClaimConversation.php`, `SettleSessions.php` | Conversation operations |
| `app/Http/Controllers/KnowledgeArticleController.php`, `AssistantConversationController.php` | HTTP |
| `app/Http/Requests/KnowledgeArticle{Store,Update,Index}Request.php`, `Assistant{Message,Ticket,ConversationIndex}Request.php` | Validation |
| `app/Http/Resources/KnowledgeArticleResource.php`, `AssistantSessionResource.php`, `AssistantMessageResource.php` | Shapes |
| `app/Console/Commands/SettleAssistantSessions.php` | Scheduled outcome settlement |
| `database/seeders/KnowledgeArticleSeeder.php` | 24 articles |
| `tests/Feature/Assistant/*`, `tests/Feature/Admin/KnowledgeArticlesTest.php` | Tests |

Web (`helpdesk-web`):

| Path | Responsibility |
|---|---|
| `src/api/assistant.api.ts`, `src/api/knowledge.api.ts` | Endpoints |
| `src/api/types.ts` | New types |
| `src/features/assistant/assistantSlice.ts`, `assistantStorage.ts` | State and localStorage mirror |
| `src/features/assistant/AssistantLauncher.tsx`, `AssistantPanel.tsx`, `MessageList.tsx`, `Composer.tsx` | Panel |
| `src/features/assistant/cards/TicketDraftCard.tsx`, `ExistingTicketCard.tsx`, `SignInCard.tsx`, `cardSchema.ts` | Cards |
| `src/features/assistant/useAssistant.ts` | The hook the panel and rail use |
| `src/features/admin/KnowledgePage.tsx`, `ConversationsPage.tsx`, `ConversationPage.tsx` | Admin screens |
| `src/features/tickets/TicketDetailPage.tsx` | Transcript block |

---

### Task 1: SDK install, config and conversation tables

**Files:**
- Modify: `composer.json` (already has `laravel/ai`), `.env`, `.env.example`
- Create: `config/ai.php` (published), `database/migrations/2026_01_11_000001_create_agent_conversations_table.php` (published, edited)
- Test: `tests/Feature/Assistant/ConversationTablesTest.php`

**Interfaces:**
- Produces: tables `agent_conversations`, `agent_conversation_messages` with `participant_id string(36)`; `config('ai.default') === 'anthropic'`.

- [ ] **Step 1: Publish config and migration**

```bash
php artisan vendor:publish --provider="Laravel\Ai\AiServiceProvider" --tag=ai-config
php artisan vendor:publish --provider="Laravel\Ai\AiServiceProvider" --tag=migrations
ls database/migrations | grep agent_conversations
```

- [ ] **Step 2: Edit the published migration so participants can be ULIDs**

In the published `create_agent_conversations_table.php`, replace both `$table->unsignedBigInteger('participant_id')->nullable();` lines with:

```php
            // Users and guests carry ULID keys, so the participant key is a string.
            $table->string('participant_id', 36)->nullable();
```

- [ ] **Step 3: Configure the provider**

In `config/ai.php` set:

```php
    'default' => env('AI_PROVIDER', 'anthropic'),
```

and inside `'providers' => ['anthropic' => [...]]` add:

```php
            'models' => [
                'text' => [
                    'default' => env('ANTHROPIC_MODEL', 'claude-haiku-4-5-20251001'),
                    'cheapest' => 'claude-haiku-4-5-20251001',
                    'smartest' => 'claude-haiku-4-5-20251001',
                ],
            ],
```

At the end of the config array add:

```php
    /*
    |--------------------------------------------------------------------------
    | Conversations
    |--------------------------------------------------------------------------
    */

    'conversations' => [
        'connection' => null,
        'tables' => [
            'conversations' => 'agent_conversations',
            'messages' => 'agent_conversation_messages',
        ],
        // A generated title costs a model call per conversation and nothing reads it.
        'generate_title' => false,
    ],
```

Append to `.env.example` and `.env`:

```ini
# The assistant. Anthropic is the only provider.
ANTHROPIC_API_KEY=
ANTHROPIC_MODEL=claude-haiku-4-5-20251001
ASSISTANT_LIVE_TESTS=0
```

- [ ] **Step 4: Write the failing test**

`tests/Feature/Assistant/ConversationTablesTest.php`:

```php
<?php

namespace Tests\Feature\Assistant;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class ConversationTablesTest extends TestCase
{
    use RefreshDatabase;

    public function test_conversation_tables_exist_with_string_participants(): void
    {
        $this->assertTrue(Schema::hasTable('agent_conversations'));
        $this->assertTrue(Schema::hasTable('agent_conversation_messages'));
        $this->assertSame('varchar', Schema::getColumnType('agent_conversations', 'participant_id'));
        $this->assertSame('anthropic', config('ai.default'));
        $this->assertFalse(config('ai.conversations.generate_title'));
    }
}
```

- [ ] **Step 5: Run migrations and the test**

```bash
php artisan migrate --force
php artisan test --compact tests/Feature/Assistant/ConversationTablesTest.php
```
Expected: PASS.

- [ ] **Step 6: Commit (user runs)**

```bash
git add composer.json composer.lock config/ai.php database/migrations/2026_01_11_000001_create_agent_conversations_table.php .env.example tests/Feature/Assistant/ConversationTablesTest.php && git commit -m "chore: install the Laravel AI SDK with Anthropic and string participant ids"
```

---

### Task 2: Guests and optional authentication

**Files:**
- Create: `database/migrations/2026_09_07_000001_create_assistant_guests_table.php`, `app/Models/AssistantGuest.php`, `app/Http/Middleware/AuthenticateIfPresent.php`, `app/Support/AssistantParticipant.php`
- Modify: `bootstrap/app.php` (middleware alias), `app/Providers/AppServiceProvider.php` (morph map)
- Test: `tests/Feature/Assistant/ParticipantTest.php`

**Interfaces:**
- Produces: `AssistantParticipant::resolve(Request $request): User|AssistantGuest`, header `X-Assistant-Guest`, middleware alias `auth.optional`, morph alias `assistant_guest`.

- [ ] **Step 1: Migration and model**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_guests', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->dateTime('last_seen_at', 3);
            $table->datetimes(3);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_guests');
    }
};
```

`app/Models/AssistantGuest.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An anonymous person talking to the assistant. Identified only by the id the
 * browser keeps; nothing about them is stored.
 *
 * @property string $id
 * @property Carbon $last_seen_at
 */
class AssistantGuest extends Model
{
    use Concerns\HasMillisecondTimestamps, HasUlids;

    protected $fillable = ['last_seen_at'];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime', 'created_at' => 'datetime', 'updated_at' => 'datetime'];
    }
}
```

Add `'assistant_guest' => AssistantGuest::class,` to the morph map in `AppServiceProvider` (with the import).

- [ ] **Step 2: Optional auth middleware**

`app/Http/Middleware/AuthenticateIfPresent.php`:

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Routes a guest may call. A bearer token, when sent, must be valid: a
 * signed-in person with a stale token should be told so, not treated as a
 * guest and quietly given a fresh anonymous conversation.
 */
final class AuthenticateIfPresent
{
    public function __construct(private readonly Authenticate $authenticate) {}

    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();

        if ($bearer === null || $bearer === '') {
            return $next($request);
        }

        return $this->authenticate->handle($request, $next);
    }
}
```

Register in `bootstrap/app.php` middleware aliases: `'auth.optional' => AuthenticateIfPresent::class,` (import it).

- [ ] **Step 3: Participant resolver**

`app/Support/AssistantParticipant.php`:

```php
<?php

namespace App\Support;

use App\Authorization\Actor;
use App\Models\AssistantGuest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Who is talking to the assistant. A signed-in person wins; otherwise the
 * guest named by the header, created on first sight. An unknown guest id is
 * replaced rather than trusted, so a forged id cannot reach another guest's
 * conversation.
 */
final class AssistantParticipant
{
    public const HEADER = 'X-Assistant-Guest';

    public function resolve(Request $request): User|AssistantGuest
    {
        if (app()->bound(Actor::class)) {
            return app(Actor::class)->user;
        }

        $id = (string) $request->header(self::HEADER, '');

        $guest = Str::isUlid($id) ? AssistantGuest::find($id) : null;

        if ($guest === null) {
            return AssistantGuest::create(['last_seen_at' => now()]);
        }

        $guest->forceFill(['last_seen_at' => now()])->save();

        return $guest;
    }
}
```

- [ ] **Step 4: Write the failing test**

`tests/Feature/Assistant/ParticipantTest.php`:

```php
<?php

namespace Tests\Feature\Assistant;

use App\Models\AssistantGuest;
use App\Models\User;
use App\Support\AssistantParticipant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class ParticipantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAuthorization();

        Route::middleware(['api', 'auth.optional'])->get('/api/_participant', function (Request $request) {
            $participant = app(AssistantParticipant::class)->resolve($request);

            return ['type' => $participant->getMorphClass(), 'id' => $participant->getKey()];
        });
    }

    public function test_a_signed_in_person_is_the_participant(): void
    {
        $user = User::factory()->create(['email' => 'p@example.test']);
        $token = $this->login('p@example.test')['token'];

        $this->asUser($token)->getJson('/api/_participant')
            ->assertOk()->assertJson(['type' => 'user', 'id' => $user->id]);
    }

    public function test_a_guest_is_created_then_recognised(): void
    {
        $first = $this->asGuest()->getJson('/api/_participant')->assertOk()->json();
        $this->assertSame('assistant_guest', $first['type']);

        $second = $this->asGuest()->withHeader(AssistantParticipant::HEADER, $first['id'])->getJson('/api/_participant')->json();
        $this->assertSame($first['id'], $second['id']);
        $this->assertSame(1, AssistantGuest::count());
    }

    public function test_a_forged_guest_id_is_replaced(): void
    {
        $this->asGuest()->withHeader(AssistantParticipant::HEADER, '01ARZ3NDEKTSV4RRFFQ69G5FAV')->getJson('/api/_participant')
            ->assertOk()->assertJsonMissing(['id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV']);
    }

    public function test_a_stale_token_is_refused_rather_than_treated_as_a_guest(): void
    {
        User::factory()->create(['email' => 's@example.test']);
        $token = $this->login('s@example.test')['token'];
        User::where('email', 's@example.test')->increment('token_version');

        $this->asUser($token)->getJson('/api/_participant')->assertUnauthorized();
    }
}
```

- [ ] **Step 5: Run**

```bash
php artisan migrate --force && php artisan test --compact tests/Feature/Assistant/ParticipantTest.php
```
Expected: PASS (4 tests).

- [ ] **Step 6: Commit (user runs)**

```bash
git add database/migrations/2026_09_07_000001_create_assistant_guests_table.php app/Models/AssistantGuest.php app/Http/Middleware/AuthenticateIfPresent.php app/Support/AssistantParticipant.php bootstrap/app.php app/Providers/AppServiceProvider.php tests/Feature/Assistant/ParticipantTest.php && git commit -m "feat: assistant guests and optional authentication"
```

---

### Task 3: Knowledge articles

**Files:**
- Create: migration `2026_09_07_000002_create_knowledge_articles_table.php`, `app/Models/KnowledgeArticle.php`, `app/Actions/Knowledge/CreateArticle.php`, `UpdateArticle.php`, `SetArticleActive.php`, `app/Http/Controllers/KnowledgeArticleController.php`, `app/Http/Requests/KnowledgeArticleStoreRequest.php`, `KnowledgeArticleUpdateRequest.php`, `KnowledgeArticleIndexRequest.php`, `app/Http/Resources/KnowledgeArticleResource.php`, `database/seeders/KnowledgeArticleSeeder.php`, `database/factories/KnowledgeArticleFactory.php`
- Modify: `app/Enums/PermissionSlug.php`, `app/Enums/ActionType.php`, `app/Authorization/PermissionMatrix.php`, `routes/api.php`, `database/seeders/DatabaseSeeder.php`, `tests/Feature/RoleAccessSweepTest.php`
- Test: `tests/Feature/Admin/KnowledgeArticlesTest.php`

**Interfaces:**
- Produces: `KnowledgeArticle` model with `scopeActive()` and `scopeSearch(string $query)`; permission `knowledge:manage` (Admin); routes `GET/POST /knowledge`, `PATCH /knowledge/{article}`; `KnowledgeArticleResource` shape `{ id, title, body, keywords, category: {id, name} | null, isActive, updatedAt }`.

- [ ] **Step 1: Enum cases and matrix**

`PermissionSlug`: add `case KnowledgeManage = 'knowledge:manage';`. `ActionType`: add

```php
    case ArticleCreated = 'knowledge.created';
    case ArticleUpdated = 'knowledge.updated';
    case ArticleRetired = 'knowledge.retired';
    case ArticleRestored = 'knowledge.restored';
```

`PermissionMatrix::catalogue()`: add `PermissionSlug::KnowledgeManage->value => 'Write and retire knowledge articles',`. `grants()` admin: add `PermissionSlug::KnowledgeManage->value => PermissionScope::All,`.

- [ ] **Step 2: Migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_articles', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('title', 120);
            $table->text('body');
            // Comma separated words the title and body might not contain, such as "vpn, remote".
            $table->string('keywords', 255)->default('');
            $table->foreignUlid('category_id')->nullable()->constrained('categories')->nullOnDelete()->cascadeOnUpdate();
            $table->boolean('is_active')->default(true);
            $table->datetimes(3);

            $table->index(['is_active', 'title'], 'idx_articles_active_title');
            $table->fullText(['title', 'body', 'keywords'], 'ft_articles_search');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_articles');
    }
};
```

- [ ] **Step 3: Model and factory**

`app/Models/KnowledgeArticle.php`:

```php
<?php

namespace App\Models;

use Database\Factories\KnowledgeArticleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $title
 * @property string $body
 * @property string $keywords
 * @property string|null $category_id
 * @property bool $is_active
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class KnowledgeArticle extends Model
{
    /** @use HasFactory<KnowledgeArticleFactory> */
    use Concerns\HasMillisecondTimestamps, HasFactory, HasUlids;

    protected $fillable = ['title', 'body', 'keywords', 'category_id', 'is_active'];

    protected $attributes = ['is_active' => true, 'keywords' => ''];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'created_at' => 'datetime', 'updated_at' => 'datetime'];
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @param  Builder<static>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Natural language full-text match, best first. Falls back to a LIKE on
     * the title for one-word queries that full text treats as stop words.
     *
     * @param  Builder<static>  $query
     */
    public function scopeSearch(Builder $query, string $term): void
    {
        $term = trim($term);

        if (mb_strlen($term) < 4) {
            $query->where('title', 'like', '%'.addcslashes($term, '%_\\').'%');

            return;
        }

        $query->whereFullText(['title', 'body', 'keywords'], $term)
            ->orderByRaw('MATCH(title, body, keywords) AGAINST (?) DESC', [$term]);
    }
}
```

`database/factories/KnowledgeArticleFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\KnowledgeArticle;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<KnowledgeArticle> */
class KnowledgeArticleFactory extends Factory
{
    protected $model = KnowledgeArticle::class;

    public function definition(): array
    {
        return [
            'title' => fake()->sentence(4),
            'body' => fake()->paragraphs(2, true),
            'keywords' => implode(', ', fake()->words(3)),
            'category_id' => null,
            'is_active' => true,
        ];
    }

    public function retired(): static
    {
        return $this->state(['is_active' => false]);
    }
}
```

- [ ] **Step 4: Actions**

`app/Actions/Knowledge/CreateArticle.php`:

```php
<?php

namespace App\Actions\Knowledge;

use App\Actions\Concerns\RecordsActions;
use App\Authorization\Actor;
use App\Enums\ActionType;
use App\Enums\PermissionSlug;
use App\Models\KnowledgeArticle;
use Illuminate\Support\Facades\DB;

final class CreateArticle
{
    use RecordsActions;

    public function handle(Actor $actor, string $title, string $body, string $keywords, ?string $categoryId): KnowledgeArticle
    {
        $actor->authorize(PermissionSlug::KnowledgeManage);

        return DB::transaction(function () use ($actor, $title, $body, $keywords, $categoryId): KnowledgeArticle {
            $article = KnowledgeArticle::create([
                'title' => $title,
                'body' => $body,
                'keywords' => $keywords,
                'category_id' => $categoryId,
            ]);

            $this->record(ActionType::ArticleCreated, $actor->user, $article, ['title' => $title]);

            return $article;
        });
    }
}
```

`app/Actions/Knowledge/UpdateArticle.php`:

```php
<?php

namespace App\Actions\Knowledge;

use App\Actions\Concerns\RecordsActions;
use App\Authorization\Actor;
use App\Enums\ActionType;
use App\Enums\PermissionSlug;
use App\Models\KnowledgeArticle;
use Illuminate\Support\Facades\DB;

final class UpdateArticle
{
    use RecordsActions;

    /** @param  array{title?: string, body?: string, keywords?: string, category_id?: string|null}  $changes */
    public function handle(Actor $actor, KnowledgeArticle $article, array $changes): KnowledgeArticle
    {
        $actor->authorize(PermissionSlug::KnowledgeManage);

        $changes = array_filter($changes, fn ($value, $key) => $article->{$key} !== $value, ARRAY_FILTER_USE_BOTH);

        if ($changes === []) {
            return $article;
        }

        return DB::transaction(function () use ($actor, $article, $changes): KnowledgeArticle {
            $article->update($changes);

            $this->record(ActionType::ArticleUpdated, $actor->user, $article, ['fields' => array_keys($changes)]);

            return $article;
        });
    }
}
```

`app/Actions/Knowledge/SetArticleActive.php`:

```php
<?php

namespace App\Actions\Knowledge;

use App\Actions\Concerns\RecordsActions;
use App\Authorization\Actor;
use App\Enums\ActionType;
use App\Enums\PermissionSlug;
use App\Models\KnowledgeArticle;
use Illuminate\Support\Facades\DB;

/** Retired articles stop answering questions but keep their history. */
final class SetArticleActive
{
    use RecordsActions;

    public function handle(Actor $actor, KnowledgeArticle $article, bool $active): KnowledgeArticle
    {
        $actor->authorize(PermissionSlug::KnowledgeManage);

        if ($article->is_active === $active) {
            return $article;
        }

        return DB::transaction(function () use ($actor, $article, $active): KnowledgeArticle {
            $article->update(['is_active' => $active]);

            $this->record($active ? ActionType::ArticleRestored : ActionType::ArticleRetired, $actor->user, $article, ['title' => $article->title]);

            return $article;
        });
    }
}
```

- [ ] **Step 5: Requests, resource, controller, routes**

`KnowledgeArticleStoreRequest`:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class KnowledgeArticleStoreRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:5', 'max:120'],
            'body' => ['required', 'string', 'min:20', 'max:20000'],
            'keywords' => ['sometimes', 'string', 'max:255'],
            'categoryId' => ['sometimes', 'nullable', 'string', Rule::exists('categories', 'id')],
        ];
    }
}
```

`KnowledgeArticleUpdateRequest`: same rules with `sometimes` instead of `required` on title and body, plus `'isActive' => ['sometimes', 'boolean']`, and the same `prepareForValidation` boolean coercion as `UserUpdateRequest`.

`KnowledgeArticleIndexRequest`:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class KnowledgeArticleIndexRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search' => ['sometimes', 'string', 'max:120'],
            'includeRetired' => ['sometimes', 'boolean'],
        ];
    }
}
```

`KnowledgeArticleResource`:

```php
<?php

namespace App\Http\Resources;

use App\Models\KnowledgeArticle;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin KnowledgeArticle */
final class KnowledgeArticleResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'body' => $this->body,
            'keywords' => $this->keywords,
            'category' => $this->category === null ? null : ['id' => $this->category->id, 'name' => $this->category->name],
            'isActive' => $this->is_active,
            'updatedAt' => $this->updated_at->toIso8601String(),
        ];
    }
}
```

`KnowledgeArticleController`:

```php
<?php

namespace App\Http\Controllers;

use App\Actions\Knowledge\CreateArticle;
use App\Actions\Knowledge\SetArticleActive;
use App\Actions\Knowledge\UpdateArticle;
use App\Authorization\Actor;
use App\Enums\PermissionSlug;
use App\Http\Requests\KnowledgeArticleIndexRequest;
use App\Http\Requests\KnowledgeArticleStoreRequest;
use App\Http\Requests\KnowledgeArticleUpdateRequest;
use App\Http\Resources\KnowledgeArticleResource;
use App\Models\KnowledgeArticle;
use Illuminate\Http\JsonResponse;

final class KnowledgeArticleController extends Controller
{
    public function index(KnowledgeArticleIndexRequest $request, Actor $actor): JsonResponse
    {
        $actor->authorize(PermissionSlug::KnowledgeManage);

        $page = KnowledgeArticle::query()
            ->with('category:id,name')
            ->when(! $request->boolean('includeRetired'), fn ($query) => $query->active())
            ->when($request->filled('search'), fn ($query) => $query->search((string) $request->query('search')))
            ->when(! $request->filled('search'), fn ($query) => $query->orderBy('title'))
            ->paginate(perPage: $request->integer('limit', 20), page: $request->integer('page', 1));

        return response()->json([
            'data' => KnowledgeArticleResource::collection($page->items()),
            'pagination' => [
                'page' => $page->currentPage(),
                'limit' => $page->perPage(),
                'totalItems' => $page->total(),
                'totalPages' => $page->lastPage(),
            ],
        ]);
    }

    public function store(KnowledgeArticleStoreRequest $request, Actor $actor, CreateArticle $create): JsonResponse
    {
        $article = $create->handle(
            $actor,
            $request->string('title')->toString(),
            $request->string('body')->toString(),
            $request->string('keywords')->toString(),
            $request->filled('categoryId') ? $request->string('categoryId')->toString() : null,
        );

        return response()->json(['data' => new KnowledgeArticleResource($article->load('category:id,name'))], 201);
    }

    public function update(
        KnowledgeArticleUpdateRequest $request,
        Actor $actor,
        KnowledgeArticle $article,
        UpdateArticle $update,
        SetArticleActive $setActive,
    ): JsonResponse {
        $changes = [];
        foreach (['title', 'body', 'keywords'] as $field) {
            if ($request->has($field)) {
                $changes[$field] = $request->string($field)->toString();
            }
        }
        if ($request->has('categoryId')) {
            $changes['category_id'] = $request->filled('categoryId') ? $request->string('categoryId')->toString() : null;
        }

        if ($changes !== []) {
            $update->handle($actor, $article, $changes);
        }

        if ($request->has('isActive')) {
            $setActive->handle($actor, $article, $request->boolean('isActive'));
        }

        return response()->json(['data' => new KnowledgeArticleResource($article->fresh()->load('category:id,name'))]);
    }
}
```

Routes (inside the `auth.jwt` group in `routes/api.php`):

```php
    Route::get('/knowledge', [KnowledgeArticleController::class, 'index']);
    Route::post('/knowledge', [KnowledgeArticleController::class, 'store']);
    Route::patch('/knowledge/{article}', [KnowledgeArticleController::class, 'update']);
```

- [ ] **Step 6: Seeder**

`database/seeders/KnowledgeArticleSeeder.php` seeds 24 articles with `updateOrCreate(['title' => ...])`. Eight per seeded category slug (`it-hardware`, `it-access-vpn`, `hr-payroll`) and none for general. Titles and bodies must be real, plain guidance. The first six as written; write the rest in the same voice:

```php
<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\KnowledgeArticle;
use Illuminate\Database\Seeder;

class KnowledgeArticleSeeder extends Seeder
{
    /** @var list<array{title: string, body: string, keywords: string, category: string|null}> */
    private const ARTICLES = [
        ['category' => null, 'title' => 'How the helpdesk works', 'keywords' => 'ticket, status, moderator, how it works',
            'body' => "Raise a ticket by describing the problem to the assistant. A moderator picks it up from the shared queue, replies on the ticket, and moves it through open, in progress and resolved. You get an email whenever the status changes or someone picks it up. Closed tickets take no further replies."],
        ['category' => null, 'title' => 'What the priorities mean', 'keywords' => 'priority, urgent, high, medium, low, deadline',
            'body' => "Urgent means you cannot work at all or there is a security risk: the target is a first response within 4 hours. High means you are blocked on a deadline: 24 hours. Medium means something is degraded but you can work: 48 hours. Low is cosmetic or a question: 72 hours. The assistant sets the priority from what you describe; a moderator can change it."],
        ['category' => null, 'title' => 'Support hours and reaching a person', 'keywords' => 'hours, human, contact, phone',
            'body' => "The desk is staffed Sunday to Thursday, 8:00 to 17:00. Outside those hours tickets are still accepted and picked up on the next working day. If you want a person rather than the assistant, ask it to raise a ticket and a moderator will reply on it."],
        ['category' => 'it-access-vpn', 'title' => 'VPN will not connect from home', 'keywords' => 'vpn, remote, connect, home, tunnel',
            'body' => "Check that you are on a normal home connection, not a hotel or public network that blocks VPN. Quit the VPN client fully and open it again. If it asks for a second factor, approve it within 30 seconds. If it still fails with an authentication error your password may have expired: sign in to the intranet in a browser first. If it fails with a timeout, restart your router. If none of that helps, raise a ticket and say which error you see."],
        ['category' => 'it-access-vpn', 'title' => 'Locked out after too many password attempts', 'keywords' => 'locked, lockout, password, attempts',
            'body' => "Accounts lock for 15 minutes after five wrong attempts. Wait 15 minutes and try once more with care. If you have forgotten the password, use the Forgotten it link on the sign-in page to get a reset link by email. If you no longer have access to that email, raise a ticket."],
        ['category' => 'it-hardware', 'title' => 'Laptop fan running loudly', 'keywords' => 'fan, noise, hot, overheating, laptop',
            'body' => "A loud fan usually means something is using the processor. Close applications you are not using, especially browsers with many tabs and video calls. Restart the laptop once a day. Keep the vents clear: do not use it on a bed or sofa. If it is loud when idle for more than a day, raise a ticket so the hardware can be checked."],
    ];

    public function run(): void
    {
        $categories = Category::pluck('id', 'slug');

        foreach (self::ARTICLES as $article) {
            KnowledgeArticle::updateOrCreate(
                ['title' => $article['title']],
                [
                    'body' => $article['body'],
                    'keywords' => $article['keywords'],
                    'category_id' => $article['category'] === null ? null : $categories[$article['category']],
                ],
            );
        }
    }
}
```

Add 18 more entries so each category has eight (payroll dates, payslip missing, leave balance wrong, expense claims, new laptop request, docking station, monitor not detected, printer, MFA reset, shared drive access, new starter account, and so on). Register the seeder in `DatabaseSeeder` after `CategorySeeder`.

- [ ] **Step 7: Tests**

`tests/Feature/Admin/KnowledgeArticlesTest.php`:

```php
<?php

namespace Tests\Feature\Admin;

use App\Models\KnowledgeArticle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class KnowledgeArticlesTest extends TestCase
{
    use RefreshDatabase;

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAuthorization();
        User::factory()->admin()->create(['email' => 'admin@example.test']);
        $this->adminToken = $this->login('admin@example.test')['token'];
    }

    public function test_articles_are_created_updated_and_retired(): void
    {
        $created = $this->asUser($this->adminToken)->postJson('/api/knowledge', [
            'title' => 'VPN will not connect', 'body' => 'Quit the client and open it again. Then approve the second factor.', 'keywords' => 'vpn, remote',
        ])->assertCreated()->json('data');

        $this->asUser($this->adminToken)->patchJson("/api/knowledge/{$created['id']}", ['title' => 'VPN cannot connect'])
            ->assertOk()->assertJsonPath('data.title', 'VPN cannot connect');

        $this->asUser($this->adminToken)->patchJson("/api/knowledge/{$created['id']}", ['isActive' => false])
            ->assertOk()->assertJsonPath('data.isActive', false);

        $this->asUser($this->adminToken)->getJson('/api/knowledge')->assertOk()->assertJsonCount(0, 'data');
        $this->asUser($this->adminToken)->getJson('/api/knowledge?includeRetired=1')->assertOk()->assertJsonCount(1, 'data');

        $this->assertDatabaseHas('action_logs', ['action' => 'knowledge.retired', 'subject_id' => $created['id']]);
    }

    public function test_search_ranks_matching_articles_and_skips_retired_ones(): void
    {
        KnowledgeArticle::factory()->create(['title' => 'VPN will not connect from home', 'body' => 'Restart the VPN client.', 'keywords' => 'vpn']);
        KnowledgeArticle::factory()->create(['title' => 'Payslip missing', 'body' => 'Payslips arrive on the 25th.', 'keywords' => 'payroll']);
        KnowledgeArticle::factory()->retired()->create(['title' => 'Old VPN guide', 'body' => 'VPN VPN VPN.', 'keywords' => 'vpn']);

        $titles = KnowledgeArticle::active()->search('vpn connect')->pluck('title')->all();

        $this->assertSame(['VPN will not connect from home'], $titles);
    }

    public function test_validation_errors_come_back_per_field(): void
    {
        $this->asUser($this->adminToken)->postJson('/api/knowledge', ['title' => 'x', 'body' => 'short'])
            ->assertStatus(400)->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonPath('error.details.0.field', 'title');
    }
}
```

Add to the sweep matrix in `RoleAccessSweepTest`:

```php
            'GET /knowledge' => [
                'request' => fn () => ['GET', '/api/knowledge', []],
                'expect' => ['anonymous' => 401, 'user' => 403, 'moderator' => 403, 'admin' => 200],
            ],
            'POST /knowledge' => [
                'request' => fn (string $role) => ['POST', '/api/knowledge', ['title' => "Article for {$role}", 'body' => 'A body that is long enough to pass validation.']],
                'expect' => ['anonymous' => 401, 'user' => 403, 'moderator' => 403, 'admin' => 201],
            ],
            'PATCH /knowledge/{id}' => [
                'request' => fn () => ['PATCH', '/api/knowledge/'.KnowledgeArticle::factory()->create()->id, ['title' => 'Renamed article']],
                'expect' => ['anonymous' => 401, 'user' => 403, 'moderator' => 403, 'admin' => 200],
            ],
```

Note: the full-text test needs MySQL, which the suite already uses. MySQL full-text on InnoDB is visible immediately after insert in the same connection.

- [ ] **Step 8: Run**

```bash
php artisan migrate --force && php artisan db:seed --class=PermissionSeeder --force && php artisan db:seed --class=RolePermissionSeeder --force && php artisan db:seed --class=KnowledgeArticleSeeder --force
php artisan test --compact tests/Feature/Admin/KnowledgeArticlesTest.php tests/Feature/RoleAccessSweepTest.php tests/Feature/PermissionReconciliationTest.php
```
Expected: PASS.

- [ ] **Step 9: Commit (user runs)**

```bash
git add app/Enums app/Authorization/PermissionMatrix.php app/Models/KnowledgeArticle.php app/Actions/Knowledge app/Http/Controllers/KnowledgeArticleController.php app/Http/Requests/KnowledgeArticle* app/Http/Resources/KnowledgeArticleResource.php database/migrations/2026_09_07_000002_create_knowledge_articles_table.php database/factories/KnowledgeArticleFactory.php database/seeders routes/api.php tests/Feature/Admin/KnowledgeArticlesTest.php tests/Feature/RoleAccessSweepTest.php && git commit -m "feat: knowledge articles managed by admins"
```

---

### Task 4: Ticket source, conversation link, and the User role loses direct creation

**Files:**
- Create: migration `2026_09_07_000003_add_source_and_conversation_to_tickets_table.php`, `app/Enums/TicketSource.php`
- Modify: `app/Models/Ticket.php`, `app/Actions/Tickets/CreateTicket.php`, `app/Authorization/PermissionMatrix.php`, `app/Http/Resources/TicketResource.php`, `tests/Feature/RoleAccessSweepTest.php`, `tests/Feature/Tickets/TicketWriteTest.php`
- Test: `tests/Feature/Tickets/TicketSourceTest.php`

**Interfaces:**
- Produces: `CreateTicket::handle(Actor, subject, description, categoryId, priority)` unchanged for moderators and admins; new `CreateTicket::createFor(User $requester, string $subject, string $description, string $categoryId, TicketPriority $priority, TicketSource $source, ?string $conversationId): Ticket` with no permission check, used by Task 8. `TicketResource` gains `source`.

- [ ] **Step 1: Enum and migration**

```php
<?php

namespace App\Enums;

enum TicketSource: string
{
    case Direct = 'direct';
    case Assistant = 'assistant';
}
```

Migration:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->enum('source', ['direct', 'assistant'])->default('direct')->after('priority');
            // The assistant conversation the ticket came out of. Kept if the ticket is deleted.
            $table->string('conversation_id', 36)->nullable()->after('source');
            $table->index('conversation_id', 'idx_tickets_conversation');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropIndex('idx_tickets_conversation');
            $table->dropColumn(['source', 'conversation_id']);
        });
    }
};
```

`Ticket` model: add `'source', 'conversation_id'` to `$fillable`, `'source' => TicketSource::class` to casts, `@property TicketSource $source` and `@property string|null $conversation_id`, and `$attributes` default `'source' => 'direct'`.

- [ ] **Step 2: Split CreateTicket**

Replace the body of `CreateTicket`:

```php
    public function handle(
        Actor $actor,
        string $subject,
        string $description,
        string $categoryId,
        TicketPriority $priority = TicketPriority::Medium,
    ): Ticket {
        $actor->authorize(PermissionSlug::TicketCreate);

        return $this->createFor($actor->user, $subject, $description, $categoryId, $priority, TicketSource::Direct, null);
    }

    /**
     * The creation itself, with no permission check. The assistant path calls
     * this after proving the conversation belongs to the requester, which is
     * a different rule from holding a grant.
     */
    public function createFor(
        User $requester,
        string $subject,
        string $description,
        string $categoryId,
        TicketPriority $priority,
        TicketSource $source,
        ?string $conversationId,
    ): Ticket {
        return DB::transaction(function () use ($requester, $subject, $description, $categoryId, $priority, $source, $conversationId): Ticket {
            $now = now();

            $ticket = Ticket::create([
                'subject' => $subject,
                'description' => $description,
                'status' => TicketStatus::Open,
                'priority' => $priority,
                'source' => $source,
                'conversation_id' => $conversationId,
                'category_id' => $categoryId,
                'requester_id' => $requester->getKey(),
                'due_at' => $now->copy()->addHours($priority->slaHours()),
            ]);

            $this->record(ActionType::TicketCreated, $requester, $ticket, [
                'priority' => $priority->value,
                'category_id' => $categoryId,
                'source' => $source->value,
            ]);

            return $ticket;
        });
    }
```

Add `use App\Enums\TicketSource; use App\Models\User;`.

- [ ] **Step 3: Matrix and resource**

In `PermissionMatrix::grants()` remove `PermissionSlug::TicketCreate->value => PermissionScope::All,` from the `RoleSlug::User` entry only. Add a comment above the user block:

```php
            // No ticket:create. A user raises tickets through the assistant,
            // which checks conversation ownership rather than a grant.
```

In `TicketResource::toArray()` add `'source' => $this->source->value,` after `priority`.

- [ ] **Step 4: Tests**

`tests/Feature/Tickets/TicketSourceTest.php`:

```php
<?php

namespace Tests\Feature\Tickets;

use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class TicketSourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAuthorization();
    }

    public function test_a_user_can_no_longer_raise_a_ticket_directly(): void
    {
        User::factory()->create(['email' => 'u@example.test']);
        $token = $this->login('u@example.test')['token'];

        $this->asUser($token)->postJson('/api/tickets', [
            'subject' => 'A subject long enough', 'description' => 'A description that is long enough.', 'categoryId' => Category::factory()->create()->id,
        ])->assertForbidden();
    }

    public function test_a_moderator_ticket_is_marked_direct(): void
    {
        User::factory()->moderator()->create(['email' => 'm@example.test']);
        $token = $this->login('m@example.test')['token'];

        $this->asUser($token)->postJson('/api/tickets', [
            'subject' => 'A subject long enough', 'description' => 'A description that is long enough.', 'categoryId' => Category::factory()->create()->id,
        ])->assertCreated()->assertJsonPath('data.source', 'direct');
    }
}
```

Update the sweep: `POST /tickets` expects `'user' => 403`. Any existing test in `TicketWriteTest` that creates as the user role must create as a moderator instead, or use `Ticket::factory()`; read that file and adjust each affected case, keeping its intent.

- [ ] **Step 5: Run**

```bash
php artisan migrate --force && php artisan db:seed --class=RolePermissionSeeder --force
php artisan test --compact
```
Expected: PASS for the whole suite.

- [ ] **Step 6: Commit (user runs)**

```bash
git add app/Enums/TicketSource.php app/Models/Ticket.php app/Actions/Tickets/CreateTicket.php app/Authorization/PermissionMatrix.php app/Http/Resources/TicketResource.php database/migrations/2026_09_07_000003_add_source_and_conversation_to_tickets_table.php tests && git commit -m "feat: ticket source and conversation link; users raise tickets through the assistant only"
```

---

### Task 5: Sessions, context and tools

**Files:**
- Create: migration `2026_09_07_000004_create_assistant_sessions_table.php`, `app/Enums/AssistantOutcome.php`, `app/Enums/CardType.php`, `app/Models/AssistantSession.php`, `app/Ai/AssistantContext.php`, `app/Ai/Tools/SearchKnowledge.php`, `FindOpenTickets.php`, `ListCategories.php`
- Test: `tests/Feature/Assistant/ToolsTest.php`

**Interfaces:**
- Produces: `AssistantSession` with `participant()` morph, `scopeFor(User|AssistantGuest)`, `isOpen()`; `AssistantContext::for(User|AssistantGuest $participant): self` with `isGuest(): bool`, `user(): ?User`, `openTicketCount`, `categories` (array of id, name); tools implementing `Laravel\Ai\Contracts\Tool` returning plain text.

- [ ] **Step 1: Enums**

```php
<?php

namespace App\Enums;

enum AssistantOutcome: string
{
    case Open = 'open';
    case Answered = 'answered';
    case TicketRaised = 'ticket_raised';
    case Abandoned = 'abandoned';
}
```

```php
<?php

namespace App\Enums;

/** What the assistant can show beside its reply. */
enum CardType: string
{
    case None = 'none';
    case SignInRequired = 'sign_in_required';
    case ExistingTicket = 'existing_ticket';
    case TicketDraft = 'ticket_draft';
}
```

- [ ] **Step 2: Migration and model**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_sessions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('conversation_id', 36)->unique();
            $table->string('participant_type');
            $table->string('participant_id', 36);
            $table->enum('outcome', ['open', 'answered', 'ticket_raised', 'abandoned'])->default('open');
            $table->foreignUlid('ticket_id')->nullable()->constrained('tickets')->nullOnDelete()->cascadeOnUpdate();
            $table->unsignedInteger('turns')->default(0);
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->string('last_agent', 40)->nullable();
            $table->json('last_draft')->nullable();
            $table->dateTime('last_activity_at', 3);
            $table->datetimes(3);

            $table->index(['participant_type', 'participant_id', 'last_activity_at'], 'idx_sessions_participant');
            $table->index(['outcome', 'last_activity_at'], 'idx_sessions_outcome');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_sessions');
    }
};
```

`app/Models/AssistantSession.php`:

```php
<?php

namespace App\Models;

use App\Enums\AssistantOutcome;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Laravel\Ai\Models\Conversation;

/**
 * What the helpdesk tracks about one conversation. The words are in the SDK's
 * tables; this row is the outcome, the cost and who it was with.
 *
 * @property string $id
 * @property string $conversation_id
 * @property string $participant_type
 * @property string $participant_id
 * @property AssistantOutcome $outcome
 * @property string|null $ticket_id
 * @property int $turns
 * @property int $input_tokens
 * @property int $output_tokens
 * @property string|null $last_agent
 * @property array<string, mixed>|null $last_draft
 * @property Carbon $last_activity_at
 */
class AssistantSession extends Model
{
    use Concerns\HasMillisecondTimestamps, HasUlids;

    protected $fillable = ['conversation_id', 'participant_type', 'participant_id', 'outcome', 'ticket_id', 'turns', 'input_tokens', 'output_tokens', 'last_agent', 'last_draft', 'last_activity_at'];

    protected function casts(): array
    {
        return [
            'outcome' => AssistantOutcome::class,
            'last_draft' => 'array',
            'last_activity_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function participant(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class)->withTrashed();
    }

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }

    /** @param  Builder<static>  $query */
    public function scopeFor(Builder $query, User|AssistantGuest $participant): void
    {
        $query->where('participant_type', $participant->getMorphClass())->where('participant_id', $participant->getKey());
    }

    public function isWith(User|AssistantGuest $participant): bool
    {
        return $this->participant_type === $participant->getMorphClass() && $this->participant_id === (string) $participant->getKey();
    }

    public function isOpen(): bool
    {
        return $this->outcome === AssistantOutcome::Open;
    }
}
```

- [ ] **Step 3: Context**

`app/Ai/AssistantContext.php`:

```php
<?php

namespace App\Ai;

use App\Enums\TicketStatus;
use App\Models\AssistantGuest;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\User;

/**
 * What the Concierge knows about the person before the first word. Built
 * once per turn, cheap to compute, and the only thing that makes guest and
 * user behaviour differ.
 */
final class AssistantContext
{
    /** @param  list<array{id: string, name: string}>  $categories */
    private function __construct(
        public readonly User|AssistantGuest $participant,
        public readonly int $openTicketCount,
        public readonly array $categories,
    ) {}

    public static function for(User|AssistantGuest $participant): self
    {
        $open = $participant instanceof User
            ? Ticket::where('requester_id', $participant->getKey())
                ->whereIn('status', array_map(fn (TicketStatus $s): string => $s->value, TicketStatus::open()))
                ->count()
            : 0;

        $categories = Category::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])
            ->map(fn (Category $c): array => ['id' => $c->id, 'name' => $c->name])->all();

        return new self($participant, $open, $categories);
    }

    public function isGuest(): bool
    {
        return $this->participant instanceof AssistantGuest;
    }

    public function user(): ?User
    {
        return $this->participant instanceof User ? $this->participant : null;
    }
}
```

- [ ] **Step 4: Tools**

`app/Ai/Tools/SearchKnowledge.php`:

```php
<?php

namespace App\Ai\Tools;

use App\Models\KnowledgeArticle;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

final class SearchKnowledge implements Tool
{
    public function description(): Stringable|string
    {
        return 'Search the helpdesk knowledge base for articles about a topic. Returns up to five articles with their full text.';
    }

    public function handle(Request $request): Stringable|string
    {
        $validated = $request->validate(['query' => 'required|string|max:200']);

        $articles = KnowledgeArticle::active()->search($validated['query'])->limit(5)->get(['title', 'body']);

        if ($articles->isEmpty()) {
            return 'No articles match.';
        }

        return $articles->map(fn (KnowledgeArticle $a): string => "## {$a->title}\n{$a->body}")->implode("\n\n");
    }

    public function schema(JsonSchema $schema): array
    {
        return ['query' => $schema->string()->description('Words describing the topic, for example "vpn home".')->required()];
    }
}
```

`app/Ai/Tools/FindOpenTickets.php`:

```php
<?php

namespace App\Ai\Tools;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/** The person's own unfinished tickets that read like the issue. Never anyone else's. */
final class FindOpenTickets implements Tool
{
    public function __construct(private readonly User $user) {}

    public function description(): Stringable|string
    {
        return 'Find the person\'s own open or in-progress tickets that match a description, to avoid raising the same issue twice.';
    }

    public function handle(Request $request): Stringable|string
    {
        $validated = $request->validate(['query' => 'required|string|max:200']);

        $tickets = Ticket::query()
            ->where('requester_id', $this->user->getKey())
            ->whereIn('status', array_map(fn (TicketStatus $s): string => $s->value, TicketStatus::open()))
            ->whereFullText(['subject', 'description'], $validated['query'])
            ->orderByDesc('created_at')
            ->limit(3)
            ->get(['id', 'subject', 'status', 'created_at']);

        if ($tickets->isEmpty()) {
            return 'No open tickets match.';
        }

        return $tickets->map(fn (Ticket $t): string => "id={$t->id} | status={$t->status->value} | raised={$t->created_at->toDateString()} | subject={$t->subject}")->implode("\n");
    }

    public function schema(JsonSchema $schema): array
    {
        return ['query' => $schema->string()->description('The gist of the issue in a few words.')->required()];
    }
}
```

`app/Ai/Tools/ListCategories.php`:

```php
<?php

namespace App\Ai\Tools;

use App\Models\Category;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

final class ListCategories implements Tool
{
    public function description(): Stringable|string
    {
        return 'List the ticket categories a ticket can be filed under, with their ids.';
    }

    public function handle(Request $request): Stringable|string
    {
        return Category::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])
            ->map(fn (Category $c): string => "id={$c->id} | name={$c->name}")->implode("\n");
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
```

- [ ] **Step 5: Tests**

`tests/Feature/Assistant/ToolsTest.php`:

```php
<?php

namespace Tests\Feature\Assistant;

use App\Ai\AssistantContext;
use App\Ai\Tools\FindOpenTickets;
use App\Ai\Tools\ListCategories;
use App\Ai\Tools\SearchKnowledge;
use App\Enums\TicketStatus;
use App\Models\AssistantGuest;
use App\Models\Category;
use App\Models\KnowledgeArticle;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

final class ToolsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAuthorization();
    }

    public function test_search_knowledge_returns_matching_active_articles(): void
    {
        KnowledgeArticle::factory()->create(['title' => 'VPN will not connect', 'body' => 'Restart the client.', 'keywords' => 'vpn']);
        KnowledgeArticle::factory()->retired()->create(['title' => 'Old VPN notes', 'body' => 'VPN retired text.', 'keywords' => 'vpn']);

        $text = (string) (new SearchKnowledge)->handle(new Request(['query' => 'vpn connect']));

        $this->assertStringContainsString('## VPN will not connect', $text);
        $this->assertStringNotContainsString('Old VPN notes', $text);
        $this->assertSame('No articles match.', (string) (new SearchKnowledge)->handle(new Request(['query' => 'zzzz qqqq'])));
    }

    public function test_find_open_tickets_only_sees_the_persons_own_unfinished_tickets(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();
        Ticket::factory()->requestedBy($me)->create(['subject' => 'VPN drops every hour', 'description' => 'The VPN tunnel drops.']);
        Ticket::factory()->requestedBy($me)->status(TicketStatus::Resolved)->create(['subject' => 'VPN was slow', 'description' => 'VPN slow last week.']);
        Ticket::factory()->requestedBy($other)->create(['subject' => 'VPN broken for me', 'description' => 'VPN broken.']);

        $text = (string) (new FindOpenTickets($me))->handle(new Request(['query' => 'vpn drops']));

        $this->assertStringContainsString('VPN drops every hour', $text);
        $this->assertStringNotContainsString('VPN was slow', $text);
        $this->assertStringNotContainsString('VPN broken for me', $text);
    }

    public function test_list_categories_and_context(): void
    {
        $cat = Category::factory()->create(['name' => 'IT - Hardware']);
        Category::factory()->retired()->create(['name' => 'Old']);
        $me = User::factory()->create();
        Ticket::factory()->requestedBy($me)->count(2)->create();

        $this->assertStringContainsString("id={$cat->id} | name=IT - Hardware", (string) (new ListCategories)->handle(new Request([])));
        $this->assertStringNotContainsString('Old', (string) (new ListCategories)->handle(new Request([])));

        $context = AssistantContext::for($me);
        $this->assertFalse($context->isGuest());
        $this->assertSame(2, $context->openTicketCount);
        $this->assertSame([['id' => $cat->id, 'name' => 'IT - Hardware']], $context->categories);

        $this->assertTrue(AssistantContext::for(AssistantGuest::create(['last_seen_at' => now()]))->isGuest());
    }
}
```

Check `Laravel\Ai\Tools\Request`'s constructor signature in `vendor/laravel/ai/src/Tools/Request.php` before writing the test; if it takes more than the arguments array, pass the additional arguments as null.

- [ ] **Step 6: Run and commit (user runs)**

```bash
php artisan migrate --force && php artisan test --compact tests/Feature/Assistant/ToolsTest.php
```

```bash
git add database/migrations/2026_09_07_000004_create_assistant_sessions_table.php app/Enums/AssistantOutcome.php app/Enums/CardType.php app/Models/AssistantSession.php app/Ai tests/Feature/Assistant/ToolsTest.php && git commit -m "feat: assistant sessions, context and tools"
```

---

### Task 6: Agents and card validation

**Files:**
- Create: `app/Ai/Agents/KnowledgeAgent.php`, `app/Ai/Agents/TriageAgent.php`, `app/Ai/Agents/Concierge.php`, `app/Ai/Cards/CardValidator.php`
- Test: `tests/Feature/Assistant/AgentsTest.php`

**Interfaces:**
- Produces: `new Concierge(AssistantContext $context)` returning structured `['reply' => string, 'card' => array]`; `CardValidator::validate(array $card, ?User $user): array` returns a normalised card with `type` from `CardType`.

- [ ] **Step 1: KnowledgeAgent**

```php
<?php

namespace App\Ai\Agents;

use App\Ai\Tools\SearchKnowledge;
use Laravel\Ai\Attributes\CacheInstructions;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\CanActAsTool;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Stringable;

/** Answers only from the knowledge base. Says so when it cannot. */
#[Provider(Lab::Anthropic)]
#[Model('claude-haiku-4-5-20251001')]
#[MaxSteps(4)]
#[Timeout(45)]
#[CacheInstructions]
final class KnowledgeAgent implements Agent, CanActAsTool, HasTools
{
    use Promptable;

    public function name(): string
    {
        return 'knowledge_base';
    }

    public function description(): Stringable|string
    {
        return 'Answer a question about IT, HR or how the helpdesk works, using the knowledge base. Pass the question in plain words.';
    }

    public function instructions(): Stringable|string
    {
        return <<<'TEXT'
        You answer questions for an internal IT and HR helpdesk.
        Always call search_knowledge first with a few words from the question. Answer only from the articles it returns.
        Keep answers short, in plain sentences, and give the steps in order. Mention the article title you used.
        If nothing relevant comes back, reply exactly: "I do not have that in the knowledge base." and nothing else.
        Never invent policies, dates, names or contact details.
        TEXT;
    }

    public function tools(): iterable
    {
        return [new SearchKnowledge];
    }
}
```

- [ ] **Step 2: TriageAgent**

```php
<?php

namespace App\Ai\Agents;

use App\Ai\Tools\FindOpenTickets;
use App\Ai\Tools\ListCategories;
use App\Models\User;
use Laravel\Ai\Attributes\CacheInstructions;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\CanActAsTool;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Turns a described problem into a ticket draft, or points at the ticket that
 * already covers it. Sees only the requester's own tickets.
 */
#[Provider(Lab::Anthropic)]
#[Model('claude-haiku-4-5-20251001')]
#[MaxSteps(6)]
#[Timeout(45)]
#[CacheInstructions]
final class TriageAgent implements Agent, CanActAsTool, HasTools
{
    use Promptable;

    public function __construct(private readonly User $user) {}

    public function name(): string
    {
        return 'triage';
    }

    public function description(): Stringable|string
    {
        return 'Given the full description of a problem the person wants help with, check for an existing ticket, then draft a ticket with a priority. Pass everything the person said about the problem.';
    }

    public function instructions(): Stringable|string
    {
        return <<<'TEXT'
        You triage support requests for an internal IT and HR helpdesk. You receive what the person has said about their problem.
        Reply with exactly one line starting with one of these three words, then a JSON object on the same line:
        ASK {"question": "..."} when you still need one specific detail before a ticket would make sense (what device, what error, since when, what were you doing). Ask one question only.
        EXISTING {"ticketId": "..."} when find_open_tickets returns a ticket that is clearly the same problem.
        DRAFT {"subject": "...", "description": "...", "categoryId": "...", "priority": "...", "reason": "..."} otherwise.
        Always call find_open_tickets before deciding. Call list_categories before drafting and use one of its ids.
        Subject: 5 to 120 characters, specific, no trailing full stop. Description: 2 to 5 sentences in the person's words, first person, with the detail that matters.
        Priority rules: urgent when they cannot work at all or there is a security risk. high when they are blocked on a deadline or a whole team is affected. medium when something is degraded but they can work. low for cosmetic issues and questions.
        Reason: one sentence saying why that priority, in words the person will read.
        Output nothing before or after the line.
        TEXT;
    }

    public function tools(): iterable
    {
        return [new FindOpenTickets($this->user), new ListCategories];
    }
}
```

- [ ] **Step 3: CardValidator**

```php
<?php

namespace App\Ai\Cards;

use App\Enums\CardType;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * The model proposes a card; this decides whether it can be shown. Anything
 * it cannot prove is downgraded to no card, with the reason logged, so a bad
 * model output never reaches the browser.
 */
final class CardValidator
{
    /**
     * @param  array<string, mixed>  $card
     * @return array<string, mixed>
     */
    public function validate(array $card, ?User $user): array
    {
        $type = CardType::tryFrom((string) ($card['type'] ?? ''));

        $result = match ($type) {
            CardType::None => ['type' => 'none'],
            CardType::SignInRequired => $user === null ? ['type' => 'sign_in_required'] : null,
            CardType::ExistingTicket => $this->existing($card, $user),
            CardType::TicketDraft => $this->draft($card, $user),
            null => null,
        };

        if ($result === null) {
            Log::info('assistant.card_rejected', ['card' => $card]);

            return ['type' => 'none'];
        }

        return $result;
    }

    /** @param  array<string, mixed>  $card */
    private function existing(array $card, ?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        $ticket = Ticket::query()
            ->where('requester_id', $user->getKey())
            ->whereIn('status', array_map(fn (TicketStatus $s): string => $s->value, TicketStatus::open()))
            ->find((string) ($card['ticketId'] ?? ''));

        return $ticket === null ? null : [
            'type' => 'existing_ticket',
            'ticketId' => $ticket->id,
            'subject' => $ticket->subject,
            'status' => $ticket->status->value,
            'createdAt' => $ticket->created_at->toIso8601String(),
        ];
    }

    /** @param  array<string, mixed>  $card */
    private function draft(array $card, ?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        $subject = trim((string) ($card['subject'] ?? ''));
        $description = trim((string) ($card['description'] ?? ''));
        $priority = TicketPriority::tryFrom((string) ($card['priority'] ?? ''));
        $category = Category::query()->where('is_active', true)->find((string) ($card['categoryId'] ?? ''));

        if (mb_strlen($subject) < 5 || mb_strlen($subject) > 120 || mb_strlen($description) < 10 || $priority === null || $category === null) {
            return null;
        }

        return [
            'type' => 'ticket_draft',
            'subject' => $subject,
            'description' => mb_substr($description, 0, 4000),
            'categoryId' => $category->id,
            'categoryName' => $category->name,
            'priority' => $priority->value,
            'reason' => trim((string) ($card['reason'] ?? '')),
        ];
    }
}
```

- [ ] **Step 4: Concierge**

```php
<?php

namespace App\Ai\Agents;

use App\Ai\AssistantContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\CacheInstructions;
use Laravel\Ai\Attributes\CacheToolDefinitions;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * The one agent the person talks to. It decides what they need and hands the
 * work to a specialist; it never searches or triages itself. Its output is
 * always a reply plus a card, so the client has nothing to parse.
 */
#[Provider(Lab::Anthropic)]
#[Model('claude-haiku-4-5-20251001')]
#[MaxSteps(8)]
#[Timeout(60)]
#[CacheInstructions]
#[CacheToolDefinitions]
final class Concierge implements Agent, Conversational, HasStructuredOutput, HasTools
{
    use Promptable, RemembersConversations;

    public function __construct(private readonly AssistantContext $context) {}

    public function instructions(): Stringable|string
    {
        $who = $this->context->isGuest()
            ? 'The person is NOT signed in. You can answer questions about the helpdesk and general IT and HR topics. You cannot raise tickets or find their tickets. If they want a ticket or a person, set card.type to "sign_in_required" and explain that they need to sign in first.'
            : sprintf('The person is signed in as %s and has %d open ticket(s). You can raise tickets for them through the triage tool.', $this->context->user()?->name, $this->context->openTicketCount);

        return <<<TEXT
        You are the support assistant for an internal IT and HR helpdesk. Plain, warm, brief. Sentence case. No exclamation marks.
        {$who}

        How to work:
        - For a question about how something works or how to fix something, call knowledge_base with the question and relay its answer in your own words. If it says it does not have that, say so and offer to raise a ticket (signed in) or to sign in (guest).
        - For a problem they want fixed, or when they ask for a human or a ticket, first make sure you have what triage needs: what is wrong, on what, since when. Then call triage with everything they said. If triage replies ASK, put its question in your reply and card.type "none". If it replies EXISTING, set card.type "existing_ticket" with that ticketId and tell them the ticket already exists. If it replies DRAFT, copy its fields into a card of type "ticket_draft" and tell them to check the details and confirm.
        - Never say a ticket has been created. You only draft; the person confirms and the system creates it.
        - If they say an existing ticket is a different issue, call triage again and say so in the task.
        - Do not answer from memory about company policy, dates or contacts. Only knowledge_base knows those.

        Always return the structured output: reply is what they read; card is "none" unless one of the cases above applies.
        TEXT;
    }

    public function tools(): iterable
    {
        $tools = [new KnowledgeAgent];

        $user = $this->context->user();
        if ($user !== null) {
            $tools[] = new TriageAgent($user);
        }

        return $tools;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'reply' => $schema->string()->min(1)->max(2000)->required(),
            'card' => $schema->anyOf([
                $schema->object(fn (JsonSchema $s) => ['type' => $s->string()->enum(['none'])->required()]),
                $schema->object(fn (JsonSchema $s) => ['type' => $s->string()->enum(['sign_in_required'])->required()]),
                $schema->object(fn (JsonSchema $s) => [
                    'type' => $s->string()->enum(['existing_ticket'])->required(),
                    'ticketId' => $s->string()->required(),
                ]),
                $schema->object(fn (JsonSchema $s) => [
                    'type' => $s->string()->enum(['ticket_draft'])->required(),
                    'subject' => $s->string()->min(5)->max(120)->required(),
                    'description' => $s->string()->min(10)->max(4000)->required(),
                    'categoryId' => $s->string()->required(),
                    'priority' => $s->string()->enum(['low', 'medium', 'high', 'urgent'])->required(),
                    'reason' => $s->string()->max(300)->required(),
                ]),
            ])->required(),
        ];
    }

    protected function maxConversationMessages(): int
    {
        return 40;
    }
}
```

If `->enum([...])` does not exist on `StringType`, check `vendor/laravel/framework/src/Illuminate/JsonSchema/Types/StringType.php` for the enum method name and use that.

- [ ] **Step 5: Tests**

`tests/Feature/Assistant/AgentsTest.php`:

```php
<?php

namespace Tests\Feature\Assistant;

use App\Ai\Agents\Concierge;
use App\Ai\Agents\TriageAgent;
use App\Ai\AssistantContext;
use App\Ai\Cards\CardValidator;
use App\Enums\TicketStatus;
use App\Models\AssistantGuest;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\AgentTool;
use Tests\TestCase;

final class AgentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAuthorization();
    }

    public function test_a_guest_concierge_has_no_triage_tool(): void
    {
        $guest = AssistantGuest::create(['last_seen_at' => now()]);
        $tools = iterator_to_array((new Concierge(AssistantContext::for($guest)))->tools());

        $this->assertCount(1, $tools);
        $this->assertNotInstanceOf(TriageAgent::class, $tools[0]);
    }

    public function test_a_signed_in_concierge_has_knowledge_and_triage(): void
    {
        $tools = iterator_to_array((new Concierge(AssistantContext::for(User::factory()->create())))->tools());

        $this->assertCount(2, $tools);
        $this->assertInstanceOf(TriageAgent::class, $tools[1]);
        $this->assertSame('triage', (new AgentTool($tools[1]))->name());
    }

    public function test_the_concierge_returns_structured_reply_and_card_from_a_fake(): void
    {
        Concierge::fake([['reply' => 'Hello there.', 'card' => ['type' => 'none']]]);

        $response = (new Concierge(AssistantContext::for(User::factory()->create())))->prompt('Hi');

        $this->assertSame('Hello there.', $response['reply']);
        $this->assertSame('none', $response['card']['type']);
    }

    public function test_card_validation_downgrades_what_it_cannot_prove(): void
    {
        $validator = new CardValidator;
        $me = User::factory()->create();
        $mine = Ticket::factory()->requestedBy($me)->create();
        $resolved = Ticket::factory()->requestedBy($me)->status(TicketStatus::Resolved)->create();
        $theirs = Ticket::factory()->create();
        $category = Category::factory()->create();

        $this->assertSame('none', $validator->validate(['type' => 'sign_in_required'], $me)['type']);
        $this->assertSame('sign_in_required', $validator->validate(['type' => 'sign_in_required'], null)['type']);
        $this->assertSame('existing_ticket', $validator->validate(['type' => 'existing_ticket', 'ticketId' => $mine->id], $me)['type']);
        $this->assertSame('none', $validator->validate(['type' => 'existing_ticket', 'ticketId' => $resolved->id], $me)['type']);
        $this->assertSame('none', $validator->validate(['type' => 'existing_ticket', 'ticketId' => $theirs->id], $me)['type']);

        $draft = ['type' => 'ticket_draft', 'subject' => 'VPN drops hourly', 'description' => 'My VPN drops every hour since Monday.', 'categoryId' => $category->id, 'priority' => 'high', 'reason' => 'Blocked on a deadline.'];
        $this->assertSame('ticket_draft', $validator->validate($draft, $me)['type']);
        $this->assertSame($category->name, $validator->validate($draft, $me)['categoryName']);
        $this->assertSame('none', $validator->validate([...$draft, 'priority' => 'sky-high'], $me)['type']);
        $this->assertSame('none', $validator->validate([...$draft, 'categoryId' => 'nope'], $me)['type']);
        $this->assertSame('none', $validator->validate($draft, null)['type']);
        $this->assertSame('none', $validator->validate(['type' => 'banana'], $me)['type']);
    }
}
```

- [ ] **Step 6: Run and commit (user runs)**

```bash
php artisan test --compact tests/Feature/Assistant/AgentsTest.php
```

```bash
git add app/Ai tests/Feature/Assistant/AgentsTest.php && git commit -m "feat: concierge, knowledge and triage agents with card validation"
```

---

### Task 7: Conversations: start, send, read

**Files:**
- Create: `app/Actions/Assistant/StartConversation.php`, `SendMessage.php`, `app/Exceptions/AssistantUnavailable.php`, `ConversationClosed.php`, `app/Http/Requests/AssistantMessageRequest.php`, `app/Http/Resources/AssistantSessionResource.php`, `AssistantMessageResource.php`, `app/Http/Controllers/AssistantConversationController.php`, `app/Support/AssistantTranscript.php`
- Modify: `routes/api.php`, `bootstrap/app.php`, `app/Providers/AppServiceProvider.php` (rate limiters)
- Test: `tests/Feature/Assistant/ConversationTest.php`

**Interfaces:**
- Produces: `StartConversation::handle(User|AssistantGuest): AssistantSession`; `SendMessage::handle(AssistantSession, User|AssistantGuest, string $text): array{reply: string, card: array, session: AssistantSession}`; `AssistantTranscript::for(string $conversationId): Collection<int, array{id, role, text, card, createdAt}>`; routes `POST /assistant/conversations`, `GET /assistant/conversations/{session}`, `POST /assistant/conversations/{session}/messages`; header `X-Assistant-Guest` echoed as `guestId` in the create response.

- [ ] **Step 1: Exceptions and rendering**

```php
<?php

namespace App\Exceptions;

use RuntimeException;

final class AssistantUnavailable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The assistant is not available right now. Try again in a moment.');
    }
}
```

```php
<?php

namespace App\Exceptions;

use RuntimeException;

final class ConversationClosed extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This conversation has ended. Start a new one.');
    }
}
```

In `bootstrap/app.php`: add `ConversationClosed::class` to the 409 conflict list and `$exceptions->render(fn (AssistantUnavailable $e) => $error('ASSISTANT_UNAVAILABLE', $e->getMessage(), 502));`.

Rate limiters in `AppServiceProvider::boot()`:

```php
        RateLimiter::for('assistant-message', fn (Request $request) => Limit::perMinutes(10, 20)->by('assistant:msg:'.$this->assistantKey($request)));
        RateLimiter::for('assistant-start', fn (Request $request) => Limit::perHour(5)->by('assistant:start:'.$this->assistantKey($request)));
```

with a private helper:

```php
    private function assistantKey(Request $request): string
    {
        return $request->bearerToken() !== null
            ? 'token:'.hash('sha256', (string) $request->bearerToken())
            : 'guest:'.((string) $request->header('X-Assistant-Guest', $request->ip()));
    }
```

- [ ] **Step 2: Transcript reader**

`app/Support/AssistantTranscript.php`:

```php
<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Laravel\Ai\Models\ConversationMessage;

/**
 * Reads a conversation back as the person saw it. The assistant's stored
 * content is the structured JSON, so it is unpacked here and the card is
 * returned as it was shown.
 */
final class AssistantTranscript
{
    /** @return Collection<int, array{id: string, role: string, text: string, card: array<string, mixed>|null, createdAt: string}> */
    public function for(string $conversationId): Collection
    {
        return ConversationMessage::query()
            ->where('conversation_id', $conversationId)
            ->whereIn('role', ['user', 'assistant'])
            ->orderBy('created_at')
            ->get()
            ->map(function (ConversationMessage $message): array {
                $decoded = $message->role === 'assistant' ? json_decode((string) $message->content, true) : null;

                return [
                    'id' => $message->id,
                    'role' => $message->role,
                    'text' => is_array($decoded) ? (string) ($decoded['reply'] ?? '') : (string) $message->content,
                    'card' => is_array($decoded) && is_array($decoded['card'] ?? null) ? $decoded['card'] : null,
                    'createdAt' => $message->created_at->toIso8601String(),
                ];
            })
            ->values();
    }
}
```

Note: the SDK stores the validated card only if we store it. `SendMessage` below rewrites the assistant message content with the validated card, so the transcript shows what the person saw.

- [ ] **Step 3: Actions**

`app/Actions/Assistant/StartConversation.php`:

```php
<?php

namespace App\Actions\Assistant;

use App\Models\AssistantGuest;
use App\Models\AssistantSession;
use App\Models\User;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Models\Conversation;

/**
 * Opens a conversation before the first word, so the browser has an id to
 * keep and the session row exists to be tracked.
 */
final class StartConversation
{
    public function __construct(private readonly ConversationStore $store) {}

    public function handle(User|AssistantGuest $participant): AssistantSession
    {
        $conversationId = $this->store->storeConversation(
            Conversation::participantType($participant),
            Conversation::participantKey($participant),
            'Support conversation',
        );

        return AssistantSession::create([
            'conversation_id' => $conversationId,
            'participant_type' => $participant->getMorphClass(),
            'participant_id' => (string) $participant->getKey(),
            'last_activity_at' => now(),
        ]);
    }
}
```

`app/Actions/Assistant/SendMessage.php`:

```php
<?php

namespace App\Actions\Assistant;

use App\Ai\Agents\Concierge;
use App\Ai\AssistantContext;
use App\Ai\Cards\CardValidator;
use App\Enums\CardType;
use App\Exceptions\AssistantUnavailable;
use App\Exceptions\ConversationClosed;
use App\Models\AssistantGuest;
use App\Models\AssistantSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Models\ConversationMessage;
use Laravel\Ai\Responses\AgentResponse;
use Throwable;

/**
 * One turn. The Concierge is prompted inside its remembered conversation; the
 * card it proposes is validated; the session's counters move.
 */
final class SendMessage
{
    public function __construct(private readonly CardValidator $cards) {}

    /** @return array{reply: string, card: array<string, mixed>, session: AssistantSession} */
    public function handle(AssistantSession $session, User|AssistantGuest $participant, string $text): array
    {
        if (! $session->isOpen()) {
            throw new ConversationClosed;
        }

        $context = AssistantContext::for($participant);
        $response = $this->prompt(new Concierge($context), $session, $participant, $text);

        $structured = $response instanceof \ArrayAccess ? $response : null;
        $reply = trim((string) ($structured['reply'] ?? $response->text));
        $card = $this->cards->validate(is_array($structured['card'] ?? null) ? $structured['card'] : [], $context->user());

        DB::transaction(function () use ($session, $response, $reply, $card): void {
            // Store what was shown, not what was proposed, so the transcript matches the screen.
            ConversationMessage::query()
                ->where('conversation_id', $session->conversation_id)
                ->where('role', 'assistant')
                ->latest('created_at')
                ->limit(1)
                ->update(['content' => json_encode(['reply' => $reply, 'card' => $card])]);

            $session->forceFill([
                'turns' => $session->turns + 1,
                'input_tokens' => $session->input_tokens + $response->usage->promptTokens,
                'output_tokens' => $session->output_tokens + $response->usage->completionTokens,
                'last_agent' => $response->toolCalls->last()?->name,
                'last_draft' => $card['type'] === CardType::TicketDraft->value ? $card : $session->last_draft,
                'last_activity_at' => now(),
            ])->save();
        });

        return ['reply' => $reply, 'card' => $card, 'session' => $session->refresh()];
    }

    private function prompt(Concierge $agent, AssistantSession $session, User|AssistantGuest $participant, string $text): AgentResponse
    {
        $attempts = 0;

        while (true) {
            try {
                return $agent->continue($session->conversation_id, as: $participant)->prompt($text);
            } catch (Throwable $e) {
                if (++$attempts >= 2) {
                    Log::warning('assistant.unavailable', ['error' => $e->getMessage()]);

                    throw new AssistantUnavailable;
                }
            }
        }
    }
}
```

Check `Laravel\Ai\Responses\StructuredAgentResponse` implements `ArrayAccess` (it exposes `offsetGet` through `ProvidesStructuredResponse`); if it exposes `toArray()` only, use `$response instanceof StructuredAgentResponse ? $response->toArray() : []`.

- [ ] **Step 4: Request, resources, controller, routes**

`AssistantMessageRequest`: `'message' => ['required', 'string', 'min:1', 'max:2000']`.

`AssistantSessionResource`:

```php
<?php

namespace App\Http\Resources;

use App\Models\AssistantSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AssistantSession */
final class AssistantSessionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'outcome' => $this->outcome->value,
            'ticketId' => $this->ticket_id,
            'turns' => $this->turns,
            'participant' => [
                'type' => $this->participant_type,
                'id' => $this->participant_id,
                'name' => $this->participant_type === 'user' ? $this->participant?->name : 'Guest',
            ],
            'lastAgent' => $this->last_agent,
            'tokens' => ['input' => $this->input_tokens, 'output' => $this->output_tokens],
            'lastActivityAt' => $this->last_activity_at->toIso8601String(),
            'createdAt' => $this->created_at->toIso8601String(),
            'messages' => $this->when(isset($this->additional['messages']), fn () => $this->additional['messages']),
        ];
    }
}
```

`AssistantConversationController`:

```php
<?php

namespace App\Http\Controllers;

use App\Actions\Assistant\SendMessage;
use App\Actions\Assistant\StartConversation;
use App\Authorization\Actor;
use App\Enums\PermissionSlug;
use App\Http\Requests\AssistantMessageRequest;
use App\Http\Resources\AssistantSessionResource;
use App\Models\AssistantGuest;
use App\Models\AssistantSession;
use App\Support\AssistantParticipant;
use App\Support\AssistantTranscript;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AssistantConversationController extends Controller
{
    public function __construct(
        private readonly AssistantParticipant $participants,
        private readonly AssistantTranscript $transcripts,
    ) {}

    public function store(Request $request, StartConversation $start): JsonResponse
    {
        $participant = $this->participants->resolve($request);
        $session = $start->handle($participant);

        return response()->json(['data' => [
            'session' => new AssistantSessionResource($session),
            'guestId' => $participant instanceof AssistantGuest ? $participant->getKey() : null,
            'greeting' => config('assistant.greeting'),
        ]], 201);
    }

    public function show(Request $request, AssistantSession $session): JsonResponse
    {
        $this->authorizeParticipant($request, $session, allowMetrics: true);

        return response()->json(['data' => (new AssistantSessionResource($session))->additional(['messages' => $this->transcripts->for($session->conversation_id)->all()])]);
    }

    public function message(AssistantMessageRequest $request, AssistantSession $session, SendMessage $send): JsonResponse
    {
        $participant = $this->authorizeParticipant($request, $session);

        $result = $send->handle($session, $participant, $request->string('message')->toString());

        return response()->json(['data' => [
            'reply' => $result['reply'],
            'card' => $result['card'],
            'session' => new AssistantSessionResource($result['session']),
        ]]);
    }

    /**
     * The conversation must be the caller's. An admin with metrics may read
     * any conversation but never speaks in one.
     */
    private function authorizeParticipant(Request $request, AssistantSession $session, bool $allowMetrics = false): \App\Models\User|AssistantGuest
    {
        $participant = $this->participants->resolve($request);

        if ($session->isWith($participant)) {
            return $participant;
        }

        if ($allowMetrics && app()->bound(Actor::class) && app(Actor::class)->can(PermissionSlug::MetricsRead)) {
            return $participant;
        }

        throw new AuthorizationException('This conversation is not yours.');
    }
}
```

`config/assistant.php`:

```php
<?php

return [
    'greeting' => 'Hello. I can answer questions about IT, HR and how the helpdesk works, or help you raise a ticket. What is going on?',
    'guest_ttl_hours' => 24,
    'answered_after_minutes' => 30,
];
```

Routes, outside the `auth.jwt` group:

```php
Route::prefix('assistant')->middleware('auth.optional')->group(function (): void {
    Route::post('/conversations', [AssistantConversationController::class, 'store'])->middleware('throttle:assistant-start');
    Route::get('/conversations/{session}', [AssistantConversationController::class, 'show']);
    Route::post('/conversations/{session}/messages', [AssistantConversationController::class, 'message'])->middleware('throttle:assistant-message');
});
```

`{session}` binds `AssistantSession` by id. Note `AuthorizationException` already renders as 403 FORBIDDEN.

- [ ] **Step 5: Tests**

`tests/Feature/Assistant/ConversationTest.php`:

```php
<?php

namespace Tests\Feature\Assistant;

use App\Ai\Agents\Concierge;
use App\Models\AssistantSession;
use App\Models\Category;
use App\Models\User;
use App\Support\AssistantParticipant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ConversationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAuthorization();
    }

    public function test_a_guest_starts_a_conversation_and_gets_a_guest_id(): void
    {
        $data = $this->asGuest()->postJson('/api/assistant/conversations')->assertCreated()->json('data');

        $this->assertNotNull($data['guestId']);
        $this->assertSame('open', $data['session']['outcome']);
        $this->assertStringContainsString('raise a ticket', $data['greeting']);
        $this->assertDatabaseHas('assistant_sessions', ['id' => $data['session']['id'], 'participant_type' => 'assistant_guest']);
    }

    public function test_a_turn_returns_the_validated_card_and_moves_the_counters(): void
    {
        $me = User::factory()->create(['email' => 'me@example.test']);
        $token = $this->login('me@example.test')['token'];
        $category = Category::factory()->create();

        Concierge::fake([
            ['reply' => 'Tell me more.', 'card' => ['type' => 'none']],
            ['reply' => 'Here is a draft.', 'card' => ['type' => 'ticket_draft', 'subject' => 'VPN drops hourly', 'description' => 'My VPN drops every hour since Monday.', 'categoryId' => $category->id, 'priority' => 'high', 'reason' => 'You are blocked.']],
            ['reply' => 'Bad card.', 'card' => ['type' => 'existing_ticket', 'ticketId' => 'not-mine']],
        ]);

        $session = $this->asUser($token)->postJson('/api/assistant/conversations')->json('data.session');

        $this->asUser($token)->postJson("/api/assistant/conversations/{$session['id']}/messages", ['message' => 'My VPN keeps dropping'])
            ->assertOk()->assertJsonPath('data.reply', 'Tell me more.')->assertJsonPath('data.card.type', 'none');

        $this->asUser($token)->postJson("/api/assistant/conversations/{$session['id']}/messages", ['message' => 'Every hour since Monday'])
            ->assertOk()->assertJsonPath('data.card.type', 'ticket_draft')->assertJsonPath('data.card.categoryName', $category->name);

        $this->asUser($token)->postJson("/api/assistant/conversations/{$session['id']}/messages", ['message' => 'ok'])
            ->assertOk()->assertJsonPath('data.card.type', 'none');

        $row = AssistantSession::find($session['id']);
        $this->assertSame(3, $row->turns);
        $this->assertSame('ticket_draft', $row->last_draft['type']);

        $shown = $this->asUser($token)->getJson("/api/assistant/conversations/{$session['id']}")->assertOk()->json('data.messages');
        $this->assertCount(6, $shown);
        $this->assertSame('user', $shown[0]['role']);
        $this->assertSame('ticket_draft', $shown[3]['card']['type']);
    }

    public function test_a_conversation_is_only_readable_by_its_participant_or_an_admin(): void
    {
        Concierge::fake();
        $guest = $this->asGuest()->postJson('/api/assistant/conversations')->json('data');

        $this->asGuest()->getJson("/api/assistant/conversations/{$guest['session']['id']}")->assertForbidden();
        $this->asGuest()->withHeader(AssistantParticipant::HEADER, $guest['guestId'])->getJson("/api/assistant/conversations/{$guest['session']['id']}")->assertOk();

        User::factory()->admin()->create(['email' => 'admin@example.test']);
        User::factory()->moderator()->create(['email' => 'mod@example.test']);
        $this->asUser($this->login('admin@example.test')['token'])->getJson("/api/assistant/conversations/{$guest['session']['id']}")->assertOk();
        $this->asUser($this->login('mod@example.test')['token'])->getJson("/api/assistant/conversations/{$guest['session']['id']}")->assertForbidden();
        $this->asUser($this->login('mod@example.test')['token'])->postJson("/api/assistant/conversations/{$guest['session']['id']}/messages", ['message' => 'hi'])->assertForbidden();
    }

    public function test_provider_failure_becomes_a_502_after_one_retry(): void
    {
        $calls = 0;
        Concierge::fake(function () use (&$calls) {
            $calls++;
            throw new \RuntimeException('boom');
        });
        $guest = $this->asGuest()->postJson('/api/assistant/conversations')->json('data');

        $this->asGuest()->withHeader(AssistantParticipant::HEADER, $guest['guestId'])
            ->postJson("/api/assistant/conversations/{$guest['session']['id']}/messages", ['message' => 'hi'])
            ->assertStatus(502)->assertJsonPath('error.code', 'ASSISTANT_UNAVAILABLE');
        $this->assertSame(2, $calls);
    }

    public function test_an_empty_message_is_a_validation_error(): void
    {
        $guest = $this->asGuest()->postJson('/api/assistant/conversations')->json('data');

        $this->asGuest()->withHeader(AssistantParticipant::HEADER, $guest['guestId'])
            ->postJson("/api/assistant/conversations/{$guest['session']['id']}/messages", ['message' => ''])
            ->assertStatus(400)->assertJsonPath('error.details.0.field', 'message');
    }
}
```

If the fake gateway catches the closure's exception rather than propagating it, replace the failure test with one that binds a `Concierge` whose `prompt` throws through the container, or assert that the exception propagates from `SendMessage` directly.

- [ ] **Step 6: Run and commit (user runs)**

```bash
php artisan test --compact tests/Feature/Assistant/ConversationTest.php
```

```bash
git add app/Actions/Assistant app/Exceptions/AssistantUnavailable.php app/Exceptions/ConversationClosed.php app/Http/Requests/AssistantMessageRequest.php app/Http/Resources/AssistantSessionResource.php app/Http/Controllers/AssistantConversationController.php app/Support/AssistantTranscript.php config/assistant.php routes/api.php bootstrap/app.php app/Providers/AppServiceProvider.php tests/Feature/Assistant/ConversationTest.php && git commit -m "feat: assistant conversations: start, send a turn, read back"
```

---

### Task 8: Raise a ticket from a conversation, claim a guest conversation, transcript on the ticket

**Files:**
- Create: `app/Actions/Assistant/RaiseTicketFromConversation.php`, `ClaimConversation.php`, `app/Http/Requests/AssistantTicketRequest.php`
- Modify: `app/Http/Controllers/AssistantConversationController.php`, `app/Http/Controllers/TicketController.php` (`loadDetail` and show), `app/Http/Resources/TicketDetailResource.php`, `routes/api.php`, `app/Enums/ActionType.php`
- Test: `tests/Feature/Assistant/RaiseTicketTest.php`, `tests/Feature/Assistant/ClaimTest.php`

**Interfaces:**
- Produces: `POST /assistant/conversations/{session}/tickets` returning the ticket detail (201); `POST /assistant/conversations/{session}/claim` returning the session; ticket detail gains `source` and, for `ticket:list_queue` holders, `transcript`.

- [ ] **Step 1: Action log cases**

`ActionType`: add `case ConversationClaimed = 'assistant.conversation_claimed';`.

- [ ] **Step 2: RaiseTicketFromConversation**

```php
<?php

namespace App\Actions\Assistant;

use App\Actions\Concerns\RecordsActions;
use App\Actions\Tickets\CreateTicket;
use App\Enums\AssistantOutcome;
use App\Enums\TicketPriority;
use App\Enums\TicketSource;
use App\Exceptions\ConversationClosed;
use App\Models\AssistantSession;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Models\ConversationMessage;

/**
 * The person confirmed the draft. Ownership of the conversation is the
 * authority here, not a grant: a user has no ticket:create and needs none.
 * The priority is the one the assistant drafted, never one the client sends.
 */
final class RaiseTicketFromConversation
{
    use RecordsActions;

    public const CONFIRMATION = 'Your ticket has been raised. A moderator will be in touch. You can follow it under Your tickets.';

    public function __construct(private readonly CreateTicket $create) {}

    public function handle(AssistantSession $session, User $requester, string $subject, string $description, string $categoryId): Ticket
    {
        if (! $session->isWith($requester)) {
            throw new AuthorizationException('This conversation is not yours.');
        }

        if (! $session->isOpen()) {
            throw new ConversationClosed;
        }

        $priority = TicketPriority::tryFrom((string) ($session->last_draft['priority'] ?? '')) ?? TicketPriority::Medium;

        return DB::transaction(function () use ($session, $requester, $subject, $description, $categoryId, $priority): Ticket {
            $ticket = $this->create->createFor($requester, $subject, $description, $categoryId, $priority, TicketSource::Assistant, $session->conversation_id);

            ConversationMessage::create([
                'conversation_id' => $session->conversation_id,
                'participant_type' => $session->participant_type,
                'participant_id' => $session->participant_id,
                'agent' => 'system',
                'role' => 'assistant',
                'content' => json_encode(['reply' => self::CONFIRMATION, 'card' => ['type' => 'none']]),
                'attachments' => '[]', 'tool_calls' => '[]', 'tool_results' => '[]', 'usage' => '{}', 'meta' => '{}',
            ]);

            $session->forceFill([
                'outcome' => AssistantOutcome::TicketRaised,
                'ticket_id' => $ticket->id,
                'last_activity_at' => now(),
            ])->save();

            return $ticket;
        });
    }
}
```

Check `Laravel\Ai\Models\ConversationMessage` for its `$fillable` and casts; if it uses `HasUuids`, the id is generated. If `attachments` and friends are cast to arrays, pass `[]` and `[]` rather than JSON strings.

- [ ] **Step 3: ClaimConversation**

```php
<?php

namespace App\Actions\Assistant;

use App\Actions\Concerns\RecordsActions;
use App\Enums\ActionType;
use App\Exceptions\ConversationClosed;
use App\Models\AssistantGuest;
use App\Models\AssistantSession;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;

/**
 * A guest who signs in keeps their conversation. Only the guest who was
 * talking can hand it over, and only while it is still open.
 */
final class ClaimConversation
{
    use RecordsActions;

    public function handle(AssistantSession $session, AssistantGuest $guest, User $user): AssistantSession
    {
        if (! $session->isWith($guest)) {
            throw new AuthorizationException('This conversation is not yours.');
        }

        if (! $session->isOpen()) {
            throw new ConversationClosed;
        }

        return DB::transaction(function () use ($session, $user): AssistantSession {
            $type = Conversation::participantType($user);
            $key = (string) Conversation::participantKey($user);

            Conversation::whereKey($session->conversation_id)->update(['participant_type' => $type, 'participant_id' => $key]);
            ConversationMessage::where('conversation_id', $session->conversation_id)->update(['participant_type' => $type, 'participant_id' => $key]);

            $session->forceFill(['participant_type' => $user->getMorphClass(), 'participant_id' => $user->getKey(), 'last_activity_at' => now()])->save();

            $this->record(ActionType::ConversationClaimed, $user, $session, ['conversation_id' => $session->conversation_id]);

            return $session;
        });
    }
}
```

Add `'assistant_session' => AssistantSession::class` to the morph map so the action log can reference it.

- [ ] **Step 4: Request, controller methods, routes**

`AssistantTicketRequest` rules: subject `required|string|min:5|max:120`, description `required|string|min:10|max:4000`, categoryId `required|string` with `Rule::exists('categories','id')->where('is_active', true)`.

Controller additions:

```php
    public function ticket(AssistantTicketRequest $request, AssistantSession $session, RaiseTicketFromConversation $raise): JsonResponse
    {
        if (! app()->bound(Actor::class)) {
            throw new AuthorizationException('Sign in to raise a ticket.');
        }

        $ticket = $raise->handle(
            $session,
            app(Actor::class)->user,
            $request->string('subject')->toString(),
            $request->string('description')->toString(),
            $request->string('categoryId')->toString(),
        );

        return response()->json(['data' => [
            'ticket' => new TicketResource($ticket->load(['category:id,slug,name', 'requester:id,name', 'assignee:id,name'])),
            'message' => RaiseTicketFromConversation::CONFIRMATION,
            'session' => new AssistantSessionResource($session->refresh()),
        ]], 201);
    }

    public function claim(Request $request, AssistantSession $session, ClaimConversation $claim): JsonResponse
    {
        if (! app()->bound(Actor::class)) {
            throw new AuthorizationException('Sign in to keep this conversation.');
        }

        $guestId = (string) $request->header(AssistantParticipant::HEADER, '');
        $guest = AssistantGuest::find($guestId);

        if ($guest === null) {
            throw new AuthorizationException('This conversation is not yours.');
        }

        return response()->json(['data' => new AssistantSessionResource($claim->handle($session, $guest, app(Actor::class)->user))]);
    }
```

Routes inside the assistant group:

```php
    Route::post('/conversations/{session}/tickets', [AssistantConversationController::class, 'ticket']);
    Route::post('/conversations/{session}/claim', [AssistantConversationController::class, 'claim']);
```

- [ ] **Step 5: Transcript on the ticket**

`TicketDetailResource::toArray()` add:

```php
            'transcript' => $this->when(isset($this->additional['transcript']), fn () => $this->additional['transcript']),
```

In `TicketController::show()`:

```php
        $resource = new TicketDetailResource($this->loadDetail($ticket));

        if ($ticket->conversation_id !== null && $actor->can(PermissionSlug::TicketListQueue)) {
            $resource->additional(['transcript' => app(AssistantTranscript::class)->for($ticket->conversation_id)->all()]);
        }

        return response()->json(['data' => $resource]);
```

Check how `JsonResource::additional()` nests: it adds top-level keys beside `data`. If so, instead pass the transcript through a public property: `$ticket->setAttribute('transcript', ...)` before wrapping and read `$this->transcript` in the resource with `whenNotNull`. Use whichever keeps `transcript` inside `data`.

- [ ] **Step 6: Tests**

`tests/Feature/Assistant/RaiseTicketTest.php`:

```php
<?php

namespace Tests\Feature\Assistant;

use App\Actions\Assistant\RaiseTicketFromConversation;
use App\Ai\Agents\Concierge;
use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class RaiseTicketTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private Category $category;

    private string $sessionId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAuthorization();
        User::factory()->create(['email' => 'me@example.test']);
        $this->token = $this->login('me@example.test')['token'];
        $this->category = Category::factory()->create();

        Concierge::fake([
            ['reply' => 'Draft ready.', 'card' => ['type' => 'ticket_draft', 'subject' => 'VPN drops hourly', 'description' => 'My VPN drops every hour since Monday.', 'categoryId' => $this->category->id, 'priority' => 'urgent', 'reason' => 'Cannot work.']],
        ]);

        $this->sessionId = $this->asUser($this->token)->postJson('/api/assistant/conversations')->json('data.session.id');
        $this->asUser($this->token)->postJson("/api/assistant/conversations/{$this->sessionId}/messages", ['message' => 'VPN drops'])->assertOk();
    }

    public function test_confirming_the_draft_creates_the_ticket_with_the_drafted_priority(): void
    {
        $data = $this->asUser($this->token)->postJson("/api/assistant/conversations/{$this->sessionId}/tickets", [
            'subject' => 'VPN drops every hour', 'description' => 'Edited by me: it drops hourly since Monday.', 'categoryId' => $this->category->id, 'priority' => 'low',
        ])->assertCreated()->json('data');

        $this->assertSame('urgent', $data['ticket']['priority']);
        $this->assertSame('assistant', $data['ticket']['source']);
        $this->assertSame('ticket_raised', $data['session']['outcome']);
        $this->assertSame(RaiseTicketFromConversation::CONFIRMATION, $data['message']);
        $this->assertDatabaseHas('tickets', ['id' => $data['ticket']['id'], 'source' => 'assistant']);
        $this->assertDatabaseHas('action_logs', ['action' => 'ticket.created', 'subject_id' => $data['ticket']['id']]);

        $messages = $this->asUser($this->token)->getJson("/api/assistant/conversations/{$this->sessionId}")->json('data.messages');
        $this->assertSame(RaiseTicketFromConversation::CONFIRMATION, end($messages)['text']);

        $this->asUser($this->token)->postJson("/api/assistant/conversations/{$this->sessionId}/messages", ['message' => 'thanks'])
            ->assertStatus(409)->assertJsonPath('error.code', 'CONFLICT');
    }

    public function test_a_moderator_sees_the_transcript_on_the_ticket_and_the_requester_does_not(): void
    {
        $ticketId = $this->asUser($this->token)->postJson("/api/assistant/conversations/{$this->sessionId}/tickets", [
            'subject' => 'VPN drops every hour', 'description' => 'It drops hourly since Monday.', 'categoryId' => $this->category->id,
        ])->json('data.ticket.id');

        User::factory()->moderator()->create(['email' => 'mod@example.test']);
        $mod = $this->login('mod@example.test')['token'];

        $this->asUser($mod)->getJson("/api/tickets/{$ticketId}")->assertOk()
            ->assertJsonPath('data.source', 'assistant')
            ->assertJsonPath('data.transcript.0.role', 'user')
            ->assertJsonPath('data.transcript.0.text', 'VPN drops');

        $this->asUser($this->token)->getJson("/api/tickets/{$ticketId}")->assertOk()
            ->assertJsonPath('data.source', 'assistant')
            ->assertJsonMissingPath('data.transcript');
    }

    public function test_someone_else_cannot_raise_from_my_conversation_and_guests_cannot_at_all(): void
    {
        User::factory()->create(['email' => 'other@example.test']);
        $other = $this->login('other@example.test')['token'];
        $body = ['subject' => 'Anything here', 'description' => 'A description long enough.', 'categoryId' => $this->category->id];

        $this->asUser($other)->postJson("/api/assistant/conversations/{$this->sessionId}/tickets", $body)->assertForbidden();
        $this->asGuest()->postJson("/api/assistant/conversations/{$this->sessionId}/tickets", $body)->assertForbidden();
    }
}
```

`tests/Feature/Assistant/ClaimTest.php`:

```php
<?php

namespace Tests\Feature\Assistant;

use App\Ai\Agents\Concierge;
use App\Models\AssistantSession;
use App\Models\User;
use App\Support\AssistantParticipant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ClaimTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAuthorization();
        Concierge::fake([['reply' => 'Sign in first.', 'card' => ['type' => 'sign_in_required']]]);
    }

    public function test_a_guest_conversation_follows_the_person_after_sign_in(): void
    {
        $guest = $this->asGuest()->postJson('/api/assistant/conversations')->json('data');
        $this->asGuest()->withHeader(AssistantParticipant::HEADER, $guest['guestId'])
            ->postJson("/api/assistant/conversations/{$guest['session']['id']}/messages", ['message' => 'I need a ticket'])
            ->assertOk()->assertJsonPath('data.card.type', 'sign_in_required');

        $user = User::factory()->create(['email' => 'me@example.test']);
        $token = $this->login('me@example.test')['token'];

        $this->asUser($token)->withHeader(AssistantParticipant::HEADER, $guest['guestId'])
            ->postJson("/api/assistant/conversations/{$guest['session']['id']}/claim")
            ->assertOk()->assertJsonPath('data.participant.type', 'user')->assertJsonPath('data.participant.id', $user->id);

        $this->assertDatabaseHas('agent_conversations', ['id' => AssistantSession::find($guest['session']['id'])->conversation_id, 'participant_id' => $user->id]);

        $this->asUser($token)->getJson("/api/assistant/conversations/{$guest['session']['id']}")->assertOk()->assertJsonCount(2, 'data.messages');
        $this->assertDatabaseHas('action_logs', ['action' => 'assistant.conversation_claimed', 'actor_id' => $user->id]);
    }

    public function test_only_the_talking_guest_can_hand_over_and_only_while_open(): void
    {
        $guest = $this->asGuest()->postJson('/api/assistant/conversations')->json('data');
        $stranger = $this->asGuest()->postJson('/api/assistant/conversations')->json('data');
        User::factory()->create(['email' => 'me@example.test']);
        $token = $this->login('me@example.test')['token'];

        $this->asUser($token)->postJson("/api/assistant/conversations/{$guest['session']['id']}/claim")->assertForbidden();
        $this->asUser($token)->withHeader(AssistantParticipant::HEADER, $stranger['guestId'])->postJson("/api/assistant/conversations/{$guest['session']['id']}/claim")->assertForbidden();
        $this->asGuest()->withHeader(AssistantParticipant::HEADER, $guest['guestId'])->postJson("/api/assistant/conversations/{$guest['session']['id']}/claim")->assertForbidden();

        AssistantSession::find($guest['session']['id'])->update(['outcome' => 'abandoned']);
        $this->asUser($token)->withHeader(AssistantParticipant::HEADER, $guest['guestId'])->postJson("/api/assistant/conversations/{$guest['session']['id']}/claim")->assertStatus(409);
    }
}
```

Add sweep cases for the new routes (`anonymous` 401 for `/tickets` and `/claim` on a session the sweep creates as the user; other roles 403 because the session is the user's). Because these routes use `auth.optional`, anonymous returns 403 not 401; set `'anonymous' => 403`.

- [ ] **Step 7: Run and commit (user runs)**

```bash
php artisan test --compact tests/Feature/Assistant tests/Feature/RoleAccessSweepTest.php
```

```bash
git add app/Actions/Assistant app/Http app/Enums/ActionType.php app/Providers/AppServiceProvider.php routes/api.php tests && git commit -m "feat: raise tickets from a conversation, claim guest conversations, transcript on the ticket"
```

---

### Task 9: Settlement, the conversations list, and metrics

**Files:**
- Create: `app/Actions/Assistant/SettleSessions.php`, `app/Console/Commands/SettleAssistantSessions.php`, `app/Http/Requests/AssistantConversationIndexRequest.php`
- Modify: `routes/console.php`, `routes/api.php`, `app/Http/Controllers/AssistantConversationController.php`, `app/Queries/MetricsQuery.php`
- Test: `tests/Feature/Assistant/TrackingTest.php`

**Interfaces:**
- Produces: `GET /assistant/conversations?outcome=&participant=&page=&limit=` (`metrics:read`), `metrics.assistant` block `{ conversations, answered, ticketsRaised, deflectionRate }` for the last 30 days, artisan `assistant:settle-sessions` scheduled every 15 minutes.

- [ ] **Step 1: SettleSessions**

```php
<?php

namespace App\Actions\Assistant;

use App\Enums\AssistantOutcome;
use App\Models\AssistantSession;

/**
 * Applies the time-based outcomes. A conversation whose last turn was an
 * answer and has been quiet for a while counts as answered; one that went
 * quiet for a day without a ticket is abandoned.
 */
final class SettleSessions
{
    /** @return array{answered: int, abandoned: int} */
    public function handle(): array
    {
        $answeredAfter = now()->subMinutes((int) config('assistant.answered_after_minutes'));
        $abandonAfter = now()->subHours((int) config('assistant.guest_ttl_hours'));

        $answered = AssistantSession::query()
            ->where('outcome', AssistantOutcome::Open->value)
            ->where('last_agent', 'knowledge_base')
            ->where('turns', '>', 0)
            ->where('last_activity_at', '<', $answeredAfter)
            ->update(['outcome' => AssistantOutcome::Answered->value]);

        $abandoned = AssistantSession::query()
            ->where('outcome', AssistantOutcome::Open->value)
            ->where('last_activity_at', '<', $abandonAfter)
            ->update(['outcome' => AssistantOutcome::Abandoned->value]);

        return ['answered' => $answered, 'abandoned' => $abandoned];
    }
}
```

Command `app/Console/Commands/SettleAssistantSessions.php` with signature `assistant:settle-sessions`, calling the action and printing the two counts. In `routes/console.php`: `Schedule::command('assistant:settle-sessions')->everyFifteenMinutes();` (import `Illuminate\Support\Facades\Schedule`).

- [ ] **Step 2: Index endpoint and metrics**

`AssistantConversationIndexRequest` rules: `outcome` in the enum values, `participant` in `user,assistant_guest`, `page`, `limit` (max 100).

Controller:

```php
    public function index(AssistantConversationIndexRequest $request, Actor $actor): JsonResponse
    {
        $actor->authorize(PermissionSlug::MetricsRead);

        $page = AssistantSession::query()
            ->with('participant')
            ->when($request->filled('outcome'), fn ($q) => $q->where('outcome', $request->query('outcome')))
            ->when($request->filled('participant'), fn ($q) => $q->where('participant_type', $request->query('participant')))
            ->orderByDesc('last_activity_at')
            ->paginate(perPage: $request->integer('limit', 20), page: $request->integer('page', 1));

        return response()->json([
            'data' => AssistantSessionResource::collection($page->items()),
            'pagination' => ['page' => $page->currentPage(), 'limit' => $page->perPage(), 'totalItems' => $page->total(), 'totalPages' => $page->lastPage()],
        ]);
    }
```

Route inside the `auth.jwt` group (it must come before the assistant group's `{session}` route would match, and it is a different prefix so no conflict): `Route::get('/assistant/conversations', [AssistantConversationController::class, 'index']);`. Because the assistant group also declares `GET /assistant/conversations/{session}` under `auth.optional`, the list route has no parameter and Laravel matches it first when declared first. Declare the list route before the group.

`MetricsQuery::overview()` add `'assistant' => $this->assistant(),` and:

```php
    /** @return array{conversations: int, answered: int, ticketsRaised: int, deflectionRate: float|null} */
    private function assistant(): array
    {
        $since = now()->subDays(30);
        $base = fn () => AssistantSession::where('created_at', '>=', $since);

        $conversations = $base()->count();
        $answered = $base()->where('outcome', AssistantOutcome::Answered->value)->count();
        $raised = $base()->where('outcome', AssistantOutcome::TicketRaised->value)->count();
        $settled = $answered + $raised;

        return [
            'conversations' => $conversations,
            'answered' => $answered,
            'ticketsRaised' => $raised,
            'deflectionRate' => $settled === 0 ? null : round($answered / $settled, 3),
        ];
    }
```

- [ ] **Step 3: Tests**

`tests/Feature/Assistant/TrackingTest.php`:

```php
<?php

namespace Tests\Feature\Assistant;

use App\Actions\Assistant\SettleSessions;
use App\Enums\AssistantOutcome;
use App\Models\AssistantGuest;
use App\Models\AssistantSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class TrackingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAuthorization();
    }

    private function session(array $overrides = []): AssistantSession
    {
        $guest = AssistantGuest::create(['last_seen_at' => now()]);

        return AssistantSession::create(array_merge([
            'conversation_id' => (string) \Illuminate\Support\Str::uuid7(),
            'participant_type' => 'assistant_guest',
            'participant_id' => $guest->id,
            'last_activity_at' => now(),
        ], $overrides));
    }

    public function test_sessions_settle_to_answered_and_abandoned_on_time(): void
    {
        $answered = $this->session(['last_agent' => 'knowledge_base', 'turns' => 2, 'last_activity_at' => now()->subHour()]);
        $fresh = $this->session(['last_agent' => 'knowledge_base', 'turns' => 1, 'last_activity_at' => now()->subMinutes(5)]);
        $abandoned = $this->session(['last_agent' => 'triage', 'turns' => 3, 'last_activity_at' => now()->subDays(2)]);
        $raised = $this->session(['outcome' => AssistantOutcome::TicketRaised->value, 'last_activity_at' => now()->subDays(3)]);

        $this->assertSame(['answered' => 1, 'abandoned' => 1], (new SettleSessions)->handle());

        $this->assertSame(AssistantOutcome::Answered, $answered->fresh()->outcome);
        $this->assertSame(AssistantOutcome::Open, $fresh->fresh()->outcome);
        $this->assertSame(AssistantOutcome::Abandoned, $abandoned->fresh()->outcome);
        $this->assertSame(AssistantOutcome::TicketRaised, $raised->fresh()->outcome);
    }

    public function test_admins_list_conversations_with_filters_and_see_the_metrics_block(): void
    {
        $this->session(['outcome' => 'answered']);
        $this->session(['outcome' => 'answered']);
        $this->session(['outcome' => 'ticket_raised']);
        $this->session();

        User::factory()->admin()->create(['email' => 'admin@example.test']);
        User::factory()->moderator()->create(['email' => 'mod@example.test']);
        $admin = $this->login('admin@example.test')['token'];

        $this->asUser($admin)->getJson('/api/assistant/conversations')->assertOk()->assertJsonCount(4, 'data')->assertJsonPath('pagination.totalItems', 4);
        $this->asUser($admin)->getJson('/api/assistant/conversations?outcome=answered')->assertOk()->assertJsonCount(2, 'data');
        $this->asUser($this->login('mod@example.test')['token'])->getJson('/api/assistant/conversations')->assertForbidden();

        $this->asUser($admin)->getJson('/api/metrics')->assertOk()
            ->assertJsonPath('data.assistant.conversations', 4)
            ->assertJsonPath('data.assistant.answered', 2)
            ->assertJsonPath('data.assistant.ticketsRaised', 1)
            ->assertJsonPath('data.assistant.deflectionRate', 0.667);
    }
}
```

Add `'GET /assistant/conversations'` to the sweep with `anonymous 401, user 403, moderator 403, admin 200`.

- [ ] **Step 4: Run and commit (user runs)**

```bash
php artisan test --compact
```

```bash
git add app/Actions/Assistant/SettleSessions.php app/Console app/Http app/Queries/MetricsQuery.php routes tests && git commit -m "feat: settle assistant sessions, list conversations, assistant metrics"
```

---

### Task 10: API docs, Postman, smoke test, live test

**Files:**
- Modify: `README.md`, `docs/spec.md` (routes table and a new section 6), `docs/helpdesk-api.postman_collection.json`, `docs/smoke-test.sh`
- Create: `tests/Feature/Assistant/LiveAssistantTest.php`

- [ ] **Step 1: Live test**

```php
<?php

namespace Tests\Feature\Assistant;

use App\Models\Category;
use App\Models\KnowledgeArticle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Calls Anthropic for real. Off unless ASSISTANT_LIVE_TESTS=1 and a key is set. */
final class LiveAssistantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (env('ASSISTANT_LIVE_TESTS') !== '1' || ! config('ai.providers.anthropic.key')) {
            $this->markTestSkipped('Live assistant tests are off.');
        }
        $this->seedAuthorization();
    }

    public function test_a_real_turn_answers_from_the_knowledge_base(): void
    {
        KnowledgeArticle::factory()->create(['title' => 'Support hours', 'body' => 'The desk is staffed Sunday to Thursday, 8:00 to 17:00.', 'keywords' => 'hours']);
        Category::factory()->create();
        User::factory()->create(['email' => 'me@example.test']);
        $token = $this->login('me@example.test')['token'];

        $session = $this->asUser($token)->postJson('/api/assistant/conversations')->json('data.session');
        $reply = $this->asUser($token)->postJson("/api/assistant/conversations/{$session['id']}/messages", ['message' => 'What are the support hours?'])
            ->assertOk()->json('data.reply');

        $this->assertMatchesRegularExpression('/Sunday|Thursday|8:00|17:00/', $reply);
    }
}
```

- [ ] **Step 2: README**

Add a section "The assistant" after "Emails" covering: what it does, the three agents and their tools, that the model drafts and the server creates, that the User role raises tickets only through it, the endpoints table, `ANTHROPIC_API_KEY` and `ANTHROPIC_MODEL`, the settlement command and schedule, and how to run the live test. Add `knowledge:manage` to the "Who can do what" table and a "Knowledge" endpoints table. Update the test count.

- [ ] **Step 3: Postman**

Add folders "Assistant" (Start conversation, Send a message, Read a conversation, Confirm the ticket draft, Claim a guest conversation, List conversations) and "Knowledge" (List, Create, Update or retire). Add variables `sessionId` and `guestId`. Each request follows the shape used by the existing entries (headers Accept and Content-Type, `{{baseUrl}}`, description, one saved response).

- [ ] **Step 4: Smoke**

Append an `== ASSISTANT ==` block to `docs/smoke-test.sh` that starts a guest conversation (201), reads it with and without the guest header (200 and 403), sends an empty message (400), lists conversations as admin (200) and as moderator (403), and creates a knowledge article as admin (201). Do not send a real message; the smoke script must not need a key.

- [ ] **Step 5: Run and commit (user runs)**

```bash
bash docs/smoke-test.sh | tail -3 && php artisan test --compact
```

```bash
git add README.md docs tests/Feature/Assistant/LiveAssistantTest.php && git commit -m "docs: document the assistant, its endpoints and the knowledge base"
```

---

### Task 11 (web): API layer, types and the assistant slice

**Files:**
- Create: `src/api/assistant.api.ts`, `src/api/knowledge.api.ts`, `src/features/assistant/assistantSlice.ts`, `src/features/assistant/assistantStorage.ts`, `src/features/assistant/cards/cardSchema.ts`
- Modify: `src/api/types.ts`, `src/api/baseApi.ts` (guest header, tag types), `src/app/store.ts`
- Test: `src/features/assistant/assistantSlice.test.ts`, `src/features/assistant/cards/cardSchema.test.ts`

**Interfaces:**
- Produces: types `AssistantCard`, `AssistantMessage`, `AssistantSession`, `KnowledgeArticle`, `Metrics.assistant`; hooks `useStartConversationMutation`, `useGetConversationQuery`, `useSendMessageMutation`, `useRaiseTicketMutation`, `useClaimConversationMutation`, `useListConversationsQuery`, `useListArticlesQuery`, `useCreateArticleMutation`, `useUpdateArticleMutation`; slice actions `panelOpened`, `panelClosed`, `conversationStarted({ sessionId, guestId, greeting })`, `messageSent(text)`, `replyReceived({ reply, card })`, `replyFailed(message)`, `conversationRestored({ sessionId, messages })`, `conversationReset()`; storage `readAssistantStorage(): { sessionId: string | null; guestId: string | null }`, `writeAssistantStorage(...)`.

- [ ] **Step 1: Types**

Append to `src/api/types.ts`:

```ts
export type AssistantCard =
  | { type: 'none' }
  | { type: 'sign_in_required' }
  | { type: 'existing_ticket'; ticketId: string; subject: string; status: TicketStatus; createdAt: string }
  | { type: 'ticket_draft'; subject: string; description: string; categoryId: string; categoryName: string; priority: TicketPriority; reason: string }

export interface AssistantMessage {
  id: string
  role: 'user' | 'assistant'
  text: string
  card: AssistantCard | null
  createdAt: string
}

export type AssistantOutcome = 'open' | 'answered' | 'ticket_raised' | 'abandoned'

export interface AssistantSession {
  id: string
  outcome: AssistantOutcome
  ticketId: string | null
  turns: number
  participant: { type: 'user' | 'assistant_guest'; id: string; name: string }
  lastAgent: string | null
  tokens: { input: number; output: number }
  lastActivityAt: string
  createdAt: string
  messages?: AssistantMessage[]
}

export interface KnowledgeArticle {
  id: string
  title: string
  body: string
  keywords: string
  category: { id: string; name: string } | null
  isActive: boolean
  updatedAt: string
}
```

Add `'knowledge:manage'` to `Permission`, `assistant: { conversations: number; answered: number; ticketsRaised: number; deflectionRate: number | null }` to `Metrics`, `source: 'direct' | 'assistant'` to `Ticket`, and `transcript?: AssistantMessage[]` to `TicketDetail`.

- [ ] **Step 2: Guest header in the base query and tags**

In `src/api/baseApi.ts` `prepareHeaders`, after the bearer:

```ts
    const guestId = (getState() as RootState).assistant.guestId
    if (guestId) headers.set('x-assistant-guest', guestId)
```

Add `'Conversation'`, `'ConversationList'`, `'Article'` to `tagTypes`.

- [ ] **Step 3: Storage and slice**

`src/features/assistant/assistantStorage.ts`:

```ts
const KEY = 'helpdesk.assistant'

export interface AssistantStorage {
  sessionId: string | null
  guestId: string | null
}

/** localStorage can throw in private windows; a failed read is the same as nothing stored. */
export function readAssistantStorage(): AssistantStorage {
  try {
    const raw = localStorage.getItem(KEY)
    if (!raw) return { sessionId: null, guestId: null }
    const parsed = JSON.parse(raw) as Partial<AssistantStorage>
    return { sessionId: parsed.sessionId ?? null, guestId: parsed.guestId ?? null }
  } catch {
    return { sessionId: null, guestId: null }
  }
}

export function writeAssistantStorage(value: AssistantStorage): void {
  try {
    localStorage.setItem(KEY, JSON.stringify(value))
  } catch {
    // Nothing to do: the conversation still works for this page load.
  }
}
```

`src/features/assistant/assistantSlice.ts`:

```ts
import { createSlice, nanoid, type PayloadAction } from '@reduxjs/toolkit'
import type { AssistantCard, AssistantMessage } from '@/api/types'
import { readAssistantStorage } from './assistantStorage'

export interface AssistantState {
  open: boolean
  sessionId: string | null
  guestId: string | null
  messages: AssistantMessage[]
  pending: boolean
  error: string | null
  /** True once the ticket was raised; the composer closes and a link shows. */
  closed: boolean
}

const stored = readAssistantStorage()

const initialState: AssistantState = {
  open: false,
  sessionId: stored.sessionId,
  guestId: stored.guestId,
  messages: [],
  pending: false,
  error: null,
  closed: false,
}

const assistantMessage = (text: string, card: AssistantCard | null = null): AssistantMessage => ({
  id: nanoid(),
  role: 'assistant',
  text,
  card,
  createdAt: new Date().toISOString(),
})

const assistantSlice = createSlice({
  name: 'assistant',
  initialState,
  reducers: {
    panelOpened(state) { state.open = true },
    panelClosed(state) { state.open = false },
    conversationStarted(state, action: PayloadAction<{ sessionId: string; guestId: string | null; greeting: string }>) {
      state.sessionId = action.payload.sessionId
      state.guestId = action.payload.guestId ?? state.guestId
      state.messages = [assistantMessage(action.payload.greeting)]
      state.closed = false
      state.error = null
    },
    conversationRestored(state, action: PayloadAction<{ sessionId: string; messages: AssistantMessage[]; closed: boolean; greeting: string }>) {
      state.sessionId = action.payload.sessionId
      state.messages = [assistantMessage(action.payload.greeting), ...action.payload.messages]
      state.closed = action.payload.closed
    },
    messageSent(state, action: PayloadAction<string>) {
      state.messages.push({ id: nanoid(), role: 'user', text: action.payload, card: null, createdAt: new Date().toISOString() })
      state.pending = true
      state.error = null
    },
    replyReceived(state, action: PayloadAction<{ reply: string; card: AssistantCard }>) {
      state.messages.push(assistantMessage(action.payload.reply, action.payload.card.type === 'none' ? null : action.payload.card))
      state.pending = false
    },
    replyFailed(state, action: PayloadAction<string>) {
      // The person's message stays in the composer, so the optimistic bubble goes.
      const last = state.messages[state.messages.length - 1]
      if (last?.role === 'user') state.messages.pop()
      state.pending = false
      state.error = action.payload
    },
    ticketRaised(state, action: PayloadAction<{ message: string }>) {
      state.messages.push(assistantMessage(action.payload.message))
      state.closed = true
    },
    conversationReset(state) {
      state.sessionId = null
      state.messages = []
      state.pending = false
      state.error = null
      state.closed = false
    },
  },
})

export const {
  panelOpened, panelClosed, conversationStarted, conversationRestored, messageSent, replyReceived, replyFailed, ticketRaised, conversationReset,
} = assistantSlice.actions
export default assistantSlice.reducer
```

In `store.ts`, add `assistant: assistantReducer` and subscribe to mirror storage:

```ts
store.subscribe(() => {
  const { sessionId, guestId } = store.getState().assistant
  writeAssistantStorage({ sessionId, guestId })
})
```

- [ ] **Step 4: Card schema**

`src/features/assistant/cards/cardSchema.ts`:

```ts
import { z } from 'zod'
import { TICKET_PRIORITIES, TICKET_STATUSES } from '@/features/tickets/filters/ticketFilters'

/** Mirrors the server's validated card union. Anything else becomes no card. */
export const cardSchema = z.discriminatedUnion('type', [
  z.object({ type: z.literal('none') }),
  z.object({ type: z.literal('sign_in_required') }),
  z.object({ type: z.literal('existing_ticket'), ticketId: z.string(), subject: z.string(), status: z.enum(TICKET_STATUSES), createdAt: z.string() }),
  z.object({
    type: z.literal('ticket_draft'),
    subject: z.string().min(5).max(120),
    description: z.string().min(10).max(4000),
    categoryId: z.string(),
    categoryName: z.string(),
    priority: z.enum(TICKET_PRIORITIES),
    reason: z.string(),
  }),
])

export const parseCard = (input: unknown) => {
  const result = cardSchema.safeParse(input)
  return result.success ? result.data : ({ type: 'none' } as const)
}
```

- [ ] **Step 5: API endpoints**

`src/api/assistant.api.ts`:

```ts
import { api } from './baseApi'
import type { AssistantCard, AssistantSession, Envelope, Paginated, Ticket } from './types'

export const assistantApi = api.injectEndpoints({
  endpoints: (build) => ({
    startConversation: build.mutation<{ session: AssistantSession; guestId: string | null; greeting: string }, void>({
      query: () => ({ url: '/assistant/conversations', method: 'POST' }),
      transformResponse: (r: Envelope<{ session: AssistantSession; guestId: string | null; greeting: string }>) => r.data,
    }),
    getConversation: build.query<AssistantSession, string>({
      query: (id) => `/assistant/conversations/${id}`,
      transformResponse: (r: Envelope<AssistantSession>) => r.data,
      providesTags: (_r, _e, id) => [{ type: 'Conversation', id }],
    }),
    sendMessage: build.mutation<{ reply: string; card: AssistantCard; session: AssistantSession }, { id: string; message: string }>({
      query: ({ id, message }) => ({ url: `/assistant/conversations/${id}/messages`, method: 'POST', body: { message } }),
      transformResponse: (r: Envelope<{ reply: string; card: AssistantCard; session: AssistantSession }>) => r.data,
    }),
    raiseTicket: build.mutation<{ ticket: Ticket; message: string; session: AssistantSession }, { id: string; subject: string; description: string; categoryId: string }>({
      query: ({ id, ...body }) => ({ url: `/assistant/conversations/${id}/tickets`, method: 'POST', body }),
      transformResponse: (r: Envelope<{ ticket: Ticket; message: string; session: AssistantSession }>) => r.data,
      invalidatesTags: ['TicketList', 'Summary'],
    }),
    claimConversation: build.mutation<AssistantSession, string>({
      query: (id) => ({ url: `/assistant/conversations/${id}/claim`, method: 'POST' }),
      transformResponse: (r: Envelope<AssistantSession>) => r.data,
    }),
    listConversations: build.query<Paginated<AssistantSession>, { page?: number; limit?: number; outcome?: string; participant?: string }>({
      query: (params) => ({ url: '/assistant/conversations', params }),
      providesTags: ['ConversationList'],
    }),
  }),
})

export const {
  useStartConversationMutation, useGetConversationQuery, useLazyGetConversationQuery, useSendMessageMutation,
  useRaiseTicketMutation, useClaimConversationMutation, useListConversationsQuery,
} = assistantApi
```

`src/api/knowledge.api.ts` with `listArticles` (params page, limit, search, includeRetired; tag Article LIST), `createArticle`, `updateArticle` (`{ id, title?, body?, keywords?, categoryId?: string | null, isActive? }`), both invalidating `Article LIST`, following the shape of `categories.api.ts`.

- [ ] **Step 6: Tests**

`assistantSlice.test.ts`:

```ts
import { describe, expect, it } from 'vitest'
import reducer, { conversationStarted, messageSent, replyFailed, replyReceived, ticketRaised } from './assistantSlice'

const start = () => reducer(undefined, conversationStarted({ sessionId: 's1', guestId: 'g1', greeting: 'Hello.' }))

describe('assistant slice', () => {
  it('starts with the greeting and keeps the guest id', () => {
    const state = start()
    expect(state.sessionId).toBe('s1')
    expect(state.guestId).toBe('g1')
    expect(state.messages.map((m) => m.text)).toEqual(['Hello.'])
  })

  it('adds the reply and drops a none card', () => {
    let state = reducer(start(), messageSent('hi'))
    expect(state.pending).toBe(true)
    state = reducer(state, replyReceived({ reply: 'Hi there.', card: { type: 'none' } }))
    expect(state.pending).toBe(false)
    expect(state.messages.at(-1)).toMatchObject({ role: 'assistant', text: 'Hi there.', card: null })
  })

  it('removes the optimistic bubble when the reply fails', () => {
    let state = reducer(start(), messageSent('hi'))
    state = reducer(state, replyFailed('Not available'))
    expect(state.messages).toHaveLength(1)
    expect(state.error).toBe('Not available')
  })

  it('closes the composer once a ticket is raised', () => {
    const state = reducer(start(), ticketRaised({ message: 'Raised.' }))
    expect(state.closed).toBe(true)
    expect(state.messages.at(-1)?.text).toBe('Raised.')
  })
})
```

`cardSchema.test.ts`:

```ts
import { describe, expect, it } from 'vitest'
import { parseCard } from './cardSchema'

describe('parseCard', () => {
  it('accepts each union member', () => {
    expect(parseCard({ type: 'none' })).toEqual({ type: 'none' })
    expect(parseCard({ type: 'sign_in_required' }).type).toBe('sign_in_required')
    expect(parseCard({ type: 'existing_ticket', ticketId: 't', subject: 's', status: 'open', createdAt: 'now' }).type).toBe('existing_ticket')
    expect(parseCard({ type: 'ticket_draft', subject: 'VPN drops', description: 'Since Monday it drops.', categoryId: 'c', categoryName: 'IT', priority: 'high', reason: 'r' }).type).toBe('ticket_draft')
  })

  it('downgrades anything else to none', () => {
    expect(parseCard({ type: 'ticket_draft', subject: 'x' })).toEqual({ type: 'none' })
    expect(parseCard(null)).toEqual({ type: 'none' })
  })
})
```

- [ ] **Step 7: Run and commit (user runs)**

```bash
npm run typecheck && npx oxlint src && npm test
```

```bash
git add src/api src/app/store.ts src/features/assistant && git commit -m "feat: assistant API layer, slice and card schema"
```

---

### Task 12 (web): The panel

**Files:**
- Create: `src/features/assistant/useAssistant.ts`, `AssistantLauncher.tsx`, `AssistantPanel.tsx`, `MessageList.tsx`, `Composer.tsx`, `cards/TicketDraftCard.tsx`, `cards/ExistingTicketCard.tsx`, `cards/SignInCard.tsx`
- Modify: `src/App.tsx`, `src/components/layout/navigation.ts`, `src/features/tickets/MyTicketsPage.tsx`, `src/features/landing/SiteFooter.tsx`, `src/features/landing/LandingPage.tsx`, `src/features/auth/LoginPage.tsx` (claim after login), `src/index.css`
- Delete: `src/features/tickets/NewTicketModal.tsx`

**Interfaces:**
- Produces: `useAssistant()` returning `{ open, openPanel, closePanel, messages, pending, error, closed, send(text), raise(input), ensureConversation() }`.

- [ ] **Step 1: The hook**

```ts
import { useCallback } from 'react'
import { useClaimConversationMutation, useLazyGetConversationQuery, useRaiseTicketMutation, useSendMessageMutation, useStartConversationMutation } from '@/api/assistant.api'
import { isApiError } from '@/api/types'
import { useAppDispatch, useAppSelector } from '@/app/hooks'
import { conversationReset, conversationRestored, conversationStarted, messageSent, panelClosed, panelOpened, replyFailed, replyReceived, ticketRaised } from './assistantSlice'
import { parseCard } from './cards/cardSchema'

const GREETING_FALLBACK = 'Hello. How can I help?'

/**
 * Everything the panel and the rail need. The conversation is created lazily
 * on first open, restored from the stored id on reload, and reset when the
 * server no longer recognises it.
 */
export function useAssistant() {
  const dispatch = useAppDispatch()
  const state = useAppSelector((s) => s.assistant)
  const [start] = useStartConversationMutation()
  const [load] = useLazyGetConversationQuery()
  const [send] = useSendMessageMutation()
  const [raise] = useRaiseTicketMutation()
  const [claim] = useClaimConversationMutation()

  const ensureConversation = useCallback(async (): Promise<string | null> => {
    if (state.sessionId && state.messages.length > 0) return state.sessionId
    if (state.sessionId) {
      try {
        const session = await load(state.sessionId).unwrap()
        dispatch(conversationRestored({
          sessionId: session.id,
          messages: (session.messages ?? []).map((m) => ({ ...m, card: m.card ? parseCard(m.card) : null })).map((m) => ({ ...m, card: m.card?.type === 'none' ? null : m.card })),
          closed: session.outcome !== 'open',
          greeting: GREETING_FALLBACK,
        }))
        return session.id
      } catch {
        dispatch(conversationReset())
      }
    }
    try {
      const started = await start().unwrap()
      dispatch(conversationStarted({ sessionId: started.session.id, guestId: started.guestId, greeting: started.greeting }))
      return started.session.id
    } catch {
      dispatch(replyFailed('The assistant is not available right now.'))
      return null
    }
  }, [dispatch, load, start, state.messages.length, state.sessionId])

  const openPanel = useCallback(() => {
    dispatch(panelOpened())
    void ensureConversation()
  }, [dispatch, ensureConversation])

  const sendText = useCallback(async (text: string) => {
    const id = await ensureConversation()
    if (!id) return
    dispatch(messageSent(text))
    try {
      const result = await send({ id, message: text }).unwrap()
      dispatch(replyReceived({ reply: result.reply, card: parseCard(result.card) }))
    } catch (error) {
      dispatch(replyFailed(isApiError(error) ? error.data.error.message : 'The assistant is not available right now.'))
    }
  }, [dispatch, ensureConversation, send])

  const raiseTicket = useCallback(async (input: { subject: string; description: string; categoryId: string }) => {
    if (!state.sessionId) throw new Error('No conversation')
    const result = await raise({ id: state.sessionId, ...input }).unwrap()
    dispatch(ticketRaised({ message: result.message }))
    return result.ticket
  }, [dispatch, raise, state.sessionId])

  const claimAfterLogin = useCallback(async () => {
    if (!state.sessionId || !state.guestId || state.closed) return
    await claim(state.sessionId).unwrap().catch(() => dispatch(conversationReset()))
  }, [claim, dispatch, state.closed, state.guestId, state.sessionId])

  return {
    open: state.open,
    messages: state.messages,
    pending: state.pending,
    error: state.error,
    closed: state.closed,
    openPanel,
    closePanel: () => dispatch(panelClosed()),
    send: sendText,
    raise: raiseTicket,
    ensureConversation,
    claimAfterLogin,
  }
}
```

- [ ] **Step 2: Components**

`AssistantLauncher.tsx`: a fixed `button.btn.btn-primary.btn-circle` at `bottom-5 right-5 z-30` with the `message` icon, `aria-label="Open the assistant"`, hidden while the panel is open. Uses `railTint` from navigation when a user is signed in by wrapping in a span with that class and `bg-[var(--rule)]`.

`AssistantPanel.tsx`: rendered only when `open`. On `md` and up a `fixed bottom-5 right-5 z-30 flex h-[min(40rem,calc(100vh-2.5rem))] w-[24rem] flex-col rounded-box border border-line bg-base-100 shadow-float`; on phones `inset-0 rounded-none w-full h-full`. Header with the title "Support assistant", a small "New conversation" ghost button (dispatches `conversationReset` then `ensureConversation`), and a close button. Body is `MessageList`. Footer is `Composer` or, when `closed`, a line "This conversation has ended." with a "Start a new one" button. Errors render as a `role="alert"` line above the composer with a Retry that resends the last text held in Composer state. `role="dialog"`, `aria-label="Support assistant"`, Escape closes.

`MessageList.tsx`: maps messages to the existing `chat` bubbles (`chat-start` assistant, `chat-end` user), renders the card under an assistant bubble by type using the three card components, shows a typing indicator (`loading loading-dots`) while pending, and scrolls to the bottom on change with a ref.

`Composer.tsx`: textarea with Enter to send and Shift+Enter for a newline, `aria-label="Message the assistant"`, Send button with the spinner while pending, disabled while pending.

`cards/TicketDraftCard.tsx`: card with a form: subject input, description textarea, category select (from `useListCategoriesQuery`, default `categoryId`), priority shown as `PriorityBadge` with the reason underneath, submit button "Raise this ticket". zod schema: subject 5..120, description 10..4000, categoryId min 1. On success shows a link to `/tickets/{id}`. Server `VALIDATION_FAILED` details map to fields; other errors become a root error. Once submitted, the form is replaced by a "Raised" line so it cannot submit twice.

`cards/ExistingTicketCard.tsx`: subject, `StatusBadge`, "Raised {timeAgo}", and a `Link` to `/tickets/{ticketId}` "Open the ticket".

`cards/SignInCard.tsx`: two buttons. "Sign in" links to `/login` with `state.from` set to the current location. "Create an account" toggles an inline note: "Accounts are created by an administrator. Ask your IT contact, or email the desk at helpdesk@example.com."

Every card component takes the narrowed card type from `AssistantCard` as its prop.

- [ ] **Step 3: Mount and wire**

`App.tsx`:

```tsx
import { RouterProvider } from 'react-router-dom'
import { router } from './app/router'
import { useSessionBootstrap } from './features/auth/useSessionBootstrap'
import { AssistantLauncher } from './features/assistant/AssistantLauncher'
import { AssistantPanel } from './features/assistant/AssistantPanel'

/** Restores the session once, then hands over to the router. The assistant sits beside the router so navigation never unmounts it. */
export function App() {
  useSessionBootstrap()
  return (
    <>
      <RouterProvider router={router} />
      <AssistantLauncher />
      <AssistantPanel />
    </>
  )
}
```

The panel uses `Link` from react-router, which needs router context. Because it is outside `RouterProvider`, replace `Link` in the assistant components with plain anchors that call `router.navigate(href)` on click; expose a tiny helper `navigateTo(href: string)` in `src/app/router.tsx` that wraps `router.navigate`, and use it in the cards and the sign-in card.

`navigation.ts`: the user role's second item becomes `{ label: 'Ask for help', to: '#assistant', icon: 'message' }`; in `NavMenu`, an item whose `to` starts with `#` renders a `button` that calls `openPanel()` from `useAssistant()` instead of a `NavLink`.

`MyTicketsPage.tsx`: remove the modal import, the `creating` state, the header button and the `?new=1` handling. The empty state's action becomes a button "Ask the assistant" that opens the panel. Delete `NewTicketModal.tsx`.

`SiteFooter.tsx`: "Raise a ticket" becomes a button that opens the panel. `LandingPage.tsx`: the user card link "Raise a ticket" does the same.

`LoginPage.tsx`: after `grantsLoaded`, call `claimAfterLogin()` from `useAssistant()` before navigating.

`index.css`: add nothing unless a shared class is needed; the panel uses utilities and existing tokens.

- [ ] **Step 4: Verify in the browser**

With the API running with a real `ANTHROPIC_API_KEY`:

1. Guest on `/`: open the launcher, ask "What are the support hours?" and get an answer citing the article. Ask "I need to raise a ticket" and get the sign-in card. Navigate to `/privacy` and back: the conversation is still there. Reload: still there.
2. Click Sign in on the card, sign in as jordan: the panel keeps the messages and the network shows `POST .../claim` 200.
3. As jordan on `/my-tickets`: no New ticket button. "Ask for help" in the rail opens the panel. Describe "my VPN drops every hour since Monday, I cannot join calls" and get the draft card; edit the subject; submit; the confirmation line appears with a link; the ticket is in Your tickets with the drafted priority.
4. Ask about the same VPN issue again in a new conversation: the existing-ticket card appears with a link.
5. As sam, open that ticket: the collapsed transcript block is there (Task 13).

- [ ] **Step 5: Run and commit (user runs)**

```bash
npm run typecheck && npx oxlint src && npm test && npm run build
```

```bash
git add src/App.tsx src/app/router.tsx src/components/layout src/features/assistant src/features/tickets src/features/landing src/features/auth/LoginPage.tsx && git rm -q src/features/tickets/NewTicketModal.tsx && git commit -m "feat: assistant panel on every page; users raise tickets through it"
```

---

### Task 13 (web): Transcript on the ticket

**Files:**
- Modify: `src/features/tickets/TicketDetailPage.tsx`

- [ ] **Step 1: Render**

Above the first comment, when `ticket.transcript` is present and non-empty, render a `collapse collapse-arrow border border-line bg-base-100` with the title "Conversation with the assistant ({n} messages)" and the transcript as compact chat bubbles (assistant left, requester right), cards shown as a one-line summary ("Draft: {subject}", "Pointed at ticket {subject}", "Asked to sign in"). When `ticket.source === 'assistant'` and there is no transcript (the requester's view), show a small `badge badge-ghost` "Raised through the assistant" beside the header badges.

- [ ] **Step 2: Verify and commit (user runs)**

Open an assistant-raised ticket as sam and as jordan; confirm the block and the badge respectively.

```bash
git add src/features/tickets/TicketDetailPage.tsx && git commit -m "feat: show the assistant transcript on tickets it raised"
```

---

### Task 14 (web): Admin screens

**Files:**
- Create: `src/features/admin/KnowledgePage.tsx`, `src/features/admin/ConversationsPage.tsx`, `src/features/admin/ConversationPage.tsx`
- Modify: `src/app/router.tsx`, `src/components/layout/navigation.ts`, `src/features/admin/AdminOverviewPage.tsx`, `src/components/ui/Badge.tsx` (an `OutcomeBadge`)

- [ ] **Step 1: Knowledge page** at `/admin/knowledge` behind `RequirePermission permission="knowledge:manage"`: search box, "Include retired" checkbox, table (Title, Category, Status, Updated, Actions) with `data-testid="knowledge-table"`, New article button opening a modal (title, body textarea, keywords, category select with "No category"), Edit in the same modal prefilled, Retire and Restore row actions with a ConfirmDialog for retire. Pagination as on Accounts.

- [ ] **Step 2: Conversations pages** at `/admin/conversations` and `/admin/conversations/:id` behind `metrics:read`: table (Participant, Started, Turns, Outcome badge, Last agent, Ticket link) with an outcome filter select in the URL, row links to the detail; detail page shows the transcript read-only with the same bubbles as the panel and a sidebar with outcome, turns, tokens, participant and the ticket link.

`OutcomeBadge`: `open` neutral, `answered` success, `ticket_raised` info, `abandoned` ghost, labels "Open", "Answered", "Ticket raised", "Abandoned".

- [ ] **Step 3: Overview block**: under the existing stats add a second `stats` row "Assistant, last 30 days": Conversations, Answered by the assistant, Tickets raised, Deflection (percent, or "No data").

- [ ] **Step 4: Rail**: Admin "Manage" gains `{ label: 'Knowledge', to: '/admin/knowledge', icon: 'book' }` and `{ label: 'Conversations', to: '/admin/conversations', icon: 'message' }`. Add a `book` symbol to `public/icons/sprite.svg` and to `IconName`.

- [ ] **Step 5: Verify, run, commit (user runs)**

Browser: create, edit, retire and restore an article; open the conversations list filtered by outcome; open a transcript; see the overview numbers.

```bash
npm run typecheck && npx oxlint src && npm test && npm run build
```

```bash
git add src/features/admin src/app/router.tsx src/components public/icons/sprite.svg && git commit -m "feat: admin knowledge base, conversations browser and assistant metrics"
```

---

### Task 15 (web): Docs

**Files:**
- Modify: `README.md`, `docs/design.md`

- [ ] **Step 1: README**: routes table gains `/admin/knowledge`, `/admin/conversations`, `/admin/conversations/:id`; remove the `?new=1` mention; add an "Assistant" section under "How it fits together" explaining the slice, the localStorage mirror, the guest header, the claim on login, and that the panel is mounted beside the router. Add `assistant/` to the structure listing. In "Not built", add "Streaming replies" and "Self-registration".

- [ ] **Step 2: Design doc**: add an "Assistant panel" section under Screens (sizes, position, bubbles, the three cards, the launcher tint) and the two new admin screens in the table.

- [ ] **Step 3: Commit (user runs)**

```bash
git add README.md docs/design.md && git commit -m "docs: describe the assistant and the new admin screens"
```

---

## Self-review

**Spec coverage.** Placement and persistence: Tasks 11, 12. Knowledge base and admin table: Tasks 3, 14. Tracking: Tasks 9, 14. Turn-based replies: Task 7. Manual creation removed for users: Tasks 4, 12. Anthropic only, Haiku: Task 1 and every agent attribute. Three agents and their tools: Tasks 5, 6. Card union and server validation: Task 6. Draft, confirm, create with the drafted priority and the fixed message: Task 8. Duplicate detection: Tasks 5, 6, 12. Guests, header, claim: Tasks 2, 8, 12. Transcript on the ticket: Tasks 8, 13. Endpoints and rate limits: Tasks 7, 8, 9. Errors: Task 7 (502, 409) and Task 12 (panel). Settlement schedule: Task 9. Tests with fakes and the live test: Tasks 6 to 10. Docs and Postman: Tasks 10, 15.

**Type consistency.** `AssistantSession::isWith()` is used in Tasks 7 and 8 and defined in Task 5. `CreateTicket::createFor()` defined in Task 4, used in Task 8. `AssistantTranscript::for()` defined in Task 7, used in Task 8. `AssistantParticipant::HEADER` defined in Task 2, used in Tasks 7, 8. Card shapes in `CardValidator` (Task 6) match `AssistantCard` (Task 11) and `cardSchema` (Task 11). `RaiseTicketFromConversation::CONFIRMATION` defined in Task 8, used in its tests and echoed in the response the slice consumes.

**Known checks left to the implementer** (each is called out inline where it applies): the `Request` constructor of the SDK tool request, whether `StructuredAgentResponse` supports array access, the `ConversationMessage` fillable and casts, how `JsonResource::additional()` nests, and the `enum()` method name on the schema string type.
