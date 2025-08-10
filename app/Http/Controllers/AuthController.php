<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use App\Models\Usuario;
use Tymon\JWTAuth\Facades\JWTAuth;

class AuthController extends Controller
{
    /**
     * Login do usuário
     */
    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|string',
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Dados inválidos',
                'errors' => $validator->errors()
            ], 422);
        }

        // Por enquanto, retorno básico para testar
        return response()->json([
            'success' => true,
            'message' => 'Login funcionando - implementar JWT',
            'user' => [
                'id' => '1',
                'name' => 'Usuário Teste',
                'email' => $request->email,
                'role' => 'admin'
            ],
            'token' => 'token_temporario'
        ]);
    }

    public function logout(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Logout realizado com sucesso'
        ]);
    }

    public function me(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'user' => [
                'id' => '1',
                'name' => 'Usuário Teste',
                'email' => 'teste@teste.com',
                'role' => 'admin'
            ]
        ]);
    }

    public function register(Request $request): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Registro não implementado ainda'
        ]);
    }
}