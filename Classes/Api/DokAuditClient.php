<?php

declare(strict_types=1);

namespace DokAudit\Typo3\Api;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

/**
 * Dünner Client für die DokAudit-API v1 (https://dokaudit.de/api/v1/docs).
 *
 * POST /documents (multipart, auto_mode=true) -> Dokument-ID, asynchrone Verarbeitung;
 * GET /documents/{id} -> Status (uploaded|queued|processing|checked|fixed|waiting_credits|error);
 * GET /documents/{id}/files/fixed -> korrigierte Fassung (PDF/UA-1).
 */
final class DokAuditClient
{
    public function __construct(
        private readonly RequestFactory $requestFactory,
        private readonly ExtensionConfiguration $extensionConfiguration,
    ) {}

    /** @return array<string, mixed> */
    public function settings(): array
    {
        try {
            $cfg = (array)$this->extensionConfiguration->get('dokaudit');
        } catch (\Throwable) {
            $cfg = [];
        }
        $key = trim((string)($cfg['apiKey'] ?? ''));
        if ($key === '') {
            $key = (string)(getenv('DOKAUDIT_API_KEY') ?: '');
        }
        $cfg['apiKey'] = $key;
        $cfg['apiUrl'] = rtrim(trim((string)($cfg['apiUrl'] ?? '')) ?: 'https://dokaudit.de', '/');
        return $cfg;
    }

    public function isConfigured(): bool
    {
        return str_starts_with($this->settings()['apiKey'], 'dka_');
    }

    /** @return array<string, mixed> */
    public function account(?string $key = null, ?string $apiUrl = null): array
    {
        // mit $key/$apiUrl wird ein noch nicht gespeicherter Schlüssel geprüft
        return $this->json('GET', '/account', [], $key, $apiUrl);
    }

    /** Installierte Version der Erweiterung (für User-Agent und Update-Hinweis). */
    public static function pluginVersion(): string
    {
        // Maßgeblich ist ext_emconf.php – in Composer-Installationen meldet TYPO3 sonst
        // den Git-Zweig des Pakets (z. B. „dev-main“).
        $datei = dirname(__DIR__, 2) . '/ext_emconf.php';
        $text = is_file($datei) ? (string)file_get_contents($datei) : '';
        if (preg_match("/'version'\s*=>\s*'([0-9][0-9A-Za-z.\-]*)'/", $text, $m)) {
            return $m[1];
        }
        try {
            $v = ExtensionManagementUtility::getExtensionVersion('dokaudit');
            return preg_match('/^[0-9]/', $v) ? $v : '';
        } catch (\Throwable) {
            return '';
        }
    }

    /** @return array<string, mixed> */
    public function upload(string $localPath, string $filename): array
    {
        $handle = fopen($localPath, 'rb');
        if ($handle === false) {
            throw new DokAuditException('Datei konnte nicht gelesen werden: ' . $filename);
        }
        try {
            return $this->json('POST', '/documents', [
                'multipart' => [
                    ['name' => 'file', 'contents' => $handle, 'filename' => $filename,
                        'headers' => ['Content-Type' => 'application/pdf']],
                    ['name' => 'auto_mode', 'contents' => 'true'],
                ],
                'timeout' => 300,
            ]);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }

    /** @return array<string, mixed> */
    public function document(string $id): array
    {
        return $this->json('GET', '/documents/' . rawurlencode($id));
    }

    public function download(string $id, string $kind, string $target): void
    {
        $response = $this->send('GET', '/documents/' . rawurlencode($id) . '/files/' . rawurlencode($kind), [
            'sink' => $target,
            'timeout' => 300,
        ]);
        if ($response->getStatusCode() !== 200) {
            @unlink($target);
            throw $this->error($response->getStatusCode(), (string)$response->getBody());
        }
        $head = (string)file_get_contents($target, false, null, 0, 5);
        if ($head !== '%PDF-') {
            @unlink($target);
            throw new DokAuditException('Antwort ist keine PDF-Datei.');
        }
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function json(string $method, string $path, array $options = [], ?string $key = null, ?string $apiUrl = null): array
    {
        $response = $this->send($method, $path, $options, $key, $apiUrl);
        $body = (string)$response->getBody();
        if ($response->getStatusCode() >= 400) {
            throw $this->error($response->getStatusCode(), $body);
        }
        $data = json_decode($body, true);
        return is_array($data) ? $data : [];
    }

    /** @param array<string, mixed> $options */
    private function send(string $method, string $path, array $options, ?string $key = null, ?string $apiUrl = null): \Psr\Http\Message\ResponseInterface
    {
        $s = $this->settings();
        $s['apiKey'] = $key ?? $s['apiKey'];
        $s['apiUrl'] = $apiUrl !== null ? rtrim($apiUrl, '/') : $s['apiUrl'];
        if (!str_starts_with($s['apiKey'], 'dka_')) {
            throw new DokAuditException('Kein gültiger API-Schlüssel konfiguriert (Erweiterungseinstellungen → dokaudit).');
        }
        $options['headers'] = array_merge($options['headers'] ?? [], [
            'Authorization' => 'Bearer ' . $s['apiKey'],
            'Accept' => 'application/json',
            'User-Agent' => 'DokAudit-TYPO3/' . (self::pluginVersion() ?: '1') . ' (TYPO3 ' . (new \TYPO3\CMS\Core\Information\Typo3Version())->getVersion() . ')',
        ]);
        $options['http_errors'] = false;
        $options['timeout'] ??= 60;
        try {
            return $this->requestFactory->request($s['apiUrl'] . '/api/v1' . $path, $method, $options);
        } catch (\Throwable $e) {
            throw new DokAuditException('DokAudit nicht erreichbar: ' . $e->getMessage());
        }
    }

    private function error(int $status, string $body): DokAuditException
    {
        $data = json_decode($body, true);
        $msg = is_array($data) ? (string)($data['message'] ?? $data['detail'] ?? '') : '';
        $code = is_array($data) ? (string)($data['error'] ?? '') : '';
        return new DokAuditException($msg !== '' ? $msg : 'HTTP ' . $status, $status, $code);
    }
}
