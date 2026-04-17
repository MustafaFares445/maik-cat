---
description: API CRUD standards (Spatie Data, Spatie Permissions, Service, Policy, Filters, PHPUnit)
globs: app/Http/Controllers/API/**/*.php, app/Services/**/*.php, app/Data/**/*.php, app/Policies/**/*.php, app/Http/Requests/**/*.php, app/Traits/FilterQueries/**/*.php, app/Http/Resources/**/*.php, app/Enums/PermissionGroup.php, app/Enums/PermissionAction.php, database/seeders/**/*.php, tests/Feature/**/*.php
alwaysApply: false
---

# API CRUD Standards

All API and domain code lives under the standard Laravel structure: `app/`, `routes/api.php`, `database/seeders/`, `tests/Feature/`.

---

## 1. Routes

- **Where**: `routes/api.php`
- **How**: One `Route::apiResource()` per resource inside `auth:sanctum` middleware; explicit `->names()` so route names mirror the permission naming convention `{resource}.{action}`. Non-CRUD and nested sub-resource routes are added manually alongside the resource declaration.

```php
// routes/api.php

Route::middleware(['auth:sanctum'])->group(function () {

    Route::apiResource('users', App\Http\Controllers\API\UserController::class)->names([
        'index'   => 'users.index',
        'store'   => 'users.store',
        'show'    => 'users.show',
        'update'  => 'users.update',
        'destroy' => 'users.destroy',
    ]);

    // Non-CRUD flat (two layers)
    Route::patch('users/sort', [UserController::class, 'sort'])
        ->name('users.sort');

    Route::post('users/{user}/ban', [UserController::class, 'ban'])
        ->name('users.ban');

    // Nested sub-resource (three layers) — name echoes the permission name
    Route::get('users/{user}/posts', [UserController::class, 'posts'])
        ->name('users.posts.index');

    Route::post('users/{user}/posts', [UserController::class, 'storePost'])
        ->name('users.posts.store');

});
```

Route name → permission mapping:

| Route name            | Layers | Permission checked       |
|-----------------------|--------|--------------------------|
| `users.index`         | 2      | `users.view`             |
| `users.show`          | 2      | `users.view`             |
| `users.store`         | 2      | `users.create`           |
| `users.update`        | 2      | `users.update`           |
| `users.destroy`       | 2      | `users.delete`           |
| `users.sort`          | 2      | `users.sort`             |
| `users.ban`           | 2      | `users.ban`              |
| `users.posts.index`   | 3      | `users.posts.view`       |
| `users.posts.store`   | 3      | `users.posts.create`     |

> **Route name ↔ permission name**: nested routes (`users.posts.index`, `users.posts.store`) echo the three-layer permission names (`users.posts.view`, `users.posts.create`) — only the terminal segment differs (`index`/`store` vs `view`/`create`) to stay consistent with Laravel conventions.

---

## 2. API Controller

- **Where**: `App\Http\Controllers\API\{Model}Controller`
- **Requirements**:
  - `declare(strict_types=1)`; explicit return types.
  - Call `$this->authorizeResource(Model::class, 'model_param')` in the constructor — wires all five standard policy methods automatically.
  - Inject `{Model}Service` in the constructor alongside `authorizeResource`.
  - `index`: type-hint `{Model}FilterRequest`; use `Model::getQuery()->paginate($request->get('per_page', 20))`; return `Resource::collection($paginator)`.
  - `store`: type-hint Form Request; call `$this->service->store(Data::from($request->validated()))`; return `Resource::make($model)`.
  - `show`: route-bound `Model $model`; return `Resource::make($model)`.
  - `update`: Form Request + route-bound `Model $model`; call `$this->service->update(...)`; return resource.
  - `destroy`: route-bound `Model $model`; `$model->delete()`; return `response()->noContent()`.
  - Non-CRUD actions (`sort`, `ban`, etc.): call `$this->authorize(PermissionAction::CASE->value, ...)` explicitly — `authorizeResource()` does **not** wire these.
  - No business logic in the controller.

