<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Hash;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::UserGroup;

    public static function getNavigationGroup(): ?string
    {
        return __('filament.navigation.group.settings');
    }

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required(),
                TextInput::make('email')
                    ->label('Email address')
                    ->email()
                    ->required(),
                TextInput::make('initial')
                    ->label('Initial')
                    ->required()
                    ->maxLength(4),
                Select::make('roles')
                    ->required()
                    ->multiple()
                    ->relationship('roles', 'name')
                    ->preload(),
                Select::make('divisions')
                    ->required()
                    ->multiple()
                    ->relationship('divisions', 'name')
                    ->preload(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('email')
                    ->label('Email address')
                    ->searchable(),
                TextColumn::make('roles.name')
                    ->badge()
                    ->separator(',')
                    ->limit(3)
                    ->tooltip(function (TextColumn $column): ?string {
                        $state = $column->getState();
                        if (is_array($state) && count($state) > 3) {
                            return implode(', ', $state);
                        }

                        return null;
                    }),
                TextColumn::make('divisions.initial')
                    ->label('Divisi')
                    ->badge()
                    ->separator(',')
                    ->limit(3),
                TextColumn::make('is_active')
                    ->label('Status')
                    ->badge()
                    ->color(fn (User $record): string => $record->is_active ? 'success' : 'danger')
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Active' : 'Inactive'),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                Action::make('toggleActive')
                    ->label(fn (User $record): string => $record->is_active ? 'Deactivate' : 'Activate')
                    ->icon(fn (User $record): string => $record->is_active ? 'heroicon-o-no-symbol' : 'heroicon-o-check-circle')
                    ->color(fn (User $record): string => $record->is_active ? 'danger' : 'success')
                    ->requiresConfirmation()
                    ->modalHeading(fn (User $record): string => $record->is_active ? 'Deactivate User' : 'Activate User')
                    ->modalDescription(fn (User $record): string => $record->is_active
                        ? 'This user will be logged out and unable to access the system until reactivated.'
                        : 'This user will be able to login and access the system again.')
                    ->visible(fn () => auth()->user()->isSuperAdmin())
                    ->action(function (User $record) {
                        $record->update(['is_active' => ! $record->is_active]);

                        Notification::make()
                            ->title($record->is_active ? 'User Activated' : 'User Deactivated')
                            ->body($record->name.' is now '.($record->is_active ? 'active' : 'inactive').'.')
                            ->success()
                            ->send();
                    }),
                Action::make('resetPassword')
                    ->label('Reset Password')
                    ->icon('heroicon-o-key')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Reset Password')
                    ->modalDescription('Are you sure you want to reset this user\'s password to the default "Atk2025!"? They will be forced to change it upon next login.')
                    ->visible(fn () => auth()->user()->isSuperAdmin())
                    ->action(function (User $record) {
                        $record->update([
                            'password' => Hash::make('Atk2025!'),
                            'has_changed_password' => false,
                        ]);

                        Notification::make()
                            ->title('Password Reset')
                            ->body('Password for '.$record->name.' has been reset to default: Atk2025!')
                            ->success()
                            ->send();
                    }),
                EditAction::make()
                    ->successNotificationTitle('User updated'),
                DeleteAction::make()
                    ->successNotificationTitle('User deleted'),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    BulkAction::make('bulkActivate')
                        ->label('Activate')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalHeading('Activate Users')
                        ->modalDescription('Selected users will be able to login and access the system.')
                        ->visible(fn () => auth()->user()->isSuperAdmin())
                        ->deselectRecordsAfterCompletion()
                        ->action(function ($records) {
                            $records->each->update(['is_active' => true]);

                            Notification::make()
                                ->title('Users Activated')
                                ->body($records->count().' user(s) activated.')
                                ->success()
                                ->send();
                        }),
                    BulkAction::make('bulkDeactivate')
                        ->label('Deactivate')
                        ->icon('heroicon-o-no-symbol')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('Deactivate Users')
                        ->modalDescription('Selected users will be logged out and unable to access the system.')
                        ->visible(fn () => auth()->user()->isSuperAdmin())
                        ->deselectRecordsAfterCompletion()
                        ->action(function ($records) {
                            $records->each->update(['is_active' => false]);

                            Notification::make()
                                ->title('Users Deactivated')
                                ->body($records->count().' user(s) deactivated.')
                                ->success()
                                ->send();
                        }),
                    DeleteBulkAction::make()
                        ->successNotificationTitle('Users deleted'),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageUsers::route('/'),
        ];
    }
}
