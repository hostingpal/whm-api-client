<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Http;

use HostingPal\WhmApiClient\Upload;
use InvalidArgumentException;
use LogicException;
use Psr\Http\Message\StreamInterface;
use SensitiveParameter;
use Throwable;

/**
 * Owns prepared sources for one synchronous multipart attempt.
 *
 * Validate the full list and borrowed object identities before opening files.
 * Preparation performs bounded metadata work and never reads or seeks a source.
 * The future multipart body must retain this set through the entire attempt and
 * release it in finally, even if its HTTP client only partially consumes it.
 *
 * @internal
 */
final class PreparedUploadSet
{
    /** @var list<PreparedUploadSource> */
    private array $sources = [];

    /** @param list<PreparedUploadSource> $sources */
    private function __construct(array $sources)
    {
        $this->sources = $sources;
    }

    private function __clone()
    {
    }

    /**
     * Prepare all descriptors, releasing earlier owned files on any failure.
     *
     * @param array<array-key, mixed> $uploads Must be a sequential list of Upload descriptors.
     * @throws InvalidArgumentException For a malformed list or repeated borrowed object.
     * @throws \RuntimeException For unreadable/nonregular sources or invalid/unknown lengths.
     */
    public static function prepare(#[SensitiveParameter] array $uploads): self
    {
        if (!array_is_list($uploads)) {
            throw new InvalidArgumentException('Upload sources must be a sequential list.');
        }
        $borrowed = [];
        $descriptors = [];
        foreach ($uploads as $upload) {
            if (!$upload instanceof Upload) {
                throw new InvalidArgumentException('Upload sources must contain upload descriptors.');
            }
            $descriptors[] = $upload;
            $source = $upload->descriptor()['source'];
            if ($source instanceof StreamInterface) {
                $id = spl_object_id($source);
                if (isset($borrowed[$id])) {
                    throw new InvalidArgumentException('A borrowed stream may occur only once per upload.');
                }
                $borrowed[$id] = true;
            }
        }

        $set = new self([]);
        try {
            foreach ($descriptors as $upload) {
                $set->sources[] = PreparedUploadSource::prepare($upload);
            }
        } catch (Throwable) {
            $set->release();
            throw new \RuntimeException('Upload sources could not be prepared.');
        }

        return $set;
    }

    /** @return list<PreparedUploadSource> */
    public function sources(): array
    {
        return $this->sources;
    }

    /** Close owned handles idempotently; borrowed streams remain caller-owned. */
    public function release(): void
    {
        foreach ($this->sources as $source) {
            $source->release();
        }
        $this->sources = [];
    }

    /**
     * @return array<string, mixed>
     * @throws LogicException Always.
     */
    public function __serialize(): array
    {
        throw new LogicException('Prepared upload sets cannot be serialized.');
    }

    /**
     * @param array<string, mixed> $data
     * @throws LogicException Always.
     */
    public function __unserialize(#[SensitiveParameter] array $data): void
    {
        throw new LogicException('Prepared upload sets cannot be unserialized.');
    }

    public function __destruct()
    {
        $this->release();
    }
}