```php
declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Data\UserData;
use App\Enums\PermissionAction;
use App\Http\Requests\UserRequests\UserBanRequest;
use App\Http\Requests\UserRequests\UserFilterRequest;
use App\Http\Requests\UserRequests\UserRequest;
use App\Http\Requests\UserRequests\UserSortRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;

class UserController
{
    public function __construct(protected UserService $userService)
    {
        $this->authorizeResource(User::class, 'user');
    }

    public function index(UserFilterRequest $request): AnonymousResourceCollection
    {
        $users = User::getQuery()->paginate($request->get('per_page', 20));
        return UserResource::collection($users);
    }

    public function store(UserRequest $request): UserResource
    {
        $user = $this->userService->store(UserData::from($request->validated()));
        return UserResource::make($user);
    }

    public function show(User $user): UserResource
    {
        return UserResource::make($user);
    }

    public function update(UserRequest $request, User $user): UserResource
    {
        $updatedUser = $this->userService->update(UserData::from($request->validated()), $user);
        return UserResource::make($updatedUser);
    }

    public function destroy(User $user): Response
    {
        $user->delete();
        return response()->noContent();
    }

    // -------------------------------------------------------------------------
    // Non-CRUD — explicit authorize() required; authorizeResource() does not wire these
    // -------------------------------------------------------------------------

    /** Bulk reorder — no model instance, authorize against the class */
    public function sort(UserSortRequest $request): JsonResponse
    {
        $this->authorize(PermissionAction::SORT->value, User::class);

        $this->userService->sort($request->validated('order'));

        return response()->json(['message' => 'Order updated.']);
    }

    /** Ban a specific user */
    public function ban(UserBanRequest $request, User $user): UserResource
    {
        $this->authorize(PermissionAction::BAN->value, $user);

        $bannedUser = $this->userService->ban($user, UserBanData::from($request->validated()));

        return UserResource::make($bannedUser);
    }
}
```

---

## 3. Data (DTO)

- **Where**: `App\Data\{Model}Data`
- **Requirements**:
  - Extend `Spatie\LaravelData\Data`.
  - Constructor: promoted properties only; use Spatie validation attributes (`#[Max(255)]`, `#[Unique(...)]`, `#[Date]`, `#[Exists('table','column')]`, etc.).
  - Property names: **snake_case**, matching model column names directly.
  - Nullable types for optional fields.

```php
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Attributes\Validation\Date;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Unique;

class UserData extends Data
{
    public function __construct(
        #[Max(255)]
        public ?string $name,
        #[Max(255), Unique('users', 'email')]
        public ?string $email,
        #[Date]
        public ?Carbon $email_verified_at,
        #[Max(255)]
        public ?string $password,
    ) {}
}
```

---

## 4. Form Request (store/update)

- **Where**: `App\Http\Requests\{Model}Requests\{Model}Request`
  - Split into `{Model}StoreRequest` / `{Model}UpdateRequest` when store and update rules diverge significantly.
- **Requirements**:
  - `authorize()` returns `true` — authorization is handled entirely by the Policy via `authorizeResource`.
  - `rules(): array` keys are **snake_case** matching payload and model columns.

```php
class UserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'              => 'string|max:255',
            'email'             => 'string|max:255|unique:users,email',
            'email_verified_at' => 'date',
            'password'          => 'string|max:255',
        ];
    }
}
```

---

## 5. Filter Request (index)

- **Where**: `App\Http\Requests\{Model}Requests\{Model}FilterRequest`
- **Requirements**:
  - `authorize()` returns `true`.
  - `per_page` `sometimes|integer|min:1|max:100`; `filter.{field}` keys in **snake_case**; `sort` with `in:` listing allowed sort columns in snake_case, prefixed with `-` for descending.

```php
class UserFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'per_page'                 => 'sometimes|integer|min:1|max:100',
            'search'                   => 'sometimes|string|max:255',
            'filter.name'              => 'sometimes|string|max:255',
            'filter.email'             => 'sometimes|email|max:255',
            'filter.email_verified_at' => 'sometimes|date',
            'filter.created_after'     => 'sometimes|date',
            'filter.created_before'    => 'sometimes|date|after_or_equal:filter.created_after',
            'filter.search'            => 'sometimes|string|max:255',
            'sort'                     => 'sometimes|string|in:name,-name,email,-email,email_verified_at,-email_verified_at,created_at,-created_at',
        ];
    }
}
```

---

## 6. Service

- **Where**: `App\Services\{Model}Service`
- **Requirements**:
  - `store({Model}Data $data): Model` — `DB::transaction()`; `Model::create([...])` with explicit column mapping; return created model.
  - `update({Model}Data $data, Model $model): Model` — `DB::transaction()`; `tap($model)->update([...])`; return updated model.
  - Non-CRUD methods (e.g. `sort`, `ban`) also wrap in `DB::transaction()`.
  - No `::query()` before `create()`.

