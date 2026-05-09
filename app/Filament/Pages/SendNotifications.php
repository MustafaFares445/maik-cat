<?php

namespace App\Filament\Pages;

use App\Enums\NotificationType;
use App\Models\NotificationAudience;
use App\Models\User;
use App\Services\AdminNotificationCampaignService;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Throwable;

class SendNotifications extends Page implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    protected static ?string $title = 'Send Notifications';

    protected static ?string $navigationLabel = 'Send Notifications';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedPaperAirplane;

    protected static string|\UnitEnum|null $navigationGroup = 'Communication';

    protected static string $routePath = 'send-notifications';

    protected string $view = 'filament.pages.send-notifications';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'audience_mode' => 'specific',
            'type' => NotificationType::GENERALE_NOTIFICATION,
        ]);
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('send_notifications') ?? false;
    }

    public function getSubheading(): ?string
    {
        return 'Compose and send multilingual push/in-app notifications.';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Audience')
                    ->description('Send to specific users, reusable audience groups, or all active app users.')
                    ->columns(2)
                    ->components([
                        Select::make('audience_mode')
                            ->label('Audience mode')
                            ->options([
                                'specific' => 'Specific users',
                                'audience' => 'Audience group',
                                'all' => 'All active app users',
                            ])
                            ->default('specific')
                            ->required()
                            ->live(),
                        Select::make('audience_id')
                            ->label('Audience group')
                            ->options(fn (): array => NotificationAudience::query()
                                ->where('is_active', true)
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->visible(fn (Get $get): bool => $get('audience_mode') === 'audience')
                            ->required(fn (Get $get): bool => $get('audience_mode') === 'audience')
                            ->searchable(),
                        Select::make('user_ids')
                            ->label('Specific users')
                            ->multiple()
                            ->options(fn (): array => User::query()
                                ->role('app_user')
                                ->where('is_active', true)
                                ->orderBy('email')
                                ->pluck('email', 'id')
                                ->all())
                            ->visible(fn (Get $get): bool => $get('audience_mode') === 'specific')
                            ->required(fn (Get $get): bool => $get('audience_mode') === 'specific')
                            ->searchable()
                            ->columnSpanFull(),
                        Select::make('type')
                            ->label('Notification type')
                            ->options(NotificationType::labels())
                            ->required(),
                    ]),
                Section::make('Localized content')
                    ->description('English content is required. Arabic and Hungarian are optional and fallback to English. Rich formatting is supported.')
                    ->columns(2)
                    ->components([
                        RichEditor::make('title_en')
                            ->label('Title (English)')
                            ->required()
                            ->maxLength(150)
                            ->toolbarButtons($this->editorToolbarButtons())
                            ->disableToolbarButtons(['attachFiles']),
                        RichEditor::make('title_ar')
                            ->label('Title (Arabic)')
                            ->maxLength(150)
                            ->toolbarButtons($this->editorToolbarButtons())
                            ->disableToolbarButtons(['attachFiles']),
                        RichEditor::make('body_en')
                            ->label('Body (English)')
                            ->required()
                            ->maxLength(500)
                            ->toolbarButtons($this->editorToolbarButtons())
                            ->disableToolbarButtons(['attachFiles']),
                        RichEditor::make('body_ar')
                            ->label('Body (Arabic)')
                            ->maxLength(500)
                            ->toolbarButtons($this->editorToolbarButtons())
                            ->disableToolbarButtons(['attachFiles']),
                        RichEditor::make('title_hu')
                            ->label('Title (Hungarian)')
                            ->maxLength(150)
                            ->toolbarButtons($this->editorToolbarButtons())
                            ->disableToolbarButtons(['attachFiles']),
                        RichEditor::make('body_hu')
                            ->label('Body (Hungarian)')
                            ->maxLength(500)
                            ->toolbarButtons($this->editorToolbarButtons())
                            ->disableToolbarButtons(['attachFiles']),
                    ]),
            ])
            ->statePath('data');
    }

    public function send(): void
    {
        /** @var User|null $sender */
        $sender = auth()->user();

        if (! $sender) {
            Notification::make()
                ->title('Unauthorized request')
                ->danger()
                ->send();

            return;
        }

        $data = $this->form->getState();
        $data['type'] = NotificationType::normalize((string) ($data['type'] ?? ''));

        try {
            app(AdminNotificationCampaignService::class)->sendCampaign($sender, $data);

            Notification::make()
                ->title('Notification campaign sent')
                ->body('The campaign has been sent and logged in notification history.')
                ->success()
                ->send();

            $this->form->fill([
                'audience_mode' => 'specific',
                'type' => NotificationType::GENERALE_NOTIFICATION,
            ]);
        } catch (Throwable $exception) {
            Notification::make()
                ->title('Unable to send campaign')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * @return array<int, string|array<int, string>>
     */
    private function editorToolbarButtons(): array
    {
        return [
            ['bold', 'italic', 'underline', 'strike', 'textColor'],
            ['h2', 'h3'],
            ['bulletList', 'orderedList', 'blockquote'],
            ['undo', 'redo'],
        ];
    }
}
