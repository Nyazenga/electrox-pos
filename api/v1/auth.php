<?php
/**
 * @OA\Post(
 *     path="/auth",
 *     tags={"Authentication"},
 *     summary="User login",
 *     description="Authenticate user and get API token",
 *     @OA\RequestBody(
 *         required=true,
 *         @OA\JsonContent(
 *             required={"email", "password", "tenant_name"},
 *             @OA\Property(property="email", type="string", format="email", example="admin@electrox.co.zw"),
 *             @OA\Property(property="password", type="string", format="password", example="Admin@123"),
 *             @OA\Property(property="tenant_name", type="string", example="primary")
 *         )
 *     ),
 *     @OA\Response(
 *         response=200,
 *         description="Login successful",
 *         @OA\JsonContent(
 *             @OA\Property(property="success", type="boolean", example=true),
 *             @OA\Property(property="message", type="string", example="Login successful"),
 *             @OA\Property(property="data", type="object",
 *                 @OA\Property(property="token", type="string", example="eyJ0eXAiOiJKV1QiLCJhbGc..."),
 *                 @OA\Property(property="user", type="object",
 *                     @OA\Property(property="id", type="integer", example=1),
 *                     @OA\Property(property="email", type="string", example="admin@electrox.co.zw"),
 *                     @OA\Property(property="first_name", type="string", example="Admin"),
 *                     @OA\Property(property="last_name", type="string", example="User"),
 *                     @OA\Property(property="role_id", type="integer", example=1),
 *                     @OA\Property(property="branch_id", type="integer", example=1)
 *                 )
 *             )
 *         )
 *     ),
 *     @OA\Response(
 *         response=401,
 *         description="Invalid credentials",
 *         @OA\JsonContent(
 *             @OA\Property(property="success", type="boolean", example=false),
 *             @OA\Property(property="message", type="string", example="Invalid email or password")
 *         )
 *     )
 * )
 */

require_once __DIR__ . '/_base.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    $input = getRequestBody();
    
    if (!isset($input['email']) || !isset($input['password'])) {
        sendError('Email and password are required', 400);
    }
    
    $email = $input['email'];
    $password = $input['password'];
    $tenantName = $input['tenant_name'] ?? null;
    
    $auth = Auth::getInstance();
    $result = $auth->login($email, $password, $tenantName);
    
    if ($result['success']) {
        // Generate API token (for now, use session-based, but in production use JWT)
        $token = base64_encode(json_encode([
            'user_id' => $_SESSION['user_id'],
            'email' => $_SESSION['email'],
            'expires' => time() + (24 * 60 * 60) // 24 hours
        ]));
        
        sendSuccess([
            'token' => $token,
            'user' => [
                'id' => $_SESSION['user_id'],
                'email' => $_SESSION['email'],
                'first_name' => $_SESSION['first_name'] ?? '',
                'last_name' => $_SESSION['last_name'] ?? '',
                'role_id' => $_SESSION['role_id'],
                'branch_id' => $_SESSION['branch_id']
            ]
        ], 'Login successful');
    } else {
        sendError($result['message'], 401);
    }
} else {
    sendError('Method not allowed', 405);
}


