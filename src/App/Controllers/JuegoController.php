<?php

namespace App\Controllers;

use App\Repositories\MazoCartaRepository;
use App\Repositories\UsuarioRepository;
use App\Repositories\PartidaRepository;
use App\Repositories\MazoRepository;
use App\Repositories\JugadaRepository;
use App\Repositories\CartaRepository;
use App\Repositories\GanaARepository;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class JuegoController
{
    public function __construct(private MazoRepository $repo_mazo, private UsuarioRepository $repo_usuario, private PartidaRepository $repo_partida, private MazoCartaRepository $repo_mazo_carta, private JugadaRepository $repo_jugada, private GanaARepository $repo_gana_a, private CartaRepository $repo_carta)
    {
    }

    public function crearPartida(Request $request, Response $response): Response
    {

        $data = json_decode($request->getBody()->getContents(), true);
        $id_usuario = $request->getAttribute('id_usuario');
        $id_mazo = $data['id_mazo'] ?? '';
        $id_mazo_servidor = 1;
        
        if (!$this->repo_mazo->validarMazo($id_usuario,$id_mazo)) {
            return $this->withJson($response, ['error' => 'este mazo no pertence al usuario logueado'], 401);
        }
        $id_partida = $this->repo_partida->crearPartida($id_usuario,$id_mazo);
        $cartas = $this->repo_mazo_carta->actualizarCartas($id_mazo);
        $this->repo_mazo_carta->actualizarCartas($id_mazo_servidor);
        $datocarta = $this->repo_mazo_carta->buscarIdCartas($id_mazo);
        if ($id_partida && $cartas){
            $descarta=$this->repo_carta->mostrarCartas($datocarta);
            return $this->withJson($response, ['mensaje' => 'Partida creada correctamente','id de partida'=> $id_partida,'cartas'=> $descarta]);
        }

        return $this->withJson($response, ['error' => 'No se pudo crear la partida'], 500);
    }

    public function crearJugada(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $id_partida = $data['id_partida'] ?? null;
        $id_carta_usuario = $data['id_carta'] ?? null;
        
        if (!$id_partida || !$id_carta_usuario){
            return $this->withJson($response, ['error' => 'Faltan datos requeridos'], 400);
        }

        $id_mazo = $this->repo_partida->obtenerIDMazo($id_partida);
        $cartas_disponibles = $this->repo_mazo_carta->obtenerCartasEnMano($id_mazo);

        if (!in_array($id_carta_usuario, $cartas_disponibles)){
            return $this->withJson($response, ['error' => 'La carta elegida no esta disponible'], 400);
        }

        $cantidad_jugadas = $this->repo_jugada->contarJugadasEnPartida($id_partida);

        if ($cantidad_jugadas >= 5) {
            return $this->withJson($response, ['error' => 'La partida ya finalizo'], 400);
        }

        $id_carta_servidor = $this->jugadaServidor();

        $fuerza_usuario = $this->repo_carta->obtenerFuerza($id_carta_usuario);
        $fuerza_servidor = $this->repo_carta->obtenerFuerza($id_carta_servidor);

        if ($id_carta_usuario != $id_carta_servidor){
            $atributo_usuario = $this->repo_carta->obtenerAtributo($id_carta_usuario);
            $atributo_servidor = $this->repo_carta->obtenerAtributo($id_carta_servidor);
            $atributo_ventaja = $this->repo_gana_a->ventaja($atributo_usuario, $atributo_servidor);
        }

        if (!empty($atributo_ventaja)){
            if ($atributo_ventaja==$atributo_usuario){
                $fuerza_usuario *= 1.3;
            }

            else {
                $fuerza_servidor *= 1.3;
            }
        }

        
        if ($fuerza_usuario > $fuerza_servidor) {
            $resultado = 'gano';
        } elseif ($fuerza_usuario < $fuerza_servidor) {
            $resultado = 'perdio';
        } else {
            $resultado = 'empato';
        }

        $this->repo_mazo_carta->descartarCarta($id_carta_usuario, $id_mazo);

        $this->repo_jugada->subirJugada($id_partida, $id_carta_usuario, $id_carta_servidor, $resultado);
        
        // Si es la quinta jugada, se finaliza la partida y se devuelve info extra
        if ($cantidad_jugadas + 1 == 5){
            $resultado_final = $this->repo_jugada->determinarGanador($id_partida);
            $this->repo_partida->finalizarPartida($id_partida, $resultado_final);
        
            return $this->withJson($response, [
                'status' => 'success',
                'carta servidor' => $id_carta_servidor,
                'Fuerza usuario' => $fuerza_usuario,
                'Fuerza servidor' => $fuerza_servidor,
                'el_usuario' => $resultado_final,
                'mensaje' => 'La partida ha finalizado'
            ]);
        }
        
        // Caso normal (jugadas 1 a 4)
        return $this->withJson($response, [
            'status' => 'success',
            'carta servidor' => $id_carta_servidor,
            'Fuerza usuario' => $fuerza_usuario,
            'Fuerza servidor' => $fuerza_servidor
        ]);
    }

    private function withJson(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }

    private function jugadaServidor(): int
    {
        $cartas_disponibles = $this->repo_mazo_carta->obtenerCartasEnMano(1);
        $carta = $cartas_disponibles[array_rand($cartas_disponibles)];
        $this->repo_mazo_carta->descartarCarta($carta, 1);
        return $carta;
    }

    
}