<?php

namespace App\Portal;

use Illuminate\Support\Facades\DB;

/* Uploaded files: encrypted at rest (AES-256-GCM, each its own random IV; the tag proves it
   was not altered), scanned before they are kept, stored once by content under a name that is
   an HMAC of the content's hash, so the folder does not reveal which documents it holds.
   A file goes to the CRM only when the record that cites it is submitted: uploading is not
   submitting. The retention policy may later remove a file's content; its row stays. */
class Files
{
    public const ALLOWED_TYPES = ['application/pdf', 'image/png', 'image/jpeg', 'image/webp', 'image/gif', 'image/heic', 'text/csv', 'text/plain'];

    public const MAX_FILE = 20 * 1024 * 1024;

    private const MAGIC = 'VPE1';

    /* the EICAR test string, assembled so this source file is not itself flagged */
    private const EICAR = 'X5O!P%@AP[4\\PZX54(P^)7CC)7}$'.'EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';

    private static function key(): string
    {
        $raw = base64_decode((string) config('portal.files_key'), true);
        if ($raw === false || strlen($raw) !== 32) {
            throw new PortalError(500, 'NO_FILE_KEY', 'The file store has no valid key (FILES_KEY). Nothing was kept.');
        }

        return $raw;
    }

    public static function dir(): string
    {
        return storage_path('app/portal-files');
    }

    public static function encrypt(string $plain): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $body = openssl_encrypt($plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag, '', 16);

