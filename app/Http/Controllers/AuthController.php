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

// ✅ Para reset por email (Laravel Password Broker)
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Auth\Events\PasswordReset;

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

            return response()->json([
                'token' => $user->createToken('auth')->plainTextToken,
                'user'  => $user->fresh(),
                'source' => 'local_user',
                'padron_found' => (bool)$pad,
            ]);
        }

        /**
         * 2) No existe user local:
         *    - Si está en padrón => crearlo desde padrón (password inicial = DNI)
         *    - Si NO está en padrón => fallback a API (password inicial = DNI)
         */

        if ($pad) {
            // ⚠️ password inicial = DNI (ignora lo que tipeó el usuario)
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

        // ⚠️ password inicial = DNI (ignora lo que tipeó el usuario)
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

    /**
     * POST /api/auth/register
     * Body: { dni, name, email, password, password_confirmation }
     * Registro para NO socios
     */
    public function register(Request $request)
    {
        $data = $request->validate([
            // Asumo que tu users.dni es required. Si es nullable, avisame y lo ajusto.
            'dni'      => 'required|string|unique:users,dni',
            'name'     => 'required|string|max:255',
            'email'    => 'required|email:rfc,dns|max:255|unique:users,email',
            'password' => 'required|string|min:6|confirmed',
        ]);

        // No permitir que un socio del padrón se registre como "no-socio"
        if (SocioPadron::where('dni', $data['dni'])->exists()) {
            throw ValidationException::withMessages([
                'dni' => ['Ese DNI pertenece a un socio. Ingresá con DNI como contraseña inicial.'],
            ]);
        }

        $user = User::create([
            'dni'      => trim((string)$data['dni']),
            'name'     => (string)$data['name'],
            'email'    => (string)$data['email'],
            'password' => Hash::make((string)$data['password']),
            'is_admin' => false,
        ]);

        return response()->json([
            'token'  => $user->createToken('auth')->plainTextToken,
            'user'   => $user->fresh(),
            'source' => 'register',
        ], 201);
    }

    /**
     * POST /api/auth/change-password (logueado)
     * Body: { current_password, new_password, new_password_confirmation }
     */
    public function changePassword(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'current_password' => 'required|string',
            'new_password'     => 'required|string|min:6|confirmed',
        ]);

        if (!Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['La contraseña actual no es correcta.'],
            ]);
        }

        $user->password = Hash::make((string)$data['new_password']);
        $user->save();

        // 🔒 Recomendado en apps: revocar tokens para que re-loguee
        // (si lo activás, el front tiene que borrar token y mandar a Login)
        // $user->tokens()->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * POST /api/auth/forgot-password
     * Body: { email }
     * Envía mail con token de reset (Laravel Password Broker)
     */
    public function forgotPassword(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|email',
        ]);

        $status = Password::sendResetLink(['email' => (string)$data['email']]);

        if ($status !== Password::RESET_LINK_SENT) {
            throw ValidationException::withMessages([
                'email' => [__($status)],
            ]);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * POST /api/auth/reset-password
     * Body: { email, token, password, password_confirmation }
     */
    public function resetPassword(Request $request)
    {
        $data = $request->validate([
            'email'                 => 'required|email',
            'token'                 => 'required|string',
            'password'              => 'required|string|min:6|confirmed',
        ]);

        $status = Password::reset(
            [
                'email' => (string)$data['email'],
                'token' => (string)$data['token'],
                'password' => (string)$data['password'],
                'password_confirmation' => (string)$data['password_confirmation'],
            ],
            function ($user, $password) {
                $user->forceFill([
                    'password' => Hash::make((string)$password),
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => [__($status)],
            ]);
        }

        return response()->json(['ok' => true]);
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
        $name = $pad->apynom ?: $dni;

        return [
            'dni'        => $dni,
            'name'       => $name,
            'email'      => null,

            'nombre'     => null,
            'apellido'   => null,

            'socio_id'   => !empty($pad->sid) ? (string)$pad->sid : null,
            'barcode'    => !empty($pad->barcode) ? (string)$pad->barcode : null,

            'api_update_ts' => now(),

            // ✅ password inicial = DNI (NO usa $plainPassword)
            'password'      => Hash::make($dni),
        ];
    }

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

        // ✅ password inicial = DNI (NO usa $plainPassword)
        if ($isNew) {
            $attrs['password'] = Hash::make($dni);
        }

        return $attrs;
    }

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
