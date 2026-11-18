<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use LogicException;
use RuntimeException;

/** Reserve a protected sibling file before running a live fixture and publish its report without overwriting. */
final class EvidencePublication
{
    private readonly string $temporary;
    private bool $complete = false;

    public function __construct(private readonly string $output, string $prefix)
    {
        if (is_link($output) || file_exists($output)) {
            throw new LogicException('Evidence needs a new destination.');
        }
        $directory = dirname($output);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Evidence directory unavailable.');
        }
        $temporary = @tempnam($directory, $prefix);
        if ($temporary === false) {
            throw new RuntimeException('Evidence file unavailable.');
        }
        if (dirname($temporary) !== realpath($directory) || !@chmod($temporary, 0600)) {
            @unlink($temporary);
            throw new RuntimeException('Evidence file protection unavailable.');
        }
        $this->temporary = $temporary;
    }

    /** Publish a complete report, retaining its private recovery copy if another writer wins the link. */
    public function publish(string $json): void
    {
        if (@file_put_contents($this->temporary, $json) !== strlen($json)) {
            throw new RuntimeException('Evidence persistence failed.');
        }
        $this->complete = true;
        if (!@link($this->temporary, $this->output)) {
            throw new RuntimeException('Evidence publication failed.');
        }
        $this->complete = false;
    }

    /** Discard partial files and remove the staging link after success. */
    public function finish(): void
    {
        if (!$this->complete) {
            @unlink($this->temporary);
        }
    }

    /** Quote the recovery path for one line of diagnostics only when publication of a complete report failed. */
    public function quotedRecoveryPath(): ?string
    {
        return $this->complete
            ? json_encode($this->temporary, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR)
            : null;
    }
}