```php
class UserService
{
    public function store(UserData $data): User
    {
        return DB::transaction(static function () use ($data) {
            return User::create([
                'name'              => $data->name,
                'email'             => $data->email,
                'email_verified_at' => $data->email_verified_at,
                'password'          => bcrypt($data->password),
            ]);
        });
    }

    public function update(UserData $data, User $user): User
    {
        return DB::transaction(static function () use ($data, $user) {
            tap($user)->update([
                'name'              => $data->name,
                'email'             => $data->email,
                'email_verified_at' => $data->email_verified_at,
            ]);
            return $user;
        });
    }

    /** Persist a new display order for a batch of users. */
    public function sort(array $orderedIds): void
    {
        DB::transaction(static function () use ($orderedIds) {
            foreach ($orderedIds as $position => $id) {
                User::where('id', $id)->update(['sort_order' => $position]);
            }
        });
    }
}
```

---

## 7. Policy

- **Where**: `App\Policies\{Model}Policy`
- **Requirements**:
  - No external traits; pure Spatie permission checks.
  - All permission strings produced via `PermissionGroup::resolve()` with `PermissionAction` enum values — **never hardcoded strings**.
  - Standard methods: `viewAny`, `view`, `create`, `update`, `delete`.
  - Non-CRUD methods (`sort`, `ban`, etc.) added per resource and called explicitly via `$this->authorize()` in the controller.
  - Nested sub-resource methods (three-layer names) use `PermissionGroup::resolve($parent, $action, $child)`.
  - Register in `AuthServiceProvider` via the `$policies` array — **never** use `Gate::policy()` calls in `AppServiceProvider`.

```php
declare(strict_types=1);

namespace App\Policies;

use App\Enums\PermissionAction;
use App\Enums\PermissionGroup;
use App\Models\User;

class UserPolicy
{
    // -----------------------------------------------------------------
    // Standard CRUD — wired automatically by authorizeResource()
    // -----------------------------------------------------------------

    public function viewAny(User $user): bool
    {
        return $user->can(PermissionGroup::resolve(PermissionGroup::USERS, PermissionAction::VIEW));
    }

    public function view(User $user, User $model): bool
    {
        return $user->can(PermissionGroup::resolve(PermissionGroup::USERS, PermissionAction::VIEW));
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionGroup::resolve(PermissionGroup::USERS, PermissionAction::CREATE));
    }

    public function update(User $user, User $model): bool
    {
        return $user->can(PermissionGroup::resolve(PermissionGroup::USERS, PermissionAction::UPDATE));
    }

    public function delete(User $user, User $model): bool
    {
        return $user->can(PermissionGroup::resolve(PermissionGroup::USERS, PermissionAction::DELETE));
    }

    // -----------------------------------------------------------------
    // Non-CRUD actions — called explicitly via $this->authorize()
    // -----------------------------------------------------------------

    /** Bulk reorder — authorize against the class, not an instance */
    public function sort(User $user): bool
    {
        return $user->can(PermissionGroup::resolve(PermissionGroup::USERS, PermissionAction::SORT));
    }

    /** Ban a specific user */
    public function ban(User $user, User $model): bool
    {
        return $user->can(PermissionGroup::resolve(PermissionGroup::USERS, PermissionAction::BAN));
    }

    // -----------------------------------------------------------------
    // Nested sub-resource actions (three-layer names)
    // -----------------------------------------------------------------

    /** List/show posts belonging to a user → "users.posts.view" */
    public function viewPosts(User $user, User $model): bool
    {
        return $user->can(
            PermissionGroup::resolve(PermissionGroup::USERS, PermissionAction::VIEW, PermissionGroup::POSTS)
        );
    }

    /** Create a post under a user → "users.posts.create" */
    public function createPost(User $user, User $model): bool
    {
        return $user->can(
            PermissionGroup::resolve(PermissionGroup::USERS, PermissionAction::CREATE, PermissionGroup::POSTS)
        );
    }
}
```

**Register every policy in `AuthServiceProvider`:**

```php
declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use App\Policies\UserPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    protected $policies = [
        User::class => UserPolicy::class,
        // Add one entry per resource:
        // Post::class => PostPolicy::class,
    ];

    public function boot(): void
    {
        $this->registerPolicies();

        // Super-admin bypass — defined once here, applies to every policy globally.
        Gate::before(function (User $user, string $ability): ?bool {
            if ($user->hasRole('super_admin')) {
                return true;
            }
            return null;
        });
    }
}
```

> **Why `$policies` over `Gate::policy()` calls?** The `$policies` array is declarative and lives in one dedicated file — every model-to-policy mapping is visible at a glance. `Gate::policy()` calls buried in `AppServiceProvider::boot()` mix authorization concerns with general bootstrapping and are easy to miss when auditing permissions.

---

## 8. PermissionAction Enum

- **Where**: `app/Enums/PermissionAction.php`
- **Purpose**: Single source of truth for every action string. Replaces all bare action strings in groups, policies, seeders, and controllers.
- **Adding a custom action**: add one `case` here, then reference it in the relevant `PermissionGroup::actions()` override.

