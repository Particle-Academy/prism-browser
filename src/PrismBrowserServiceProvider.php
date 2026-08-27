<?php

declare(strict_types=1);

namespace Prism\Browser;

use Illuminate\Support\ServiceProvider;
use Prism\Browser\Contracts\AttachmentStore;
use Prism\Browser\Stores\LaravelAttachmentStore;

final class PrismBrowserServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AttachmentStore::class, LaravelAttachmentStore::class);
    }
}
