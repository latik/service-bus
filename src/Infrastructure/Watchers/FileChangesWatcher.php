<?php

/**
 * PHP Service Bus (publish-subscribe pattern implementation).
 *
 * @author  Maksim Masiukevich <dev@async-php.com>
 * @license MIT
 * @license https://opensource.org/licenses/MIT
 */

declare(strict_types = 1);

namespace ServiceBus\Infrastructure\Watchers;

use function Amp\ByteStream\buffer;
use function Amp\call;
use Amp\Process\Process;
use Amp\Promise;

/**
 * File change monitoring.
 */
final class FileChangesWatcher
{
    private string $directory;

    /**
     * Previous hash of files in the directory.
     */
    private ?string $previousHash = null;

    public function __construct(string $directory)
    {
        $this->directory = $directory;
    }

    /**
     * Compare hashes
     * If returned false, the files have not been changed. Otherwise, return true.
     */
    public function compare(): Promise
    {
        return call(
            function (): \Generator
            {
                /** @var string $bufferContent */
                $bufferContent = yield from self::execute($this->directory);

                $hash = self::extractHash($bufferContent);

                /** A runtime error has occurred */
                // @codeCoverageIgnoreStart
                if (null === $hash)
                {
                    return false;
                }
                // @codeCoverageIgnoreEnd

                /** New hash is different from previously received */
                if (null !== $this->previousHash && $this->previousHash !== $hash)
                {
                    return true;
                }

                $this->previousHash = $hash;

                return false;
            }
        );
    }

    /**
     * Execute calculate hashes.
     */
    private static function execute(string $directory): \Generator
    {
        try
        {
            $process = new Process(
                \sprintf(
                    'find %s -name \'*.php\' \( -exec sha1sum "$PWD"/{} \; -o -print \) | sha1sum',
                    $directory
                )
            );

            /** @psalm-suppress TooManyTemplateParams Wrong Promise template */
            yield $process->start();

            /** @var string $bufferContent */
            $bufferContent = yield buffer($process->getStdout());

            return $bufferContent;
        }
        // @codeCoverageIgnoreStart
        catch (\Throwable $throwable)
        {
            return '';
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * Get a hash from the stdOut.
     */
    private static function extractHash(string $response): ?string
    {
        $parts = \array_map('trim', \explode(' ', $response));

        return $parts[0] ?? null;
    }
}