```php
declare(strict_types=1);

namespace App\Enums;

enum PermissionAction: string
{
    // Standard CRUD + sort
    case VIEW   = 'view';
    case CREATE = 'create';
    case UPDATE = 'update';
    case DELETE = 'delete';
    case SORT   = 'sort';   // reorder / drag-and-drop endpoints

    // Custom (non-CRUD) actions — add one case per action as the app grows
    case ASSIGN  = 'assign';
    case BAN     = 'ban';
    case APPROVE = 'approve';
    case PUBLISH = 'publish';

    /** Human-readable label for admin UIs / logs. */
    public function label(): string
    {
        return match ($this) {
            self::VIEW    => 'View',
            self::CREATE  => 'Create',
            self::UPDATE  => 'Update',
            self::DELETE  => 'Delete',
            self::SORT    => 'Sort',
            self::ASSIGN  => 'Assign',
            self::BAN     => 'Ban',
            self::APPROVE => 'Approve',
            self::PUBLISH => 'Publish',
        };
    }

    /**
     * Default CRUD + sort set shared by most resources.
     *
     * @return PermissionAction[]
     */
    public static function defaults(): array
    {
        return [self::VIEW, self::CREATE, self::UPDATE, self::DELETE, self::SORT];
    }
}
```

---

## 9. PermissionGroup Enum

- **Where**: `app/Enums/PermissionGroup.php`
- **Purpose**: Single source of truth for every resource group. `actions()` returns `PermissionAction[]`. `resolve()` accepts an optional `$subGroup` for two-or-three-layer permission names. Policies and seeders both derive names from this enum — no hardcoded strings anywhere else.
- **Adding a standard resource**: add one `case`; update `label()` and `sortOrder()`. The seeder picks it up automatically.
- **Adding a non-CRUD action**: add a `PermissionAction` case (§ 8), then override `actions()` for the relevant group.
- **Adding a nested resource**: pass the child `PermissionGroup` as `$subGroup` to `resolve()`.

```php
declare(strict_types=1);

namespace App\Enums;

enum PermissionGroup: string
{
    case USERS = 'users';
    case ROLES = 'roles';
    case POSTS = 'posts';
    // Add one case per resource as the app grows.

    /**
     * Ordered actions available for this group.
     * Uses PermissionAction::defaults() for standard resources.
     * Override per-case to add or remove actions.
     *
     * @return PermissionAction[]
     */
    public function actions(): array
    {
        return match ($this) {
            self::ROLES => [
                PermissionAction::VIEW,
                PermissionAction::CREATE,
                PermissionAction::UPDATE,
                PermissionAction::DELETE,
                PermissionAction::SORT,
                PermissionAction::ASSIGN,
            ],
            self::USERS => [
                PermissionAction::VIEW,
                PermissionAction::CREATE,
                PermissionAction::UPDATE,
                PermissionAction::DELETE,
                PermissionAction::SORT,
                PermissionAction::BAN,
            ],
            default => PermissionAction::defaults(),
        };
    }

    /** Human-readable label for admin UIs. */
    public function label(): string
    {
        return match ($this) {
            self::USERS => 'Users',
            self::ROLES => 'Roles',
            self::POSTS => 'Posts',
        };
    }

    /**
     * Display order for admin UIs and sorted permission lists.
     * Lower value = appears first.
     */
    public function sortOrder(): int
    {
        return match ($this) {
            self::USERS => 1,
            self::ROLES => 2,
            self::POSTS => 3,
            default     => 99,
        };
    }

    /**
     * All cases sorted by sortOrder() for consistent display.
     *
     * @return PermissionGroup[]
     */
    public static function sorted(): array
    {
        $cases = self::cases();
        usort($cases, fn (self $a, self $b) => $a->sortOrder() <=> $b->sortOrder());
        return $cases;
    }

    /**
     * Resolve the full permission string (two or three layers).
     *
     * Two layers:   PermissionGroup::resolve(self::USERS, PermissionAction::BAN)
     *               → "users.ban"
     *
     * Three layers: PermissionGroup::resolve(self::USERS, PermissionAction::VIEW, self::POSTS)
     *               → "users.posts.view"
     */
    public static function resolve(
        self             $group,
        PermissionAction $action,
        ?self            $subGroup = null,
    ): string {
        return $subGroup !== null
            ? "{$group->value}.{$subGroup->value}.{$action->value}"
            : "{$group->value}.{$action->value}";
    }
}
```

---

## 10. Permissions & Roles Seeders

### PermissionsSeeder

