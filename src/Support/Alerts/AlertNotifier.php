<?php

namespace IsrarMinhas\FilamentAiVisibility\Support\Alerts;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use IsrarMinhas\FilamentAiVisibility\Support\Settings;
use Throwable;

/**
 * Delivers alerts to the channels chosen in Settings: in-panel notifications,
 * email and a Slack incoming webhook. A failing channel never stops the others.
 */
class AlertNotifier
{
    public function __construct(
        protected Settings $settings,
    ) {}

    public function send(Alert $alert): void
    {
        $this->toDatabase($alert);
        $this->toMail($alert);
        $this->toSlack($alert);
    }

    protected function toDatabase(Alert $alert): void
    {
        if (! $this->settings->get('alerts.database', true)) {
            return;
        }

        $userIds = (array) $this->settings->get('alerts.user_ids', []);
        $userModel = config('auth.providers.users.model');

        if ($userIds === [] || ! $userModel || ! class_exists($userModel)) {
            return;
        }

        try {
            $notification = Notification::make()
                ->title($alert->title)
                ->body($alert->body)
                ->status($alert->level);

            if ($alert->url) {
                $notification->actions([
                    Action::make('open')->label($alert->urlLabel ?? 'Open')->url($alert->url)->button(),
                ]);
            }

            // Delivered immediately, not queued: alerts must arrive even when the queue worker is down.
            foreach ($userModel::query()->whereKey($userIds)->get() as $user) {
                $user->notifyNow($notification->toDatabase());
            }
        } catch (Throwable $e) {
            // The app may not have the notifications table or database notifications enabled.
            Log::warning('AI Visibility could not send an in-panel alert: ' . $e->getMessage());
        }
    }

    protected function toMail(Alert $alert): void
    {
        $emails = array_filter((array) $this->settings->get('alerts.emails', []));

        if ($emails === []) {
            return;
        }

        try {
            $body = $alert->body . ($alert->url ? "\n\n{$alert->urlLabel}: {$alert->url}" : '');

            Mail::raw($body, function ($message) use ($emails, $alert) {
                $message->to($emails)->subject('[AI Visibility] ' . $alert->title);
            });
        } catch (Throwable $e) {
            Log::warning('AI Visibility could not send an alert email: ' . $e->getMessage());
        }
    }

    protected function toSlack(Alert $alert): void
    {
        $webhook = $this->settings->get('alerts.slack_webhook');

        if (blank($webhook)) {
            return;
        }

        $emoji = match ($alert->level) {
            'danger' => ':red_circle:',
            'success' => ':white_check_mark:',
            default => ':warning:',
        };

        try {
            Http::timeout(10)->post($webhook, [
                'text' => "{$emoji} *{$alert->title}*\n{$alert->body}" . ($alert->url ? "\n<{$alert->url}|{$alert->urlLabel}>" : ''),
            ]);
        } catch (Throwable $e) {
            Log::warning('AI Visibility could not send a Slack alert: ' . $e->getMessage());
        }
    }
}
