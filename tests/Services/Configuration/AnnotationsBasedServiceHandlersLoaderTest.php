<?php

/**
 * PHP Service Bus (publish-subscribe pattern implementation).
 *
 * @author  Maksim Masiukevich <dev@async-php.com>
 * @license MIT
 * @license https://opensource.org/licenses/MIT
 */

declare(strict_types = 1);

namespace ServiceBus\Tests\Services\Configuration;

use Amp\Promise;
use Amp\Success;
use PHPUnit\Framework\TestCase;
use ServiceBus\Context\KernelContext;
use ServiceBus\Services\Annotations\CommandHandler;
use ServiceBus\Services\Annotations\EventListener;
use ServiceBus\Services\Configuration\AnnotationsBasedServiceHandlersLoader;
use ServiceBus\Services\Configuration\DefaultHandlerOptions;
use ServiceBus\Services\Exceptions\InvalidHandlerArguments;
use ServiceBus\Tests\Stubs\Messages\FirstEmptyCommand;
use ServiceBus\Tests\Stubs\Messages\FirstEmptyEvent;

/**
 *
 */
final class AnnotationsBasedServiceHandlersLoaderTest extends TestCase
{
    /**
     * @test
     *
     * @throws \Throwable
     */
    public function loadFromEmptyService(): void
    {
        $object = new class()
        {
        };

        $handlers = (new AnnotationsBasedServiceHandlersLoader())->load($object);

        static::assertEmpty($handlers);
    }

    /**
     * @test
     *
     * @throws \Throwable
     */
    public function loadFilledService(): void
    {
        $service = new class()
        {
            /**
             * @CommandHandler(
             *     validate=true,
             *     groups={"qwerty", "root"}
             * )
             */
            public function handle(FirstEmptyCommand $command, KernelContext $context): void
            {
            }

            /**
             * @EventListener()
             */
            public function firstEventListener(FirstEmptyEvent $event, KernelContext $context): Promise
            {
                return new Success([$event, $context]);
            }

            /**
             * @EventListener()
             */
            public function secondEventListener(FirstEmptyEvent $event, KernelContext $context): \Generator
            {
                yield from [$event, $context];
            }

            /**
             * @ServiceBus\Tests\Services\Configuration\SomeAnotherMethodLevelAnnotation
             */
            public function ignoredMethod(FirstEmptyCommand $command, KernelContext $context): void
            {
            }
        };

        $handlers = (new AnnotationsBasedServiceHandlersLoader())->load($service);

        static::assertNotEmpty($handlers);
        static::assertCount(3, $handlers);

        /** @var \ServiceBus\Services\Configuration\ServiceMessageHandler $handler */
        foreach ($handlers as $handler)
        {
            /**
             * @var \ServiceBus\Common\MessageHandler\MessageHandler $handler
             * @var DefaultHandlerOptions                            $options
             */
            $options = $handler->messageHandler->options;

            static::assertNotNull($handler->messageHandler->returnDeclaration);

            static::assertTrue($handler->messageHandler->hasArguments);
            static::assertCount(2, $handler->messageHandler->arguments);

            if (true === $handler->messageHandler->options->isCommandHandler)
            {
                static::assertSame(FirstEmptyCommand::class, $handler->messageHandler->messageClass);
                static::assertInstanceOf(\Closure::class, $handler->messageHandler->closure);

                static::assertTrue($options->validationEnabled);
                static::assertSame(['qwerty', 'root'], $options->validationGroups);

                static::assertSame('handle', $handler->messageHandler->methodName);
            }
            else
            {
                static::assertSame(FirstEmptyEvent::class, $handler->messageHandler->messageClass);
                static::assertInstanceOf(\Closure::class, $handler->messageHandler->closure);

                static::assertFalse($options->validationEnabled);
                static::assertEmpty($options->validationGroups);
            }
        }
    }

    /**
     * @test
     *
     * @throws \Throwable
     */
    public function loadHandlerWithNoArguments(): void
    {
        $this->expectException(InvalidHandlerArguments::class);
        $this->expectExceptionMessage('The event handler must have at least 2 arguments: the message object (the first argument) and the context');

        $service = new class()
        {
            /**
             * @CommandHandler(
             *     validate=true,
             *     groups={"qwerty", "root"}
             * )
             */
            public function handle(): void
            {
            }
        };

        (new AnnotationsBasedServiceHandlersLoader())->load($service);
    }

    /**
     * @test
     *
     * @throws \Throwable
     */
    public function loadHandlerWithWrongMessageArgument(): void
    {
        $this->expectException(InvalidHandlerArguments::class);
        $this->expectExceptionMessage('The first argument to the message handler must be the message object');

        $service = new class()
        {
            /**
             * @CommandHandler(
             *     validate=true,
             *     groups={"qwerty", "root"}
             * )
             */
            public function handle(string $qwerty, KernelContext $context): void
            {
            }
        };

        (new AnnotationsBasedServiceHandlersLoader())->load($service);
    }
}