- **Where**: `database/seeders/PermissionsSeeder.php`
- Loops `PermissionGroup::cases()` for flat permissions and an explicit `$nested` map for three-layer permissions.
- Always call `resolve(PermissionRegistrar::class)->forgetCachedPermissions()` at the top.

```php
declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\PermissionGroup;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class PermissionsSeeder extends Seeder
{
    public function run(): void
    {
        resolve(PermissionRegistrar::class)->forgetCachedPermissions();

        // Flat permissions: {resource}.{action}
        foreach (PermissionGroup::cases() as $group) {
            foreach ($group->actions() as $action) {
                Permission::firstOrCreate([
                    'name'       => PermissionGroup::resolve($group, $action),
                    'guard_name' => 'sanctum',
                ]);
            }
        }

        // Nested permissions: {resource}.{sub-resource}.{action}
        $nested = [
            PermissionGroup::USERS => [PermissionGroup::POSTS],
            // Add further nesting here as the app grows.
        ];

        foreach ($nested as $parent => $children) {
            foreach ($children as $child) {
                foreach ($child->actions() as $action) {
                    Permission::firstOrCreate([
                        'name'       => PermissionGroup::resolve($parent, $action, $child),
                        'guard_name' => 'sanctum',
                    ]);
                }
            }
        }
    }
}
```

### RolesSeeder

- **Where**: `database/seeders/RolesSeeder.php`
- Must run **after** `PermissionsSeeder`.
- Keep `super_admin` even though `Gate::before` bypasses checks — useful for introspection and admin UIs.

```php
declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesSeeder extends Seeder
{
    public function run(): void
    {
        resolve(PermissionRegistrar::class)->forgetCachedPermissions();

        // Super admin — Gate::before grants access; permissions assigned for introspection
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'sanctum'])
            ->syncPermissions(Permission::all());

        // Viewer — read-only across all resources (flat and nested)
        Role::firstOrCreate(['name' => 'viewer', 'guard_name' => 'sanctum'])
            ->syncPermissions(Permission::where('name', 'like', '%.view')->get());

        // Scoped roles — uncomment / duplicate as needed
        // Role::firstOrCreate(['name' => 'user_manager', 'guard_name' => 'sanctum'])
        //     ->syncPermissions(Permission::where('name', 'like', 'users.%')->get());
    }
}
```

### DatabaseSeeder — call order

```php
$this->call([
    PermissionsSeeder::class, // always first
    RolesSeeder::class,
]);
```

---

## 11. Filter Query Trait

- **Where**: `App\Traits\FilterQueries\{Model}FilterQuery`
- **Requirements**:
  - Static `getQuery(): QueryBuilder`; all filter and sort field names **snake_case**.
  - `AllowedFilter::scope()` name matches the `filter.{name}` key in FilterRequest.
  - `AllowedSort::field('sort_order')` included when the model supports manual ordering.
  - Model scopes (`scopeCreatedAfter`, `scopeCreatedBefore`, `scopeSearch`) defined on the model.
  - Model must `use {Model}FilterQuery`.

```php
use Illuminate\Database\Eloquent\Builder;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

trait UserFilterQuery
{
    public static function getQuery(): QueryBuilder
    {
        return QueryBuilder::for(User::class)
            ->allowedFilters([
                AllowedFilter::partial('name'),
                AllowedFilter::partial('email'),
                AllowedFilter::exact('email_verified_at'),
                AllowedFilter::scope('created_after'),
                AllowedFilter::scope('created_before'),
                AllowedFilter::scope('search'),
            ])
            ->allowedSorts([
                AllowedSort::field('name'),
                AllowedSort::field('email'),
                AllowedSort::field('email_verified_at'),
                AllowedSort::field('sort_order'),  // manual ordering via users.sort endpoint
                AllowedSort::field('created_at'),
            ])
            ->defaultSort('-created_at');
    }

    public function scopeCreatedAfter(Builder $query, string $date): Builder
    {
        return $query->where('created_at', '>=', $date);
    }

    public function scopeCreatedBefore(Builder $query, string $date): Builder
    {
        return $query->where('created_at', '<=', $date);
    }

    public function scopeSearch(Builder $query, string $search): Builder
    {
        $safe = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
        return $query->where(function (Builder $q) use ($safe) {
            $q->where('name', 'like', "%{$safe}%")
              ->orWhere('email', 'like', "%{$safe}%");
        });
    }
}
```

---

## 12. API Resource

- **Where**: `App\Http\Resources\{Model}Resource`
- **Requirements**:
  - Extend `JsonResource`; `@mixin Model` PHPDoc.
  - `toArray()` returns **snake_case** keys; use `$this->whenLoaded('relation')` for relations.

