<?php
require __DIR__ . '/vendor/autoload.php';

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;


$app = AppFactory::create();
$app->addBodyParsingMiddleware();
$app->addRoutingMiddleware();
$app->addErrorMiddleware(true, true, true);
$app->add( function ($request, $handler) {
    $response = $handler->handle($request);

    return $response
        ->withHeader('Access-Control-Allow-Origin', '*')
        ->withHeader('Access-Control-Allow-Headers', 'X-Requested-With, Content-Type, Accept, Origin, Authorization')
        ->withHeader('Access-Control-Allow-Methods', 'OPTIONS, GET, POST, PUT, PATCH, DELETE')
        ->withHeader('Content-Type', 'application/json')
    ;
});


//DB CONNECTION
$dsn = 'mysql:host=db;dbname=' . getenv('DB_NAME');
$username = getenv('DB_USER');
$password = getenv('DB_PASS');
$pdo = new PDO($dsn, $username, $password);


//JWT secret key
$secretKey = getenv('JWT_SECRET');

//login
$app->post('/login', function (Request $request, Response $response) use ($pdo, $secretKey) {
    $data = $request->getParsedBody();
    $nombre = $data['nombre'] ?? '';
    $usuario = $data['usuario'] ?? '';
    $clave = $data['clave'] ?? '';

    if (empty($nombre) || empty($usuario) || empty($clave)) {
        $response->getBody()->write(json_encode(['status' => 'error', 'message' => 'Nombre, usuario y clave requeridos']));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }

    $stmt = $pdo->prepare("SELECT id, nombre, usuario, password FROM usuario WHERE usuario = :usuario");
    $stmt->execute(['usuario' => $usuario]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !password_verify($clave, $user['password'])) {
        $response->getBody()->write(json_encode(['status' => 'error', 'message' => 'Credenciales inválidas']));
        return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
    }

    $exp = time() + 3600;
    $payload = [
        'sub' => $user['id'],
        'name' => $user['nombre'],
        'iat' => time(),
        'exp' => $exp
    ];
    $token = JWT::encode($payload, $secretKey, 'HS256');

    $stmt = $pdo->prepare("UPDATE usuario SET token = :token, vencimiento_token = FROM_UNIXTIME(:exp) WHERE id = :id");
    $stmt->execute(['token' => $token, 'exp' => $exp, 'id' => $user['id']]);

    $response->getBody()->write(json_encode([
        'status' => 'success',
        'token' => $token,
    ]));

    return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
});


$app->put('/usuario/{usuario}', function (Request $request, Response $response, array $args) use ($pdo) {
    $id_usuario = $args['usuario'];
    $data = json_decode($request->getBody()->getContents(), true);


    $nombre = $data['nombre'];
    $contrasenia = $data['contrasenia'];


    $stmt = $pdo->prepare("UPDATE usuario SET nombre = :nombre, password = :contrasenia WHERE id = :usuario");
    $stmt->bindParam(':nombre', $nombre);
    $stmt->bindParam(':contrasenia', $contrasenia);
    $stmt->bindParam(':usuario', $id_usuario);

    if ($stmt->execute()) {
        $response->getBody()->write(json_encode(['mensaje' => 'Usuario actualizado correctamente']));
        return $response->withHeader('Content-Type', 'application/json');
    } else {
        $response->getBody()->write(json_encode(['error' => 'No se pudo actualizar el usuario']));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }

});

$app->run();
