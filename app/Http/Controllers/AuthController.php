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

        $dni  = trim((string)$data['dni']);
        $pass = (string)$data['password'];

        // 0) Intentar leer del padrón local (fuente rápida/confiable)
        $pad = SocioPadron::where('dni', $dni)->first();

        // 1) ¿Usuario local?
        $user = User::where('dni', $dni)->first();

        if ($user) {
            if (!Hash::check($pass, $user->password)) {
                throw ValidationException::withMessages([
                    'password' => ['Credenciales inválidas.'],
                ]);
            }

            // Completar desde padrón local si faltan datos
            if ($pad) {
                $this->fillFromPadronIfMissing($user, $pad);
            }

            // API opcional: si querés, SOLO para foto o refresh eventual.
            // Recomendación: NO bloquear login si falla la API.
            // $this->maybeRefreshFromApi($user, $api);

            return response()->json([
                'token' => $user->createToken('auth')->plainTextToken,
                'user'  => $user->fresh(),
                'source' => 'local_user',
                'padron_found' => (bool)$pad,
            ]);
        }

        /**
         * 2) No existe user local:
         *    - Si está en padrón => crearlo desde padrón
         *    - Si NO está en padrón => fallback a API (opcional)
         */

        if ($pad) {
            $attrs = $this->mapPadronToUserAttributes($pad, $dni, $pass);

            // (Opcional) bajar foto si querés (usa sid)
            $socioIdForPhoto = (string)($attrs['socio_id'] ?? '');
            if ($socioIdForPhoto !== '') {
                $avatarPath = $this->downloadAndStoreAvatar($api, $socioIdForPhoto);
                if ($avatarPath) {
                    $attrs['avatar_path'] = $avatarPath;
                }
            }

            $user = User::create($attrs);

            return response()->json([
                'token' => $user->createToken('auth')->plainTextToken,
                'user'  => $user->fresh(),
                'source' => 'padron',
            ], 201);
        }

        // 3) Fallback a API (si querés permitir login aunque el padrón no tenga ese DNI)
        $socio = $api->getSocioPorDni($dni);
        if (!$socio) {
            throw ValidationException::withMessages([
                'dni' => ['No se encontró el socio por DNI (ni en padrón local ni en el servicio).'],
            ]);
        }

        $attrs = $this->mapSocioToUserAttributes($socio, $dni, $pass);

        // Intentar enriquecer igual desde padrón si aparece (raro, pero por si tu sync está a medias)
        $pad2 = SocioPadron::where('dni', $dni)->first();
        if ($pad2) {
            $attrs = $this->mergeAttrsFromPadronRow($attrs, $pad2, $dni);
        }

        $socioIdForPhoto = (string)($attrs['socio_id'] ?? '');
        if ($socioIdForPhoto !== '') {
            $avatarPath = $this->downloadAndStoreAvatar($api, $socioIdForPhoto);
            if ($avatarPath) {
                $attrs['avatar_path'] = $avatarPath;
            }
        }

        $user = User::create($attrs);

        return response()->json([
            'token' => $user->createToken('auth')->plainTextToken,
            'user'  => $user->fresh(),
            'source' => 'api_fallback',
        ], 201);
    }

    public function me(Request $request)
    {
        return $request->user();
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();
        return response()->json(['ok' => true]);
    }

    /**
     * ======== PADRON -> USER (fuente principal) ========
     */
    protected function mapPadronToUserAttributes(SocioPadron $pad, string $dni, string $plainPassword): array
    {
        // apynom viene "APELLIDO NOMBRE" (todo junto)
        $name = $pad->apynom ?: $dni;

        return [
            'dni'        => $dni,
            'name'       => $name,
            'email'      => null, // si no lo tenés en padrón

            // si tu tabla users tiene estos campos (los tenías ya):
            'nombre'     => null,
            'apellido'   => null,

            'socio_id'   => !empty($pad->sid) ? (string)$pad->sid : null,
            'barcode'    => !empty($pad->barcode) ? (string)$pad->barcode : null,

            // si existieran en users y te sirven
            // 'saldo'    => $pad->saldo,
            // 'semaforo' => $pad->semaforo,

            'api_update_ts' => now(), // o null si preferís
'password' => Hash::make($dni), // password inicial = DNI
        ];
    }

    /**
     * Completa un User ya existente con datos del padrón local si faltan.
     */
    protected function fillFromPadronIfMissing(User $user, SocioPadron $pad): void
    {
        $changed = false;

        if (($user->name === $user->dni || !$user->name) && !empty($pad->apynom)) {
            $user->name = $pad->apynom;
            $changed = true;
        }

        if (empty($user->socio_id) && !empty($pad->sid)) {
            $user->socio_id = (string)$pad->sid;
            $changed = true;
        }

        if (empty($user->barcode) && !empty($pad->barcode)) {
            $user->barcode = (string)$pad->barcode;
            $changed = true;
        }

        if ($changed) {
            $user->save();
        }
    }

    /**
     * Mezcla attrs con una row de padrón (por si venís de API)
     */
    protected function mergeAttrsFromPadronRow(array $attrs, SocioPadron $pad, string $dni): array
    {
        if (($attrs['name'] ?? $dni) === $dni && !empty($pad->apynom)) {
            $attrs['name'] = $pad->apynom;
        }

        if (empty($attrs['socio_id']) && !empty($pad->sid)) {
            $attrs['socio_id'] = (string)$pad->sid;
        }

        if (empty($attrs['barcode']) && !empty($pad->barcode)) {
            $attrs['barcode'] = (string)$pad->barcode;
        }

        return $attrs;
    }

    /**
     * ======== API -> USER (fallback) ========
     * (tu método original, lo dejo casi igual)
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
            'barcode'      => isset($socio['barcode']) ? (string)$socio['barcode'] : null,
            'estado_socio' => $socio['estado'] ?? null,
            'api_update_ts'=> ($socio['update_ts'] ?? null) ? Carbon::parse($socio['update_ts']) : now(),
        ];

        if ($isNew && $plainPassword !== null) {
            $attrs['password'] = Hash::make($plainPassword);
        }

        return $attrs;
    }

    /**
     * Descarga la imagen del socio y la guarda en /storage/app/public/socios/{id}.jpg
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