```php
class UserResource extends JsonResource
{
    /** @mixin User */
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'name'              => $this->name,
            'email'             => $this->email,
            'email_verified_at' => $this->email_verified_at,
            'sort_order'        => $this->sort_order,
            'created_at'        => $this->created_at->toDateTimeString(),
            'updated_at'        => $this->updated_at->toDateTimeString(),
        ];
    }
}
```

---

## 13. Factory

- **Where**: `database/factories/{Model}Factory.php`
- snake_case keys; match fillable columns and types.

```php
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'name'               => fake()->name(),
            'email'              => fake()->unique()->safeEmail(),
            'email_verified_at'  => fake()->dateTime(),
            'password'           => bcrypt('password'),
            'sort_order'         => 0,
        ];
    }
}
```

---

## 14. Unit Tests (Service layer)

- **Where**: `tests/Unit/{Model}/{Model}ServiceTest.php`
- **Purpose**: Test the Service class in isolation — happy paths, sad paths, edge cases, transaction rollback.
- **Requirements**:
  - Extend `Tests\TestCase` (uses `RefreshDatabase`).
  - Arrange / Act / Assert structure with clear inline comments.
  - Cover every public method: `store`, `update`, and non-CRUD methods (`sort`, `ban`, etc.).
  - **Happy path**: assert the returned model has the correct attributes and is persisted.
  - **Sad path**: assert that invalid data throws the expected exception and leaves the database unchanged.
  - **Transaction rollback**: mock a failure mid-transaction and assert no record is created.

```php
declare(strict_types=1);

namespace Tests\Unit\User;

use App\Data\UserData;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class UserServiceTest extends TestCase
{
    use RefreshDatabase;

    private UserService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new UserService();
    }

    // -------------------------------------------------------------------------
    // store — happy path
    // -------------------------------------------------------------------------

    public function test_store_creates_user_with_correct_attributes(): void
    {
        // Arrange
        $data = new UserData(
            name: 'Jane Doe',
            email: 'jane@example.com',
            email_verified_at: null,
            password: 'secret123',
        );

        // Act
        $user = $this->service->store($data);

        // Assert
        $this->assertInstanceOf(User::class, $user);
        $this->assertNotNull($user->id);
        $this->assertSame('Jane Doe', $user->name);
        $this->assertSame('jane@example.com', $user->email);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => 'jane@example.com']);
    }

    // -------------------------------------------------------------------------
    // store — sad paths
    // -------------------------------------------------------------------------

    public function test_store_rolls_back_when_exception_is_thrown(): void
    {
        // Arrange
        DB::shouldReceive('transaction')->once()->andThrow(new RuntimeException('db error'));

        // Act & Assert
        $this->expectException(RuntimeException::class);

        $this->service->store(new UserData(
            name: 'Fail User',
            email: 'fail@example.com',
            email_verified_at: null,
            password: 'secret',
        ));

        $this->assertDatabaseMissing('users', ['email' => 'fail@example.com']);
    }

    public function test_store_does_not_persist_when_duplicate_email_exists(): void
    {
        // Arrange
        User::factory()->create(['email' => 'taken@example.com']);

        // Act & Assert
        $this->expectException(\Illuminate\Database\QueryException::class);

        $this->service->store(new UserData(
            name: 'Duplicate',
            email: 'taken@example.com',
            email_verified_at: null,
            password: 'secret',
        ));

        $this->assertDatabaseCount('users', 1);
    }

    // -------------------------------------------------------------------------
    // update — happy path
    // -------------------------------------------------------------------------

    public function test_update_changes_user_attributes_and_persists(): void
    {
        // Arrange
        $user = User::factory()->create(['name' => 'Old Name', 'email' => 'old@example.com']);
        $data = new UserData(
            name: 'New Name',
            email: 'new@example.com',
            email_verified_at: null,
            password: null,
        );

        // Act
        $updated = $this->service->update($data, $user);

        // Assert
        $this->assertSame($user->id, $updated->id);
        $this->assertSame('New Name', $updated->name);
        $this->assertSame('new@example.com', $updated->email);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'New Name', 'email' => 'new@example.com']);
    }

    // -------------------------------------------------------------------------
    // update — sad paths
    // -------------------------------------------------------------------------

    public function test_update_rolls_back_when_exception_is_thrown(): void
    {
        // Arrange
        $user = User::factory()->create(['name' => 'Original']);
        DB::shouldReceive('transaction')->once()->andThrow(new RuntimeException('db error'));

        // Act & Assert
        $this->expectException(RuntimeException::class);

        $this->service->update(new UserData(
            name: 'Changed',
            email: $user->email,
            email_verified_at: null,
            password: null,
        ), $user);

        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'Original']);
    }

    public function test_update_does_not_change_other_users(): void
    {
        // Arrange
        $target    = User::factory()->create(['name' => 'Target']);
        $bystander = User::factory()->create(['name' => 'Bystander']);

        $data = new UserData(
            name: 'Updated Target',
            email: $target->email,
            email_verified_at: null,
            password: null,
        );

        // Act
        $this->service->update($data, $target);

        // Assert — bystander untouched
        $this->assertDatabaseHas('users', ['id' => $bystander->id, 'name' => 'Bystander']);
    }

    // -------------------------------------------------------------------------
    // sort — happy path
    // -------------------------------------------------------------------------

    public function test_sort_persists_new_order(): void
    {
        // Arrange
        [$first, $second, $third] = User::factory()->count(3)->create()->pluck('id')->toArray();
        $newOrder = [$third, $first, $second]; // reversed

        // Act
        $this->service->sort($newOrder);

        // Assert
        $this->assertDatabaseHas('users', ['id' => $third,  'sort_order' => 0]);
        $this->assertDatabaseHas('users', ['id' => $first,  'sort_order' => 1]);
        $this->assertDatabaseHas('users', ['id' => $second, 'sort_order' => 2]);
    }
}
```

