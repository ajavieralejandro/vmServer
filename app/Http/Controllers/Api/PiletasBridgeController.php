<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class PiletasBridgeController
{
    public function token(Request $request)
    {
        $user = $request->user();

        $payload = [
            'external_provider' => 'vm',
            'external_user_id'  => (string) $user->id,
            'email'             => $user->email ?? null,
            'dni'               => $user->dni ?? null,
            'nombre'            => $user->nombre ?? null,
            'apellido'          => $user->apellido ?? null,
        ];

        $url = config('services.piletas.internal_url');
        $key = config('services.piletas.internal_key');

        if (!$url || !$key) {
            return response()->json([
                'message' => 'Config faltante: PILETAS_INTERNAL_URL / PILETAS_INTERNAL_KEY'
            ], 500);
        }

        $res = Http::withHeaders([
            'X-Internal-Key' => $key,
            'Accept' => 'application/json',
        ])->post($url, $payload);

        if (!$res->ok()) {
            return response()->json([
                'message' => 'No se pudo obtener token de Piletas',
                'status'  => $res->status(),
                'body'    => $res->json() ?? $res->body(),
            ], 502);
        }

        $token = $res->json('token');

        if (!$token) {
            return response()->json([
                'message' => 'Piletas respondió sin token',
                'body'    => $res->json(),
            ], 502);
        }

        return response()->json([
            'piletas_token' => $token,
            'expires_in' => 60 * 60 * 24 * 30,
        ]);
    }
}
