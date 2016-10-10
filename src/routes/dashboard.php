<?php

use Firebase\JWT\JWT;
use Tuupola\Base62;

$app->group('/dashboard', function () {

    $this->post("/token", function ($request, $response) {
        /* Here generate and return JWT to the client. */
        $data = $request->getParsedBody();
        $formElement = '';
        $response_data = [
            'status' => false,
            'message' => ''];

        try {
            $formElement = 'username';
            formIsEmpty($data, $formElement);
            $formElement = 'password';
            formIsEmpty($data, $formElement);

            $formElement = '';
            if ($data['username'] != 'dashboard' || $data['password'] != 'dashboard') {
                throw new Exception("Credential is not valid");
            }

            $jti = Base62::encode(openssl_random_pseudo_bytes(16));
            $now = new DateTime();
            $future = new DateTime("now +2 hours");
            $payload = [
                "nbf" => $now->getTimeStamp(),
                "iat" => $now->getTimeStamp(),
                "exp" => $future->getTimeStamp(),
                "jti" => $jti,
                "iss" => 'baristawars2016.com',
                "sub" => 'dashboard',
                "aud" => $data["username"] . '@dashboard.baristawars2016.com',
            ];
            $secret = $this->get('settings')['jwt']['secret'];
            $token = JWT::encode($payload, $secret, "HS256");

            $response_data = [
                'status' => true,
                'token' => $token,
                'profile' => [
                    'username' => $data['username']
                ]
            ];
        } catch (Exception $e) {
            $response_data = [
                'status' => false,
                'message' => $e->getMessage(),
                'element' => $formElement
            ];
        }

        $response->withJson($response_data);
        return $response;
    });
    $this->get("/chart/gender/percentage", function ($request, $response) {
//
//        $param = $request->getParsedBody();
//
//        $token = JWT::decode($param['token'], $this->get('settings')['jwt']['secret'], ["HS256"]);
//        console.log($token);
        $response_data = [
            'male' => 30,
            'female' => 70,
        ];
        $response->withJson($response_data);
        return $response;
    });
    $this->get("/chart/position/value", function ($request, $response) {
//
//        $param = $request->getParsedBody();
//
//        $token = JWT::decode($param['token'], $this->get('settings')['jwt']['secret'], ["HS256"]);
//        console.log($token);
        $response_data = [
            'owner' => [
                'items' => [
                    'male' => 12,
                    'female' => 18,
                ],
                'total' => 30,
            ],
            'barista' => [
                'items' => [
                    'male' => 43,
                    'female' => 17,
                ],
                'total' => 60,
            ],
            'individu' => [
                'items' => [
                    'male' => 52,
                    'female' => 18,
                ],
                'total' => 70,
            ],
        ];
        $response->withJson($response_data);
        return $response;
    });
});