---

## 15. Feature Tests (endpoint flow)

- **Where**: `tests/Feature/{Model}/{Model}Test.php`
- **Purpose**: Test the full HTTP request → controller → service → database → response cycle.
- **Requirements**:
  - Extend `Tests\TestCase` (uses `RefreshDatabase`).
  - `setUp`: seed `PermissionsSeeder`; create user with factory; `Sanctum::actingAs`.
  - Grant the required permission with `givePermissionTo` using `PermissionGroup::resolve()` — no bare strings.
  - Assert HTTP status, response structure, and database state.
  - All payload keys and `assertDatabaseHas` keys use **snake_case**.
  - Keep each test focused on the happy path — authorization and validation edge cases belong in dedicated test classes.

```php
declare(strict_types=1);

namespace Tests\Feature\User;

use App\Enums\PermissionAction;
use App\Enums\PermissionGroup;
use App\Models\User;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserTest extends TestCase
{
    use RefreshDatabase;

    private User $actingUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionsSeeder::class);

        $this->actingUser = User::factory()->create();
        Sanctum::actingAs($this->actingUser);
    }

    // -------------------------------------------------------------------------
    // index
    // -------------------------------------------------------------------------

    public function test_index_returns_paginated_list_of_users(): void
    {
        // Arrange
        $this->actingUser->givePermissionTo(
            PermissionGroup::resolve(PermissionGroup::USERS, PermissionAction::VIEW)
        );
        User::factory()->count(3)->create();

        // Act
        $response = $this->getJson('/api/users');

        // Assert
        $response->assertOk();
        $this->assertArrayHasKey('data', $response->json());
        $this->assertCount(4, $response->json('data')); // 3 + actingUser
    }

    // -------------------------------------------------------------------------
    // store
    // -------------------------------------------------------------------------

    public function test_store_creates_a_user_and_returns_201(): void
    {
        // Arrange
        $this->actingUser->givePermissionTo(
            PermissionGroup::resolve(PermissionGroup::USERS, PermissionAction::CREATE)
        );
        $payload = [
            'name'     => 'New User',
            'email'    => 'newuser@example.com',
            'password' => 'secret123',
        ];

        // Act
        $response = $this->postJson('/api/users', $payload);

        // Assert
        $response->assertCreated();
        $this->assertDatabaseHas('users', [
            'id'    => $response->json('data.id'),
            'email' => 'newuser@example.com',
        ]);
    }

    // -------------------------------------------------------------------------
    // show
    // -------------------------------------------------------------------------

    public function test_show_returns_the_requested_user(): void
    {
        // Arrange
        $this->actingUser->givePermissionTo(
            PermissionGroup::resolve(PermissionGroup::USERS, PermissionAction::VIEW)
        );
        $user = User::factory()->create();

        // Act
        $response = $this->getJson("/api/users/{$user->id}");

        // Assert
        $response->assertOk();
        $this->assertSame($user->id, $response->json('data.id'));
        $this->assertSame($user->email, $response->json('data.email'));
    }

    // -------------------------------------------------------------------------
    // update
    // -------------------------------------------------------------------------

    public function test_update_modifies_user_and_returns_200(): void
    {
        // Arrange
        $this->actingUser->givePermissionTo(
            PermissionGroup::resolve(PermissionGroup::USERS, PermissionAction::UPDATE)
        );
        $user = User::factory()->create(['name' => 'Before']);

        // Act
        $response = $this->putJson("/api/users/{$user->id}", ['name' => 'After']);

        // Assert
        $response->assertOk();
        $this->assertSame('After', $response->json('data.name'));
        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'After']);
    }

    // -------------------------------------------------------------------------
    // destroy
    // -------------------------------------------------------------------------

    public function test_destroy_deletes_user_and_returns_204(): void
    {
        // Arrange
        $this->actingUser->givePermissionTo(
            PermissionGroup::resolve(PermissionGroup::USERS, PermissionAction::DELETE)
        );
        $user = User::factory()->create();

        // Act
        $response = $this->deleteJson("/api/users/{$user->id}");

        // Assert
        $response->assertNoContent();
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    // -------------------------------------------------------------------------
    // sort (non-CRUD)
    // -------------------------------------------------------------------------

    public function test_sort_persists_new_order_and_returns_200(): void
    {
        // Arrange
        $this->actingUser->givePermissionTo(
            PermissionGroup::resolve(PermissionGroup::USERS, PermissionAction::SORT)
        );
        [$a, $b, $c] = User::factory()->count(3)->create()->pluck('id')->toArray();

        // Act
        $response = $this->patchJson('/api/users/sort', ['order' => [$c, $a, $b]]);

        // Assert
        $response->assertOk();
        $this->assertDatabaseHas('users', ['id' => $c, 'sort_order' => 0]);
        $this->assertDatabaseHas('users', ['id' => $a, 'sort_order' => 1]);
        $this->assertDatabaseHas('users', ['id' => $b, 'sort_order' => 2]);
    }
}
```

