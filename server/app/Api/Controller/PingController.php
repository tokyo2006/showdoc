<?php

namespace App\Api\Controller;

use App\Common\BaseController;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;

class PingController extends BaseController
{
    public function index(Request $request, Response $response): Response
    {
        return $this->success($response, [
            'pong' => true,
            'time' => date('c'),
        ]);
    }
}
