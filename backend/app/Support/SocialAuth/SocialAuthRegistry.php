<?php

namespace App\Support\SocialAuth;

use App\Contracts\SocialAuthProviderInterface;
use App\Support\SocialAuth\Providers\AmazonSocialProvider;
use App\Support\SocialAuth\Providers\AppleSocialProvider;
use App\Support\SocialAuth\Providers\FacebookSocialProvider;
use App\Support\SocialAuth\Providers\GitHubSocialProvider;
use App\Support\SocialAuth\Providers\GoogleSocialProvider;
use App\Support\SocialAuth\Providers\MicrosoftSocialProvider;
use App\Support\SocialAuth\Providers\OpenAISocialProvider;
use App\Support\SocialAuth\Providers\SandboxSocialProvider;
use App\Support\SocialAuth\Providers\SdpSocialProvider;
use InvalidArgumentException;

final class SocialAuthRegistry
{
    /** @var array<string, SocialAuthProviderInterface> */
    private array $providers = [];

    public function __construct()
    {
        $this->register(new SandboxSocialProvider);
        $this->register(new GoogleSocialProvider);
        $this->register(new MicrosoftSocialProvider);
        $this->register(new AppleSocialProvider);
        $this->register(new FacebookSocialProvider);
        $this->register(new AmazonSocialProvider);
        $this->register(new SdpSocialProvider);
        $this->register(new GitHubSocialProvider);
        $this->register(new OpenAISocialProvider);
    }

    public function register(SocialAuthProviderInterface $provider): void
    {
        $this->providers[$provider->name()] = $provider;
    }

    public function get(string $name): SocialAuthProviderInterface
    {
        return $this->providers[$name]
            ?? throw new InvalidArgumentException("Unknown social provider [{$name}].");
    }

    /**
     * @return array<int, array{name: string, label: string, configured: bool}>
     */
    public function all(): array
    {
        return array_map(
            fn (SocialAuthProviderInterface $provider) => [
                'name' => $provider->name(),
                'label' => $provider->label(),
                'configured' => $provider->isConfigured(),
            ],
            array_values($this->providers),
        );
    }
}
