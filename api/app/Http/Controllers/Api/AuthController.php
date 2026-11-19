<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        $validated = $request->validated();

        [$user, $studentProfile] = DB::transaction(function () use ($validated): array {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $validated['password'],
            ]);

            $studentProfile = $user->studentProfile()->create([
                'nim' => $validated['nim'],
                'university_name' => $validated['university_name'],
                'study_program' => $validated['study_program'],
                'whatsapp_number' => $validated['whatsapp_number'],
            ]);

            return [$user, $studentProfile];
        });

        $token = $user->createToken('pinjamin-mobile')->plainTextToken;

        return response()->json([
            'message' => 'Registrasi berhasil.',
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => $user->only(['id', 'name', 'email']),
            'student_profile' => $studentProfile->only([
                'user_id',
                'nim',
                'university_name',
                'study_program',
                'whatsapp_number',
                'profile_photo_path',
            ]),
        ], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = User::where('email', $validated['email'])->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            return response()->json([
                'message' => 'Email atau password salah.',
            ], 401);
        }

        if ($user->account_status !== 'active') {
            return response()->json([
                'message' => 'Akun tidak aktif.',
            ], 403);
        }

        $token = $user->createToken('pinjamin-mobile')->plainTextToken;

        return response()->json([
            'message' => 'Login berhasil.',
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => $user->only(['id', 'name', 'email']),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $currentAccessToken = $request->user()->currentAccessToken();

        if ($currentAccessToken && method_exists($currentAccessToken, 'delete')) {
            $currentAccessToken->delete();
        }

        return response()->json([
            'message' => 'Logout berhasil.',
        ]);
    }
}
