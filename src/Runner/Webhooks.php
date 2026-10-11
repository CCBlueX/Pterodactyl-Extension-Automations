<?php

declare(strict_types=1);

namespace Automations\Runner;

use Automations\Settings;
use Automations\Support\StepFailed;
use Automations\Support\Targets;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Posts a notify step to Discord or to any webhook.
 */
final readonly class Webhooks
{
    public function __construct(private Settings $settings) {}

    /** @param array{automation: string, server: string, identifier: string, run: int} $context */
    public function send(string $kind, string $url, string $message, array $context): void
    {
        if (($problem = Targets::problem($url, $this->settings->allowPrivateTargets())) !== null) {
            throw new StepFailed($problem);
        }

        $message = str_replace(['{SERVER}', '{AUTOMATION}'], [$context['server'], $context['automation']], $message);
        $body = $kind === 'discord'
            ? [
                'username' => 'Automations',
                'embeds' => [['title' => $context['automation'], 'description' => $message, 'color' => 0x3B82F6, 'footer' => ['text' => $context['server']], 'timestamp' => CarbonImmutable::now()->toIso8601String()]],
                'allowed_mentions' => ['parse' => ['users', 'roles']],
            ]
            : [
                'event' => 'automation.notify',
                'automation' => $context['automation'],
                'server' => ['name' => $context['server'], 'identifier' => $context['identifier']],
                'run' => $context['run'],
                'message' => $message,
                'sent_at' => CarbonImmutable::now()->toIso8601String(),
            ];

        try {
            $response = Http::timeout(10)->connectTimeout(5)->withoutRedirecting()->acceptJson()->post($url, $body);
        } catch (ConnectionException) {
            throw new StepFailed('The webhook could not be reached.');
        }

        if ($response->failed()) {
            throw new StepFailed("The webhook answered with HTTP {$response->status()}.");
        }
    }
}
