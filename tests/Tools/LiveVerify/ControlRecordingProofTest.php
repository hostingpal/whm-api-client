<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Tools\LiveVerify;

use HostingPal\WhmApiClient\Tools\LiveVerify\ControlRecordingProof;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ControlRecordingProofTest extends TestCase
{
    public function testEveryCheckPassesThroughUnchangedAndOnlyTheFirstIsTheControl(): void
    {
        $final = ['outcome' => 'present', 'residue' => ['sessions' => 8]];
        $inner = RecordingFilesystemProof::after($final);
        $proof = new ControlRecordingProof($inner);

        self::assertFalse($proof->controlPassed());
        self::assertSame(RecordingFilesystemProof::PRESENT, $proof->check('wlsession', 'wlsession.example.com'));
        self::assertSame($final, $proof->check('wlsession', 'wlsession.example.com'));
        $call = ['wlsession', 'wlsession.example.com'];
        self::assertSame([$call, $call], $inner->calls);
        self::assertTrue($proof->controlPassed());
        self::assertFalse($proof->controlSawSessions());
    }

    public function testAControlThatSawSessionNamesIsReported(): void
    {
        $residue = [...RecordingFilesystemProof::PRESENT['residue'], 'sessions' => 1];
        $control = ['outcome' => 'present', 'residue' => $residue];
        $proof = new ControlRecordingProof(new RecordingFilesystemProof([$control]));
        $proof->check('wlsession', 'wlsession.example.com');

        self::assertTrue($proof->controlPassed());
        self::assertTrue($proof->controlSawSessions());
    }

    /** A control that is absent in any account location, unconfirmed or absent altogether has not passed. */
    public function testOnlyAControlPresentInEveryAccountLocationPasses(): void
    {
        $controls = [
            ['outcome' => 'unconfirmed', 'residue' => []],
            ['outcome' => 'confirmed_absent', 'residue' => []],
            ['outcome' => 'present', 'residue' => ['home' => 1, 'users' => 1]],
        ];
        foreach ($controls as $control) {
            $proof = new ControlRecordingProof(new RecordingFilesystemProof([$control]));
            $proof->check('wlsession', 'wlsession.example.com');
            self::assertFalse($proof->controlPassed());
        }
    }

    public function testAControlCheckThatThrowsLeavesNoControl(): void
    {
        $proof = new ControlRecordingProof(new RecordingFilesystemProof([null, RecordingFilesystemProof::PRESENT]));
        try {
            $proof->check('wlsession', 'wlsession.example.com');
        } catch (RuntimeException) {
        }
        $proof->check('wlsession', 'wlsession.example.com');

        self::assertFalse($proof->controlPassed());
        self::assertFalse($proof->controlSawSessions());
    }
}
