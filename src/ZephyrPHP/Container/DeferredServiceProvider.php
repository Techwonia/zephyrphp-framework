<?php

declare(strict_types=1);

namespace ZephyrPHP\Container;

/**
 * Deferred Service Provider
 *
 * A deferred service provider is only loaded when one of the services
 * it provides is actually requested. This improves performance by
 * avoiding loading unnecessary service providers.
 *
 * Example:
 *
 * class MailServiceProvider extends DeferredServiceProvider
 * {
 *     public function provides(): array
 *     {
 *         return [
 *             MailerInterface::class,
 *             'mailer',
 *         ];
 *     }
 *
 *     public function register(Container $container): void
 *     {
 *         $container->singleton(MailerInterface::class, function() {
 *             return new SmtpMailer(env('MAIL_HOST'));
 *         });
 *
 *         $container->alias('mailer', MailerInterface::class);
 *     }
 * }
 */
abstract class DeferredServiceProvider extends ServiceProvider
{
    /**
     * Get the services provided by the provider.
     *
     * @return array<string> List of service identifiers this provider registers
     */
    abstract public function provides(): array;
}
