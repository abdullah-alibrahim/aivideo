<?php

namespace App\Services;

use Symfony\Component\Process\Process;
use Throwable;

/**
 * Stores secrets in the Windows Credential Manager as generic credentials
 * named "AI Reels Studio/<ref>", driven through PowerShell (CredWrite /
 * CredRead / CredDelete P/Invoke).
 */
class WindowsCredentialStore implements SecretStore
{
    private const TARGET_PREFIX = 'AI Reels Studio/';

    public function store(string $ref, string $secret): void
    {
        $this->powerShell($ref, $secret, 'write');
    }

    public function read(string $ref): ?string
    {
        return $this->powerShell($ref, null, 'read');
    }

    public function delete(string $ref): bool
    {
        return $this->powerShell($ref, null, 'delete') !== null;
    }

    /**
     * Runs one Credential Manager operation via PowerShell. The secret travels
     * on stdin so it never appears in the process command line.
     */
    private function powerShell(string $ref, ?string $secret, string $operation): ?string
    {
        $target = self::TARGET_PREFIX.$ref;

        $script = <<<'POWERSHELL'
param([string]$Operation, [string]$Target)
$ErrorActionPreference = 'Stop'
Add-Type -TypeDefinition @"
using System;
using System.Runtime.InteropServices;
public static class CredMan {
    [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Unicode)]
    public struct CREDENTIAL {
        public int Flags; public int Type; public string TargetName; public string Comment;
        public System.Runtime.InteropServices.ComTypes.FILETIME LastWritten;
        public int CredentialBlobSize; public IntPtr CredentialBlob; public int Persist;
        public int AttributeCount; public IntPtr Attributes; public string TargetAlias; public string UserName;
    }
    [DllImport("advapi32.dll", CharSet = CharSet.Unicode, SetLastError = true)]
    public static extern bool CredWrite(ref CREDENTIAL credential, int flags);
    [DllImport("advapi32.dll", CharSet = CharSet.Unicode, SetLastError = true)]
    public static extern bool CredRead(string target, int type, int flags, out IntPtr credentialPtr);
    [DllImport("advapi32.dll", CharSet = CharSet.Unicode, SetLastError = true)]
    public static extern bool CredDelete(string target, int type, int flags);
}
"@
if ($Operation -eq 'write') {
    $secret = [Console]::In.ReadToEnd().TrimEnd("`r", "`n")
    $blob = [System.Text.Encoding]::Unicode.GetBytes($secret)
    $ptr = [System.Runtime.InteropServices.Marshal]::AllocHGlobal($blob.Length)
    [System.Runtime.InteropServices.Marshal]::Copy($blob, 0, $ptr, $blob.Length)
    $credential = New-Object CredMan+CREDENTIAL
    $credential.Flags = 0
    $credential.Type = 1
    $credential.TargetName = $Target
    $credential.Persist = 2
    $credential.CredentialBlobSize = $blob.Length
    $credential.CredentialBlob = $ptr
    $credential.UserName = 'AI Reels Studio'
    $ok = [CredMan]::CredWrite([ref]$credential, 0)
    [System.Runtime.InteropServices.Marshal]::FreeHGlobal($ptr)
    if (-not $ok) { throw "CredWrite failed" }
    exit 0
}
if ($Operation -eq 'read') {
    $ptr = [IntPtr]::Zero
    if (-not [CredMan]::CredRead($Target, 1, 0, [ref]$ptr)) { exit 3 }
    $credential = [System.Runtime.InteropServices.Marshal]::PtrToStructure($ptr, [type][CredMan+CREDENTIAL])
    $bytes = New-Object byte[] $credential.CredentialBlobSize
    [System.Runtime.InteropServices.Marshal]::Copy($credential.CredentialBlob, $bytes, 0, $credential.CredentialBlobSize)
    [Console]::Out.Write([System.Text.Encoding]::Unicode.GetString($bytes))
    exit 0
}
if ($Operation -eq 'delete') {
    if (-not [CredMan]::CredDelete($Target, 1, 0)) { exit 3 }
    exit 0
}
exit 2
POWERSHELL;

        $binary = 'powershell';

        try {
            $process = new Process(
                [$binary, '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-Command', $script, '-Operation', $operation, '-Target', $target],
                null,
                null,
            );
            $process->setTimeout(15);
            if ($secret !== null) {
                $process->setInput($secret);
            }
            $process->run();

            if ($process->isSuccessful()) {
                return $process->getOutput() !== '' ? $process->getOutput() : null;
            }

            if ($this->mustThrow($operation)) {
                throw new \RuntimeException('Credential Manager operation failed: '.trim($process->getErrorOutput()));
            }

            return null;
        } catch (Throwable $exception) {
            if ($this->mustThrow($operation)) {
                throw $exception;
            }

            return null;
        }
    }

    private function mustThrow(string $operation): bool
    {
        return $operation === 'write';
    }
}