        return self::MAGIC.$iv.$tag.$body;
    }

    public static function decrypt(string $stored): string
    {
        if (! str_starts_with($stored, self::MAGIC)) {
            return $stored;
        }
        $plain = openssl_decrypt(substr($stored, 32), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr($stored, 4, 12), substr($stored, 16, 16));
        if ($plain === false) {
            throw new PortalError(500, 'FILE_ALTERED', 'This file could not be opened: it was altered or the key has changed.');
        }

        return $plain;
    }

    /** magic numbers: the declared type must be what the bytes are */
    public static function sniff(string $buf): ?string
    {
        if (str_starts_with($buf, '%PDF-')) {
            return 'application/pdf';
        }
        if (str_starts_with($buf, "\x89PNG")) {
            return 'image/png';
        }
        if (str_starts_with($buf, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }
        if (substr($buf, 0, 4) === 'RIFF' && substr($buf, 8, 4) === 'WEBP') {
            return 'image/webp';
        }
        if (str_starts_with($buf, 'GIF')) {
            return 'image/gif';
        }
        if (str_contains(substr($buf, 4, 8), 'ftyphei')) {
            return 'image/heic';
        }

        return strpos(substr($buf, 0, 512), "\0") === false ? 'text/plain' : null;
    }

    public static function scannerEngine(): string
    {
        $host = config('portal.clamd_host');

        return $host ? "ClamAV ({$host}:".config('portal.clamd_port').')' : 'Built-in checks (basic)';
    }

    public static function basicScan(string $buf, ?string $mime): array
    {
        if (str_contains($buf, self::EICAR)) {
            return ['status' => 'infected', 'threat' => 'EICAR test signature'];
        }
        if ($mime === 'application/pdf') {
            foreach (['/JavaScript' => 'JavaScript', '/JS ' => 'JavaScript', '/JS(' => 'JavaScript', '/Launch' => 'a launch action', '/EmbeddedFile' => 'an embedded file', '/RichMedia' => 'rich media'] as $k => $what) {
                if (str_contains($buf, $k)) {
                    return ['status' => 'infected', 'threat' => "PDF with {$what}"];
                }
            }
        }

        return ['status' => 'clean'];
    }

    /** a configured scanner that cannot answer keeps nothing: a file is never kept unscanned by mistake */
    public static function scan(string $buf, ?string $mime): array
    {
        $basic = self::basicScan($buf, $mime);
        if ($basic['status'] !== 'clean') {
            return $basic + ['engine' => 'Built-in checks'];
        }
        $host = config('portal.clamd_host');
        if (! $host) {
            return ['status' => 'clean', 'engine' => self::scannerEngine()];
        }

        return self::clamd($host, (int) config('portal.clamd_port'), $buf) + ['engine' => self::scannerEngine()];
    }

    private static function clamd(string $host, int $port, string $buf): array
    {
        $unavailable = fn ($why) => new PortalError(503, 'SCANNER_UNAVAILABLE', "The virus scanner {$why}. The file was not kept; try again in a moment.");
        $s = @fsockopen($host, $port, $errno, $errstr, 5);
        if (! $s) {
            throw $unavailable('is not reachable');
        }
        stream_set_timeout($s, 30);
        fwrite($s, "zINSTREAM\0");
        foreach (str_split($buf, 64 * 1024) as $chunk) {
            fwrite($s, pack('N', strlen($chunk)).$chunk);
        }
        fwrite($s, pack('N', 0));
        $answer = trim(str_replace("\0", '', (string) stream_get_contents($s)));
        fclose($s);
        if (preg_match('/:\s*OK$/', $answer)) {
            return ['status' => 'clean'];
        }
        if (preg_match('/FOUND$/', $answer)) {
            return ['status' => 'infected', 'threat' => preg_replace(['/^stream:\s*/', '/\s*FOUND$/'], '', $answer)];
        }
        throw $unavailable('did not answer');
    }

    /** checks, scans and keeps one upload; refuses what it will not keep */
    public static function accept(Ctx $ctx, string $buf, string $name): array
    {
        $ctx->single();
        if ($buf === '') {
            throw new PortalError(400, 'EMPTY', 'The file is empty.');
        }
        if (strlen($buf) > self::MAX_FILE) {
            throw new PortalError(413, 'TOO_LARGE', 'Files must be 20 MB or smaller.');
        }
        $kind = self::sniff($buf);
        if (! $kind || ! in_array($kind, self::ALLOWED_TYPES, true)) {
            throw new PortalError(415, 'TYPE', 'Only PDF, PNG, JPEG, WebP, HEIC, GIF, CSV and text files are accepted.');
        }
        $name = mb_substr(str_replace(['\\', '/'], '_', $name), 0, 200);
        $scan = self::scan($buf, $kind);
        if ($scan['status'] === 'infected') {
            $ctx->audit('file.refused', null, ['name' => $name, 'size' => strlen($buf), 'threat' => $scan['threat'], 'scanner' => $scan['engine']]);
            throw new PortalError(422, 'MALWARE', "This file was refused: {$scan['threat']}. It was not kept. If it is a genuine document, save it again as a plain PDF or a photo.");
        }
        $out = self::save($buf, $ctx->user->org_id, $ctx->providerId, $name, $kind, $ctx->user->id, $scan);
        $ctx->audit('file.uploaded', $out['ref'], ['name' => $name, 'size' => strlen($buf), 'mime' => $kind, 'scanner' => $scan['engine']]);

        return $out;
    }

    public static function save(string $buf, string $orgId, string $providerId, string $name, string $mime, ?string $userId, ?array $scan): array
    {
        if (! is_dir(self::dir())) {
            mkdir(self::dir(), 0775, true);
        }
        $sha = hash('sha256', $buf);
        $ref = portal_id('FIL', 8);
        $file = self::dir().DIRECTORY_SEPARATOR.hash_hmac('sha256', $sha, self::key()).'.enc';
        if (! is_file($file)) {
            file_put_contents($file, self::encrypt($buf));
        }
        DB::table('files')->insert(['ref' => $ref, 'org_id' => $orgId, 'provider_id' => $providerId, 'name' => $name, 'mime' => $mime, 'size' => strlen($buf), 'sha256' => $sha, 'path' => $file,
            'created_by' => $userId, 'created_at' => iso_now(), 'scan_status' => $scan['status'] ?? 'not scanned', 'scanned_by' => $scan['engine'] ?? null, 'scanned_at' => $scan ? iso_now() : null]);

        return ['ref' => $ref, 'name' => $name, 'mime' => $mime, 'size' => strlen($buf), 'sha256' => $sha];
    }

    public static function meta(string $ref, string $orgId): ?object
    {
        return DB::table('files')->where('ref', $ref)->where('org_id', $orgId)->first();
    }

    public static function read(string $ref): string
    {
        $f = DB::table('files')->where('ref', $ref)->first();
        if (! $f || $f->removed_at || ! is_file($f->path)) {
            return '';
        }

        return self::decrypt((string) file_get_contents($f->path));
    }

    public static function setCrmRef(string $ref, string $crmRef): void
    {
        DB::table('files')->where('ref', $ref)->update(['crm_ref' => $crmRef]);
    }

    /** retention: remove a file's content; the row stays as the record that it existed */
    public static function removeContent(string $ref, string $why): bool
    {
        $f = DB::table('files')->where('ref', $ref)->whereNull('removed_at')->first();
        if (! $f) {
            return false;
        }
        DB::table('files')->where('ref', $ref)->update(['removed_at' => iso_now(), 'removed_why' => $why]);
        if (! DB::table('files')->where('path', $f->path)->whereNull('removed_at')->exists() && is_file($f->path)) {
            unlink($f->path); // shared by identical uploads
        }

        return true;
    }

    /** files cited by a draft are sent to the CRM at submission */
    public static function push(Ctx $ctx, $docs): array
    {
        $out = [];
        foreach ((array) ($docs ?? []) as $d) {
            if (empty($d['ref'])) {
                continue;
            }
            $f = self::meta($d['ref'], $ctx->user->org_id);
            if (! $f) {
                throw new PortalError(400, 'NO_FILE', 'The file '.(($d['name'] ?? null) ?: $d['ref']).' is no longer available. Attach it again.');
            }
            $crmRef = $f->crm_ref;
            if (! $crmRef) {
                $crmRef = Crm::upload(self::read($f->ref), ['scope' => $ctx->scope, 'providerId' => $ctx->providerId, 'name' => $f->name, 'mime' => $f->mime, 'user' => $ctx->user->name])['ref'];
                self::setCrmRef($f->ref, $crmRef);
            }
            $out[] = array_merge($d, ['ref' => $crmRef, 'name' => ($d['name'] ?? null) ?: $f->name]);
        }

        return $out;
    }
}
