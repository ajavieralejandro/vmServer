<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SociosApi
{
    protected string $base;
    protected string $login;
    protected string $token;
    protected string $imgBase;
    protected int $timeout;
    protected bool $verify;

    public function __construct()
    {
        $cfg = config('services.socios');

        $this->base    = rtrim($cfg['base'] ?? '', '/');   // https://clubvillamitre.com/api_back_socios
        $this->login   = (string)($cfg['login'] ?? '');
        $this->token   = (string)($cfg['token'] ?? '');
        $this->imgBase = rtrim($cfg['img_base'] ?? '', '/');
        $this->timeout = (int)($cfg['timeout'] ?? 15);
        $this->verify  = (bool)($cfg['verify'] ?? true);   // en DEV podés setear false
    }

    public function getSocioPorDni(string $dni): ?array
    {
        if ($this->base === '' || $this->login === '' || $this->token === '') {
            Log::error('SociosApi: config incompleta', [
                'base' => $this->base,
                'login' => $this->login,
                'token_len' => strlen($this->token),
            ]);
            return null;
        }

        $url = "{$this->base}/get_socio";

        // 1) Primer intento: x-www-form-urlencoded
        $resp = Http::withOptions([
                'timeout' => $this->timeout,
                'verify'  => $this->verify,   // si hay problemas SSL, poné false en .env -> SERVICES.SOCIOS.VERIFY
            ])
            ->asForm()
            ->withHeaders([
                'Authorization' => $this->token,
                'Login'         => $this->login,
            ])
            ->post($url, ['dni' => $dni]);

        $json = $this->decodeAndLog('form', $url, $resp);
        $result = $this->extractResult($json);
        if ($result) return $result;

        // 2) Segundo intento: multipart/form-data (algunas APIs lo exigen)
        $resp2 = Http::withOptions([
                'timeout' => $this->timeout,
                'verify'  => $this->verify,
            ])
            ->asMultipart()
            ->withHeaders([
                'Authorization' => $this->token,
                'Login'         => $this->login,
            ])
            ->post($url, [
                ['name' => 'dni', 'contents' => $dni],
            ]);

        $json2 = $this->decodeAndLog('multipart', $url, $resp2);
        $result2 = $this->extractResult($json2);
        return $result2;
    }

    protected function extractResult(?array $json): ?array
    {
        if (!$json) return null;

        // Casos típicos de éxito observados:
        // { estado:"0", result:{...}, msg:"Proceso OK" }
        // A veces 'estado' varía, por eso validamos 'result' por presencia
        if (!empty($json['result']) && is_array($json['result'])) {
            return $json['result'];
        }
        return null;
    }

    protected function decodeAndLog(string $mode, string $url, $resp): ?array
    {
        if (!$resp->ok()) {
            Log::error("SociosApi {$mode} HTTP error", [
                'status' => $resp->status(),
                'url'    => $url,
                'body'   => $resp->body(),
            ]);
            return null;
        }

        $json = null;
        try {
            $json = $resp->json();
        } catch (\Throwable $e) {
            Log::error("SociosApi {$mode} JSON parse error", [
                'url'  => $url,
                'raw'  => $resp->body(),
                'err'  => $e->getMessage(),
            ]);
        }

        Log::info("SociosApi {$mode} response", [
            'url'   => $url,
            'json'  => $json,
        ]);

        return $json;
    }

    public function getPadronFull(): ?array
{
    $url = "{$this->base}/get_padron_full";

    $resp = Http::withOptions([
            'timeout' => $this->timeout,
            'verify'  => $this->verify,
        ])
        ->withHeaders([
            'Authorization' => $this->token,
            'Login'         => $this->login,
            'Accept'        => 'application/json',
        ])
        ->get($url);

    $json = $this->decodeAndLog('padron_full', $url, $resp);

    if (!$json) return null;
    if (!empty($json['result']) && is_array($json['result'])) {
        return $json['result'];
    }

    return null;
}


    public function fetchFotoSocio(string $socioId): ?string
    {
        if ($this->imgBase === '' || $socioId === '') return null;

        $url = "{$this->imgBase}/{$socioId}.jpg";
        $resp = Http::withOptions([
                'timeout' => $this->timeout,
                'verify'  => $this->verify,
            ])->get($url);

        if ($resp->ok() && str_starts_with($resp->header('Content-Type', ''), 'image/')) {
            return $resp->body();
        }

        Log::warning('SociosApi foto no encontrada/ok', [
            'status' => $resp->status(),
            'url'    => $url,
            'ct'     => $resp->header('Content-Type', ''),
        ]);
        return null;
    }
}