---

## Checklist for a new API resource

- [ ] Route: `Route::apiResource(...)` in `routes/api.php` with explicit `->names(['index' => '{resource}.index', ...])`.
- [ ] Non-CRUD routes (sort, ban, etc.) added manually alongside the resource; named `'{resource}.{action}'`.
- [ ] Nested sub-resource routes added manually; named `'{resource}.{sub}.{verb}'`.
- [ ] Controller in `App\Http\Controllers\API`; `$this->authorizeResource(Model::class, 'param')` in constructor; `{Model}Service` injected; `index` uses FilterRequest + `Model::getQuery()->paginate()`; `store`/`update` use Data + Service. Non-CRUD methods call `$this->authorize(PermissionAction::CASE->value, ...)` explicitly.
- [ ] `App\Data\{Model}Data` extending `Spatie\LaravelData\Data`; snake_case properties; Spatie validation attributes.
- [ ] Form Request in `App\Http\Requests\{Model}Requests\`; `authorize()` returns `true`; all keys snake_case.
- [ ] FilterRequest in same folder; `per_page`, `filter.*`, `sort` all snake_case; `sort` `in:` list includes `sort_order` if the model supports manual ordering.
- [ ] Service with `store`/`update` (and any non-CRUD methods) in `DB::transaction`; explicit column mapping.
- [ ] `PermissionAction` enum: new `case` added for any custom action (with `label()`); `defaults()` updated only if the standard set changes.
- [ ] `PermissionGroup` enum: new `case` added; `label()`, `sortOrder()`, and `actions()` updated. Override `actions()` to include custom `PermissionAction` cases if needed.
- [ ] Policy in `App\Policies\{Model}Policy`; every method calls `PermissionGroup::resolve($group, $action)` — no bare strings. Non-CRUD and nested methods added as needed.
- [ ] Policy registered by adding `Model::class => ModelPolicy::class` to `$policies` in `AuthServiceProvider`.
- [ ] `Gate::before` super-admin bypass in `AuthServiceProvider::boot()` (add once, not per resource).
- [ ] `PermissionsSeeder` re-run (`php artisan db:seed --class=PermissionsSeeder`); add parent → child entry to `$nested` map if sub-resource permissions are needed.
- [ ] `RolesSeeder` updated if a new scoped role is needed.
- [ ] Filter trait `App\Traits\FilterQueries\{Model}FilterQuery` with `getQuery()`, filters, sorts (snake_case, include `sort_order` if applicable), scopes; model uses trait.
- [ ] API Resource `toArray` returning snake_case keys; include `sort_order` if applicable.
- [ ] Factory with snake_case column keys; include `sort_order` defaulting to `0` if applicable.
- [ ] Unit test `tests/Unit/{Model}/{Model}ServiceTest.php` — happy path (store/update/sort persist correctly), sad paths (duplicate, rollback), isolation of side-effects.
- [ ] Feature test `tests/Feature/{Model}/{Model}Test.php` — one happy-path test per endpoint (index, store, show, update, destroy, sort, and any non-CRUD/nested routes); `givePermissionTo(PermissionGroup::resolve(...))` before each call; assert status + database state.
