<?php

namespace App\Models;

use App\Enums\PreferredLanguage;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password', 'fcm_token', 'is_active', 'preferred_language'])]
#[Hidden(['password', 'remember_token', 'authorized_device_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens;

    use HasFactory;
    use HasRoles;
    use Notifiable;

    protected string $guard_name = 'web';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'device_bound_at' => 'datetime',
        ];
    }

    public function scopeAppUsers(Builder $query): Builder
    {
        return $query
            ->where(function (Builder $query): void {
                $query
                    ->whereHas('roles', fn (Builder $roleQuery) => $roleQuery->where('name', 'app_user'))
                    ->orWhereDoesntHave('roles');
            })
            ->whereDoesntHave(
                'roles',
                fn (Builder $roleQuery) => $roleQuery->whereIn('name', ['super_admin', 'admin', 'content_manager']),
            );
    }

    public function savedItems(): BelongsToMany
    {
        return $this->belongsToMany(Item::class, 'saved_items', 'user_id', 'item_id')->withTimestamps();
    }

    public function routeNotificationForFcm(): ?string
    {
        return $this->fcm_token;
    }

    public function notificationAudiences(): BelongsToMany
    {
        return $this->belongsToMany(NotificationAudience::class, 'notification_audience_user')
            ->withTimestamps();
    }

    public function sentNotificationCampaigns(): HasMany
    {
        return $this->hasMany(AdminNotificationCampaign::class, 'sent_by');
    }

    public function notificationCampaignRecipients(): HasMany
    {
        return $this->hasMany(AdminNotificationCampaignRecipient::class, 'user_id');
    }

    public function hasAuthorizedDevice(): bool
    {
        return filled($this->authorized_device_token);
    }

    public function isAuthorizedDeviceToken(string $deviceToken): bool
    {
        $authorizedToken = (string) $this->authorized_device_token;

        return $authorizedToken !== ''
            && $deviceToken !== ''
            && hash_equals($authorizedToken, $deviceToken);
    }

    public function bindAuthorizedDevice(string $deviceToken): void
    {
        $this->forceFill([
            'authorized_device_token' => $deviceToken,
            'device_bound_at' => now(),
            'fcm_token' => $deviceToken,
        ])->save();
    }

    public function resetAuthorizedDevice(): void
    {
        $this->tokens()->delete();

        $this->forceFill([
            'authorized_device_token' => null,
            'device_bound_at' => null,
            'fcm_token' => null,
        ])->save();
    }

    public function canAccessPanel(Panel $panel): bool
    {
        if (! $this->is_active) {
            return false;
        }

        return $this->hasAnyRole(['super_admin', 'admin', 'content_manager']);
    }

    public function preferredLanguageOrDefault(): string
    {
        $preferred = Str::lower(trim((string) $this->preferred_language));

        return in_array($preferred, PreferredLanguage::values(), true)
            ? $preferred
            : PreferredLanguage::EN->value;
    }
}
