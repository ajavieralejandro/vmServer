<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\SocioPadron;
use App\Services\SociosApi;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * POST /api/auth/login
     * Body: { dni: string, password: string }
     */
    public function login(Request $request, SociosApi $api)
    {
        $data = $request->validate([
            'dni'      => 'required|string',
            'password' => 'required|string',
        ]);

        $dni  = trim($data['dni']);
        $pass = $data['password'];

        // 1) ¿Usuario local?
        $user = User::where('dni', $dni)->first();

        if ($user) {
            if (!Hash::check($pass, $user->password)) {
                throw ValidationException::withMessages([
                    'password' => ['Credenciales inválidas.'],
                ]);
            }

            // Intentar refrescar (pero ojo: get_socio no trae nombre; igual puede traer barcode/saldo/semaforo)
            $this->maybeRefreshFromApi($user, $api);

            // Completar desde padrón local si faltan datos
            $this->fillFromPadronIfMissing($user);

            return response()->json([
                'token' => $user->createToken('auth')->plainTextToken,
                'user'  => $user->fresh(),
                'fetched_from_api' => false,
                'refreshed' => false,
            ]);
        }

        // 2) No existe local: buscamos en API (get_socio es pobre, pero al menos confirma acceso)
        $socio = $api->getSocioPorDni($dni);
        if (!$socio) {
            // Si el proveedor falla, esto puede ser 500. A futuro: devolver 503.
            throw ValidationException::withMessages([
                'dni' => ['No se encontró el socio por DNI o el servicio no responde.'],
            ]);
        }

        // 3) Creamos usuario local con lo mínimo
        $attrs = $this->mapSocioToUserAttributes($socio, $dni, $pass);

        // 4) Completar desde padrón local (DNI -> apynom/sid/barcode/saldo/semaforo)
        $attrs = $this->mergeAttrsFromPadron($attrs, $dni);

        // 5) Descargar avatar si tenemos un ID utilizable (sid del padrón)
        $socioIdForPhoto = (string)($attrs['socio_id'] ?? '');
        $avatarPath = $this->downloadAndStoreAvatar($api, $socioIdForPhoto);
        if ($avatarPath) {
            $attrs['avatar_path'] = $avatarPath;
        }

        $user = User::create($attrs);

        return response()->json([
            'token' => $user->createToken('auth')->plainTextToken,
            'user'  => $user->fresh(),
            'fetched_from_api' => true,
        ], 201);
    }

    /**
     * GET /api/auth/me  (auth:sanctum)
     */
    public function me(Request $request)
    {
        return $request->user();
    }

    /**
     * POST /api/auth/logout  (auth:sanctum)
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();
        return response()->json(['ok' => true]);
    }

    /**
     * Refresca datos desde API si hubiese update_ts.
     * (Ojo: tu get_socio actual no trae update_ts ni nombre, pero lo dejamos por compatibilidad.)
     */
    protected function maybeRefreshFromApi(User $user, SociosApi $api): void
    {
        $socio = $api->getSocioPorDni($user->dni);
        if (!$socio) return;

        $apiTsStr = $socio['update_ts'] ?? null;
        $apiTs = $apiTsStr ? Carbon::parse($apiTsStr) : null;

        if (!$apiTs) return;

        $localTs = $user->api_update_ts ? Carbon::parse($user->api_update_ts) : null;
        if ($localTs && $apiTs->lte($localTs)) {
            return;
        }

        $attrs = $this->mapSocioToUserAttributes($socio, $user->dni, null, false);

        $socioId = (string)($attrs['socio_id'] ?? '');
        if ($socioId !== '') {
            if ($binary = $api->fetchFotoSocio($socioId)) {
                $file = "socios/{$socioId}.jpg";
                Storage::disk('public')->put($file, $binary);
                $attrs['avatar_path'] = "storage/{$file}";
            }
        }

        $user->fill($attrs);
        $user->save();
    }

    /**
     * Mapea el payload del socio (API) a los atributos del User local.
     * Tu get_socio hoy solo trae barcode/saldo/semaforo, así que name cae en DNI.
     */
    protected function mapSocioToUserAttributes(array $socio, string $dni, ?string $plainPassword, bool $isNew = true): array
    {
        $nombre   = trim((string)($socio['nombre'] ?? ''));
        $apellido = trim((string)($socio['apellido'] ?? ''));
        $email    = $socio['mail'] ?? null;

        $socioIdRaw = $socio['Id'] ?? $socio['socio_n'] ?? null;
        $socioId = ($socioIdRaw !== null && $socioIdRaw !== '') ? (string)$socioIdRaw : null;

        $attrs = [
            'dni'          => $dni,
            'name'         => ($apellido || $nombre) ? trim("{$apellido}, {$nombre}", " ,") : $dni,
            'email'        => $email ?: null,

            'nombre'       => $nombre ?: null,
            'apellido'     => $apellido ?: null,
            'nacionalidad' => $socio['nacionalidad'] ?? null,
            'nacimiento'   => !empty($socio['nacimiento']) ? Carbon::parse($socio['nacimiento']) : null,
            'domicilio'    => $socio['domicilio'] ?? null,
            'localidad'    => $socio['localidad'] ?? null,
            'telefono'     => $socio['telefono'] ?? null,
            'celular'      => $socio['celular'] ?? null,
            'categoria'    => $socio['categoria'] ?? null,

            'socio_id'     => $socioId,
            'barcode'      => $socio['barcode'] ?? null,
            'estado_socio' => $socio['estado'] ?? null,
            'api_update_ts'=> ($socio['update_ts'] ?? null) ? Carbon::parse($socio['update_ts']) : now(),
        ];

        if ($isNew && $plainPassword !== null) {
            $attrs['password'] = Hash::make($plainPassword);
        }

        return $attrs;
    }

    /**
     * Completa un User ya existente con datos del padrón local si faltan.
     */
    protected function fillFromPadronIfMissing(User $user): void
    {
        if (!class_exists(SocioPadron::class)) {
            return;
        }

        $pad = SocioPadron::where('dni', $user->dni)->first();
        if (!$pad) return;

        $changed = false;

        if (($user->name === $user->dni || !$user->name) && $pad->apynom) {
            $user->name = $pad->apynom;
            $changed = true;
        }

        if (empty($user->socio_id) && !empty($pad->sid)) {
            $user->socio_id = (string)$pad->sid;
            $changed = true;
        }

        if (empty($user->barcode) && !empty($pad->barcode)) {
            $user->barcode = $pad->barcode;
            $changed = true;
        }

        if ($changed) {
            $user->save();
        }
    }

    /**
     * Mezcla attrs con padrón local ANTES de crear user
     */
    protected function mergeAttrsFromPadron(array $attrs, string $dni): array
    {
        if (!class_exists(SocioPadron::class)) {
            return $attrs;
        }

        $pad = SocioPadron::where('dni', $dni)->first();
        if (!$pad) return $attrs;

        // name: apynom viene "APELLIDO NOMBRE"
        if (($attrs['name'] ?? $dni) === $dni && !empty($pad->apynom)) {
            $attrs['name'] = $pad->apynom;
        }

        // socio_id: usamos sid
        if (empty($attrs['socio_id']) && !empty($pad->sid)) {
            $attrs['socio_id'] = (string)$pad->sid;
        }

        // barcode
        if (empty($attrs['barcode']) && !empty($pad->barcode)) {
            $attrs['barcode'] = $pad->barcode;
        }

        return $attrs;
    }

    /**
     * Descarga la imagen del socio y la guarda en /storage/app/public/socios/{id}.jpg
     * Devuelve la ruta pública "storage/socios/{id}.jpg" o null si no pudo.
     */
    protected function downloadAndStoreAvatar(SociosApi $api, string $socioId): ?string
    {
        if ($socioId === '') return null;

        if ($binary = $api->fetchFotoSocio($socioId)) {
            $file = "socios/{$socioId}.jpg";
            Storage::disk('public')->put($file, $binary);
            return "storage/{$file}";
        }
        return null;
    }
}
